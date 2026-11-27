<?php
declare(strict_types=1);

namespace Pardusmapper;

/**
 * Universe-wide sector layout, built from what the site already has:
 *  - each cluster's minimap template (templates/clusters/*.php) gives its sectors' grid positions
 *    and, through the wormhole connector images (t, r, b, l, lt, rt, lb, rb), which neighbours link;
 *  - resources/index.css gives each cluster's area on the universe map (a#code { top; left; height; width }).
 *
 * The Core minimap (core.php) shows all four P*C clusters and Pardus together, so it is placed over the
 * combined area of those four on the universe map and the separate pec/pfc/puc templates are skipped.
 *
 * Coordinates are in universe-map pixels (the 704 x 624 home page image) times SCALE.
 */
class UniverseMap
{
    public const SCALE = 3;

    /** templates covered by core.php */
    private const CORE_PARTS = ['pc', 'pec', 'pfc', 'puc'];

    /** connector code => [row step, column step] */
    private const DIRECTIONS = [
        't' => [-1, 0], 'b' => [1, 0], 'l' => [0, -1], 'r' => [0, 1],
        'lt' => [-1, -1], 'tl' => [-1, -1], 'rt' => [-1, 1], 'tr' => [-1, 1],
        'lb' => [1, -1], 'bl' => [1, -1], 'rb' => [1, 1], 'br' => [1, 1],
    ];

    private static ?array $cache = null;

    /**
     * @return array ['sectors' => name => ['x','y','w','h','cluster'], 'links' => list of [nameA, nameB]]
     */
    public static function layout(): array
    {
        if (!is_null(self::$cache)) {
            return self::$cache;
        }

        $boxes = self::clusterBoxes();
        $sectors = [];
        $links = [];
        $dangling = []; // connectors that run off their cluster's grid

        // Some cluster templates spell a sector with different capitals ("Epsilon eridani",
        // "Delta pavonis"); use the real sector names so they match the sightings.
        $canon = [];
        $db = Core\MySqlDB::instance();
        $db->execute('SELECT name FROM Pardus_Sectors');
        while ($row = $db->fetchObject()) {
            $canon[strtolower((string) $row->name)] = (string) $row->name;
        }
        $name = static fn (string $n): string => $canon[strtolower($n)] ?? $n;

        foreach (glob(ROOT . '/templates/clusters/*.php') ?: [] as $file) {
            $code = basename($file, '.php');
            if (in_array($code, self::CORE_PARTS, true)) {
                continue;
            }
            $box = $boxes[$code] ?? null;
            if (is_null($box)) {
                continue;
            }

            $grid = self::parseTemplate((string) file_get_contents($file));
            $rows = count($grid);
            $cols = max(array_map('count', $grid) ?: [1]);
            if ($rows === 0 || $cols === 0) {
                continue;
            }
            $cellW = $box['w'] / $cols;
            $cellH = $box['h'] / $rows;

            foreach ($grid as $r => $line) {
                foreach ($line as $c => $cell) {
                    if (is_null($cell)) {
                        continue;
                    }
                    $sectors[$name($cell['name'])] = [
                        'x' => ($box['left'] + ($c + 0.5) * $cellW) * self::SCALE,
                        'y' => ($box['top'] + ($r + 0.5) * $cellH) * self::SCALE,
                        'w' => $cellW * self::SCALE,
                        'h' => $cellH * self::SCALE,
                        'cluster' => strtoupper($code),
                    ];
                    foreach ($cell['dirs'] as $dir) {
                        [$dr, $dc] = self::DIRECTIONS[$dir];
                        $other = $grid[$r + $dr][$c + $dc] ?? null;
                        if (!is_null($other)) {
                            $a = $name($cell['name']);
                            $b = $name($other['name']);
                            $links[self::linkKey($a, $b)] = [$a, $b];
                        } else {
                            // runs off the cluster: a wormhole to another cluster, paired up below
                            $sec = $sectors[$name($cell['name'])];
                            $dangling[] = ['name' => $name($cell['name']), 'cluster' => $code, 'x' => $sec['x'], 'y' => $sec['y'], 'dx' => $dc, 'dy' => $dr];
                        }
                    }
                }
            }
        }

        foreach (self::pairDangling($dangling) as [$a, $b]) {
            $links[self::linkKey($a, $b)] = [$a, $b];
        }

        return self::$cache = ['sectors' => $sectors, 'links' => array_values($links)];
    }

    /** Furthest apart (universe-map units) two connectors can be and still be paired. */
    private const MAX_PAIR_DISTANCE = 120 * self::SCALE;

