<?php 
declare(strict_types=1);
require_once('../app/settings.php');

/** @var string $base_url */

use Pardusmapper\Core\ApiResponse;
use Pardusmapper\CORS;
use Pardusmapper\DB;
use Pardusmapper\Post;
use Pardusmapper\Session;

CORS::mapper();

$uni = Post::uni();
http_response(is_null($uni), ApiResponse::OK, sprintf('uni query parameter is required or invalid: %s', $uni ?? 'null'));

session_name($uni);
session_start();

$security = Session::pint(key: 'security', default: 0);

$sector = DB::sectors_static();

//$return = "<table style=\"height:100px; overflow-y:auto\">";
$return = '<table>';
$return .= '<tr>';
$return .= '<th>Sector</th>';
$return .= '<th>Cluster</th>';
$return .= '</tr>';

$i = 0;
foreach ($sector as $key => $s) {
    //$c = $s[0];
    //$g = $s[1];
                
    if ($i++ % 2 == 0) {
        $return .= '<tr class="alternating">';
    } else {
        $return .= '<tr>';
    }
    $return .= '<td align="center">';
        //$return .= $s->name;
        $return .= '<a href="' . $base_url . '/' . $uni . '/' . $s->name . '">' . $s->name . '</a>';
    $return .= '</td>';
    $return .= '<td align="center">';
        // cluster pages are at /{Universe}/{CODE} (e.g. /Pegasus/GAP); the cluster's name would be
        // taken for a sector
        $code = strtoupper((string) $s->cluster);
        $return .= $code !== ''
            ? '<a href="' . $base_url . '/' . $uni . '/' . htmlspecialchars($code) . '">' . htmlspecialchars((string) $s->c_name) . '</a>'
            : htmlspecialchars((string) $s->c_name);
    $return .= '</td>';

    $return .= '</tr>';
}
$return .= '</table>';
echo $return;
