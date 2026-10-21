<?php

namespace Tests\Feature;

use App\Database\Migrations\UpdateFwhmFloorAndNarrowbandSettings;
use Tests\Support\DatabaseTestCase;

/**
 * 2026-10-21-000002_UpdateFwhmFloorAndNarrowbandSettings on a database seeded before it: renames
 * the FWHM floor and updates NARROWBAND_FILTERS only where they still hold the old seed, and
 * reverses that in down().
 *
 * @internal
 */
final class SettingsMigrationTest extends DatabaseTestCase
{
    private const PARAMS = ['STAR_FWHM_MIN_ARCSEC', 'STAR_FWHM_MIN_PX', 'NARROWBAND_FILTERS'];
    private const NARROWBAND_NEW = 'Ha,OIII,SII,NII,LeNhance,LeXtreme,LuLtimate,NBZ,QuadBand,TriBand,DuoBand';

    /** @var list<array<string, mixed>> */
    private array $saved = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->saved = $this->db()->table('settings')->whereIn('param', self::PARAMS)->get()->getResultArray();
        $this->db()->table('settings')->whereIn('param', self::PARAMS)->delete();
    }

    protected function tearDown(): void
    {
        $this->db()->table('settings')->whereIn('param', self::PARAMS)->delete();
        foreach ($this->saved as $row) {
            $this->db()->table('settings')->insert($row);
        }
        parent::tearDown();
    }

    private function migration(): UpdateFwhmFloorAndNarrowbandSettings
    {
        // Date-prefixed migration files aren't PSR-4 autoloadable.
        require_once APPPATH . 'Database/Migrations/2026-10-21-000002_UpdateFwhmFloorAndNarrowbandSettings.php';

        return new UpdateFwhmFloorAndNarrowbandSettings(\Config\Database::forge($this->db()));
    }

    private function seed(string $param, string $value, string $description = 'operator'): void
    {
        $this->db()->table('settings')->insert(['param' => $param, 'value' => $value, 'description' => $description, 'type' => 'config']);
    }

    /** @return array<string, array{value: string, description: string}> */
    private function settings(): array
    {
        $rows = $this->db()->table('settings')->whereIn('param', self::PARAMS)->get()->getResultArray();
        $out  = [];
        foreach ($rows as $row) {
            $out[$row['param']] = ['value' => $row['value'], 'description' => $row['description']];
        }
        ksort($out);

        return $out;
    }

    public function testUpRenamesAndUpdatesTheOldSeedAndDownRestoresIt(): void
    {
        $this->seed('STAR_FWHM_MIN_ARCSEC', '2.5', 'Minimum acceptable star FWHM in arcseconds');
        $this->seed('NARROWBAND_FILTERS', 'Ha,OIII,SII,NII', 'narrowband');
        $before = $this->settings();

        $this->migration()->up();

        $this->assertSame([
            'NARROWBAND_FILTERS' => ['value' => self::NARROWBAND_NEW, 'description' => 'narrowband'],
            'STAR_FWHM_MIN_PX'   => ['value' => '1.2', 'description' => 'Minimum star FWHM in pixels — sharper sources are hot pixels or cosmic rays'],
        ], $this->settings());

        $this->migration()->down();

        $this->assertSame($before, $this->settings());
    }

    public function testUpLeavesOperatorEditedValuesAndAnExistingPixelFloorAlone(): void
    {
        $this->seed('STAR_FWHM_MIN_ARCSEC', '3.0');
        $this->seed('STAR_FWHM_MIN_PX', '1.5');
        $this->seed('NARROWBAND_FILTERS', 'Ha,OIII,SII,NII,MyDualBand');
        $before = $this->settings();

        $this->migration()->up();

        $this->assertSame($before, $this->settings());
    }

    public function testUpAddsThePixelFloorWhenNeitherRowExists(): void
    {
        $this->migration()->up();

        $this->assertSame('1.2', $this->settings()['STAR_FWHM_MIN_PX']['value']);
    }

    public function testDownKeepsAnOperatorTunedPixelFloor(): void
    {
        $this->seed('STAR_FWHM_MIN_PX', '1.5');
        $before = $this->settings();

        $this->migration()->down();

        $this->assertSame($before, $this->settings());
    }
}
