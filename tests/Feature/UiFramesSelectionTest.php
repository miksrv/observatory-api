<?php

namespace Tests\Feature;

use App\Models\PipelineModel;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\DatabaseTestCase;
use Tests\Support\SignsInToUi;

/**
 * /ui/frames: the QC filter, and creating a task for every frame matching the filter
 * (`all_matching=1`) rather than only the checked rows of one page.
 *
 * @internal
 */
final class UiFramesSelectionTest extends DatabaseTestCase
{
    use FeatureTestTrait;
    use SignsInToUi;

    /** @var array<string, string> who => pipeline id */
    private array $pipelines = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->signInToUi();

        foreach (['task_items', 'tasks', 'anomalies', 'source_charts', 'frame_sources', 'source_observations', 'sources', 'object_stats', 'frames'] as $table) {
            $this->db()->query("DELETE FROM {$table}");
        }
        $this->db()->query("DELETE FROM pipelines WHERE name <> 'default'");

        foreach (['a', 'b'] as $who) {
            $this->pipelines[$who] = (new PipelineModel())->createWithKey("observatory-{$who}")['pipeline']['id'];
        }
    }

    /**
     * @param list<array{0: string, 1: string, 2: ?string}> $specs [object, quality_flag, pipeline who|null], each $count times
     *
     * @return array<string, list<string>> "object/flag/who" => frame ids
     */
    private function frames(array $specs, int $count): array
    {
        $rows = [];
        $ids  = [];
        $n    = 0;
        foreach ($specs as [$object, $flag, $who]) {
            for ($k = 0; $k < $count; $k++, $n++) {
                $id    = uniqid('', true);
                $ids["{$object}/{$flag}/" . ($who ?? '-')][] = $id;
                $rows[] = [
                    'id'           => $id,
                    'filename'     => "sel_{$n}_" . uniqid() . '.fits',
                    'pipeline_id'  => $who !== null ? $this->pipelines[$who] : null,
                    'object'       => $object,
                    'obs_time'     => date('Y-m-d H:i:s', strtotime('2026-10-01 20:00:00') + $n * 60),
                    'ra_center'    => 10.0,
                    'dec_center'   => 20.0,
                    'fov_deg'      => 1.0,
                    'quality_flag' => $flag,
                ];
            }
        }
        foreach (array_chunk($rows, 500) as $chunk) {
            $this->db()->table('frames')->insertBatch($chunk);
        }

        return $ids;
    }

    /** @return array<string, list<string>> pipeline id ('' = none) => frame ids in the task */
    private function taskFramesByPipeline(): array
    {
        $out = [];
        foreach ($this->db()->table('tasks')->get()->getResultArray() as $task) {
            $items = $this->db()->table('task_items')->where('task_id', $task['id'])->get()->getResultArray();
            $ids   = array_column($items, 'frame_id');
            sort($ids);
            $out[$task['pipeline_id'] ?? ''] = $ids;
        }
        ksort($out, SORT_STRING);

        return $out;
    }

    public function testQcFilterNarrowsTheList(): void
    {
        $ids = $this->frames([['M51', 'OK', 'a'], ['M51', 'BLUR', 'a'], ['M51', 'TRAIL', 'a']], 1);
        $ok    = $ids['M51/OK/a'][0];
        $blur  = $ids['M51/BLUR/a'][0];
        $trail = $ids['M51/TRAIL/a'][0];

        $page = $this->get('/ui/frames?qc=OK');
        $page->assertSee($ok);
        $page->assertDontSee($blur);
        $page->assertSee('Сбросить');

        $page = $this->get('/ui/frames?qc=not_ok');
        $page->assertSee($blur);
        $page->assertSee($trail);
        $page->assertDontSee($ok);

        $page = $this->get('/ui/frames?qc=TRAIL');
        $page->assertSee($trail);
        $page->assertDontSee($blur);

        // The select offers each non-OK flag present.
        $all = $this->get('/ui/frames');
        $this->assertStringContainsString('<option value="BLUR"', $all->getBody());
        $this->assertStringContainsString('<option value="TRAIL"', $all->getBody());
    }

    public function testSearchMatchesAnyPartOfTheFileName(): void
    {
        $this->db()->table('frames')->insertBatch([
            ['id' => 'f-m51', 'filename' => 'M51_Luminance_2026-10-01_001.fits', 'pipeline_id' => $this->pipelines['a'], 'object' => 'M51', 'obs_time' => '2026-10-01 20:00:00', 'ra_center' => 10.0, 'dec_center' => 20.0, 'fov_deg' => 1.0, 'quality_flag' => 'OK'],
            ['id' => 'f-m31', 'filename' => 'M31_Red_2026-10-02_001.fits', 'pipeline_id' => $this->pipelines['a'], 'object' => 'M31', 'obs_time' => '2026-10-02 20:00:00', 'ra_center' => 10.0, 'dec_center' => 20.0, 'fov_deg' => 1.0, 'quality_flag' => 'OK'],
            ['id' => 'f-pct', 'filename' => 'odd_100%_x.fits', 'pipeline_id' => $this->pipelines['a'], 'object' => 'X', 'obs_time' => '2026-10-03 20:00:00', 'ra_center' => 10.0, 'dec_center' => 20.0, 'fov_deg' => 1.0, 'quality_flag' => 'OK'],
        ]);

        $page = $this->get('/ui/frames?search=' . urlencode('Luminance_2026'));
        $page->assertSee('M51_Luminance_2026-10-01_001.fits');
        $page->assertDontSee('M31_Red_2026-10-02_001.fits');
        $page->assertSee('Сбросить');

        // `%` is a literal character, not a wildcard.
        $page = $this->get('/ui/frames?search=' . urlencode('0%_'));
        $page->assertSee('odd_100%_x.fits');
        $page->assertDontSee('M51_Luminance_2026-10-01_001.fits');

        // «All frames matching the filter» takes the search too.
        $this->post('/ui/tasks', $this->uiForm(['type' => 'DETECT_ANOMALIES', 'all_matching' => '1', 'search' => 'M31_']));
        $this->assertSame([$this->pipelines['a'] => ['f-m31']], $this->taskFramesByPipeline());
    }

    /** @return array<string, string> processing status ('' = none recorded) => frame id */
    private function framesByProcessing(): array
    {
        $ids = [];
        foreach (['OK' => null, 'DEGRADED' => '["catalog_failed:Gaia DR3","calibration_failed"]', 'FAILED' => '["astrometry_failed"]', 'SKIPPED' => null, '' => null] as $status => $issues) {
            $id = 'proc-' . ($status === '' ? 'none' : strtolower($status));
            $this->db()->table('frames')->insert([
                'id' => $id, 'filename' => "{$id}.fits", 'pipeline_id' => $this->pipelines['a'], 'object' => 'M81',
                'obs_time' => '2026-10-01 20:00:00', 'ra_center' => 10.0, 'dec_center' => 20.0, 'fov_deg' => 1.0,
                'quality_flag' => $status === 'SKIPPED' ? 'BLUR' : 'OK',
                'processing_status' => $status === '' ? null : $status, 'processing_issues' => $issues,
            ]);
            $ids[$status] = $id;
        }

        return $ids;
    }

    public function testProcessingFilterFindsFramesAnalysedIncompletely(): void
    {
        $ids = $this->framesByProcessing();

        $page = $this->get('/ui/frames?processing=problems');
        $page->assertSee($ids['DEGRADED']);
        $page->assertSee($ids['FAILED']);
        $page->assertDontSee($ids['OK']);
        $page->assertDontSee($ids['SKIPPED']);
        $page->assertDontSee($ids['']);
        // The issues are spelled out under the status, and the filter shows as a badge.
        $page->assertSee('Gaia DR3 не ответил');
        $page->assertSee('astap не решил кадр');
        $page->assertSee('Обработка: С проблемами');

        $page = $this->get('/ui/frames?processing=unknown');
        $page->assertSee($ids['']);
        $page->assertDontSee($ids['OK']);

        $page = $this->get('/ui/frames?processing=SKIPPED');
        $page->assertSee($ids['SKIPPED']);
        $page->assertDontSee($ids['DEGRADED']);

        // «All frames matching the filter» takes it too: re-run ANALYZE on exactly those frames.
        $this->post('/ui/tasks', $this->uiForm(['type' => 'DETECT_ANOMALIES', 'all_matching' => '1', 'processing' => 'problems']));
        $expected = [$ids['DEGRADED'], $ids['FAILED']];
        sort($expected);
        $this->assertSame([$this->pipelines['a'] => $expected], $this->taskFramesByPipeline());
    }

    /**
     * Only «Объект» stays in the bar; every other filter is in the side panel, and an applied one
     * shows as a badge whose × drops just that filter (keeping «Объект» and the sort).
     */
    public function testAppliedPanelFiltersShowAsRemovableBadges(): void
    {
        $this->frames([['M51', 'OK', 'a']], 1);

        $page = $this->get('/ui/frames?object=M51&qc=OK&search=M51_&sort=filename&dir=asc');
        $page->assertSee('QC: OK');
        $page->assertSee('Файл: M51_');
        $body = (string) $page->getBody();
        $this->assertDoesNotMatchRegularExpression('/<aside class="filter-drawer"[^>]*data-open/', $body);
        // × on the QC badge: the same list without qc.
        $this->assertStringContainsString(
            'href="/ui/frames?object=M51&search=M51_&sort=filename&dir=asc"',
            html_entity_decode($body, ENT_QUOTES | ENT_HTML5)
        );
        // The badge itself opens the panel (a link for a browser without JS).
        $this->assertStringContainsString('filters=1', $body);

        // ?filters=1 renders the panel open; the bar has no badges without panel filters.
        $open = (string) $this->get('/ui/frames?object=M51&filters=1')->getBody();
        $this->assertMatchesRegularExpression('/<aside class="filter-drawer"[^>]*data-open/', $open);
        $this->assertStringNotContainsString('class="applied"', $open);
        // The open flag is how the page was opened, not what it lists: sort links drop it.
        preg_match_all('/href="([^"]*sort=[^"]*)"/', html_entity_decode($open, ENT_QUOTES | ENT_HTML5), $sortLinks);
        $this->assertNotEmpty($sortLinks[1]);
        foreach ($sortLinks[1] as $href) {
            $this->assertStringNotContainsString('filters=1', $href);
        }
    }

    public function testAllMatchingCreatesTasksForEveryMatchingFrameAcrossPages(): void
    {
        // 250 matching frames: more than the largest page (200).
        $ids = $this->frames([
            ['M51', 'OK', 'a'], ['M51', 'OK', 'b'], ['M51', 'BLUR', 'a'], ['M31', 'OK', 'a'],
        ], 125);
        $ids['M51/OK/-'] = $this->frames([['M51', 'OK', null]], 3)['M51/OK/-'];

        $result = $this->post('/ui/tasks', $this->uiForm([
            'type'         => 'DETECT_ANOMALIES',
            'frame_ids'    => [$ids['M51/OK/a'][0]],  // the checked page; ignored
            'all_matching' => '1',
            'object'       => 'M51',
            'qc'           => 'OK',
            'pipeline'     => '',
            'date_from'    => '',
            'date_to'      => '',
            'scope_object' => 'M51',
        ]));
        $result->assertRedirectTo('/ui/tasks');

        $expected = [
            ''                     => $ids['M51/OK/-'],
            $this->pipelines['a'] => $ids['M51/OK/a'],
            $this->pipelines['b'] => $ids['M51/OK/b'],
        ];
        foreach ($expected as &$list) {
            sort($list);
        }
        unset($list);
        ksort($expected, SORT_STRING);

        $this->assertSame($expected, $this->taskFramesByPipeline());
    }

    public function testAllMatchingWithOnePipelineFilterMakesOneTask(): void
    {
        $ids = $this->frames([['M51', 'BLUR', 'a'], ['M51', 'TRAIL', 'a'], ['M51', 'OK', 'a'], ['M51', 'BLUR', 'b']], 110);

        $result = $this->post('/ui/tasks', $this->uiForm([
            'type' => 'ANALYZE', 'all_matching' => '1', 'object' => 'M51', 'qc' => 'not_ok', 'pipeline' => 'observatory-a',
        ]));

        $tasks = $this->db()->table('tasks')->get()->getResultArray();
        $this->assertCount(1, $tasks);
        $this->assertSame($this->pipelines['a'], $tasks[0]['pipeline_id']);
        $this->assertSame(220, (int) $tasks[0]['total_items']);
        $result->assertRedirectTo('/ui/tasks/' . $tasks[0]['id']);

        $filenames = array_column($this->db()->table('task_items')->where('task_id', $tasks[0]['id'])->get()->getResultArray(), 'filename');
        $expected  = array_column($this->db()->table('frames')->whereIn('id', array_merge($ids['M51/BLUR/a'], $ids['M51/TRAIL/a']))->get()->getResultArray(), 'filename');
        sort($filenames);
        sort($expected);
        $this->assertSame($expected, $filenames);
    }

    public function testAllMatchingRefusesMoreThanTheCap(): void
    {
        $this->frames([['M51', 'OK', 'a']], 5001);

        $result = $this->post('/ui/tasks', $this->uiForm(['type' => 'DETECT_ANOMALIES', 'all_matching' => '1', 'object' => 'M51']));

        $result->assertSessionHas('error');
        $this->assertSame(0, $this->db()->table('tasks')->countAllResults());
    }

    public function testAllMatchingWithNothingMatchingIsAnError(): void
    {
        $this->frames([['M51', 'OK', 'a']], 2);

        $result = $this->post('/ui/tasks', $this->uiForm(['type' => 'DETECT_ANOMALIES', 'all_matching' => '1', 'object' => 'M82']));

        $result->assertSessionHas('error');
        $this->assertSame(0, $this->db()->table('tasks')->countAllResults());
    }

    public function testWithoutAllMatchingOnlyTheCheckedFramesAreTaken(): void
    {
        $ids     = $this->frames([['M51', 'OK', 'a']], 5);
        $checked = array_slice($ids['M51/OK/a'], 0, 2);

        // Filter fields present but all_matching off (the disabled fieldset is never sent, but
        // a stray value must not change the meaning either).
        $this->post('/ui/tasks', $this->uiForm([
            'type' => 'DETECT_ANOMALIES', 'frame_ids' => $checked, 'object' => 'M51', 'all_matching' => '0',
        ]))->assertRedirectTo('/ui/tasks/' . $this->db()->table('tasks')->get()->getRowArray()['id']);

        sort($checked);
        $this->assertSame([$this->pipelines['a'] => $checked], $this->taskFramesByPipeline());
    }

    public function testThePageOffersTheMatchingSelectionWithTheFilter(): void
    {
        $this->frames([['M51', 'OK', 'a']], 30);

        $page = $this->get('/ui/frames?object=M51&qc=OK&per_page=25');
        $this->assertStringContainsString('data-match-total="30"', $page->getBody());
        $page->assertSee('Выбрать все 30 кадров по фильтру');
        $this->assertStringContainsString('<input type="hidden" name="qc" value="OK">', $page->getBody());
        $this->assertStringContainsString('<input type="hidden" name="all_matching" value="1">', $page->getBody());
    }
}
