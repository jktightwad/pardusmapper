<?php
declare(strict_types=1);

use Pardusmapper\Request;
use Pardusmapper\Session;
use Pardusmapper\Core\MySqlDB;
use Pardusmapper\DB;

require_once 'app/settings.php';

$uni = Request::uni();
if (!$uni) {
    header('Location: /');
    exit;
}

// FIX: Set session name and start BEFORE attempting to read security level
session_name($uni);
session_start();

$security = Session::pint(key: 'security', default: 0);

$error = '';

// FIX: Removed 'if (0 === $security)' wrapper to allow re-authentication 
// if a user switches universes without logging out.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user = Request::postString('username');
    $pass = Request::postString('password');

    if ($user && $pass) {
        $u = DB::user(universe: $uni, user: $user);

        if ($u && $u->password === sha1($pass)) {
            // Clear any old session data from previous universes
            session_regenerate_id(true);

            $_SESSION['user'] = $u->user;
            $_SESSION['user_id'] = $u->user_id;
            $_SESSION['id'] = $u->id;
            $_SESSION['security'] = $u->security;
            $_SESSION['login'] = $u->login;
            $_SESSION['loaded'] = $u->loaded;
            $_SESSION['faction'] = $u->faction;
            $_SESSION['syndicate'] = $u->syndicate;
            $_SESSION['rank'] = $u->rank;
            $_SESSION['comp'] = $u->comp;

            if ($u->imagepack) {
                setcookie('imagepack', $u->imagepack, time() + (86400 * 30), "/");
            }

            $db = MySqlDB::instance();
            $db->execute(
                sprintf('UPDATE %s_Users SET login = UTC_TIMESTAMP() WHERE id = ?', $uni),
                ['i', $u->id]
            );

            header("Location: /$uni/");
            exit;
        } else {
            $error = 'Invalid username or password.';
            session_destroy();
        }
    }
} elseif ($security > 0) {
    // If already validly logged into THIS specific universe, redirect to map
    header("Location: /$uni/");
    exit;
}

require_once 'templates/login.php';