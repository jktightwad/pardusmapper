<?php

declare(strict_types=1);
require_once('app/settings.php');

/**
 * Rare NPC Stats: sighting history for the NPCs in NPC::HISTORY, combined across universes.
 *
 * Views (all active contributors only, same 48h rule as NPC List):
 *   /{Uni}/npcstats                          overview of every tracked NPC
 *   /{Uni}/npcstats?npc=feral_serpent        one NPC: its unique IDs + where it has been seen (by name)
 *   /{Uni}/npcstats?npc=feral_serpent&flight=1[&u=Orion]  Feral Serpent flight paths on the universe map
 *   /{Uni}/npcstats?npc=feral_serpent&u=Orion&nid=123     one serpent's flight path
 */

use Pardusmapper\Core\ApiResponse;
use Pardusmapper\DB;
use Pardusmapper\FlightPaths;
use Pardusmapper\NPC;
use Pardusmapper\Request;
use Pardusmapper\Session;
use Pardusmapper\UniverseMap;

$uni = Request::uni();
http_response(is_null($uni), ApiResponse::OK, sprintf('uni query parameter is required or invalid: %s', $uni ?? 'null'));

session_name($uni);
session_start();

$security = Session::pint(key: 'security', default: 0);

if (!Session::contributor(universe: $uni)) {
    require_once(templates('notlogged'));
    exit;
}

$groups = NPC::history_groups();

$slug = vstring($_REQUEST['npc'] ?? null);
if (!is_null($slug) && !isset($groups[$slug])) {
    $slug = null;
}
$trailUni = Request::uni(key: 'u');
$nid = Request::pint(key: 'nid');

$view = 'overview';
if (!is_null($slug)) {
    // only the Feral Serpent moves between sectors, so only it has a flight-path view
    $wantsFlight = Request::pbool(key: 'flight') || (!is_null($trailUni) && !is_null($nid));
    $view = ($slug === FlightPaths::SLUG && $wantsFlight) ? 'flight' : 'npc';
}

// keep every value the template needs in one place
$stats = ['view' => $view, 'slug' => $slug, 'groups' => $groups, 'u' => $trailUni, 'nid' => $nid];

// All dates are when a record was added (`spotted`), never `updated`: that is overwritten when
// the NPC is reported gone. Rows come back oldest first, so the last row seen is the latest.
// Every tile an NPC is recorded on counts (movement); only repeats of the same NPC on the same
// tile less than 2 hours apart are merged, see NPC::mark_sightings() (sets ->counted).

if ($view === 'overview') {
    $all = [];
    foreach ($groups as $g) { $all = array_merge($all, $g['images']); }
    $imageToSlug = [];
    foreach ($groups as $s => $g) { foreach ($g['images'] as $img) { $imageToSlug[$img] = $s; } }

    $summary = [];
    foreach ($groups as $s => $g) {
        $summary[$s] = ['name' => $g['name'], 'sightings' => 0, 'records' => 0, 'ids' => [], 'noid' => 0, 'active' => 0, 'first' => null, 'last' => null, 'sectors' => []];
    }
    foreach (NPC::mark_sightings(DB::npc_sightings(images: $all)) as $r) {
        $s = $imageToSlug[$r->image] ?? null;
        if (is_null($s)) { continue; }
        $row = &$summary[$s];
        $row['records']++;
        if ($r->counted) {
            $row['sightings']++;
            if (is_null($r->nid)) { $row['noid']++; }
        }
        if (!is_null($r->nid)) { $row['ids'][$r->uni][(int) $r->nid] = true; }
        if ((int) $r->active === 1) { $row['active']++; }
        $row['first'] ??= $r->spotted;
        $row['last'] = $r->spotted;
        $row['sectors'][$r->sector] = true;
        unset($row);
    }
    $stats['summary'] = $summary;
}

if ($view === 'npc') {
    $ids = [];      // "Uni|nid" => [...]
    $noid = [];     // Uni => separate sightings without an ID
    $sectors = [];  // sector => [...]

    foreach (NPC::mark_sightings(DB::npc_sightings(images: $groups[$slug]['images'])) as $r) {
        if (!is_null($r->nid)) {
            $key = $r->uni . '|' . $r->nid;
            $id = $ids[$key] ?? ['uni' => $r->uni, 'nid' => (int) $r->nid, 'sightings' => 0, 'sectors' => [], 'first' => $r->spotted, 'last' => null, 'active' => 0, 'where' => null];
            if ($r->counted) { $id['sightings']++; }
            $id['sectors'][$r->sector] = true;
            $id['last'] = $r->spotted;
            $id['active'] = (int) $r->active;
            $id['where'] = sprintf('%s [%d,%d]', $r->sector, $r->x, $r->y);
            $ids[$key] = $id;
        } elseif ($r->counted) {
            $noid[$r->uni] = ($noid[$r->uni] ?? 0) + 1;
        }

        if (!$r->counted) {
            continue;
        }
        $sec = $sectors[$r->sector] ?? ['total' => 0, 'ids' => [], 'unis' => [], 'last' => null, 'tiles' => [], 'live' => []];
        $sec['total']++;
        if (!is_null($r->nid)) { $sec['ids'][$r->uni . '|' . $r->nid] = true; }
        $sec['unis'][$r->uni] = ($sec['unis'][$r->uni] ?? 0) + 1;
        $sec['last'] = $r->spotted;
        $tile = $r->x . ',' . $r->y;
        $sec['tiles'][$tile] = ($sec['tiles'][$tile] ?? 0) + 1;
        if ((int) $r->active === 1) { $sec['live'][$tile] = true; }
        $sectors[$r->sector] = $sec;
    }

    // most recently active IDs first; busiest sectors first
    uasort($ids, static fn ($a, $b) => strcmp((string) $b['last'], (string) $a['last']));
    uasort($sectors, static fn ($a, $b) => $b['total'] <=> $a['total'] ?: strcmp((string) $b['last'], (string) $a['last']));

    $stats['ids'] = $ids;
    $stats['noid'] = $noid;
    $stats['sectors'] = $sectors;
    $stats['dims'] = DB::sector_dims(array_keys($sectors));
}

if ($view === 'flight') {
    // Feral Serpent flight paths on the universe map: all serpents (optionally one universe),
    // or one serpent ID (u + nid). See FlightPaths for how sightings become moves.
    $stats['flight'] = FlightPaths::build(universe: $trailUni, nid: is_null($trailUni) ? null : $nid);
    $stats['layout'] = UniverseMap::layout();
}

$title = 'Rare NPC Stats';
require_once(templates('npcstats'));
