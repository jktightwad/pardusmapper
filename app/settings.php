<?php
declare(strict_types=1);

ob_start();

define('ROOT', dirname(__DIR__));
date_default_timezone_set("UTC");

require_once(dirname(__DIR__) . '/vendor/autoload.php');

use Pardusmapper\Core\MySqlDB;
use Pardusmapper\Core\Environment;
use Pardusmapper\Core\Settings;
use Pardusmapper\Request;
use Pardusmapper\CORS;

Environment::load();
Settings::init();

// Keep pilots logged in. PHP's defaults end a login after 24 minutes without a page view (and on
// shared hosting the session folder is cleaned on that schedule), and the login cookie only lasts
// until the browser closes. Keep the site's sessions in their own folder outside the website, for
// 30 days. Every page loads this file before session_start(), so this covers the whole site.
const SESSION_DAYS = 30;
$sessionDir = dirname(ROOT) . '/pardusmapper_sessions';
if ((is_dir($sessionDir) || @mkdir($sessionDir, 0700, true)) && is_writable($sessionDir)) {
    session_save_path($sessionDir);
    // PHP only removes expired sessions from this folder itself, now and then
    ini_set('session.gc_probability', '1');
    ini_set('session.gc_divisor', '1000');
}
ini_set('session.gc_maxlifetime', (string) (SESSION_DAYS * 86400));
session_set_cookie_params([
    'lifetime' => SESSION_DAYS * 86400,
    'path' => '/',
    'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'httponly' => true,
]);

$testing = Settings::$TESTING;
$debug = Settings::$DEBUG;

// Load debug from REQUEST, if not valid, use $debug from settings
$debug = Request::debug(default: Settings::$DEBUG);

// if the request comes from Pardus.AT
// set initialize the MySQL instace with source PARDUS so it can use a different connect function
if (isset($_SERVER['HTTP_ORIGIN']) && preg_match('/^https?:\/\/(orion|artemis|pegasus)?\.pardus\.at$/i', (string) $_SERVER['HTTP_ORIGIN'])) {
    MySqlDB::instance(['source' => CORS::PARDUS]);
}

$base_url = Settings::$BASE_URL;
$img_url = Settings::$IMG_DIR;
// Override the $img_url if an image pack is used 
if (isset($_COOKIE['imagepack'])) {
	$img_url = rtrim((string) $_COOKIE['imagepack'], '/') . '/';
}

if ($testing) { $base_url .= '/TestMap'; }

$css = $base_url . '/resources/main.css';
$game_css = $base_url . '/resources/game.css';
$cluster_css = $base_url . '/resources/cluster.css';
$index_css = $base_url . '/resources/index.css';
$r_css = $base_url. '/resources/resources.css';
$n_css = $base_url. '/resources/npc.css';
$m_css = $base_url. '/resources/mission.css';

if ($testing || $debug) { 
	error_reporting(E_STRICT | E_ALL | E_NOTICE);
}

if (Settings::$SHOW_EXCEPTIONS) {
    ini_set('display_errors', '1');
	error_reporting(E_STRICT | E_ALL | E_NOTICE);
    set_exception_handler('mapper_exception_handler');
}
