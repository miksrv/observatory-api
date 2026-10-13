<?php

namespace Tests\Feature;

use App\Libraries\UiListQuery;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;
use Tests\Support\DatabaseTestCase;
use Tests\Support\SignsInToUi;

/**
 * Sorting and pages on the web UI's lists (App\Libraries\UiListQuery): frames, sources, the
 * anomaly groups and the chart gallery are counted, sorted and paged in SQL, so every row is
 * reachable however many there are.
 *
 * @internal
 */
final class UiListsTest extends DatabaseTestCase
{
    use FeatureTestTrait;
    use SignsInToUi;

    protected function setUp(): void
    {
        parent::setUp();
        $this->signInToUi();

        foreach (['anomalies', 'source_charts', 'task_items', 'tasks', 'source_observations', 'frame_sources', 'sources', 'frames'] as $table) {
            $this->db()->query("DELETE FROM {$table}");
        }
    }

    private function frame(string $filename, string $obsTime): string
    {
        $id = uniqid('', true);
        $this->db()->table('frames')->insert([
            'id'           => $id,
            'filename'     => $filename,
            'obs_time'     => $obsTime,
            'ra_center'    => 10.0,
            'dec_center'   => 20.0,
            'fov_deg'      => 1.0,
            'quality_flag' => 'OK',
            'object'       => 'M31',
        ]);

        return $id;
    }

    private function source(string $catalogId, int $observations = 1): string
    {
        $id = uniqid('', true);
        $this->db()->table('sources')->insert([
            'id'                => $id,
            'catalog_name'      => 'Gaia DR3',
            'catalog_id'        => $catalogId,
            'first_observed_at' => '2026-10-01 20:00:00',
            'last_observed_at'  => '2026-10-01 20:00:00',
            'observation_count' => $observations,
        ]);

        return $id;
    }

    private function anomaly(string $frameId, ?string $sourceId, string $type = 'UNKNOWN'): string
    {
        $id = uniqid('', true);
        $this->db()->table('anomalies')->insert([
            'id'           => $id,
            'frame_id'     => $frameId,
            'source_id'    => $sourceId,
            'anomaly_type' => $type,
            'ra'           => 10.0,
            'dec'          => 20.0,
            'is_alert'     => 0,
        ]);

        return $id;
    }

    /** How many times a marker occurs in a page's body. */
    private function countIn(string $body, string $marker): int
    {
        return substr_count($body, $marker);
    }

    // -------------------------------------------------------------------------
    // UiListQuery
    // -------------------------------------------------------------------------

    private function listFor(array $get, int $total): UiListQuery
    {
        $request = Services::incomingrequest(null, false);
        $request->setGlobal('get', $get);

        return (new UiListQuery(['name' => 'name'], 'name', 'asc', [], 'id', 25, UiListQuery::PER_PAGE, $request))
            ->paginate($total);
    }

    public function testThePageNumbersShowTheEndsAndTheNeighbourhood(): void
    {
        $this->assertSame([1, null, 4, 5, 6, 7, 8, null, 20], $this->listFor(['page' => '6'], 500)->window());
        $this->assertSame([1, 2, 3, null, 20], $this->listFor([], 500)->window());
        // A gap of a single page shows that page instead of "…".
        $this->assertSame([1, 2, 3, 4, 5, 6, null, 20], $this->listFor(['page' => '4'], 500)->window());
        $this->assertSame([1], $this->listFor([], 3)->window());
    }

    public function testAPageBeyondTheLastShowsTheLast(): void
    {
        $list = $this->listFor(['page' => '99', 'per_page' => '25'], 60);

        $this->assertSame(3, $list->page);
        $this->assertSame(51, $list->from());
        $this->assertSame(60, $list->to());
    }

    public function testOnlyWhitelistedSortsAndPageSizesAreTaken(): void
    {
        $list = $this->listFor(['sort' => 'name; DROP TABLE frames', 'dir' => 'sideways', 'per_page' => '7'], 10);

        $this->assertSame('name', $list->sort);
        $this->assertSame('asc', $list->dir);
        $this->assertSame(25, $list->perPage);
        $this->assertSame('name ASC, id ASC', $list->orderBy());
    }

    // -------------------------------------------------------------------------
    // Frames
    // -------------------------------------------------------------------------

    public function testFramesArePagedAndEveryOneIsReachable(): void
    {
        for ($i = 0; $i < 30; $i++) {
            $this->frame(sprintf('frame_%02d.fits', $i), sprintf('2026-10-01 %02d:%02d:00', $i % 24, $i));
        }

        // Read each body before the next request: the test client reuses one response object.
        $first  = $this->get('/ui/frames?per_page=25')->response()->getBody();
        $second = $this->get('/ui/frames?per_page=25&page=2')->response()->getBody();
        $beyond = $this->get('/ui/frames?per_page=25&page=99')->response()->getBody();

        $this->assertSame(25, $this->countIn($first, 'name="frame_ids[]"'));
        $this->assertSame(5, $this->countIn($second, 'name="frame_ids[]"'));
        $this->assertStringContainsString('26–30</span> из', $second);
        // Past the last page: the last page.
        $this->assertSame(5, $this->countIn($beyond, 'name="frame_ids[]"'));

        // Together the pages hold every frame exactly once.
        preg_match_all('/name="frame_ids\[\]" value="([^"]+)"/', $first . $second, $m);
        $this->assertCount(30, array_unique($m[1]));
    }

