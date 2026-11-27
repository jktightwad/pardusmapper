<?php
declare(strict_types=1);

namespace Pardusmapper;

/**
 * Feral Serpent flight paths: turns its sightings into flybys and sector-to-sector moves.
 *
 * The serpent flies fast, so:
 * - A flyby is the serpent passing through a sector. Sightings in the same sector less than
 *   FLYBY_GAP apart are one flyby; further apart, it has come round again (another flyby).
 * - Two sightings in different sectors are one move only if they are at most LINK_WINDOW apart.
 *   With a longer gap it could have been anywhere in between, so nothing is drawn.
 * - A move between sectors linked by a wormhole is a flight. A move to any other sector in that
 *   short time is a jump: a teleport (e.g. from Nebul), or a sector nobody saw it cross.
 * - A different NPC ID means a different serpent: never joined.
 *
 * Each universe has its own serpent, but they appear to fly the same routes. When a universe's
 * flyby has no move leading to it, a flyby in another universe in a neighbouring sector within
 * LINK_WINDOW before it is joined to it as a flight (linked across universes). That fills gaps
 * between pilots' observations; jumps are never made across universes.
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

    /** Sightings in different sectors at most this many seconds apart are one move. */
    public const LINK_WINDOW = 2700;

    /**
     * @param string|null $universe one universe, or null for all three (linked across universes)
     * @param int|null $nid one serpent (requires $universe)
     * @return array ['moves' => key => [...], 'sectors' => name => [...], 'jumps' => sector => [...],
     *                'records' => int, 'flybys' => int, 'linked' => int (moves joined across universes)]
     */
    public static function build(?string $universe = null, ?int $nid = null): array
    {
        $records = DB::npc_sightings(images: self::IMAGES, universe: $universe, nid: $nid);

        $adjacent = [];
        $layout = UniverseMap::layout();
        foreach ($layout['links'] as [$a, $b]) { $adjacent[UniverseMap::linkKey($a, $b)] = true; }
        foreach (DB::wormhole_links() as [$a, $b]) { $adjacent[UniverseMap::linkKey($a, $b)] = true; }
        $isAdjacent = static fn (string $a, string $b): bool => isset($adjacent[UniverseMap::linkKey($a, $b)]);

        $moves = [];
        $sectors = [];
        $jumps = [];
        $last = [];    // universe => its latest sighting
        $recent = [];  // flyby starts within LINK_WINDOW, all universes (for linking across universes)
        $flybys = 0;
        $linked = 0;

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
            $recent = array_values(array_filter($recent, static fn ($q) => $r->ts - $q->ts <= self::LINK_WINDOW));
            $flybys++;
            $s = $sectors[$r->sector] ?? ['flybys' => 0, 'last' => null, 'active' => false, 'unis' => []];
            $s['flybys']++;
            $s['last'] = $r->spotted;
            $s['active'] = $s['active'] || (int) $r->active === 1;
            $s['unis'][$r->uni] = ($s['unis'][$r->uni] ?? 0) + 1;
            $sectors[$r->sector] = $s;

            if ($joined && $p->sector !== $r->sector) {
                $type = $isAdjacent($p->sector, $r->sector) ? 'flight' : 'jump';
                self::addMove($moves, $p, $r, $type, false);
                if ($type === 'jump') {
                    $j = $jumps[$p->sector] ?? ['count' => 0, 'to' => [], 'tiles' => []];
                    $j['count']++;
                    $j['to'][$r->sector] = ($j['to'][$r->sector] ?? 0) + 1;
                    $tile = $p->x . ',' . $p->y; // last tile it was seen on before jumping
                    $j['tiles'][$tile] = ($j['tiles'][$tile] ?? 0) + 1;
                    $jumps[$p->sector] = $j;
                }
            } elseif (is_null($universe)) {
                // Nothing in this universe leads here. Join it to the latest flyby in another universe
                // in a neighbouring sector shortly before, if there is one.
                $from = null;
                foreach ($recent as $q) {
                    if ($q->uni !== $r->uni && $q->sector !== $r->sector && $isAdjacent($q->sector, $r->sector)) {
                        $from = $q; // $recent is oldest first, so the last match is the latest
                    }
                }
                if ($from) {
                    self::addMove($moves, $from, $r, 'flight', true);
                    $linked++;
                }
            }
            $recent[] = $r;
        }

        uasort($jumps, static fn ($a, $b) => $b['count'] <=> $a['count']);

        return ['moves' => $moves, 'sectors' => $sectors, 'jumps' => $jumps,
                'records' => count($records), 'flybys' => $flybys, 'linked' => $linked];
    }

    /** Count a move from $p's sector to $r's. $across: joined from another universe's flyby. */
    private static function addMove(array &$moves, object $p, object $r, string $type, bool $across): void
    {
        $key = $p->sector . '|' . $r->sector;
        $m = $moves[$key] ?? ['from' => $p->sector, 'to' => $r->sector, 'type' => $type, 'count' => 0, 'across' => 0, 'unis' => [], 'last' => null];
        $m['count']++;
        if ($across) {
            $m['across']++;
            $label = $p->uni . ' → ' . $r->uni;
        } else {
            $label = $r->uni;
        }
        $m['unis'][$label] = ($m['unis'][$label] ?? 0) + 1;
        $m['last'] = $r->spotted;
        $moves[$key] = $m;
    }
}
