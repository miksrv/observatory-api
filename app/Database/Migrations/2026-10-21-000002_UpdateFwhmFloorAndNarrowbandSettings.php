<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Bring two seeded settings in line with observatory-pipeline (its docs/API-TASKS.md #3).
 * Data-only; no schema change.
 *
 * - `STAR_FWHM_MIN_ARCSEC` → `STAR_FWHM_MIN_PX` (value 1.2). The pipeline's hot-pixel floor moved
 *   from arcsec to pixels (a hot pixel's size is set by the pixel grid, not the optics) and the old
 *   name no longer exists there, so the old row was silently ignored and the new parameter could
 *   not be tuned remotely. Renamed only when no `STAR_FWHM_MIN_PX` row exists yet; the old value is
 *   not carried over — it was in different units.
 * - `NARROWBAND_FILTERS` gains the multi-band OSC filters. The old seed value (`Ha,OIII,SII,NII`)
 *   held an L-eNhance or other multi-band frame to the broadband star floor and calibrated it
 *   against Gaia. Updated only while the row still holds that old seed value, so an
 *   operator-edited list is left alone.
 */
class UpdateFwhmFloorAndNarrowbandSettings extends Migration
{
    private const FWHM_OLD = [
        'param'       => 'STAR_FWHM_MIN_ARCSEC',
        'value'       => '2.5',
        'description' => 'Minimum acceptable star FWHM in arcseconds',
    ];

    private const FWHM_NEW = [
        'param'       => 'STAR_FWHM_MIN_PX',
        'value'       => '1.2',
        'description' => 'Minimum star FWHM in pixels — sharper sources are hot pixels or cosmic rays',
    ];

    private const NARROWBAND_OLD = 'Ha,OIII,SII,NII';
    private const NARROWBAND_NEW = 'Ha,OIII,SII,NII,LeNhance,LeXtreme,LuLtimate,NBZ,QuadBand,TriBand,DuoBand';

    public function up(): void
    {
        $this->renameSetting(self::FWHM_OLD, self::FWHM_NEW, insertIfMissing: true);

        $this->db->query(
            "UPDATE `settings` SET `value` = ? WHERE `param` = 'NARROWBAND_FILTERS' AND `value` = ?",
            [self::NARROWBAND_NEW, self::NARROWBAND_OLD],
        );
    }

    public function down(): void
    {
        // Only while it still holds the value up() gave it: an operator-tuned pixel floor stays.
        $this->renameSetting(self::FWHM_NEW, self::FWHM_OLD, insertIfMissing: false, onlyIfValue: self::FWHM_NEW['value']);

        $this->db->query(
            "UPDATE `settings` SET `value` = ? WHERE `param` = 'NARROWBAND_FILTERS' AND `value` = ?",
            [self::NARROWBAND_OLD, self::NARROWBAND_NEW],
        );
    }

    /**
     * Turn the `$from` row into `$to` (name, value, description) unless a `$to` row already
     * exists, and — with `$onlyIfValue` — only while `$from` still holds that value. With no
     * `$from` row either, `$to` is inserted when `$insertIfMissing`.
     *
     * @param array{param: string, value: string, description: string} $from
     * @param array{param: string, value: string, description: string} $to
     */
    private function renameSetting(array $from, array $to, bool $insertIfMissing, ?string $onlyIfValue = null): void
    {
        if ($this->settingExists($to['param'])) {
            return;
        }

        if ($this->settingExists($from['param'])) {
            $this->db->query(
                'UPDATE `settings` SET `param` = ?, `value` = ?, `description` = ? WHERE `param` = ?'
                . ($onlyIfValue !== null ? ' AND `value` = ?' : ''),
                array_merge([$to['param'], $to['value'], $to['description'], $from['param']], $onlyIfValue !== null ? [$onlyIfValue] : []),
            );
        } elseif ($insertIfMissing) {
            $this->db->query(
                "INSERT INTO `settings` (`param`, `value`, `description`, `type`) VALUES (?, ?, ?, 'config')",
                [$to['param'], $to['value'], $to['description']],
            );
        }
    }

    private function settingExists(string $param): bool
    {
        return $this->db->query('SELECT 1 FROM `settings` WHERE `param` = ?', [$param])->getRow() !== null;
    }
}
