<?php
declare(strict_types=1);
require_once('../app/settings.php');

/** @var string $base_url */
/** @var string $img_url */

use Pardusmapper\Core\MySqlDB;
use Pardusmapper\Core\ApiResponse;
use Pardusmapper\Post;
use Pardusmapper\Session;
use Pardusmapper\CORS;
use Pardusmapper\DB;

CORS::mapper();

debug($_POST);

$db = MySqlDB::instance();  // Create an instance of the Database class

$uni = Post::uni();
http_response(is_null($uni), ApiResponse::OK, sprintf('uni query parameter is required or invalid: %s', $uni ?? 'null'));

$npc_filter = Post::pstring(key: 'npc');
$sort = Post::pstring(key: 'sort', default: '');
$order = Post::pint(key: 'order', default: 0);
$sector = Post::pstring(key: 'sector');
$cluster = Post::pstring(key: 'cluster');

session_name($uni);
session_start();

$security = Session::pint(key: 'security', default: 0);

$static = DB::static_locations();
$delete = [];
$npc = [];
$return = '';
$sort_by = '';

if (strlen((string) $sort)) {
    $sort_by = ' ORDER BY ';
    for($i=0;$i<strlen((string) $sort);$i++) {
        switch(substr((string) $sort,$i,1)) {
            case "C" :
                $sort_by .= "cluster ";
                if ($order & 1) { $sort_by .= " ASC, "; }
                else { $sort_by .= " DESC, "; }
                break;
            case "S" :
                $sort_by .= "sector ";
                if ($order & 2) { $sort_by .= " ASC, "; }
                else { $sort_by .= " DESC, "; }
                break;
            case "L" :
                $sort_by .= "x ";
                if ($order & 4) { $sort_by .= " ASC, "; }
                else { $sort_by .= " DESC, "; }
                $sort_by .= "y ";
                if ($order & 4) { $sort_by .= " ASC, "; }
                else { $sort_by .= " DESC, "; }
                break;
            case "N" :
                $sort_by .= "name ";
                if ($order & 8) { $sort_by .= " ASC, "; }
                else { $sort_by .= " DESC, "; }
                break;
            case "A" :
                $sort_by .= "spotted ";
                if ($order & 16) { $sort_by .= " DESC, "; }
                else { $sort_by .= " ASC, "; }
                break;
            case "T" :
                $sort_by .= "updated ";
                if ($order & 32) { $sort_by .= " DESC, "; }
                else { $sort_by .= " ASC, "; }
                break;
        }
    }
    $sort_by = substr($sort_by,0,-2);
}

//if (strlen($sort_by)) { $return .= $sort_by . '<br>'; }

//$query = 'SELECT *, UTC_TIMESTAMP() "today" FROM ' . $uni . '_Test_Npcs Where 1 = 1 and (deleted is null or deleted = 0) ';
$bindType = [];
$bindValues = [];
$query = sprintf('SELECT *, UTC_TIMESTAMP() "today",DATEDIFF(UTC_TIMESTAMP(), spotted) "aged", CONCAT(FLOOR(HOUR(TIMEDIFF(UTC_TIMESTAMP(), updated)) / 24), \'d \',MOD(HOUR(TIMEDIFF(UTC_TIMESTAMP(), updated)), 24), \'h \',MINUTE(TIMEDIFF(UTC_TIMESTAMP(), updated)), \'m\') "updateString",CONCAT(FLOOR(HOUR(TIMEDIFF(UTC_TIMESTAMP(), spotted)) / 24), \'d \',MOD(HOUR(TIMEDIFF(UTC_TIMESTAMP(), spotted)), 24), \'h \',MINUTE(TIMEDIFF(UTC_TIMESTAMP(), spotted)), \'m\') "spottedString" FROM %s_Test_Npcs Where 1 = 1 and (deleted is null or deleted = 0) ', $uni);
if (isset($sector)) {		
    $query .= ' AND sector = ?';
    $bindType[] = 's';
    $bindValues[] = $sector;
} elseif (isset($cluster)) {
    if ($cluster != 'CORE') {
        $c = DB::cluster(code: $cluster);	
        $query .= ' AND cluster = ?';
        $bindType[] = 's';
        $bindValues[] = $c->name;
    } else {
        $query .= ' AND cluster LIKE \'Pardus%Contingent\'';
    }	
}/*  else {
} */
if (strtolower((string) $npc_filter) != 'all') {
    $query .= ' AND name = ?';
    $bindType[] = 's';
    $bindValues[] = $npc_filter;
}
if (strlen($sort_by)) { $query .= $sort_by; }	
        

