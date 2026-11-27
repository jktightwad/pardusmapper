<?php
declare(strict_types=1);

namespace Pardusmapper;

/**
 * Feral Serpent flight paths: turns its sightings into flybys, observed chains and inferred routes.
 *
 * The serpent flies fast, so:
 * - A flyby is the serpent passing through a sector. Sightings in the same sector less than
 *   FLYBY_GAP apart are one flyby; further apart, it has come round again (another flyby).
 * - A chain is what pilots saw of one flight: flybys in one universe, each in a different sector
 *   from the last and at most LINK_WINDOW after it. With a longer gap it could have been anywhere
 *   in between, so the chain ends. A different NPC ID (another serpent) also ends it.
 * - A move between sectors linked by a wormhole is a flight; to any other sector in that short
 *   time, a jump: a teleport (e.g. from Nebul), or a sector nobody saw it cross.
 *
 * The universes are copies, and the serpent flies the same routes in each, at different times.
 * Pilots rarely see more than 2-3 sectors of a flight, so routes are pieced together from the
 * chains of all universes and times (routes()).
 */
class FlightPaths
{
    public const SLUG = 'feral_serpent';

    /** All images of the serpent (its picture changes as it ages; the age doesn't matter here). */
    public const IMAGES = [
        'opponents/feral_serpent.png',
        'opponents/feral_serpent_1.png',
        'opponents/feral_serpent_2.png',
        'opponents/feral_serpent_3.png',
    ];

    /** Sectors confirmed by players as teleport points. */
    public const KNOWN_TELEPORTS = ['Nebul'];

    /** Same sector seen again more than this many seconds later: another flyby. */
    public const FLYBY_GAP = 1200;

    /** Flybys in different sectors at most this many seconds apart are part of one chain. */
    public const LINK_WINDOW = 2700;

    /** Stop looking for more routes after this many (the graph is small; this is a safety net). */
    private const MAX_ROUTES = 60;

    /**
     * @param string|null $universe one universe, or null for all three
     * @param int|null $nid one serpent (requires $universe)
     * @return array ['moves' => "from|to" => [...], 'sectors' => name => [...], 'jumps' => sector => [...],
     *                'turns' => sector => from => to => count, 'chains' => int, 'records' => int, 'flybys' => int]
     */
    public static function build(?string $universe = null, ?int $nid = null): array
    {
        $records = DB::npc_sightings(images: self::IMAGES, universe: $universe, nid: $nid);

        $adjacent = [];
        $layout = UniverseMap::layout();
        foreach ($layout['links'] as [$a, $b]) { $adjacent[UniverseMap::linkKey($a, $b)] = true; }
        foreach (DB::wormhole_links() as [$a, $b]) { $adjacent[UniverseMap::linkKey($a, $b)] = true; }

        $moves = [];
        $sectors = [];
        $jumps = [];
        $turns = [];
        $last = [];   // universe => its latest sighting
        $chain = [];  // universe => sectors of its current chain
        $chains = 0;  // chains of 2 or more sectors
        $flybys = 0;

        foreach ($records as $r) {
            $r->ts = (int) strtotime($r->spotted . ' UTC');
            $p = $last[$r->uni] ?? null;
            $last[$r->uni] = $r;

            $gap = is_null($p) ? PHP_INT_MAX : $r->ts - $p->ts;
            $otherSerpent = !is_null($p) && !is_null($p->nid) && !is_null($r->nid) && (int) $p->nid !== (int) $r->nid;
            $joined = !is_null($p) && !$otherSerpent && $gap <= self::LINK_WINDOW;

            // still the same flyby: update the sector and move on
            if ($joined && $p->sector === $r->sector && $gap <= self::FLYBY_GAP) {
                $sectors[$r->sector]['last'] = $r->spotted;
                $sectors[$r->sector]['active'] = $sectors[$r->sector]['active'] || (int) $r->active === 1;
                continue;
            }

            // a new flyby of this sector
            $flybys++;
            $s = $sectors[$r->sector] ?? ['flybys' => 0, 'last' => null, 'active' => false, 'unis' => []];
            $s['flybys']++;
            $s['last'] = $r->spotted;
            $s['active'] = $s['active'] || (int) $r->active === 1;
            $s['unis'][$r->uni] = ($s['unis'][$r->uni] ?? 0) + 1;
            $sectors[$r->sector] = $s;

            if ($joined && $p->sector !== $r->sector) {
                // the chain goes on
                $type = isset($adjacent[UniverseMap::linkKey($p->sector, $r->sector)]) ? 'flight' : 'jump';
                self::addMove($moves, $p->sector, $r->sector, $type, $r);
                if ($type === 'jump') {
                    $j = $jumps[$p->sector] ?? ['count' => 0, 'to' => [], 'tiles' => []];
                    $j['count']++;
                    $j['to'][$r->sector] = ($j['to'][$r->sector] ?? 0) + 1;
                    $tile = $p->x . ',' . $p->y; // last tile it was seen on before jumping
                    $j['tiles'][$tile] = ($j['tiles'][$tile] ?? 0) + 1;
                    $jumps[$p->sector] = $j;
                }
                $c = &$chain[$r->uni];
                if (count($c) === 1) { $chains++; }
                $c[] = $r->sector;
                $n = count($c);
                if ($n >= 3) {
                    // seen flying through $c[$n-2]: from $c[$n-3], on to $c[$n-1]
                    $turns[$c[$n - 2]][$c[$n - 3]][$c[$n - 1]] = ($turns[$c[$n - 2]][$c[$n - 3]][$c[$n - 1]] ?? 0) + 1;
                }
                unset($c);
            } else {
                // a new chain starts here
                $chain[$r->uni] = [$r->sector];
            }
        }

        uasort($jumps, static fn ($a, $b) => $b['count'] <=> $a['count']);

        return ['moves' => $moves, 'sectors' => $sectors, 'jumps' => $jumps, 'turns' => $turns,
                'chains' => $chains, 'records' => count($records), 'flybys' => $flybys];
    }

