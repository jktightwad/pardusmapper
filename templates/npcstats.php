<?php
declare(strict_types=1);

/** @var string $base_url */
/** @var string $css */
/** @var string $uni */
/** @var string $title */
/** @var array $stats */

use Pardusmapper\Core\Settings;
use Pardusmapper\FlightPaths;
use Pardusmapper\SpawnCompare;
use Pardusmapper\TileMap;

$h = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES);
// Database times are UTC. Each is printed as "YYYY-MM-DD HH:MM UTC" and rewritten to the viewer's
// local time by pmLocalizeTimes() (resources/main.js) when the page loads.
$utcAttr = static fn (string $d): string => sprintf(' data-utc="%sZ" data-utc-text="%s UTC"', str_replace(' ', 'T', substr($d, 0, 19)), substr($d, 0, 16));
$utcText = static fn (string $d): string => substr($d, 0, 16) . ' UTC';
$when = static fn (?string $d): string => is_null($d) ? '-' : '<span' . $utcAttr($d) . '>' . $utcText($d) . '</span>';
$statsUrl = $base_url . '/' . $uni . '/npcstats';
$sectorUrl = static fn (string $u, string $sector): string => $base_url . '/' . $u . '/' . rawurlencode($sector);

/**
 * Colour-coded map of the part of a sector around the sightings (terrain, planets/starbases,
 * wormholes from {Universe}_Maps), with the sightings outlined on top.
 * $marks: list of ['x','y','label','weight' 0..1, 'active' (optional), 'utc' (optional: a time in the label),
 *                  'n' (optional: stop number drawn on the tile), 'hidden' (optional: only used to size the map)]
 * $paths: optional, drawn on top: list of ['colour', 'label', 'lines' => [[[x, y], ...], ...] (tiles in order),
 *                  'dots' => [[x, y], ...] (tiles visited)]
 */
$sectorSvg = static function (string $u, string $sector, ?object $dim, array $marks, array $paths = []) use ($h, $utcAttr): string {
    $crop = TileMap::crop($dim, $marks);
    [$x0, $y0, $x1, $y1] = $crop;
    $cols = $x1 - $x0 + 1;
    $rows = $y1 - $y0 + 1;
    $cell = max(6, min(16, intdiv(360, $cols), intdiv(320, $rows)));
    $w = $cols * $cell;
    $hgt = $rows * $cell;

    $svg = sprintf('<svg class="minimap" width="%d" height="%d" viewBox="0 0 %d %d" xmlns="http://www.w3.org/2000/svg" shape-rendering="crispEdges">', $w, $hgt, $w, $hgt);

    // terrain: runs of the same tile type in a row become one rectangle
    foreach (TileMap::grid($u, $sector, $crop) as $ry => $line) {
        $start = 0;
        for ($rx = 1; $rx <= $cols; $rx++) {
            if ($rx === $cols || $line[$rx] !== $line[$start]) {
                $svg .= sprintf('<rect x="%d" y="%d" width="%d" height="%d" fill="%s"/>', $start * $cell, $ry * $cell, ($rx - $start) * $cell, $cell, TileMap::COLOURS[$line[$start]][1]);
                $start = $rx;
            }
        }
    }

    // faint grid lines
    $path = '';
    for ($i = 1; $i < $cols; $i++) { $path .= sprintf('M%d 0V%d', $i * $cell, $hgt); }
    for ($i = 1; $i < $rows; $i++) { $path .= sprintf('M0 %dH%d', $i * $cell, $w); }
    if ($path !== '') { $svg .= '<path d="' . $path . '" class="mm-grid"/>'; }

    // sightings
    foreach ($marks as $m) {
        if (!empty($m['hidden'])) { continue; }
        $opacity = 0.45 + 0.55 * max(0.0, min(1.0, (float) $m['weight']));
        $mx = ((int) $m['x'] - $x0) * $cell;
        $my = ((int) $m['y'] - $y0) * $cell;
        $svg .= sprintf(
            '<g class="mm-mark%s" opacity="%.2f"><rect x="%.1f" y="%.1f" width="%.1f" height="%.1f"/>',
            empty($m['active']) ? '' : ' mm-active', $opacity, $mx + 1, $my + 1, $cell - 2, $cell - 2
        );
        if (isset($m['n']) && $cell >= 11) {
            $svg .= sprintf('<text x="%.1f" y="%.1f">%d</text>', $mx + $cell / 2, $my + $cell / 2 + 3, $m['n']);
        }
        $svg .= sprintf('<title%s>%s</title></g>', empty($m['utc']) ? '' : $utcAttr($m['utc']), $h($m['label']));
    }

    // paths across the tiles: a line per flyby (dot at the start, ring at the end), and a dot on every tile visited
    $centre = static fn (array $t): array => [((int) $t[0] - $x0 + 0.5) * $cell, ((int) $t[1] - $y0 + 0.5) * $cell];
    foreach ($paths as $p) {
        $svg .= sprintf('<g class="mm-path" style="color:%s"><title>%s</title>', $h($p['colour']), $h($p['label']));
        foreach ($p['dots'] ?? [] as $t) {
            [$cx, $cy] = $centre($t);
            $svg .= sprintf('<circle cx="%.1f" cy="%.1f" r="%.1f" class="mm-dot"/>', $cx, $cy, $cell * 0.18);
        }
        foreach ($p['lines'] ?? [] as $line) {
            $pts = array_map($centre, $line);
            $svg .= '<polyline class="mm-line" points="' . implode(' ', array_map(static fn ($c) => sprintf('%.1f,%.1f', $c[0], $c[1]), $pts)) . '"/>';
            $svg .= sprintf('<circle cx="%.1f" cy="%.1f" r="%.1f" class="mm-start"/>', $pts[0][0], $pts[0][1], $cell * 0.24);
            $end = end($pts);
            $svg .= sprintf('<circle cx="%.1f" cy="%.1f" r="%.1f" class="mm-end"/>', $end[0], $end[1], $cell * 0.3);
        }
        $svg .= '</g>';
    }

    return $svg . '</svg>';
};

