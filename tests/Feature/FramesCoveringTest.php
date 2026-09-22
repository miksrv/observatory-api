<?php

namespace Tests\Feature;

use Tests\Support\DatabaseTestCase;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * Feature tests for GET /api/v1/frames/covering
 *
 * @internal
 */
final class FramesCoveringTest extends DatabaseTestCase
{
    use FeatureTestTrait;

    private const API_KEY  = 'your-secret-key-here';
    private const ENDPOINT = '/api/v1/frames/covering';

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
        $db = \Config\Database::connect();
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
     * Insert a frame directly into the DB. Returns the frame id (string).
     */
    private function createFrame(
        float  $raCenterDeg  = 202.4696,
        float  $decCenterDeg = 47.1952,
        float  $fovDeg       = 1.25,
        string $obsTime      = '2024-03-15 22:01:34',
        ?int   $widthPx      = null,
        ?int   $heightPx     = null
    ): string {
        $db = \Config\Database::connect();
        $id = uniqid('', true);
        $db->table('frames')->insert([
            'id'           => $id,
            'filename'     => 'covering_test_' . uniqid() . '.fits',
            'obs_time'     => $obsTime,
            'ra_center'    => $raCenterDeg,
            'dec_center'   => $decCenterDeg,
            'fov_deg'      => $fovDeg,
            'width_px'     => $widthPx,
            'height_px'    => $heightPx,
            'quality_flag' => 'OK',
        ]);

        return $id;
    }

    private function coveringIds(float $ra, float $dec): array
    {
        $result = $this->withHeaders($this->authHeaders())
            ->get(self::ENDPOINT, [
                'ra'          => (string) $ra,
                'dec'         => (string) $dec,
                'before_time' => '2025-01-01T00:00:00Z',
            ]);
        $result->assertStatus(200);

        return array_column(json_decode($result->getJSON(), true)['data'], 'id');
    }

    // -------------------------------------------------------------------------
    // Happy path
    // -------------------------------------------------------------------------

    public function testValidQueryReturnsFramesThatCoverThePoint(): void
    {
        // Frame centred at (202.4696, 47.1952) with FOV 1.25 deg.
        // The query point is the same as the centre — definitely inside.
        $this->createFrame();

        $result = $this->withHeaders($this->authHeaders())
            ->get(self::ENDPOINT, [
                'ra'          => '202.4696',
                'dec'         => '47.1952',
                'before_time' => '2025-01-01T00:00:00Z',
            ]);

        $result->assertStatus(200);
        $json = json_decode($result->getJSON(), true);
        $this->assertArrayHasKey('data', $json);
        $this->assertNotEmpty($json['data']);

        $frame = $json['data'][0];
        $this->assertArrayHasKey('id', $frame);
        $this->assertArrayHasKey('filename', $frame);
        $this->assertArrayHasKey('obs_time', $frame);
        $this->assertArrayHasKey('ra_center', $frame);
        $this->assertArrayHasKey('dec_center', $frame);
        $this->assertArrayHasKey('fov_deg', $frame);
    }

    /**
     * fov_deg is the frame's LONGEST axis, so a fov_deg / 2 circle is inscribed
     * along that side and never reaches the corners. A 4656x3520 frame with a
     * 1.0 deg long axis has its corners 0.627 deg from the centre: a point at
     * 0.56 deg along the diagonal is inside the frame and must be covered,
     * one at 0.66 deg is outside it and must not be.
     */
    public function testAPointInTheFrameCornerIsCovered(): void
    {
        // Frame at the equator so that 1 deg of RA is 1 deg of sky.
        $frameId = $this->createFrame(180.0, 0.0, 1.0, '2024-03-15 22:01:34', 4656, 3520);

        $inside  = 0.56 / M_SQRT2;   // (dRA, dDec) 0.56 deg along the diagonal
        $outside = 0.66 / M_SQRT2;

        $this->assertContains($frameId, $this->coveringIds(180.0 + $inside, $inside));
        $this->assertNotContains($frameId, $this->coveringIds(180.0 + $outside, $outside));
        // Along the long axis the old inscribed circle already agreed: 0.49 in, 0.51 out of the radius.
        $this->assertContains($frameId, $this->coveringIds(180.49, 0.0));
    }

    /**
     * Without pixel dimensions the aspect ratio is unknown, so the square-frame
     * worst case (half-diagonal fov_deg / 2 * sqrt(2) = 0.707 deg) applies.
     */
    public function testUnknownDimensionsFallBackToTheSquareHalfDiagonal(): void
    {
        $frameId = $this->createFrame(180.0, 0.0, 1.0);

        $this->assertContains($frameId, $this->coveringIds(180.0 + 0.66 / M_SQRT2, 0.66 / M_SQRT2));
        $this->assertNotContains($frameId, $this->coveringIds(180.0 + 0.75 / M_SQRT2, 0.75 / M_SQRT2));
    }

