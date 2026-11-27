<?php

declare(strict_types=1);
require_once('app/settings.php');

use Pardusmapper\Coordinates;
use Pardusmapper\Core\ApiResponse;
use Pardusmapper\Request;
use Pardusmapper\Session;
use Pardusmapper\DB;
use Pardusmapper\Core\Settings;

// Universe is required
$uni = Request::uni();
http_response(is_null($uni), ApiResponse::OK, sprintf('uni query parameter is required or invalid: %s', $uni ?? 'null'));

$cluster = Request::pstring(key: 'cluster');
$sector = Request::pstring(key: 'sector');
$x1 = Request::pint(key: 'x1');
$y1 = Request::pint(key: 'y1');


// Set Univers Variable and Session Name
session_name($uni);
session_start();

debug($_REQUEST);
debug($_SESSION);

$security = Session::pint(key: 'security', default: 0);
$rank = Session::pint(key: 'rank');
$comp = Session::pint(key: 'comp');
$faction = Session::pstring(key: 'faction');
$syndicate = Session::pstring(key: 'syndicate');

$s = null;
$c = null;

// do this before sector or it will mess with the template
// TODO: fix it at some point
if (isset($cluster)) {
    $c = DB::cluster(code: $cluster);
    $clusterCode = $c->code;
}

if (isset($sector)) {
    $s = DB::sector(sector: $sector);
    $c = DB::cluster(sector: $sector); // needed for side bar
    $cluster = $c->code;
}

$id = null;
if (!is_null($x1) && !is_null($y1)) {
    $id = Coordinates::getID($s->s_id, $s->rows, $x1, $y1);
}

$today = date("Y-m-d");
$mission_list = Settings::MISSIONS_LIST;
sort($mission_list);

if (Session::contributor(universe: $uni)) {
    require_once(templates('mission'));
} else {
    require_once(templates('notlogged'));
}
