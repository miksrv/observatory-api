<?php

namespace Tests\Feature;

use App\Models\PipelineModel;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\Test\StreamFilterTrait;
use CodeIgniter\Test\TestResponse;
use Tests\Support\DatabaseTestCase;

/**
 * Feature tests for multi-pipeline support (migration 2026-10-12-000001_AddPipelineRouting):
 *   - per-pipeline API keys: authentication, revocation, rotation, heartbeat (ApiKeyFilter)
 *   - frame ownership on POST /frames, and refusing a cross-pipeline overwrite
 *   - task targeting: POST /tasks targets the caller, GET /tasks?claimable=1, the claim guard
 *   - the Web UI splitting a selection into one task per owning pipeline, and RESTART fan-out
 *   - the `pipeline:*` spark commands
 *
 * Same conventions as TasksClaimTest: DatabaseTestCase + FeatureTestTrait, fixtures inserted
 * directly, tables emptied with raw DELETE FROM in setUp(). Two pipelines, `a` and `b`, are
 * registered fresh for every test, each with its own key.
 *
 * @internal
 */
final class PipelineRoutingTest extends DatabaseTestCase
{
    use FeatureTestTrait;
    use StreamFilterTrait;

    /** @var array<string, array{id: string, key: string}> */
    private array $pipelines = [];

    /** @var array<string, ?string> settings rows changed by a test, restored in tearDown() */
    private array $settingsToRestore = [];

