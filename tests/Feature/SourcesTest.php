<?php

namespace Tests\Feature;

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * Feature tests for:
 *   POST /api/v1/frames/{id}/sources
 *   GET  /api/v1/sources/near
 *
 * @internal
 */
final class SourcesTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    private const API_KEY       = 'your-secret-key-here';
    private const ENDPOINT_NEAR = '/api/v1/sources/near';

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
     * Insert a frame directly into the DB and return its id (string).
     */
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

    /**
     * Insert a source directly into the DB and return its id (string).
     */
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

    private function sourcesEndpoint(string $frameId): string
    {
        return "/api/v1/frames/{$frameId}/sources";
    }

    private function threeSources(): array
    {
        return [
            ['ra' => 202.461, 'dec' => 47.182, 'mag' => 14.23, 'flux' => 45230.5, 'object_type' => 'STAR'],
            ['ra' => 202.463, 'dec' => 47.184, 'mag' => 15.10, 'flux' => 22000.0, 'object_type' => 'STAR'],
            ['ra' => 202.458, 'dec' => 47.179, 'mag' => 13.80, 'flux' => 60000.1, 'object_type' => 'STAR'],
        ];
    }

    // -------------------------------------------------------------------------
    // POST /api/v1/frames/{id}/sources — happy paths
    // -------------------------------------------------------------------------

    public function testValidSourcesArrayReturns201WithCorrectCount(): void
    {
        $frameId = $this->createFrame();

        $result = $this->withHeaders($this->authHeaders())
            ->withBodyFormat('json')
            ->post($this->sourcesEndpoint($frameId), [
                'filename' => 'test.fits',
                'sources'  => $this->threeSources(),
            ]);

        $result->assertStatus(201);
        $json = json_decode($result->getJSON(), true);
        $this->assertSame('Sources saved successfully', $json['message']);
        $this->assertSame(3, $json['count']);
        $this->assertSame(3, $json['new_sources']);
        $this->assertSame(0, $json['matched_sources']);
    }

    public function testEmptySourcesArrayReturns201WithCountZero(): void
    {
        $frameId = $this->createFrame();

        $result = $this->withHeaders($this->authHeaders())
            ->withBodyFormat('json')
            ->post($this->sourcesEndpoint($frameId), [
                'filename' => 'test.fits',
                'sources'  => [],
            ]);

        $result->assertStatus(201);
        $json = json_decode($result->getJSON(), true);
        $this->assertSame(0, $json['count']);
        $this->assertSame(0, $json['new_sources']);
        $this->assertSame(0, $json['matched_sources']);
    }

    public function testSourceMatchingWorks(): void
    {
        // Create first frame and save sources
        $frameId1 = $this->createFrame(['obs_time' => '2024-01-01 00:00:00']);

        $result1 = $this->withHeaders($this->authHeaders())
            ->withBodyFormat('json')
            ->post($this->sourcesEndpoint($frameId1), [
                'filename' => 'test1.fits',
                'sources'  => $this->threeSources(),
            ]);

        $result1->assertStatus(201);
        $json1 = json_decode($result1->getJSON(), true);
        $this->assertSame(3, $json1['new_sources']);

        // Create second frame and save same sources — they should match
        $frameId2 = $this->createFrame(['obs_time' => '2024-01-02 00:00:00']);

        $result2 = $this->withHeaders($this->authHeaders())
            ->withBodyFormat('json')
            ->post($this->sourcesEndpoint($frameId2), [
                'filename' => 'test2.fits',
                'sources'  => $this->threeSources(),
            ]);

        $result2->assertStatus(201);
        $json2 = json_decode($result2->getJSON(), true);
        $this->assertSame(3, $json2['count']);
        $this->assertSame(0, $json2['new_sources']);
        $this->assertSame(3, $json2['matched_sources']);
    }

    // -------------------------------------------------------------------------
    // POST /api/v1/frames/{id}/sources — error paths
    // -------------------------------------------------------------------------

    public function testNonExistentFrameIdReturns404(): void
    {
        $result = $this->withHeaders($this->authHeaders())
            ->withBodyFormat('json')
            ->post('/api/v1/frames/nonexistent.12345678/sources', [
                'filename' => 'test.fits',
                'sources'  => [],
            ]);

        $result->assertStatus(404);
    }

    public function testMissingSourcesFieldReturns400(): void
    {
        $frameId = $this->createFrame();

        $result = $this->withHeaders($this->authHeaders())
            ->withBodyFormat('json')
            ->post($this->sourcesEndpoint($frameId), [
                'filename' => 'test.fits',
                // 'sources' intentionally omitted
            ]);

        $result->assertStatus(400);
    }

    public function testMissingFilenameFieldReturns400(): void
    {
        $frameId = $this->createFrame();

        $result = $this->withHeaders($this->authHeaders())
            ->withBodyFormat('json')
            ->post($this->sourcesEndpoint($frameId), [
                // 'filename' intentionally omitted
                'sources' => $this->threeSources(),
            ]);

        $result->assertStatus(400);
    }

    public function testPostSourcesNoApiKeyReturns401(): void
    {
        $result = $this->withBodyFormat('json')
            ->post('/api/v1/frames/anyid.12345678/sources', [
                'filename' => 'test.fits',
                'sources'  => [],
            ]);

        $result->assertStatus(401);
    }

    // -------------------------------------------------------------------------
    // GET /api/v1/sources/near — happy paths
    // -------------------------------------------------------------------------

    public function testNearQueryReturnsSourcesWithinRadius(): void
    {
        // Create sources directly
        $this->createSource(['ra' => 202.461, 'dec' => 47.182]);
        $this->createSource(['ra' => 202.463, 'dec' => 47.184]);
        $this->createSource(['ra' => 202.458, 'dec' => 47.179]);

        // Query near the cluster — radius 60 arcsec
        $result = $this->withHeaders($this->authHeaders())
            ->get(self::ENDPOINT_NEAR, [
                'ra'            => '202.461',
                'dec'           => '47.182',
                'radius_arcsec' => '60',
            ]);

        $result->assertStatus(200);
        $json = json_decode($result->getJSON(), true);
        $this->assertArrayHasKey('data', $json);
        $this->assertNotEmpty($json['data']);
    }

    public function testNearQueryOutsideAllSourcesReturnsEmptyData(): void
    {
        $result = $this->withHeaders($this->authHeaders())
            ->get(self::ENDPOINT_NEAR, [
                'ra'            => '0.0001',   // Far from any test data
                'dec'           => '0.0001',
                'radius_arcsec' => '1',
            ]);

        $result->assertStatus(200);
        $json = json_decode($result->getJSON(), true);
        $this->assertSame([], $json['data']);
    }

    // -------------------------------------------------------------------------
    // GET /api/v1/sources/near — missing parameters → 400
    // -------------------------------------------------------------------------

    public function testNearMissingRaReturns400(): void
    {
        $result = $this->withHeaders($this->authHeaders())
            ->get(self::ENDPOINT_NEAR, [
                'dec'           => '47.182',
                'radius_arcsec' => '60',
            ]);

        $result->assertStatus(400);
    }

    public function testNearMissingDecReturns400(): void
    {
        $result = $this->withHeaders($this->authHeaders())
            ->get(self::ENDPOINT_NEAR, [
                'ra'            => '202.461',
                'radius_arcsec' => '60',
            ]);

        $result->assertStatus(400);
    }

    public function testNearMissingRadiusArcsecReturns400(): void
    {
        $result = $this->withHeaders($this->authHeaders())
            ->get(self::ENDPOINT_NEAR, [
                'ra'  => '202.461',
                'dec' => '47.182',
            ]);

        $result->assertStatus(400);
    }

    public function testNearNoApiKeyReturns401(): void
    {
        $result = $this->get(self::ENDPOINT_NEAR, [
            'ra'            => '202.461',
            'dec'           => '47.182',
            'radius_arcsec' => '60',
        ]);

        $result->assertStatus(401);
    }
}
