<?php
declare(strict_types=1);

use Pardusmapper\Core\ApiResponse;
use Pardusmapper\Core\MySqlDB;
use Pardusmapper\CORS;
use Pardusmapper\DB;
use Pardusmapper\Request;

require_once('../app/settings.php');

CORS::pardus();

$db = MySqlDB::instance(); // Create an instance of the Database class

debug($_REQUEST);

// Set Univers Variable
$uni = Request::uni();
http_response(is_null($uni), ApiResponse::OK, sprintf('uni query parameter is required or invalid: %s', $uni ?? 'null'));

// Get Version
$minVersion = '5.8';
$version = Request::pstring(key: 'version', default: '0.0');
http_response(version_compare($version, $minVersion, '<'), ApiResponse::OK, sprintf('version query parameter is required or invalid: %s ... minumum version: %s', ($uni ?? 'null'), $minVersion));

// Get Location
// Building Main Page Variables
$loc = Request::pint(key: 'loc');
http_response(is_null($loc), ApiResponse::OK, sprintf('loc query parameter is required or invalid: %s', $loc ?? 'null'));

$image = Request::pstring(key: 'img');
$nid = Request::pint(key: 'nid');
$dead = Request::pbool('dead');

// Set Hull, Armor, Shield Levels
$hull = Request::pint(key: 'hull', default: 0);
$armor = Request::pint(key: 'armor', default: 0);
$shield = Request::pint(key: 'shield', default: 0);

http_response(is_null($image) || !str_starts_with($image, 'opponents/'), ApiResponse::OK, sprintf('img query parameter is required or invalid: %s', $image ?? 'null'));

// The pilot is on the tile fighting this NPC, so this is a direct sighting: find (or add) the
// record of this NPC only. Other NPCs on the tile (a Vyrex stack, a cloaked Vyrex) are left alone,
// except NPCs that cannot share a tile with it (see DB::npc_add). Its nID is filled in only if the
// record has none; a record's nID is never changed to a different NPC's.
$kid = DB::npc_add(universe: $uni, image: $image, id: $loc, sector: null, x: 0, y: 0, nid: $nid);
http_response(is_null($kid), ApiResponse::OK, sprintf('tile not found: %s', $loc));

if ($dead) {
    // End only the NPC that was killed. If nobody had recorded it, it was added just above, so
    // the kill still leaves a sighting in the history.
    debug('You killed it, good job...ending its record');
    DB::npc_end(universe: $uni, kids: [$kid], deleteMissions: true);
} else {
    debug('Updating Hull, Armor, and Shield');
    DB::npc_update_health(universe: $uni, kid: $kid, hull: $hull, armor: $armor, shield: $shield);
}
