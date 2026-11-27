<?php
declare(strict_types=1);

namespace Pardusmapper;

use Pardusmapper\Core\MySqlDB;
use Pardusmapper\Core\Settings;

class Session
{
    /**
     * Returns protected string from $_SESSION
     *
     * @param string $key
     * @param string|null $default
     * @return string|null
     */
    public static function pstring(string $key, ?string $default = null): ?string
    {
        $value = vstring(($_SESSION[$key] ?? null), $default);

        if (!is_null($value)) {
            $value = MySqlDB::instance()->protect($value);
        }

        debug(sprintf('%s: %s', $key, $value));

        return $value;
    }

    /**
     * Returns protected int from $_SESSION
     *
     * @param string $key
     * @param int|null $default
     * @return int|null
     */
    public static function pint(string $key, ?int $default = null): ?int
    {
        $value = vint(($_SESSION[$key] ?? null), $default);

        debug(sprintf('%s: %s', $key, $value));

        return $value;
    }

    /**
     * Returns protected bool from $_SESSION
     *
     * @param string $key
     * @param bool $default
     * @return bool
     */
    public static function pbool(string $key, bool $default = false): bool
    {
        $value = vbool(($_SESSION[$key] ?? null), $default);

        debug(sprintf('%s: %s', $key, $value ? 'true' : 'false'));

        return $value;
    }

    /**
     * Is the logged-in user an active contributor in this universe?
     *
     * Elevated pages (NPC List, Mission List) are only for pilots whose user script has
     * sent data from this universe recently. The `loaded` timestamp is updated in
     * {Universe}_Users by include/extensionloaded.php whenever the script reports in.
     *
     * Read it fresh from the database on every call rather than trusting the copy saved
     * in the session at login. Otherwise a pilot who logs in first and plays afterwards
     * (e.g. after switching universes) stays locked out until they log out and back in.
     *
     * @param string $universe
     * @param int $maxAgeSeconds
     * @return bool
     */
    public static function contributor(string $universe, int $maxAgeSeconds = 172800): bool
    {
        $username = vstring($_SESSION['user'] ?? null);
        if (is_null($username)) {
            return false;
        }

        // DB::user matches on LOWER(username), so pass it lowercased (works regardless of collation)
        $u = DB::user(universe: $universe, username: strtolower($username));
        if (is_null($u) || empty($u->loaded)) {
            return false;
        }

        // keep the session copy current for anything else that reads it
        $_SESSION['loaded'] = $u->loaded;

        // Same comparison as before (start of today UTC minus last report), so the access
        // window is unchanged; only the source of `loaded` is different.
        return (strtotime(date('Y-m-d')) - strtotime((string) $u->loaded)) < $maxAgeSeconds;
    }

    /**
     * Returns protected float from $_SESSION
     *
     * @param string $key
     * @param float|null $default
     * @return float|null
     */
    public static function pfloat(string $key, ?float $default = null): ?float
    {
        $value = vfloat(($_SESSION[$key] ?? null), $default);

        debug(sprintf('%s: %s', $key, $value));

        return $value;
    }
}