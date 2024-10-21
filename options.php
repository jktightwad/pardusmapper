<?php

declare(strict_types=1);
require_once('app/settings.php');

use Pardusmapper\Core\MySqlDB;
use Pardusmapper\DB;
use Pardusmapper\Request;

$db = new MySqlDB;

// Set Univers Variable and Session Name
$uni = Request::uni();
if (is_null($uni)) {
    require_once(templates('landing'));
    exit;
}

session_name($uni);
session_start();

$url = Request::pstring(key: 'url');
if (is_null($url)) {
    $url = $_SERVER['HTTP_REFERER'];
}

$image = Request::pstring(key: 'image');
$loc2 = Request::pint(key: 'loc2');
$loc3 = Request::pint(key: 'loc3');

debug($_SESSION);

$logged_in = 0;
if (isset($_SESSION['user'])) {
    $logged_in = 1;
}

if (!is_null($image)) {
    if ($logged_in) {
        $db->execute(sprintf('UPDATE %s_Users SET imagepack = ? WHERE username = ?', $uni), [
            'ss',
            ($loc2 . $loc3),
            $_SESSION['user']
        ]);
    }
    setcookie("imagepack", $loc2 . $loc3, time() + 60 * 60 * 24 * 365, "/");
}

$oldpwd = Request::pstring(key: 'oldpwd');
$newpwd1 = Request::pstring(key: 'newpwd1');
$newpwd2 = Request::pstring(key: 'newpwd2');

$invalidoldpwd = 0;

if (is_null($oldpwd)) {
    debug('Old Password was not entered');
    unset($_REQUEST['change']);
} else {
    debug('Old Password entered');
    $u = DB::user(username: $_SESSION['user'], universe: $uni);
    if (!is_null($u)) {
        if ($u->password != sha1($oldpwd)) {
            debug('Old Password did not match');
            $invalidoldpwd = 1;
            unset($_REQUEST['change']);
        }
    }
}

$invalidpwd = 0;
$newpwdnotentered = 0;
$newpwd = null;
if (!is_null($newpwd1)) {
    if ($newpwd1 !== $newpwd2) {
        debug('New Passwords do not Match');
        $invalidpwd = 1;
        unset($_REQUEST['change']);
    } else {
        debug('New Passwords Match');
        $newpwd = $newpwd1;
    }
} else {
    debug('New Password not set');
    $newpwdnotentered = 1;
    unset($_REQUEST['change']);
}


if (isset($_REQUEST['change']) && !is_null($newpwd)) {
    $db->execute(sprintf('UPDATE %s_Users SET password = ? WHERE username = ?', $uni), [
        'ss', sha1($newpwd), $_SESSION['user'],
    ]);
    $db->close();
    header("Location: {$url}");
} else {

    debug('Change not Set');
    $db->close();

    require_once(templates('options'));
}