//$return .= $query; //this will display the query for the NPC list

// Commented out code to loop and remove old NPCs to try and fix DB connections issue 4.28.2021 --Added back in 1.9.2023

$params = [];
$params[] = implode('', $bindType);
$params = array_merge($params, $bindValues);
$db->execute($query, $params);

while ($q = $db->nextObject()) {
    debug($q);
    if (empty($q->sector)) {$delete[] = $q->kid; continue;}
    // Calculate Days/Hours/Mins Since last seen
    $diff['sec'] = isset($q->updated) ? strtotime($q->today) - strtotime($q->updated) : 99999999;
    $diff['days'] = $diff['sec']/60/60/24;
    $diff['hours'] = ($diff['days'] - floor($diff['days'])) * 24;
    $diff['min'] = ($diff['hours'] - floor($diff['hours'])) * 60;
    $diff['string'] = floor($diff['days']) . 'd ' . floor($diff['hours']) . 'h ' . floor($diff['min']) . 'm';
    $expiry_time = new DateTime($q->updated);
    $current_time = new DateTime();
    $diffs = $expiry_time->diff($current_time);
    //echo $diffs->format('%H:%I:%S');
    $diff['string'] = $diffs->format('%dd %hh %im');
    
    $q->tick = $diff['string'];
        
    unset($diff);
        
    // Calculate Days/Hours/Mins Since First Spotted
    $diff['sec'] = strtotime($q->today) - strtotime($q->spotted);
    $diff['days'] = $diff['sec']/60/60/24;
    $diff['hours'] = ($diff['days'] - floor($diff['days'])) * 24;
    $diff['min'] = ($diff['hours'] - floor($diff['hours'])) * 60;
    $diff['string'] = floor($diff['days']) . 'd ' . floor($diff['hours']) . 'h ' . floor($diff['min']) . 'm';
        
    $q->age = $diff['string'];
    $cloaked = $q->cloaked;
    $aged = $q->aged;
    if ((($aged > 2) && ($cloaked == 1))||$aged > 7) { $delete[] = $q->kid; }
    if (($diff['days'] > 2 && ($cloaked == 1))||$diff['days'] > 7) { $delete[] = $q->kid; }
    if ($diff['days'] > 7) { $delete[] = $q->kid; }
    else { if (!(in_array($q->id,$static))) { $npc[] = $q; } }
    
    unset($diff);
    unset($cloaked);
    unset($aged);
}

// expire only the stale records, not every NPC on their tiles
DB::npc_end(universe: $uni, kids: $delete);

$db->close();

