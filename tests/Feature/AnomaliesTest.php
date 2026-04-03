<?php

namespace Tests\Feature;

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * Feature tests for POST /api/v1/frames/{id}/anomalies
 *
 * @internal
 */
final class AnomaliesTest extends CIUnitTestCase
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

    private function createFrame(): int
    {
        $db = \Config\Database::connect('default');
        $db->table('frames')->insert([
            'filename'     => 'anomaly_test_' . uniqid() . '.fits',
            'obs_time'     => '2024-03-15 22:01:34',
            'ra_center'    => 202.4696,
            'dec_center'   => 47.1952,
            'fov_deg'      => 1.25,
            'quality_flag' => 'OK',
        ]);

        return (int) $db->insertID();
    }

    private function anomaliesEndpoint(int $frameId): string
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

    public function testAllFourAlertTypesProduceAlerts4(): void
    {
        $frameId = $this->createFrame();

        $alertTypes = ['SUPERNOVA_CANDIDATE', 'MOVING_UNKNOWN', 'SPACE_DEBRIS', 'UNKNOWN'];
        $anomalies  = array_map(fn (string $t) => $this->anomalyOf($t), $alertTypes);

        $result = $this->withHeaders($this->authHeaders())
            ->withBodyFormat('json')
            ->post($this->anomaliesEndpoint($frameId), [
                'filename'  => 'test.fits',
                'anomalies' => $anomalies,
            ]);

        $result->assertStatus(201);
        $json = json_decode($result->getJSON(), true);
        $this->assertSame(4, $json['count']);
        $this->assertSame(4, $json['alerts']);
    }

    public function testNonAlertTypesProduceAlerts0(): void
    {
        $frameId = $this->createFrame();

        $nonAlertTypes = ['ASTEROID', 'VARIABLE_STAR', 'BINARY_STAR', 'COMET'];
        $anomalies     = array_map(fn (string $t) => $this->anomalyOf($t), $nonAlertTypes);

        $result = $this->withHeaders($this->authHeaders())
            ->withBodyFormat('json')
            ->post($this->anomaliesEndpoint($frameId), [
                'filename'  => 'test.fits',
                'anomalies' => $anomalies,
            ]);

        $result->assertStatus(201);
        $json = json_decode($result->getJSON(), true);
        $this->assertSame(4, $json['count']);
        $this->assertSame(0, $json['alerts']);
    }

    // -------------------------------------------------------------------------
    // Error paths
    // -------------------------------------------------------------------------

    public function testNonExistentFrameIdReturns404(): void
    {
        $result = $this->withHeaders($this->authHeaders())
            ->withBodyFormat('json')
            ->post('/api/v1/frames/999999/anomalies', [
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

    public function testNoApiKeyReturns401(): void
    {
        $result = $this->withBodyFormat('json')
            ->post('/api/v1/frames/1/anomalies', [
                'filename'  => 'test.fits',
                'anomalies' => [],
            ]);

        $result->assertStatus(401);
    }
}
