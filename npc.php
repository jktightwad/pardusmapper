<?php

declare(strict_types=1);
require_once('app/settings.php');

use Pardusmapper\Core\ApiResponse;
use Pardusmapper\DB;
use Pardusmapper\Request;
use Pardusmapper\Session;

// Set Univers Variable and Session Name
$uni = Request::uni();
http_response(is_null($uni), ApiResponse::OK, sprintf('uni query parameter is required or invalid: %s', $uni ?? 'null'));

$cluster = Request::pstring(key: 'cluster');
$sector = Request::pstring(key: 'sector');

session_name($uni);
session_start();

$security = Session::pint(key: 'security', default: 0);

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

$today = date("Y-m-d");

if (Session::contributor(universe: $uni)) {
    require_once(templates('npc'));
} else {
    require_once(templates('notlogged'));
}
