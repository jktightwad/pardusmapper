<?php
declare(strict_types=1);

/**
 * Feral Serpent flight paths on the universe map. Included from templates/npcstats.php.
 *
 * @var array $stats      ['flight' => FlightPaths::build(), 'layout' => UniverseMap::layout(), 'u', 'nid', 'slug']
 * @var string $uni
 * @var string $statsUrl
 * @var callable $h, $when, $utcAttr, $utcText, $sectorUrl
 */

use Pardusmapper\Core\Settings;
use Pardusmapper\DB;
use Pardusmapper\FlightPaths;
use Pardusmapper\UniverseMap;

$flight = $stats['flight'];
$pos = $stats['layout']['sectors'];
$mapUni = $stats['u'] ?? $uni; // sector links open in this universe
$base = $statsUrl . '?npc=' . FlightPaths::SLUG . '&flight=1';

// Sectors that appear in the data and on the map
$visited = array_filter(array_keys($flight['sectors']), static fn ($s) => isset($pos[$s]));
$unplaced = array_diff(array_keys($flight['sectors']), $visited);
?>
				<p class="note"><b>Universe:</b>
					<?= is_null($stats['u']) ? '<b>All</b>' : '<a href="' . $h($base) . '">All</a>' ?>
					<?php foreach (Settings::UNIVERSE as $fu) { ?> | <?= ($stats['u'] === $fu && is_null($stats['nid'])) ? '<b>' . $h($fu) . '</b>' : '<a href="' . $h($base . '&u=' . $fu) . '">' . $h($fu) . '</a>' ?><?php } ?>
					<?php if ($stats['u'] && $stats['nid']) { ?> &nbsp; Showing serpent ID <b><?= $h($stats['nid']) ?></b> only.<?php } ?></p>

