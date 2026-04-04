<?php

namespace Tests\Feature;

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * Feature tests for batch API endpoints:
 *   POST /api/v1/sources/near/batch
 *   POST /api/v1/frames/covering/batch
 *
 * @internal
 */
final class BatchTest extends CIUnitTestCase
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

    private function createFrame(array $overrides = []): string
    {
        $db = \Config\Database::connect('default');
        $id = uniqid('', true);
        $db->table('frames')->insert(array_merge([
            'id'           => $id,
            'filename'     => 'test_frame_' . uniqid() . '.fits',
            'obs_time'     => '2024-03-15 22:01:34',
            'ra_center'    => 202.4696,
            'dec_center'   => 47.1952,
            'fov_deg'      => 1.25,
            'quality_flag' => 'OK',
        ], $overrides));

        return $id;
    }

    private function createSource(array $data = []): string
    {
        $db = \Config\Database::connect('default');
        $id = uniqid('', true);
        $db->table('sources')->insert(array_merge([
            'id'                => $id,
            'ra'                => 202.461,
            'dec'               => 47.182,
            'catalog_name'      => 'Gaia DR3',
            'catalog_id'        => 'Gaia DR3 1234567890',
            'object_type'       => 'STAR',
            'first_observed_at' => '2024-01-01 00:00:00',
            'last_observed_at'  => '2024-01-01 00:00:00',
            'observation_count' => 1,
        ], $data));

        return $id;
    }

    // =========================================================================
    // POST /api/v1/sources/near/batch
    // =========================================================================

    public function testSourcesNearBatchReturnsEmptyResultsForEmptyPositions(): void
    {
        $result = $this->withHeaders($this->authHeaders())
            ->withBodyFormat('json')
            ->post('/api/v1/sources/near/batch', [
                'positions'    => [],
                'radius_arcsec' => 5.0,
            ]);

        $result->assertStatus(200);
        $json = json_decode($result->getJSON(), true);
        $this->assertArrayHasKey('results', $json);
        $this->assertEmpty((array) $json['results']);
    }

    public function testSourcesNearBatchReturnsSourcesForEachPosition(): void
    {
        // Create frames and source observations (not just sources)
        $frameId1 = $this->createFrame(['obs_time' => '2024-01-01 00:00:00']);
        $frameId2 = $this->createFrame(['obs_time' => '2024-01-02 00:00:00']);

        // Create source observations directly
        $db = \Config\Database::connect('default');
        $db->table('source_observations')->insert([
            'id'        => uniqid('', true),
            'source_id' => $this->createSource(['ra' => 202.461, 'dec' => 47.182]),
            'frame_id'  => $frameId1,
            'ra'        => 202.461,
            'dec'       => 47.182,
            'mag'       => 14.5,
            'flux'      => 10000.0,
            'obs_time'  => '2024-01-01 00:00:00',
        ]);
        $db->table('source_observations')->insert([
            'id'        => uniqid('', true),
            'source_id' => $this->createSource(['ra' => 202.490, 'dec' => 47.195]),
            'frame_id'  => $frameId2,
            'ra'        => 202.490,
            'dec'       => 47.195,
            'mag'       => 15.0,
            'flux'      => 8000.0,
            'obs_time'  => '2024-01-02 00:00:00',
        ]);

        $result = $this->withHeaders($this->authHeaders())
            ->withBodyFormat('json')
            ->post('/api/v1/sources/near/batch', [
                'positions' => [
                    ['ra' => 202.461, 'dec' => 47.182],  // Should find first observation
                    ['ra' => 202.500, 'dec' => 47.200],  // Should find second observation (close)
                    ['ra' => 100.000, 'dec' => 10.000],  // Should find nothing
                ],
                'radius_arcsec' => 60.0,
            ]);

        $result->assertStatus(200);
        $json = json_decode($result->getJSON(), true);

        $this->assertArrayHasKey('results', $json);
        $this->assertArrayHasKey('0', $json['results']);
        $this->assertArrayHasKey('1', $json['results']);
        $this->assertArrayHasKey('2', $json['results']);

        // First position should find an observation
        $this->assertNotEmpty($json['results']['0']);
        // Check that response has correct fields
        $this->assertArrayHasKey('ra', $json['results']['0'][0]);
        $this->assertArrayHasKey('mag', $json['results']['0'][0]);
        $this->assertArrayHasKey('flux', $json['results']['0'][0]);
        $this->assertArrayHasKey('frame_id', $json['results']['0'][0]);
        $this->assertArrayHasKey('obs_time', $json['results']['0'][0]);

        // Third position should be empty
        $this->assertEmpty($json['results']['2']);
    }

    public function testSourcesNearBatchMissingPositionsReturns400(): void
    {
        $result = $this->withHeaders($this->authHeaders())
            ->withBodyFormat('json')
            ->post('/api/v1/sources/near/batch', [
                'radius_arcsec' => 5.0,
            ]);

        $result->assertStatus(400);
    }

    public function testSourcesNearBatchMissingRadiusReturns400(): void
    {
        $result = $this->withHeaders($this->authHeaders())
            ->withBodyFormat('json')
            ->post('/api/v1/sources/near/batch', [
                'positions' => [['ra' => 202.461, 'dec' => 47.182]],
            ]);

        $result->assertStatus(400);
    }

    public function testSourcesNearBatchInvalidPositionReturns400(): void
    {
        $result = $this->withHeaders($this->authHeaders())
            ->withBodyFormat('json')
            ->post('/api/v1/sources/near/batch', [
                'positions' => [
                    ['ra' => 'invalid', 'dec' => 47.182],
                ],
                'radius_arcsec' => 5.0,
            ]);

        $result->assertStatus(400);
    }

    public function testSourcesNearBatchNoApiKeyReturns401(): void
    {
        $result = $this->withBodyFormat('json')
            ->post('/api/v1/sources/near/batch', [
                'positions' => [['ra' => 202.461, 'dec' => 47.182]],
                'radius_arcsec' => 5.0,
            ]);

        $result->assertStatus(401);
    }

    // =========================================================================
    // POST /api/v1/frames/covering/batch
    // =========================================================================

    public function testFramesCoveringBatchReturnsEmptyResultsForEmptyPositions(): void
    {
        $result = $this->withHeaders($this->authHeaders())
            ->withBodyFormat('json')
            ->post('/api/v1/frames/covering/batch', [
                'positions'   => [],
                'before_time' => '2025-01-01T00:00:00Z',
            ]);

        $result->assertStatus(200);
        $json = json_decode($result->getJSON(), true);
        $this->assertArrayHasKey('results', $json);
        $this->assertEmpty((array) $json['results']);
    }

    public function testFramesCoveringBatchReturnsFramesForEachPosition(): void
    {
        // Create a frame centered at (202.47, 47.20) with FOV 1.25 deg
        $this->createFrame([
            'ra_center'  => 202.47,
            'dec_center' => 47.20,
            'fov_deg'    => 1.25,
            'obs_time'   => '2024-03-10 00:00:00',
        ]);

        $result = $this->withHeaders($this->authHeaders())
            ->withBodyFormat('json')
            ->post('/api/v1/frames/covering/batch', [
                'positions' => [
                    ['ra' => 202.47, 'dec' => 47.20],   // Center - covered
                    ['ra' => 202.50, 'dec' => 47.25],  // Close to center - covered
                    ['ra' => 100.00, 'dec' => 10.00],  // Far away - not covered
                ],
                'before_time' => '2025-01-01T00:00:00Z',
            ]);

        $result->assertStatus(200);
        $json = json_decode($result->getJSON(), true);

        $this->assertArrayHasKey('results', $json);
        $this->assertArrayHasKey('0', $json['results']);
        $this->assertArrayHasKey('1', $json['results']);
        $this->assertArrayHasKey('2', $json['results']);

        // First two positions should find the frame
        $this->assertNotEmpty($json['results']['0']);
        $this->assertNotEmpty($json['results']['1']);

        // Third position should be empty
        $this->assertEmpty($json['results']['2']);
    }

    public function testFramesCoveringBatchSameFrameCanAppearMultipleTimes(): void
    {
        // Create a frame covering a large area
        $frameId = $this->createFrame([
            'ra_center'  => 202.47,
            'dec_center' => 47.20,
            'fov_deg'    => 2.0,
            'obs_time'   => '2024-03-10 00:00:00',
        ]);

        $result = $this->withHeaders($this->authHeaders())
            ->withBodyFormat('json')
            ->post('/api/v1/frames/covering/batch', [
                'positions' => [
                    ['ra' => 202.40, 'dec' => 47.15],
                    ['ra' => 202.50, 'dec' => 47.25],
                ],
                'before_time' => '2025-01-01T00:00:00Z',
            ]);

        $result->assertStatus(200);
        $json = json_decode($result->getJSON(), true);

        // Both positions should find the same frame
        $this->assertNotEmpty($json['results']['0']);
        $this->assertNotEmpty($json['results']['1']);
        $this->assertSame($json['results']['0'][0]['id'], $json['results']['1'][0]['id']);
    }

    public function testFramesCoveringBatchMissingPositionsReturns400(): void
    {
        $result = $this->withHeaders($this->authHeaders())
            ->withBodyFormat('json')
            ->post('/api/v1/frames/covering/batch', [
                'before_time' => '2025-01-01T00:00:00Z',
            ]);

        $result->assertStatus(400);
    }

    public function testFramesCoveringBatchMissingBeforeTimeReturns400(): void
    {
        $result = $this->withHeaders($this->authHeaders())
            ->withBodyFormat('json')
            ->post('/api/v1/frames/covering/batch', [
                'positions' => [['ra' => 202.47, 'dec' => 47.20]],
            ]);

        $result->assertStatus(400);
    }

    public function testFramesCoveringBatchNoApiKeyReturns401(): void
    {
        $result = $this->withBodyFormat('json')
            ->post('/api/v1/frames/covering/batch', [
                'positions'   => [['ra' => 202.47, 'dec' => 47.20]],
                'before_time' => '2025-01-01T00:00:00Z',
            ]);

        $result->assertStatus(401);
    }

    public function testFramesCoveringBatchRespectsBeforeTime(): void
    {
        // Create a frame observed at a specific time
        $this->createFrame([
            'ra_center'  => 202.47,
            'dec_center' => 47.20,
            'fov_deg'    => 1.25,
            'obs_time'   => '2024-03-10 12:00:00',
        ]);

        // Query before the frame was taken
        $result = $this->withHeaders($this->authHeaders())
            ->withBodyFormat('json')
            ->post('/api/v1/frames/covering/batch', [
                'positions' => [
                    ['ra' => 202.47, 'dec' => 47.20],
                ],
                'before_time' => '2024-03-10T00:00:00Z',  // Before the frame
            ]);

        $result->assertStatus(200);
        $json = json_decode($result->getJSON(), true);

        // Should not find the frame (it's after before_time)
        $this->assertEmpty($json['results']['0']);
    }
}

