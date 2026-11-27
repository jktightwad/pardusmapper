<?php
declare(strict_types=1);

use Pardusmapper\Coordinates;
use Pardusmapper\Core\Settings;
use Pardusmapper\Core\ApiResponse;
use Pardusmapper\Core\MySqlDB;
use Pardusmapper\CORS;
use Pardusmapper\DB;
use Pardusmapper\Request;
use Pardusmapper\NPC;

require_once('../app/settings.php');

CORS::pardus();

/**
 * all DB::npc_remove here will only remove the NPC 
 * they will not remove missions as cloaky NPCs can be cloaked 
 */

$db = MySqlDB::instance(); // Create an instance of the Database class

debug($_REQUEST);

$mapdata = Request::pstring(key: 'mapdata');
http_response(is_null($mapdata), ApiResponse::OK, sprintf('mapdata query parameter is required or invalid: %s', $mapdata ?? 'null'));

if (str_contains((string) $mapdata, "sb_")) {
    $site = Settings::$BASE_URL . '/include/importstarbasemap.php?';
    $site .= $_SERVER['QUERY_STRING'];
    header("Location: $site");
    exit();
}

// Set Univers Variable and Session Name
$uni = Request::uni();
http_response(is_null($uni), ApiResponse::OK, sprintf('uni query parameter is required or invalid: %s', $uni ?? 'null'));

// Get Version
$minVersion = '6.5';
$version = Request::pstring(key: 'version', default: '0.0');
http_response(version_compare($version, $minVersion, '<'), ApiResponse::OK, sprintf('version query parameter is required or invalid: %s ... minumum version: %s', ($uni ?? 'null'), $minVersion));

$loc = Request::pint(key: 'id');
http_response(is_null($loc), ApiResponse::OK, sprintf('location(loc) query parameter is required or invalid: %s', $loc ?? 'null'));


$cloaked = NPC::cloaked();
$single = NPC::single();
$hack = NPC::hack();
$nonblocking = NPC::nonblocking();
$mobile = NPC::mobile();

$time_pre = microtime(true);
$sqlcount = 0;

$sector = Request::pstring(key: 'sector');
if (is_null($sector)) {
    $sector = Request::pstring(key: 's');
}
http_response(is_null($sector), ApiResponse::OK, 'sector/s query parameter is required');
$s = DB::sector(sector: $sector);
$x = Coordinates::getX($loc, $s->s_id, $s->rows);
$y = Coordinates::getY($loc, $s->s_id, $s->rows, $x);

// Set Pilot Info
$ip = $_SERVER['REMOTE_ADDR'];

$uid = Request::pint(key: 'uid', default: 0);
$user = Request::pstring(key: 'user', default: "Unknown");

/****************************************************************************************************
 * 
 * this is all related to (Orion|Artemis|Pegasus)_Hack which doesn't seem to exist anymore
 * 
 ****************************************************************************************************
*/
// $coords = "Unknown";
// $px = "Unknown";
// $py = "Unknown";
// $ps = $sector;
// if (isset($_REQUEST['x'])) {
//     $px = $db->real_escape_string($_REQUEST['x']);
// }
// debug('pilot x location = ' . $px);

// if (isset($_REQUEST['y'])) {
//     $py = $db->real_escape_string($_REQUEST['y']);$
// }
// debug('pilot y location = ' . $py);

// debug('pilot s location = ' . $ps);

//$db->query("INSERT INTO ${uni}_Hack (`username`,`user_id`,`location`,`ip`,`date`) VALUES ('$user','$uid','" . $ps . "[" . $px . "," . $py . "]','$ip',UTC_TIMESTAMP())");
// End Pilot Data

//$db->query("INSERT INTO connection_log (`universe`,`username`,`user_id`,`querycount`,`duration`, `date`) VALUES ('Test','Test2','5','5','1.1',UTC_TIMESTAMP())");
/****************************************************************************************************/

++$sqlcount; // Counting SQL iterations per connection

$maparray = explode('~', (string) $mapdata);
$dataString = implode(",", $maparray);
debug($maparray);