    protected function setUp(): void
    {
        parent::setUp();
        $db = $this->db();
        $db->query('DELETE FROM task_items');
        $db->query('DELETE FROM tasks');
        $db->query('DELETE FROM anomalies');
        $db->query('DELETE FROM frame_sources');
        $db->query('DELETE FROM source_observations');
        $db->query('DELETE FROM sources');
        $db->query('DELETE FROM object_stats');
        $db->query('DELETE FROM frames');
        $db->query("DELETE FROM pipelines WHERE name <> 'default'");

        foreach (['a', 'b'] as $who) {
            $created               = (new PipelineModel())->createWithKey("observatory-{$who}");
            $this->pipelines[$who] = ['id' => $created['pipeline']['id'], 'key' => $created['key']];
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->settingsToRestore as $id => $value) {
            $this->db()->table('settings')->where('id', $id)->update(['value' => $value]);
        }
        parent::tearDown();
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function id(string $who): string
    {
        return $this->pipelines[$who]['id'];
    }

    private function as(string $who): self
    {
        return $this->withHeaders(['X-API-Key' => $this->pipelines[$who]['key']]);
    }

    private function postFrame(string $filename, string $who): TestResponse
    {
        return $this->as($who)
            ->withBodyFormat('json')
            ->post('/api/v1/frames', [
                'filename'     => $filename,
                'obs_time'     => '2026-10-01T20:00:00Z',
                'ra_center'    => 10.0,
                'dec_center'   => 20.0,
                'fov_deg'      => 1.0,
                'quality_flag' => 'OK',
            ]);
    }

    private function createFrame(?string $who, string $obsTime = '2026-10-01 20:00:00'): string
    {
        $id = uniqid('', true);
        $this->db()->table('frames')->insert([
            'id'           => $id,
            'filename'     => 'routing_' . uniqid() . '.fits',
            'pipeline_id'  => $who !== null ? $this->id($who) : null,
            'object'       => 'M31',
            'obs_time'     => $obsTime,
            'ra_center'    => 10.0,
            'dec_center'   => 20.0,
            'fov_deg'      => 1.0,
            'quality_flag' => 'OK',
        ]);

        return $id;
    }

    private function createTask(?string $who, string $type = 'ANALYZE', string $status = 'PENDING'): string
    {
        $id = uniqid('', true);
        $this->db()->table('tasks')->insert([
            'id'          => $id,
            'type'        => $type,
            'status'      => $status,
            'pipeline_id' => $who !== null ? $this->id($who) : null,
            'total_items' => 0,
        ]);

        return $id;
    }

    private function claim(string $taskId, string $who): TestResponse
    {
        return $this->as($who)
            ->withBodyFormat('json')
            ->patch("/api/v1/tasks/{$taskId}", ['status' => 'RUNNING']);
    }

    private function json(TestResponse $response): array
    {
        return json_decode($response->getJSON(), true);
    }

    private function row(string $table, string $id): ?array
    {
        return $this->db()->table($table)->where('id', $id)->get()->getRowArray();
    }

    // -------------------------------------------------------------------------
    // Keys + heartbeat
    // -------------------------------------------------------------------------

    public function testEachPipelineAuthenticatesWithItsOwnKeyAndRecordsItsHeartbeat(): void
    {
        $this->assertNull($this->row('pipelines', $this->id('a'))['last_seen_at']);

        $this->as('a')->get('/api/v1/tasks')->assertStatus(200);

        $this->assertNotNull($this->row('pipelines', $this->id('a'))['last_seen_at']);
        $this->assertNull($this->row('pipelines', $this->id('b'))['last_seen_at']);
    }

    public function testAnUnknownKeyIsRejected(): void
    {
        $this->withHeaders(['X-API-Key' => str_repeat('0', 64)])->get('/api/v1/tasks')->assertStatus(401);
        $this->withHeaders([])->get('/api/v1/tasks')->assertStatus(401);
    }

    public function testOnlyTheKeyHashIsStored(): void
    {
        $row = $this->row('pipelines', $this->id('a'));

        $this->assertSame(hash('sha256', $this->pipelines['a']['key']), $row['key_hash']);
        $this->assertStringNotContainsString($this->pipelines['a']['key'], json_encode($row));
    }

    public function testARevokedKeyIsRejected(): void
    {
        (new PipelineModel())->revoke($this->id('a'));

        $this->as('a')->get('/api/v1/tasks')->assertStatus(401);
        $this->as('b')->get('/api/v1/tasks')->assertStatus(200);
    }

    public function testRotatingAKeyKeepsTheIdentityAndRetiresTheOldKey(): void
    {
        $oldKey = $this->pipelines['a']['key'];
        $newKey = (new PipelineModel())->rotateKey($this->id('a'));

        $this->withHeaders(['X-API-Key' => $oldKey])->get('/api/v1/tasks')->assertStatus(401);

        $frameId = $this->json($this->withHeaders(['X-API-Key' => $newKey])
            ->withBodyFormat('json')
            ->post('/api/v1/frames', [
                'filename' => 'rotated.fits', 'obs_time' => '2026-10-01T20:00:00Z',
                'ra_center' => 1.0, 'dec_center' => 2.0, 'fov_deg' => 1.0, 'quality_flag' => 'OK',
            ]))['id'];
        $this->assertSame($this->id('a'), $this->row('frames', $frameId)['pipeline_id']);
    }

    // -------------------------------------------------------------------------
    // Frame ownership
    // -------------------------------------------------------------------------

    public function testPostFrameRecordsTheCallerAsOwner(): void
    {
        $result = $this->postFrame('owned.fits', 'a');
        $result->assertStatus(201);
        $frameId = $this->json($result)['id'];

        $this->assertSame($this->id('a'), $this->row('frames', $frameId)['pipeline_id']);
        $this->assertSame($this->id('a'), $this->json($this->as('a')->get("/api/v1/frames/{$frameId}"))['frame']['pipeline_id']);
    }

    public function testTheOwnerCanReRegisterItsFrame(): void
    {
        $frameId = $this->json($this->postFrame('owned.fits', 'a'))['id'];

        $again = $this->postFrame('owned.fits', 'a');
        $again->assertStatus(200);
        $this->assertSame($frameId, $this->json($again)['id']);
    }

    public function testAnotherPipelineCannotTakeOverARegisteredFilename(): void
    {
        $frameId = $this->json($this->postFrame('collision.fits', 'a'))['id'];

        $result = $this->postFrame('collision.fits', 'b');
        $result->assertStatus(409);
        $this->assertSame('observatory-a', $this->json($result)['details']['pipeline']);

        $this->assertSame($this->id('a'), $this->row('frames', $frameId)['pipeline_id']);
    }

    public function testAnUnownedFrameIsClaimedByTheFirstPipelineToReRegisterIt(): void
    {
        $frameId  = $this->createFrame(null);
        $filename = $this->row('frames', $frameId)['filename'];

        $this->postFrame($filename, 'b')->assertStatus(200);
        $this->assertSame($this->id('b'), $this->row('frames', $frameId)['pipeline_id']);
    }

    // -------------------------------------------------------------------------
    // Task targeting
    // -------------------------------------------------------------------------

    public function testATaskCreatedThroughTheApiIsTargetedAtItsCreator(): void
    {
        $result = $this->as('a')
            ->withBodyFormat('json')
            ->post('/api/v1/tasks', [
                'type'  => 'ANALYZE',
                'items' => [['filename' => '/fits/incoming/a.fits']],
            ]);
        $result->assertStatus(201);
        $json = $this->json($result);

        $this->assertSame($this->id('a'), $json['pipeline_id']);
        $this->assertSame($this->id('a'), $this->row('tasks', $json['id'])['pipeline_id']);
    }

    public function testClaimableListsOnlyWhatTheCallerMayClaim(): void
    {
        $mine   = $this->createTask('a');
        $shared = $this->createTask(null);
        $theirs = $this->createTask('b');

        $ids = array_column($this->json($this->as('a')->get('/api/v1/tasks?status=PENDING&claimable=1&order=asc'))['data'], 'id');

        $this->assertContains($mine, $ids);
        $this->assertContains($shared, $ids);
        $this->assertNotContains($theirs, $ids);

        // Without the filter the full list is unchanged.
        $this->assertCount(3, $this->json($this->as('a')->get('/api/v1/tasks'))['data']);
    }

    public function testATargetedTaskCanOnlyBeClaimedByItsPipeline(): void
    {
        $taskId = $this->createTask('a');

        $refused = $this->claim($taskId, 'b');
        $refused->assertStatus(409);
        $this->assertSame($this->id('a'), $this->json($refused)['details']['pipeline_id']);
        $this->assertSame('PENDING', $this->row('tasks', $taskId)['status']);

        $claimed = $this->claim($taskId, 'a');
        $claimed->assertStatus(200);
        $this->assertSame($this->id('a'), $this->json($claimed)['task']['claimed_by']);
        $this->assertSame('RUNNING', $this->row('tasks', $taskId)['status']);
    }

    public function testAnUntargetedTaskIsClaimableByAnyoneAndRecordsTheClaimant(): void
    {
        $taskId = $this->createTask(null);

        $this->claim($taskId, 'b')->assertStatus(200);
        $this->assertSame($this->id('b'), $this->row('tasks', $taskId)['claimed_by']);
    }

    public function testResettingATaskToPendingReleasesTheClaim(): void
    {
        $taskId = $this->createTask(null);
        $this->claim($taskId, 'b')->assertStatus(200);

        $this->as('b')->withBodyFormat('json')
            ->patch("/api/v1/tasks/{$taskId}", ['status' => 'PENDING'])
            ->assertStatus(200);

        $this->assertNull($this->row('tasks', $taskId)['claimed_by']);
    }

    // -------------------------------------------------------------------------
    // Web UI
    // -------------------------------------------------------------------------

    public function testAFrameSelectionBecomesOneTaskPerOwningPipeline(): void
    {
        $result = $this->post('/ui/tasks', [
            'type'      => 'ANALYZE',
            'frame_ids' => [$this->createFrame('a'), $this->createFrame('a'), $this->createFrame('b'), $this->createFrame(null)],
        ]);
        $result->assertRedirectTo('/ui/tasks');

        $countByTarget = [];
        foreach ($this->db()->table('tasks')->get()->getResultArray() as $task) {
            $countByTarget[$task['pipeline_id'] ?? ''] = (int) $task['total_items'];
        }
        ksort($countByTarget, SORT_STRING);

        $expected = ['' => 1, $this->id('a') => 2, $this->id('b') => 1];
        ksort($expected, SORT_STRING);
        $this->assertSame($expected, $countByTarget);
    }

    public function testASinglePipelineSelectionStillOpensItsTask(): void
    {
        $result = $this->post('/ui/tasks', ['type' => 'DELETE_FRAME', 'frame_ids' => [$this->createFrame('a')]]);

        $task = $this->db()->table('tasks')->get()->getRowArray();
        $this->assertSame($this->id('a'), $task['pipeline_id']);
        $result->assertRedirectTo('/ui/tasks/' . $task['id']);
    }

    public function testChartsGoToThePipelineHoldingTheLatestEpoch(): void
    {
        $older = $this->createFrame('a', '2026-10-01 20:00:00');
        $newer = $this->createFrame('b', '2026-10-02 20:00:00');

        $sourceId = uniqid('', true);
        $this->db()->table('sources')->insert([
            'id'                => $sourceId,
            'first_observed_at' => '2026-10-01 20:00:00',
            'last_observed_at'  => '2026-10-02 20:00:00',
            'observation_count' => 2,
        ]);
        foreach ([[$older, '2026-10-01 20:00:00'], [$newer, '2026-10-02 20:00:00']] as [$frameId, $obsTime]) {
            $this->db()->table('source_observations')->insert([
                'id'        => uniqid('', true),
                'source_id' => $sourceId,
                'frame_id'  => $frameId,
                'ra'        => 10.0,
                'dec'       => 20.0,
                'obs_time'  => $obsTime,
            ]);
        }

        $this->post('/ui/sources/generate-charts', ['source_id' => $sourceId]);

        $task = $this->db()->table('tasks')->get()->getRowArray();
        $this->assertSame('GENERATE_CHARTS', $task['type']);
        $this->assertSame($this->id('b'), $task['pipeline_id']);
    }

    public function testSavingSettingsRestartsEveryActivePipelineOnce(): void
    {
        $db = $this->db();
        (new PipelineModel())->revoke($this->id('b'));
        $stale = $this->createTask('a', 'RESTART');

        $setting = $db->table('settings')->where('type', 'config')->get()->getRowArray();
        $this->settingsToRestore[$setting['id']] = $setting['value'];

        $this->post('/ui/settings', ['setting_' . $setting['id'] => ($setting['value'] ?? '') . '-changed']);

        $targets = array_column(
            $db->table('tasks')->where('type', 'RESTART')->where('status', 'PENDING')->get()->getResultArray(),
            'pipeline_id',
        );
        sort($targets);
        $defaultId = $db->table('pipelines')->where('name', 'default')->get()->getRowArray()['id'];
        $expected  = [$this->id('a'), $defaultId];
        sort($expected);

        // One per active pipeline; the revoked one gets none.
        $this->assertSame($expected, $targets);
        // The older RESTART queued for observatory-a is superseded, not left to fire twice.
        $this->assertSame('CANCELLED', $this->row('tasks', $stale)['status']);
    }

    public function testUiPagesShowPipelinesByName(): void
    {
        $this->createFrame('a');
        $taskId = $this->createTask('a');
        $this->claim($taskId, 'a')->assertStatus(200);

        $dashboard = $this->get('/ui');
        $dashboard->assertStatus(200);
        $dashboard->assertSee('observatory-a');
        $dashboard->assertSee('online');

        $this->get('/ui/frames?pipeline=observatory-a')->assertSee('observatory-a');
        $this->get('/ui/tasks?pipeline=observatory-a')->assertSee($taskId);
        $this->get('/ui/tasks/' . $taskId)->assertSee('observatory-a');
    }

    // -------------------------------------------------------------------------
    // spark pipeline:*
    // -------------------------------------------------------------------------

    public function testCreateCommandIssuesAWorkingKey(): void
    {
        command('pipeline:create observatory-c');
        $output = $this->getStreamFilterBuffer();

        $this->assertMatchesRegularExpression('/(?<![0-9a-f])[0-9a-f]{64}(?![0-9a-f])/', $output);
        preg_match('/(?<![0-9a-f])([0-9a-f]{64})(?![0-9a-f])/', $output, $m);

        $this->withHeaders(['X-API-Key' => $m[1]])->get('/api/v1/tasks')->assertStatus(200);
        $this->assertNotNull((new PipelineModel())->findByName('observatory-c')['last_seen_at']);
    }

    public function testCreateCommandRefusesADuplicateName(): void
    {
        command('pipeline:create observatory-a');

        $this->assertSame(1, $this->db()->table('pipelines')->where('name', 'observatory-a')->countAllResults());
    }

    public function testRenameCommandKeepsTheKeyWorking(): void
    {
        command('pipeline:rename observatory-a observatory-north');

        $this->assertSame('observatory-north', $this->row('pipelines', $this->id('a'))['name']);
        $this->as('a')->get('/api/v1/tasks')->assertStatus(200);
    }

    public function testRevokeCommandRejectsTheKey(): void
    {
        command('pipeline:revoke observatory-a');

        $this->as('a')->get('/api/v1/tasks')->assertStatus(401);
    }
}
