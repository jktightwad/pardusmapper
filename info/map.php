<?php 
declare(strict_types=1);
require_once('../app/settings.php');

/** @var string $base_url */

use Pardusmapper\Core\ApiResponse;
use Pardusmapper\Core\MySqlDB;
use Pardusmapper\CORS;
use Pardusmapper\Post;
use Pardusmapper\Session;
use Pardusmapper\DB;
use Pardusmapper\NPC;

CORS::mapper();

$dbClass = MySqlDB::instance();  // Create an instance of the Database class

// Set Univers Variable and Session Name
$uni = Post::uni();
http_response(is_null($uni), ApiResponse::OK, sprintf('uni query parameter is required or invalid: %s', $uni ?? 'null'));

$sector = Post::pstring(key: 'sector');
http_response(is_null($sector), ApiResponse::OK, 'sector/s query parameter is required');

$cluster = Post::pstring(key: 'cluster');
$img_url = Post::pstring(key: 'img_url'); // this will override the settings/cookies value
$mode = Post::pstring(key: 'mode');
$shownpc = Post::pbool(key: 'shownpc'); // below we override it from $mode based on options
$whole = Post::pbool(key: 'whole'); // below we override it from $mode based on options
$grid = Post::pbool(key: 'grid');
$history = Post::pint(key: 'history') === 1;

$loc = Post::pint(key: 'id'); //trying to figure out why $loc is missing definition
// http_response(is_null($loc), ApiResponse::OK, sprintf('location(id) query parameter is required or invalid: %s', $loc ?? 'null'));

// Start the Session
session_name($uni);
session_start();

$security = Session::pint(key: 'security', default: 0);

$coreWH = ['Pardus', 'Enif', 'Quaack', 'Nhandu', 'Procyon'];
if (in_array($sector, $coreWH)) {
    DB::wh_update_pardus_status(universe: $uni); // Call the method on the mysqldb instance
}

// Get Sector Information
$s = DB::sector(sector: $sector);
http_response(is_null($s), ApiResponse::OK, sprintf('sector not found for sector name: %s', $sector)); // exit if not found in DB

// NPCs only shown to logged in users?
$npc_list = NPC::for_logged_users();

// Get Map Data for Sector
$dbClass->execute(sprintf('SELECT *, UTC_TIMESTAMP() as today FROM %s_Maps WHERE sector = ? AND starbase = 0 ORDER BY x,y', $uni), [
    's', $sector
]);
$m = [];
while ($q = $dbClass->fetchObject()) {
    $m[$q->x][$q->y] = $q;
    // $m[$q->id] = $q; // Uncomment if you want to use id as the key
}

// Every NPC recorded on each tile right now. The tile shows one image, but Vyrex and Pirates share
// tiles with other NPCs and Vyrex stack, so the tooltip lists them all: "Shadow, Vyrex Assassin x3".
$tileNpcs = [];
$dbClass->execute(sprintf('SELECT x, y, name, image, MAX(cloaked) AS cloaked, COUNT(*) AS n FROM %s_Test_Npcs WHERE sector = ? AND (deleted IS NULL OR deleted = 0) GROUP BY x, y, name, image', $uni), [
    's', $sector
]);
while ($q = $dbClass->fetchObject()) {
    if (in_array($q->image, $npc_list) && !isset($_SESSION['user'])) {
        continue;
    }
    $label = $q->name ?: ucwords(str_replace('_', ' ', basename((string) $q->image, '.png')));
    $tileNpcs[$q->x][$q->y][] = $label . ((int) $q->n > 1 ? ' x' . (int) $q->n : '') . (empty($q->cloaked) ? '' : ' (cloaked?)');
}

