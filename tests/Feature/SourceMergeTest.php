<?php

namespace Tests\Feature;

use App\Models\SourceModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * Feature tests for POST /ui/sources/merge (Web\SourcesController::merge(),
 * SourceModel::mergeSources()).
 *
 * Built for the real scenario that motivated this feature: an uncatalogued,
 * fast-moving object (e.g. comet C/2020 R4 ATLAS, which SkyBot/MPC never
 * carries ephemeris data for — see observatory-pipeline's CLAUDE.md, Known
 * Issues) shifts more than SourceModel::findByCoordinates()'s own ~2" dedup
 * radius between exposures, so every single frame it's detected on mints a
 * brand-new `sources` row instead of accumulating observations against one.
 * This merges several such fragments (selected via checkboxes on /ui/charts)
 * into one new source.
 *
 * NOTE: written but deliberately NOT executed yet — the operator is testing
 * this feature live against a real database first; see the conversation
 * this was built in. Run with `vendor/bin/phpunit tests/Feature/SourceMergeTest.php`
 * when ready.
 *
 * @internal
 */
final class SourceMergeTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    // Same minimal-but-signed PNG body SourceChartsTest.php uses — enough to
    // exercise file-existence/cleanup assertions without a real renderer.
    private const MINIMAL_PNG = "\x89PNG\r\n\x1a\n" . 'rest-of-a-fake-but-signed-png-body';

    protected function setUp(): void
    {
        parent::setUp();
        $this->emptyAppTables();
    }

    // -------------------------------------------------------------------------
    // Fixtures
    // -------------------------------------------------------------------------

    private function emptyAppTables(): void
    {
        $db = \Config\Database::connect('default');
        $db->query('DELETE FROM source_charts');
        $db->query('DELETE FROM anomalies');
        $db->query('DELETE FROM frame_sources');
        $db->query('DELETE FROM source_observations');
        $db->query('DELETE FROM sources');
        $db->query('DELETE FROM object_stats');
        $db->query('DELETE FROM frames');
    }

    private function createFrame(array $overrides = []): string
    {
        $db = \Config\Database::connect('default');
        $id = uniqid('', true);
        $db->table('frames')->insert(array_merge([
            'id'           => $id,
            'filename'     => 'merge_test_' . uniqid() . '.fits',
            'object'       => 'C_2020_R4_ATLAS',
            'obs_time'     => '2021-04-27 18:00:00',
            'ra_center'    => 222.72,
            'dec_center'   => 32.62,
            'fov_deg'      => 1.0,
            'quality_flag' => 'OK',
        ], $overrides));

        return $id;
    }

    /**
     * Insert one uncatalogued source (catalog_name/catalog_id both null —
     * the comet-fragment case this feature exists for) with exactly one
     * observation on a fresh frame, plus the matching `frame_sources` link
     * row (production ingestion writes both, not just source_observations).
     *
     * @return array{source_id: string, frame_id: string}
     */
    private function createFragmentedSource(string $obsTime, float $ra, float $dec): array
    {
        $db       = \Config\Database::connect('default');
        $sourceId = uniqid('', true);
        $frameId  = $this->createFrame(['obs_time' => $obsTime]);

        $db->table('sources')->insert([
            'id'                => $sourceId,
            'catalog_name'      => null,
            'catalog_id'        => null,
            'object_type'       => null,
            'first_observed_at' => $obsTime,
            'last_observed_at'  => $obsTime,
            'observation_count' => 1,
        ]);

        $db->table('source_observations')->insert([
            'id'        => uniqid('', true),
            'source_id' => $sourceId,
            'frame_id'  => $frameId,
            'ra'        => $ra,
            'dec'       => $dec,
            'mag'       => 12.9,
            'obs_time'  => $obsTime,
        ]);

        $db->table('frame_sources')->insert([
            'id'        => uniqid('', true),
            'frame_id'  => $frameId,
            'source_id' => $sourceId,
        ]);

        return ['source_id' => $sourceId, 'frame_id' => $frameId];
    }

    private function createAnomalyFor(string $sourceId, string $frameId): string
    {
        $db = \Config\Database::connect('default');
        $id = uniqid('', true);
        $db->table('anomalies')->insert([
            'id'           => $id,
            'frame_id'     => $frameId,
            'source_id'    => $sourceId,
            'anomaly_type' => 'MOVING_UNKNOWN',
            'ra'           => 222.72,
            'dec'          => 32.62,
            'is_alert'     => 1,
        ]);

        return $id;
    }

    private function createChartFor(string $sourceId): void
    {
        $db = \Config\Database::connect('default');
        $db->table('source_charts')->insert([
            'id'          => uniqid('', true),
            'source_id'   => $sourceId,
            'style'       => 'track',
            'frame_count' => 1,
            'updated_at'  => date('Y-m-d H:i:s'),
        ]);

        file_put_contents(WRITEPATH . 'uploads/charts/' . $sourceId . '.png', self::MINIMAL_PNG);
    }

    // -------------------------------------------------------------------------
    // POST /ui/sources/merge
    // -------------------------------------------------------------------------

    public function testMergeRequiresAtLeastTwoSourceIds(): void
    {
        $frag = $this->createFragmentedSource('2021-04-27 17:43:16', 222.606, 32.552);

        $result = $this->post('/ui/sources/merge', ['source_ids' => [$frag['source_id']]]);

        $result->assertRedirect();
        $result->assertSessionHas('error');

        $db = \Config\Database::connect('default');
        $this->assertSame(1, $db->table('sources')->where('id', $frag['source_id'])->countAllResults());
    }

    public function testMergeReassignsObservationsAndFrameLinksOntoNewSourceAndDeletesOldOnes(): void
    {
        // Three fragments of the same real comet, same shape as the actual
        // C_2020_R4_ATLAS incident this feature was built for (see
        // observatory-pipeline's CLAUDE.md, Known Issues, and the
        // conversation this was designed in): SkyBot never recognizes it,
        // so every frame mints a new source_id for the same real object.
        $a = $this->createFragmentedSource('2021-04-27 17:43:16', 222.606, 32.552);
        $b = $this->createFragmentedSource('2021-04-27 18:08:05', 222.560, 32.481);
        $c = $this->createFragmentedSource('2021-04-27 18:34:28', 222.487, 32.643);

        $db = \Config\Database::connect('default');

        $result = $this->post('/ui/sources/merge', [
            'source_ids' => [$a['source_id'], $b['source_id'], $c['source_id']],
        ]);

        $result->assertRedirectTo('/ui/charts');
        $result->assertSessionHas('success');

        // Old sources are gone.
        foreach ([$a['source_id'], $b['source_id'], $c['source_id']] as $oldId) {
            $this->assertSame(0, $db->table('sources')->where('id', $oldId)->countAllResults());
        }

        // Exactly one source remains — the freshly-created target (fixture
        // starts from an empty `sources` table, see emptyAppTables()).
        $remaining = $db->table('sources')->get()->getResultArray();
        $this->assertCount(1, $remaining);
        $targetId = $remaining[0]['id'];

        $this->assertNotContains($targetId, [$a['source_id'], $b['source_id'], $c['source_id']]);
        $this->assertSame(3, (int) $remaining[0]['observation_count']);
        $this->assertSame('2021-04-27 17:43:16', $remaining[0]['first_observed_at']);
        $this->assertSame('2021-04-27 18:34:28', $remaining[0]['last_observed_at']);

        // All three observations now point at the target — reassigned in
        // place, not recreated — with their original frame_id/ra/dec intact.
        $obs = $db->table('source_observations')
            ->where('source_id', $targetId)
            ->orderBy('obs_time', 'ASC')
            ->get()->getResultArray();
        $this->assertCount(3, $obs);
        $this->assertSame(
            [$a['frame_id'], $b['frame_id'], $c['frame_id']],
            array_column($obs, 'frame_id')
        );
        $this->assertSame(222.606, (float) $obs[0]['ra']);
        $this->assertSame(32.643, (float) $obs[2]['dec']);

        // frame_sources re-linked onto the target; old links are gone (not
        // just orphaned — see SourceModel::mergeSources()'s docblock on why
        // this can't be a raw reassignment given the uk_frame_source unique
        // key, and is instead done via the idempotent linkSourceToFrame()).
        $links = $db->table('frame_sources')->where('source_id', $targetId)->get()->getResultArray();
        $this->assertCount(3, $links);
        $this->assertSame(
            0,
            $db->table('frame_sources')
                ->whereIn('source_id', [$a['source_id'], $b['source_id'], $c['source_id']])
                ->countAllResults()
        );
    }

    public function testMergeDeletesOldAnomaliesAndChartsIncludingFilesOnDisk(): void
    {
        $a = $this->createFragmentedSource('2021-04-27 17:43:16', 222.606, 32.552);
        $b = $this->createFragmentedSource('2021-04-27 18:34:28', 222.487, 32.643);

        $this->createAnomalyFor($a['source_id'], $a['frame_id']);
        $this->createAnomalyFor($b['source_id'], $b['frame_id']);
        $this->createChartFor($a['source_id']);
        $this->createChartFor($b['source_id']);

        $this->assertFileExists(WRITEPATH . 'uploads/charts/' . $a['source_id'] . '.png');
        $this->assertFileExists(WRITEPATH . 'uploads/charts/' . $b['source_id'] . '.png');

        $result = $this->post('/ui/sources/merge', [
            'source_ids' => [$a['source_id'], $b['source_id']],
        ]);
        $result->assertSessionHas('success');

        $db = \Config\Database::connect('default');

        // Old anomalies deleted outright (never reassigned — see
        // SourceModel::mergeSources()'s docblock: a fresh DETECT_ANOMALIES
        // run against the merged source is expected to replace them, this
        // method never hand-crafts a "merged" anomaly of its own).
        $this->assertSame(0, $db->table('anomalies')->countAllResults());

        // Old charts (DB rows AND files) gone; no chart exists for the new
        // target either (it's brand new — nothing has rendered one yet).
        $this->assertSame(0, $db->table('source_charts')->countAllResults());
        $this->assertFileDoesNotExist(WRITEPATH . 'uploads/charts/' . $a['source_id'] . '.png');
        $this->assertFileDoesNotExist(WRITEPATH . 'uploads/charts/' . $b['source_id'] . '.png');
    }

    public function testMergeIgnoresUnknownIdsButProceedsWithTheValidRest(): void
    {
        $a = $this->createFragmentedSource('2021-04-27 17:43:16', 222.606, 32.552);
        $b = $this->createFragmentedSource('2021-04-27 18:34:28', 222.487, 32.643);

        $result = $this->post('/ui/sources/merge', [
            'source_ids' => [$a['source_id'], $b['source_id'], 'does-not-exist.12345678'],
        ]);

        $result->assertSessionHas('success');
        $this->assertStringContainsString('does-not-exist.12345678', $_SESSION['success']);

        $db        = \Config\Database::connect('default');
        $remaining = $db->table('sources')->get()->getResultArray();
        $this->assertCount(1, $remaining);
        $this->assertSame(2, (int) $remaining[0]['observation_count']);
    }

    // -------------------------------------------------------------------------
    // SourceModel::mergeSources() — direct, lower-level checks
    // -------------------------------------------------------------------------

    public function testMergeSourcesThrowsWithFewerThanTwoExistingSources(): void
    {
        $a = $this->createFragmentedSource('2021-04-27 17:43:16', 222.606, 32.552);

        $this->expectException(\RuntimeException::class);
        (new SourceModel())->mergeSources([$a['source_id'], 'does-not-exist.12345678']);
    }

    public function testMergeSourcesReturnsTargetIdFrameIdsAndMergedCount(): void
    {
        $a = $this->createFragmentedSource('2021-04-27 17:43:16', 222.606, 32.552);
        $b = $this->createFragmentedSource('2021-04-27 18:34:28', 222.487, 32.643);

        $result = (new SourceModel())->mergeSources([$a['source_id'], $b['source_id']]);

        $this->assertSame(2, $result['merged_count']);
        $this->assertSame([], $result['missing_ids']);
        $this->assertCount(2, $result['frame_ids']);
        $this->assertContains($a['frame_id'], $result['frame_ids']);
        $this->assertContains($b['frame_id'], $result['frame_ids']);
        $this->assertNotContains($result['target_id'], [$a['source_id'], $b['source_id']]);
    }
}
