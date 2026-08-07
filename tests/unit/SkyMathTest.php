<?php

namespace Tests\Unit;

use App\Libraries\SkyMath;
use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * @internal
 */
final class SkyMathTest extends CIUnitTestCase
{
    #[Test]
    public function haversineIsZeroForIdenticalPoints(): void
    {
        $this->assertSame(0.0, SkyMath::haversineArcsec(202.4696, 47.1952, 202.4696, 47.1952));
    }

    #[Test]
    public function haversineMatchesKnownOneDegreeSeparationAtEquator(): void
    {
        // At dec=0, 1 degree of RA is exactly 1 degree of angular distance = 3600 arcsec.
        $distance = SkyMath::haversineArcsec(0.0, 0.0, 1.0, 0.0);

        $this->assertEqualsWithDelta(3600.0, $distance, 0.5);
    }

    #[Test]
    public function haversineHandlesAntipodalPointsWithoutNan(): void
    {
        // Guards the asin(sqrt($a)) domain clamp for floating-point overshoot.
        $distance = SkyMath::haversineArcsec(0.0, 90.0, 180.0, -90.0);

        $this->assertEqualsWithDelta(180.0 * 3600.0, $distance, 1.0);
    }

    #[Test]
    public function raMarginEqualsInputMarginAtEquator(): void
    {
        $this->assertEqualsWithDelta(0.001, SkyMath::raMargin(0.0, 0.001), 0.00001);
    }

    #[Test]
    public function raMarginWidensNearThePoles(): void
    {
        // cos(60°) = 0.5 → margin should double.
        $this->assertEqualsWithDelta(0.002, SkyMath::raMargin(60.0, 0.001), 0.0001);

        // cos(89°) ≈ 0.01745 → margin should blow up by ~57x.
        $wide = SkyMath::raMargin(89.0, 0.001);
        $this->assertGreaterThan(0.05, $wide);
    }

    #[Test]
    public function raMarginClampsToHalfSkyAtThePoles(): void
    {
        $this->assertSame(180.0, SkyMath::raMargin(90.0, 1.0));
        $this->assertSame(180.0, SkyMath::raMargin(-90.0, 1.0));
    }

    #[Test]
    public function raRangesReturnsSingleRangeWhenFarFromTheSeam(): void
    {
        $ranges = SkyMath::raRanges(180.0, 0.5);

        $this->assertSame([[179.5, 180.5]], $ranges);
    }

    #[Test]
    public function raRangesSplitsAcrossZeroSeam(): void
    {
        // ra=0.2 with margin=0.5 → window is [-0.3, 0.7], must wrap to [359.7, 360] + [0, 0.7]
        $ranges = SkyMath::raRanges(0.2, 0.5);

        $this->assertCount(2, $ranges);
        $this->assertEqualsWithDelta(0.0, $ranges[0][0], 0.0001);
        $this->assertEqualsWithDelta(0.7, $ranges[0][1], 0.0001);
        $this->assertEqualsWithDelta(359.7, $ranges[1][0], 0.0001);
        $this->assertEqualsWithDelta(360.0, $ranges[1][1], 0.0001);
    }

    #[Test]
    public function raRangesSplitsAcross360Seam(): void
    {
        // ra=359.8 with margin=0.5 → window is [359.3, 360.3], must wrap to [359.3, 360] + [0, 0.3]
        $ranges = SkyMath::raRanges(359.8, 0.5);

        $this->assertCount(2, $ranges);
        $this->assertEqualsWithDelta(359.3, $ranges[0][0], 0.0001);
        $this->assertEqualsWithDelta(360.0, $ranges[0][1], 0.0001);
        $this->assertEqualsWithDelta(0.0, $ranges[1][0], 0.0001);
        $this->assertEqualsWithDelta(0.3, $ranges[1][1], 0.0001);
    }

    #[Test]
    public function raRangesCoversWholeSkyWhenMarginIsAtLeast180(): void
    {
        $this->assertSame([[0.0, 360.0]], SkyMath::raRanges(10.0, 180.0));
        $this->assertSame([[0.0, 360.0]], SkyMath::raRanges(10.0, 200.0));
    }

    #[Test]
    public function raRangesNormalizesOutOfRangeInputRa(): void
    {
        // -0.1 should behave identically to 359.9
        $this->assertSame(SkyMath::raRanges(359.9, 0.2), SkyMath::raRanges(-0.1, 0.2));
    }
}
