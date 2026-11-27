<?php
declare(strict_types=1);

namespace Pardusmapper;

/**
 * Small colour-coded sector maps for the Rare NPC Stats page.
 *
 * Only terrain plus planets, starbases and wormholes are shown (no other buildings), cropped to
 * the area around the NPC sightings so the map gives a point of reference without being huge.
 */
class TileMap
{
    public const IMPASSABLE = 'I';
    public const ENERGY = 'E';
    public const SPACE = 'S';
    public const NEBULA = 'N';
    public const ASTEROIDS = 'A';
    public const VIRAL = 'V';
    public const EXOTIC = 'X';
    public const WORMHOLE = 'W';
    public const PLANET = 'P';

    /** code => [label, colour] (also used for the legend) */
    public const COLOURS = [
        self::IMPASSABLE => ['Impassable', '#3a6ea5'],
        self::ENERGY => ['Energy (passable)', '#15294a'],
        self::SPACE => ['Space', '#000000'],
        self::NEBULA => ['Nebula', '#a01b14'],
        self::ASTEROIDS => ['Asteroids', '#7a7a7a'],
        self::VIRAL => ['Viral cloud', '#2e8b2e'],
        self::EXOTIC => ['Exotic matter', '#b8860b'],
        self::PLANET => ['Planet / Starbase', '#2bff2b'],
        self::WORMHOLE => ['Wormhole', '#d36cf0'],
    ];

    /** Tiles of margin around the sightings. */
    public const PADDING = 3;

    /**
     * Category of one tile. Missing tiles (no map row) are impassable, like on the sector map.
     */
    public static function category(?string $bg, ?string $fg): string
    {
        $fg = (string) $fg;
        if ($fg !== '') {
            if (str_contains($fg, 'wormhole') || str_contains($fg, 'xhole') || str_contains($fg, 'yhole')) {
                return self::WORMHOLE;
            }
            if (str_contains($fg, 'foregrounds/planet') || str_contains($fg, 'foregrounds/starbase')) {
                return self::PLANET;
            }
        }

        $bg = (string) $bg;
        return match (true) {
            $bg === '' || str_contains($bg, 'energymax') => self::IMPASSABLE,
            str_contains($bg, 'backgrounds/energy') => self::ENERGY,
            str_contains($bg, 'backgrounds/nebula') => self::NEBULA,
            str_contains($bg, 'backgrounds/asteroids') => self::ASTEROIDS,
            str_contains($bg, 'backgrounds/viral_cloud') => self::VIRAL,
            str_contains($bg, 'backgrounds/exotic_matter') => self::EXOTIC,
            default => self::SPACE,
        };
    }

    /**
     * Area to show: the sightings plus PADDING tiles, kept inside the sector.
     *
     * @param object|null $dim sector (rows, cols) from DB::sector_dims()
     * @param array $marks list of ['x' => int, 'y' => int, ...]
     * @return array [x0, y0, x1, y1] inclusive
     */
    public static function crop(?object $dim, array $marks): array
    {
        $xs = array_column($marks, 'x') ?: [0];
        $ys = array_column($marks, 'y') ?: [0];
        $maxX = $dim ? (int) $dim->cols - 1 : max($xs) + self::PADDING;
        $maxY = $dim ? (int) $dim->rows - 1 : max($ys) + self::PADDING;

        return [
            max(0, min($xs) - self::PADDING),
            max(0, min($ys) - self::PADDING),
            min($maxX, max($xs) + self::PADDING),
            min($maxY, max($ys) + self::PADDING),
        ];
    }

    /**
     * Category code for every tile in the crop: $grid[y - y0][x - x0].
     */
    public static function grid(string $universe, string $sector, array $crop): array
    {
        [$x0, $y0, $x1, $y1] = $crop;
        $grid = array_fill(0, $y1 - $y0 + 1, array_fill(0, $x1 - $x0 + 1, self::IMPASSABLE));

        foreach (DB::map_tiles($universe, $sector, $x0, $x1, $y0, $y1) as $t) {
            $grid[(int) $t->y - $y0][(int) $t->x - $x0] = self::category($t->bg, $t->fg);
        }

        return $grid;
    }
}
