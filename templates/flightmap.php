<?php
declare(strict_types=1);

/**
 * Feral Serpent routes on the universe map: one map, all universes combined (they are copies and
 * it flies the same routes in each). Included from templates/npcstats.php.
 *
 * @var array $stats      ['flight' => FlightPaths::build(), 'layout' => UniverseMap::layout(), 'routes', 'dims', 'slug']
 * @var string $uni
 * @var string $statsUrl
 * @var callable $h, $when, $utcAttr, $utcText, $sectorUrl, $sectorSvg
 */

use Pardusmapper\DB;
use Pardusmapper\FlightPaths;
use Pardusmapper\UniverseMap;

$flight = $stats['flight'];
$pos = $stats['layout']['sectors'];
$mapUni = $uni; // sector links open in the universe being browsed

// Sectors that appear in the data and on the map
$visited = array_filter(array_keys($flight['sectors']), static fn ($s) => isset($pos[$s]));
$unplaced = array_diff(array_keys($flight['sectors']), $visited);

/**
 * What a move is, for the map: confirmed (seen flying it), suspected (filled in between
 * sightings, or flown through sectors nobody saw), a confirmed teleport (too fast to have flown),
 * or a teleport out of a sector where it was stuck.
 */
$moveKind = static function (array $m): string {
    if ($m['type'] === 'jump') {
        if ($m['exits']['teleport'] > 0) { return 'teleport'; }
        if ($m['exits']['stuck'] > 0) { return 'stuck'; }
        return 'unseen';
    }
    if ($m['count'] === 0) {
        return ($m['teleportOut'] ?? 0) > 0 ? 'hop' : 'suspected';
    }
    return 'confirmed';
};
?>
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

    // Wormhole network (cluster minimaps + recorded wormholes), drawn faintly underneath. X and Y
    // holes (and other far-reaching wormholes) aren't drawn: they link sectors all over the map.
    $links = [];
    foreach ($stats['layout']['links'] as [$a, $b]) { $links[UniverseMap::linkKey($a, $b)] = [$a, $b]; }
    foreach (DB::wormhole_links() as [$a, $b]) { $links[UniverseMap::linkKey($a, $b)] = [$a, $b]; }
    $maxLink = $cell * 3;
?>
				<p class="note">Where the Feral Serpent flies, pieced together from sightings in all three universes (they are copies, and it flies the same routes in each).
					Each arrow shows the direction it was flying. <b>Confirmed</b> moves were seen: it was spotted in one sector and then the next within <?= FlightPaths::LINK_WINDOW / 60 ?> minutes.
					<b>Suspected</b> moves weren't seen: the likely way between two sightings <?= FlightPaths::LINK_WINDOW / 60 ?>-<?= FlightPaths::EXTRAPOLATE_WINDOW / 60 ?> minutes apart
					(along routes seen before, else the shortest way by wormholes it could have flown in the time), or a flight on through sectors nobody saw.
					A <b>teleport</b> is confirmed only when it turned up somewhere sooner than it could have flown there (<?= FlightPaths::FASTEST_SECTOR_TIME ?> s a sector: 2 sectors away in under a minute).
					When it gets <b>stuck</b> (impassable energy ahead, away from any wormhole, no way back) it <b>teleports out</b> one sector and flies on from there.
					Hover an arrow or sector for details.</p>
				<div class="legend">
					<span><span class="sw" style="background:#ff8c1a"></span>Confirmed route</span>
					<span><span class="sw" style="background:repeating-linear-gradient(90deg,#ff8c1a 0 2px,transparent 2px 5px)"></span>Suspected route</span>
					<span><span class="sw" style="background:#b06cf0"></span>Teleport (too fast to have flown)</span>
					<span><span class="sw" style="background:repeating-linear-gradient(90deg,#b06cf0 0 2px,transparent 2px 5px)"></span>Teleported out (stuck)</span>
					<span><span class="sw" style="border:2px solid #00e5ff"></span>Seen there now</span>
					<span><span class="sw" style="border:2px dashed #b06cf0"></span>Known teleport sector</span>
				</div>
				<p class="note">Scroll on the map to zoom in, drag to move around, double-click to reset.</p>
				<div class="fm-wrap">
				<svg id="flightmap" class="flightmap" viewBox="<?= sprintf('%.0f %.0f %.0f %.0f', $vx, $vy, $vw, $vh) ?>" xmlns="http://www.w3.org/2000/svg">
					<defs>
						<marker id="fm-arrow-flight" viewBox="0 0 10 10" refX="9" refY="5" markerUnits="userSpaceOnUse" markerWidth="9" markerHeight="9" orient="auto-start-reverse"><path d="M0 0L10 5L0 10z" fill="#ff8c1a"/></marker>
						<marker id="fm-arrow-jump" viewBox="0 0 10 10" refX="9" refY="5" markerUnits="userSpaceOnUse" markerWidth="11" markerHeight="11" orient="auto-start-reverse"><path d="M0 0L10 5L0 10z" fill="#b06cf0"/></marker>
					</defs>
