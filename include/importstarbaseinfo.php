<?php
declare(strict_types=1);

use Pardusmapper\Coordinates;
use Pardusmapper\Core\ApiResponse;
use Pardusmapper\Core\MySqlDB;
use Pardusmapper\CORS;
use Pardusmapper\Request;
use Pardusmapper\DB;

require_once('../app/settings.php');

// Enable CORS for any subdomain of pardus.at
CORS::pardus_extended();

$db = MySqlDB::instance(); // Create an instance of the Database class

debug($_REQUEST);

// Set Univers Variable and Session Name
$uni = Request::uni();
http_response(is_null($uni), ApiResponse::OK, sprintf('uni query parameter is required or invalid: %s', $uni ?? 'null'));

// Get Version
$minVersion = '5.8';
$version = Request::pstring(key: 'version', default: '0.0');
http_response(version_compare($version, $minVersion, '<'), ApiResponse::OK, sprintf('version query parameter is required or invalid: %s ... minumum version: %s', ($uni ?? 'null'), $minVersion));

// Starbase Main Page Variables
$loc = Request::pint(key: 'loc');
http_response(is_null($loc), ApiResponse::OK, sprintf('location(loc) query parameter is required or invalid: %s', $loc ?? 'null'));

$name = Request::pstring(key: 'name');
$image = Request::pstring(key: 'img');
$faction = Request::pstring(key: 'faction');
$owner = Request::pstring(key: 'owner');
$alliance = Request::pstring(key: 'alliance');
$pop = Request::pint(key: 'pop');
$crime = Request::pint(key: 'crime');

// Trade Page Variables (Additional)
$credit = Request::pint(key: 'credit');

// Starbase Building Page Variables (additional)
$x = Request::pint(key: 'x');
$y = Request::pint(key: 'y');
$condition = Request::pint(key: 'condition');

// Extra request data
$sb = Request::pbool(key: 'sb');   // visited SB
$sbb = Request::pbool(key: 'sbt');    // visited SB building
$sbt = Request::sbt();  // SB trade data
$fs = Request::pint(key: 'fs', default: 0);    // free space
$squads = Request::squads();  // SB squadrons


// Get Map information
$m = DB::map(id: $loc, universe: $uni);
// Handle query error
if (false === $m) {
    debug("Query failed: " . MySqlDB::instance()->getDb()->error);
}
// Stop of map not ofund
http_response(is_null($m), ApiResponse::OK, sprintf('map not found for location: %s', $loc));

debug('Got Map Data', $m);

// Verify Building is already in DB Tables Add if Not
// Perform the query to fetch building information
$b = DB::building(id: $loc, universe: $uni);
if ($b) {
    // Building in DB, Verify Stock is in DB
    $stocks = DB::stocks(id: $loc, universe: $uni);
    if (0 === count($stocks)) {
        DB::building_stock_add(universe: $uni, image: $m->fg, id: $loc);
    }
} else {
    // Building not in DB
    DB::building_add(universe: $uni, image: $image, id: $loc, sb: 1);

    // After we add a buildimg, load the object
    $b = DB::building(id: $loc, universe: $uni);
}

debug('Got Building Info', $b);


// Get Sector and Cluster Information from Location
$s = DB::sector(id: $loc);
http_response(is_null($s), ApiResponse::OK, sprintf('sector not found for location: %s', $loc));

$c = DB::cluster(id: $s->c_id);
http_response(is_null($s->c_id), ApiResponse::OK, sprintf('cluster not found from sector location: %s', $s->c_id));

$updateBuilding = [];


// Double Check that Cluster and Sector have been Set for the Building
if (is_null($b->cluster) || is_null($b->sector)) {
    $updateBuilding['cluster'] = $c->name;
    $updateBuilding['sector'] = $s->name;
}

if ($sb) {
    //Visited Starbase
    debug('Visited Starbase');

    // Collect Info

    // Update DB with common SB info
    if (!$b->x && !$b->y) {
        $x = Coordinates::getX($loc, $s->s_id, $s->rows);
        $y = Coordinates::getY($loc, $s->s_id, $s->rows, $x);

        $updateBuilding['x'] = $x;
        $updateBuilding['y'] = $y;
    }

    $updateBuilding['faction'] = $faction;
    $updateBuilding['alliance'] = $alliance;

    if (isset($owner)) {
        debug('Updating owner');
        $updateBuilding['owner'] = $owner;
    }

    if (isset($alliance)) {
        debug('Updating alliance');
    } else {
        debug('Nulling alliance');
    }
    if (isset($faction)) {
        debug('Updating faction');
    } else {
        debug('Nulling faction');
    }

    $updateBuilding['name'] = $name;
    $updateBuilding['image'] = $image;
    $updateBuilding['population'] = (int)$pop;
    $updateBuilding['crime'] = $crime;

    // moved this at the end to have the correct timestamp
    DB::building_update(id: $loc, params: $updateBuilding, universe: $uni);
}

