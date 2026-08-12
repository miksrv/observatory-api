<?php

namespace Tests\Feature;

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * Feature tests for the DELETE_FRAME task type:
 *   - Api\V1\TasksController::postItemsProgress() — the hook that runs
 *     FrameModel::deleteWithDependents() once a DELETE_FRAME item is reported DONE
 *     (the pipeline reports DONE once it has relocated the frame's file to FITS_REJECTED).
 *   - Web\FramesController::createTask() — creating a DELETE_FRAME task from a checked
 *     set of frames on /ui/frames (POST /ui/tasks).
 *
 * Mirrors this repo's existing Feature test conventions (see FramesCreateTest.php,
 * SourceMergeTest.php, AnomaliesGenerateChartsTest.php): CIUnitTestCase + FeatureTestTrait,
 * app tables emptied via raw DELETE FROM against the 'default' connection (NOT
 * DatabaseTestTrait — its migration handling hardcodes SQLite and fights the real MariaDB
 * schema this app actually uses), and fixture rows inserted directly via
 * \Config\Database::connect('default')->table(...)->insert(...) with uniqid('', true) ids.
 *
 * @internal
 */
final class DeleteFrameTaskTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    private const API_KEY = 'your-secret-key-here';

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

    private function authHeaders(): array
    {
        return ['X-API-Key' => self::API_KEY];
    }

    private function createFrame(array $overrides = []): string
    {
        $db = \Config\Database::connect('default');
        $id = uniqid('', true);
        $db->table('frames')->insert(array_merge([
            'id'           => $id,
            'filename'     => 'delete_frame_test_' . uniqid() . '.fits',
            'object'       => 'M51',
            'obs_time'     => '2024-03-15 22:01:34',
            'ra_center'    => 202.4696,
            'dec_center'   => 47.1952,
            'fov_deg'      => 1.25,
            'quality_flag' => 'OK',
        ], $overrides));

        return $id;
    }

    /**
     * Insert a `sources` row plus one `source_observations` + `frame_sources` pair linking
     * it to the given frame.
     */
    private function linkSourceToFrame(string $sourceId, string $frameId, float $ra, float $dec, string $obsTime): void
    {
        $db = \Config\Database::connect('default');

        $db->table('source_observations')->insert([
            'id'        => uniqid('', true),
            'source_id' => $sourceId,
            'frame_id'  => $frameId,
            'ra'        => $ra,
            'dec'       => $dec,
            'mag'       => 15.2,
            'obs_time'  => $obsTime,
        ]);

        $db->table('frame_sources')->insert([
            'id'        => uniqid('', true),
            'frame_id'  => $frameId,
            'source_id' => $sourceId,
        ]);
    }

    private function createSource(string $obsTime, int $observationCount = 1): string
    {
        $db       = \Config\Database::connect('default');
        $sourceId = uniqid('', true);

        $db->table('sources')->insert([
            'id'                => $sourceId,
            'catalog_name'      => null,
            'catalog_id'        => null,
            'object_type'       => null,
            'first_observed_at' => $obsTime,
            'last_observed_at'  => $obsTime,
            'observation_count' => $observationCount,
        ]);

        return $sourceId;
    }

    private function createAnomalyFor(string $sourceId, string $frameId): string
    {
        $db = \Config\Database::connect('default');
        $id = uniqid('', true);
        $db->table('anomalies')->insert([
            'id'           => $id,
            'frame_id'     => $frameId,
            'source_id'    => $sourceId,
            'anomaly_type' => 'UNKNOWN',
            'ra'           => 202.4696,
            'dec'          => 47.1952,
            'is_alert'     => 1,
        ]);

        return $id;
    }

    /**
     * Create a DELETE_FRAME task with a single item targeting the given frame_id (or an
     * arbitrary, possibly-nonexistent one, for the failure-path test).
     *
     * @return array{task_id: string, item_id: string}
     */
    private function createDeleteFrameTask(?string $frameId): array
    {
        $db     = \Config\Database::connect('default');
        $taskId = uniqid('', true);

        $db->table('tasks')->insert([
            'id'          => $taskId,
            'type'        => 'DELETE_FRAME',
            'status'      => 'RUNNING',
            'total_items' => 1,
        ]);

        $itemId = uniqid('', true);
        $db->table('task_items')->insert([
            'id'       => $itemId,
            'task_id'  => $taskId,
            'seq'      => 0,
            'filename' => null,
            'frame_id' => $frameId,
            'source_id' => null,
            'payload'  => null,
            'status'   => 'PENDING',
        ]);

        return ['task_id' => $taskId, 'item_id' => $itemId];
    }

    private function postProgress(string $taskId, string $itemId, string $status): \CodeIgniter\Test\TestResponse
    {
        return $this->withHeaders($this->authHeaders())
            ->withBodyFormat('json')
            ->post("/api/v1/tasks/{$taskId}/items/progress", [
                'items' => [
                    ['item_id' => $itemId, 'status' => $status],
                ],
            ]);
    }

    // -------------------------------------------------------------------------
    // (a) Happy path — orphaned source purged
    // -------------------------------------------------------------------------

    public function testDeleteFrameCascadesAndPurgesOrphanedSource(): void
    {
        $db = \Config\Database::connect('default');

        $frameId  = $this->createFrame();
        $sourceId = $this->createSource('2024-03-15 22:01:34');
        $this->linkSourceToFrame($sourceId, $frameId, 202.47, 47.20, '2024-03-15 22:01:34');
        $anomalyId = $this->createAnomalyFor($sourceId, $frameId);

        $task = $this->createDeleteFrameTask($frameId);

        $result = $this->postProgress($task['task_id'], $task['item_id'], 'DONE');

        $result->assertStatus(200);
        $json = json_decode($result->getJSON(), true);
        $this->assertSame('ok', $json['results'][0]['status']);
        $this->assertArrayNotHasKey('note', $json['results'][0], 'a clean cascade must not carry a DB-cleanup-failure note');

        // Frame itself is gone.
        $this->assertSame(0, $db->table('frames')->where('id', $frameId)->countAllResults());

        // Everything that only made sense in relation to it cascaded away.
        $this->assertSame(0, $db->table('source_observations')->where('frame_id', $frameId)->countAllResults());
        $this->assertSame(0, $db->table('frame_sources')->where('frame_id', $frameId)->countAllResults());
        $this->assertSame(0, $db->table('anomalies')->where('id', $anomalyId)->countAllResults());

        // The source had zero observations left anywhere -> purged outright.
        $this->assertSame(0, $db->table('sources')->where('id', $sourceId)->countAllResults());

        // Task auto-completed (single-item task, now resolved).
        $this->assertSame('COMPLETED', $json['task']['status']);
        $this->assertSame(1, (int) $json['task']['completed_items']);
    }

    // -------------------------------------------------------------------------
    // (b) Source survives when still observed on another frame
    // -------------------------------------------------------------------------

    public function testDeleteFrameLeavesSourceIntactWhenStillObservedElsewhere(): void
    {
        $db = \Config\Database::connect('default');

        $frameToDelete  = $this->createFrame(['filename' => 'delete_frame_test_a_' . uniqid() . '.fits']);
        $frameSurviving = $this->createFrame(['filename' => 'delete_frame_test_b_' . uniqid() . '.fits']);

        $sourceId = $this->createSource('2024-03-15 22:01:34', 2);
        $this->linkSourceToFrame($sourceId, $frameToDelete, 202.47, 47.20, '2024-03-15 22:01:34');
        $this->linkSourceToFrame($sourceId, $frameSurviving, 202.48, 47.21, '2024-03-16 22:01:34');

        $task = $this->createDeleteFrameTask($frameToDelete);

        $result = $this->postProgress($task['task_id'], $task['item_id'], 'DONE');
        $result->assertStatus(200);

        // Deleted frame is gone; the other frame is untouched.
        $this->assertSame(0, $db->table('frames')->where('id', $frameToDelete)->countAllResults());
        $this->assertSame(1, $db->table('frames')->where('id', $frameSurviving)->countAllResults());

        // The source itself survives — it still has an observation on the surviving frame.
        $this->assertSame(1, $db->table('sources')->where('id', $sourceId)->countAllResults());

        // Only the deleted frame's own observation/link disappeared.
        $this->assertSame(0, $db->table('source_observations')->where('frame_id', $frameToDelete)->countAllResults());
        $this->assertSame(1, $db->table('source_observations')->where('frame_id', $frameSurviving)->countAllResults());
        $this->assertSame(0, $db->table('frame_sources')->where('frame_id', $frameToDelete)->countAllResults());
        $this->assertSame(1, $db->table('frame_sources')->where('frame_id', $frameSurviving)->countAllResults());

        // The surviving frame's own observation is completely untouched.
        $survivingObs = $db->table('source_observations')->where('frame_id', $frameSurviving)->get()->getRowArray();
        $this->assertSame(202.48, (float) $survivingObs['ra']);
        $this->assertSame(47.21, (float) $survivingObs['dec']);
    }

    // -------------------------------------------------------------------------
    // (c) Web\FramesController::createTask() can create a DELETE_FRAME task
    // -------------------------------------------------------------------------

    public function testUiCreateTaskCreatesDeleteFrameTaskWithOneItemPerFrame(): void
    {
        $frameA = $this->createFrame(['filename' => 'delete_frame_ui_a_' . uniqid() . '.fits']);
        $frameB = $this->createFrame(['filename' => 'delete_frame_ui_b_' . uniqid() . '.fits']);

        // The Web UI routes carry NO api_key filter (see Routes.php's own comment on the `ui`
        // group) — no X-API-Key header here, unlike the API assertions above.
        $result = $this->post('/ui/tasks', [
            'type'      => 'DELETE_FRAME',
            'frame_ids' => [$frameA, $frameB],
        ]);

        $result->assertRedirect();
        $result->assertSessionHas('success');

        $db   = \Config\Database::connect('default');
        $task = $db->table('tasks')->where('type', 'DELETE_FRAME')->get()->getRowArray();

        $this->assertNotNull($task);
        $this->assertSame('PENDING', $task['status']);
        $this->assertSame(2, (int) $task['total_items']);

        $items = $db->table('task_items')->where('task_id', $task['id'])->orderBy('seq', 'ASC')->get()->getResultArray();
        $this->assertCount(2, $items);

        $frameIdsSeen = array_column($items, 'frame_id');
        $this->assertContains($frameA, $frameIdsSeen);
        $this->assertContains($frameB, $frameIdsSeen);

        foreach ($items as $item) {
            $this->assertNull($item['filename']);
            $this->assertNull($item['source_id']);
        }
    }

    // -------------------------------------------------------------------------
    // (d) Failure path — item's frame is no longer there by the time it's processed
    // -------------------------------------------------------------------------

    /**
     * DISCREPANCY vs. the original task description: `task_items.frame_id` carries a REAL
     * foreign key to `frames.id` (see CreateTasksTable migration — `ON DELETE SET NULL`, but
     * still enforced on INSERT/UPDATE). So a task_item whose `frame_id` "doesn't correspond to
     * any existing frame row" cannot actually be constructed at all — inserting one fails with a
     * DB-level foreign key violation (verified: doing exactly that in an earlier version of this
     * test threw `CodeIgniter\Database\Exceptions\DatabaseException` from the FK constraint
     * `task_items_frame_id_foreign`). That scenario is therefore not reachable through the real
     * `postItemsProgress()` code path.
     *
     * The realistic near-equivalent this schema DOES allow: two DELETE_FRAME items in the same
     * task both targeting the same frame (e.g. an operator double-selects, or resubmits). Once
     * the first item's DONE report deletes the frame, the FK's `ON DELETE SET NULL` behavior
     * automatically nulls out the SECOND item's `frame_id` too (it pointed at the same now-gone
     * row) — so by the time that second item is reported DONE, `postItemsProgress()`'s own guard
     * (`$item['frame_id'] !== null`) is already false and it skips the cascade-delete call
     * entirely, exactly like the item never had a frame_id in the first place. This is the
     * genuine "nothing to do, don't blow up" edge case this task type has to tolerate, and it
     * still exercises the same 200-OK / no-thrown-exception contract the original point (d) was
     * asking for.
     */
    public function testSecondDeleteFrameItemForAlreadyDeletedFrameStillRecordsProgressWithoutError(): void
    {
        $db = \Config\Database::connect('default');

        $frameId = $this->createFrame();

        // One task, two items, both pointing at the same frame.
        $taskId = uniqid('', true);
        $db->table('tasks')->insert([
            'id' => $taskId, 'type' => 'DELETE_FRAME', 'status' => 'RUNNING', 'total_items' => 2,
        ]);

        $itemId1 = uniqid('', true);
        $itemId2 = uniqid('', true);
        $db->table('task_items')->insert([
            'id' => $itemId1, 'task_id' => $taskId, 'seq' => 0, 'frame_id' => $frameId, 'status' => 'PENDING',
        ]);
        $db->table('task_items')->insert([
            'id' => $itemId2, 'task_id' => $taskId, 'seq' => 1, 'frame_id' => $frameId, 'status' => 'PENDING',
        ]);

        // First item: real cascade delete happens here.
        $first = $this->postProgress($taskId, $itemId1, 'DONE');
        $first->assertStatus(200);
        $firstJson = json_decode($first->getJSON(), true);
        $this->assertSame('ok', $firstJson['results'][0]['status']);
        $this->assertSame(0, $db->table('frames')->where('id', $frameId)->countAllResults());

        // FK's ON DELETE SET NULL already nulled the second item's frame_id out from under it.
        $itemBefore = $db->table('task_items')->where('id', $itemId2)->get()->getRowArray();
        $this->assertNull($itemBefore['frame_id']);

        // Second item: still 200 OK, still recorded DONE, no exception bubbles up even though
        // there's nothing left to delete.
        $second = $this->postProgress($taskId, $itemId2, 'DONE');
        $second->assertStatus(200);
        $secondJson = json_decode($second->getJSON(), true);
        $this->assertSame('ok', $secondJson['results'][0]['status']);
        $this->assertSame('COMPLETED', $secondJson['task']['status']);
        $this->assertSame(2, (int) $secondJson['task']['completed_items']);
        $this->assertSame(0, (int) $secondJson['task']['failed_items']);

        $itemAfter = $db->table('task_items')->where('id', $itemId2)->get()->getRowArray();
        $this->assertSame('DONE', $itemAfter['status']);
    }
}
