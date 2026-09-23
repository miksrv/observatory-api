<?php

namespace Tests\Feature;

use Tests\Support\DatabaseTestCase;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * Feature tests for POST /api/v1/frames/{id}/anomalies
 *
 * @internal
 */
final class AnomaliesTest extends DatabaseTestCase
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

    private function createFrame(): string
    {
        $db = \Config\Database::connect();
        $id = uniqid('', true);
        $db->table('frames')->insert([
            'id'           => $id,
            'filename'     => 'anomaly_test_' . uniqid() . '.fits',
            'obs_time'     => '2024-03-15 22:01:34',
            'ra_center'    => 202.4696,
            'dec_center'   => 47.1952,
            'fov_deg'      => 1.25,
            'quality_flag' => 'OK',
        ]);

        return $id;
    }

    private function anomaliesEndpoint(string $frameId): string
    {
        return "/api/v1/frames/{$frameId}/anomalies";
    }

    private function anomalyOf(string $type): array
    {
        return [
            'anomaly_type' => $type,
            'ra'           => 202.489,
            'dec'          => 47.201,
            'magnitude'    => 17.8,
            'delta_mag'    => null,
            'notes'        => "Test anomaly of type {$type}",
        ];
    }

    /**
     * Insert a minimal `sources` row directly and return its id — used to
     * exercise the anomalies.source_id FK (see CreateAnomaliesTable migration).
     */
    private function createSource(): string
    {
        $db = \Config\Database::connect();
        $id = uniqid('', true);
        $db->table('sources')->insert([
            'id'                => $id,
            'catalog_name'      => 'MPC',
            'catalog_id'        => 'Vesta-' . $id,
            'object_type'       => 'ASTEROID',
            'first_observed_at' => '2024-03-15 22:01:34',
            'last_observed_at'  => '2024-03-15 22:01:34',
            'observation_count' => 1,
        ]);

        return $id;
    }

    private function asteroidWithEphemeris(): array
    {
        return [
            'anomaly_type'    => 'ASTEROID',
            'ra'              => 202.492,
            'dec'             => 47.198,
            'magnitude'       => 18.2,
            'delta_mag'       => null,
            'mpc_designation' => '2019 XY3',
            'ephemeris' => [
                'predicted_ra'                     => 202.491,
                'predicted_dec'                    => 47.200,
                'predicted_mag'                    => 17.9,
                'distance_au'                      => 1.23,
                'angular_velocity_arcsec_per_hour' => 45.2,
            ],
            'notes' => 'Matched MPC object',
        ];
    }

    // -------------------------------------------------------------------------
    // Happy paths
    // -------------------------------------------------------------------------

    public function testUnknownPlusAsteroidReturns201WithCount2AndAlerts1(): void
    {
        $frameId = $this->createFrame();

        $result = $this->withHeaders($this->authHeaders())
            ->withBodyFormat('json')
            ->post($this->anomaliesEndpoint($frameId), [
                'filename'  => 'test.fits',
                'anomalies' => [
                    $this->anomalyOf('UNKNOWN'),
                    $this->asteroidWithEphemeris(),
                ],
            ]);

        $result->assertStatus(201);
        $json = json_decode($result->getJSON(), true);
        $this->assertSame('Anomalies saved successfully', $json['message']);
        $this->assertSame(2, $json['count']);
        $this->assertSame(1, $json['alerts']);
    }

    public function testEmptyAnomaliesReturns201WithCountAndAlertsZero(): void
    {
        $frameId = $this->createFrame();

        $result = $this->withHeaders($this->authHeaders())
            ->withBodyFormat('json')
            ->post($this->anomaliesEndpoint($frameId), [
                'filename'  => 'test.fits',
                'anomalies' => [],
            ]);

        $result->assertStatus(201);
        $json = json_decode($result->getJSON(), true);
        $this->assertSame(0, $json['count']);
        $this->assertSame(0, $json['alerts']);
    }

    public function testAllThreeAlertTypesProduceAlerts3(): void
    {
        $frameId = $this->createFrame();

        $alertTypes = ['SUPERNOVA_CANDIDATE', 'MOVING_UNKNOWN', 'UNKNOWN'];
        $anomalies  = array_map(fn (string $t) => $this->anomalyOf($t), $alertTypes);

        $result = $this->withHeaders($this->authHeaders())
            ->withBodyFormat('json')
            ->post($this->anomaliesEndpoint($frameId), [
                'filename'  => 'test.fits',
                'anomalies' => $anomalies,
            ]);

        $result->assertStatus(201);
        $json = json_decode($result->getJSON(), true);
        $this->assertSame(3, $json['count']);
        $this->assertSame(3, $json['alerts']);
    }

    /**
     * A satellite/aircraft trail is recorded (so a fast mover's track is never
     * erased and trails stay out of UNKNOWN) but is not something an operator
     * has to act on — it must be persisted with is_alert = 0.
     */
    public function testSpaceDebrisIsRecordedButNotAnAlert(): void
    {
        $frameId = $this->createFrame();

        $result = $this->withHeaders($this->authHeaders())
            ->withBodyFormat('json')
            ->post($this->anomaliesEndpoint($frameId), [
                'filename'  => 'test.fits',
                'anomalies' => [$this->anomalyOf('SPACE_DEBRIS')],
            ]);

        $result->assertStatus(201);
        $json = json_decode($result->getJSON(), true);
        $this->assertSame(1, $json['count']);
        $this->assertSame(0, $json['alerts']);

        $row = \Config\Database::connect()->table('anomalies')
            ->where('frame_id', $frameId)->get()->getRowArray();
        $this->assertSame('SPACE_DEBRIS', $row['anomaly_type']);
        $this->assertSame(0, (int) $row['is_alert']);
    }

    public function testNonAlertTypesProduceAlerts0(): void
    {
        $frameId = $this->createFrame();

        $nonAlertTypes = ['ASTEROID', 'VARIABLE_STAR', 'BINARY_STAR', 'COMET', 'SPACE_DEBRIS'];
        $anomalies     = array_map(fn (string $t) => $this->anomalyOf($t), $nonAlertTypes);

        $result = $this->withHeaders($this->authHeaders())
            ->withBodyFormat('json')
            ->post($this->anomaliesEndpoint($frameId), [
                'filename'  => 'test.fits',
                'anomalies' => $anomalies,
            ]);

        $result->assertStatus(201);
        $json = json_decode($result->getJSON(), true);
        $this->assertSame(5, $json['count']);
        $this->assertSame(0, $json['alerts']);
    }

    // -------------------------------------------------------------------------
    // Replace is atomic (API audit 2026-08-20, finding C1)
    // -------------------------------------------------------------------------

    /**
     * The delete-then-insert replace must not be able to half-succeed: an
     * insert that fails after the delete (here: a source_id that violates the
     * anomalies.source_id FK) must leave the frame's previous anomalies in
     * place and answer 500, so the pipeline retries instead of believing the
     * frame has no anomalies.
     */
    public function testFailedInsertKeepsThePreviousAnomalies(): void
    {
        $frameId = $this->createFrame();

        $this->withHeaders($this->authHeaders())
            ->withBodyFormat('json')
            ->post($this->anomaliesEndpoint($frameId), [
                'filename'  => 'test.fits',
                'anomalies' => [$this->anomalyOf('UNKNOWN'), $this->anomalyOf('ASTEROID')],
            ])
            ->assertStatus(201);

        $bad              = $this->anomalyOf('SUPERNOVA_CANDIDATE');
        $bad['source_id'] = 'no-such-source-' . uniqid();

        $result = $this->withHeaders($this->authHeaders())
            ->withBodyFormat('json')
            ->post($this->anomaliesEndpoint($frameId), [
                'filename'  => 'test.fits',
                'anomalies' => [$this->anomalyOf('MOVING_UNKNOWN'), $bad],
            ]);

        $result->assertStatus(500);

        $kept  = \Config\Database::connect()->table('anomalies')
            ->where('frame_id', $frameId)->get()->getResultArray();
        $types = array_column($kept, 'anomaly_type');
        sort($types);  // anomaly_type is an ENUM — ORDER BY would sort by enum index, not name
        $this->assertSame(['ASTEROID', 'UNKNOWN'], $types);
    }

    public function testEmptyListStillReplacesThePreviousAnomalies(): void
    {
        $frameId = $this->createFrame();

        $this->withHeaders($this->authHeaders())
            ->withBodyFormat('json')
            ->post($this->anomaliesEndpoint($frameId), [
                'filename'  => 'test.fits',
                'anomalies' => [$this->anomalyOf('UNKNOWN')],
            ])
            ->assertStatus(201);

        $this->withHeaders($this->authHeaders())
            ->withBodyFormat('json')
            ->post($this->anomaliesEndpoint($frameId), ['filename' => 'test.fits', 'anomalies' => []])
            ->assertStatus(201);

        $this->assertSame(0, \Config\Database::connect()->table('anomalies')
            ->where('frame_id', $frameId)->countAllResults());
    }

    // -------------------------------------------------------------------------
    // source_id linkage (FK to sources.id — see CreateAnomaliesTable migration)
    // -------------------------------------------------------------------------

    public function testAnomalySourceIdIsPersistedWhenProvided(): void
    {
        $frameId  = $this->createFrame();
        $sourceId = $this->createSource();

        $anomaly              = $this->anomalyOf('ASTEROID');
        $anomaly['source_id'] = $sourceId;

        $result = $this->withHeaders($this->authHeaders())
            ->withBodyFormat('json')
            ->post($this->anomaliesEndpoint($frameId), [
                'filename'  => 'test.fits',
                'anomalies' => [$anomaly],
            ]);

        $result->assertStatus(201);

        $db  = \Config\Database::connect();
        $row = $db->table('anomalies')->where('frame_id', $frameId)->get()->getRowArray();
        $this->assertNotNull($row);
        $this->assertSame($sourceId, $row['source_id']);
    }

    public function testAnomalySourceIdOmittedDefaultsToNull(): void
    {
        $frameId = $this->createFrame();

        $result = $this->withHeaders($this->authHeaders())
            ->withBodyFormat('json')
            ->post($this->anomaliesEndpoint($frameId), [
                'filename'  => 'test.fits',
                // 'source_id' intentionally omitted, as the pipeline sends
                // for any source it could not resolve a sources.id for.
                'anomalies' => [$this->anomalyOf('UNKNOWN')],
            ]);

        $result->assertStatus(201);

        $db  = \Config\Database::connect();
        $row = $db->table('anomalies')->where('frame_id', $frameId)->get()->getRowArray();
        $this->assertNull($row['source_id']);
    }

    /**
     * Regression test for the anomalies.source_id FK added to the existing
     * CreateAnomaliesTable migration: deleting the referenced `sources` row
     * must detach the anomaly (ON DELETE SET NULL), not cascade-delete it —
     * an anomaly is a detection *event* and should survive its source being
     * later removed from the catalog.
     */
    public function testDeletingSourceDetachesAnomalyInsteadOfDeletingIt(): void
    {
        $frameId  = $this->createFrame();
        $sourceId = $this->createSource();

        $anomaly              = $this->anomalyOf('ASTEROID');
        $anomaly['source_id'] = $sourceId;

        $this->withHeaders($this->authHeaders())
            ->withBodyFormat('json')
            ->post($this->anomaliesEndpoint($frameId), [
                'filename'  => 'test.fits',
                'anomalies' => [$anomaly],
            ])
            ->assertStatus(201);

        $db = \Config\Database::connect();
        $db->table('sources')->where('id', $sourceId)->delete();

        $row = $db->table('anomalies')->where('frame_id', $frameId)->get()->getRowArray();
        $this->assertNotNull($row, 'Anomaly row must survive the source deletion');
        $this->assertNull($row['source_id'], 'source_id must be detached (SET NULL), not left dangling');
    }

    // -------------------------------------------------------------------------
    // Error paths
    // -------------------------------------------------------------------------

    public function testNonExistentFrameIdReturns404(): void
    {
        $result = $this->withHeaders($this->authHeaders())
            ->withBodyFormat('json')
            ->post('/api/v1/frames/nonexistent.12345678/anomalies', [
                'filename'  => 'test.fits',
                'anomalies' => [],
            ]);

        $result->assertStatus(404);
    }

    public function testMissingAnomaliesFieldReturns400(): void
    {
        $frameId = $this->createFrame();

        $result = $this->withHeaders($this->authHeaders())
            ->withBodyFormat('json')
            ->post($this->anomaliesEndpoint($frameId), [
                'filename' => 'test.fits',
                // 'anomalies' intentionally omitted
            ]);

        $result->assertStatus(400);
    }

    public function testInvalidAnomalyTypeReturns400AndInsertsNothing(): void
    {
        $frameId = $this->createFrame();

        $result = $this->withHeaders($this->authHeaders())
            ->withBodyFormat('json')
            ->post($this->anomaliesEndpoint($frameId), [
                'filename'  => 'test.fits',
                'anomalies' => [
                    $this->anomalyOf('UNKNOWN'),
                    $this->anomalyOf('NOT_A_REAL_TYPE'),
                ],
            ]);

        $result->assertStatus(400);

        // The whole batch is rejected atomically — the valid UNKNOWN entry
        // ahead of the bad one must not have been inserted either.
        $db    = \Config\Database::connect();
        $count = $db->table('anomalies')->where('frame_id', $frameId)->countAllResults();
        $this->assertSame(0, $count);
    }

    public function testNoApiKeyReturns401(): void
    {
        $result = $this->withBodyFormat('json')
            ->post('/api/v1/frames/anyid.12345678/anomalies', [
                'filename'  => 'test.fits',
                'anomalies' => [],
            ]);

        $result->assertStatus(401);
    }

    // -------------------------------------------------------------------------
    // Web debug UI (Web\AnomaliesController::index) — designation resolution
    // -------------------------------------------------------------------------

    /**
     * Insert a `sources` row with an arbitrary catalog match (Simbad, Gaia
     * DR3, ...) — unlike createSource() above, which is hardcoded to an MPC
     * match.
     */
    private function createSourceWithCatalog(string $catalogName, string $catalogId): string
    {
        $db = \Config\Database::connect();
        $id = uniqid('', true);
        $db->table('sources')->insert([
            'id'                => $id,
            'catalog_name'      => $catalogName,
            'catalog_id'        => $catalogId,
            'object_type'       => 'V*',
            'first_observed_at' => '2024-03-15 22:01:34',
            'last_observed_at'  => '2024-03-15 22:01:34',
            'observation_count' => 1,
        ]);

        return $id;
    }

    /**
     * Regression test: a Simbad-matched anomaly type (VARIABLE_STAR here)
     * never carries `mpc_designation` — that field is scoped to MPC
     * solar-system objects only (see observatory-pipeline's
     * modules/anomaly_detector.py). /ui/anomalies must fall back to the
     * source's own `catalog_id` for its display designation and for the
     * `designation` field embedded in the GENERATE_CHARTS task payload it
     * builds — otherwise a finder chart generated from that task never
     * shows the star's catalog name next to its anomaly_type, even though
     * /ui/charts (which reads catalog_id directly off `sources`) shows it
     * fine.
     */
    public function testVariableStarAnomalyFallsBackToCatalogIdForDesignation(): void
    {
        $frameId  = $this->createFrame();
        $sourceId = $this->createSourceWithCatalog('Simbad', 'V* BE UMa');

        $anomaly              = $this->anomalyOf('VARIABLE_STAR');
        $anomaly['source_id'] = $sourceId;
        // 'mpc_designation' intentionally omitted/null.

        $this->withHeaders($this->authHeaders())
            ->withBodyFormat('json')
            ->post($this->anomaliesEndpoint($frameId), [
                'filename'  => 'test.fits',
                'anomalies' => [$anomaly],
            ])
            ->assertStatus(201);

        $result = $this->get('/ui/anomalies');

        $result->assertStatus(200);
        $result->assertSee('V* BE UMa');
    }
}