// Rare NPC sighting history, combined across all universes (logged-in users only)
// Times come from when the record was added (`spotted`); `updated` is overwritten when the NPC
// is reported gone, so it is not a "last seen" time. Records of the same NPC on the same tile
// less than 2 hours apart count as one sighting (see NPC::mark_sightings).
// $hist[x][y] = ['total' => visits, 'last' => datetime, 'image' => latest image, 'title' => tooltip]
$hist = [];
if ($history && isset($_SESSION['user'])) {
    $tracked = NPC::HISTORY;
    $tiles = [];

    $records = NPC::mark_sightings(DB::npc_sightings(images: array_keys($tracked), sector: $s->name));
    foreach ($records as $row) {
        if (!$row->counted) {
            continue;
        }
        $hx = (int) $row->x;
        $hy = (int) $row->y;
        $label = $tracked[$row->image] ?? $row->image;
        $tile = $tiles[$hx][$hy] ?? ['total' => 0, 'last' => null, 'image' => null, 'npcs' => []];

        $tile['total']++;
        $tile['npcs'][$label][$row->uni] = ($tile['npcs'][$label][$row->uni] ?? 0) + 1;
        if (is_null($tile['last']) || strcmp((string) $row->spotted, (string) $tile['last']) > 0) {
            $tile['last'] = $row->spotted;
            $tile['image'] = $row->image;
        }

        $tiles[$hx][$hy] = $tile;
    }

    foreach ($tiles as $hx => $col) {
        foreach ($col as $hy => $tile) {
            $lines = [];
            foreach ($tile['npcs'] as $label => $perUni) {
                $byUni = [];
                foreach ($perUni as $u => $n) { $byUni[] = $u . ' ' . $n; }
                $n = array_sum($perUni);
                $lines[] = sprintf('%s seen here %d time%s (%s)', $label, $n, $n === 1 ? '' : 's', implode(', ', $byUni));
            }
            // shown in the viewer's local time by pmLocalizeTimes() in resources/main.js
            $tile['utc'] = str_replace(' ', 'T', substr((string) $tile['last'], 0, 19)) . 'Z';
            $tile['utc_text'] = substr((string) $tile['last'], 0, 16) . ' UTC';
            $lines[] = 'Last seen ' . $tile['utc_text'];
            $tile['title'] = implode("\n", $lines);
            $hist[$hx][$hy] = $tile;

            // On empty tiles, show a faded marker for the most recently reported NPC
            if (isset($m[$hx][$hy]) && empty($m[$hx][$hy]->npc)) {
                $m[$hx][$hy]->npc = $tile['image'];
                $m[$hx][$hy]->npc_updated = $tile['last'];
                $m[$hx][$hy]->npc_cloaked = 0;
                $m[$hx][$hy]->is_history = true;
            }
        }
    }
}


$showfg = in_array($mode, ['all', 'buildings']) ? true : false;
$shownpc = in_array($mode, ['all', 'npcs']) ? true : $shownpc;

//Get NPC Information for Sector

