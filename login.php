<?php

declare(strict_types=1);
require_once('app/settings.php');

/** @var string $base_url */
/** @var string $debug */

use Pardusmapper\Core\MySqlDB;
use Pardusmapper\Request;
use Pardusmapper\Post;
use Pardusmapper\Session;
use Pardusmapper\DB;

$dbClass = MySqlDB::instance(); // Create an instance of the Database class

// Set Univers Variable and Session Name
$uni = Request::uni();

if (is_null($uni)) {
    require_once(templates('landing'));
    exit;
}

$security = Session::pint(key: 'security', default: 0);
$url = Request::pstring(key: 'url');

session_name($uni);
session_start();

if (isset($_REQUEST['login'])) {
    if (0 === $security) {
        $name = Post::pstring(key: 'username');
        $pwd = Post::pstring(key: 'password');

        debug($name, sha1($pwd));

        if (!isset($name) || !isset($pwd)) {
            session_destroy();
        } else {
            $u = DB::user(username: $name, universe: $uni);
            debug($u);

            if (is_null($u) || strcmp($u->password, sha1($pwd)) != 0) {
                session_destroy();
            } else {
                debug('Creating Session Variables');
                session_regenerate_id(true);
                $_SESSION['user'] = $u->username;
                if ($u->user_id) {
                    $_SESSION['user_id'] = $u->user_id;
                }
                if ($u->id) {
                    $_SESSION['id'] = $u->id;
                }
                if ($u->security) {
                    $_SESSION['security'] = $u->security;
                }
                if ($u->login) {
                    $_SESSION['login'] = $u->login;
                }
                if ($u->loaded) {
                    $_SESSION['loaded'] = $u->loaded;
                }
                if ($u->faction) {
                    $_SESSION['faction'] = $u->faction;
                }
                if ($u->syndicate) {
                    $_SESSION['syndicate'] = $u->syndicate;
                }
                if ($u->rank) {
                    $_SESSION['rank'] = $u->rank;
                }
                if ($u->comp) {
                    $_SESSION['comp'] = $u->comp;
                }
                if ($u->imagepack) {
                    setcookie("imagepack", $u->imagepack, time() + 60 * 60 * 24 * 365, "/");
                }
                $dbClass->execute(sprintf('UPDATE %s_Users SET login = UTC_TIMESTAMP() WHERE LOWER(username) = ?', $uni), [
                    's', $name
                ]);
            }
        }
    }
    session_write_close();
    debug($_SESSION);
    debug($url);
    if (strpos($url, $base_url) === false) {
        $url = $base_url . '/' . $uni . '/index.php';
    }
    if (!$debug) {
        header("Location: $url");
    }
} else {
    $signedup = 0;
    $alreadysignedup = 0;
    $url = null;
    if (isset($_REQUEST['signedup'])) {
        $signedup = 1;
    }
    if (isset($_REQUEST['alreadysignedup'])) {
        $alreadysignedup = 1;
    }
    if (is_null($url)) {
        $url = $_SERVER['HTTP_REFERER'];
    }

    require_once(templates('login'));
}
