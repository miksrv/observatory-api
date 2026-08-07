<?php

namespace Tests\Feature;

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * Feature tests for GET /api/v1/frames/nearest-before
 *
 * @internal
 */
final class FramesNearestBeforeTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    private const API_KEY  = 'your-secret-key-here';
    private const ENDPOINT = '/api/v1/frames/nearest-before';

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
     * Insert a frame directly into the DB. Returns the frame id (string).
     */
    private function createFrame(
        string $object   = 'Vesta_A807_FA',
        string $filename = 'test.fits',
        string $obsTime  = '2021-03-14 16:54:55'
    ): string {
        $db = \Config\Database::connect('default');
        $id = uniqid('', true);
        $db->table('frames')->insert([
            'id'           => $id,
            'filename'     => $filename,
            'object'       => $object,
            'obs_time'     => $obsTime,
            'ra_center'    => 167.28,
            'dec_center'   => 17.34,
            'fov_deg'      => 1.0,
            'quality_flag' => 'OK',
        ]);

        return $id;
    }

    // -------------------------------------------------------------------------
    // Happy path
    // -------------------------------------------------------------------------

    public function testReturnsTheMostRecentEarlierFrameOfTheSameObject(): void
    {
        $this->createFrame('Vesta_A807_FA', 'frame1.fits', '2021-03-14 16:54:55');
        $this->createFrame('Vesta_A807_FA', 'frame2.fits', '2021-03-14 17:05:27');
        $this->createFrame('Vesta_A807_FA', 'frame3.fits', '2021-03-14 18:05:44');

        $result = $this->withHeaders($this->authHeaders())
            ->get(self::ENDPOINT, [
                'object'      => 'Vesta_A807_FA',
                'before_time' => '2021-03-14T18:05:44Z',
            ]);

        $result->assertStatus(200);
        $json = json_decode($result->getJSON(), true);
        $this->assertNotNull($json['frame']);
        // The most RECENT frame strictly before 18:05:44 is frame2, not frame1.
        $this->assertSame('frame2.fits', $json['frame']['filename']);
        $this->assertSame('Vesta_A807_FA', $json['frame']['object']);
    }

    public function testFramesOfADifferentObjectAreIgnored(): void
    {
        $this->createFrame('M51', 'other_object.fits', '2021-03-14 16:00:00');

        $result = $this->withHeaders($this->authHeaders())
            ->get(self::ENDPOINT, [
                'object'      => 'Vesta_A807_FA',
                'before_time' => '2021-03-14T18:05:44Z',
            ]);

        $result->assertStatus(200);
        $json = json_decode($result->getJSON(), true);
        $this->assertNull($json['frame']);
    }

    public function testNoEarlierFrameReturnsNullFrame(): void
    {
        // Only frame is AFTER before_time — must not be returned.
        $this->createFrame('Vesta_A807_FA', 'later.fits', '2021-03-14 20:00:00');

        $result = $this->withHeaders($this->authHeaders())
            ->get(self::ENDPOINT, [
                'object'      => 'Vesta_A807_FA',
                'before_time' => '2021-03-14T18:05:44Z',
            ]);

        $result->assertStatus(200);
        $json = json_decode($result->getJSON(), true);
        $this->assertNull($json['frame']);
    }

    public function testEmptyFramesTableReturnsNullFrame(): void
    {
        $result = $this->withHeaders($this->authHeaders())
            ->get(self::ENDPOINT, [
                'object'      => 'Vesta_A807_FA',
                'before_time' => '2021-03-14T18:05:44Z',
            ]);

        $result->assertStatus(200);
        $json = json_decode($result->getJSON(), true);
        $this->assertNull($json['frame']);
    }

    public function testResponseShapeHasIdFilenameObjectObsTime(): void
    {
        $this->createFrame('Vesta_A807_FA', 'frame1.fits', '2021-03-14 16:54:55');

        $result = $this->withHeaders($this->authHeaders())
            ->get(self::ENDPOINT, [
                'object'      => 'Vesta_A807_FA',
                'before_time' => '2021-03-14T18:05:44Z',
            ]);

        $result->assertStatus(200);
        $json = json_decode($result->getJSON(), true);
        $this->assertArrayHasKey('id', $json['frame']);
        $this->assertArrayHasKey('filename', $json['frame']);
        $this->assertArrayHasKey('object', $json['frame']);
        $this->assertArrayHasKey('obs_time', $json['frame']);
        $this->assertSame('2021-03-14T16:54:55Z', $json['frame']['obs_time']);
    }

    // -------------------------------------------------------------------------
    // Missing/invalid parameters → 400
    // -------------------------------------------------------------------------

    public function testMissingObjectReturns400(): void
    {
        $result = $this->withHeaders($this->authHeaders())
            ->get(self::ENDPOINT, [
                'before_time' => '2021-03-14T18:05:44Z',
            ]);

        $result->assertStatus(400);
    }

    public function testMissingBeforeTimeReturns400(): void
    {
        $result = $this->withHeaders($this->authHeaders())
            ->get(self::ENDPOINT, [
                'object' => 'Vesta_A807_FA',
            ]);

        $result->assertStatus(400);
    }

    public function testInvalidBeforeTimeReturns400(): void
    {
        $result = $this->withHeaders($this->authHeaders())
            ->get(self::ENDPOINT, [
                'object'      => 'Vesta_A807_FA',
                'before_time' => 'not-a-date',
            ]);

        $result->assertStatus(400);
    }

    // -------------------------------------------------------------------------
    // Authentication
    // -------------------------------------------------------------------------

    public function testNoApiKeyReturns401(): void
    {
        $result = $this->get(self::ENDPOINT, [
            'object'      => 'Vesta_A807_FA',
            'before_time' => '2021-03-14T18:05:44Z',
        ]);

        $result->assertStatus(401);
    }
}