//$last = $db->query("SELECT payload FROM connection_log where username = '$user' order by id desc limit 1");
//$last1 = implode('-',$last);
//debug('last payload' . $last1 . 'wtf');
//if (!($last == $dataString)) {
//debug(111);

$static = DB::static_locations();
++$sqlcount;

for ($i = 1; $i < count($maparray); $i++) { //This loop addresses only the current tile

    $temp = explode(',', $maparray[$i]);
    debug($temp);

    $id = preg_match('/^\d+$/', $temp[0]) ? (int)$temp[0] : null;

    if ($id == $loc) { 
        // this scenario will never happen as the foreground in map view can't be an opponent, it's always your ship.
        // Should look to "other ships" screen NOTED
        debug((str_contains($temp[1], "ponents") ? 'true' : 'false'). ' Evaluation Check');
        // (No fatal error here for an NPC on the pilot's tile: with an orbiter for that species, or in a
        // BOP-X, any NPC is non-blocking and shows on the pilot's own tile, so this is normal data.)

        debug(strpos($temp[1], "ponents") . ' vs !strpos($temp[1],"ponents") ');
        debug((!strpos($temp[1], "ponents") != false) . ' !strpos($temp[1],"ponents") != false');
        debug((!strpos($temp[1], "ponents") != false) . ' !strpos($temp[1],"ponents") !== false');
        debug((!strpos($temp[1], "ponents") != false) . ' strpos($temp[1],"ponents") != false');
        debug((!strpos($temp[1], "ponents") != false) . ' strpos($temp[1],"ponents") == false');
        debug('WTRF! If 1=' . (!strpos($temp[1], "ponents") != false) . ' then we should be removing any NPCs');

        if (!strpos($temp[1], "ponents") != false) {
            // No NPC or Ships on current tile????
            //debug('WTF!');
            //debug(strpos($temp[1],"ships"));
            debug($temp[1]);
            debug('??NPC has been killed');
            debug('Blow Away 6');
            DB::npc_remove(universe: $uni, id: $id); //removed to debug 9.16.24
            ++$sqlcount; // Counting SQL iterations per connection
            //die("0,Fatal Error");
        }
    }
}

