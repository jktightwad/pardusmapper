<?php

declare(strict_types=1);
require_once('app/settings.php');

use Pardusmapper\Core\MySqlDB;
use Pardusmapper\Request;
use Pardusmapper\Post;
use Pardusmapper\Session;
use Pardusmapper\DB;

$dbClass = MySqlDB::instance();

// Set Universe Variable
$uni = Request::uni();

if (is_null($uni)) {
    require_once(templates('landing'));
    exit;
}

// FIX: Initialize session BEFORE reading values from it
session_name($uni);
session_start();

$security = Session::pint(key: 'security', default: 0);
$url = Request::pstring(key: 'url');

if (isset($_REQUEST['login'])) {
    // FIX: Removed 'if (0 === $security)' to allow switching universes
    $name = Post::pstring(key: 'username');
    $pwd = Post::pstring(key: 'password');

    if (!isset($name) || !isset($pwd)) {
        session_destroy();
    } else {
        $u = DB::user(username: $name, universe: $uni);

        if (is_null($u) || strcmp($u->password, sha1($pwd)) != 0) {
            session_destroy();
        } else {
            session_regenerate_id(true);
            $_SESSION['user'] = $u->username;
            if ($u->user_id) { $_SESSION['user_id'] = $u->user_id; }
            if ($u->id) { $_SESSION['id'] = $u->id; }
            if ($u->security) { $_SESSION['security'] = $u->security; }
            if ($u->login) { $_SESSION['login'] = $u->login; }
            if ($u->loaded) { $_SESSION['loaded'] = $u->loaded; }
            if ($u->faction) { $_SESSION['faction'] = $u->faction; }
            if ($u->syndicate) { $_SESSION['syndicate'] = $u->syndicate; }
            if ($u->rank) { $_SESSION['rank'] = $u->rank; }
            if ($u->comp) { $_SESSION['comp'] = $u->comp; }
            
            if ($u->imagepack) {
                setcookie("imagepack", $u->imagepack, time() + 60 * 60 * 24 * 365, "/");
            }
            
            $dbClass->execute(sprintf('UPDATE %s_Users SET login = UTC_TIMESTAMP() WHERE LOWER(username) = ?', $uni), [
                's', $name
            ]);
        }
    }
    
    session_write_close();
    
    // Ensure the redirect URL is valid
    if (strpos((string)$url, (string)$base_url) === false) {
        $url = $base_url . '/' . $uni . '/index.php';
    }
    
    if (!$debug) {
        header("Location: $url");
        exit;
    }
} else {
    // Serve the login form if not processing a login request
    $signedup = isset($_REQUEST['signedup']) ? 1 : 0;
    $alreadysignedup = isset($_REQUEST['alreadysignedup']) ? 1 : 0;
    
    if (!isset($url)) {
        $url = $_SERVER['HTTP_REFERER'] ?? null;
    }

    require_once(templates('login'));
}
