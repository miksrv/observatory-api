<?php

namespace Tests\Feature;

use App\Models\FrameSourceModel;
use App\Models\SourceModel;
use App\Models\SourceObservationModel;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\DatabaseTestCase;

/**
 * POST /frames/{id}/sources under a retried request: the pipeline gives up
 * after 30 s and retries while the first call is still running, and the
 * second call's "no observation row / no link yet" lookups were overtaken by
 * the first call's inserts — a 500 on uk_srcobs_frame_source /
 * uk_frame_source. The writes are now single atomic statements; these tests
 * replay the overtaken lookup against the helpers directly (a feature test
 * can't pause a request between two statements) and check the endpoint's
 * idempotency end to end.
 *
 * @internal
 */
final class SaveSourcesRaceTest extends DatabaseTestCase
{
    use FeatureTestTrait;

    private const API_KEY = 'your-secret-key-here';

    protected function setUp(): void
    {
        parent::setUp();
        $db = $this->db();
        $db->query('DELETE FROM source_charts');
        $db->query('DELETE FROM anomalies');
        $db->query('DELETE FROM frame_sources');
        $db->query('DELETE FROM source_observations');
        $db->query('DELETE FROM sources');
        $db->query('DELETE FROM object_stats');
        $db->query('DELETE FROM frames');
    }

    private function createFrame(string $obsTime = '2024-03-15 22:01:34'): string
    {
        $id = uniqid('', true);
        $this->db()->table('frames')->insert([
            'id'           => $id,
            'filename'     => 'race_' . uniqid() . '.fits',
            'obs_time'     => $obsTime,
            'ra_center'    => 202.4696,
            'dec_center'   => 47.1952,
            'fov_deg'      => 1.25,
            'quality_flag' => 'OK',
        ]);

        return $id;
    }

    private function createSource(string $catalogId): string
    {
        $id = uniqid('', true);
        $this->db()->table('sources')->insert([
            'id'                => $id,
            'catalog_name'      => 'Gaia DR3',
            'catalog_id'        => $catalogId,
            'object_type'       => 'STAR',
            'first_observed_at' => '2024-01-01 00:00:00',
            'last_observed_at'  => '2024-01-01 00:00:00',
            'observation_count' => 1,
        ]);

        return $id;
    }

    private function observation(string $frameId, string $sourceId, float $mag): array
    {
        return [
            'source_id'        => $sourceId,
            'frame_id'         => $frameId,
            'ra'               => 202.461,
            'dec'              => 47.182,
            'mag'              => $mag,
            'mag_err'          => 0.02,
            'flux'             => 1000.0,
            'flux_err'         => null,
            'fwhm'             => 3.1,
            'snr'              => 50.0,
            'elongation'       => 1.1,
            'saturated'        => 0,
            'near_edge'        => 0,
            'from_subtraction' => 0,
            'obs_time'         => '2024-03-15 22:01:34',
        ];
    }

    /**
     * The overtaken lookup: the row was inserted (by "the other request")
     * after this one decided there was none. The upsert updates it in place
     * — last writer's photometry wins, the row keeps its id — and reports
     * that it was not this pairing's first appearance.
     */
    public function testObservationUpsertUpdatesARowInsertedConcurrently(): void
    {
        $frameId  = $this->createFrame();
        $sourceId = $this->createSource('Gaia DR3 1');
        $model    = new SourceObservationModel();

        $this->assertTrue($model->upsertForFrameSource($this->observation($frameId, $sourceId, 14.0)));
        $firstId = $this->db()->table('source_observations')->where('frame_id', $frameId)->get()->getRowArray()['id'];

        $this->assertFalse($model->upsertForFrameSource($this->observation($frameId, $sourceId, 14.5)));
        $this->assertFalse($model->upsertForFrameSource($this->observation($frameId, $sourceId, 14.5)), 'An unchanged duplicate is not an insert either.');

        $rows = $this->db()->table('source_observations')->where('frame_id', $frameId)->get()->getResultArray();
        $this->assertCount(1, $rows);
        $this->assertSame($firstId, $rows[0]['id']);
        $this->assertEqualsWithDelta(14.5, (float) $rows[0]['mag'], 1e-6);
    }

    public function testLinkingTheSameSourceTwiceKeepsOneLink(): void
    {
        $frameId  = $this->createFrame();
        $sourceId = $this->createSource('Gaia DR3 2');
        $model    = new FrameSourceModel();

        $this->assertTrue($model->linkSourceToFrame($frameId, $sourceId));
        $this->assertFalse($model->linkSourceToFrame($frameId, $sourceId));
        $this->assertSame(1, $this->db()->table('frame_sources')->where('frame_id', $frameId)->countAllResults());
    }

