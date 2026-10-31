<?php

namespace Tests\Feature;

use App\Models\PipelineModel;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\DatabaseTestCase;
use Tests\Support\SignsInToUi;

/**
 * POST /api/v1/pipelines/catalogs — a pipeline's check of the online catalogs it uses
 * (migration 2026-10-31-000001_AddPipelineCatalogStatus), and the dashboard showing it.
 *
 * @internal
 */
final class PipelineCatalogsTest extends DatabaseTestCase
{
    use FeatureTestTrait;
    use SignsInToUi;

    /** @var array{id: string, key: string} */
    private array $pipeline;

    protected function setUp(): void
    {
        parent::setUp();
        $this->signInToUi();
        $this->db()->query("DELETE FROM pipelines WHERE name <> 'default'");
        $created        = (new PipelineModel())->createWithKey('observatory-cat');
        $this->pipeline = ['id' => $created['pipeline']['id'], 'key' => $created['key']];
    }

    private function report(array $body, ?string $key = null): \CodeIgniter\Test\TestResponse
    {
        return $this->withHeaders(['X-API-Key' => $key ?? $this->pipeline['key']])
            ->withBodyFormat('json')
            ->post('/api/v1/pipelines/catalogs', $body);
    }

    private function row(): array
    {
        return (new PipelineModel())->find($this->pipeline['id']);
    }

    public function testAReportIsStoredForTheCallingPipeline(): void
    {
        $response = $this->report(['catalogs' => [
            ['name' => 'Simbad', 'ok' => false, 'latency_ms' => null, 'error' => '  connect timeout  '],
            ['name' => 'Gaia DR3', 'ok' => true, 'latency_ms' => 412, 'error' => null],
        ]]);

        $response->assertStatus(200);
        $this->assertSame(2, json_decode($response->getJSON(), true)['data']['stored']);

        $row = $this->row();
        $this->assertNotNull($row['catalog_checked_at']);
        $this->assertSame([
            ['name' => 'Simbad', 'ok' => false, 'latency_ms' => null, 'error' => 'connect timeout'],
            ['name' => 'Gaia DR3', 'ok' => true, 'latency_ms' => 412, 'error' => null],
        ], PipelineModel::catalogsOf($row));
    }

    public function testANewReportReplacesThePreviousOne(): void
    {
        $this->report(['catalogs' => [['name' => 'Simbad', 'ok' => false]]])->assertStatus(200);
        $this->report(['catalogs' => [['name' => 'Simbad', 'ok' => true, 'latency_ms' => 90]]])->assertStatus(200);

        $this->assertSame(
            [['name' => 'Simbad', 'ok' => true, 'latency_ms' => 90, 'error' => null]],
            PipelineModel::catalogsOf($this->row()),
        );
    }

    public function testAnotherPipelinesRowIsUntouched(): void
    {
        $other = (new PipelineModel())->createWithKey('observatory-other');
        $this->report(['catalogs' => [['name' => 'Simbad', 'ok' => true]]])->assertStatus(200);

        $this->assertSame([], PipelineModel::catalogsOf((new PipelineModel())->find($other['pipeline']['id'])));
    }

    public function testErrorsAreTruncated(): void
    {
        $this->report(['catalogs' => [['name' => 'Simbad', 'ok' => false, 'error' => str_repeat('x', 1000)]]])->assertStatus(200);

        $this->assertSame(300, strlen(PipelineModel::catalogsOf($this->row())[0]['error']));
    }

    /**
     * @dataProvider malformedBodies
     */
    public function testAMalformedReportIsRefused(array $body): void
    {
        $this->report($body)->assertStatus(400);
        $this->assertNull($this->row()['catalog_status']);
    }

    public static function malformedBodies(): array
    {
        return [
            'no catalogs'      => [[]],
            'empty list'       => [['catalogs' => []]],
            'not a list'       => [['catalogs' => ['Simbad' => ['ok' => true]]]],
            'too many'         => [['catalogs' => array_fill(0, 21, ['name' => 'Simbad', 'ok' => true])]],
            'bad name'         => [['catalogs' => [['name' => '<b>x</b>', 'ok' => true]]]],
            'missing name'     => [['catalogs' => [['ok' => true]]]],
            'ok not a boolean' => [['catalogs' => [['name' => 'Simbad', 'ok' => 'yes']]]],
            'negative latency' => [['catalogs' => [['name' => 'Simbad', 'ok' => true, 'latency_ms' => -1]]]],
            'error not string' => [['catalogs' => [['name' => 'Simbad', 'ok' => false, 'error' => ['x']]]]],
        ];
    }

    public function testAnUnknownKeyIsRefused(): void
    {
        $this->report(['catalogs' => [['name' => 'Simbad', 'ok' => true]]], 'not-a-key')->assertStatus(401);
    }

    public function testTheDashboardShowsTheCatalogs(): void
    {
        $this->report(['catalogs' => [
            ['name' => 'Simbad', 'ok' => false, 'error' => 'connect timeout'],
            ['name' => 'Gaia DR3', 'ok' => true, 'latency_ms' => 412],
        ]])->assertStatus(200);

        $page = $this->get('/ui');
        $page->assertStatus(200);
        $page->assertSee('Онлайн-каталоги · недоступно 1');
        $page->assertSee('Gaia DR3');
        $page->assertSee('412 мс');
        $page->assertSee('title="connect timeout"', null);
        $page->assertDontSee('устарело');
    }

    public function testAnOldCheckIsMarkedOutOfDate(): void
    {
        (new PipelineModel())->saveCatalogStatus(
            $this->pipeline['id'],
            [['name' => 'Simbad', 'ok' => true, 'latency_ms' => 50, 'error' => null]],
            gmdate('Y-m-d H:i:s', time() - PipelineModel::CATALOG_STALE_SEC - 60),
        );

        $this->get('/ui')->assertSee('устарело');
    }

    public function testBeforeTheMigrationTheReportIs503AndTheDashboardStillWorks(): void
    {
        $forge = \Config\Database::forge($this->db());
        $forge->dropColumn('pipelines', ['catalog_status', 'catalog_checked_at']);

        try {
            $this->report(['catalogs' => [['name' => 'Simbad', 'ok' => true]]])->assertStatus(503);
            $this->get('/ui')->assertStatus(200);
        } finally {
            $forge->addColumn('pipelines', [
                'catalog_status'     => ['type' => 'TEXT', 'null' => true, 'after' => 'version'],
                'catalog_checked_at' => ['type' => 'DATETIME', 'null' => true, 'after' => 'catalog_status'],
            ]);
        }
    }

    public function testAPipelineThatNeverReportedShowsNoCatalogBlock(): void
    {
        $this->get('/ui')->assertDontSee('Онлайн-каталоги');
    }
}
