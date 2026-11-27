<?php
declare(strict_types=1);

/** @var string $base_url */
/** @var string $css */
/** @var string $uni */
/** @var string $title */
/** @var array $stats */

$h = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES);
// Database times are UTC. Each is printed as "YYYY-MM-DD HH:MM UTC" and rewritten to the viewer's
// local time by pmLocalizeTimes() (resources/main.js) when the page loads.
$utcAttr = static fn (string $d): string => sprintf(' data-utc="%sZ" data-utc-text="%s UTC"', str_replace(' ', 'T', substr($d, 0, 19)), substr($d, 0, 16));
$utcText = static fn (string $d): string => substr($d, 0, 16) . ' UTC';
$when = static fn (?string $d): string => is_null($d) ? '-' : '<span' . $utcAttr($d) . '>' . $utcText($d) . '</span>';
$statsUrl = $base_url . '/' . $uni . '/npcstats';
$sectorUrl = static fn (string $u, string $sector): string => $base_url . '/' . $u . '/' . rawurlencode($sector);

/**
 * Small SVG of a sector grid with marked tiles.
 * $marks: list of ['x','y','label','weight' 0..1, 'active' (optional), 'utc' (optional: a time in the label)]
 */
$sectorSvg = static function (?object $dim, array $marks) use ($h, $utcAttr): string {
    $cell = 7;
    $cols = $dim ? max(1, (int) $dim->cols) : 1 + max(array_column($marks, 'x') ?: [0]);
    $rows = $dim ? max(1, (int) $dim->rows) : 1 + max(array_column($marks, 'y') ?: [0]);
    $w = $cols * $cell;
    $hgt = $rows * $cell;
    $svg = sprintf('<svg class="minimap" width="%d" height="%d" viewBox="0 0 %d %d" xmlns="http://www.w3.org/2000/svg">', $w, $hgt, $w, $hgt);
    $svg .= sprintf('<rect width="%d" height="%d" class="mm-bg"/>', $w, $hgt);
    foreach ($marks as $m) {
        $opacity = 0.35 + 0.65 * max(0.0, min(1.0, (float) $m['weight']));
        $svg .= sprintf(
            '<rect x="%d" y="%d" width="%d" height="%d" class="mm-mark%s" fill-opacity="%.2f"><title%s>%s</title></rect>',
            $m['x'] * $cell, $m['y'] * $cell, $cell, $cell, empty($m['active']) ? '' : ' mm-active', $opacity,
            empty($m['utc']) ? '' : $utcAttr($m['utc']), $h($m['label'])
        );
    }
    return $svg . '</svg>';
};
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
			#stats svg.minimap { display: block; margin: 4px auto; }
			#stats svg .mm-bg { fill: #0b1a22; stroke: #334649; }
			#stats svg .mm-mark { fill: #ff5a3c; }
			#stats svg .mm-active { fill: #6f6; }
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
					<?php if ($stats['view'] === 'trail') { ?> &raquo; <?= $h($stats['u']) ?> ID <?= $h($stats['nid']) ?><?php } ?>
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
				<p class="note">The mapper adds a record each time a pilot's script first sees one of these NPCs on a tile. A moving or flickering NPC makes many records,
					so <b>Sightings</b> only counts a record if the same NPC had no record in that universe in the 2 hours before it (hover a number for the raw record count).
					The NPC ID is only recorded when a pilot stands on its tile; records without an ID are not counted if any record of that NPC came less than 2 hours before.
					IDs have been recorded since early 2021. All times are when the NPC was first seen on a tile, shown in your local time.
					"Seen now" is how many have not yet been reported gone.</p>

