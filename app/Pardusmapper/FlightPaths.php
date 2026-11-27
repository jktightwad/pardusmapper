<?php
declare(strict_types=1);

namespace Pardusmapper;

/**
 * Feral Serpent flight paths: turns its sightings into sector-to-sector moves.
 *
 * There is one Feral Serpent per universe at a time, so within a universe its records, oldest
 * first, are one serpent's path. The path is broken when the NPC ID changes (a new serpent
 * after a respawn), or when records without an ID are more than GAP apart (it may have died
 * and respawned unseen).
 *
 * A move between sectors linked by a wormhole is a flight. Any other move is a jump: either a
 * teleport (the serpent can teleport, e.g. from Nebul) or a route nobody saw it take.
 */
class FlightPaths
{
    public const SLUG = 'feral_serpent';

    /** Growth stages, youngest first. */
    public const STAGES = [
        'opponents/feral_serpent.png' => 0,
        'opponents/feral_serpent_1.png' => 1,
        'opponents/feral_serpent_2.png' => 2,
        'opponents/feral_serpent_3.png' => 3,
    ];

    /** Sectors confirmed by players as teleport points. */
    public const KNOWN_TELEPORTS = ['Nebul'];

    /** Records without an ID further apart than this are not joined into one path. */
    public const GAP = 172800;

    /**
     * @param string|null $universe one universe, or null for all three combined
     * @param int|null $nid one serpent (requires $universe)
     * @return array ['moves' => key => [...], 'sectors' => name => [...], 'jumps' => sector => [...], 'records' => int, 'paths' => int]
     */
    public static function build(?string $universe = null, ?int $nid = null): array
    {
        $records = NPC::mark_sightings(DB::npc_sightings(images: array_keys(self::STAGES), universe: $universe, nid: $nid));

        $adjacent = [];
        $layout = UniverseMap::layout();
        foreach ($layout['links'] as [$a, $b]) { $adjacent[UniverseMap::linkKey($a, $b)] = true; }
        foreach (DB::wormhole_links() as [$a, $b]) { $adjacent[UniverseMap::linkKey($a, $b)] = true; }

        $moves = [];
        $sectors = [];
        $jumps = [];
        $prev = [];   // universe => previous record
        $paths = [];  // distinct path starts
        $count = 0;

        foreach ($records as $r) {
            if (!$r->counted) {
                continue; // same tile again within 2 hours
            }
            $count++;
            $stage = self::STAGES[$r->image] ?? 0;

            $s = $sectors[$r->sector] ?? ['sightings' => 0, 'stages' => [], 'last' => null, 'active' => false, 'unis' => []];
            $s['sightings']++;
            $s['stages'][$stage] = ($s['stages'][$stage] ?? 0) + 1;
            $s['last'] = $r->spotted;
            $s['active'] = $s['active'] || (int) $r->active === 1;
            $s['unis'][$r->uni] = true;
            $sectors[$r->sector] = $s;

            $p = $prev[$r->uni] ?? null;
            $prev[$r->uni] = $r;

            $newSerpent = !is_null($p) && !is_null($p->nid) && !is_null($r->nid) && (int) $p->nid !== (int) $r->nid;
            $tooLong = !is_null($p) && (is_null($p->nid) || is_null($r->nid)) && ($r->ts - $p->ts) > self::GAP;
            if (is_null($p) || $newSerpent || $tooLong) {
                $paths[] = true;
                continue;
            }
            if ($p->sector === $r->sector) {
                continue;
            }

            $type = isset($adjacent[UniverseMap::linkKey($p->sector, $r->sector)]) ? 'flight' : 'jump';
            $key = $p->sector . '|' . $r->sector;
            $m = $moves[$key] ?? ['from' => $p->sector, 'to' => $r->sector, 'type' => $type, 'count' => 0, 'unis' => [], 'last' => null, 'stages' => []];
            $m['count']++;
            $m['unis'][$r->uni] = ($m['unis'][$r->uni] ?? 0) + 1;
            $m['last'] = $r->spotted;
            $m['stages'][$stage] = true;
            $moves[$key] = $m;

            if ($type === 'jump') {
                $j = $jumps[$p->sector] ?? ['count' => 0, 'to' => [], 'tiles' => []];
                $j['count']++;
                $j['to'][$r->sector] = ($j['to'][$r->sector] ?? 0) + 1;
                $tile = $p->x . ',' . $p->y; // last tile it was seen on before jumping
                $j['tiles'][$tile] = ($j['tiles'][$tile] ?? 0) + 1;
                $jumps[$p->sector] = $j;
            }
        }

        uasort($jumps, static fn ($a, $b) => $b['count'] <=> $a['count']);

        return ['moves' => $moves, 'sectors' => $sectors, 'jumps' => $jumps, 'records' => $count, 'paths' => count($paths)];
    }
}
