<?php 
declare(strict_types=1);
require_once('../app/settings.php');

use Pardusmapper\Core\ApiResponse;
use Pardusmapper\Core\MySqlDB;
use Pardusmapper\CORS;
use Pardusmapper\Post;
use Pardusmapper\Session;
use Pardusmapper\DB;
use Pardusmapper\NPC;

CORS::mapper();

$dbClass = MySqlDB::instance();

$uni = Post::uni();
http_response(is_null($uni), ApiResponse::OK, sprintf('uni query parameter is required or invalid: %s', $uni ?? 'null'));

$sector = Post::pstring(key: 'sector');
http_response(is_null($sector), ApiResponse::OK, 'sector/s query parameter is required');

$img_url = Post::pstring(key: 'img_url');
$mode = Post::pstring(key: 'mode');
$shownpc = Post::pbool(key: 'shownpc');
$whole = Post::pbool(key: 'whole');
$grid = Post::pbool(key: 'grid');
$history = Post::pbool(key: 'history');

session_name($uni);
session_start();

$security = Session::pint(key: 'security', default: 0);

$s = DB::sector(sector: $sector);
http_response(is_null($s), ApiResponse::OK, sprintf('sector not found for sector name: %s', $sector));

$npc_list = NPC::for_logged_users();

// 1. Get Base Map Data
$dbClass->execute(sprintf('SELECT *, UTC_TIMESTAMP() as today FROM %s_Maps WHERE sector = ? AND starbase = 0 ORDER BY x,y', $uni), [
    's', $sector
]);
$m = [];
while ($q = $dbClass->fetchObject()) {
    $m[$q->x][$q->y] = $q;
}

// 2. Handle History Logic
$counts = [];
if ($history) {
    // Group all Feral Serpents (1,2,3) together for the count
    $dbClass->execute(sprintf("SELECT id, COUNT(*) as total FROM %s_Npcs WHERE npc = 'Shadow' OR npc LIKE 'Feral Serpent%%' GROUP BY id", $uni));
    while ($c_row = $dbClass->fetchObject()) {
        $counts[$c_row->id] = (int)$c_row->total;
    }

    // Fetch deleted sightings to display as markers if the tile is currently empty
    $dbClass->execute(sprintf("SELECT * FROM %s_Npcs WHERE deleted = 1 AND sector = ? AND (npc = 'Shadow' OR npc LIKE 'Feral Serpent%%')", $uni), ['s', $sector]);
    while ($h_row = $dbClass->fetchObject()) {
        if (isset($m[$h_row->x][$h_row->y]) && empty($m[$h_row->x][$h_row->y]->npc)) {
            $m[$h_row->x][$h_row->y]->npc = $h_row->npc;
            $m[$h_row->x][$h_row->y]->npc_updated = $h_row->date_added;
            $m[$h_row->x][$h_row->y]->npc_cloaked = 0;
            $m[$h_row->x][$h_row->y]->is_history = true;
        }
    }
}

$showfg = in_array($mode, ['all', 'buildings']) ? true : false;
$shownpc = in_array($mode, ['all', 'npcs']) ? true : $shownpc;

$return = '<table id="sectorTableMap">';
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
            
            $can_click = (!$map->wormhole && (($showfg && $map->fg) || ($shownpc && $map->npc && (!in_array($map->npc,$npc_list) || isset($_SESSION['user'])))));
            if ($can_click) {
                $return .= ' onClick="loadDetail(\'' . $base_url . '\',\'' . $uni . '\',' . $map->id . ');return true;" onMouseOut="closeInfo();" onMouseOver="openInfo(\'' . $base_url . '\',\'' . $uni . '\',' . $map->id . ');"';
            } 
            $return .= '>';

            $return .= '<img class="bg" src="' . $img_url . $map->bg . '" title=""/>';
            
            if (($map->security == 0) || ($security == $map->security) || ($security == 100)) {
                // Foreground / Buildings / Planets
                if ($map->fg && ($showfg || strpos($map->fg,"planet") || strpos($map->fg,"federation") || $map->wormhole)) {
                    $fg_img = $img_url . $map->fg;
                    $fg_time = !empty($map->fg_updated) ? strtotime($map->fg_updated) : 0;
                    $sec = strtotime((string)$map->today) - $fg_time;
                    $fg_diff = floor($sec/86400) . 'd ' . floor(($sec%86400)/3600) . 'h ' . floor(($sec%3600)/60) . 'm';

                    if ($map->wormhole) {
                        $return .= '<a href="'. $base_url .'/' . $uni . '/' . $map->wormhole .'">';
                        $title = $map->wormhole . ' [' . $fg_diff . ']';
                        $return .= '<img class="fg" src="' . $fg_img . '" alt="" title="' . $title . '" />';
                        $return .= '</a>';
                    } else {
                        $return .= '<img class="fg" src="' . $fg_img . '" alt="' . $fg_diff . '" />';
                    }
                }

                // NPCs
                if ($map->npc && $shownpc && (!$map->wormhole)) {
                    if (!in_array($map->npc,$npc_list) || isset($_SESSION['user'])) {
                        $npc_img = $img_url . $map->npc;
                        $npc_time = !empty($map->npc_updated) ? strtotime($map->npc_updated) : 0;
                        $sec = strtotime((string)$map->today) - $npc_time;
                        $npc_diff = floor($sec/86400) . 'd ' . floor(($sec%86400)/3600) . 'h ' . floor(($sec%3600)/60) . 'm';

                        $npc_class = ($map->npc_cloaked == 1) ? 'npcCloak' : 'npc';
                        if (isset($map->is_history)) { $npc_class .= ' history-npc'; }

                        $return .= '<div style="position:relative; display:inline-block; vertical-align:top;">';
                        $return .= '<img class="' . $npc_class . '" src="' . $npc_img . '" alt="' . $npc_diff . '" />';
                        
                        // Show count badge for Serpents/Shadows if > 1
                        if (isset($counts[$map->id]) && $counts[$map->id] > 1) {
                            $return .= '<span class="npc-count-badge">' . $counts[$map->id] . '</span>';
                        }
                        $return .= '</div>';
                    }
                }
            }
            $return .= '</td>';
        } else {
            $class = $grid ? 'grid' : 'nogrid';
            $return .= '<td class="'.$class.'"><img class="bg" src="' . $img_url . 'backgrounds/energymax.png" title=""/></td>';								
        }
    }
    $return .= '<th>' . $y . '</th></tr>';
}
$return .= '</tbody><tfoot><tr><th />';
for ($i = 0;$i < $s->cols;$i++) { $return .= '<th>' . $i . '</th>'; }
$return .= '<th /></tr></tfoot></table>';

echo $return;