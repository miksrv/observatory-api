<?php

namespace Tests\Feature;

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * Feature tests for Web\AnomaliesController — specifically the grouping-by-source_id +
 * GENERATE_CHARTS task creation path (POST /ui/anomalies/generate-charts) and chart cleanup
 * (POST /ui/anomalies/delete).
 *
 * Regression coverage for the 2026-08-11 UI report: source_id 6a7be36b4d7578.98132403 had 12
 * MOVING_UNKNOWN + 1 UNKNOWN anomalies, but GENERATE_CHARTS only ever produced ONE chart — because
 * createTask() built one task item per GROUP (picking a single, arbitrary anomaly_type), not one
 * per distinct anomaly_type within that group. See createTask()'s own docblock.
 *
 * @internal
 */
final class AnomaliesGenerateChartsTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    private const MINIMAL_PNG = "\x89PNG\r\n\x1a\n" . 'rest-of-a-fake-but-signed-png-body';

    protected function setUp(): void
    {
        parent::setUp();
        $this->emptyAppTables();
    }

    private function emptyAppTables(): void
    {
        $db = \Config\Database::connect('default');
        $db->query('DELETE FROM task_items');
        $db->query('DELETE FROM tasks');
        $db->query('DELETE FROM source_charts');
        $db->query('DELETE FROM anomalies');
        $db->query('DELETE FROM frame_sources');
        $db->query('DELETE FROM source_observations');
        $db->query('DELETE FROM sources');
        $db->query('DELETE FROM object_stats');
        $db->query('DELETE FROM frames');
    }

    // -------------------------------------------------------------------------
    // Fixtures
    // -------------------------------------------------------------------------

    private function createFrame(array $overrides = []): string
    {
        $db = \Config\Database::connect('default');
        $id = uniqid('', true);
        $db->table('frames')->insert(array_merge([
            'id'           => $id,
            'filename'     => 'anomalies_charts_test_' . uniqid() . '.fits',
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
     * One uncatalogued source with two anomalies of DIFFERENT anomaly_type, on two different
     * frames — the exact shape of the real 2026-08-11 report (minus the count).
     *
     * @return array{source_id: string, anomaly_id_moving: string, anomaly_id_unknown: string}
     */
    private function createSourceWithTwoAnomalyTypes(): array
    {
        $db       = \Config\Database::connect('default');
        $sourceId = uniqid('', true);

        $db->table('sources')->insert([
            'id'                => $sourceId,
            'catalog_name'      => null,
            'catalog_id'        => null,
            'object_type'       => null,
            'first_observed_at' => '2021-04-27 17:00:00',
            'last_observed_at'  => '2021-04-27 18:00:00',
            'observation_count' => 2,
        ]);

        $frameA = $this->createFrame(['obs_time' => '2021-04-27 17:00:00']);
        $frameB = $this->createFrame(['obs_time' => '2021-04-27 18:00:00']);

        $unknownId = uniqid('', true);
        $db->table('anomalies')->insert([
            'id'           => $unknownId,
            'frame_id'     => $frameA,
            'source_id'    => $sourceId,
            'anomaly_type' => 'UNKNOWN',
            'ra'           => 222.60,
            'dec'          => 32.55,
            'is_alert'     => 1,
        ]);

        $movingId = uniqid('', true);
        $db->table('anomalies')->insert([
            'id'           => $movingId,
            'frame_id'     => $frameB,
            'source_id'    => $sourceId,
            'anomaly_type' => 'MOVING_UNKNOWN',
            'ra'           => 222.72,
            'dec'          => 32.62,
            'is_alert'     => 1,
        ]);

        return ['source_id' => $sourceId, 'anomaly_id_unknown' => $unknownId, 'anomaly_id_moving' => $movingId];
    }

    // -------------------------------------------------------------------------
    // GET /ui/anomalies — grouping
    // -------------------------------------------------------------------------

    public function testIndexGroupsBothAnomalyTypesUnderOneSourceRowWithIdsByType(): void
    {
        $fixture = $this->createSourceWithTwoAnomalyTypes();

        $result = $this->get('/ui/anomalies');

        $result->assertStatus(200);
        $body = $result->response()->getBody();

        // One row for the source, both types shown as badges.
        $this->assertStringContainsString('MOVING_UNKNOWN', $body);
        $this->assertStringContainsString('UNKNOWN', $body);

        // The checkbox's JSON payload must carry ids_by_type keyed by BOTH
        // types (not just one), each mapped to its own anomaly id — key
        // order isn't asserted (it follows frames.obs_time DESC, most
        // recent anomaly's type first), only that both are present.
        $matched = preg_match('/name="group_data\[\]"\s*\n?\s*value="([^"]+)"/', $body, $match);
        $this->assertSame(1, $matched, 'expected exactly one group_data[] checkbox in the response body');
        $decoded = json_decode(html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5), true);

        $this->assertSame($fixture['source_id'], $decoded['source_id']);
        $this->assertSame([$fixture['anomaly_id_moving']], $decoded['ids_by_type']['MOVING_UNKNOWN']);
        $this->assertSame([$fixture['anomaly_id_unknown']], $decoded['ids_by_type']['UNKNOWN']);
    }

    // -------------------------------------------------------------------------
    // POST /ui/anomalies/generate-charts
    // -------------------------------------------------------------------------

    public function testGenerateChartsCreatesOneTaskItemPerDistinctAnomalyType(): void
    {
        $fixture = $this->createSourceWithTwoAnomalyTypes();

        $groupData = json_encode([
            'source_id'   => $fixture['source_id'],
            'ids_by_type' => [
                'MOVING_UNKNOWN' => [$fixture['anomaly_id_moving']],
                'UNKNOWN'        => [$fixture['anomaly_id_unknown']],
            ],
            'designation' => null,
        ]);

        $result = $this->post('/ui/anomalies/generate-charts', ['group_data' => [$groupData]]);

        $result->assertSessionHas('success');

        $db    = \Config\Database::connect('default');
        $task  = $db->table('tasks')->where('type', 'GENERATE_CHARTS')->get()->getRowArray();
        $this->assertNotNull($task);
        $this->assertSame(2, (int) $task['total_items']);

        $items = $db->table('task_items')->where('task_id', $task['id'])->orderBy('seq', 'ASC')->get()->getResultArray();
        $this->assertCount(2, $items);

        $typesSeen = [];
        foreach ($items as $item) {
            $this->assertSame($fixture['source_id'], $item['source_id']);
            $payload = json_decode($item['payload'], true);
            $typesSeen[$payload['anomaly_type']] = $payload['anomaly_ids'];
        }

        // Both types present, each item scoped to just ITS OWN anomaly's id
        // — not the whole group's flattened anomaly_ids list.
        $this->assertSame([$fixture['anomaly_id_moving']], $typesSeen['MOVING_UNKNOWN']);
        $this->assertSame([$fixture['anomaly_id_unknown']], $typesSeen['UNKNOWN']);
    }

    public function testGenerateChartsWithSingleTypeGroupStillCreatesOneItem(): void
    {
        $db       = \Config\Database::connect('default');
        $sourceId = uniqid('', true);
        $frameId  = $this->createFrame();

        $db->table('sources')->insert([
            'id' => $sourceId, 'catalog_name' => 'MPC', 'catalog_id' => '4 Vesta',
            'observation_count' => 1,
        ]);
        $anomalyId = uniqid('', true);
        $db->table('anomalies')->insert([
            'id' => $anomalyId, 'frame_id' => $frameId, 'source_id' => $sourceId,
            'anomaly_type' => 'ASTEROID', 'ra' => 202.47, 'dec' => 47.20, 'is_alert' => 1,
        ]);

        $groupData = json_encode([
            'source_id'   => $sourceId,
            'ids_by_type' => ['ASTEROID' => [$anomalyId]],
            'designation' => '4 Vesta',
        ]);

        $result = $this->post('/ui/anomalies/generate-charts', ['group_data' => [$groupData]]);
        $result->assertSessionHas('success');

        $task  = $db->table('tasks')->where('type', 'GENERATE_CHARTS')->get()->getRowArray();
        $this->assertSame(1, (int) $task['total_items']);

        $item    = $db->table('task_items')->where('task_id', $task['id'])->get()->getRowArray();
        $payload = json_decode($item['payload'], true);
        $this->assertSame('ASTEROID', $payload['anomaly_type']);
        $this->assertSame('4 Vesta', $payload['designation']);
    }

    public function testGenerateChartsWithNoSourceIdIsRejected(): void
    {
        $groupData = json_encode([
            'source_id'   => null,
            'ids_by_type' => ['UNKNOWN' => ['some-id']],
            'designation' => null,
        ]);

        $result = $this->post('/ui/anomalies/generate-charts', ['group_data' => [$groupData]]);

        $result->assertSessionHas('error');
        $db = \Config\Database::connect('default');
        $this->assertSame(0, $db->table('tasks')->countAllResults());
    }

    // -------------------------------------------------------------------------
    // POST /ui/anomalies/delete
    // -------------------------------------------------------------------------

    public function testDeleteRemovesBothStyleSuffixedChartFilesForASource(): void
    {
        $fixture = $this->createSourceWithTwoAnomalyTypes();

        $db = \Config\Database::connect('default');
        $db->table('source_charts')->insert([
            'id' => uniqid('', true), 'source_id' => $fixture['source_id'],
            'style' => 'track', 'frame_count' => 1, 'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $db->table('source_charts')->insert([
            'id' => uniqid('', true), 'source_id' => $fixture['source_id'],
            'style' => 'stamp_strip', 'frame_count' => 1, 'updated_at' => date('Y-m-d H:i:s'),
        ]);
        file_put_contents(WRITEPATH . 'uploads/charts/' . $fixture['source_id'] . '_track.png', self::MINIMAL_PNG);
        file_put_contents(WRITEPATH . 'uploads/charts/' . $fixture['source_id'] . '_stamp_strip.png', self::MINIMAL_PNG);

        $groupData = json_encode([
            'source_id'   => $fixture['source_id'],
            'ids_by_type' => [
                'MOVING_UNKNOWN' => [$fixture['anomaly_id_moving']],
                'UNKNOWN'        => [$fixture['anomaly_id_unknown']],
            ],
            'designation' => null,
        ]);

        $result = $this->post('/ui/anomalies/delete', ['group_data' => [$groupData]]);
        $result->assertSessionHas('success');

        $this->assertSame(0, $db->table('anomalies')->countAllResults());
        $this->assertSame(0, $db->table('source_charts')->countAllResults());
        $this->assertFileDoesNotExist(WRITEPATH . 'uploads/charts/' . $fixture['source_id'] . '_track.png');
        $this->assertFileDoesNotExist(WRITEPATH . 'uploads/charts/' . $fixture['source_id'] . '_stamp_strip.png');
    }
}
