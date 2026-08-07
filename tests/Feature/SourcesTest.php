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
     * Insert a source directly into the DB — plus a matching
     * source_observations row (and a frame to satisfy its FK) — and return
     * the source's id (string).
     *
     * `sources` no longer has ra/dec columns of its own (see SourceModel):
     * GET /sources/near and /sources/{id}/observations now derive position
     * from source_observations, so seeding just the `sources` row is not
     * enough to make those endpoints find anything.
     */
    private function createSource(array $data = []): string
    {
        $db = \Config\Database::connect('default');
        $id = uniqid('', true);

        $ra  = $data['ra']  ?? 202.461;
        $dec = $data['dec'] ?? 47.182;

        $row = array_merge([
            'id'                => $id,
            // catalog_id defaults to something unique per call — `sources`
            // now has a UNIQUE(catalog_name, catalog_id) key, and some
            // tests call createSource() more than once without overriding it.
            'catalog_name'      => 'Gaia DR3',
            'catalog_id'        => 'Gaia DR3 ' . $id,
            'object_type'       => 'STAR',
            'first_observed_at' => '2024-01-01 00:00:00',
            'last_observed_at'  => '2024-01-01 00:00:00',
            'observation_count' => 1,
        ], $data);

        unset($row['ra'], $row['dec']);

        $db->table('sources')->insert($row);

        $frameId = $this->createFrame([
            'ra_center'  => $ra,
            'dec_center' => $dec,
            'obs_time'   => $row['first_observed_at'],
        ]);

        $db->table('source_observations')->insert([
            // Plain uniqid('', true), not a suffixed variant — the column is
            // CHAR(24) and uniqid('', true) alone is already ~22 chars.
            'id'        => uniqid('', true),
            'source_id' => $row['id'],
            'frame_id'  => $frameId,
            'ra'        => $ra,
            'dec'       => $dec,
            'obs_time'  => $row['first_observed_at'],
        ]);

        return $row['id'];
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

        // source_ids is positionally parallel to the request's sources[] —
        // the pipeline zips this against its own source list to learn each
        // source's resolved sources.id (see FramesController::saveSources).
        $this->assertArrayHasKey('source_ids', $json);
        $this->assertCount(3, $json['source_ids']);
        foreach ($json['source_ids'] as $sourceId) {
            $this->assertIsString($sourceId);
            $this->assertNotSame('', $sourceId);
        }
        // All three sources are distinct — no accidental collisions.
        $this->assertCount(3, array_unique($json['source_ids']));
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
        $this->assertSame([], $json['source_ids']);
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

        // Re-matching the same catalog identity must resolve to the SAME
        // sources.id as the first call, in the same input order.
        $this->assertSame($json1['source_ids'], $json2['source_ids']);
    }

    /**
     * Regression test for a real incident (2026-08-06): a first-ever
     * detection of a catalog-identified object (e.g. an MPC-matched
     * asteroid) that happens to sit within the 2" position-matching radius
     * of an unrelated, already-known source (e.g. a Gaia DR3 star) must NOT
     * be folded into that unrelated source's row just because
     * findByCatalogIdentity() hasn't seen this exact identity before. It
     * must get its own new `sources` row instead — position matching is
     * reserved for sources with no catalog identity at all.
     */
    public function testCatalogIdentifiedSourceNeverMergesIntoUnrelatedNearbySource(): void
    {
        // An existing Gaia DR3 star, already on record at this sky position.
        $starId = $this->createSource([
            'ra'           => 166.7008,
            'dec'          => 17.4406,
            'catalog_name' => 'Gaia DR3',
            'catalog_id'   => '3971465931154563840',
            'object_type'  => 'STAR',
        ]);

        // A brand-new MPC-matched asteroid, first ever observation of this
        // designation, whose position (this epoch) happens to land within
        // 2" of that star.
        $frameId = $this->createFrame(['obs_time' => '2026-08-06 18:46:53']);

        $result = $this->withHeaders($this->authHeaders())
            ->withBodyFormat('json')
            ->post($this->sourcesEndpoint($frameId), [
                'filename' => 'test.fits',
                'sources'  => [[
                    'ra'           => 166.7005,
                    'dec'          => 17.4409,
                    'mag'          => 17.2,
                    'catalog_name' => 'MPC',
                    'catalog_id'   => '2014 RY1',
                    'object_type'  => 'ASTEROID',
                ]],
            ]);

        $result->assertStatus(201);
        $json = json_decode($result->getJSON(), true);

        $this->assertSame(1, $json['new_sources'], 'Must create a new source, not match the unrelated star.');
        $this->assertSame(0, $json['matched_sources']);
        $this->assertCount(1, $json['source_ids']);
        $this->assertNotSame($starId, $json['source_ids'][0]);

        // The star's own row must stay untouched — still Gaia DR3, still
        // observed only once.
        $db  = \Config\Database::connect('default');
        $star = $db->table('sources')->where('id', $starId)->get()->getRowArray();
        $this->assertSame('Gaia DR3', $star['catalog_name']);
        $this->assertSame(1, (int) $star['observation_count']);
    }

    public function testSourceIdsAlignPositionallyAndNullOutSkippedEntries(): void
    {
        $frameId = $this->createFrame();

        $result = $this->withHeaders($this->authHeaders())
            ->withBodyFormat('json')
            ->post($this->sourcesEndpoint($frameId), [
                'filename' => 'test.fits',
                'sources'  => [
                    ['ra' => 202.461, 'dec' => 47.182, 'mag' => 14.23],
                    ['dec' => 47.184, 'mag' => 15.10], // missing ra — skipped
                    ['ra' => 202.458, 'dec' => 47.179, 'mag' => 13.80],
                ],
            ]);

        $result->assertStatus(201);
        $json = json_decode($result->getJSON(), true);

        $this->assertCount(3, $json['source_ids']);
        $this->assertIsString($json['source_ids'][0]);
        $this->assertNull($json['source_ids'][1]);
        $this->assertIsString($json['source_ids'][2]);
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

    // -------------------------------------------------------------------------
    // GET /api/v1/sources/near — RA=0/360 seam and pole scaling regressions
    // -------------------------------------------------------------------------

    /**
     * A naive `ra BETWEEN ra-delta AND ra+delta` bounding box never wraps at
     * the RA=0/360 seam, so a source at ra=359.999 is invisible to a query
     * at ra=0.001 even though the two points are only ~7 arcsec apart.
     */
    public function testNearQueryFindsSourceAcrossRaSeam(): void
    {
        $this->createSource(['ra' => 359.999, 'dec' => 10.0]);

        $result = $this->withHeaders($this->authHeaders())
            ->get(self::ENDPOINT_NEAR, [
                'ra'            => '0.001',
                'dec'           => '10.0',
                'radius_arcsec' => '10',
            ]);

        $result->assertStatus(200);
        $json = json_decode($result->getJSON(), true);
        $this->assertNotEmpty($json['data'], 'Source across the RA=0/360 seam must still be found.');
    }

    /**
     * Near the celestial poles, meridians converge — a fixed angular radius
     * spans far more RA *degrees* than at the equator. A bounding box that
     * uses the radius directly as an RA delta under-covers there and can
     * silently miss real matches.
     */
    public function testNearQueryFindsSourceNearPoleDespiteLargeRaDifference(): void
    {
        // (100, 89.5) and (110, 89.5) are ~314 arcsec apart on the sky despite
        // a 10-degree RA difference, because cos(89.5°) ≈ 0.0087.
        $this->createSource(['ra' => 100.0, 'dec' => 89.5]);

        $result = $this->withHeaders($this->authHeaders())
            ->get(self::ENDPOINT_NEAR, [
                'ra'            => '110.0',
                'dec'           => '89.5',
                'radius_arcsec' => '400',
            ]);

        $result->assertStatus(200);
        $json = json_decode($result->getJSON(), true);
        $this->assertNotEmpty($json['data'], 'Source near the pole must still be found despite the large RA delta.');
    }
}
