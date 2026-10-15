<?php
declare(strict_types=1);

/**
 * Per-city profiles for the tree inventories.
 *
 * The two open datasets agree on almost nothing:
 *
 *                    Kitchener                  Waterloo
 *   coordinates      UTM zone 17N metres        WGS84 degrees, x=lng y=lat
 *   genus            SPECIES_GENUS column       first word of the Latin Name
 *   common name      "Black Cherry"             "Cherry, black"
 *   alive means      STATUS = ACTIVE            STATUS = Existing
 *   address          civic number + street      one Address field
 *   stable id        OBJECTID                   Tree ID
 *
 * The OBJECTIDs collide - both count from 1 - so the dedupe marker written into
 * notes is prefixed per source: [kt:…] and [wl:…].
 */
final class Source
{
    /** @return array<string,array> */
    public static function all(): array
    {
        return [
            'kitchener' => [
                'label'    => 'Kitchener',
                'prefix'   => 'kt',
                'requires' => ['SPECIES_NAME', 'SPECIES_GENUS', 'OBJECTID'],
                'alive'    => ['ACTIVE'],
                'statusCol' => 'STATUS',
                'map'      => static function (callable $g): array {
                    $x = $g('x');
                    $y = $g('y');
                    if (!is_numeric($x) || !is_numeric($y) || (float) $x == 0.0) {
                        return [];
                    }
                    [$lat, $lng] = Utm::toLatLon((float) $x, (float) $y);

                    $where = trim($g('Closest Civic Number') . ' ' . $g('STREET'));
                    $park  = $g('PARK');

                    $bits = array_filter([
                        $g('SPECIES_LATIN') ?: $g('SPECIES_GENUS'),
                        $park !== '' && $where !== '' ? $park : '',
                        $g('LOCATION') ? strtolower($g('LOCATION')) : '',
                        ((int) $g('Mapping DBH (cm)')) > 0 ? 'trunk ' . (int) $g('Mapping DBH (cm)') . 'cm' : '',
                        ((int) $g('YEAR_PLANTED')) > 1700 ? 'planted ' . (int) $g('YEAR_PLANTED') : '',
                        $g('WARD') ? 'ward ' . $g('WARD') : '',
                    ]);

                    return [
                        'id'      => $g('OBJECTID'),
                        'lat'     => $lat,
                        'lng'     => $lng,
                        'species' => $g('SPECIES_NAME'),
                        'genus'   => $g('SPECIES_GENUS'),
                        'name'    => $where !== '' ? $where : ($park !== '' ? $park : $g('LOCATION')),
                        'bits'    => $bits,
                    ];
                },
            ],

            'waterloo' => [
                'label'    => 'Waterloo',
                'prefix'   => 'wl',
                'requires' => ['Tree ID', 'Common Name', 'Latin Name'],
                'alive'    => ['Existing'],
                'statusCol' => 'STATUS',
                'map'      => static function (callable $g): array {
                    // already degrees - x is longitude, y is latitude
                    $lng = $g('x');
                    $lat = $g('y');
                    if (!is_numeric($lat) || !is_numeric($lng) || (float) $lat == 0.0) {
                        return [];
                    }

                    $latin = $g('Latin Name');
                    $genus = $latin !== '' ? explode(' ', $latin)[0] : '';

                    $bits = array_filter([
                        $latin ?: $genus,
                        $g('TREE_TYPE') ? strtolower($g('TREE_TYPE')) : '',
                        ((int) $g('DBH_CM')) > 0 ? 'trunk ' . (int) $g('DBH_CM') . 'cm' : '',
                        $g('Owner') && $g('Owner') !== 'City of Waterloo' ? $g('Owner') : '',
                    ]);

                    return [
                        'id'      => $g('Tree ID'),
                        'lat'     => (float) $lat,
                        'lng'     => (float) $lng,
                        'species' => self::tidyName($g('Common Name')),
                        'genus'   => $genus,
                        'name'    => $g('Address'),
                        'bits'    => $bits,
                    ];
                },
            ],
        ];
    }

    /**
     * Waterloo files its common names inverted - "Cherry, black" - so the same
     * tree would appear twice in a shared species list, once per city. Flipping
     * on the comma and title-casing lines them up: "Mulberry, White" and
     * Kitchener's "White Mulberry" become one entry.
     */
    public static function tidyName(string $name): string
    {
        $name = trim(preg_replace('/\s+/', ' ', $name) ?? $name);
        if ($name === '') {
            return '';
        }
        if (str_contains($name, ',')) {
            [$head, $tail] = array_map('trim', explode(',', $name, 2));
            if ($tail !== '') {
                $name = $tail . ' ' . $head;
            }
        }
        return ucwords(mb_strtolower($name));
    }

    /** Work out which city a CSV came from, by its header. */
    public static function detect(array $header): ?string
    {
        $have = array_flip(array_map('trim', $header));
        foreach (self::all() as $key => $src) {
            $ok = true;
            foreach ($src['requires'] as $col) {
                if (!isset($have[$col])) {
                    $ok = false;
                    break;
                }
            }
            if ($ok) {
                return $key;
            }
        }
        return null;
    }

    public static function get(string $key): ?array
    {
        return self::all()[$key] ?? null;
    }
}
