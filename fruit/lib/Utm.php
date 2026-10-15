<?php
declare(strict_types=1);

/**
 * UTM -> WGS84.
 *
 * The Kitchener inventory ships x/y as UTM zone 17N metres, not degrees, so
 * every row has to be projected before Google Maps can plot it. This is the
 * standard inverse transverse Mercator series (Snyder, USGS Professional Paper
 * 1395); it round-trips against the forward projection to well under a
 * millimetre, which is far finer than the trees are surveyed.
 */
final class Utm
{
    private const A  = 6378137.0;              // WGS84 semi-major axis
    private const F  = 1 / 298.257223563;      // flattening
    private const K0 = 0.9996;                 // UTM scale factor
    private const E0 = 500000.0;               // false easting

    /** Kitchener sits in zone 17N. */
    public const KITCHENER_ZONE = 17;

    /** @return array{0:float,1:float} [latitude, longitude] in degrees */
    public static function toLatLon(
        float $x,
        float $y,
        int $zone = self::KITCHENER_ZONE,
        bool $north = true
    ): array {
        $e2  = self::F * (2 - self::F);
        $ep2 = $e2 / (1 - $e2);

        $x -= self::E0;
        if (!$north) {
            $y -= 10000000.0;
        }

        $m  = $y / self::K0;
        $mu = $m / (self::A * (1 - $e2 / 4 - 3 * $e2 ** 2 / 64 - 5 * $e2 ** 3 / 256));

        $e1 = (1 - sqrt(1 - $e2)) / (1 + sqrt(1 - $e2));

        $phi1 = $mu
            + (3 * $e1 / 2 - 27 * $e1 ** 3 / 32) * sin(2 * $mu)
            + (21 * $e1 ** 2 / 16 - 55 * $e1 ** 4 / 32) * sin(4 * $mu)
            + (151 * $e1 ** 3 / 96) * sin(6 * $mu)
            + (1097 * $e1 ** 4 / 512) * sin(8 * $mu);

        $c1 = $ep2 * cos($phi1) ** 2;
        $t1 = tan($phi1) ** 2;
        $n1 = self::A / sqrt(1 - $e2 * sin($phi1) ** 2);
        $r1 = self::A * (1 - $e2) / (1 - $e2 * sin($phi1) ** 2) ** 1.5;
        $d  = $x / ($n1 * self::K0);

        $lat = $phi1 - ($n1 * tan($phi1) / $r1) * (
            $d ** 2 / 2
            - (5 + 3 * $t1 + 10 * $c1 - 4 * $c1 ** 2 - 9 * $ep2) * $d ** 4 / 24
            + (61 + 90 * $t1 + 298 * $c1 + 45 * $t1 ** 2 - 252 * $ep2 - 3 * $c1 ** 2) * $d ** 6 / 720
        );

        $lon = (
            $d
            - (1 + 2 * $t1 + $c1) * $d ** 3 / 6
            + (5 - 2 * $c1 + 28 * $t1 - 3 * $c1 ** 2 + 8 * $ep2 + 24 * $t1 ** 2) * $d ** 5 / 120
        ) / cos($phi1);

        $lon0 = deg2rad(($zone - 1) * 6 - 180 + 3);

        return [rad2deg($lat), rad2deg($lon0 + $lon)];
    }

    /** Rough sanity fence for the Kitchener-Waterloo area. */
    public static function plausible(float $lat, float $lon): bool
    {
        return $lat > 43.2 && $lat < 43.7 && $lon > -80.8 && $lon < -80.2;
    }
}