<?php
    foreach ($links as [$a, $b]) {
        if (!isset($pos[$a], $pos[$b]) || !($inView($pos[$a]) || $inView($pos[$b]))) { continue; }
        if (hypot($pos[$b]['x'] - $pos[$a]['x'], $pos[$b]['y'] - $pos[$a]['y']) > $maxLink) { continue; }
        printf('<line x1="%.1f" y1="%.1f" x2="%.1f" y2="%.1f" class="fm-link"/>', $pos[$a]['x'], $pos[$a]['y'], $pos[$b]['x'], $pos[$b]['y']);
    }

    foreach ($pos as $name => $p) {
        if (!$inView($p)) { continue; }
        $sec = $flight['sectors'][$name] ?? null;
        $w = $p['w'] * 0.72; $hh = $p['h'] * 0.62;
        $class = 'fm-sector' . ($sec ? ' fm-visited' : '') . (($sec['active'] ?? false) ? ' fm-live' : '') . (in_array($name, FlightPaths::KNOWN_TELEPORTS, true) ? ' fm-teleport' : '');
        $title = $name;
        if ($sec) {
            $title .= sprintf(': seen here, last %s%s', $utcText((string) $sec['last']), $sec['active'] ? ', there now' : '');
        }
        if (in_array($name, FlightPaths::KNOWN_TELEPORTS, true)) { $title .= ' (known teleport sector)'; }
        printf('<a href="%s"><g class="%s"><title%s>%s</title>', $h($sectorUrl($mapUni, $name)), $class, $sec ? $utcAttr((string) $sec['last']) : '', $h($title));
        printf('<rect x="%.1f" y="%.1f" width="%.1f" height="%.1f" rx="4"/>', $p['x'] - $w / 2, $p['y'] - $hh / 2, $w, $hh);
        $font = max(7, min($hh * 0.3, $w * 0.16));
        printf('<text x="%.1f" y="%.1f" font-size="%.1f">%s</text>', $p['x'], $p['y'] + $font * 0.35, $font, $h($name));
        echo '</g></a>';
    }

    // Moves: shorten to the sector edges, offset sideways so A->B and B->A are separate arrows
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

        $kind = $moveKind($m);
        $via = $m['via'] ? ' Could have flown via ' . implode(' or via ', array_map(static fn ($w) => implode(', ', $w), $m['via'])) . '.' : '';
        $label = $m['from'] . ' → ' . $m['to'] . ': ' . match ($kind) {
            'confirmed' => 'confirmed (seen flying it), last ' . $utcText((string) $m['last'])
                . (($m['teleportOut'] ?? 0) > 0 ? '; it has also teleported out of ' . $m['from'] . ' into ' . $m['to'] . ' when stuck' : ''),
            'suspected' => 'suspected (the likely way between two sightings, not seen)',
            'hop' => 'teleported out of ' . $m['from'] . ' (stuck), then flew on',
            'teleport' => 'teleport: seen there sooner than it could have flown, last ' . $utcText((string) $m['last']),
            'stuck' => 'teleported out of ' . $m['from'] . ' (stuck), then flew on unseen.' . $via,
            default => 'suspected: flew on through sectors nobody saw.' . $via,
        };
        $moveKey = $h($m['from'] . '|' . $m['to']);
        $timed = in_array($kind, ['confirmed', 'teleport'], true) ? $utcAttr((string) $m['last']) : '';

        if ($m['type'] === 'flight') {
            $class = ['confirmed' => '', 'suspected' => ' fm-guess', 'hop' => ' fm-guess fm-hop'][$kind];
            printf('<g class="fm-move fm-flight%s" data-move="%s"><title%s>%s</title><line x1="%.1f" y1="%.1f" x2="%.1f" y2="%.1f" stroke-width="2.4" marker-end="url(#fm-arrow-flight)"/></g>',
                $class, $moveKey, $timed, $h($label), $x1, $y1, $x2, $y2);
        } else {
            // curve jumps so they stand apart from the wormhole network
            $cx = ($x1 + $x2) / 2 - $uy * $len * 0.18; $cy = ($y1 + $y2) / 2 + $ux * $len * 0.18;
            $class = ['teleport' => 'fm-jump', 'stuck' => 'fm-jump fm-maybe', 'unseen' => 'fm-unseen'][$kind];
            printf('<g class="fm-move %s" data-move="%s"><title%s>%s</title><path d="M%.1f %.1fQ%.1f %.1f %.1f %.1f" stroke-width="2.4" marker-end="url(#%s)"/></g>',
                $class, $moveKey, $timed, $h($label), $x1, $y1, $cx, $cy, $x2, $y2, $kind === 'unseen' ? 'fm-arrow-flight' : 'fm-arrow-jump');
        }
    }
