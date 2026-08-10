<?php

namespace Tests\Feature;

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * Feature tests for:
 *   GET  /api/v1/sources/{id}/track
 *   POST /api/v1/sources/{id}/chart
 *   GET  /api/v1/sources/{id}/chart.png
 *
 * @internal
 */
final class SourceChartsTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    private const API_KEY = 'your-secret-key-here';

    // A minimal but valid 1x1 PNG (the 8-byte signature plus a trivial
    // IHDR/IDAT/IEND chunk set) — enough to pass the controller's signature
    // check without needing a real rendering library in these tests.
    private const MINIMAL_PNG = "\x89PNG\r\n\x1a\n" . "rest-of-a-fake-but-signed-png-body";

    protected function setUp(): void
    {
        parent::setUp();
        $this->emptyAppTables();
    }

    private function emptyAppTables(): void
    {
        $db = \Config\Database::connect('default');
        $db->query('DELETE FROM source_charts');
        $db->query('DELETE FROM anomalies');
        $db->query('DELETE FROM frame_sources');
        $db->query('DELETE FROM source_observations');
        $db->query('DELETE FROM sources');
        $db->query('DELETE FROM object_stats');
        $db->query('DELETE FROM frames');
    }

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
            'object'       => 'Vesta_A807_FA',
            'obs_time'     => '2024-03-15 22:01:34',
            'ra_center'    => 202.4696,
            'dec_center'   => 47.1952,
            'fov_deg'      => 1.25,
            'quality_flag' => 'OK',
        ], $overrides));

        return $id;
    }

    /**
     * Insert a source plus N source_observations rows (each on its own
     * frame), simulating a multi-epoch track like a moving asteroid.
     *
     * @return array{0: string, 1: string[]} [source_id, frame_ids]
     */
    private function createSourceWithEpochs(int $epochCount): array
    {
        $db       = \Config\Database::connect('default');
        $sourceId = uniqid('', true);

        $db->table('sources')->insert([
            'id'                => $sourceId,
            'catalog_name'      => 'MPC',
            // Unique per call (not just "Vesta") so a single test can create
            // more than one source without tripping the (catalog_name,
            // catalog_id) unique constraint — see uniq_sources_catalog_identity.
            'catalog_id'        => 'Vesta-' . $sourceId,
            'object_type'       => null,
            'first_observed_at' => '2024-03-15 16:54:55',
            'last_observed_at'  => '2024-03-15 18:05:44',
            'observation_count' => $epochCount,
        ]);

        $frameIds = [];

        for ($i = 0; $i < $epochCount; $i++) {
            $obsTime = sprintf('2024-03-15 %02d:00:00', 16 + $i);
            $frameId = $this->createFrame([
                'filename' => "Vesta_A807_FA_Light_L_60_epoch{$i}.fits",
                'obs_time' => $obsTime,
            ]);
            $frameIds[] = $frameId;

            $db->table('source_observations')->insert([
                'id'        => uniqid('', true),
                'source_id' => $sourceId,
                'frame_id'  => $frameId,
                'ra'        => 202.4696 + $i * 0.01,
                'dec'       => 47.1952 + $i * 0.005,
                'mag'       => 8.1,
                'obs_time'  => $obsTime,
            ]);
        }

        return [$sourceId, $frameIds];
    }

    // -------------------------------------------------------------------------
    // GET /api/v1/sources/{id}/track
    // -------------------------------------------------------------------------

    public function testTrackReturns404ForUnknownSource(): void
    {
        $result = $this->withHeaders($this->authHeaders())
            ->get('/api/v1/sources/does-not-exist/track');

        $result->assertStatus(404);
    }

    public function testTrackReturnsEpochsInChronologicalOrderWithPositionsAndFilenames(): void
    {
        [$sourceId] = $this->createSourceWithEpochs(3);

        $result = $this->withHeaders($this->authHeaders())
            ->get("/api/v1/sources/{$sourceId}/track");

        $result->assertStatus(200);
        $json = json_decode($result->getJSON(), true);

        $this->assertSame($sourceId, $json['source_id']);
        $this->assertCount(3, $json['epochs']);

        // Chronological order: epoch0 (16:00) before epoch1 (17:00) before epoch2 (18:00).
        $this->assertSame('Vesta_A807_FA_Light_L_60_epoch0.fits', $json['epochs'][0]['filename']);
        $this->assertSame('Vesta_A807_FA_Light_L_60_epoch2.fits', $json['epochs'][2]['filename']);

        // Each epoch carries the position the object was actually detected
        // at on that frame — not a single fixed source position — since a
        // moving object's ra/dec differs epoch to epoch.
        $this->assertSame(202.4696, $json['epochs'][0]['ra']);
        $this->assertEqualsWithDelta(202.4896, $json['epochs'][2]['ra'], 1e-9);

        $this->assertSame('Vesta_A807_FA', $json['epochs'][0]['object']);
        $this->assertArrayHasKey('frame_id', $json['epochs'][0]);
        $this->assertArrayHasKey('mag', $json['epochs'][0]);
    }

    public function testTrackReturnsEmptyEpochsForSourceWithNoObservations(): void
    {
        // A source row with zero source_observations rows is not expected
        // in practice (see SourceModel docblock — a source is only ever
        // created alongside its first observation), but the endpoint must
        // still degrade gracefully rather than error.
        $db       = \Config\Database::connect('default');
        $sourceId = uniqid('', true);
        $db->table('sources')->insert([
            'id'                => $sourceId,
            'catalog_name'      => 'Gaia DR3',
            'catalog_id'        => 'Gaia DR3 999',
            'observation_count' => 0,
        ]);

        $result = $this->withHeaders($this->authHeaders())
            ->get("/api/v1/sources/{$sourceId}/track");

        $result->assertStatus(200);
        $json = json_decode($result->getJSON(), true);
        $this->assertSame([], $json['epochs']);
    }

    // -------------------------------------------------------------------------
    // POST /api/v1/sources/{id}/chart
    // -------------------------------------------------------------------------

    public function testUploadChartReturns404ForUnknownSource(): void
    {
        // Well-formed (alnum + dot, like a real uniqid()) but not present in
        // `sources` — exercises the 404 branch specifically, as opposed to
        // the isValidSourceId() 400 branch covered separately below.
        $result = $this->withHeaders($this->authHeaders())
            ->withBody(self::MINIMAL_PNG)
            ->post('/api/v1/sources/doesnotexist123.00000000/chart?style=track&frame_count=3');

        $result->assertStatus(404);
    }

    public function testUploadChartRejectsInvalidStyle(): void
    {
        [$sourceId] = $this->createSourceWithEpochs(2);

        $result = $this->withHeaders($this->authHeaders())
            ->withBody(self::MINIMAL_PNG)
            ->post("/api/v1/sources/{$sourceId}/chart?style=bogus&frame_count=2");

        $result->assertStatus(400);
    }

    public function testUploadChartRejectsNonPngBody(): void
    {
        [$sourceId] = $this->createSourceWithEpochs(2);

        $result = $this->withHeaders($this->authHeaders())
            ->withBody('not a png at all')
            ->post("/api/v1/sources/{$sourceId}/chart?style=track&frame_count=2");

        $result->assertStatus(400);
    }

    public function testUploadChartStoresFileAndUpsertsRecord(): void
    {
        [$sourceId] = $this->createSourceWithEpochs(3);

        $result = $this->withHeaders($this->authHeaders())
            ->withBody(self::MINIMAL_PNG)
            ->post("/api/v1/sources/{$sourceId}/chart?style=track&frame_count=3");

        $result->assertStatus(200);
        $json = json_decode($result->getJSON(), true);
        $this->assertSame($sourceId, $json['source_id']);
        $this->assertSame('track', $json['style']);
        $this->assertSame(3, $json['frame_count']);
        $this->assertNotNull($json['updated_at']);

        $this->assertFileExists(WRITEPATH . 'uploads/charts/' . $sourceId . '.png');
        $this->assertSame(self::MINIMAL_PNG, file_get_contents(WRITEPATH . 'uploads/charts/' . $sourceId . '.png'));

        // A second upload for the same source overwrites in place — exactly
        // one source_charts row, not a growing history table.
        $result2 = $this->withHeaders($this->authHeaders())
            ->withBody(self::MINIMAL_PNG)
            ->post("/api/v1/sources/{$sourceId}/chart?style=stamp_strip&frame_count=4");

        $result2->assertStatus(200);
        $json2 = json_decode($result2->getJSON(), true);
        $this->assertSame('stamp_strip', $json2['style']);
        $this->assertSame(4, $json2['frame_count']);

        $db    = \Config\Database::connect('default');
        $count = $db->table('source_charts')->where('source_id', $sourceId)->countAllResults();
        $this->assertSame(1, $count);

        @unlink(WRITEPATH . 'uploads/charts/' . $sourceId . '.png');
    }

    // -------------------------------------------------------------------------
    // GET /api/v1/sources/{id}/chart.png
    // -------------------------------------------------------------------------

    public function testChartReturns404WhenNoneUploadedYet(): void
    {
        [$sourceId] = $this->createSourceWithEpochs(1);

        $result = $this->withHeaders($this->authHeaders())
            ->get("/api/v1/sources/{$sourceId}/chart.png");

        $result->assertStatus(404);
    }

    public function testChartServesUploadedBytesWithPngContentType(): void
    {
        [$sourceId] = $this->createSourceWithEpochs(2);

        $this->withHeaders($this->authHeaders())
            ->withBody(self::MINIMAL_PNG)
            ->post("/api/v1/sources/{$sourceId}/chart?style=track&frame_count=2");

        $result = $this->withHeaders($this->authHeaders())
            ->get("/api/v1/sources/{$sourceId}/chart.png");

        $result->assertStatus(200);
        $this->assertSame(self::MINIMAL_PNG, $result->response()->getBody());
        $this->assertStringContainsString('image/png', $result->response()->getHeaderLine('Content-Type'));

        @unlink(WRITEPATH . 'uploads/charts/' . $sourceId . '.png');
    }

    public function testChartRejectsPathTraversalAttemptInIdSegment(): void
    {
        $result = $this->withHeaders($this->authHeaders())
            ->get('/api/v1/sources/..%2f..%2f..%2fetc%2fpasswd/chart.png');

        // Either a 400 (rejected by isValidSourceId()) or a 404 (route
        // segment doesn't actually resolve to a traversal after decoding)
        // is an acceptable outcome — what matters is it is never a 200
        // serving arbitrary filesystem content.
        $this->assertNotSame(200, $result->response()->getStatusCode());
    }

    // -------------------------------------------------------------------------
    // POST /api/v1/sources/tracks/batch
    // -------------------------------------------------------------------------

    public function testTracksBatchReturnsEpochsKeyedBySourceId(): void
    {
        [$sourceIdA] = $this->createSourceWithEpochs(3);
        [$sourceIdB] = $this->createSourceWithEpochs(1);

        $result = $this->withHeaders($this->authHeaders())
            ->withBodyFormat('json')
            ->post('/api/v1/sources/tracks/batch', ['source_ids' => [$sourceIdA, $sourceIdB]]);

        $result->assertStatus(200);
        $json = json_decode($result->getJSON(), true);

        $this->assertCount(3, $json['results'][$sourceIdA]);
        $this->assertCount(1, $json['results'][$sourceIdB]);

        // Chronological order preserved, same as the single-source endpoint.
        $this->assertSame('Vesta_A807_FA_Light_L_60_epoch0.fits', $json['results'][$sourceIdA][0]['filename']);
        $this->assertSame('Vesta_A807_FA_Light_L_60_epoch2.fits', $json['results'][$sourceIdA][2]['filename']);
    }

    public function testTracksBatchResolvesUnknownOrMalformedIdsToEmptyEpochsWithoutFailingOthers(): void
    {
        [$sourceIdA] = $this->createSourceWithEpochs(2);

        $result = $this->withHeaders($this->authHeaders())
            ->withBodyFormat('json')
            ->post('/api/v1/sources/tracks/batch', [
                'source_ids' => [$sourceIdA, 'does-not-exist.00000000', '../../etc/passwd'],
            ]);

        $result->assertStatus(200);
        $json = json_decode($result->getJSON(), true);

        $this->assertCount(2, $json['results'][$sourceIdA]);
        $this->assertSame([], $json['results']['does-not-exist.00000000']);
        $this->assertSame([], $json['results']['../../etc/passwd']);
    }

    public function testTracksBatchWithEmptySourceIdsReturnsEmptyResults(): void
    {
        $result = $this->withHeaders($this->authHeaders())
            ->withBodyFormat('json')
            ->post('/api/v1/sources/tracks/batch', ['source_ids' => []]);

        $result->assertStatus(200);
        $json = json_decode($result->getJSON(), true);
        $this->assertSame([], $json['results']);
    }

    public function testTracksBatchRejectsMissingSourceIdsField(): void
    {
        $result = $this->withHeaders($this->authHeaders())
            ->withBodyFormat('json')
            ->post('/api/v1/sources/tracks/batch', ['oops' => []]);

        $result->assertStatus(400);
    }
}
