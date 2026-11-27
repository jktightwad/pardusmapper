<?php

declare(strict_types=1);
require_once('app/settings.php');

/**
 * Rare NPC Stats: sighting history for the NPCs in NPC::HISTORY, combined across universes.
 *
 * Views (all active contributors only, same 48h rule as NPC List):
 *   /{Uni}/npcstats                          overview of every tracked NPC
 *   /{Uni}/npcstats?npc=feral_serpent        one NPC: its unique IDs + where it has been seen (by name)
 *   /{Uni}/npcstats?npc=...&u=Orion&nid=123  one NPC ID: its trail, sector by sector, in date order
 */

use Pardusmapper\Core\ApiResponse;
use Pardusmapper\DB;
use Pardusmapper\NPC;
use Pardusmapper\Request;
use Pardusmapper\Session;

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
    $view = (!is_null($trailUni) && !is_null($nid)) ? 'trail' : 'npc';
}

// keep every value the template needs in one place
$stats = ['view' => $view, 'slug' => $slug, 'groups' => $groups, 'u' => $trailUni, 'nid' => $nid];

/** Later of two datetime strings (either may be null). */
$later = static fn (?string $a, ?string $b): ?string => (is_null($a) || (!is_null($b) && strcmp($b, $a) > 0)) ? $b : $a;

if ($view === 'overview') {
    $all = [];
    foreach ($groups as $g) { $all = array_merge($all, $g['images']); }
    $imageToSlug = [];
    foreach ($groups as $s => $g) { foreach ($g['images'] as $img) { $imageToSlug[$img] = $s; } }

    $summary = [];
    foreach ($groups as $s => $g) {
        $summary[$s] = ['name' => $g['name'], 'total' => 0, 'ids' => [], 'noid' => 0, 'active' => 0, 'first' => null, 'last' => null, 'sectors' => []];
    }
    foreach (DB::npc_sightings(images: $all) as $r) {
        $s = $imageToSlug[$r->image] ?? null;
        if (is_null($s)) { continue; }
        $row = &$summary[$s];
        $row['total']++;
        if (is_null($r->nid)) { $row['noid']++; } else { $row['ids'][$r->uni][(int) $r->nid] = true; }
        if ((int) $r->active === 1) { $row['active']++; }
        if (is_null($row['first']) || strcmp((string) $r->spotted, (string) $row['first']) < 0) { $row['first'] = $r->spotted; }
        $row['last'] = $later($row['last'], $r->last_seen);
        $row['sectors'][$r->sector] = true;
        unset($row);
    }
    $stats['summary'] = $summary;
}

if ($view === 'npc') {
    $ids = [];      // "Uni|nid" => [...]
    $noid = [];     // Uni => count
    $sectors = [];  // sector => [...]

    foreach (DB::npc_sightings(images: $groups[$slug]['images']) as $r) {
        if (!is_null($r->nid)) {
            $key = $r->uni . '|' . $r->nid;
            $id = $ids[$key] ?? ['uni' => $r->uni, 'nid' => (int) $r->nid, 'total' => 0, 'sectors' => [], 'first' => $r->spotted, 'last' => null, 'active' => 0, 'where' => null];
            $id['total']++;
            $id['sectors'][$r->sector] = true;
            $id['last'] = $later($id['last'], $r->last_seen);
            if ((int) $r->active === 1) { $id['active']++; }
            $id['where'] = sprintf('%s [%d,%d]', $r->sector, $r->x, $r->y); // rows are oldest first, so this ends as the latest
            $ids[$key] = $id;
        } else {
            $noid[$r->uni] = ($noid[$r->uni] ?? 0) + 1;
        }

        $sec = $sectors[$r->sector] ?? ['total' => 0, 'ids' => [], 'unis' => [], 'last' => null, 'tiles' => []];
        $sec['total']++;
        if (!is_null($r->nid)) { $sec['ids'][$r->uni . '|' . $r->nid] = true; }
        $sec['unis'][$r->uni] = ($sec['unis'][$r->uni] ?? 0) + 1;
        $sec['last'] = $later($sec['last'], $r->last_seen);
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

if ($view === 'trail') {
    // Consecutive sightings in the same sector become one step of the trail
    $steps = [];
    foreach (DB::npc_sightings(images: $groups[$slug]['images'], universe: $trailUni, nid: $nid) as $r) {
        $last = count($steps) - 1;
        if ($last < 0 || $steps[$last]['sector'] !== $r->sector) {
            $steps[] = ['sector' => $r->sector, 'from' => $r->spotted, 'to' => $r->last_seen, 'points' => [], 'active' => 0];
            $last++;
        }
        $steps[$last]['points'][] = ['x' => (int) $r->x, 'y' => (int) $r->y, 'spotted' => $r->spotted, 'last' => $r->last_seen, 'active' => (int) $r->active];
        $steps[$last]['to'] = $later($steps[$last]['to'], $r->last_seen);
        $steps[$last]['active'] += (int) $r->active;
    }

    $stats['steps'] = $steps;
    $stats['dims'] = DB::sector_dims(array_column($steps, 'sector'));
}

$title = 'Rare NPC Stats';
require_once(templates('npcstats'));