$legend = '<div class="legend">';
foreach (TileMap::COLOURS as [$label, $colour]) {
    $legend .= sprintf('<span><span class="sw" style="background:%s"></span>%s</span>', $colour, $h($label));
}
$legend .= '<span><span class="sw" style="background:#ffe14d"></span>NPC sighting</span>'
    . '<span><span class="sw" style="background:#00e5ff"></span>NPC there now</span></div>';
?>
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml">
	<head>
		<meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
		<title><?= $h($title) ?></title>
		<link rel="stylesheet" type="text/css" href="<?= $css; ?>" />
		<style type="text/css">
			#stats { padding: 10px 15px 25px; text-align: left; }
			#stats h2, #stats h3 { color: #DFFF91; }
			#stats p.note { color: #A1A1AF; font-size: 12px; max-width: 900px; }
			#stats table.stats { margin: 6px 0 18px; }
			#stats table.stats td { padding: 3px 8px; border-bottom: 1px solid #334649; text-align: center; }
			#stats table.stats td.l { text-align: left; }
			#stats table.stats th { padding: 3px 8px; }
			#stats .crumbs { margin-bottom: 8px; font-size: 13px; }
			#stats .live { color: #8f8; font-weight: bold; }
			#stats .cards { display: flex; flex-wrap: wrap; align-items: center; gap: 6px; }
			#stats .card { background: rgba(0,0,0,0.35); border: 1px solid #334649; padding: 6px; font-size: 11px; color: #A1A1AF; text-align: center; }
			#stats .card .sname { font-size: 12px; font-weight: bold; }
			#stats .arrow { color: #DFFF91; font-size: 18px; }
			#stats svg.minimap { display: block; margin: 4px auto; border: 1px solid #334649; }
			#stats svg .mm-grid { stroke: rgba(255,255,255,0.10); stroke-width: 1; fill: none; }
			/* sightings: yellow outlined square; cyan if the NPC is there now */
			#stats svg .mm-mark rect { fill: rgba(255,225,77,0.55); stroke: #ffe14d; stroke-width: 2; }
			#stats svg .mm-mark text { fill: #000; font: bold 9px sans-serif; text-anchor: middle; }
			#stats svg .mm-active rect { fill: rgba(0,229,255,0.6); stroke: #00e5ff; }
			/* Feral Serpent flight map (templates/flightmap.php) */
			#stats svg.flightmap { display: block; border: 1px solid #334649; margin: 6px 0 14px; }
			#stats svg .fm-link { stroke: #2a4650; stroke-width: 1.5; }
			#stats svg .fm-sector rect { fill: #13232b; stroke: #2a4650; stroke-width: 1; }
			#stats svg .fm-sector text { fill: #5d7680; text-anchor: middle; font-family: sans-serif; }
			#stats svg .fm-visited rect { fill: #1d3b48; stroke: #81F0F1; }
			#stats svg .fm-visited text { fill: #e8f4f4; font-weight: bold; }
			#stats svg .fm-live rect { stroke: #00e5ff; stroke-width: 3; }
			#stats svg .fm-teleport rect { stroke: #b06cf0; stroke-width: 2.5; stroke-dasharray: 6 3; }
			#stats svg .fm-flight line { stroke: #ff8c1a; stroke-linecap: round; opacity: 0.85; }
			/* serpent paths across the tiles of a sector (colour set per direction) */
			#stats svg .mm-path .mm-line { fill: none; stroke: currentColor; stroke-width: 2; opacity: 0.8; shape-rendering: geometricPrecision; }
			#stats svg .mm-path .mm-dot { fill: currentColor; opacity: 0.55; }
			#stats svg .mm-path .mm-start { fill: currentColor; }
			#stats svg .mm-path .mm-end { fill: none; stroke: currentColor; stroke-width: 2; }
			#stats .dirs { text-align: left; margin-top: 3px; }
			#stats .dirs span.sw { display: inline-block; width: 10px; height: 10px; margin-right: 4px; vertical-align: -1px; }
			/* hovering a route in the routes table shows only its arrows */
			#stats svg.fm-dim .fm-move { opacity: 0.12; }
			#stats svg.fm-dim .fm-move.fm-hl { opacity: 1; }
			#stats svg.fm-dim .fm-hl line, #stats svg.fm-dim .fm-hl path { stroke: #fff; opacity: 1; }
			#stats tr.fm-route:hover td { background: rgba(255,140,26,0.15); }
			#stats svg .fm-jump path { stroke: #b06cf0; fill: none; stroke-dasharray: 8 5; opacity: 0.85; }
			#stats svg .fm-maybe path { stroke-dasharray: 2 5; stroke-linecap: round; opacity: 0.7; }
			#stats svg .fm-guess line { stroke-dasharray: 1 5; stroke-linecap: round; opacity: 0.55; }
			#stats svg .fm-unseen path { stroke: #ff8c1a; fill: none; stroke-dasharray: 2 4; stroke-linecap: round; opacity: 0.85; }
			#stats svg .fm-move:hover line, #stats svg .fm-move:hover path { opacity: 1; stroke: #fff; }
			/* Compare universes */
			#stats table.compare td { vertical-align: top; min-width: 170px; }
			#stats table.compare .app { padding: 1px 0; white-space: nowrap; }
			#stats table.compare .when { color: #A1A1AF; font-size: 11px; }
			#stats .match { color: #ffe14d; font-weight: bold; }
			#stats .legend { display: flex; flex-wrap: wrap; gap: 4px 12px; font-size: 11px; color: #A1A1AF; margin: 4px 0 8px; }
			#stats .legend span.sw { display: inline-block; width: 11px; height: 11px; margin-right: 4px; vertical-align: -1px; border: 1px solid #334649; }
		</style>
	</head>
	<body>
		<div id="header_side"><?php require_once(templates('header_side')); ?></div>
		<div id="body">
			<center>
			<div id="stats">
				<h2>Rare NPC Stats <span style="font-size:12px;color:#A1A1AF">(all universes combined)</span></h2>
				<div class="crumbs">
					<a href="<?= $h($statsUrl) ?>">All tracked NPCs</a>
					<?php if ($stats['slug']) { ?> &raquo; <a href="<?= $h($statsUrl . '?npc=' . $stats['slug']) ?>"><?= $h($stats['groups'][$stats['slug']]['name']) ?></a><?php } ?>
					<?php if ($stats['view'] === 'compare') { ?> &raquo; Compare universes<?php } ?>
					<?php if ($stats['view'] === 'flight') { ?> &raquo; Flight paths<?= $stats['u'] ? ' &raquo; ' . $h($stats['u']) : '' ?><?= ($stats['u'] && $stats['nid']) ? ' ID ' . $h($stats['nid']) : '' ?><?php } ?>
				</div>