?>
				</svg>
				</div>
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
					Teleports are part of routes. Where it left by a wormhole and flew on unseen, nobody knows which way it went (it may have turned back), so that does not join routes.
					<i>Italic</i> sectors are where pieces were joined without one chain seen all the way through.
					A route is <b>Confirmed</b> when every move on it was seen and a chain was seen through every sector on it; otherwise it is <b>partly suspected</b>. Hover a route to show it on the map.</p>
				<table class="stats routes">
					<tr><th>#</th><th>Route</th><th>Heading</th><th>Sectors</th><th>Status</th></tr>
					<?php foreach ($stats['routes'] as $i => $rt) {
						$parts = [];
						foreach ($rt['sectors'] as $k => $s) {
							$name = '<a href="' . $h($sectorUrl($mapUni, $s)) . '">' . $h($s) . '</a>';
							$parts[] = in_array($s, $rt['joined'], true) ? '<i title="pieces joined here">' . $name . '</i>' : $name;
						}
						$confirmed = $rt['guessed'] === 0 && count($rt['joined']) === 0;
					?>
					<tr class="fm-route" data-moves="<?= $h(implode(',', $rt['moves'])) ?>">
						<td><?= $i + 1 ?></td>
						<td class="l"><?= implode(' → ', $parts) ?><?= $rt['jumps'] ? ' <span title="includes a teleport">(teleport)</span>' : '' ?></td>
						<td><?= $h($rt['heading'] ?? '-') ?></td>
						<td><?= count($rt['sectors']) ?></td>
						<td><?= $confirmed ? '<span class="live">Confirmed</span>' : 'Partly suspected' ?></td>
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

				<h3>Paths through sectors</h3>
				<?php
				// sectors where it was followed across 2+ tiles first, then the busiest
				$pathCount = static fn (array $dirs): int => array_sum(array_map(static fn ($d) => count($d['paths']), $dirs));
				$flybyCount = static fn (array $dirs): int => array_sum(array_column($dirs, 'flybys'));
				$trackSectors = $flight['tracks'];
				uasort($trackSectors, static fn ($a, $b) => $pathCount($b) <=> $pathCount($a) ?: $flybyCount($b) <=> $flybyCount($a));
				$dirColours = ['#ff8c1a', '#00e5ff', '#e05cff', '#7cff6b', '#ffe14d', '#ff4d6d', '#4d8bff'];
				?>
				<p class="note">Where it went within each sector, to help find it when it is cloaked or has just gone out of view.
					Each colour is one direction of travel: the sector it came from and the sector it went to next (? = not seen).
					A line follows one flyby tile by tile, from the dot to the ring; the smaller dots are every tile it was seen on in that direction.
					Sectors where it was followed across more than one tile come first.</p>
				<div class="cards">
				<?php foreach (array_slice($trackSectors, 0, 30, true) as $secName => $dirs) {
					$marks = [];
					$paths = [];
					$legendRows = [];
					$i = 0;
					foreach ($dirs as $d) {
						$known = !is_null($d['from']) || !is_null($d['to']);
						$colour = $known ? $dirColours[$i++ % count($dirColours)] : '#9aa7ad';
						$label = sprintf('%s → %s', $d['from'] ?? '?', $d['to'] ?? '?');
						$dots = [];
						foreach ($d['tiles'] as $t => $n) {
							[$tx, $ty] = array_map('intval', explode(',', $t));
							$dots[] = [$tx, $ty];
							$marks[] = ['x' => $tx, 'y' => $ty, 'weight' => 0, 'label' => '', 'hidden' => true];
						}
						$paths[] = ['colour' => $colour, 'label' => $label, 'lines' => $d['paths'], 'dots' => $dots];
						$legendRows[] = sprintf('<div><span class="sw" style="background:%s"></span>%s</div>', $colour, $h($label));
					}
				?>
					<div class="card">
						<div class="sname"><a href="<?= $h($sectorUrl($mapUni, (string) $secName)) ?>"><?= $h($secName) ?></a></div>
						<?= $sectorSvg($mapUni, (string) $secName, $stats['dims'][$secName] ?? null, $marks, $paths) ?>
						<div class="dirs"><?= implode('', $legendRows) ?></div>
					</div>
				<?php } ?>
				</div>
				<?php if (count($trackSectors) > 30) { ?><p class="note">Showing the 30 sectors with the most to show, of <?= count($trackSectors) ?>.</p><?php } ?>

				<h3>Where jumps start</h3>
				<?php if (count($flight['jumps']) === 0) { ?>
					<p class="note">No jumps seen yet.</p>
				<?php } else { ?>
				<p class="note">Sectors the serpent left for a sector with no wormhole link, or got to faster than it could have flown.
					Seen there sooner than the quickest possible flight: a <b>confirmed teleport</b>.
					Otherwise: last seen on or next to a wormhole, it <b>left by the wormhole</b> and flew on through sectors nobody saw (the shortest way on is shown as "via");
					stuck away from any wormhole, with impassable energy on the next tile in the direction it was heading, it <b>teleported out</b> one sector and flew on from there;
					anywhere else it just <b>went out of view</b>, probably flying on to a wormhole unseen.</p>
				<table class="stats">
					<tr><th>From</th><th>Teleports</th><th>Teleported out<br />(stuck)</th><th>Left by a<br />wormhole</th><th>Went out<br />of view</th><th>Landed in</th><th>Tiles last seen before leaving</th></tr>
					<?php foreach ($flight['jumps'] as $from => $j) {
						arsort($j['to']);
						uasort($j['tiles'], static fn ($a, $b) => $b['n'] <=> $a['n']);
						$to = [];
						foreach (array_slice($j['to'], 0, 5, true) as $s => $n) {
							$via = $flight['moves'][$from . '|' . $s]['via'] ?? null;
							$to[] = $h($s) . ' ×' . $n . ($via ? ' <span style="color:#A1A1AF">(via ' . $h(implode(' or via ', array_map(static fn ($w) => implode(', ', $w), $via))) . ')</span>' : '');
						}
						$tiles = [];
						foreach (array_slice($j['tiles'], 0, 4, true) as $t => $tl) {
							$where = match ($tl['how']) {
								'teleport' => is_null($tl['min']) ? 'no way to fly there' : sprintf('seen after %d s, flying takes at least %d s', $tl['gap'], $tl['min']),
								'stuck' => sprintf('stuck, %d tiles from a wormhole', $tl['dist']),
								'wormhole' => 'next to a wormhole',
								'unseen' => sprintf('out of view, %d tiles from a wormhole', $tl['dist']),
								default => 'no wormhole mapped in this sector',
							};
							$tiles[] = '[' . $h($t) . '] ×' . $tl['n'] . ' <span style="color:#A1A1AF">(' . $h($where) . ')</span>';
						}
					?>
					<tr>
						<td class="l"><a href="<?= $h($sectorUrl($mapUni, (string) $from)) ?>"><?= $h($from) ?></a><?= in_array($from, FlightPaths::KNOWN_TELEPORTS, true) ? ' <span class="live">known teleport</span>' : '' ?></td>
						<td><?= $j['teleport'] ? '<span class="live">' . $j['teleport'] . '</span>' : '0' ?></td>
						<td><?= $j['stuck'] ?></td>
						<td><?= $j['wormhole'] ?></td>
						<td><?= $j['unseen'] ?><?= $j['unknown'] ? ' <span title="no wormhole mapped in this sector, so it can\'t be told">(+' . $j['unknown'] . ' ?)</span>' : '' ?></td>
						<td class="l"><?= implode(', ', $to) ?></td>
						<td class="l"><?= implode(', ', $tiles) ?></td>
					</tr>
					<?php } ?>
				</table>
				<?php } ?>
<?php } ?>