    /**
     * A catalog identity created by another connection after this call's
     * lookup found nothing: the insert must not fail on
     * uniq_sources_catalog_identity and must hand back the existing row —
     * inside a transaction, as saveSources() runs it.
     */
    public function testCatalogIdentityCreatedConcurrentlyIsFoundNotDuplicated(): void
    {
        $db    = $this->db();
        $other = \Config\Database::connect(null, false);
        $model = new SourceModel();

        $this->assertNull($model->findByCatalogIdentity('Gaia DR3', 'Gaia DR3 race'));

        $otherId = uniqid('', true);
        $other->table('sources')->insert([
            'id'                => $otherId,
            'catalog_name'      => 'Gaia DR3',
            'catalog_id'        => 'Gaia DR3 race',
            'first_observed_at' => '2024-01-01 00:00:00',
            'last_observed_at'  => '2024-01-01 00:00:00',
            'observation_count' => 1,
        ]);
        $other->close();

        $db->query('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
        $db->transException(true)->transStart();

        try {
            [$row, $created] = $model->createOrFindByCatalogIdentity([
                'catalog_name'      => 'Gaia DR3',
                'catalog_id'        => 'Gaia DR3 race',
                'object_type'       => 'STAR',
                'first_observed_at' => '2024-03-15 22:01:34',
                'last_observed_at'  => '2024-03-15 22:01:34',
                'observation_count' => 1,
            ]);
            $this->assertTrue($db->transComplete());
        } finally {
            $db->transException(false);
        }

        $this->assertFalse($created);
        $this->assertSame($otherId, $row['id']);
        $this->assertSame(1, $db->table('sources')->where('catalog_id', 'Gaia DR3 race')->countAllResults());
    }

    /**
     * End to end: the same payload posted twice — more sources than one
     * transaction chunk, catalogued sources that already existed before the
     * first call — succeeds both times, returns the same parallel
     * source_ids, and bumps each existing source's observation_count once.
     */
    public function testRepeatedCallWithTheSamePayloadSucceedsAndCountsOnce(): void
    {
        $frameId = $this->createFrame('2024-03-15 22:01:34');

        $existing = [];
        $sources  = [];
        for ($k = 0; $k < 120; $k++) {
            $catalogId = 'Gaia DR3 9' . $k;
            if ($k % 3 === 0) {
                $existing[$catalogId] = $this->createSource($catalogId);
            }
            $sources[] = [
                'ra'           => 202.40 + $k * 0.001,
                'dec'          => 47.10 + $k * 0.001,
                'mag'          => 14.0 + $k / 100,
                'catalog_name' => $k % 5 === 4 ? null : 'Gaia DR3',
                'catalog_id'   => $k % 5 === 4 ? null : $catalogId,
            ];
        }
        $sources[] = ['ra' => 'bad', 'dec' => 47.0];

        $post = fn () => $this->withHeaders(['X-API-Key' => self::API_KEY])->withBodyFormat('json')
            ->post("/api/v1/frames/{$frameId}/sources", ['filename' => 'race.fits', 'sources' => $sources]);

        $first = $post();
        $first->assertStatus(201);
        $json1 = json_decode($first->getJSON(), true);

        $second = $post();
        $second->assertStatus(201);
        $json2 = json_decode($second->getJSON(), true);

        $this->assertCount(121, $json1['source_ids']);
        $this->assertNull($json1['source_ids'][120]);
        $this->assertSame($json1['source_ids'], $json2['source_ids']);
        $this->assertSame(0, $json2['new_sources']);
        $this->assertSame(120, $json2['matched_sources']);
        $this->assertSame(0, $json2['retracted_sources']);

        $db = $this->db();
        $this->assertSame(120, $db->table('source_observations')->where('frame_id', $frameId)->countAllResults());
        $this->assertSame(120, $db->table('frame_sources')->where('frame_id', $frameId)->countAllResults());

        $preExisting = 0;
        foreach ($existing as $catalogId => $sourceId) {
            $row = $db->table('sources')->where('id', $sourceId)->get()->getRowArray();
            if ((int) substr($catalogId, strlen('Gaia DR3 9')) % 5 === 4) {
                continue; // posted without its identity
            }
            $preExisting++;
            $this->assertSame(2, (int) $row['observation_count'], "observation_count of {$catalogId}");
            $this->assertSame('2024-03-15 22:01:34', $row['last_observed_at']);
        }
        $this->assertGreaterThan(20, $preExisting);

        foreach ($json1['source_ids'] as $k => $sourceId) {
            if ($sourceId === null || isset(array_flip($existing)[$sourceId])) {
                continue;
            }
            $row = $db->table('sources')->where('id', $sourceId)->get()->getRowArray();
            $this->assertSame(1, (int) $row['observation_count'], "new source at index {$k}");
        }
    }
}
