<?php

namespace Tests\Feature;

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * Feature tests for:
 *   GET /api/v1/stats/objects
 *   GET /api/v1/stats/objects/{object}
 *
 * @internal
 */
final class StatsTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    private const API_KEY = 'your-secret-key-here';

    // -------------------------------------------------------------------------
    // Test lifecycle
    // -------------------------------------------------------------------------

    protected function setUp(): void
    {
        parent::setUp();
        $this->emptyAppTables();
    }

    private function emptyAppTables(): void
    {
        $db = \Config\Database::connect('default');
        $db->query('DELETE FROM anomalies');
        $db->query('DELETE FROM frame_sources');
        $db->query('DELETE FROM source_observations');
        $db->query('DELETE FROM sources');
        $db->query('DELETE FROM object_stats');
        $db->query('DELETE FROM frames');
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function authHeaders(): array
    {
        return ['X-API-Key' => self::API_KEY];
    }

    /**
     * Create a frame via API and return its ID.
     */
    private function createFrameViaApi(array $overrides = []): string
    {
        $payload = array_merge([
            'filename'     => 'test_frame_' . uniqid() . '.fits',
            'obs_time'     => '2024-03-15T22:01:34Z',
            'ra_center'    => 202.4696,
            'dec_center'   => 47.1952,
            'fov_deg'      => 1.25,
            'quality_flag' => 'OK',
            'observation'  => [
                'object'  => 'M51',
                'exptime' => 120.0,
                'filter'  => 'L',
                'airmass' => 1.23,
            ],
            'qc' => [
                'fwhm_median' => 3.2,
            ],
        ], $overrides);

        $result = $this->withHeaders($this->authHeaders())
            ->withBodyFormat('json')
            ->post('/api/v1/frames', $payload);

        $json = json_decode($result->getJSON(), true);
        return $json['id'];
    }

    // -------------------------------------------------------------------------
    // GET /api/v1/stats/objects — happy paths
    // -------------------------------------------------------------------------

    public function testStatsObjectsReturnsEmptyWhenNoData(): void
    {
        $result = $this->withHeaders($this->authHeaders())
            ->get('/api/v1/stats/objects');

        $result->assertStatus(200);
        $json = json_decode($result->getJSON(), true);
        $this->assertArrayHasKey('data', $json);
        $this->assertSame([], $json['data']);
    }

    public function testStatsObjectsReturnsAggregatedData(): void
    {
        // Create 3 frames for M51 with different filters
        $this->createFrameViaApi([
            'observation' => ['object' => 'M51', 'exptime' => 120.0, 'filter' => 'L'],
        ]);
        $this->createFrameViaApi([
            'observation' => ['object' => 'M51', 'exptime' => 180.0, 'filter' => 'R'],
        ]);
        $this->createFrameViaApi([
            'observation' => ['object' => 'M51', 'exptime' => 180.0, 'filter' => 'L'],
        ]);

        // Create 1 frame for NGC 7000
        $this->createFrameViaApi([
            'observation' => ['object' => 'NGC 7000', 'exptime' => 300.0, 'filter' => 'Ha'],
        ]);

        $result = $this->withHeaders($this->authHeaders())
            ->get('/api/v1/stats/objects');

        $result->assertStatus(200);
        $json = json_decode($result->getJSON(), true);
        
        $this->assertCount(2, $json['data']);

        // Find M51 stats
        $m51 = null;
        foreach ($json['data'] as $obj) {
            if ($obj['object'] === 'M51') {
                $m51 = $obj;
                break;
            }
        }

        $this->assertNotNull($m51);
        $this->assertSame(3, $m51['total_frames']);
        $this->assertSame(480.0, $m51['total_exposure_sec']); // 120 + 180 + 180
        $this->assertContains('L', $m51['filters']);
        $this->assertContains('R', $m51['filters']);
    }

    public function testStatsObjectsFiltersByObjectName(): void
    {
        // Create frames for different objects
        $this->createFrameViaApi(['observation' => ['object' => 'M51', 'exptime' => 120.0, 'filter' => 'L']]);
        $this->createFrameViaApi(['observation' => ['object' => 'M31', 'exptime' => 120.0, 'filter' => 'L']]);
        $this->createFrameViaApi(['observation' => ['object' => 'NGC 7000', 'exptime' => 120.0, 'filter' => 'Ha']]);

        // Filter by "M" should return M51 and M31
        $result = $this->withHeaders($this->authHeaders())
            ->get('/api/v1/stats/objects', ['object' => 'M']);

        $result->assertStatus(200);
        $json = json_decode($result->getJSON(), true);
        
        $this->assertCount(2, $json['data']);
        $objects = array_column($json['data'], 'object');
        $this->assertContains('M51', $objects);
        $this->assertContains('M31', $objects);
        $this->assertNotContains('NGC 7000', $objects);
    }

    // -------------------------------------------------------------------------
    // GET /api/v1/stats/objects/{object} — happy paths
    // -------------------------------------------------------------------------

    public function testStatsObjectDetailReturnsBreakdownByFilter(): void
    {
        // Create frames with different filters
        $this->createFrameViaApi([
            'observation' => ['object' => 'M51', 'exptime' => 120.0, 'filter' => 'L', 'airmass' => 1.2],
            'qc' => ['fwhm_median' => 2.8],
        ]);
        $this->createFrameViaApi([
            'observation' => ['object' => 'M51', 'exptime' => 180.0, 'filter' => 'L', 'airmass' => 1.3],
            'qc' => ['fwhm_median' => 3.0],
        ]);
        $this->createFrameViaApi([
            'observation' => ['object' => 'M51', 'exptime' => 300.0, 'filter' => 'Ha', 'airmass' => 1.5],
            'qc' => ['fwhm_median' => 3.5],
        ]);

        $result = $this->withHeaders($this->authHeaders())
            ->get('/api/v1/stats/objects/M51');

        $result->assertStatus(200);
        $json = json_decode($result->getJSON(), true);

        $this->assertSame('M51', $json['object']);
        
        // Check summary
        $this->assertSame(3, $json['summary']['total_frames']);
        $this->assertSame(600.0, $json['summary']['total_exposure_sec']); // 120 + 180 + 300

        // Check by_filter
        $this->assertCount(2, $json['by_filter']);

        // Find L filter stats
        $lFilter = null;
        foreach ($json['by_filter'] as $f) {
            if ($f['filter'] === 'L') {
                $lFilter = $f;
                break;
            }
        }

        $this->assertNotNull($lFilter);
        $this->assertSame(2, $lFilter['frame_count']);
        $this->assertSame(300.0, $lFilter['total_exposure_sec']); // 120 + 180
        $this->assertEqualsWithDelta(2.9, $lFilter['avg_fwhm'], 0.01); // (2.8 + 3.0) / 2
        $this->assertEqualsWithDelta(1.25, $lFilter['avg_airmass'], 0.01); // (1.2 + 1.3) / 2
    }

    public function testStatsObjectDetailReturns404ForUnknownObject(): void
    {
        $result = $this->withHeaders($this->authHeaders())
            ->get('/api/v1/stats/objects/UNKNOWN_OBJECT');

        $result->assertStatus(404);
        $json = json_decode($result->getJSON(), true);
        $this->assertArrayHasKey('error', $json);
    }

    public function testStatsObjectDetailHandlesUrlEncodedNames(): void
    {
        // Create frame with space in name
        $this->createFrameViaApi([
            'observation' => ['object' => 'NGC 7000', 'exptime' => 300.0, 'filter' => 'Ha'],
        ]);

        // URL encoded: "NGC 7000" -> "NGC%207000"
        $result = $this->withHeaders($this->authHeaders())
            ->get('/api/v1/stats/objects/NGC%207000');

        $result->assertStatus(200);
        $json = json_decode($result->getJSON(), true);
        $this->assertSame('NGC 7000', $json['object']);
    }

    // -------------------------------------------------------------------------
    // Error paths
    // -------------------------------------------------------------------------

    public function testStatsObjectsNoApiKeyReturns401(): void
    {
        $result = $this->get('/api/v1/stats/objects');
        $result->assertStatus(401);
    }

    public function testStatsObjectDetailNoApiKeyReturns401(): void
    {
        $result = $this->get('/api/v1/stats/objects/M51');
        $result->assertStatus(401);
    }

    // -------------------------------------------------------------------------
    // Auto-update on frame creation
    // -------------------------------------------------------------------------

    public function testFrameCreationUpdatesObjectStats(): void
    {
        // Create first frame
        $this->createFrameViaApi([
            'observation' => ['object' => 'M51', 'exptime' => 120.0, 'filter' => 'L'],
        ]);

        // Check stats
        $result = $this->withHeaders($this->authHeaders())
            ->get('/api/v1/stats/objects/M51');

        $result->assertStatus(200);
        $json = json_decode($result->getJSON(), true);
        $this->assertSame(1, $json['summary']['total_frames']);

        // Create second frame
        $this->createFrameViaApi([
            'observation' => ['object' => 'M51', 'exptime' => 180.0, 'filter' => 'L'],
        ]);

        // Check stats again
        $result = $this->withHeaders($this->authHeaders())
            ->get('/api/v1/stats/objects/M51');

        $result->assertStatus(200);
        $json = json_decode($result->getJSON(), true);
        $this->assertSame(2, $json['summary']['total_frames']);
        $this->assertSame(300.0, $json['summary']['total_exposure_sec']);
    }

    public function testFrameWithoutObjectDoesNotCreateStats(): void
    {
        // Create frame without object
        $result = $this->withHeaders($this->authHeaders())
            ->withBodyFormat('json')
            ->post('/api/v1/frames', [
                'filename'     => 'test_frame.fits',
                'obs_time'     => '2024-03-15T22:01:34Z',
                'ra_center'    => 202.4696,
                'dec_center'   => 47.1952,
                'fov_deg'      => 1.25,
                'quality_flag' => 'OK',
                // No observation.object
            ]);

        $result->assertStatus(201);

        // Check that no stats were created
        $statsResult = $this->withHeaders($this->authHeaders())
            ->get('/api/v1/stats/objects');

        $statsJson = json_decode($statsResult->getJSON(), true);
        $this->assertSame([], $statsJson['data']);
    }
}

