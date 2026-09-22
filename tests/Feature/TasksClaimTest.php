<?php

namespace Tests\Feature;

use Tests\Support\DatabaseTestCase;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * Feature tests for the atomic parts of the task queue (API audit 2026-08-20,
 * findings C2 and H1):
 *   - PATCH /api/v1/tasks/{id} {"status":"RUNNING"} claims a task only while it is PENDING
 *   - POST /api/v1/tasks/{id}/items/progress counts an item exactly once
 *
 * Same conventions as DeleteFrameTaskTest: DatabaseTestCase + FeatureTestTrait, tables emptied
 * with raw DELETE FROM on the 'default' connection, fixtures inserted directly.
 *
 * @internal
 */
final class TasksClaimTest extends DatabaseTestCase
{
    use FeatureTestTrait;

    private const API_KEY = 'your-secret-key-here';

    protected function setUp(): void
    {
        parent::setUp();
        $db = \Config\Database::connect();
        $db->query('DELETE FROM task_items');
        $db->query('DELETE FROM tasks');
    }

    private function authHeaders(): array
    {
        return ['X-API-Key' => self::API_KEY];
    }

    /** @return array{task_id: string, item_id: string} */
    private function createTask(string $status = 'PENDING', int $items = 1): array
    {
        $db     = \Config\Database::connect();
        $taskId = uniqid('', true);
        $db->table('tasks')->insert([
            'id'          => $taskId,
            'type'        => 'ANALYZE',
            'status'      => $status,
            'total_items' => $items,
        ]);

        $itemId = null;
        for ($seq = 0; $seq < $items; $seq++) {
            $itemId = uniqid('', true);
            $db->table('task_items')->insert([
                'id'       => $itemId,
                'task_id'  => $taskId,
                'seq'      => $seq,
                'filename' => "/fits/incoming/frame_{$seq}.fits",
                'status'   => 'PENDING',
            ]);
        }

        return ['task_id' => $taskId, 'item_id' => (string) $itemId];
    }

    private function patchStatus(string $taskId, string $status): \CodeIgniter\Test\TestResponse
    {
        return $this->withHeaders($this->authHeaders())
            ->withBodyFormat('json')
            ->patch("/api/v1/tasks/{$taskId}", ['status' => $status]);
    }

    private function postProgress(string $taskId, string $itemId, string $status): \CodeIgniter\Test\TestResponse
    {
        return $this->withHeaders($this->authHeaders())
            ->withBodyFormat('json')
            ->post("/api/v1/tasks/{$taskId}/items/progress", [
                'items' => [['item_id' => $itemId, 'status' => $status]],
            ]);
    }

    // -------------------------------------------------------------------------
    // C2 — claiming
    // -------------------------------------------------------------------------

    public function testAPendingTaskCanBeClaimedOnce(): void
    {
        $task = $this->createTask();

        $first = $this->patchStatus($task['task_id'], 'RUNNING');
        $first->assertStatus(200);
        $json = json_decode($first->getJSON(), true);
        $this->assertSame('RUNNING', $json['task']['status']);
        $this->assertNotNull($json['task']['started_at']);

        // The second worker to arrive loses the claim and is told so.
        $second = $this->patchStatus($task['task_id'], 'RUNNING');
        $second->assertStatus(409);
        $json = json_decode($second->getJSON(), true);
        $this->assertSame('RUNNING', $json['details']['status']);

        $row = \Config\Database::connect()->table('tasks')->where('id', $task['task_id'])->get()->getRowArray();
        $this->assertSame('RUNNING', $row['status']);
    }

    public function testAClaimOnACancelledTaskIsRefused(): void
    {
        $task = $this->createTask('CANCELLED');

        $this->patchStatus($task['task_id'], 'RUNNING')->assertStatus(409);
    }

    public function testOtherTransitionsStayUnconditional(): void
    {
        $task = $this->createTask('RUNNING');

        // An operator resets a task a crashed worker left RUNNING ...
        $this->patchStatus($task['task_id'], 'PENDING')->assertStatus(200);
        // ... after which it can be claimed again, and then failed by its worker.
        $this->patchStatus($task['task_id'], 'RUNNING')->assertStatus(200);
        $this->patchStatus($task['task_id'], 'FAILED')->assertStatus(200);
    }

    // -------------------------------------------------------------------------
    // H1 — item resolution counts once
    // -------------------------------------------------------------------------

    public function testADuplicateProgressReportIsCountedOnce(): void
    {
        $task = $this->createTask('RUNNING', 2);

        $first = $this->postProgress($task['task_id'], $task['item_id'], 'DONE');
        $first->assertStatus(200);
        $json = json_decode($first->getJSON(), true);
        $this->assertSame('ok', $json['results'][0]['status']);
        $this->assertSame(1, $json['task']['completed_items']);

        $again = $this->postProgress($task['task_id'], $task['item_id'], 'FAILED');
        $again->assertStatus(200);
        $json = json_decode($again->getJSON(), true);
        $this->assertSame('ok', $json['results'][0]['status']);
        $this->assertStringContainsString('Already resolved', $json['results'][0]['note']);
        $this->assertSame(1, $json['task']['completed_items']);
        $this->assertSame(0, $json['task']['failed_items']);
        $this->assertSame('RUNNING', $json['task']['status']);

        $item = \Config\Database::connect()->table('task_items')->where('id', $task['item_id'])->get()->getRowArray();
        $this->assertSame('DONE', $item['status']);  // the losing report did not overwrite it
    }

    public function testAnItemOfAnotherTaskIsNotResolvable(): void
    {
        $mine   = $this->createTask('RUNNING');
        $theirs = $this->createTask('RUNNING');

        $result = $this->postProgress($mine['task_id'], $theirs['item_id'], 'DONE');
        $result->assertStatus(200);
        $json = json_decode($result->getJSON(), true);
        $this->assertSame('error', $json['results'][0]['status']);

        $item = \Config\Database::connect()->table('task_items')->where('id', $theirs['item_id'])->get()->getRowArray();
        $this->assertSame('PENDING', $item['status']);
    }
}
