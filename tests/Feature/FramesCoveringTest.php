<?php

namespace Tests\Feature;

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * Feature tests for GET /api/v1/frames/covering
 *
 * @internal
 */
final class FramesCoveringTest extends CIUnitTestCase
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
        $db = \Config\Database::connect('default');
        $db->query('DELETE FROM anomalies');
        $db->query('DELETE FROM sources');
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
     * Insert a frame directly into the DB. Returns the frame id.
     */
    private function createFrame(
        float  $raCenterDeg  = 202.4696,
        float  $decCenterDeg = 47.1952,
        float  $fovDeg       = 1.25,
        string $obsTime      = '2024-03-15 22:01:34'
    ): int {
        $db = \Config\Database::connect('default');
        $db->table('frames')->insert([
            'filename'     => 'covering_test_' . uniqid() . '.fits',
            'obs_time'     => $obsTime,
            'ra_center'    => $raCenterDeg,
            'dec_center'   => $decCenterDeg,
            'fov_deg'      => $fovDeg,
            'quality_flag' => 'OK',
        ]);

        return (int) $db->insertID();
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
}
