<?php

namespace App\Libraries;

/**
 * Spherical-geometry helpers shared by every cone-search / coverage query.
 *
 * Centralizes three pieces of math that used to be duplicated (and subtly
 * wrong) across FramesController, SourcesController and SourceModel:
 *
 *  - Haversine great-circle distance (arcseconds) — the precise filter
 *    applied after a bounding-box pre-filter.
 *  - Declination-corrected RA margin — a fixed angular radius spans more
 *    RA *degrees* as |dec| grows, because meridians converge at the poles.
 *    Using the radius directly as an RA delta under-covers near the poles.
 *  - RA range splitting across the 0°/360° seam — a naive
 *    `ra BETWEEN ra-delta AND ra+delta` silently misses sources on the far
 *    side of RA=0 (e.g. querying ra=0.001 must also catch ra=359.999).
 */
final class SkyMath
{
    /**
     * Great-circle angular separation between two sky points, in arcseconds.
     */
    public static function haversineArcsec(float $ra1, float $dec1, float $ra2, float $dec2): float
    {
        $ra1  = deg2rad($ra1);
        $dec1 = deg2rad($dec1);
        $ra2  = deg2rad($ra2);
        $dec2 = deg2rad($dec2);

        $dra  = $ra2 - $ra1;
        $ddec = $dec2 - $dec1;

        $a = sin($ddec / 2) ** 2 + cos($dec1) * cos($dec2) * sin($dra / 2) ** 2;

        return 2 * asin(sqrt(min(1.0, $a))) * (180.0 / M_PI) * 3600.0;
    }

    /**
     * Radius (arcseconds) of the circle a frame is treated as covering.
     *
     * `frames.fov_deg` is the pipeline's LONGEST axis, so `fov_deg / 2` is
     * the circle inscribed along that side and never reaches the corners:
     * on a 4656x3520 frame at 0.78"/px the corners lie ~0.63 deg from the
     * centre while fov_deg / 2 is 0.50 deg. Every sky position in the
     * corner regions was therefore reported "never covered", however many
     * frames had imaged it — and observatory-pipeline's classifier trusts
     * that answer, sending a corner subtraction candidate straight to the
     * no-coverage UNKNOWN alert branch without consulting history (10 of 11
     * UNKNOWN alerts on the 2026-09-22 IC3322A test run).
     *
     * This returns the half-DIAGONAL instead — the circumscribed circle —
     * from the frame's own aspect ratio when its pixel dimensions are known,
     * and the square-frame worst case (fov_deg / 2 * sqrt(2)) when they are
     * not. It errs toward "covered", which is the safe direction for this
     * check: a false "covered" at worst turns a FIRST_OBSERVATION note into a
     * classification the other evidence still has to support, whereas a false
     * "not covered" bypasses the history check entirely. The exact rotated
     * rectangle would need the frame's position angle applied with a sign
     * convention this side does not own; the circle needs none.
     */
    public static function coverageRadiusArcsec(float $fovDeg, ?int $widthPx, ?int $heightPx): float
    {
        $halfLong = $fovDeg / 2.0;

        if ($widthPx !== null && $heightPx !== null && $widthPx > 0 && $heightPx > 0) {
            $aspect = min($widthPx, $heightPx) / max($widthPx, $heightPx);

            return $halfLong * sqrt(1.0 + $aspect * $aspect) * 3600.0;
        }

        return $halfLong * M_SQRT2 * 3600.0;
    }

    /**
     * RA half-width (in degrees) a bounding box needs at a given declination
     * to fully cover a fixed angular margin.
     *
     * At the equator, 1 degree of RA ≈ 1 degree of angular distance. Near the
     * poles the same angular distance spans many more RA degrees (meridians
     * converge), so the naive `marginDeg` used directly as an RA delta
     * under-covers as |dec| grows and can silently miss real matches.
     *
     * Clamped to 180° (half the sky) — nothing wider is ever useful, and it
     * keeps the value finite right at the poles where cos(dec) → 0.
     */
    public static function raMargin(float $decDeg, float $marginDeg): float
    {
        $cosDec = cos(deg2rad($decDeg));

        // Guard against division by (near-)zero at the poles.
        if (abs($cosDec) < 0.0001) {
            return 180.0;
        }

        return min(180.0, $marginDeg / abs($cosDec));
    }

    /**
     * Split a [ra - margin, ra + margin] window into one or two RA ranges,
     * each fully inside [0, 360), so a bounding-box query never has to rely
     * on a naively-wrapping BETWEEN clause at the RA=0/360 seam.
     *
     * @return list<array{0: float, 1: float}> One range in the normal case,
     *                                          two if the window straddles
     *                                          the RA=0/360 seam.
     */
    public static function raRanges(float $raDeg, float $marginDeg): array
    {
        if ($marginDeg >= 180.0) {
            return [[0.0, 360.0]];
        }

        // Normalize into [0, 360) first so callers can pass any raw RA value.
        $raDeg = fmod($raDeg, 360.0);
        if ($raDeg < 0.0) {
            $raDeg += 360.0;
        }

        $min = $raDeg - $marginDeg;
        $max = $raDeg + $marginDeg;

        if ($min < 0.0) {
            return [[0.0, $max], [$min + 360.0, 360.0]];
        }

        if ($max > 360.0) {
            return [[$min, 360.0], [0.0, $max - 360.0]];
        }

        return [[$min, $max]];
    }

    /**
     * Compute one or two RA ranges that together cover a whole batch of
     * (ra, marginDeg) pairs — used to build a single bounding-box query for
     * the batch endpoints instead of one query per position.
     *
     * A plain min/max over raw RA values breaks down the moment the batch
     * straddles the RA=0/360 seam (e.g. one source at ra=359.9, another at
     * ra=0.1 — the "obvious" span of 359.8° is actually a real cluster only
     * 0.2° wide). This uses the circular mean of the RA values as a
     * wrap-safe center, which degrades gracefully to a plain arithmetic mean
     * when the batch isn't anywhere near the seam.
     *
     * @param list<array{0: float, 1: float}> $raWithMargins List of
     *                                                        [raDeg, marginDeg] pairs — one per position, margin already
     *                                                        declination-scaled by the caller via {@see self::raMargin()}.
     *
     * @return list<array{0: float, 1: float}>
     */
    public static function combinedRaRanges(array $raWithMargins): array
    {
        if ($raWithMargins === []) {
            return [[0.0, 360.0]];
        }

        $sumSin = 0.0;
        $sumCos = 0.0;

        foreach ($raWithMargins as [$raDeg, $margin]) {
            $rad = deg2rad($raDeg);
            $sumSin += sin($rad);
            $sumCos += cos($rad);
        }

        $center = rad2deg(atan2($sumSin, $sumCos));
        if ($center < 0.0) {
            $center += 360.0;
        }

        $halfSpan = 0.0;

        foreach ($raWithMargins as [$raDeg, $margin]) {
            // Offset of this position from the center, wrapped to (-180, 180].
            $offset   = fmod($raDeg - $center + 540.0, 360.0) - 180.0;
            $halfSpan = max($halfSpan, abs($offset) + $margin);
        }

        return self::raRanges($center, $halfSpan);
    }
}