$return = '<table id="sectorTableMap" >';
$return .= '<thead><tr><th />';
for ($i = 0;$i < $s->cols;$i++) { $return .= '<th>' . $i . '</th>'; }
$return .= '<th /></tr></thead>';
$return .= '<tbody>';
for ($y = 0;$y < $s->rows;$y++) {
    $return .= '<tr><th>' . $y . '</th>';
    for ($x = 0;$x < $s->cols;$x++) {
        if (isset($m[$x][$y])) {
            $map = $m[$x][$y]; 
            $return .= '<td id="' . $map->id . '"';
            if ($grid) { $return .= ' class="grid"'; }
            else { $return .= ' class="nogrid"'; }
            if (isset($hist[$x][$y])) { $return .= ' style="position:relative"'; } // anchors the sighting count badge
            if (!$map->wormhole && (($showfg && $map->fg) || ($shownpc && $map->npc && (!in_array($map->npc,$npc_list) || isset($_SESSION['user']))))) {
                $return .= ' onClick="loadDetail(\'' . $base_url . '\',\'' . $uni . '\',' . $map->id . ');return true;" onMouseOut="closeInfo();" onMouseOver="openInfo(\'' . $base_url . '\',\'' . $uni . '\',' . $map->id . ');"';
            } 
            $return .= '>';

            // Set the Background
            $bg_img = $img_url . $map->bg;
            $return .= '<img class="bg" src="' . $bg_img . '" title=""/>';
            if (($map->security == 0) || ($security == $map->security) || ($security == 100)) {
                if ($map->fg){
                    $fg_img = $img_url . $map->fg;
                    // Calculate Days/Hours/Mins Since last Visited
                    //$fg_diff['sec'] = strtotime($map->today) - strtotime($map->fg_date);
                    if (!empty($map->today) && !empty($map->fg_updated)) {
                        $fg_diff['sec'] = strtotime($map->today) - strtotime($map->fg_updated);
                    } else {
                        $fg_diff['sec'] = 0; // no update time recorded for this building
                    }
                    $fg_diff['days'] = $fg_diff['sec']/60/60/24;
                    $fg_diff['hours'] = ($fg_diff['days'] - floor($fg_diff['days'])) * 24;
                    $fg_diff['min'] = ($fg_diff['hours'] - floor($fg_diff['hours'])) * 60;
                    $fg_diff['string'] = ' ' . floor($fg_diff['days']) . 'd ' . floor($fg_diff['hours']) . 'h ' . floor($fg_diff['min']) . 'm';
                            
                    // Set Wormhole Data if we Got it
                    if ($map->wormhole && $security != 100) {
                        $return .= '<a href="'. $base_url .'/' . $uni . '/' . $map->wormhole .'">';
                        if (strpos($fg_img,"wormholeseal")) {
                            if (strpos($fg_img,"open")) {
                                $return .= '<img class="fg" src="' . $fg_img . '" alt="" title="' . $map->wormhole . ' {Open} [' . $fg_diff['string'] . ']" />';							
                            } else {
                                $return .= '<img class="fg" src="' . $fg_img . '" alt="" title="' . $map->wormhole . ' {Closed} [' . $fg_diff['string'] . ']" />';							
                            }
                        } else {
                            //$return .= '<img class="fg" src="' . $fg_img . '" alt="' . $loc . '" title=" ' . $map->wormhole . ' " />';
                            $return .= '<img class="fg" src="' . $fg_img . '" alt="' . $loc . '" title=" ' . $map->wormhole . ' " />';
                        }
                        $return .= '</a>';
                    // Set ability to delete Wormhole
                    }
                    else if ($map->wormhole && $security == 100) {
                        
                        if ($whole == 1){$return .= '<a href="'. $base_url .'/include/destroyWH.php?uni=' .$uni. '&id=' .$map->id. '" target="_blank">';}
                        else {$return .= '<a href="'. $base_url .'/' . $uni . '/' . $map->wormhole .'">';}
                        if (strpos($fg_img,"wormholeseal")) {
                            if (strpos($fg_img,"open")) {
                                $return .= '<img class="fg" src="' . $fg_img . '" alt="" title="' . $map->wormhole . ' {Open} [' . $fg_diff['string'] . ']" />';							
                            } else {
                                $return .= '<img class="fg" src="' . $fg_img . '" alt="" title="' . $map->wormhole . ' {Closed} [' . $fg_diff['string'] . ']" />';							
                            }
                        } else {
                            if ($whole == 1){$return .= '<img class="fg" src="' . $fg_img . '" alt="' . $loc . '" title="remove ID '.$map->id.'" />';}
                            else {$return .= '<img class="fg" src="' . $fg_img . '" alt="' . $loc . '" title="' . $map->wormhole . '" />';}
                        }
                        $return .= '</a>';
                    // Set Building Info if we got it
                    }
                    elseif ($showfg || strpos($fg_img,"planet") || strpos($fg_img,"federation"))  {
                        $return .= '<img class="fg" src="' . $fg_img . '" alt = "' . $fg_diff['string'] . '"';
                        $return .= ' />';
                    }
                }
                if ($map->npc && $shownpc && !$map->wormhole) {
                    if (!in_array($map->npc,$npc_list) || isset($_SESSION['user'])) {
                        // Consolidate Feral Serpent variants to use the default image
                        $display_npc_img = (strpos($map->npc, 'Feral Serpent') !== false) ? 'npcs/feral_serpent.png' : $map->npc;
                        $npc_img = $img_url . $display_npc_img;

                        // Calculate Days/Hours/Mins Since last Visited
                        $npc_diff['sec'] = strtotime($map->today) - strtotime($map->npc_updated);
                        $npc_diff['days'] = $npc_diff['sec']/60/60/24;
                        $npc_diff['hours'] = ($npc_diff['days'] - floor($npc_diff['days'])) * 24;
                        $npc_diff['min'] = ($npc_diff['hours'] - floor($npc_diff['hours'])) * 60;
                        $npc_diff['string'] = ' ' . floor($npc_diff['days']) . 'd ' . floor($npc_diff['hours']) . 'h ' . floor($npc_diff['min']) . 'm';

                        // Pilot has logged Data recently
                        $npc_id = 'npc';
                        if ($map->npc_cloaked == 1) { $npc_id .= 'Cloak'; }
                        if (isset($map->is_history)) { $npc_id .= ' history-npc'; }

                        // tooltip: every NPC recorded here now (when there is more than one), then the
                        // rare NPC history for the tile
                        $here = (count($tileNpcs[$x][$y] ?? []) > 1 || str_contains(implode('', $tileNpcs[$x][$y] ?? []), ' x'))
                            ? 'NPCs here: ' . implode(', ', $tileNpcs[$x][$y])
                            : '';
                        if (isset($hist[$x][$y])) {
                            $title = ($here !== '' ? $here . "\n" : '') . $hist[$x][$y]['title'];
                            $hist_title = ' title="' . htmlspecialchars($title) . '" data-utc="' . htmlspecialchars($hist[$x][$y]['utc']) . '" data-utc-text="' . htmlspecialchars($hist[$x][$y]['utc_text']) . '"';
                        } else {
                            $hist_title = $here !== '' ? ' title="' . htmlspecialchars($here) . '"' : '';
                        }
                        // plain image like every other tile layer (bg/fg/npc stack via margin-top:-32px)
                        $return .= '<img class="' . $npc_id . '" src="' . $npc_img . '" alt="' . $npc_diff['string'] . '"' . $hist_title . ' />';

                        // sighting count, pinned to this tile's bottom-right corner (the td is position:relative)
                        if (isset($hist[$x][$y])) {
                            $return .= '<span class="npc-count-badge">' . $hist[$x][$y]['total'] . '</span>';
                        }
                    }
                }
            }
            $return .= '</td>';
        } else {
            if ($grid) { $return .= '<td class="grid">'; }
            else { $return .= '<td class="nogrid">'; }
            $return .= '<img class="bg" src="' . $img_url . 'backgrounds/energymax.png" title=""/></td>';								
        }
    }
    $return .= '<th>' . $y . '</th></tr>';
}
$return .= '</tbody>';
$return .= '<tfoot><tr><th />';
for ($i = 0;$i < $s->cols;$i++) { $return .= '<th>' . $i . '</th>'; }
$return .= '<th /></tr></tfoot></table>';

echo $return;