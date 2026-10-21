<?php

namespace Tests\Feature;

use Tests\Support\DatabaseTestCase;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * Feature tests for batch API endpoints:
 *   POST /api/v1/sources/near/batch
 *   POST /api/v1/frames/covering/batch
 *
 * @internal
 */
final class BatchTest extends DatabaseTestCase
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

    private function createFrame(array $overrides = []): string
    {
        $db = \Config\Database::connect();
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
        $db  = \Config\Database::connect();
        $id  = uniqid('', true);
        $row = array_merge([
            'id'                => $id,
            // catalog_id defaults to something unique per call — `sources`
            // now has a UNIQUE(catalog_name, catalog_id) key, and several
            // tests call createSource() more than once without overriding it.
            'catalog_name'      => 'Gaia DR3',
            'catalog_id'        => 'Gaia DR3 ' . $id,
            'object_type'       => 'STAR',
            'first_observed_at' => '2024-01-01 00:00:00',
            'last_observed_at'  => '2024-01-01 00:00:00',
            'observation_count' => 1,
        ], $data);

        // `sources` no longer has ra/dec columns — callers pass them through
        // here only for convenience/readability at the call site; the actual
        // position always ends up in the source_observations row the caller
        // inserts separately.
        unset($row['ra'], $row['dec']);

        $db->table('sources')->insert($row);

        return $row['id'];
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
        $db = \Config\Database::connect();
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

    /**
     * source_observations itself has no filter column — nearBatch() must
     * LEFT JOIN frames to pull it, since the pipeline's anomaly_detector.py
     * needs each historical detection's filter to restrict its Δmag
     * comparison to same-filter epochs only (comparing across filters is a
     * color-term artifact, not real variability — see CLAUDE.md on the
     * pipeline side).
     */
    public function testSourcesNearBatchReturnsFilterFromParentFrame(): void
    {
        $frameId = $this->createFrame([
            'obs_time' => '2024-01-01 00:00:00',
            'filter'   => 'Ha',
        ]);

        $db = \Config\Database::connect();
        $db->table('source_observations')->insert([
            'id'        => uniqid('', true),
            'source_id' => $this->createSource(['ra' => 202.461, 'dec' => 47.182]),
            'frame_id'  => $frameId,
            'ra'        => 202.461,
            'dec'       => 47.182,
            'mag'       => 14.5,
            'flux'      => 10000.0,
            'obs_time'  => '2024-01-01 00:00:00',
        ]);

        $result = $this->withHeaders($this->authHeaders())
            ->withBodyFormat('json')
            ->post('/api/v1/sources/near/batch', [
                'positions'     => [['ra' => 202.461, 'dec' => 47.182]],
                'radius_arcsec' => 60.0,
            ]);

        $result->assertStatus(200);
        $json = json_decode($result->getJSON(), true);

        $this->assertNotEmpty($json['results']['0']);
        $this->assertArrayHasKey('filter', $json['results']['0'][0]);
        $this->assertSame('Ha', $json['results']['0'][0]['filter']);
    }

    /**
     * uncatalogued_only keeps observations of sources with no catalog
     * identity or an MPC one, and drops catalogued stars — the pipeline's
     * wide-cone moving-object query, which OOM-killed its worker when every
     * star in a 228-frame field came back.
     */
    public function testSourcesNearBatchUncataloguedOnlyDropsCataloguedStars(): void
    {
        $frameId = $this->createFrame(['obs_time' => '2024-01-01 00:00:00']);
        $db      = \Config\Database::connect();

        $sources = [
            'star'    => $this->createSource(),
            'unknown' => $this->createSource(['catalog_name' => null, 'catalog_id' => null, 'object_type' => null]),
            'mpc'     => $this->createSource(['catalog_name' => 'MPC', 'catalog_id' => '2014 RY1', 'object_type' => 'ASTEROID']),
        ];
        $mags = ['star' => 14.0, 'unknown' => 18.0, 'mpc' => 17.0];
        foreach ($sources as $kind => $sourceId) {
            $db->table('source_observations')->insert([
                'id'        => uniqid('', true),
                'source_id' => $sourceId,
                'frame_id'  => $frameId,
                'ra'        => 202.461,
                'dec'       => 47.182,
                'mag'       => $mags[$kind],
                'flux'      => 1000.0,
                'obs_time'  => '2024-01-01 00:00:00',
            ]);
        }

        $request = [
            'positions'     => [['ra' => 202.461, 'dec' => 47.182]],
            'radius_arcsec' => 60.0,
            'before_time'   => '2024-06-01T00:00:00Z',
        ];

        $all = json_decode($this->withHeaders($this->authHeaders())->withBodyFormat('json')
            ->post('/api/v1/sources/near/batch', $request)->getJSON(), true);
        $this->assertCount(3, $all['results']['0']);

        $filtered = json_decode($this->withHeaders($this->authHeaders())->withBodyFormat('json')
            ->post('/api/v1/sources/near/batch', $request + ['uncatalogued_only' => true])->getJSON(), true);
        $magsBack = array_column($filtered['results']['0'], 'mag');
        sort($magsBack);
        $this->assertEquals([17.0, 18.0], $magsBack);
    }

    /**
     * The declination-sorted pre-filter must not lose a match: each position
     * gets exactly the observations within its own cone, however the others
     * are spread.
     */
    public function testSourcesNearBatchPreFilterKeepsEveryPositionsOwnMatches(): void
    {
        $frameId = $this->createFrame(['obs_time' => '2024-01-01 00:00:00']);
        $db      = \Config\Database::connect();

        // A column of observations 30" apart in dec, plus one 30" east of the first.
        $points = [];
        for ($k = 0; $k < 10; $k++) {
            $points[] = [202.461, 47.100 + $k * 30 / 3600];
        }
        $points[] = [202.461 + (30 / 3600) / cos(deg2rad(47.100)), 47.100];
        foreach ($points as [$ra, $dec]) {
            $db->table('source_observations')->insert([
                'id'        => uniqid('', true),
                'source_id' => $this->createSource(),
                'frame_id'  => $frameId,
                'ra'        => $ra,
                'dec'       => $dec,
                'mag'       => 15.0,
                'flux'      => 1000.0,
                'obs_time'  => '2024-01-01 00:00:00',
            ]);
        }

        $json = json_decode($this->withHeaders($this->authHeaders())->withBodyFormat('json')
            ->post('/api/v1/sources/near/batch', [
                'positions'     => [['ra' => 202.461, 'dec' => 47.100], ['ra' => 202.461, 'dec' => 47.100 + 270 / 3600]],
                'radius_arcsec' => 35.0,
                'before_time'   => '2024-06-01T00:00:00Z',
            ])->getJSON(), true);

        // First position: itself, the next one north, and the one east.
        $this->assertCount(3, $json['results']['0']);
        // Last in the column: itself and the one south.
        $this->assertCount(2, $json['results']['1']);
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

    /**
     * A naive per-batch bounding box built from raw min/max RA values breaks
     * down at the RA=0/360 seam: a lone position at ra=0.001 gets boxed to
     * roughly [-0.001, 0.003], which never matches an observation sitting
     * right across the seam at ra=359.999.
     */
    public function testSourcesNearBatchFindsObservationAcrossRaSeam(): void
    {
        $frameId = $this->createFrame(['obs_time' => '2024-01-01 00:00:00']);

        $db = \Config\Database::connect();
        $db->table('source_observations')->insert([
            'id'        => uniqid('', true),
            'source_id' => $this->createSource(['ra' => 359.999, 'dec' => 10.0]),
            'frame_id'  => $frameId,
            'ra'        => 359.999,
            'dec'       => 10.0,
            'mag'       => 14.0,
            'flux'      => 10000.0,
            'obs_time'  => '2024-01-01 00:00:00',
        ]);

        $result = $this->withHeaders($this->authHeaders())
            ->withBodyFormat('json')
            ->post('/api/v1/sources/near/batch', [
                'positions'     => [['ra' => 0.001, 'dec' => 10.0]],
                'radius_arcsec' => 10.0,
            ]);

        $result->assertStatus(200);
        $json = json_decode($result->getJSON(), true);
        $this->assertNotEmpty($json['results']['0'], 'Observation across the RA=0/360 seam must still be found.');
    }

    /**
     * Sanity check for southern-hemisphere (negative dec) targets — the
     * batch bounding box's dec bounds must be derived from the actual min/max
     * of the batch, not from an accidentally-positive sentinel.
     */
    public function testSourcesNearBatchWorksForSouthernDeclinations(): void
    {
        $frameId = $this->createFrame(['obs_time' => '2024-01-01 00:00:00']);

        $db = \Config\Database::connect();
        $db->table('source_observations')->insert([
            'id'        => uniqid('', true),
            'source_id' => $this->createSource(['ra' => 60.0, 'dec' => -47.2]),
            'frame_id'  => $frameId,
            'ra'        => 60.0,
            'dec'       => -47.2,
            'mag'       => 14.0,
            'flux'      => 10000.0,
            'obs_time'  => '2024-01-01 00:00:00',
        ]);

        $result = $this->withHeaders($this->authHeaders())
            ->withBodyFormat('json')
            ->post('/api/v1/sources/near/batch', [
                'positions'     => [['ra' => 60.0, 'dec' => -47.2], ['ra' => 60.0, 'dec' => -10.0]],
                'radius_arcsec' => 10.0,
            ]);

        $result->assertStatus(200);
        $json = json_decode($result->getJSON(), true);
        $this->assertNotEmpty($json['results']['0']);
        $this->assertEmpty($json['results']['1']);
    }

    /**
     * nearBatch() streams the candidate rows in declination order and sweeps
     * them against the positions sorted by dec, instead of loading the whole
     * bounding box into memory (it ran out of PHP's 256 MB on the hosting).
     * The answer must be exactly what a brute-force cone search over every
     * stored observation gives: same matches, same fields, ordered by dec
     * within each position — for many overlapping positions (unsorted, one
     * repeated), across filters and the RA=0/360 seam, with and without
     * before_time and uncatalogued_only.
     */
    public function testSourcesNearBatchMatchesBruteForceConeSearch(): void
    {
        mt_srand(20261021);
        $db = \Config\Database::connect();

        $frames = [
            $this->createFrame(['obs_time' => '2024-01-01 00:00:00', 'filter' => 'L']),
            $this->createFrame(['obs_time' => '2024-02-01 00:00:00', 'filter' => 'R']),
            $this->createFrame(['obs_time' => '2024-03-01 00:00:00', 'filter' => null]),
            $this->createFrame(['obs_time' => '2024-04-01 00:00:00', 'filter' => 'Ha']),
        ];
        $catalogs = ['Gaia DR3', null, 'MPC', 'Simbad'];

        $fields = [[202.47, 47.19], [0.0, 10.0]]; // M51-like field and the RA seam
        foreach ($fields as [$ra0, $dec0]) {
            for ($k = 0; $k < 150; $k++) {
                $catalog  = $catalogs[$k % 4];
                $sourceId = $this->createSource(
                    $catalog === null ? ['catalog_name' => null, 'catalog_id' => null] : ['catalog_name' => $catalog]
                );
                $frameIdx = mt_rand(0, 3);
                $ra       = fmod($ra0 + (mt_rand(-10000, 10000) / 10000) * 0.01 / cos(deg2rad($dec0)) + 360.0, 360.0);
                $db->table('source_observations')->insert([
                    'id'        => uniqid('', true),
                    'source_id' => $sourceId,
                    'frame_id'  => $frames[$frameIdx],
                    'ra'        => round($ra, 7),
                    'dec'       => round($dec0 + (mt_rand(-10000, 10000) / 10000) * 0.01, 7),
                    'mag'       => $k % 7 === 0 ? null : 12.0 + mt_rand(0, 8000) / 1000,
                    'flux'      => $k % 9 === 0 ? null : (float) mt_rand(100, 90000),
                    'obs_time'  => ['2024-01-01 00:00:00', '2024-02-01 00:00:00', '2024-03-01 00:00:00', '2024-04-01 00:00:00'][$frameIdx],
                ]);
            }
        }

        $positions = [];
        for ($k = 0; $k < 30; $k++) {
            [$ra0, $dec0] = $fields[$k % 2];
            $positions[]  = [
                'ra'  => fmod($ra0 + (mt_rand(-10000, 10000) / 10000) * 0.01 / cos(deg2rad($dec0)) + 360.0, 360.0),
                'dec' => $dec0 + (mt_rand(-10000, 10000) / 10000) * 0.01,
            ];
        }
        $positions[] = $positions[3];                  // the same position twice
        $positions[] = ['ra' => 0.0003, 'dec' => 10.0];  // just east of the seam
        $positions[] = ['ra' => 359.9997, 'dec' => 10.0];
        $positions[] = ['ra' => 100.0, 'dec' => -30.0];  // nothing there

        $all = $db->query(
            'SELECT so.id, so.frame_id, so.ra, so.dec, so.mag, so.flux, so.obs_time, f.filter, s.catalog_name
             FROM source_observations so
             LEFT JOIN frames f ON f.id = so.frame_id
             JOIN sources s ON s.id = so.source_id'
        )->getResultArray();

        $radius = 40.0;
        $cases  = [
            [],
            ['before_time' => '2024-03-01T00:00:00Z'],
            ['uncatalogued_only' => true],
            ['before_time' => '2024-03-15T00:00:00Z', 'uncatalogued_only' => true],
        ];

        $totalMatches = 0;
        foreach ($cases as $options) {
            $expected = [];
            foreach ($positions as $i => $pos) {
                $matches = [];
                foreach ($all as $obs) {
                    if (isset($options['before_time']) && strtotime($obs['obs_time'] . ' UTC') >= strtotime($options['before_time'])) {
                        continue;
                    }
                    if (! empty($options['uncatalogued_only']) && ! in_array($obs['catalog_name'], [null, 'MPC'], true)) {
                        continue;
                    }
                    if (\App\Libraries\SkyMath::haversineArcsec((float) $pos['ra'], (float) $pos['dec'], (float) $obs['ra'], (float) $obs['dec']) > $radius) {
                        continue;
                    }
                    $matches[] = $obs;
                }
                usort($matches, static fn (array $a, array $b): int => [(float) $a['dec'], $a['id']] <=> [(float) $b['dec'], $b['id']]);
                $expected[(string) $i] = array_map(static fn (array $obs): array => [
                    'ra'       => (float) $obs['ra'],
                    'dec'      => (float) $obs['dec'],
                    'mag'      => $obs['mag'] !== null ? (float) $obs['mag'] : null,
                    'flux'     => $obs['flux'] !== null ? (float) $obs['flux'] : null,
                    'frame_id' => $obs['frame_id'],
                    'obs_time' => gmdate('Y-m-d\TH:i:s\Z', strtotime($obs['obs_time'])),
                    'filter'   => $obs['filter'],
                ], $matches);
                $totalMatches += count($matches);
            }

            $result = $this->withHeaders($this->authHeaders())->withBodyFormat('json')
                ->post('/api/v1/sources/near/batch', ['positions' => $positions, 'radius_arcsec' => $radius] + $options);

            $result->assertStatus(200);
            $body = $result->getJSON();
            $this->assertInstanceOf(\stdClass::class, json_decode($body)->results, 'results must stay a JSON object keyed by index');
            // Through JSON on both sides: an integral float (flux 75006.0) decodes as an int.
            $this->assertSame(json_decode(json_encode($expected), true), json_decode($body, true)['results'], 'Options: ' . json_encode($options));
        }

        // The fixture must actually exercise the sweep: many matches, overlapping positions.
        $this->assertGreaterThan(200, $totalMatches);
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

    /**
     * Same RA=0/360 seam problem as sources/near/batch, on the frames side:
     * a lone query position near ra=0 must still find a frame centred just
     * across the seam near ra=360.
     */
    public function testFramesCoveringBatchFindsFrameAcrossRaSeam(): void
    {
        $this->createFrame([
            'ra_center'  => 359.98,
            'dec_center' => 10.0,
            'fov_deg'    => 1.0,
            'obs_time'   => '2024-03-10 00:00:00',
        ]);

        $result = $this->withHeaders($this->authHeaders())
            ->withBodyFormat('json')
            ->post('/api/v1/frames/covering/batch', [
                'positions'   => [['ra' => 0.02, 'dec' => 10.0]],
                'before_time' => '2025-01-01T00:00:00Z',
            ]);

        $result->assertStatus(200);
        $json = json_decode($result->getJSON(), true);
        $this->assertNotEmpty($json['results']['0'], 'Frame across the RA=0/360 seam must still be found.');
    }
}