    public function testQueryAtPointNoCoverageReturnsEmptyData(): void
    {
        // Query a point far from any frame data
        $result = $this->withHeaders($this->authHeaders())
            ->get(self::ENDPOINT, [
                'ra'          => '0.0001',
                'dec'         => '0.0001',
                'before_time' => '2025-01-01T00:00:00Z',
            ]);

        $result->assertStatus(200);
        $json = json_decode($result->getJSON(), true);
        $this->assertSame([], $json['data']);
    }

    /**
     * A frame observed after before_time must not appear in the results.
     */
    public function testFrameObservedAfterBeforeTimeIsExcluded(): void
    {
        // Insert a frame with obs_time after the before_time cutoff
        $this->createFrame(10.0, 10.0, 5.0, '2030-01-01 00:00:00');

        $result = $this->withHeaders($this->authHeaders())
            ->get(self::ENDPOINT, [
                'ra'          => '10.0',
                'dec'         => '10.0',
                'before_time' => '2025-01-01T00:00:00Z', // strictly before 2030
            ]);

        $result->assertStatus(200);
        $json = json_decode($result->getJSON(), true);
        // The 2030 frame must not appear in the result
        foreach ($json['data'] as $frame) {
            $this->assertNotSame('2030-01-01T00:00:00Z', $frame['obs_time']);
        }
    }

    // -------------------------------------------------------------------------
    // Missing parameters → 400
    // -------------------------------------------------------------------------

    public function testMissingRaReturns400(): void
    {
        $result = $this->withHeaders($this->authHeaders())
            ->get(self::ENDPOINT, [
                'dec'         => '47.1952',
                'before_time' => '2025-01-01T00:00:00Z',
            ]);

        $result->assertStatus(400);
    }

    public function testMissingDecReturns400(): void
    {
        $result = $this->withHeaders($this->authHeaders())
            ->get(self::ENDPOINT, [
                'ra'          => '202.4696',
                'before_time' => '2025-01-01T00:00:00Z',
            ]);

        $result->assertStatus(400);
    }

    public function testMissingBeforeTimeReturns400(): void
    {
        $result = $this->withHeaders($this->authHeaders())
            ->get(self::ENDPOINT, [
                'ra'  => '202.4696',
                'dec' => '47.1952',
            ]);

        $result->assertStatus(400);
    }

    // -------------------------------------------------------------------------
    // Authentication
    // -------------------------------------------------------------------------

    public function testNoApiKeyReturns401(): void
    {
        $result = $this->get(self::ENDPOINT, [
            'ra'          => '202.4696',
            'dec'         => '47.1952',
            'before_time' => '2025-01-01T00:00:00Z',
        ]);

        $result->assertStatus(401);
    }

    // -------------------------------------------------------------------------
    // RA=0/360 seam and pole scaling regressions
    // -------------------------------------------------------------------------

    /**
     * A naive `ra_center BETWEEN ra-fov AND ra+fov` bounding box never wraps
     * at the RA=0/360 seam, so a frame centred at ra_center=359.98 is
     * invisible to a query at ra=0.02 even though it's well inside the FOV.
     */
    public function testCoveringFindsFrameAcrossRaSeam(): void
    {
        $this->createFrame(359.98, 10.0, 1.0, '2024-03-10 00:00:00');

        $result = $this->withHeaders($this->authHeaders())
            ->get(self::ENDPOINT, [
                'ra'          => '0.02',
                'dec'         => '10.0',
                'before_time' => '2025-01-01T00:00:00Z',
            ]);

        $result->assertStatus(200);
        $json = json_decode($result->getJSON(), true);
        $this->assertNotEmpty($json['data'], 'Frame across the RA=0/360 seam must still be found.');
    }

    /**
     * Near the celestial poles, meridians converge — a fixed fov_deg margin
     * used directly as an RA delta under-covers there. (100, 89.5) and
     * (110, 89.5) are only ~157 arcsec apart despite a 10-degree RA gap.
     */
    public function testCoveringFindsFrameNearPoleDespiteLargeRaDifference(): void
    {
        $this->createFrame(100.0, 89.5, 1.0, '2024-03-10 00:00:00');

        $result = $this->withHeaders($this->authHeaders())
            ->get(self::ENDPOINT, [
                'ra'          => '110.0',
                'dec'         => '89.5',
                'before_time' => '2025-01-01T00:00:00Z',
            ]);

        $result->assertStatus(200);
        $json = json_decode($result->getJSON(), true);
        $this->assertNotEmpty($json['data'], 'Frame near the pole must still be found despite the large RA delta.');
    }
}