<?php } elseif ($stats['view'] === 'npc') { ?>
				<h3>Unique NPC IDs</h3>
				<?php if (count($stats['ids']) === 0) { ?>
					<p class="note">No sightings with an NPC ID yet.</p>
				<?php } else { ?>
				<table class="stats">
					<tr><th>Universe</th><th>NPC ID</th><th>Sightings</th><th>Sectors</th><th>Last seen at</th><th>First seen</th><th>Last seen</th><th>Trail</th></tr>
					<?php foreach ($stats['ids'] as $id) { ?>
					<tr>
						<td><?= $h($id['uni']) ?></td>
						<td><?= $id['nid'] ?></td>
						<td><?= $id['sightings'] ?></td>
						<td><?= count($id['sectors']) ?></td>
						<td class="l"><?= $h($id['where']) ?><?= $id['active'] ? ' <span class="live">(seen now)</span>' : '' ?></td>
						<td><?= $when($id['first']) ?></td>
						<td><?= $when($id['last']) ?></td>
						<td><a href="<?= $h($statsUrl . '?npc=' . $stats['slug'] . '&u=' . $id['uni'] . '&nid=' . $id['nid']) ?>">View trail</a></td>
					</tr>
					<?php } ?>
				</table>
				<?php } ?>
				<?php if (count($stats['noid'])) { ?>
					<p class="note">Plus sightings without an NPC ID (seen from a distance, or before 2021):
					<?php $parts = []; foreach ($stats['noid'] as $u => $n) { $parts[] = $h($u) . ' ' . $n; } echo implode(', ', $parts); ?>.
					They are included in the sector maps below.</p>
				<?php } ?>

				<h3>Where it has been seen (all IDs, all universes)</h3>
				<p class="note">Busiest sectors first. Numbers are visits to a tile (the same NPC on the same tile less than 2 hours apart is one visit).
					Brighter tiles have more visits; green tiles have one there now. Click a sector name to open its map.</p>
				<div class="cards">
				<?php foreach ($stats['sectors'] as $name => $sec) {
					$max = max($sec['tiles']);
					$marks = [];
					foreach ($sec['tiles'] as $tile => $n) {
						[$tx, $ty] = array_map('intval', explode(',', $tile));
						$live = isset($sec['live'][$tile]);
						$marks[] = ['x' => $tx, 'y' => $ty, 'weight' => $max > 1 ? ($n - 1) / ($max - 1) : 1, 'active' => $live,
							'label' => sprintf('[%d,%d] %d visit%s%s', $tx, $ty, $n, $n === 1 ? '' : 's', $live ? ', there now' : '')];
					}
					$byUni = [];
					foreach ($sec['unis'] as $u => $n) { $byUni[] = $u . ' ' . $n; }
				?>
					<div class="card">
						<div class="sname"><a href="<?= $h($sectorUrl($uni, (string) $name)) ?>"><?= $h($name) ?></a></div>
						<?= $sectorSvg($stats['dims'][$name] ?? null, $marks) ?>
						<?= $sec['total'] ?> visit<?= $sec['total'] === 1 ? '' : 's' ?>, <?= count($sec['ids']) ?> ID<?= count($sec['ids']) === 1 ? '' : 's' ?><br />
						<?= $h(implode(', ', $byUni)) ?><br />
						last seen <?= $when($sec['last']) ?>
					</div>
				<?php } ?>
				</div>

<?php } else { /* trail */ ?>
				<?php if (count($stats['steps']) === 0) { ?>
					<p class="note">No sightings found for this NPC ID.</p>
				<?php } else { ?>
				<p class="note">Sectors in the order this NPC was seen in them. Within a sector, later stops are brighter; hover a tile for its order and when it was first seen there. Green means it is there now. Times are your local time.</p>
				<div class="cards">
				<?php foreach ($stats['steps'] as $i => $step) {
					$count = count($step['points']);
					$marks = [];
					foreach ($step['points'] as $p => $pt) {
						$marks[] = ['x' => $pt['x'], 'y' => $pt['y'], 'weight' => $count > 1 ? $p / ($count - 1) : 1, 'active' => $pt['active'], 'utc' => $pt['spotted'],
							'label' => sprintf('#%d [%d,%d] first seen %s%s', $p + 1, $pt['x'], $pt['y'], $utcText($pt['spotted']), $pt['active'] ? ', still there' : '')];
					}
				?>
					<?php if ($i > 0) { ?><span class="arrow">&rarr;</span><?php } ?>
					<div class="card">
						<div class="sname"><?= $i + 1 ?>. <a href="<?= $h($sectorUrl($stats['u'], $step['sector'])) ?>"><?= $h($step['sector']) ?></a></div>
						<?= $sectorSvg($stats['dims'][$step['sector']] ?? null, $marks) ?>
						<?= $count ?> stop<?= $count === 1 ? '' : 's' ?><?= $step['active'] ? ' <span class="live">here now</span>' : '' ?><br />
						<?= $when($step['from']) ?><?= $step['to'] !== $step['from'] ? ' to ' . $when($step['to']) : '' ?>
					</div>
				<?php } ?>
				</div>
				<?php } ?>
<?php } ?>
			</div>
			</center>
		</div>
		<div id="footer"><?php require_once(templates('footer')); ?></div>
		<script type="text/javascript" src="<?= $base_url; ?>/resources/main.js"></script>
		<script type="text/javascript">if (typeof pmLocalizeTimes === 'function') { pmLocalizeTimes(document); }</script>
	</body>
</html>