    /**
     * Pair up connectors that run off their cluster's grid: these are wormholes to a neighbouring
     * cluster, which the templates don't name. A connector pairs with one in another cluster that
     * lies ahead of it and points back towards it; the closest, best-aligned pairs are taken first,
     * each connector at most once.
     *
     * @param array $dangling list of ['name','cluster','x','y','dx','dy']
     * @return array list of [sectorA, sectorB]
     */
    private static function pairDangling(array $dangling): array
    {
        $candidates = [];
        foreach ($dangling as $i => $a) {
            $la = hypot($a['dx'], $a['dy']);
            foreach ($dangling as $j => $b) {
                if ($j <= $i || $a['cluster'] === $b['cluster']) {
                    continue;
                }
                $vx = $b['x'] - $a['x'];
                $vy = $b['y'] - $a['y'];
                $dist = hypot($vx, $vy);
                if ($dist < 1 || $dist > self::MAX_PAIR_DISTANCE) {
                    continue;
                }
                $lb = hypot($b['dx'], $b['dy']);
                // how well each connector points at the other (1 = straight at it)
                $aim = ($vx * $a['dx'] + $vy * $a['dy']) / ($dist * $la);
                $back = -($vx * $b['dx'] + $vy * $b['dy']) / ($dist * $lb);
                if ($aim < 0.5 || $back < 0.5) {
                    continue;
                }
                $candidates[] = [$dist * (3 - $aim - $back), $i, $j];
            }
        }
        usort($candidates, static fn ($p, $q) => $p[0] <=> $q[0]);

        $used = [];
        $pairs = [];
        foreach ($candidates as [, $i, $j]) {
            if (isset($used[$i]) || isset($used[$j])) {
                continue;
            }
            $used[$i] = $used[$j] = true;
            $pairs[] = [$dangling[$i]['name'], $dangling[$j]['name']];
        }

        return $pairs;
    }

    /** Undirected key for a pair of sectors. */
    public static function linkKey(string $a, string $b): string
    {
        return strcmp($a, $b) < 0 ? $a . '|' . $b : $b . '|' . $a;
    }

    /**
     * Cluster areas on the universe map from resources/index.css; Core spans the four P*C areas.
     *
     * @return array code => ['left','top','w','h']
     */
    private static function clusterBoxes(): array
    {
        $css = (string) @file_get_contents(ROOT . '/resources/index.css');
        preg_match_all('/a#(\w+)\s*\{\s*top:\s*(-?\d+)px;\s*left:\s*(-?\d+)px;\s*height:\s*(\d+)px;\s*width:\s*(\d+)px;/', $css, $m, PREG_SET_ORDER);

        $boxes = [];
        foreach ($m as [, $code, $top, $left, $height, $width]) {
            $boxes[$code] = ['left' => (int) $left, 'top' => (int) $top, 'w' => (int) $width, 'h' => (int) $height];
        }

        $parts = array_values(array_intersect_key($boxes, array_flip(self::CORE_PARTS)));
        if ($parts) {
            $left = min(array_column($parts, 'left'));
            $top = min(array_column($parts, 'top'));
            $right = max(array_map(static fn ($b) => $b['left'] + $b['w'], $parts));
            $bottom = max(array_map(static fn ($b) => $b['top'] + $b['h'], $parts));
            $boxes['core'] = ['left' => $left, 'top' => $top, 'w' => $right - $left, 'h' => $bottom - $top];
        }

        return $boxes;
    }

    /**
     * Grid of a cluster template: $grid[row][col] = null (empty) or ['name' => sector, 'dirs' => [connector codes]].
     */
    private static function parseTemplate(string $html): array
    {
        $grid = [];
        foreach (array_slice(preg_split('/<tr\b[^>]*>/i', $html) ?: [], 1) as $rowHtml) {
            // match the whole cell up to </td>: its style attribute contains a PHP close tag, whose ">"
            // would end a simple [^>]* attribute match early
            preg_match_all('/<td\b(.*?)<\/td>/is', $rowHtml, $cells, PREG_SET_ORDER);
            $line = [];
            foreach ($cells as [, $cell]) {
                if (!preg_match('/href="[^"]*\?>\s*([^"]+)"/', $cell, $name)) {
                    $line[] = null;
                    continue;
                }
                $dirs = [];
                if (preg_match('/wormhole\/([a-z-]+)\.png/i', $cell, $conn)) {
                    $dirs = array_values(array_filter(explode('-', strtolower($conn[1])), static fn ($d) => isset(self::DIRECTIONS[$d])));
                }
                $line[] = ['name' => html_entity_decode(trim($name[1]), ENT_QUOTES), 'dirs' => $dirs];
            }
            if ($line) {
                $grid[] = $line;
            }
        }

        return $grid;
    }
}
