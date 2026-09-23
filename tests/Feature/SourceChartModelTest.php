<?php

namespace Tests\Feature;

use App\Models\SourceChartModel;
use Tests\Support\DatabaseTestCase;

/**
 * SourceChartModel::upsertForSource() / upsertForTaskItem() — the atomic
 * upsert that replaced a SELECT-then-INSERT/UPDATE pair (API audit
 * 2026-08-20, finding M3).
 *
 * @internal
 */
final class SourceChartModelTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $db = $this->db();
        $db->query('DELETE FROM source_charts');
        $db->query('DELETE FROM anomalies');
        $db->query('DELETE FROM frame_sources');
        $db->query('DELETE FROM source_observations');
        $db->query('DELETE FROM sources');
    }

    private function createSource(): string
    {
        $id = uniqid('', true);
        $this->db()->table('sources')->insert([
            'id'                => $id,
            'catalog_name'      => 'Gaia DR3',
            'catalog_id'        => 'Gaia DR3 ' . $id,
            'object_type'       => 'STAR',
            'first_observed_at' => '2024-03-15 22:01:34',
            'last_observed_at'  => '2024-03-15 22:01:34',
            'observation_count' => 1,
        ]);

        return $id;
    }

    public function testRepeatUpsertUpdatesInPlaceAndKeepsTheId(): void
    {
        $model    = new SourceChartModel();
        $sourceId = $this->createSource();

        $first  = $model->upsertForSource($sourceId, 'track', 3);
        $second = $model->upsertForSource($sourceId, 'track', 5);

        $this->assertSame($first['id'], $second['id']);
        $this->assertSame(5, (int) $second['frame_count']);
        $this->assertSame(1, $this->db()->table('source_charts')->where('source_id', $sourceId)->countAllResults());
    }

    /**
     * The race, replayed: another request inserted the row between "does it
     * exist?" and "insert it". With a single statement there is no such
     * window — the model must update the row it did not know about, not
     * fail on the unique key.
     */
    public function testARowInsertedBehindTheModelsBackIsUpdatedNotDuplicated(): void
    {
        $model    = new SourceChartModel();
        $sourceId = $this->createSource();
        $this->db()->table('source_charts')->insert([
            'id'          => 'racer-' . uniqid(),
            'source_id'   => $sourceId,
            'style'       => 'stamp_strip',
            'frame_count' => 1,
            'updated_at'  => '2024-01-01 00:00:00',
        ]);

        $row = $model->upsertForSource($sourceId, 'stamp_strip', 7);

        $this->assertStringStartsWith('racer-', $row['id']);
        $this->assertSame(7, (int) $row['frame_count']);
        $this->assertNotSame('2024-01-01 00:00:00', $row['updated_at']);
        $this->assertSame(1, $this->db()->table('source_charts')->where('source_id', $sourceId)->countAllResults());
    }

    public function testStylesOfOneSourceAreSeparateRows(): void
    {
        $model    = new SourceChartModel();
        $sourceId = $this->createSource();

        $model->upsertForSource($sourceId, 'track', 3);
        $model->upsertForSource($sourceId, 'stamp_strip', 3);

        $this->assertSame(2, $this->db()->table('source_charts')->where('source_id', $sourceId)->countAllResults());
    }

    public function testTaskItemUpsertIsKeyedByTaskItemId(): void
    {
        $model  = new SourceChartModel();
        $itemId = uniqid('', true);

        $first  = $model->upsertForTaskItem($itemId, 'catalog_preview', 1);
        $second = $model->upsertForTaskItem($itemId, 'catalog_preview', 1);

        $this->assertSame($first['id'], $second['id']);
        $this->assertNull($second['source_id']);
        $this->assertSame(1, $this->db()->table('source_charts')->where('task_item_id', $itemId)->countAllResults());
    }
}
