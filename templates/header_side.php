<?php
declare(strict_types=1);
/** @var string $base_url */
/** @var string $cluster */
?>
<table id="header_table" border="2" cellspacing="5" cellpadding="5">
	<?php 
		echo '<tr><td><a href="' . $base_url . '">Change Universe</a></td></tr>';
		if (isset($_REQUEST['uni'])) {echo '<tr><th><a href="' . $base_url . '/' . $_REQUEST['uni'] . '">' . $_REQUEST['uni'] . '</a></th></tr>';}
		// Sector List / Gem Merchant List load in place on the universe map (templates/map.php defines
		// getSectors/getGemMerchant). Other pages (NPC List, Mission List, Options...) don't have those
		// functions, so the link falls back to the universe map, which opens the list on load.
		$menu_uni = htmlspecialchars((string) ($_REQUEST['uni'] ?? ''), ENT_QUOTES);
		if (!(isset($_REQUEST['sector']) || isset($_REQUEST['cluster']))) {echo '<tr><td><a href="' . $base_url . '/index.php?uni=' . $menu_uni . '&amp;sectors=1" onClick="if (typeof getSectors === \'function\') { getSectors(\'' . $menu_uni . '\'); return false; }">Sector<br />List</a></td></tr>';}
		if (isset($_REQUEST['cluster'])) { echo '<tr><th><a href="' . $base_url . '/' . $_REQUEST['uni'] . '/' . $_REQUEST['cluster'] . '">' . $_REQUEST['cluster'] . '</a></th></tr>'; }
		if (isset($_REQUEST['sector'])) {
			// Ensure $cluster is a string and handle null or object cases
			if (is_object($cluster)) {
				// Extract the relevant property if $cluster is an object
				$cluster = $cluster->code ?? 'Unknown Cluster';
			} else if(!is_string($cluster)){
				// Handle cases where $cluster is null or a string
				$cluster = 'Unknown Cluster';
			}

			// Output using the $cluster variable
			echo '<tr><th><a href="' . $base_url . '/' . $_REQUEST['uni'] . '/' . urlencode((string) $cluster) . '">' . htmlspecialchars((string) $cluster) . '</a></th></tr>';
			//echo '<tr><th><a href="' . $base_url . '/' . $_REQUEST['uni'] . '/' . urlencode($_REQUEST['sector']) . '">' . htmlspecialchars(str_replace(" ", "<br />", $_REQUEST['sector'])) . '</a></th></tr>';
			//echo '<pre>' . print_r($_REQUEST['sector'], true) . '</pre>';
			//echo '<tr><th><a href="' . $base_url . '/' . $_REQUEST['uni'] . '/' . urlencode($_REQUEST['sector']) . '">' . htmlspecialchars(str_replace(" ", "<br />", urldecode($_REQUEST['sector']))) . '</a></th></tr>';
			echo '<tr><th><a href="' . $base_url . '/' . $_REQUEST['uni'] . '/' . rawurlencode((string) $_REQUEST['sector']) . '">'
				. str_replace(" ", "&nbsp;<br />", htmlspecialchars(urldecode((string) $_REQUEST['sector'])))
				. '</a></th></tr>';
		}

			
		if (isset($_REQUEST['sector'])) {
			// Set Upkeep Link
			echo '<tr><td><a href="' . $base_url;
			echo (isset($_REQUEST['uni'])) ? '/' . $_REQUEST['uni'] : '';
			echo (isset($_REQUEST['sector'])) ?  '/' . $_REQUEST['sector'] : '';
			echo '/resources">Upkeep<br />Table</a></td></tr>';
		}
					
		// Set NPC Link
		echo '<tr><td><a href="' . $base_url;
		echo (isset($_REQUEST['uni'])) ? '/' . $_REQUEST['uni'] : '';
		echo (isset($_REQUEST['cluster'])) ? '/' . $_REQUEST['cluster'] : '';
		echo (isset($_REQUEST['sector'])) ? '/' . $_REQUEST['sector'] : '';
		echo '/npc">NPC<br />List</a></td></tr>';
						
		// Set Mission Link
		echo '<tr><td><a href="' . $base_url;
		echo (isset($_REQUEST['uni'])) ? '/' . $_REQUEST['uni'] : '';
		echo (isset($_REQUEST['cluster'])) ? '/' . $_REQUEST['cluster'] : '';
		echo (isset($_REQUEST['sector'])) ? '/' . $_REQUEST['sector'] : '';
		echo (isset($_REQUEST['x1']) && isset($_REQUEST['y1'])) ? '/' . $_REQUEST['x1'] . '/' . $_REQUEST['y1'] : '';
		echo '/mission">Mission<br />List</a></td></tr>';
						
		if (isset($_SESSION['security']) && $_SESSION['security'] == 100) {
			// Set Owner Link
			echo '<tr><td><a href="' . $base_url;
			echo (isset($_REQUEST['uni'])) ? '/' . $_REQUEST['uni'] : '';
			echo (isset($_REQUEST['cluster'])) ? '/' . $_REQUEST['cluster'] : '';
			echo (isset($_REQUEST['sector'])) ? '/' . $_REQUEST['sector'] : '';
			echo '/owners">Owner<br />List</a></td></tr>';
		}
		
		if (!(isset($_REQUEST['sector']) || isset($_REQUEST['cluster']))) {
			echo '<tr><td><a href="' . $base_url . '/' . $menu_uni . '/gems" onClick="if (typeof getGemMerchant === \'function\') { getGemMerchant(\'' . $menu_uni . '\'); return false; }">Gem<br />Merchant<br />List</a></td></tr>';
		}
		if (!isset($_SESSION['user'])) { echo '<tr><td><a href="' . $base_url . '/' . $_REQUEST['uni']. '/login.php">Log In</a></td></tr>'; }
		else { echo '<tr><td><a href="' . $base_url . '/' . $_REQUEST['uni'] . '/logout.php">Log Out</a></td></tr>'; }
		echo '<tr><td><a href="' . $base_url . '/' . $_REQUEST['uni'] . '/options.php">Options</a></td></tr>';
		echo '<tr><td><a href="' . $base_url . '/' . $_REQUEST['uni']. '/info">News</a></td></tr>';
		
	?>
</table>
