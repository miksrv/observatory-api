<?php

namespace Tests\Feature;

use Tests\Support\DatabaseTestCase;
use Tests\Support\SignsInToUi;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * Feature tests for the "looked at it, nothing interesting" review mark: POST /ui/charts/review,
 * POST /ui/anomalies/review|unreview, the review filters on /ui/anomalies and /ui/charts, the
 * dashboard counts, bulk chart deletion (POST /ui/charts/delete), and the mark surviving a
 * DETECT_ANOMALIES re-run (POST /api/v1/frames/{id}/anomalies replaces the frame's anomalies).
 *
 * @internal
 */
final class AnomalyReviewsTest extends DatabaseTestCase
{
    use FeatureTestTrait;
    use SignsInToUi;

    private const API_KEY     = 'your-secret-key-here';
    private const MINIMAL_PNG = "\x89PNG\r\n\x1a\n" . 'rest-of-a-fake-but-signed-png-body';

    protected function setUp(): void
    {
        parent::setUp();
        $this->signInToUi();

        $db = \Config\Database::connect();
        foreach (['anomaly_reviews', 'source_charts', 'anomalies', 'frame_sources', 'source_observations', 'sources', 'object_stats', 'frames'] as $table) {
            $db->query("DELETE FROM {$table}");
        }
    }

    // -------------------------------------------------------------------------
    // Fixtures
    // -------------------------------------------------------------------------

    private function createFrame(string $obsTime = '2025-04-16 20:00:00'): string
    {
        $id = uniqid('', true);
        \Config\Database::connect()->table('frames')->insert([
            'id'           => $id,
            'filename'     => 'review_test_' . uniqid() . '.fits',
            'object'       => 'M51',
            'obs_time'     => $obsTime,
            'ra_center'    => 202.47,
            'dec_center'   => 47.19,
            'fov_deg'      => 1.0,
            'quality_flag' => 'OK',
        ]);

        return $id;
    }

    private function createSource(): string
    {
        $id = uniqid('', true);
        \Config\Database::connect()->table('sources')->insert([
            'id'                => $id,
            'catalog_name'      => null,
            'catalog_id'        => null,
            'first_observed_at' => '2025-04-16 20:00:00',
            'last_observed_at'  => '2025-04-16 20:00:00',
            'observation_count' => 1,
        ]);

        return $id;
    }

    private function createAnomaly(string $frameId, ?string $sourceId, string $type = 'UNKNOWN'): string
    {
        $id = uniqid('', true);
        \Config\Database::connect()->table('anomalies')->insert([
            'id'           => $id,
            'frame_id'     => $frameId,
            'source_id'    => $sourceId,
            'anomaly_type' => $type,
            'ra'           => 202.5,
            'dec'          => 47.2,
            'is_alert'     => in_array($type, ['UNKNOWN', 'MOVING_UNKNOWN', 'SUPERNOVA_CANDIDATE'], true) ? 1 : 0,
        ]);

        return $id;
    }

    private function createChart(string $sourceId, string $style = 'stamp_strip'): void
    {
        \Config\Database::connect()->table('source_charts')->insert([
            'id'          => uniqid('', true),
            'source_id'   => $sourceId,
            'style'       => $style,
            'frame_count' => 2,
            'updated_at'  => '2025-04-17 10:00:00',
        ]);
        $dir = WRITEPATH . 'uploads/charts';
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        file_put_contents($dir . '/' . $sourceId . '_' . $style . '.png', self::MINIMAL_PNG);
    }

    /** The group checkbox value /ui/anomalies posts for these anomalies of one source. */
    private function groupData(?string $sourceId, array $idsByType): string
    {
        return json_encode(['source_id' => $sourceId, 'ids_by_type' => $idsByType, 'designation' => null]);
    }

    private function reviewCount(): int
    {
        return (int) \Config\Database::connect()->query('SELECT COUNT(*) AS n FROM anomaly_reviews')->getRow()->n;
    }

    // -------------------------------------------------------------------------
    // Marking from the anomalies list
    // -------------------------------------------------------------------------

    public function testReviewedAnomalyLeavesTheDefaultListAndShowsUnderReviewed(): void
    {
        $frame  = $this->createFrame();
        $source = $this->createSource();
        $id     = $this->createAnomaly($frame, $source);

        $this->post('/ui/anomalies/review', $this->uiForm([
            'group_data' => [$this->groupData($source, ['UNKNOWN' => [$id]])],
            'note'       => 'Hot pixel, nothing on the frame',
        ]))->assertRedirect();

        $this->assertSame(1, $this->reviewCount());

        $default = $this->get('/ui/anomalies');
        $default->assertOK();
        $this->assertStringNotContainsString($source, $default->getBody());

        $done = $this->get('/ui/anomalies?review=done');
        $this->assertStringContainsString($source, $done->getBody());
        $this->assertStringContainsString('Hot pixel, nothing on the frame', $done->getBody());
    }

    public function testUnreviewBringsItBack(): void
    {
        $frame  = $this->createFrame();
        $source = $this->createSource();
        $id     = $this->createAnomaly($frame, $source);
        $form   = ['group_data' => [$this->groupData($source, ['UNKNOWN' => [$id]])]];

        $this->post('/ui/anomalies/review', $this->uiForm($form));
        $this->post('/ui/anomalies/unreview', $this->uiForm($form))->assertRedirect();

        $this->assertSame(0, $this->reviewCount());
        $this->assertStringContainsString($source, $this->get('/ui/anomalies')->getBody());
    }

    public function testAnAnomalyWithoutASourceCannotBeReviewed(): void
    {
        $frame = $this->createFrame();
        $id    = $this->createAnomaly($frame, null);

        $this->post('/ui/anomalies/review', $this->uiForm([
            'group_data' => [$this->groupData(null, ['UNKNOWN' => [$id]])],
        ]))->assertRedirect();

        $this->assertSame(0, $this->reviewCount());
    }

    public function testGroupActionsOnTheDefaultTabCoverTheReviewedAnomaliesToo(): void
    {
        $source   = $this->createSource();
        $reviewed = $this->createAnomaly($this->createFrame('2025-04-16 20:00:00'), $source);
        $open     = $this->createAnomaly($this->createFrame('2025-04-16 20:05:00'), $source, 'MOVING_UNKNOWN');
        $this->post('/ui/anomalies/review', $this->uiForm([
            'group_data' => [$this->groupData($source, ['UNKNOWN' => [$reviewed]])],
            'note'       => 'first look',
        ]));

        // The default tab lists the group with its one open anomaly, but the group's checkbox
        // carries both, so charts are built for both types and «Удалить» removes both.
        $body = html_entity_decode($this->get('/ui/anomalies')->getBody(), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $this->assertMatchesRegularExpression('/<td class="num">1<\/td>/', $body);
        $this->assertStringContainsString($reviewed, $body);
        $this->assertStringContainsString($open, $body);

        // Marking again with no note keeps the earlier one.
        $this->post('/ui/anomalies/review', $this->uiForm([
            'group_data' => [$this->groupData($source, ['UNKNOWN' => [$reviewed], 'MOVING_UNKNOWN' => [$open]])],
        ]));
        $notes = array_column(\Config\Database::connect()->table('anomaly_reviews')->orderBy('anomaly_type')->get()->getResultArray(), 'note', 'anomaly_type');
        $this->assertSame(['MOVING_UNKNOWN' => null, 'UNKNOWN' => 'first look'], $notes);
    }

    public function testTheEmptyDefaultTabSaysEverythingIsReviewed(): void
    {
        $source = $this->createSource();
        $id     = $this->createAnomaly($this->createFrame(), $source);
        $this->post('/ui/anomalies/review', $this->uiForm([
            'group_data' => [$this->groupData($source, ['UNKNOWN' => [$id]])],
        ]));

        $body = html_entity_decode($this->get('/ui/anomalies')->getBody(), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $this->assertStringContainsString('Всё просмотрено', $body);
    }

    // -------------------------------------------------------------------------
    // Surviving DETECT_ANOMALIES re-runs
    // -------------------------------------------------------------------------

    public function testTheMarkSurvivesADetectRerunOfTheSameFrame(): void
    {
        $frame  = $this->createFrame();
        $source = $this->createSource();
        $id     = $this->createAnomaly($frame, $source);

        $this->post('/ui/anomalies/review', $this->uiForm([
            'group_data' => [$this->groupData($source, ['UNKNOWN' => [$id]])],
            'note'       => 'residual',
        ]));

        // The re-run deletes the frame's anomalies and inserts them again, with new ids.
        $this->withHeaders(['X-API-Key' => self::API_KEY])->withBodyFormat('json')
            ->post('/api/v1/frames/' . $frame . '/anomalies', [
                'filename'  => 'x.fits',
                'anomalies' => [[
                    'anomaly_type' => 'UNKNOWN', 'source_id' => $source, 'ra' => 202.5, 'dec' => 47.2,
                ]],
            ])->assertStatus(201);

        $newId = \Config\Database::connect()->table('anomalies')->get()->getRow()->id;
        $this->assertNotSame($id, $newId);
        $this->assertStringNotContainsString($source, $this->get('/ui/anomalies')->getBody());
        $this->assertStringContainsString('residual', $this->get('/ui/anomalies?review=done')->getBody());
    }

    public function testANewFrameOrANewTypeComesInUnreviewed(): void
    {
        $frame  = $this->createFrame();
        $source = $this->createSource();
        $id     = $this->createAnomaly($frame, $source);

        $this->post('/ui/anomalies/review', $this->uiForm([
            'group_data' => [$this->groupData($source, ['UNKNOWN' => [$id]])],
        ]));

        $later = $this->createFrame('2025-05-01 21:00:00');
        $this->createAnomaly($later, $source);
        $this->createAnomaly($frame, $source, 'MOVING_UNKNOWN');

        $body = $this->get('/ui/anomalies?source_id=' . $source)->getBody();
        $this->assertStringContainsString($source, $body);
        // Two of the three anomalies are open; the reviewed one is not in the default list.
        $this->assertMatchesRegularExpression('/<td class="num">2<\/td>/', $body);
    }

    // -------------------------------------------------------------------------
    // Charts gallery
    // -------------------------------------------------------------------------

    public function testReviewFromChartsMarksEveryCurrentAnomalyOfTheSource(): void
    {
        $source = $this->createSource();
        $this->createAnomaly($this->createFrame('2025-04-16 20:00:00'), $source);
        $this->createAnomaly($this->createFrame('2025-04-16 20:05:00'), $source, 'MOVING_UNKNOWN');
        $other = $this->createSource();
        $this->createAnomaly($this->createFrame('2025-04-16 20:10:00'), $other);
        $this->createChart($source);
        $this->createChart($other);

        $this->post('/ui/charts/review', $this->uiForm([
            'source_ids' => [$source],
            'note'       => 'coma residual in the corner',
        ]))->assertRedirect();

        $this->assertSame(2, $this->reviewCount());

        $done = $this->get('/ui/charts?review=done')->getBody();
        $this->assertStringContainsString($source, $done);
        $this->assertStringNotContainsString($other, $done);

        $open = $this->get('/ui/charts?review=new')->getBody();
        $this->assertStringContainsString($other, $open);
        $this->assertStringNotContainsString($source, $open);
    }

    public function testBulkDeleteRemovesTheChartsButKeepsAnomaliesAndReviews(): void
    {
        $source = $this->createSource();
        $id     = $this->createAnomaly($this->createFrame(), $source);
        $this->createChart($source, 'stamp_strip');
        $this->createChart($source, 'stamp_strip_gif');
        $keep = $this->createSource();
        $this->createChart($keep);

        $this->post('/ui/charts/review', $this->uiForm(['source_ids' => [$source]]));
        $this->post('/ui/charts/delete', $this->uiForm(['source_ids' => [$source]]))->assertRedirect();

        $db = \Config\Database::connect();
        $this->assertSame(0, $db->table('source_charts')->where('source_id', $source)->countAllResults());
        $this->assertSame(1, $db->table('source_charts')->where('source_id', $keep)->countAllResults());
        $this->assertFileDoesNotExist(WRITEPATH . 'uploads/charts/' . $source . '_stamp_strip.png');
        $this->assertFileExists(WRITEPATH . 'uploads/charts/' . $keep . '_stamp_strip.png');
        $this->assertSame(1, $db->table('anomalies')->where('id', $id)->countAllResults());
        $this->assertSame(1, $this->reviewCount());

        @unlink(WRITEPATH . 'uploads/charts/' . $keep . '_stamp_strip.png');
    }

    // -------------------------------------------------------------------------
    // Dashboard and cascades
    // -------------------------------------------------------------------------

    public function testDashboardCountsOnlyUnreviewedAnomalies(): void
    {
        $frame   = $this->createFrame();
        $source  = $this->createSource();
        $id      = $this->createAnomaly($frame, $source);
        $another = $this->createSource();
        $this->createAnomaly($frame, $another);

        $this->post('/ui/anomalies/review', $this->uiForm([
            'group_data' => [$this->groupData($source, ['UNKNOWN' => [$id]])],
        ]));

        $body = html_entity_decode($this->get('/ui')->getBody(), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $this->assertMatchesRegularExpression('/<div class="metric-value">1<\/div>\s*<div class="metric-foot"><span style="color: var\(--red\)">1 алерт<\/span> · 1 проверено/u', $body);
    }

    public function testDeletingTheFrameDeletesItsReviews(): void
    {
        $frame  = $this->createFrame();
        $source = $this->createSource();
        $id     = $this->createAnomaly($frame, $source);
        $this->post('/ui/anomalies/review', $this->uiForm([
            'group_data' => [$this->groupData($source, ['UNKNOWN' => [$id]])],
        ]));

        \Config\Database::connect()->table('frames')->where('id', $frame)->delete();

        $this->assertSame(0, $this->reviewCount());
    }
}
