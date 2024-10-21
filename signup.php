<?php

declare(strict_types=1);
require_once('app/settings.php');

/** @var string $base_url */

use Pardusmapper\Core\MySqlDB;
use Pardusmapper\Post;
use Pardusmapper\Request;
use Pardusmapper\DB;

$db = MySqlDB::instance();

// Set Univers Variable and Session Name
$uni = Request::uni();
if (is_null($uni)) {
    require_once(templates('landing'));
    exit;
}

session_name($uni);
session_start();

$name = Post::pstring(key: 'username');
$pwd = Post::pstring(key: 'password1');
$pwdCheck = Post::pstring(key: 'password2');
$url = null;
$invalidusr = 0;
$invalidpwd = 0;

$url = Request::pstring(key: 'url');
if (is_null($url)) {
    $url = $_SERVER['HTTP_REFERER'];
}

if (!is_null($name)) {
    $u = DB::user(username: $name, universe: $uni);

    if (!is_null($u)) {
        if ($u->password != sha1('n0p2ssword')) {
            $redirectURL = $base_url . '/' . $uni . '/login.php?alreadysignedup=1&url=' . $url;
            header("Location: {$redirectURL}");
        } else {
            if (!is_null($pwd)) {
                if ($pwd !== $pwdCheck) {
                    $invalidpwd = 1;
                    unset($_REQUEST['signup']);
                } else {
                    $db->execute(sprintf('UPDATE %s_Users SET password = ? WHERE username = ?', $uni), [
                        'ss', sha1($pwd), $name
                    ]);

                    $redirectURL = $base_url . '/' . $uni . '/login.php?signedup=1&url=' . $url;
                    header("Location: {$redirectURL}");
                }
            }
        }
    } else {
        $invalidusr = 1;
    }
}

$db->close();

require_once(templates('signup'));