    private static function addMove(array &$moves, string $from, string $to, string $type, object $r): void
    {
        $key = $from . '|' . $to;
        $m = $moves[$key] ?? ['from' => $from, 'to' => $to, 'type' => $type, 'count' => 0, 'unis' => [], 'last' => null];
        $m['count']++;
        $m['unis'][$r->uni] = ($m['unis'][$r->uni] ?? 0) + 1;
        $m['last'] = $r->spotted;
        $moves[$key] = $m;
    }

    /**
     * Piece the observed chains together into routes.
     *
     * Every move seen (any universe, any time) is a piece. A route is extended through a sector
     * when a chain was seen flying through it from the same direction (it shows which way it goes
     * on), or, failing that, when the join is the only possibility both ways (one way on, one way
     * in). Where the data allows more than one and nothing to choose between them, the route stops
     * there rather than guess. It never turns straight back or visits a sector twice.
     *
     * @param array $flight from build()
     * @param array $pos UniverseMap::layout()['sectors'], for the heading
     * @return array list of ['sectors' => [...], 'moves' => ["from|to", ...], 'seen' => int (moves seen along it),
     *               'unis' => uni => n, 'jumps' => int, 'joined' => [sector, ...] (joined without a chain through it),
     *               'heading' => 'NE'|...|null], longest first
     */
    public static function routes(array $flight, array $pos = []): array
    {
        $out = [];
        $in = [];
        foreach ($flight['moves'] as $m) {
            $out[$m['from']][$m['to']] = true;
            $in[$m['to']][$m['from']] = true;
        }
        $turns = $flight['turns'];

        $found = [];
        foreach ($flight['moves'] as $m) {
            foreach (self::forward([$m['from'], $m['to']], $out, $in, $turns) as $path) {
                foreach (self::backward($path, $out, $in, $turns) as $full) {
                    $found[implode('|', $full)] = $full;
                }
            }
            if (count($found) >= self::MAX_ROUTES) {
                break;
            }
        }

        // longest first; drop any route that is part of a longer one
        $all = array_values($found);
        usort($all, static fn ($a, $b) => count($b) <=> count($a));
        $kept = [];
        foreach ($all as $route) {
            $needle = '|' . implode('|', $route) . '|';
            foreach ($kept as $k) {
                if (str_contains('|' . implode('|', $k) . '|', $needle)) {
                    continue 2;
                }
            }
            $kept[] = $route;
        }

        $routes = [];
        foreach ($kept as $route) {
            $keys = [];
            $seen = 0;
            $unis = [];
            $jumps = 0;
            for ($i = 1; $i < count($route); $i++) {
                $key = $route[$i - 1] . '|' . $route[$i];
                $m = $flight['moves'][$key];
                $keys[] = $key;
                $seen += $m['count'];
                foreach ($m['unis'] as $u => $n) { $unis[$u] = ($unis[$u] ?? 0) + $n; }
                $jumps += $m['type'] === 'jump' ? 1 : 0;
            }
            $joined = [];
            for ($i = 1; $i < count($route) - 1; $i++) {
                if (empty($turns[$route[$i]][$route[$i - 1]][$route[$i + 1]])) {
                    $joined[] = $route[$i];
                }
            }
            $routes[] = ['sectors' => $route, 'moves' => $keys, 'seen' => $seen, 'unis' => $unis, 'jumps' => $jumps,
                         'joined' => $joined, 'heading' => self::heading($pos[$route[0]] ?? null, $pos[end($route)] ?? null)];
        }
        usort($routes, static fn ($a, $b) => count($b['sectors']) <=> count($a['sectors']) ?: $b['seen'] <=> $a['seen']);

        return $routes;
    }

