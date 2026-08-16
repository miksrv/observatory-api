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
        $db->query('DELETE FROM frame_sources');
        $db->query('DELETE FROM source_observations');
        $db->query('DELETE FROM sources');
        $db->query('DELETE FROM object_stats');
        $db->query('DELETE FROM frames');
    }

    // -------------------------------------------------------------------------
    // Helper
    // -------------------------------------------------------------------------

    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'filename'          => 'frame_phpunit_test.fits',
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

    // -------------------------------------------------------------------------
    // Idempotent upsert-by-filename (re-analysis)
    // -------------------------------------------------------------------------

    /**
     * Regression test for a real incident (2026-08-12): re-running an ANALYZE
     * task on an already-registered file (e.g. after improving the detection
     * algorithm) must UPDATE the existing `frames` row in place, not create a
     * second one with a new frame_id.
     */
    public function testResubmittingSameFilenameUpdatesExistingFrameInsteadOfDuplicating(): void
    {
        $first = $this->withHeaders($this->authHeaders())
            ->withBodyFormat('json')
            ->post(self::ENDPOINT, $this->validPayload(['ra_center' => 202.4696, 'quality_flag' => 'OK']));

        $first->assertStatus(201);
        $firstId = json_decode($first->getJSON(), true)['id'];

        // Re-analysis: same filename, different measured values (as if a
        // re-plate-solve/QC pass produced slightly different numbers).
        $second = $this->withHeaders($this->authHeaders())
            ->withBodyFormat('json')
            ->post(self::ENDPOINT, $this->validPayload(['ra_center' => 202.5000, 'quality_flag' => 'OK']));

        $second->assertStatus(200);
        $json2 = json_decode($second->getJSON(), true);
        $this->assertSame($firstId, $json2['id'], 'Re-analysis of the same filename must return the same frame_id.');
        $this->assertSame('Frame updated successfully', $json2['message']);

        $db    = \Config\Database::connect('default');
        $count = $db->table('frames')->where('filename', $this->validPayload()['filename'])->countAllResults();
        $this->assertSame(1, $count, 'Exactly one frames row must exist for this filename, not two.');

        $row = $db->table('frames')->where('id', $firstId)->get()->getRowArray();
        $this->assertSame(202.5, (float) $row['ra_center']);
    }

    /**
     * Re-analyzing an already-registered frame must not double-count it in
     * object_stats — only a genuinely new frame increments frame_count.
     */
    public function testResubmittingSameFilenameDoesNotDoubleCountObjectStats(): void
    {
        $this->withHeaders($this->authHeaders())
            ->withBodyFormat('json')
            ->post(self::ENDPOINT, $this->validPayload())
            ->assertStatus(201);

        $this->withHeaders($this->authHeaders())
            ->withBodyFormat('json')
            ->post(self::ENDPOINT, $this->validPayload())
            ->assertStatus(200);

        $db  = \Config\Database::connect('default');
        $row = $db->table('object_stats')->where('object', 'M51')->get()->getRowArray();
        $this->assertNotNull($row);
        $this->assertSame(1, (int) $row['frame_count'], 'A re-analysis of the same file must not increment frame_count again.');
    }

    // -------------------------------------------------------------------------
    // Mount pointing error — stored on first registration, preserved on re-analysis
    // -------------------------------------------------------------------------

    public function testPointingErrorFieldsAreStoredOnFirstRegistration(): void
    {
        $result = $this->withHeaders($this->authHeaders())
            ->withBodyFormat('json')
            ->post(self::ENDPOINT, $this->validPayload([
                'pointing_error_arcsec'     => 12.4,
                'pointing_error_ra_arcsec'  => -8.1,
                'pointing_error_dec_arcsec' => -9.4,
            ]));

        $result->assertStatus(201);
        $id = json_decode($result->getJSON(), true)['id'];

        $db  = \Config\Database::connect('default');
        $row = $db->table('frames')->where('id', $id)->get()->getRowArray();
        $this->assertSame(12.4, (float) $row['pointing_error_arcsec']);
        $this->assertSame(-8.1, (float) $row['pointing_error_ra_arcsec']);
        $this->assertSame(-9.4, (float) $row['pointing_error_dec_arcsec']);
    }

    public function testPointingErrorFieldsDefaultToNullWhenOmitted(): void
    {
        $result = $this->withHeaders($this->authHeaders())
            ->withBodyFormat('json')
            ->post(self::ENDPOINT, $this->validPayload());

        $result->assertStatus(201);
        $id = json_decode($result->getJSON(), true)['id'];

        $db  = \Config\Database::connect('default');
        $row = $db->table('frames')->where('id', $id)->get()->getRowArray();
        $this->assertNull($row['pointing_error_arcsec']);
        $this->assertNull($row['pointing_error_ra_arcsec']);
        $this->assertNull($row['pointing_error_dec_arcsec']);
    }

    /**
     * The one exception to "re-analysis updates all fields": pointing error
     * characterizes the mount's pointing behavior at ORIGINAL capture time, so
     * a re-analysis of the same filename must leave whatever was stored first
     * completely untouched — even though the pipeline recomputes and submits
     * a fresh value on every call regardless (it has no notion of "already
     * stored" — see observatory-pipeline's CLAUDE.md, pipeline.py step 11).
     */
    public function testResubmittingSameFilenameDoesNotOverwritePointingError(): void
    {
        $first = $this->withHeaders($this->authHeaders())
            ->withBodyFormat('json')
            ->post(self::ENDPOINT, $this->validPayload([
                'pointing_error_arcsec'     => 12.4,
                'pointing_error_ra_arcsec'  => -8.1,
                'pointing_error_dec_arcsec' => -9.4,
            ]));

        $first->assertStatus(201);
        $firstId = json_decode($first->getJSON(), true)['id'];

        // Re-analysis: same filename, a freshly (re)computed — and different —
        // pointing error, as if the file were re-solved from scratch.
        $second = $this->withHeaders($this->authHeaders())
            ->withBodyFormat('json')
            ->post(self::ENDPOINT, $this->validPayload([
                'pointing_error_arcsec'     => 99.9,
                'pointing_error_ra_arcsec'  => 50.0,
                'pointing_error_dec_arcsec' => 60.0,
            ]));

        $second->assertStatus(200);
        $this->assertSame($firstId, json_decode($second->getJSON(), true)['id']);

        $db  = \Config\Database::connect('default');
        $row = $db->table('frames')->where('id', $firstId)->get()->getRowArray();
        $this->assertSame(12.4, (float) $row['pointing_error_arcsec'], 'Re-analysis must not overwrite the original pointing_error_arcsec.');
        $this->assertSame(-8.1, (float) $row['pointing_error_ra_arcsec'], 'Re-analysis must not overwrite the original pointing_error_ra_arcsec.');
        $this->assertSame(-9.4, (float) $row['pointing_error_dec_arcsec'], 'Re-analysis must not overwrite the original pointing_error_dec_arcsec.');
    }

    /**
     * A frame first registered with no pointing error available (e.g.
     * astrometry failed that run) stays NULL forever, even if a later
     * re-analysis manages to compute one — same preserve-first-value rule,
     * just starting from NULL instead of a real number.
     */
    public function testResubmittingSameFilenameDoesNotBackfillPointingErrorFromNull(): void
    {
        $first = $this->withHeaders($this->authHeaders())
            ->withBodyFormat('json')
            ->post(self::ENDPOINT, $this->validPayload());

        $first->assertStatus(201);
        $firstId = json_decode($first->getJSON(), true)['id'];

        $second = $this->withHeaders($this->authHeaders())
            ->withBodyFormat('json')
            ->post(self::ENDPOINT, $this->validPayload([
                'pointing_error_arcsec'     => 12.4,
                'pointing_error_ra_arcsec'  => -8.1,
                'pointing_error_dec_arcsec' => -9.4,
            ]));

        $second->assertStatus(200);

        $db  = \Config\Database::connect('default');
        $row = $db->table('frames')->where('id', $firstId)->get()->getRowArray();
        $this->assertNull($row['pointing_error_arcsec']);
        $this->assertNull($row['pointing_error_ra_arcsec']);
        $this->assertNull($row['pointing_error_dec_arcsec']);
    }
}