<?php if (count($visited) === 0) { ?>
				<p class="note">No Feral Serpent sightings to show yet.</p>
<?php } else {
    // Crop to the visited sectors plus a margin
    $xs = []; $ys = []; $cell = 0;
    foreach ($visited as $s) { $xs[] = $pos[$s]['x']; $ys[] = $pos[$s]['y']; $cell = max($cell, $pos[$s]['w'], $pos[$s]['h']); }
    $margin = $cell * 2;
    $vx = max(0, min($xs) - $margin);
    $vy = max(0, min($ys) - $margin);
    $vw = max(max($xs) + $margin - $vx, 500);
    $vh = max(max($ys) + $margin - $vy, 320);
    $inView = static fn (array $p): bool => $p['x'] >= $vx - $p['w'] && $p['x'] <= $vx + $vw + $p['w'] && $p['y'] >= $vy - $p['h'] && $p['y'] <= $vy + $vh + $p['h'];

    // Wormhole network (cluster minimaps + recorded wormholes), drawn faintly underneath
    $links = [];
    foreach ($stats['layout']['links'] as [$a, $b]) { $links[UniverseMap::linkKey($a, $b)] = [$a, $b]; }
    foreach (DB::wormhole_links() as [$a, $b]) { $links[UniverseMap::linkKey($a, $b)] = [$a, $b]; }

    $flights = 0; $jumpsSeen = 0;
    foreach ($flight['moves'] as $m) { if ($m['type'] === 'flight') { $flights += $m['count']; } else { $jumpsSeen += $m['count']; } }
?>
				<p class="note">Based on <b><?= $flight['records'] ?></b> sightings, making <b><?= $flight['flybys'] ?></b> flyby<?= $flight['flybys'] === 1 ? '' : 's' ?>
					and <b><?= $flight['chains'] ?></b> observed chain<?= $flight['chains'] === 1 ? '' : 's' ?> of 2 or more sectors:
					<b><?= $flights ?></b> flight<?= $flights === 1 ? '' : 's' ?> between linked sectors and <b><?= $jumpsSeen ?></b> jump<?= $jumpsSeen === 1 ? '' : 's' ?>.</p>
				<p class="note">The serpent flies fast. Sightings in the same sector less than <?= FlightPaths::FLYBY_GAP / 60 ?> minutes apart are one <b>flyby</b>; later than that it has come round again.
					A <b>chain</b> is what pilots saw of one flight: flybys in different sectors, each at most <?= FlightPaths::LINK_WINDOW / 60 ?> minutes after the last. After a longer gap it could have gone anywhere, so no arrow is drawn.
					The universes are copies and the serpent flies the same routes in each, so the arrows combine the chains of all universes and all times.
					Each direction has its own arrow, and thicker arrows were seen more often.
					A <b>jump</b> (dashed purple) is a move to a sector with no wormhole link in that short time: a teleport, or a sector nobody saw it cross.
					Hover an arrow or sector for details. Times are your local time.</p>
				<div class="legend">
					<span><span class="sw" style="background:#ff8c1a"></span>Flight (linked sectors)</span>
					<span><span class="sw" style="background:#b06cf0"></span>Jump (teleport or unseen sector)</span>
					<span><span class="sw" style="border:2px solid #00e5ff"></span>Seen there now</span>
					<span><span class="sw" style="border:2px dashed #b06cf0"></span>Known teleport sector</span>
				</div>
				<p class="note">Scroll on the map to zoom in (e.g. on FRC), drag to move around, double-click to reset.</p>
				<svg id="flightmap" class="flightmap" viewBox="<?= sprintf('%.0f %.0f %.0f %.0f', $vx, $vy, $vw, $vh) ?>" xmlns="http://www.w3.org/2000/svg" style="width:100%;max-width:<?= (int) min(1100, $vw) ?>px">
					<defs>
						<marker id="fm-arrow-flight" viewBox="0 0 10 10" refX="9" refY="5" markerUnits="userSpaceOnUse" markerWidth="9" markerHeight="9" orient="auto-start-reverse"><path d="M0 0L10 5L0 10z" fill="#ff8c1a"/></marker>
						<marker id="fm-arrow-jump" viewBox="0 0 10 10" refX="9" refY="5" markerUnits="userSpaceOnUse" markerWidth="11" markerHeight="11" orient="auto-start-reverse"><path d="M0 0L10 5L0 10z" fill="#b06cf0"/></marker>
					</defs>
					<rect x="<?= $vx ?>" y="<?= $vy ?>" width="<?= $vw ?>" height="<?= $vh ?>" fill="#081116"/>
<?php
    foreach ($links as [$a, $b]) {
        if (!isset($pos[$a], $pos[$b]) || !($inView($pos[$a]) || $inView($pos[$b]))) { continue; }
        printf('<line x1="%.1f" y1="%.1f" x2="%.1f" y2="%.1f" class="fm-link"/>', $pos[$a]['x'], $pos[$a]['y'], $pos[$b]['x'], $pos[$b]['y']);
    }

    foreach ($pos as $name => $p) {
        if (!$inView($p)) { continue; }
        $sec = $flight['sectors'][$name] ?? null;
        $w = $p['w'] * 0.72; $hh = $p['h'] * 0.62;
        $class = 'fm-sector' . ($sec ? ' fm-visited' : '') . (($sec['active'] ?? false) ? ' fm-live' : '') . (in_array($name, FlightPaths::KNOWN_TELEPORTS, true) ? ' fm-teleport' : '');
        $title = $name;
        if ($sec) {
            $byUni = [];
            foreach (Settings::UNIVERSE as $u) { if (isset($sec['unis'][$u])) { $byUni[] = $u . ' ' . $sec['unis'][$u]; } }
            $title .= sprintf(': %d flyby%s (%s), last seen %s%s', $sec['flybys'], $sec['flybys'] === 1 ? '' : 's', implode(', ', $byUni), $utcText((string) $sec['last']), $sec['active'] ? ', there now' : '');
        }
        if (in_array($name, FlightPaths::KNOWN_TELEPORTS, true)) { $title .= ' (known teleport sector)'; }
        printf('<a href="%s"><g class="%s"><title%s>%s</title>', $h($sectorUrl($mapUni, $name)), $class, $sec ? $utcAttr((string) $sec['last']) : '', $h($title));
        printf('<rect x="%.1f" y="%.1f" width="%.1f" height="%.1f" rx="4"/>', $p['x'] - $w / 2, $p['y'] - $hh / 2, $w, $hh);
        $font = max(7, min($hh * 0.3, $w * 0.16));
        printf('<text x="%.1f" y="%.1f" font-size="%.1f">%s</text>', $p['x'], $p['y'] + $font * 0.35, $font, $h($name));
        echo '</g></a>';
    }

    // Moves: shorten to the sector edges, offset sideways so A->B and B->A are separate arrows
    $maxCount = max(array_column($flight['moves'], 'count') ?: [1]);
    foreach ($flight['moves'] as $m) {
        if (!isset($pos[$m['from']], $pos[$m['to']])) { continue; }
        $a = $pos[$m['from']]; $b = $pos[$m['to']];
        $dx = $b['x'] - $a['x']; $dy = $b['y'] - $a['y'];
        $len = sqrt($dx * $dx + $dy * $dy);
        if ($len < 1) { continue; }
        $ux = $dx / $len; $uy = $dy / $len;
        $off = 4.5;                                      // sideways offset (to the right of travel), so A->B and B->A sit side by side
        $ox = -$uy * $off; $oy = $ux * $off;
        $trimA = min($a['w'], $a['h']) * 0.36; $trimB = min($b['w'], $b['h']) * 0.36;
        $x1 = $a['x'] + $ux * $trimA + $ox; $y1 = $a['y'] + $uy * $trimA + $oy;
        $x2 = $b['x'] - $ux * $trimB + $ox; $y2 = $b['y'] - $uy * $trimB + $oy;
        $width = 1.2 + 2.8 * log(1 + $m['count']) / log(1 + $maxCount);

        $byUni = [];
        foreach ($m['unis'] as $u => $n) { $byUni[] = $u . ' ' . $n; }
        $label = sprintf('%s → %s (%s): seen %d time%s (%s), last %s', $m['from'], $m['to'], $m['type'] === 'flight' ? 'flight' : 'jump',
            $m['count'], $m['count'] === 1 ? '' : 's', implode(', ', $byUni), $utcText((string) $m['last']));
        $moveKey = $h($m['from'] . '|' . $m['to']);

        if ($m['type'] === 'flight') {
            printf('<g class="fm-move fm-flight" data-move="%s"><title%s>%s</title><line x1="%.1f" y1="%.1f" x2="%.1f" y2="%.1f" stroke-width="%.1f" marker-end="url(#fm-arrow-flight)"/></g>',
                $moveKey, $utcAttr((string) $m['last']), $h($label), $x1, $y1, $x2, $y2, $width);
        } else {
            // curve jumps so they stand apart from the wormhole network
            $cx = ($x1 + $x2) / 2 - $uy * $len * 0.18; $cy = ($y1 + $y2) / 2 + $ux * $len * 0.18;
            printf('<g class="fm-move fm-jump" data-move="%s"><title%s>%s</title><path d="M%.1f %.1fQ%.1f %.1f %.1f %.1f" stroke-width="%.1f" marker-end="url(#fm-arrow-jump)"/></g>',
                $moveKey, $utcAttr((string) $m['last']), $h($label), $x1, $y1, $cx, $cy, $x2, $y2, $width);
        }
    }
?>
				</svg>
				<script type="text/javascript">
				// Zoom (mouse wheel, around the cursor), pan (drag) and reset (double-click) the flight map
				(function () {
					var svg = document.getElementById('flightmap');
					if (!svg) { return; }
					var home = svg.getAttribute('viewBox').split(' ').map(Number), vb = home.slice(), drag = null, moved = false;
					function apply() { svg.setAttribute('viewBox', vb.join(' ')); }
					function point(e) {
						var r = svg.getBoundingClientRect();
						return [vb[0] + (e.clientX - r.left) / r.width * vb[2], vb[1] + (e.clientY - r.top) / r.height * vb[3]];
					}
					svg.addEventListener('wheel', function (e) {
						e.preventDefault();
						var p = point(e), w = Math.min(home[2] * 1.5, Math.max(60, vb[2] * (e.deltaY < 0 ? 0.8 : 1.25))), k = w / vb[2];
						vb = [p[0] - (p[0] - vb[0]) * k, p[1] - (p[1] - vb[1]) * k, vb[2] * k, vb[3] * k];
						apply();
					}, { passive: false });
					svg.addEventListener('mousedown', function (e) { drag = { x: e.clientX, y: e.clientY, vb: vb.slice() }; moved = false; e.preventDefault(); });
					window.addEventListener('mousemove', function (e) {
						if (!drag) { return; }
						var r = svg.getBoundingClientRect();
						if (Math.abs(e.clientX - drag.x) + Math.abs(e.clientY - drag.y) > 3) { moved = true; }
						vb[0] = drag.vb[0] - (e.clientX - drag.x) / r.width * vb[2];
						vb[1] = drag.vb[1] - (e.clientY - drag.y) / r.height * vb[3];
						apply();
					});
					window.addEventListener('mouseup', function () { drag = null; });
					svg.addEventListener('click', function (e) { if (moved) { e.preventDefault(); moved = false; } }, true); // a drag is not a click on a sector
					svg.addEventListener('dblclick', function (e) { e.preventDefault(); vb = home.slice(); apply(); });
				})();
				</script>
				<?php if ($unplaced) { ?><p class="note">Also seen in (not on the universe layout): <?= $h(implode(', ', $unplaced)) ?></p><?php } ?>

				<h3>Inferred routes</h3>
				<?php if (count($stats['routes']) === 0) { ?>
					<p class="note">No moves seen yet.</p>
				<?php } else { ?>
				<p class="note">Pilots rarely see more than 2-3 sectors of a flight, so routes are pieced together from all the chains, in all universes and at any time.
					A route goes on through a sector when a chain was seen flying through it from the same direction, or when only one way on has ever been seen from it.
					Where there is more than one way on and nothing to choose between them, the route stops there; more sightings will join them up.
					<i>Italic</i> sectors are where pieces were joined without one chain seen all the way through. Hover a route to show it on the map.</p>
				<table class="stats routes">
					<tr><th>#</th><th>Route</th><th>Heading</th><th>Sectors</th><th>Moves seen</th><th>Universes</th></tr>
					<?php foreach ($stats['routes'] as $i => $rt) {
						$parts = [];
						foreach ($rt['sectors'] as $k => $s) {
							$name = '<a href="' . $h($sectorUrl($mapUni, $s)) . '">' . $h($s) . '</a>';
							$parts[] = in_array($s, $rt['joined'], true) ? '<i title="pieces joined here">' . $name . '</i>' : $name;
						}
						$byUni = [];
						foreach (Settings::UNIVERSE as $u) { if (isset($rt['unis'][$u])) { $byUni[] = $h($u) . ' ' . $rt['unis'][$u]; } }
					?>
					<tr class="fm-route" data-moves="<?= $h(implode(',', $rt['moves'])) ?>">
						<td><?= $i + 1 ?></td>
						<td class="l"><?= implode(' → ', $parts) ?><?= $rt['jumps'] ? ' <span title="includes a jump">(jump)</span>' : '' ?></td>
						<td><?= $h($rt['heading'] ?? '-') ?></td>
						<td><?= count($rt['sectors']) ?></td>
						<td><?= $rt['seen'] ?></td>
						<td><?= implode(', ', $byUni) ?></td>
					</tr>
					<?php } ?>
				</table>
				<script type="text/javascript">
				// Hover a route to show only its arrows on the map
				(function () {
					var svg = document.getElementById('flightmap');
					if (!svg) { return; }
					var rows = document.querySelectorAll('tr.fm-route');
					for (var i = 0; i < rows.length; i++) {
						rows[i].addEventListener('mouseenter', function () {
							var keys = this.getAttribute('data-moves').split(',');
							svg.classList.add('fm-dim');
							var moves = svg.querySelectorAll('.fm-move');
							for (var j = 0; j < moves.length; j++) {
								moves[j].classList.toggle('fm-hl', keys.indexOf(moves[j].getAttribute('data-move')) !== -1);
							}
						});
						rows[i].addEventListener('mouseleave', function () { svg.classList.remove('fm-dim'); });
					}
				})();
				</script>
				<?php } ?>

				<h3>Where jumps start</h3>
				<?php if (count($flight['jumps']) === 0) { ?>
					<p class="note">No jumps seen yet.</p>
				<?php } else { ?>
				<p class="note">Sectors the serpent left for a sector with no wormhole link. Teleport sectors should show up here repeatedly,
					usually from the same tile; a one-off may just be a route nobody saw it take.</p>
				<table class="stats">
					<tr><th>From</th><th>Jumps</th><th>Landed in</th><th>Last tile seen before jumping</th></tr>
					<?php foreach ($flight['jumps'] as $from => $j) {
						arsort($j['to']); arsort($j['tiles']);
						$to = []; foreach (array_slice($j['to'], 0, 5, true) as $s => $n) { $to[] = $h($s) . ' ×' . $n; }
						$tiles = []; foreach (array_slice($j['tiles'], 0, 3, true) as $t => $n) { $tiles[] = '[' . $h($t) . '] ×' . $n; }
					?>
					<tr>
						<td class="l"><a href="<?= $h($sectorUrl($mapUni, (string) $from)) ?>"><?= $h($from) ?></a><?= in_array($from, FlightPaths::KNOWN_TELEPORTS, true) ? ' <span class="live">known teleport</span>' : '' ?></td>
						<td><?= $j['count'] ?></td>
						<td class="l"><?= implode(', ', $to) ?></td>
						<td class="l"><?= implode(', ', $tiles) ?></td>
					</tr>
					<?php } ?>
				</table>
				<?php } ?>
<?php } ?>
