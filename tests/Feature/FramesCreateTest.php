<?php

namespace Tests\Feature;

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * Feature tests for POST /api/v1/frames
 *
 * Test isolation: the app tables are emptied in setUp() via direct SQL so
 * every test starts with a clean slate. We do not use DatabaseTestTrait
 * because its migration management hardcodes the SQLite 'tests' group and
 * interferes with the real MariaDB schema.
 *
 * @internal
 */
final class FramesCreateTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    private const API_KEY       = 'your-secret-key-here';
    private const WRONG_API_KEY = 'bad-key-xyz';
    private const ENDPOINT      = '/api/v1/frames';

    // -------------------------------------------------------------------------
    // Test lifecycle
    // -------------------------------------------------------------------------

    protected function setUp(): void
    {
        parent::setUp();
        $this->emptyAppTables();
    }

    /**
     * Delete all rows from app tables in FK-safe order.
     * Connects explicitly to the 'default' MySQLi group, not the test-bootstrap SQLite.
     */
    private function emptyAppTables(): void
    {
        $db = \Config\Database::connect('default');
        $db->query('DELETE FROM anomalies');
        $db->query('DELETE FROM sources');
        $db->query('DELETE FROM frames');
    }

    // -------------------------------------------------------------------------
    // Helper
    // -------------------------------------------------------------------------

    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'filename'          => 'frame_phpunit_test.fits',
            'original_filepath' => '/fits/archive/M51/frame_phpunit_test.fits',
            'obs_time'          => '2024-03-15T22:01:34Z',
            'ra_center'         => 202.4696,
            'dec_center'        => 47.1952,
            'fov_deg'           => 1.25,
            'quality_flag'      => 'OK',
            'observation' => [
                'object'     => 'M51',
                'exptime'    => 120.0,
                'filter'     => 'V',
                'frame_type' => 'Light',
                'airmass'    => 1.23,
            ],
            'instrument' => [
                'telescope'       => 'Celestron EdgeHD 11',
                'camera'          => 'ZWO ASI2600MM Pro',
                'focal_length_mm' => 2800,
                'aperture_mm'     => 280,
            ],
            'sensor' => [
                'temp_celsius'          => -10.0,
                'temp_setpoint_celsius' => -10.0,
                'binning_x'             => 1,
                'binning_y'             => 1,
                'gain'                  => 100,
                'offset'                => 50,
                'width_px'              => 6248,
                'height_px'             => 4176,
            ],
            'observer' => [
                'name'        => 'PHPUnit Test',
                'site_name'   => 'Test Observatory',
                'site_lat'    => 55.7558,
                'site_lon'    => 37.6173,
                'site_elev_m' => 150,
            ],
            'software' => ['capture' => 'PHPUnit 10'],
            'qc' => [
                'fwhm_median'    => 3.2,
                'elongation'     => 1.1,
                'snr_median'     => 42.5,
                'sky_background' => 850.3,
                'star_count'     => 287,
                'eccentricity'   => 0.4,
            ],
        ], $overrides);
    }

    private function authHeaders(): array
    {
        return ['X-API-Key' => self::API_KEY];
    }

    // -------------------------------------------------------------------------
    // Happy path
    // -------------------------------------------------------------------------

    public function testValidFullPayloadReturns201WithIdAndMessage(): void
    {
        $result = $this->withHeaders($this->authHeaders())
            ->withBodyFormat('json')
            ->post(self::ENDPOINT, $this->validPayload());

        $result->assertStatus(201);
        $json = json_decode($result->getJSON(), true);
        $this->assertArrayHasKey('id', $json);
        $this->assertIsString($json['id']);
        $this->assertNotEmpty($json['id']);
        $this->assertSame('Frame registered successfully', $json['message']);
    }

    // -------------------------------------------------------------------------
    // Missing required fields → 400
    // -------------------------------------------------------------------------

    public function testMissingFilenameReturns400(): void
    {
        $payload = $this->validPayload();
        unset($payload['filename']);

        $result = $this->withHeaders($this->authHeaders())
            ->withBodyFormat('json')
            ->post(self::ENDPOINT, $payload);

        $result->assertStatus(400);
    }

    public function testMissingObsTimeReturns400(): void
    {
        $payload = $this->validPayload();
        unset($payload['obs_time']);

        $result = $this->withHeaders($this->authHeaders())
            ->withBodyFormat('json')
            ->post(self::ENDPOINT, $payload);

        $result->assertStatus(400);
    }

    public function testMissingRaCenterReturns400(): void
    {
        $payload = $this->validPayload();
        unset($payload['ra_center']);

        $result = $this->withHeaders($this->authHeaders())
            ->withBodyFormat('json')
            ->post(self::ENDPOINT, $payload);

        $result->assertStatus(400);
    }

    public function testMissingDecCenterReturns400(): void
    {
        $payload = $this->validPayload();
        unset($payload['dec_center']);

        $result = $this->withHeaders($this->authHeaders())
            ->withBodyFormat('json')
            ->post(self::ENDPOINT, $payload);

        $result->assertStatus(400);
    }

    public function testMissingFovDegReturns400(): void
    {
        $payload = $this->validPayload();
        unset($payload['fov_deg']);

        $result = $this->withHeaders($this->authHeaders())
            ->withBodyFormat('json')
            ->post(self::ENDPOINT, $payload);

        $result->assertStatus(400);
    }

    public function testMissingQualityFlagReturns400(): void
    {
        $payload = $this->validPayload();
        unset($payload['quality_flag']);

        $result = $this->withHeaders($this->authHeaders())
            ->withBodyFormat('json')
            ->post(self::ENDPOINT, $payload);

        $result->assertStatus(400);
    }

    // -------------------------------------------------------------------------
    // Non-numeric sky coordinates → 422
    // -------------------------------------------------------------------------

    public function testNonNumericRaCenterReturns422(): void
    {
        $result = $this->withHeaders($this->authHeaders())
            ->withBodyFormat('json')
            ->post(self::ENDPOINT, $this->validPayload(['ra_center' => 'not-a-number']));

        $result->assertStatus(422);
    }

    public function testNonNumericDecCenterReturns422(): void
    {
        $result = $this->withHeaders($this->authHeaders())
            ->withBodyFormat('json')
            ->post(self::ENDPOINT, $this->validPayload(['dec_center' => 'not-a-number']));

        $result->assertStatus(422);
    }

    public function testNonNumericFovDegReturns422(): void
    {
        $result = $this->withHeaders($this->authHeaders())
            ->withBodyFormat('json')
            ->post(self::ENDPOINT, $this->validPayload(['fov_deg' => 'wide']));

        $result->assertStatus(422);
    }

    // -------------------------------------------------------------------------
    // Authentication
    // -------------------------------------------------------------------------

    public function testNoApiKeyReturns401(): void
    {
        $result = $this->withBodyFormat('json')
            ->post(self::ENDPOINT, $this->validPayload());

        $result->assertStatus(401);
    }

    public function testWrongApiKeyReturns401(): void
    {
        $result = $this->withHeaders(['X-API-Key' => self::WRONG_API_KEY])
            ->withBodyFormat('json')
            ->post(self::ENDPOINT, $this->validPayload());

        $result->assertStatus(401);
    }
}