$return .= '<table id="npc_table">';
$return .= '<tr>';
$return .= '<th>';
if (str_contains((string) $sort,"C"))  {
    if ($order & 1) { $return .= '<span class="symbol">&uarr;</span>'; } else { $return .= '<span class="symbol">&darr;</span>'; }
    $return .= '<a href="#" onClick="multiSort(\'C\');return false;">&nbsp;Cluster&nbsp;</a>';
    $return .= '<a href="#" onClick="removeSort(\'C\');return false;"><span class="symbol">&times;</span></a>';
} else {
    $return .= '<a href="#" onClick="multiSort(\'C\');return false;">Cluster</a>';
}
$return .= '</th>';
$return .= '<th>';
if (str_contains((string) $sort,"S")) { 
    if ($order & 2) { $return .= '<span class="symbol">&uarr;</span>'; } else { $return .= '<span class="symbol">&darr;</span>'; }
    $return .= '<a href="#" onClick="multiSort(\'S\');return false;">&nbsp;Sector&nbsp;</a>';
    $return .= '<a href="#" onClick="removeSort(\'S\');return false;"><span class="symbol">&times;</span></a>';
} else {
    $return .= '<a href="#" onClick="multiSort(\'S\');return false;">Sector</a>';
}
$return .= '</th>';
$return .= '<th>';
if (str_contains((string) $sort,"L")) { 
    if ($order & 4) { $return .= '<span class="symbol">&uarr;</span>'; } else { $return .= '<span class="symbol">&darr;</span>'; }
    $return .= '<a href="#" onClick="multiSort(\'L\');return false;">&nbsp;Location&nbsp;</a>';
    $return .= '<a href="#" onClick="removeSort(\'L\');return false;"><span class="symbol">&times;</span></a>';
} else {
    $return .= '<a href="#" onClick="multiSort(\'L\');return false;">Location</a>';
}
$return .= '</th>';
$return .= '<th colspan="2">';
if (str_contains((string) $sort,"N")) { 
    if ($order & 8) { $return .= '<span class="symbol">&uarr;</span>'; } else { $return .= '<span class="symbol">&darr;</span>'; }
    $return .= '<a href="#" onClick="multiSort(\'N\');return false;">&nbsp;NPC&nbsp;</a>';
    $return .= '<a href="#" onClick="removeSort(\'N\');return false;"><span class="symbol">&times;</span></a>';
} else {
    $return .= '<a href="#" onClick="multiSort(\'N\');return false;">NPC</a>';
}
$return .= '</th>';
$return .= '<th>';
if (str_contains((string) $sort,"A")) { 
    if ($order & 16) { $return .= '<span class="symbol">&uarr;</span>'; } else { $return .= '<span class="symbol">&darr;</span>'; }
    $return .= '<a href="#" onClick="multiSort(\'A\');return false;">&nbsp;First Spotted&nbsp;</a>';
    $return .= '<a href="#" onClick="removeSort(\'A\');return false;"><span class="symbol">&times;</span></a>';
} else {
    $return .= '<a href="#" onClick="multiSort(\'A\');return false;">First Spotted</a>';
}
$return .= '</th>';
$return .= '<th>';
if (str_contains((string) $sort,"T")) { 
    if ($order & 32) { $return .= '<span class="symbol">&uarr;</span>'; } else { $return .= '<span class="symbol">&darr;</span>'; }
    $return .= '<a href="#" onClick="multiSort(\'T\');return false;">&nbsp;Last Spotted&nbsp;</a>';
    $return .= '<a href="#" onClick="removeSort(\'T\');return false;"><span class="symbol">&times;</span></a>';
} else {
    $return .= '<a href="#" onClick="multiSort(\'T\');return false;">Last Spotted</a>';
}
$return .= '</th>';
$return .= '</tr>';

$i = 0;
if ($npc) {
    foreach ($npc as $n) {
        if ($i++ % 2 == 0) {
            $return .= '<tr class="alternating">';
        } else {
            $return .= '<tr>';
        }
        $return .= '<td align="center">' . $n->cluster . '</td>';
        $return .= '<td align="center"><a href="' . $base_url . '/' . $uni . '/' . $n->sector . '">' . $n->sector . '</a></td>';
        $return .= '<td align="center">[' . $n->x . ',' . $n->y . ']</td>';
        $return .= '<td align="left">';
        if (isset($n->npc_cloaked) && $n->npc_cloaked) {
            $return .= '<img class="cloaked" src="' . $img_url . $n->image . '" />';
        } else {
            $return .= '<img src="' . $img_url . $n->image . '" />';
        }

        $return .= '</td>';
        $return .= '<td align="left">' . $n->name . '</td>';
        $return .= '<td align="center">' . $n->spottedString . '</td>';
        //$return .= '<td align="center">' . $n->tick . '</td>';
        $return .= '<td align="center">' . $n->updateString . '</td>';
        $return .= '</tr>';
    }
}

$return .= '</table>';

echo $return;
