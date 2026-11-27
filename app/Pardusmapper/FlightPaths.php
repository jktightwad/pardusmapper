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
 *   from the last and at most LINK_WINDOW after it. A different NPC ID (another serpent) ends it.
 * - Seen in another sector LINK_WINDOW to EXTRAPOLATE_WINDOW later: the likely route in between
 *   is filled in (guessed moves, never used as evidence of a turn). Later than that is a
 *   different flyby and route.
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

    /**
     * Flybys in different sectors at most this many seconds apart are part of one chain. It crosses
     * a sector in 2-3 minutes (7-10 s a tile), so after a longer gap it could have taken another
     * route entirely.
     */
    public const LINK_WINDOW = 480;

    /**
     * Seen in another sector up to this many seconds later (but more than LINK_WINDOW): the likely
     * route in between is filled in (a guess). Longer: a different flyby and route.
     */
    public const EXTRAPOLATE_WINDOW = 1200;

    /** The fastest it is assumed to cross a sector, in seconds (its speed varies). */
    public const MIN_SECTOR_TIME = 90;

    /** A jump that left by a wormhole is shown with the way on it could have flown, up to this many sectors. */
    public const MAX_UNSEEN = 3;

    /** Last seen on or next to a wormhole (this many tiles or fewer) before a jump: it left through the wormhole. */
    public const NEAR_WORMHOLE = 1;

    /** Stop looking for more routes after this many (the graph is small; this is a safety net). */
    private const MAX_ROUTES = 60;

    /**
     * @param string|null $universe one universe, or null for all three
     * @param int|null $nid one serpent (requires $universe)
     * @return array ['moves' => "from|to" => [...], 'sectors' => name => [...], 'jumps' => sector => [...],
     *                'turns' => sector => from => to => count, 'tracks' => see tracks(),
     *                'chains' => int, 'records' => int, 'flybys' => int]
     */
    public static function build(?string $universe = null, ?int $nid = null): array
    {
        $records = DB::npc_sightings(images: self::IMAGES, universe: $universe, nid: $nid);

        $adjacent = [];
        $neighbours = []; // sector => [sector => true], for the unseen way round a jump
        $layout = UniverseMap::layout();
        foreach (array_merge($layout['links'], DB::wormhole_links()) as [$a, $b]) {
            $adjacent[UniverseMap::linkKey($a, $b)] = true;
            $neighbours[$a][$b] = true;
            $neighbours[$b][$a] = true;
        }

        $moves = [];
        $sectors = [];
        $jumpEvents = [];
        $gapEvents = [];
        $turns = [];
        $last = [];   // universe => its latest sighting
        $chain = [];  // universe => sectors of its current chain
        $chains = 0;  // chains of 2 or more sectors
        $flybys = 0;
        $trail = [];  // every flyby: ['sector','from','to','tiles' => [[x, y], ...]]
        $open = [];   // universe => index in $trail of its current flyby

        foreach ($records as $r) {
            $r->ts = (int) strtotime($r->spotted . ' UTC');
            $p = $last[$r->uni] ?? null;
            $last[$r->uni] = $r;

            $gap = is_null($p) ? PHP_INT_MAX : $r->ts - $p->ts;
            $otherSerpent = !is_null($p) && !is_null($p->nid) && !is_null($r->nid) && (int) $p->nid !== (int) $r->nid;
            $joined = !is_null($p) && !$otherSerpent && $gap <= self::LINK_WINDOW;

            // still the same flyby (same sector within FLYBY_GAP): update the sector and move on
            if (!is_null($p) && !$otherSerpent && $p->sector === $r->sector && $gap <= self::FLYBY_GAP) {
                $sectors[$r->sector]['last'] = $r->spotted;
                $sectors[$r->sector]['active'] = $sectors[$r->sector]['active'] || (int) $r->active === 1;
                $t = &$trail[$open[$r->uni]]['tiles'];
                if (end($t) !== [(int) $r->x, (int) $r->y]) {
                    $t[] = [(int) $r->x, (int) $r->y]; // it moved on within the sector
                }
                unset($t);
                continue;
            }

            // seen in another sector 8-20 minutes later: a gap to fill with a likely route afterwards
            if (!is_null($p) && !$otherSerpent && !$joined && $p->sector !== $r->sector && $gap <= self::EXTRAPOLATE_WINDOW) {
                $gapEvents[] = ['from' => $p->sector, 'to' => $r->sector, 'gap' => $gap, 'flyby' => $open[$r->uni], 'r' => $r];
            }

            // where it went next, for the flyby it just left
            $moved = $joined && $p->sector !== $r->sector;
            $leftTiles = [];
            if ($moved) {
                $trail[$open[$r->uni]]['to'] = $r->sector;
                $leftTiles = $trail[$open[$r->uni]]['tiles']; // the tiles it crossed in the sector it left
            }
            $trail[] = ['sector' => $r->sector, 'from' => $moved ? $p->sector : null, 'to' => null, 'tiles' => [[(int) $r->x, (int) $r->y]]];
            $open[$r->uni] = array_key_last($trail);

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
                    // classified once all jumps are known (by the tile it was last seen on before leaving)
                    $jumpEvents[] = ['from' => $p->sector, 'to' => $r->sector, 'x' => (int) $p->x, 'y' => (int) $p->y,
                                     'tiles' => array_slice($leftTiles, -2)];
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

        // Gaps of 8-20 minutes: fill in the likely route between the two sightings. It flies one big
        // looping route with smaller routes inside, so first the shortest way along moves already
        // seen, else the shortest way by wormholes, and only if it could fly it in the time.
        $seenOut = [];
        foreach ($moves as $m) {
            if ($m['type'] === 'flight') { $seenOut[$m['from']][$m['to']] = true; }
        }
        $guesses = ['seen' => 0, 'map' => 0, 'none' => 0];
        foreach ($gapEvents as $g) {
            $maxBetween = max(0, intdiv($g['gap'], self::MIN_SECTOR_TIME) - 1); // sectors in between it could have crossed
            $how = 'seen';
            $between = self::shortestWay($seenOut, $g['from'], $g['to'], $maxBetween);
            if (is_null($between)) {
                $how = 'map';
                $between = self::shortestWay($neighbours, $g['from'], $g['to'], $maxBetween);
            }
            if (is_null($between)) {
                $guesses['none']++;
                continue;
            }
            $guesses[$how]++;
            $path = [$g['from'], ...$between, $g['to']];
            for ($i = 1; $i < count($path); $i++) {
                self::addMove($moves, $path[$i - 1], $path[$i], 'flight', $g['r'], true);
            }
            $trail[$g['flyby']]['to'] ??= $path[1]; // the way it most likely left
        }

        // Jumps, by where it was last seen before it went:
        // - on or next to a wormhole: it left through the wormhole and flew on unseen;
        // - stuck (the next tile in the direction it was heading is impassable) away from any
        //   wormhole: it teleported (e.g. Nebul, running into the wall going west to east);
        // - anywhere else: it went out of view, probably flying on to a wormhole unseen.
        // Nothing is assumed about how far a teleport goes.
        $whTiles = DB::wormhole_tiles(array_column($jumpEvents, 'from'));
        $jumps = [];
        foreach ($moves as &$m) {
            $m['exits'] = ['wormhole' => 0, 'teleport' => 0, 'unseen' => 0, 'unknown' => 0];
        }
        unset($m);
        foreach ($jumpEvents as $e) {
            $dist = self::wormholeDistance($whTiles[$e['from']] ?? [], $e['x'], $e['y']);
            if (is_null($dist)) {
                $how = 'unknown'; // no wormhole mapped in that sector
            } elseif ($dist <= self::NEAR_WORMHOLE) {
                $how = 'wormhole';
            } else {
                $how = self::stuck($e['from'], $e['tiles'] ?: [[$e['x'], $e['y']]]) ? 'teleport' : 'unseen';
            }
            $moves[$e['from'] . '|' . $e['to']]['exits'][$how]++;

            $j = $jumps[$e['from']] ?? ['count' => 0, 'wormhole' => 0, 'teleport' => 0, 'unseen' => 0, 'unknown' => 0, 'to' => [], 'tiles' => []];
            $j['count']++;
            $j[$how]++;
            $j['to'][$e['to']] = ($j['to'][$e['to']] ?? 0) + 1;
            $tile = $e['x'] . ',' . $e['y'];
            $j['tiles'][$tile] = ['n' => ($j['tiles'][$tile]['n'] ?? 0) + 1, 'how' => $how, 'dist' => $dist];
            $jumps[$e['from']] = $j;
        }
        uasort($jumps, static fn ($a, $b) => $b['teleport'] <=> $a['teleport'] ?: $b['count'] <=> $a['count']);

        // For a jump where it flew on unseen, the shortest ways on it could have taken (all of them
        // when there are several of the same length)
        foreach ($moves as &$m) {
            $m['via'] = ($m['type'] === 'jump' && ($m['exits']['wormhole'] + $m['exits']['unseen']) > 0)
                ? self::shortestWays($neighbours, $m['from'], $m['to'], self::MAX_UNSEEN) : null;
        }
        unset($m);

        return ['moves' => $moves, 'sectors' => $sectors, 'jumps' => $jumps, 'turns' => $turns,
                'tracks' => self::tracks($trail), 'guesses' => $guesses,
                'chains' => $chains, 'records' => count($records), 'flybys' => $flybys];
    }

    /**
     * The tiles it crossed in each sector, grouped by direction of travel.
     *
     * @param array $trail flybys from build()
     * @return array sector => "from|to" => ['from','to' (null if not seen), 'flybys' => int,
     *               'paths' => [[[x, y], ...], ...] (flybys seen on 2+ tiles, in order), 'tiles' => "x,y" => count]
     *               Directions with the most flybys first.
     */
    /**
     * Was it stuck where it was last seen? $tiles: its last one or two tiles in the sector, oldest
     * first. With two, it is stuck if the next tile in the direction it was heading is impassable
     * (or off the map). With only one (no direction), if at most one tile around it is passable.
     */
    private static function stuck(string $sector, array $tiles): bool
    {
        [$x, $y] = end($tiles);
        $terrain = [];
        foreach (DB::map_tiles('Orion', $sector, $x - 1, $x + 1, $y - 1, $y + 1) as $t) {
            $terrain[(int) $t->x . ',' . (int) $t->y] = TileMap::category($t->bg, $t->fg);
        }
        // Blocked: off the edge of the sector (tiles count from 0,0), or mapped as impassable.
        // A tile nobody has mapped is not assumed to be a wall.
        $dim = DB::sector_dims([$sector])[$sector] ?? null;
        $blocked = static fn (int $tx, int $ty): bool => $tx < 0 || $ty < 0
            || (!is_null($dim) && ($tx >= (int) $dim->cols || $ty >= (int) $dim->rows))
            || ($terrain[$tx . ',' . $ty] ?? null) === TileMap::IMPASSABLE;

        if (count($tiles) === 2) {
            [$px, $py] = $tiles[0];
            $dx = $x <=> $px;
            $dy = $y <=> $py;
            if ($dx !== 0 || $dy !== 0) {
                return $blocked($x + $dx, $y + $dy);
            }
        }
        $open = 0;
        for ($dx = -1; $dx <= 1; $dx++) {
            for ($dy = -1; $dy <= 1; $dy++) {
                if (($dx !== 0 || $dy !== 0) && !$blocked($x + $dx, $y + $dy)) {
                    $open++;
                }
            }
        }

        return $open <= 1;
    }

    /**
     * Every equally short wormhole route from $from to $to (e.g. Greandin -> Cassand through Regulus
     * or through Aeg), as lists of the sectors in between; at most 4, and only if they have at most
     * $max sectors in between. Null if there is none that short.
     */
    private static function shortestWays(array $neighbours, string $from, string $to, int $max): ?array
    {
        $dist = [$from => 0];
        $parents = [$from => []];
        $queue = [$from];
        while ($queue) {
            $s = array_shift($queue);
            if ($dist[$s] > $max) {
                break;
            }
            foreach (array_keys($neighbours[$s] ?? []) as $n) {
                if (!isset($dist[$n])) {
                    $dist[$n] = $dist[$s] + 1;
                    $parents[$n] = [$s];
                    $queue[] = $n;
                } elseif ($dist[$n] === $dist[$s] + 1) {
                    $parents[$n][] = $s; // another way of the same length
                }
            }
        }
        if (!isset($dist[$to]) || $dist[$to] - 1 > $max) {
            return null;
        }

        $ways = [];
        $walk = static function (string $s, array $tail) use (&$walk, &$ways, $parents, $from): void {
            if (count($ways) >= 4) {
                return;
            }
            if ($s === $from) {
                $ways[] = $tail;
                return;
            }
            foreach ($parents[$s] as $p) {
                $walk($p, $p === $from ? $tail : [$p, ...$tail]);
            }
        };
        $walk($to, []);

        return $ways;
    }

    /** Tiles from ($x, $y) to the nearest wormhole tile (diagonal steps count as one); null if none is known. */
    private static function wormholeDistance(array $whTiles, int $x, int $y): ?int
    {
        $best = null;
        foreach ($whTiles as [$wx, $wy]) {
            $d = max(abs($wx - $x), abs($wy - $y));
            $best = is_null($best) ? $d : min($best, $d);
        }

        return $best;
    }

    /**
     * The sectors in between on the shortest wormhole route from $from to $to, if it has at most
     * $max of them; null if there is none that short.
     */
    private static function shortestWay(array $neighbours, string $from, string $to, int $max): ?array
    {
        $prev = [$from => null];
        $queue = [$from];
        while ($queue) {
            $s = array_shift($queue);
            if ($s === $to) {
                $way = [];
                for ($c = $prev[$to]; $c !== $from && !is_null($c); $c = $prev[$c]) {
                    array_unshift($way, $c);
                }
                return count($way) <= $max ? $way : null;
            }
            foreach (array_keys($neighbours[$s] ?? []) as $n) {
                if (!array_key_exists($n, $prev)) {
                    $prev[$n] = $s;
                    $queue[] = $n;
                }
            }
        }

        return null;
    }

    private static function tracks(array $trail): array
    {
        $tracks = [];
        foreach ($trail as $f) {
            $key = ($f['from'] ?? '') . '|' . ($f['to'] ?? '');
            $d = $tracks[$f['sector']][$key] ?? ['from' => $f['from'], 'to' => $f['to'], 'flybys' => 0, 'paths' => [], 'tiles' => []];
            $d['flybys']++;
            if (count($f['tiles']) > 1) {
                $d['paths'][] = $f['tiles'];
            }
            foreach ($f['tiles'] as [$x, $y]) {
                $d['tiles'][$x . ',' . $y] = ($d['tiles'][$x . ',' . $y] ?? 0) + 1;
            }
            $tracks[$f['sector']][$key] = $d;
        }
        foreach ($tracks as &$dirs) {
            uasort($dirs, static fn ($a, $b) => $b['flybys'] <=> $a['flybys']);
        }

        return $tracks;
    }

    /** Count a move. $guessed: filled in between two sightings 8-20 minutes apart, not seen. */
    private static function addMove(array &$moves, string $from, string $to, string $type, object $r, bool $guessed = false): void
    {
        $key = $from . '|' . $to;
        $m = $moves[$key] ?? ['from' => $from, 'to' => $to, 'type' => $type, 'count' => 0, 'guessed' => 0, 'unis' => [], 'last' => null];
        if ($guessed) {
            $m['guessed']++;
        } else {
            $m['count']++;
            $m['unis'][$r->uni] = ($m['unis'][$r->uni] ?? 0) + 1;
        }
        $m['last'] = max((string) $m['last'], (string) $r->spotted);
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
        // Teleports are part of a route. A jump where it left by a wormhole and went on unseen is
        // left out: nobody knows which way it flew (it may have turned back).
        $pieces = array_filter($flight['moves'], static fn ($m) => $m['type'] === 'flight'
            || ($m['exits']['teleport'] ?? 0) > 0 || in_array($m['from'], self::KNOWN_TELEPORTS, true));
        $out = [];
        $in = [];
        foreach ($pieces as $m) {
            $out[$m['from']][$m['to']] = true;
            $in[$m['to']][$m['from']] = true;
        }
        $turns = $flight['turns'];

        $found = [];
        foreach ($pieces as $m) {
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
            $guessedOnly = 0;
            for ($i = 1; $i < count($route); $i++) {
                $key = $route[$i - 1] . '|' . $route[$i];
                $m = $flight['moves'][$key];
                $keys[] = $key;
                $seen += $m['count'];
                foreach ($m['unis'] as $u => $n) { $unis[$u] = ($unis[$u] ?? 0) + $n; }
                $jumps += $m['type'] === 'jump' ? 1 : 0;
                $guessedOnly += $m['count'] === 0 ? 1 : 0;
            }
            $joined = [];
            for ($i = 1; $i < count($route) - 1; $i++) {
                if (empty($turns[$route[$i]][$route[$i - 1]][$route[$i + 1]])) {
                    $joined[] = $route[$i];
                }
            }
            $routes[] = ['sectors' => $route, 'moves' => $keys, 'seen' => $seen, 'unis' => $unis, 'jumps' => $jumps,
                         'joined' => $joined, 'guessed' => $guessedOnly, 'heading' => self::heading($pos[$route[0]] ?? null, $pos[end($route)] ?? null)];
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
