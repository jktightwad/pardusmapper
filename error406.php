<?php
declare(strict_types=1);

/**
 * Handler for HTTP 406 responses (wired up via "ErrorDocument 406" in .htaccess).
 *
 * The host's ModSecurity firewall rejects any URL *path* containing "cp " followed
 * by text, because it looks like the shell copy command. That blocks pretty URLs for
 * the sector "CP 2-197" (e.g. /Orion/CP%202-197) before .htaccess or PHP ever run.
 * The same request passes when the sector is sent as a query string, so for blocked
 * requests that match one of our sector routes we redirect to the query-string form.
 *
 * Routes mirror the "Sector" rules in .htaccess. Anything else keeps the 406.
 *
 * Deliberately standalone: no app/settings.php, no database, so it cannot fail on
 * anything other than its own code.
 */

const UNIVERSES = ['Orion', 'Artemis', 'Pegasus'];
const NAME_PATTERN = "/^[A-Za-z0-9 .'\\-]{1,40}$/";
const COORD_PATTERN = '/^[0-9]{1,2}$/';

/**
 * Work out the query-string URL for a blocked pretty URL.
 *
 * @return string|null redirect target (relative to the site root), or null if no route matches
 */
function pm_406_target(string $requestUri): ?string
{
    $path = (string) parse_url($requestUri, PHP_URL_PATH);
    $parts = array_map('rawurldecode', explode('/', trim($path, '/')));

    if (count($parts) < 2 || !in_array($parts[0], UNIVERSES, true)) {
        return null;
    }

    $uni = array_shift($parts);
    $sector = array_shift($parts);

    if (!preg_match(NAME_PATTERN, $sector)) {
        return null;
    }

    $params = ['uni' => $uni, 'sector' => $sector];
    $isCoord = static fn (?string $v): bool => $v !== null && preg_match(COORD_PATTERN, $v) === 1;

    switch (count($parts)) {
        case 0: // /Uni/Sector
            $script = 'game.php';
            break;

        case 1: // /Uni/Sector/{npc|resources|mission}
            $script = match ($parts[0]) {
                'npc' => 'npc.php',
                'resources' => 'resources.php',
                'mission' => 'mission.php',
                default => null,
            };
            break;

        case 2: // /Uni/Sector/resources/Pilot or /Uni/Sector/x/y
            if ($parts[0] === 'resources' && preg_match(NAME_PATTERN, $parts[1])) {
                $script = 'presources.php';
                $params['pilot'] = $parts[1];
            } elseif ($isCoord($parts[0]) && $isCoord($parts[1])) {
                $script = 'game.php';
                $params['x1'] = $parts[0];
                $params['y1'] = $parts[1];
            } else {
                $script = null;
            }
            break;

        case 3: // /Uni/Sector/x/y/{mission|flyclose}
            $script = null;
            if ($isCoord($parts[0]) && $isCoord($parts[1])) {
                if ($parts[2] === 'mission') {
                    $script = 'mission.php';
                    $params['x1'] = $parts[0];
                    $params['y1'] = $parts[1];
                } elseif ($parts[2] === 'flyclose') {
                    $script = 'game.php';
                    $params['x2'] = $parts[0];
                    $params['y2'] = $parts[1];
                }
            }
            break;

        default:
            $script = null;
    }

    if ($script === null) {
        return null;
    }

    return '/' . $script . '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
}

// Only act when run as an error page, not when included by a test.
if (PHP_SAPI !== 'cli') {
    $target = pm_406_target((string) ($_SERVER['REQUEST_URI'] ?? ''));

    header('Cache-Control: no-store');

    if ($target !== null) {
        // 302 (temporary) so browsers don't cache it; if the host later whitelists
        // the rule, the pretty URL works directly and this handler is never reached.
        http_response_code(302);
        header('Location: ' . $target);
        exit;
    }

    http_response_code(406);
    header('Content-Type: text/html; charset=UTF-8');
    echo '<!DOCTYPE html><html><head><title>Not Acceptable</title></head><body>'
        . '<h1>Not Acceptable</h1><p>This request was blocked by the server.</p>'
        . '</body></html>';
}