if (count($sbt) > 0) {
    //Visited a Starbase
    debug('Visited a Starbase Trade');

    $updateBuildingStock = [];

    //Collect Info
    if ($fs > 0) {
        $cap = $fs;
        $updateBuildingStock['freespace'] = $fs;
    } else {
        $cap = 0;
    }

    $building_stock_level = 0;
    $building_stock_max = 0;
    
    // Loop through all sbt data
    for ($i = 1; $i < count($sbt); $i++) {
        $temp = explode(',', (string) $sbt[$i]);
        debug($temp);

        $temp[1] = str_replace(',', '', $temp[1]); // Remove commas from the second element
        $cap += $temp[1];
        $res = $temp[0];
        
        // Execute the query
        $u = DB::upkeep_static(fg: 'foregrounds/starbase_f0_s1.png', res: $res); // any SB image

        if ($u !== null && isset($u->upkeep)) {
            if ($u->upkeep) {
                $building_stock_level += $temp[1];
                $building_stock_max += $temp[4];
            }
        }
        $stock = 0;
        if ($temp[4]) {
            $stock = round(($temp[1] / $temp[4]) * 100, 0);
            if ($stock > 100) {
                $stock = 100;
            }
        }

        debug('Stocking for ' . $temp[0] . ' = ' . $stock);

        $stocks = DB::stocks(id: $loc, name: $temp[0], universe: $uni);
        if (0 === count($stocks)) {
            DB::stock_create(id: $loc, name: $temp[0], universe: $uni);
        }

        $updateStock = [];
        $updateStock['amount'] = (int)$temp[1];
        $updateStock['bal'] = (int)$temp[2];
        $updateStock['min'] = (int)$temp[3];
        $updateStock['max'] = (int)$temp[4];
        $updateStock['buy'] = (int)$temp[5];
        $updateStock['sell'] = (int)$temp[6];
        $updateStock['stock'] = (int)$stock;
        DB::stock_update(id: $loc, name: $temp[0], params: $updateStock, universe: $uni);
    }

    $updateBuildingStock['capacity'] = (int)$cap;
    $updateBuildingStock['credit'] = (int)$credit;

    // Set Building Stock level
    if ($building_stock_max) {
        $building_stock_level = round(($building_stock_level / $building_stock_max) * 100, 0);
        if ($building_stock_level > 100) {
            $building_stock_level = 100;
        }
    }

    debug('Building Stock Level ' . $building_stock_level);
    $updateBuildingStock['stock'] = (int)$building_stock_level;

    DB::building_stock_update(id: $loc, params: $updateBuildingStock, universe: $uni);
}

if (count($squads) > 0) {
    //Visted Squadrons at a Player SB

    // Erase old Squad info from DB.
    $db->execute(sprintf('DELETE FROM %s_Squadrons WHERE id = ?', $uni), [
        'i', $loc
    ]);

    for ($i = 0; $i < sizeOf($squads); $i++) {
        $temp = explode(',', (string) $squads[$i]);

        $db->execute(sprintf('INSERT INTO %s_Squadrons (`image`,`type`,`weapons`,`credit`,`date`,`id`) VALUES (?, ?, ?, ?, UTC_TIMESTAMP(), ?)', $uni), [
            'ssiii', $temp[0], $temp[1], $temp[2], $temp[3], $loc
        ]);
    }

    $db->execute(sprintf('UPDATE %s_Squadrons SET cluster = ?, sector = ?, x = ?, y = ? WHERE id = ?', $uni), [
        'ssiii', $c->name, $s->name, $b->x, $b->y, $loc
    ]);
}

if ($sbb) {
    //Visited SB Building
    debug('Visited SB Building');

    //Collect Info
    $updateBuilding['name'] = $name;
    $updateBuilding['image'] = $image;
    $updateBuilding['condition'] = (int)$condition;

    $updateBuilding['faction'] = $faction;
    $updateBuilding['alliance'] = $alliance;

    if (isset($owner)) {
        debug('Updating owner');
        $updateBuilding['owner'] = $owner;
    }

    if (isset($alliance)) {
        debug('Updating alliance');
    } else {
        debug('Nulling alliance');
    }
    if (isset($faction)) {
        debug('Updating faction');
    } else {
        debug('Nulling faction');
    }

    DB::building_update(id: $loc, params: $updateBuilding, universe: $uni);
}