//debug(count($maparray));
for ($i = 1; $i < sizeof($maparray); $i++) { //Not the tiles the ship is on ideally...
    global $sqlcount;
    $temp = explode(',', $maparray[$i]);
    debug($temp);

    $id = preg_match('/^\d+$/', $temp[0]) ? (int)$temp[0] : null;
    //debug($id);
    //$db->query("INSERT INTO connection_log (`universe`,`username`,`user_id`,`querycount`,`duration`, `date`) VALUES ('Test','$i','5','5','1.1',UTC_TIMESTAMP())");
    if (in_array($id, $static)) {
        continue;
    }
    // Check to see if we got good data
    if (!strpos($temp[1], "nodata.png") && !is_null($id)) {
        debug($id . ' Does Not Contain "nodata.png"');
        // Check to see if we got Building Info
        $r_bg = 0;
        if (str_contains($temp[1], "foregrounds")) {
            debug($id . ' Contains "Foreground" Info');
            $r_fg = 1;
            $r_npc = 0;
            if ((str_contains($temp[1], "wormhole")) || (str_contains($temp[1], "xhole")) || (str_contains($temp[1], "yhole"))) {
                if (sizeof($temp) != 3) {
                    debug($id, ' Contains "Critter" Info not "Foreground" Info');
                    $r_fg = 0;
                    $r_npc = 1;
                }
            }
            // Check to see if we got Critter info
        } elseif (str_contains($temp[1], "ponents")) {
            debug($id . ' Contains "Critter" Info');
            $r_fg = 0;
            $r_npc = 1;
            if (isset($temp[2]) && $temp[2] !== false) {
                $r_fg = 0;
                DB::building_remove(universe: $uni, id: $id, sb: 0); // can't have a building if we can see the NPC from the map view
                ++$sqlcount; // Counting SQL iterations per connection
            }
            // Must be a Ship or something I don't want
        } elseif (str_contains($temp[1], "xmas-star")) {
            debug($id . ' Contains Xmas Info');
            $r_fg = 1;
            $r_npc = 0;
        } else {
            debug($id . ' Contains "Background" Info');
            $r_fg = 0;
            $r_npc = 0;
            $r_bg = 1;
            //$db->removeBuilding($uni,$id,0);  // can't have a building if we can see the background only from the map view
                                                // This might be a bit strong, we don't need to delete non-existant data NOTED
            //++$sqlcount; // Counting SQL iterations per connection
        }

        // Check to see if we have Info for the current tile
        // Insert new data if there is not current info
        // Do Nothing if there is current info
        // This should not be the case with a complete map as every ID should be in the system, consider removing? REMOVED 4.2.20
        // Perform the initial query
        $r = DB::map(id: $id, universe: $uni);

        // Counting SQL iterations per connection
        ++$sqlcount;

        // Check if any row was returned
        if (!$r) {
            // There is no existing information for the current tile
            debug($id . ' New Information Inserting into DB');

            // Call method to add new map
            DB::map_add(universe: $uni, image: $temp[$r_bg], id: $id);

            // Counting SQL iterations per connection
            ++$sqlcount;

            // Perform the query again after adding new map
            $r = DB::map(id: $id, universe: $uni);

            // Counting SQL iterations per connection
            ++$sqlcount;
        }

        // Free the result set
        $db->free();


        debug(__FILE__, __LINE__, $r);

        // // Why would we not have sector and cluster?  This should be disabled?
        //if (is_null($r->cluster) || is_null($r->sector)) {
        //	debug($id . ' Sector and/or Cluster is Null');
        //	$s = $db->getSector($id,"");
        //	$db->query('UPDATE ' . $uni . '_Maps SET sector = \'' . $s->name . '\' WHERE id = ' . $id);
        //	$c = $db->getCluster($s->c_id,"");
        //	$db->query('UPDATE ' . $uni . '_Maps SET cluster = \'' . $c->name . '\' WHERE id = ' . $id);
        //} // We also have all the X/Y coords...again we should remove this?
        //if ($r->x == 0 || $r->y == 0) {
        //	debug($id . ' X and/or Y is Null');
        //	if (!$s) { $s = $db->getSector($id,""); }
        //	$x = $db->getX($id,$s->s_id,$s->rows);
        //	$db->query('UPDATE ' . $uni . '_Maps SET x = ' . $x . ' WHERE id = ' . $id);
        //	$y = $db->getY($id,$s->s_id,$s->rows,$x);
        //	$db->query('UPDATE ' . $uni . '_Maps SET y = ' . $y . ' WHERE id = ' . $id);
        //} 
        // If we are seeing the Nav Screen at the current location then there is NPC
        // Check to see if we have Wormhole destination info

        //Removing WH addition logic...just doesn't make sense while the Universes are stable - 3.17.2021 JT
        /*
            debug($id . ' Size = ' . sizeof($temp) . '');
            if (sizeof($temp) == 3 && !(strpos($temp[1],"ponents") !== false )) {  // this is where wormholes are updated as long as they aren't an opponent (no adding)...keeping for now NOTED
                debug($temp[1].'---'.!(strpos($temp[1],"ponents") !== false) . 'We think this is a Wormhole');
                if ($temp[2] != 'unknown') {
                    $db->query('UPDATE ' . $uni . '_Maps SET `wormhole` = \'' . $temp[2] . '\' WHERE id = ' . $id);
                    ++$sqlcount; // Counting SQL iterations per connection
                }
                $db->query ('UPDATE ' . $uni . '_Maps SET `fg` = \'' . $temp[$r_fg] . '\' WHERE id = ' . $id);
                ++$sqlcount; // Counting SQL iterations per connection
                $db->query ('UPDATE ' . $uni . '_Maps SET `npc` = NULL WHERE id = ' . $id);
                ++$sqlcount; // Counting SQL iterations per connection
                $r->wormhole = $temp[2];
                continue;
            } 
            if ($r->wormhole) { continue; }
            */
        if ($r_fg != 0) {
            // Check to see if we have Foreground information for the current tile
            // If we do not then we need to double check for existing info and remove it.
            debug($id . ' Building information exists for current location');
            // Check to See if the DB is NULL
            if (is_null($r->fg)) {
                // DB is NULL Just Add new Info
                debug('Adding Building');
                DB::building_add(universe: $uni, image: $temp[$r_fg], id: $id, sb: 0);
                ++$sqlcount; // Counting SQL iterations per connection
            } else if (sizeof($temp) != 3) { // this isn't an NPC record from the non-blocking window
                //Test to See if Map and DB match
                debug('Testing New FG - ' . str_replace("_tradeoff", "", $temp[$r_fg]));
                debug('Testing DB FG - ' . str_replace("_tradeoff", "", $r->fg));
                if (str_replace("_tradeoff", "", $temp[$r_fg]) != str_replace("_tradeoff", "", $r->fg)) {
                    debug($id . ' Foreground info Does Not Matches DB');
                    // See if we have a Gem merchant
                    if (str_contains($temp[$r_fg], "gem_merchant")) {
                        // Perform the query
                        $result = $db->execute(sprintf('SELECT * FROM %s_Maps WHERE fg = ? AND cluster = ?', $uni), [
                            'ss', $temp[$r_fg], $r->cluster
                        ]);

                        // Check for query errors
                        if (!$result) {
                            debug(sprintf('(3) Query failed: %s', $db->getDb()->error));
                            continue;
                        }


                        // Counting SQL iterations per connection
                        ++$sqlcount;

                        // Initialize an array to hold the results
                        $gems = [];

                        // Fetch each row as an object
                        while ($g = $db->fetchObject()) {
                            $gems[] = $g;
                        }

                        // Free the result set
                        $db->free();

                        // Process each gem
                        foreach ($gems as $g) {
                            DB::building_remove(universe: $uni, id: $g->id, sb: 0);
                            // Counting SQL iterations per connection
                            ++$sqlcount;
                        }
                    }
                    debug($id . ' Deleting Old Building');

                    DB::building_remove(universe: $uni, id: $id, sb: 0);

                    ++$sqlcount; // Counting SQL iterations per connection
                    debug($id . ' Inserting New Building');

                    DB::building_add(universe: $uni, image: $temp[$r_fg], id: $id, sb: 0);
                    ++$sqlcount; // Counting SQL iterations per connection
                } else {
                    debug($id . ' Foreground info Matches DB');
                    DB::map_update_fg(universe: $uni, image: $temp[$r_fg], id: $id);
                    ++$sqlcount; // Counting SQL iterations per connection
                    if ($temp[$r_fg] != $r->fg) {
                        debug($id . ' Foreground Image Changed');
                        DB::building_update(id: $id, params: ['image' => $temp[$r_fg]], universe: $uni);
                        ++$sqlcount; // Counting SQL iterations per connection
                    }
                }
            }
        } else {
            debug($id . ' No Foreground info to worry about');
        }
        debug('this is result ' . $r_npc . 'Temp 2 check');
        if ($r_npc != 0) { // an NPC is shown on this tile
            if (!is_null($r->fg) && (isset($temp[2]) && $temp[2] !== false)) { // we have an NPC on the map but not an NID, thus can't have a building or SB or we wouldn't see the NPC
                if (strpos($r->fg, "starbase")) {
                    DB::building_remove(universe: $uni, id: $id, sb: 1);
                    ++$sqlcount; // Counting SQL iterations per connection
                } else {
                    debug('Blowing Away a building');
                    DB::building_remove(universe: $uni, id: $id, sb: 0);
                    ++$sqlcount; // Counting SQL iterations per connection
                }
            }
            debug($id . ' Checking NPC data');
            if (in_array($id, $static)) {
                continue;
            }
            $npc = $temp[$r_npc];
            // An nID only ever comes from the pilot's own tile (the Other Ships list). Never take one
            // for any other tile: an NPC must not get another NPC's ID from a remote observation.
            $nid = ($id == $loc && isset($temp[2]) && ctype_digit((string) $temp[2])) ? (int) $temp[2] : null;

            // a building is recorded here and the NPC came without an ID: keep the building, skip the NPC
            if (is_null($r->npc) && !is_null($r->fg) && is_null($nid)) {
                debug($id . ' NPC seen on a building tile without an nID, skipping');
            } else {
                if (in_array($npc, $single) && $r->npc != $npc) {
                    // NPC is only one per cluster: it has left wherever it was recorded before
                    DB::npc_end_elsewhere(universe: $uni, cluster: (string) $r->cluster, image: $npc, exceptId: $id);
                    ++$sqlcount; // Counting SQL iterations per connection
                }
                // Record this NPC on the tile. Other NPCs recorded here are kept if they can share the
                // tile (Vyrex, Pirates: cloaked or hidden under a bigger NPC); see DB::npc_add.
                debug($id . ' NPC seen: ' . $npc . ' nid = ' . ($nid ?? 'none'));
                DB::npc_add(universe: $uni, image: $npc, id: $id, sector: null, x: $x, y: $y, nid: $nid);
                ++$sqlcount; // Counting SQL iterations per connection
            }
        } else {
            debug($id . ', - ' . $temp[$r_npc] . ', - ' . $r->npc . ' No NPC info to worry about');
        }
        if ($r_bg != 0) {
            //Check to see if we have Building information for the current tile
            if (!(is_null($r->fg))) {
                // We do have Building Information  //Need to check for Starbase functionality NOTED
                //if ($id == $_REQUEST['id']) { //Not sure what this check is doing as id is our current location which means we can only remove a building we are standing on? NOTED
                debug($id . ' Deleting Foreground info from DB');
                if (strpos($r->fg, "starbase")) {
                    DB::building_remove(universe: $uni, id: $id, sb: 1);
                    ++$sqlcount; // Counting SQL iterations per connection
                } else {
                    DB::building_remove(universe: $uni, id: $id, sb: 0);
                    ++$sqlcount; // Counting SQL iterations per connection
                }
                //}
            }
            if (!(is_null($r->npc))) { // NPCs are recorded here but the tile shows none
                if ($id == $loc) {
                    // The pilot is on this tile and sees no NPC: nothing is here, cloaked or not.
                    debug('Blow Away 3: pilot on the tile sees no NPC');
                    DB::npc_remove(universe: $uni, id: $id);
                    ++$sqlcount; // Counting SQL iterations per connection
                } else {
                    // Seen from another tile. Decide per NPC record:
                    // - Vyrex: may be cloaked, so only a pilot on the tile can clear it. Mark it cloaked.
                    // - other NPCs that can cloak (and don't roam): mark cloaked; clear after 5 days unseen.
                    // - everything else is gone.
                    $ended = [];
                    foreach (DB::npc_live(universe: $uni, id: $id) as $n) {
                        $img = (string) $n->image;
                        if (NPC::is_vyrex($img)) {
                            DB::npc_cloak(universe: $uni, kid: (int) $n->kid);
                        } elseif (in_array($img, $cloaked) && !in_array($img, $mobile)) {
                            if (empty($n->cloaked)) {
                                debug('NPC has cloaked', $img);
                                DB::npc_cloak(universe: $uni, kid: (int) $n->kid);
                            } elseif (strtotime((string) $n->today) - strtotime((string) $n->updated) > 432000) {
                                debug('Blow Away 4: cloaked for 5 days', $img);
                                $ended[] = (int) $n->kid;
                            }
                        } else {
                            debug('Blow Away 5: NPC gone', $img);
                            $ended[] = (int) $n->kid;
                        }
                        ++$sqlcount; // Counting SQL iterations per connection
                    }
                    DB::npc_end(universe: $uni, kids: $ended, sync: false);
                    DB::npc_sync_tile(universe: $uni, id: $id);
                    $sqlcount += 2; // Counting SQL iterations per connection
                }
            }
        }
    }
}
//}

++$sqlcount;
$time_post = microtime(true);
$exec_time = $time_post - $time_pre;
$db->execute("INSERT INTO connection_log (`universe`,`username`,`user_id`,`querycount`,`duration`, `date`, `payload`) VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP(), ?)", [
    'ssiidb', $uni, $user, $uid, $sqlcount, $exec_time, $dataString
]);
