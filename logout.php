<?php

declare(strict_types=1);
require_once('app/settings.php');

/** @var string $base_url */

use Pardusmapper\Core\MySqlDB;
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

$name = $_SESSION['user'] ?? null;
if (!is_null($name)) {
    $db->execute(sprintf('UPDATE %s_Users SET logout = UTC_TIMESTAMP() WHERE username = ?', $uni), [
        's', $name
    ]);
}

$db->close();

session_destroy();
session_write_close();
$url = $base_url . '/' . $uni . '/index.php';
header("Location: $url");