<?php if ($stats['view'] === 'overview') { ?>
				<table class="stats">
					<tr><th>NPC</th><th>Sightings</th><th>Unique IDs<br />Orion / Artemis / Pegasus</th><th>Sightings<br />without ID</th><th>Sectors</th><th>Seen now</th><th>First seen</th><th>Last seen</th></tr>
					<?php foreach ($stats['summary'] as $s => $row) { ?>
					<tr>
						<td class="l"><a href="<?= $h($statsUrl . '?npc=' . $s) ?>"><?= $h($row['name']) ?></a></td>
						<td title="<?= $row['records'] ?> raw records"><?= $row['sightings'] ?></td>
						<td><?= count($row['ids']['Orion'] ?? []) ?> / <?= count($row['ids']['Artemis'] ?? []) ?> / <?= count($row['ids']['Pegasus'] ?? []) ?></td>
						<td><?= $row['noid'] ?></td>
						<td><?= count($row['sectors']) ?></td>
						<td><?= $row['active'] ? '<span class="live">' . $row['active'] . '</span>' : '0' ?></td>
						<td><?= $when($row['first']) ?></td>
						<td><?= $when($row['last']) ?></td>
					</tr>
					<?php } ?>
				</table>
				<p class="note">A <b>sighting</b> is the NPC being seen on a tile. Every tile it moves to counts, so you can follow its path.
					If it drops out of view and is seen again on the same tile less than 2 hours later, that is the same sighting, not a new one
					(hover a number for the raw record count). The NPC ID is only recorded when a pilot stands on its tile, and has been recorded since early 2021.
					All times are when the NPC was first seen on a tile, shown in your local time. "Seen now" is how many have not yet been reported gone.</p>

<?php } elseif ($stats['view'] === 'npc') { ?>
				<?php $isSerpent = $stats['slug'] === FlightPaths::SLUG; ?>
				<p class="note"><b><a href="<?= $h($statsUrl . '?npc=' . $stats['slug'] . '&compare=1') ?>">Compare the universes over time</a></b>
					&ndash; Orion, Artemis and Pegasus side by side, and whether it turns up in the same sectors at the same time.</p>
				<?php if ($isSerpent) { ?>
					<p class="note"><b>Flight paths on the universe map:</b>
						<a href="<?= $h($statsUrl . '?npc=' . $stats['slug'] . '&flight=1') ?>">all universes</a>
						<?php foreach (Settings::UNIVERSE as $fu) { ?> | <a href="<?= $h($statsUrl . '?npc=' . $stats['slug'] . '&flight=1&u=' . $fu) ?>"><?= $h($fu) ?></a><?php } ?></p>
				<?php } ?>
				<h3>Unique NPC IDs</h3>
				<?php if (count($stats['ids']) === 0) { ?>
					<p class="note">No sightings with an NPC ID yet.</p>
				<?php } else { ?>
				<table class="stats">
					<tr><th>Universe</th><th>NPC ID</th><th>Sightings<br />with this ID</th><th>Sectors</th><th>Last seen at</th><th>First seen</th><th>Last seen</th><?= $isSerpent ? '<th>Flight path</th>' : '' ?></tr>
					<?php foreach ($stats['ids'] as $id) { ?>
					<tr>
						<td><?= $h($id['uni']) ?></td>
						<td><?= $id['nid'] ?></td>
						<td><?= $id['sightings'] ?></td>
						<td><?= count($id['sectors']) ?></td>
						<td class="l"><?= $h($id['where']) ?><?= $id['active'] ? ' <span class="live">(seen now)</span>' : '' ?></td>
						<td><?= $when($id['first']) ?></td>
						<td><?= $when($id['last']) ?></td>
						<?php if ($isSerpent) { ?><td><a href="<?= $h($statsUrl . '?npc=' . $stats['slug'] . '&u=' . $id['uni'] . '&nid=' . $id['nid']) ?>">Flight path</a></td><?php } ?>
					</tr>
					<?php } ?>
				</table>
				<?php } ?>
				<?php if (count($stats['totals'])) { ?>
					<p class="note"><b>All sightings</b>, with or without an ID:
					<?php $parts = []; foreach (Settings::UNIVERSE as $u) { if (isset($stats['totals'][$u])) { $parts[] = $h($u) . ' ' . $stats['totals'][$u]; } } echo implode(', ', $parts); ?>.
					<?php if (count($stats['noid'])) { ?>
					Of these, without an NPC ID (seen from another tile, so no ID was captured):
					<?php $parts = []; foreach (Settings::UNIVERSE as $u) { if (isset($stats['noid'][$u])) { $parts[] = $h($u) . ' ' . $stats['noid'][$u]; } } echo implode(', ', $parts); ?>.
					<?php } ?>
					The sector maps below count all sightings.</p>
				<?php } ?>

				<h3>Where it has been seen (all IDs, all universes)</h3>
				<p class="note">Busiest sectors first, zoomed to the area around the sightings. Brighter squares have more sightings; cyan has one there now. Hover a square for its count. Click a sector name to open its full map.</p>
				<?= $legend ?>
				<div class="cards">
				<?php foreach ($stats['sectors'] as $name => $sec) {
					$max = max($sec['tiles']);
					$marks = [];
					foreach ($sec['tiles'] as $tile => $n) {
						[$tx, $ty] = array_map('intval', explode(',', $tile));
						$live = isset($sec['live'][$tile]);
						$marks[] = ['x' => $tx, 'y' => $ty, 'weight' => $max > 1 ? ($n - 1) / ($max - 1) : 1, 'active' => $live,
							'label' => sprintf('[%d,%d] %d sighting%s%s', $tx, $ty, $n, $n === 1 ? '' : 's', $live ? ', there now' : '')];
					}
					$byUni = [];
					foreach ($sec['unis'] as $u => $n) { $byUni[] = $u . ' ' . $n; }
				?>
					<div class="card">
						<div class="sname"><a href="<?= $h($sectorUrl($uni, (string) $name)) ?>"><?= $h($name) ?></a></div>
						<?= $sectorSvg($uni, (string) $name, $stats['dims'][$name] ?? null, $marks) ?>
						<?= $sec['total'] ?> sighting<?= $sec['total'] === 1 ? '' : 's' ?>, <?= count($sec['ids']) ?> ID<?= count($sec['ids']) === 1 ? '' : 's' ?><br />
						<?= $h(implode(', ', $byUni)) ?><br />
						last seen <?= $when($sec['last']) ?>
					</div>
				<?php } ?>
				</div>

<?php } elseif ($stats['view'] === 'compare') {
	$base = $statsUrl . '?npc=' . $stats['slug'] . '&compare=1';
	$windowDays = SpawnCompare::PERIODS[$stats['period']];
	$periodLabel = static function (string $start, string $period): string {
		$ts = (int) strtotime($start . ' UTC');
		return match ($period) {
			'month' => gmdate('M Y', $ts),
			'week' => 'Week of ' . gmdate('M j, Y', $ts),
			default => gmdate('D M j, Y', $ts),
		};
	};
	$pct = static fn (float $f): string => (string) round($f * 100) . '%';
?>
				<p class="note">
					Group by:
					<?php foreach (array_keys(SpawnCompare::PERIODS) as $p) { ?>
						<?= $p === $stats['period'] ? '<b>' . $h($p) . '</b>' : '<a href="' . $h($base . '&by=' . $p . ($stats['oldest'] ? '&oldest=1' : '')) . '">' . $h($p) . '</a>' ?>
					<?php } ?>
					&nbsp;|&nbsp;
					<?= $stats['oldest'] ? '<a href="' . $h($base . '&by=' . $stats['period']) . '">newest first</a> | <b>oldest first</b>' : '<b>newest first</b> | <a href="' . $h($base . '&by=' . $stats['period'] . '&oldest=1') . '">oldest first</a>' ?>
				</p>

				<h3>Do the universes follow each other?</h3>
				<table class="stats">
					<tr><th>Universes</th><th>Compared</th><th>Appearances</th><th>Same sector<br />within &plusmn;<?= $windowDays ?> day<?= $windowDays === 1 ? '' : 's' ?></th><th>Same sector<br />at any time</th><th>Same cluster<br />within &plusmn;<?= $windowDays ?> day<?= $windowDays === 1 ? '' : 's' ?></th><th>Typical gap</th><th>Verdict</th></tr>
					<?php foreach ($stats['pairs'] as $p) { ?>
					<tr>
						<td><?= $h($p['a']) ?> &amp; <?= $h($p['b']) ?></td>
						<td><?= is_null($p['from']) ? '-' : $h(gmdate('M Y', $p['from']) . ' – ' . gmdate('M Y', $p['to'])) ?></td>
						<td><?= $p['n'] ?></td>
						<?php if ($p['near'] || $p['ever']) { ?>
						<td><?= $pct($p['near']) ?></td>
						<td><?= $pct($p['ever']) ?></td>
						<td><?= $pct($p['nearCluster']) ?></td>
						<td><?= is_null($p['lag']) ? '-' : $h(($p['lag'] >= 0 ? $p['b'] . ' ' : $p['a'] . ' ') . SpawnCompare::duration(abs($p['lag'])) . ' later') ?></td>
						<?php } else { ?>
						<td>-</td><td>-</td><td>-</td><td>-</td>
						<?php } ?>
						<td class="l"><?= $h($p['verdict']) ?></td>
					</tr>
					<?php } ?>
				</table>
				<p class="note">An <b>appearance</b> is the NPC turning up in a sector: a spawn, or a visit if it moves. Seeing it there again within 2 days is the same appearance.
					For each pair, every appearance in one universe is checked against the other: is there an appearance in the <b>same sector within &plusmn;<?= $windowDays ?> day<?= $windowDays === 1 ? '' : 's' ?></b>,
					or in that sector <b>at any time</b>? If the first is close to the second, the universes follow each other in time; if only the second is high, they use the same places but not in step.
					Only the time both universes have sightings for is compared. Sightings are only recorded when a pilot sees the NPC, so gaps are normal.</p>

				<h3>Side by side</h3>
				<p class="note">Each row is one <?= $h($stats['period']) ?> (UTC). A sector <span class="match">highlighted like this</span> also shows up in another universe in the same row.</p>
				<table class="stats compare">
					<tr><th><?= $h(ucfirst($stats['period'])) ?></th><?php foreach (Settings::UNIVERSE as $u) { ?><th><?= $h($u) ?></th><?php } ?></tr>
					<?php foreach ($stats['timeline'] as $start => $cols) {
						// sectors seen in more than one universe in this row
						$seen = [];
						foreach ($cols as $u => $list) { foreach ($list as $a) { $seen[$a['sector']][$u] = true; } }
					?>
					<tr>
						<td class="l" style="white-space:nowrap;"><?= $h($periodLabel((string) $start, $stats['period'])) ?></td>
						<?php foreach (Settings::UNIVERSE as $u) { ?>
						<td class="l">
							<?php foreach ($cols[$u] ?? [] as $a) {
								$match = count($seen[$a['sector']]) > 1;
								$ids = array_keys($a['ids']);
								$tip = sprintf('%d sighting%s%s', $a['sightings'], $a['sightings'] === 1 ? '' : 's', $ids ? ', ID ' . implode(', ', $ids) : '');
							?>
							<div class="app" title="<?= $h($tip) ?>">
								<a href="<?= $h($sectorUrl($u, $a['sector'])) ?>"<?= $match ? ' class="match"' : '' ?>><?= $h($a['sector']) ?></a> [<?= $a['x'] ?>,<?= $a['y'] ?>]
								<span class="when"><?= $when(gmdate('Y-m-d H:i:s', $a['first'])) ?></span>
							</div>
							<?php } ?>
						</td>
						<?php } ?>
					</tr>
					<?php } ?>
				</table>

<?php } else { /* flight */ ?>
				<?php require templates('flightmap'); ?>
<?php } ?>
			</div>
			</center>
		</div>
		<div id="footer"><?php require_once(templates('footer')); ?></div>
		<script type="text/javascript" src="<?= $base_url; ?>/resources/main.js"></script>
		<script type="text/javascript">if (typeof pmLocalizeTimes === 'function') { pmLocalizeTimes(document); }</script>
	</body>
</html>
