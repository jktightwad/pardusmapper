<?php
declare(strict_types=1);
/** @var string $css */
?>
<html>
	<head>
		<title>News Page</title>
		<link rel="stylesheet" type="text/css" href="<?php echo $css; ?>" />
	</head>
	<body>
		<div id="header_side"><?php require_once(templates('header_side')); ?></div>
		<div id="footer"><?php require_once(templates('footer')); ?></div>
		<div id="body">
			<ul>
				<li><h1>Rare NPC Stats and Map Fixes (November 2026)</h1>
				<ul>
					<li><h2>Rare NPC Stats</h2>
					<ul>
						<li>New <b>Rare NPC Stats</b> page in the menu, for logged-in pilots whose mapper script has sent data in the last 48 hours
						<li>Tracks the Feral Serpent, Shadow, Hidden Drug Stash, Cyborg Corebreaker and Pirate Captain, with sightings from Orion, Artemis and Pegasus combined
						<li>See how many separate sightings and unique NPC IDs each one has, and a map of every sector and tile it has been seen in
						<li>Feral Serpent flight paths: its moves drawn on a universe map, including which way it flies and where it teleports
						<li>On any sector map, tick <b>Rare NPC History</b> to see where these NPCs have been spotted before
					</ul>
					<li><h2>Map Fixes</h2>
					<ul>
						<li>Pardus Core sectors: the cluster minimap is smaller and no longer covers the sector map. Large sectors scroll beside it, and the row and column numbers stay in view
						<li>NPC tracking is more accurate: NPCs sharing a tile (Vyrex, Pirates, Exocrabs, hole monsters) are each kept, and one NPC's ID no longer overwrites another's. Hover over a tile to see every NPC on it
					</ul>
				</ul>
				<li><h1>Solarix's IP</h1>
				<ul>
					<li>I have finally gotten around to adding Solarix's IP to the choices on the Options Page. Everone enjoy these wonderful images
				</ul>
				<li><h1>Script Version 3.9</h1>
				<ul>
					<li>I have not been very good at updating this page so I am going to add all the updates between version 3.2 and 3.9 here.
					<li><h2>3.9</h2>
					<ul>
						<li>Updated script to allow remote updating of Prices and Stocks for Buildings accessable from the Overview->Buildings Tab
						<li>Fixed a bug in the mainMap function when I updated it to work with the FF Coords Addon
					</ul>
					<li><h2>3.8</h2>
					<ul>
						<li>Updated script mainMap function to work with Coords Addon for FF
					</ul>
					<li><h2>3.7</h2>
					<ul>
						<li>Added userID collection to allow for name changes without having to create new account
					</ul>
					<li><h2>3.6</h2>
					<ul>
						<li>Added in functionality to Grab Equipment Info from Starbases and Building info from Overview Screen
						<li>Changed import script names on server to help troubleshooting and hopefully speed up the script and server
					</ul>
					<li><h2>3.5</h2>
					<ul>
						<li>Updated script for new "ID" tags add by Pardus Devs added in a Testing Flag to send data to Test Site
					</ul>
					<li><h2>3.4</h2>
					<ul>
						<li>Fixed a bug for FF 3.6 and background Images
					</ul>
					<li><h2>3.3</h2>
					<ul>
						<li>Fixes the disappearing Wormhole Bug
					</ul>
				</ul>
				<li><h1>New Site Look</h1>
				<ul>
					<li>I have moved the Test Site over to Production and I am working through a few bugs that have cropped up
					<li>PM me in-game if you find anything that isn't working correctly
				</ul>
				<li><h1>Test Site</h1>
				<ul>
					<li>If you are reading this then you are viewing the New Test Site.  Enjoy.</li>
				</ul>
				<li><h1>Script Version 3.2</h1>
				<ul>
					<li><h2>Ship Combat Bug</h2>
					<ul>
						<li>Glemlin pointed out a bug in Ship combat where if you have DC or OC error messages where appearing on the bottom of the
							screen.
						<li>Fixed in version 3.2
					</ul>
				</ul>
				<li><h1>Automation Continued</h1>
				<ul>
					<li>I have changed the log out link to point towards a user options page. This page now allows you to change your password as
						well as logging out.
				</ul>
				<li><h1>Automated Account Sign Up</h1>
				<ul>
					<li>I have added a link on the Log in Page to allow a user to set their password for the web site. You can still PM me ingame
						to set your password for you if you like or if the automation fails.
					<li>I am working on an Account page where a user can change their password once it has been set. Until that time if a password needs
						changed you will need to PM me in the game.
				</ul>
				<li><h1>Gem Merchant Tracking</h1>
				<ul>
					<li>I have added a new link in the header to bring up a listing of all known Gem Merchants in a Universe
					<li>Contains Links to Cluster Maps, Sector Maps, and Building information (if available)
				</ul>
				<li><h1>Bug Found with script for Firefox users in version 3</h1>
				<ul>
					<li>I fixed a bug that was found that kept Firefox from updating the Mapper. After fixing the problem I had trouble getting
						the new script installed.  I ended up having to unistall my Greasemonkey script, restart Firefox, then reinstall my script.
					<li>Not sure why I had this difficulty, Please PM Nhyujm (Orion), Cpt Zaq (Artemis), Zaqwer (Pegasus) if you have any additional
						problems or notice the script not loading data.
				</ul>
				<li><h1> Mission Listings</h1>
				<ul>
					<li><h2>For those who have taken the time to request an Account you can now see Missions under the following criteria</h2>
					<ul>
						<li>You must log in
						<li>You will only see your Faction Missions, Syndicate Missions and Neutral Missions based on your account
						<li>You will only see Missions with +- 2 of your Current Rank/Comp Level
						<li>Additional Filtering is in the Works
					</ul>
					<li><h2>I am currently testing the newest script to handle the missions page. I will post here when the new version is ready</h2>
				</ul>
					
				<li><h1> I have moved host from http://zaqwer.comlic.com to http://mapper.pardus-alliance.com</h1>
				<ul>
					<li>I hope this move will greatly increase the stability of my site and eliminate those annoying max-connection errors
				</ul>
				<li><h1> I have stopped updating the Firefox extension to focus on the User Script</h1>
				<ul>
					<li>Since the User Script can be used in Firefox with Greasemonkey I have stopped updating so I can focus on one script that can be used by all browsers
				</ul>
				<li><h1> Version 3.0 of the User Script is now online</h1>
				<ul>
					<li><h2> This Version of the User Script incudes the following updates </h2>
					<ul> 
						<li>Updates to the New Host
						<li>Allows the user to disable portions of the script
						<li>Collect Mission Information from SBs and Planets
					</ul>
				</ul>
			</ul>
		</div>
	</body>
</html>

