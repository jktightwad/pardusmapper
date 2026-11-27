<?php
declare(strict_types=1);
/** @var string $base_url */
/** @var string $img_url */
/** @var string $css */
/** @var string $game_css */
/** @var string $cluster_css */
/** @var string $uni */
/** @var string $sector */
/** @var string $cluster */
/** @var string $title */
/** @var bool $shownpc */
/** @var int $id */
/** @var int $security */
/** @var object $s */
?>
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml">
	<head>
		<meta http-equiv="Content-Type" content="text/html" />
		<meta http-equiv="Content-Style-Type" content="text/css" />
		<meta http-equiv="Content-Script-Type" content="text/javascript" />
		<title><?php echo $title;?></title>
		<link rel="stylesheet" type="text/css" href="<?php echo $css; ?>" />
		<link rel="stylesheet" type="text/css" href="<?php echo $game_css; ?>" />
		<link rel="stylesheet" type="text/css" href="<?php echo $cluster_css; ?>" />
		<style type="text/css">
			/* sighting count, inside the tile's bottom-right corner (its td is position:relative) */
			.npc-count-badge {
				position: absolute;
				bottom: 1px;
				right: 1px;
				background: rgba(200, 0, 0, 0.9);
				color: white;
				font-size: 9px;
				line-height: 10px;
				font-weight: bold;
				padding: 0 2px;
				border-radius: 3px;
				border: 1px solid white;
				z-index: 40;
				pointer-events: none;
			}
			/* past sighting (NPC not there now): nearly full brightness with a gold glow to set it apart */
			#sectorTableMap img.history-npc { opacity: 0.9; filter: drop-shadow(0 0 3px #ffd34d); }
			/* keep column numbers (top) and row numbers (left) visible while scrolling the map */
			#sectorTableMap thead th { position: sticky; top: 0; z-index: 60; background-color: #94BABA; opacity: 1; }
			#sectorTableMap tbody tr > th:first-child { position: sticky; left: 0; z-index: 55; background-color: #94BABA; opacity: 1; }
			#sectorTableMap thead th:first-child { left: 0; z-index: 65; }
		</style>
		<script type="text/javascript" src="<?php echo $base_url; ?>/resources/main.js"></script>
		<script type="text/javascript">
			//<![CDATA[
			function getCheckedValue(radioObj) {
				if(!radioObj)
					return "all";
				var radioLength = radioObj.length;
				if(radioLength == undefined)
					if(radioObj.checked)
						return radioObj.value;
					else
						return "";
				for(var i = 0; i < radioLength; i++) {
					if(radioObj[i].checked) {
						return radioObj[i].value;
					}
				}
				return "";
			}
			// Remember the "Show Rare NPC History" choice in this browser across sectors and visits
			function restoreHistoryToggle() {
				var hist = document.getElementById('hist');
				if (!hist) { return; }
				try { hist.checked = (window.localStorage.getItem('pm_show_history') === '1'); } catch (e) {}
			}
			function toggleHistory(hist) {
				try { window.localStorage.setItem('pm_show_history', hist.checked ? '1' : '0'); } catch (e) {}
				updateMap();
			}
			function loadMap() {
				restoreHistoryToggle();
			<?php
				if (isset($_REQUEST['x1']) && isset($_REQUEST['y1'])) {
					$loc = $s->s_id + $_REQUEST['y1'] + ($s->rows * $_REQUEST['x1']);
					echo 'loadDetail(\'' . $base_url . '\',\'' . $uni . '\',' . $loc . ');';
				}
			?>
				updateMap();
			}
			function updateMap() {
				var url = <?php echo '"' . $base_url . '"'; ?> + "/info/map.php";
				var mode = getCheckedValue(document.getElementById('rf').mode);
				var grid = getCheckedValue(document.getElementById('gl').mode);
				var whole = getCheckedValue(document.getElementById('wh').mode);
				var hist = document.getElementById('hist');
				var history = (hist && hist.checked) ? 1 : 0;
				var params = "uni=" + <?php echo '"' . $uni . '"'; ?> + "&sector=" + <?php echo '"' . $sector . '"'; ?> + "&img_url=" + <?php echo '"' . $img_url . '"'; ?> + "&shownpc=" + <?php echo '"' . $shownpc . '"'; ?> + "&mode=" + mode + "&grid=" + grid + "&whole=" + whole + "&history=" + history;
				<?php if (!is_null($id)) { echo 'params += "&loc=" + \'' . $id . '\''; } ?>
				maphttp.open("POST",url,true);
				maphttp.setRequestHeader("Content-type", "application/x-www-form-urlencoded");
				maphttp.onreadystatechange = function () {
					if (maphttp.readyState == 4) {
						var el = document.getElementById("sectorMapDiv");
						el.innerHTML = maphttp.responseText;
						if (typeof pmLocalizeTimes === 'function') { pmLocalizeTimes(el); }
						fitMapBesideMinimap();
					}
				}
				maphttp.send(params);
			}
			// Size and place the map box. The side menu is fixed to the left edge and the cluster minimap
			// to the right edge of the window. The box starts just right of the menu, and if a wide sector
			// (e.g. Pardus) would run underneath the minimap, the box stops at the minimap's left edge and
			// the map scrolls inside it (both ways, so the scrollbars stay on screen).
			function fitMapBesideMinimap() {
				var el = document.getElementById("sectorMapDiv");
				var table = document.getElementById("sectorTableMap");
				if (!el || !table) { return; }
				var w, h;
				function sizeBoxToMap() {
					var r = table.getBoundingClientRect(); // includes the zoom, if any
					w = Math.ceil(r.width);
					h = Math.ceil(r.height);
					el.style.width = w + "px";
					el.style.height = h + "px";
				}
				sizeBoxToMap();
				el.style.overflow = "";
				el.style.paddingTop = "";
				el.style.top = "";
				el.style.left = "";

				// The side menu is fixed to the left edge of the window; start the map box just right of it.
				var menu = document.getElementById("header_side");
				if (menu) {
					var gap = menu.getBoundingClientRect().right + 4 - el.getBoundingClientRect().left;
					if (gap > 0) {
						el.style.left = ((parseFloat(window.getComputedStyle(el).left) || 0) + gap) + "px";
					}
				}

				var minimap = document.getElementById("clusterMapDiv");
				var limit = minimap ? minimap.getBoundingClientRect().left : window.innerWidth;
				var box = el.getBoundingClientRect();
				var available = limit - box.left - 6;
				if (box.width <= available || available < 300) { return; } // fits, or window too narrow to help

				// The top padding holds the options bar. Move the box down by that much instead (via its
				// relative top offset) so map tiles don't scroll up behind the options bar.
				var cs = window.getComputedStyle(el);
				var padTop = parseFloat(cs.paddingTop) || 0;
				el.style.paddingTop = "0px";
				el.style.top = ((parseFloat(cs.top) || 0) + padTop) + "px";
				box = el.getBoundingClientRect();

				var chromeW = box.width - w;  // left/right padding + border around the map
				var chromeH = box.height - h; // top/bottom padding + border (top padding holds the options bar)
				el.style.width = (available - chromeW) + "px";
				el.style.height = Math.max(300, Math.min(h, window.innerHeight - Math.max(0, box.top) - chromeH - 10)) + "px";
				el.style.overflow = "auto";
			}
			window.addEventListener("resize", fitMapBesideMinimap);
			var maphttp = getXMLHttpObject();
			var overviewhttp = getXMLHttpObject();
			var detailhttp = getXMLHttpObject();
			window.onload = loadMap;
			//]]>
		</script>
	</head>
	<body>
		<div id="header_side"><?php require_once(templates('header_side')); ?></div>
		<div id="footer"><?php require_once(templates('footer')); ?></div>
		<div id="details" name="game"><div id="close_detail"><a href="#" onClick="closeDetail();return false;">Close Detail</a></div><div id="d_con"></div></div>
		<div id="overview" name="game"></div>
		<div id="body">
			<?php // The Pardus Core cluster overview shows all four P*C clusters, so it is drawn at 70% ?>
			<div id="clusterMapDiv"<?php if (($code ?? '') === 'CORE') { echo ' style="zoom:0.7;"'; } ?> onmouseover="this.style.zIndex=20" onmouseout="this.style.zIndex=0">
                <?php 
                    $url = rtrim((string) $base_url) . '/' . $uni . '/';
                    require_once(clusters(strtolower((string) $cluster)));
                ?>
            </div>
			<div id="mapSelection" style="width:auto; white-space:nowrap;">
				<form id="rf" action="" style="display:inline;">
					<input type="radio" onclick="updateMap()" name="mode" value="all" checked />All
					<input type="radio" onclick="updateMap()" name="mode" value="buildings" />Buildings
					<input type="radio" onclick="updateMap()" name="mode" value="npcs" />NPCs
					<input type="radio" onclick="updateMap()" name="mode" value="none" />None
				</form>
				<form id="gl" action="" style="display:inline;">
					<input type="radio" onclick="updateMap()" name="mode" value="1" checked />Grid Lines
					<input type="radio" onclick="updateMap()" name="mode" value="0" />No Grid Lines
				</form>
				<?php if (isset($_SESSION['user'])) { ?>
				<form id="history_toggle" action="" style="display:inline; margin-left: 10px; border-left: 1px solid #666; padding-left: 10px;">
					<input type="checkbox" id="hist" onclick="toggleHistory(this)" name="history" value="1" /> <span title="Feral Serpent, Shadow, Hidden Drug Stash, Cyborg Corebreaker and Pirate Captain sightings from Orion, Artemis and Pegasus combined">Rare NPC History</span>
				</form>
				<?php } ?>
				<?php
				if ($security==100){
					echo '<form id="wh" action="" style="display:inline;"><input type="radio" onclick="updateMap()" name="mode" value="0" checked />Navigate WH<input type="radio" onclick="updateMap()" name="mode" value="1" />Remove WH</form>';
				} else {
					// Non-admins only ever navigate wormholes, so there is no choice to show. Keep the (hidden)
					// field so updateMap() still sends whole=0.
					echo '<form id="wh" action="" style="display:none;"><input type="radio" name="mode" value="0" checked /></form>';
				}
				?>
			</div>
			<div id="sectorMapDiv"></div>
		</div>
	</body>
</html>