    public function testFramesSortByAColumnBothWays(): void
    {
        $this->frame('b.fits', '2026-10-01 20:00:00');
        $this->frame('a.fits', '2026-10-01 21:00:00');
        $this->frame('c.fits', '2026-10-01 19:00:00');

        $asc  = $this->get('/ui/frames?sort=filename&dir=asc')->response()->getBody();
        $desc = $this->get('/ui/frames?sort=filename&dir=desc')->response()->getBody();
        // Default: newest observation first.
        $byTime = $this->get('/ui/frames')->response()->getBody();

        $this->assertLessThan(strpos($asc, 'b.fits'), strpos($asc, 'a.fits'));
        $this->assertLessThan(strpos($asc, 'c.fits'), strpos($asc, 'b.fits'));
        $this->assertLessThan(strpos($desc, 'b.fits'), strpos($desc, 'c.fits'));
        $this->assertLessThan(strpos($byTime, 'b.fits'), strpos($byTime, 'a.fits'));
    }

    // -------------------------------------------------------------------------
    // Sources
    // -------------------------------------------------------------------------

    public function testSourcesSortByObservationCount(): void
    {
        $this->source('few', 2);
        $this->source('many', 9);

        $body = $this->get('/ui/sources?sort=observations&dir=desc')->response()->getBody();

        $this->assertLessThan(strpos($body, '>few<'), strpos($body, '>many<'));
    }

    // -------------------------------------------------------------------------
    // Anomalies: pages of groups, not of anomalies
    // -------------------------------------------------------------------------

    public function testAnomalyPagesCountGroupsAndKeepEachGroupWhole(): void
    {
        // 30 sources with two anomalies each, plus 2 anomalies without a source (a group each).
        for ($i = 0; $i < 30; $i++) {
            $source = $this->source(sprintf('S%02d', $i));
            $frameA = $this->frame("a{$i}.fits", sprintf('2026-10-01 %02d:00:00', $i % 24));
            $frameB = $this->frame("b{$i}.fits", sprintf('2026-10-02 %02d:00:00', $i % 24));
            $this->anomaly($frameA, $source, 'UNKNOWN');
            $this->anomaly($frameB, $source, 'MOVING_UNKNOWN');
        }
        $orphanFrame = $this->frame('orphan.fits', '2026-09-01 00:00:00');
        $this->anomaly($orphanFrame, null);
        $this->anomaly($orphanFrame, null);

        $first  = $this->get('/ui/anomalies?per_page=25&sort=designation&dir=asc')->response()->getBody();
        $second = $this->get('/ui/anomalies?per_page=25&sort=designation&dir=asc&page=2')->response()->getBody();

        $this->assertSame(25, $this->countIn($first, 'name="group_data[]"'));
        $this->assertSame(7, $this->countIn($second, 'name="group_data[]"'));

        // A group on either page still carries both of its types.
        preg_match_all('/name="group_data\[\]"\s*value="([^"]+)"/', $first . $second, $m);
        $groups = array_map(static fn ($v) => json_decode(html_entity_decode($v, ENT_QUOTES | ENT_HTML5), true), $m[1]);
        $withSource = array_values(array_filter($groups, static fn ($g) => $g['source_id'] !== null));

        $this->assertCount(30, $withSource);
        $this->assertCount(30, array_unique(array_column($withSource, 'source_id')));
        foreach ($withSource as $g) {
            $this->assertEqualsCanonicalizing(['UNKNOWN', 'MOVING_UNKNOWN'], array_keys($g['ids_by_type']));
        }
        $this->assertCount(2, array_filter($groups, static fn ($g) => $g['source_id'] === null));
    }

    public function testAnomalyGroupsSortByCount(): void
    {
        $frame = $this->frame('f.fits', '2026-10-01 20:00:00');
        $one   = $this->source('ONE');
        $three = $this->source('THREE');
        $this->anomaly($frame, $one);
        foreach (range(1, 3) as $_) {
            $this->anomaly($frame, $three);
        }

        $body = $this->get('/ui/anomalies?sort=count&dir=desc')->response()->getBody();

        $this->assertLessThan(strpos($body, '>ONE<'), strpos($body, '>THREE<'));
    }

    // -------------------------------------------------------------------------
    // Charts
    // -------------------------------------------------------------------------

    public function testTheChartGalleryIsPaged(): void
    {
        for ($i = 0; $i < 30; $i++) {
            $this->db()->table('source_charts')->insert([
                'id'          => uniqid('', true),
                'source_id'   => $this->source("C{$i}"),
                'style'       => 'track',
                'frame_count' => $i,
                'updated_at'  => sprintf('2026-10-01 %02d:00:00', $i % 24),
            ]);
        }

        $first  = $this->get('/ui/charts')->response()->getBody();
        $second = $this->get('/ui/charts?page=2')->response()->getBody();

        // 24 a page by default.
        $this->assertSame(24, $this->countIn($first, 'name="source_ids[]"'));
        $this->assertSame(6, $this->countIn($second, 'name="source_ids[]"'));
    }
}