    /**
     * Without a chain seen through $cur, joining $prev -> $cur -> $next is only safe when it is the
     * only possibility both ways: the only way on from $cur (other than back to $prev), and the only
     * way into $cur (other than from $next).
     */
    private static function onlyWay(string $prev, string $cur, string $next, array $out, array $in): bool
    {
        $on = array_diff(array_keys($out[$cur] ?? []), [$prev]);
        $into = array_diff(array_keys($in[$cur] ?? []), [$next]);

        return array_values($on) === [$next] && array_values($into) === [$prev];
    }

    /** All ways to extend $path forwards (see routes()). */
    private static function forward(array $path, array $out, array $in, array $turns): array
    {
        $cur = $path[count($path) - 1];
        $prev = $path[count($path) - 2];
        $ways = array_values(array_filter(array_keys($out[$cur] ?? []), static fn ($s) => !in_array($s, $path, true)));
        $next = array_values(array_intersect(array_keys($turns[$cur][$prev] ?? []), $ways));
        if (!$next && count($ways) === 1 && self::onlyWay($prev, $cur, $ways[0], $out, $in)) {
            $next = $ways;
        }

        if (!$next || count($path) > 40) {
            return [$path];
        }
        $paths = [];
        foreach ($next as $n) {
            array_push($paths, ...self::forward([...$path, $n], $out, $in, $turns));
        }

        return $paths;
    }

    /** All ways to extend $path backwards (see routes()). */
    private static function backward(array $path, array $out, array $in, array $turns): array
    {
        $cur = $path[0];
        $next = $path[1];
        $ways = array_values(array_filter(array_keys($in[$cur] ?? []), static fn ($s) => !in_array($s, $path, true)));
        $prev = array_values(array_filter($ways, static fn ($p) => !empty($turns[$cur][$p][$next])));
        if (!$prev && count($ways) === 1 && self::onlyWay($ways[0], $cur, $next, $out, $in)) {
            $prev = $ways;
        }

        if (!$prev || count($path) > 40) {
            return [$path];
        }
        $paths = [];
        foreach ($prev as $p) {
            array_push($paths, ...self::backward([$p, ...$path], $out, $in, $turns));
        }

        return $paths;
    }

    /** Overall compass heading from one sector to another (map y grows downwards = south). */
    public static function heading(?array $from, ?array $to): ?string
    {
        if (is_null($from) || is_null($to)) {
            return null;
        }
        $dx = $to['x'] - $from['x'];
        $dy = $from['y'] - $to['y'];
        if (abs($dx) < 1 && abs($dy) < 1) {
            return null;
        }
        $deg = fmod(rad2deg(atan2($dx, $dy)) + 360, 360); // 0 = north, clockwise
        $names = ['N', 'NE', 'E', 'SE', 'S', 'SW', 'W', 'NW'];

        return $names[(int) round($deg / 45) % 8];
    }
}
