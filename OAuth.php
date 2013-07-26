<?php
/*
 (c) Aaron Schulz 2013, GPL

 This program is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License as published by
 the Free Software Foundation; either version 2 of the License, or
 (at your option) any later version.

 This program is distributed in the hope that it will be useful,
 but WITHOUT ANY WARRANTY; without even the implied warranty of
 MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 GNU General Public License for more details.

 You should have received a copy of the GNU General Public License along
 with this program; if not, write to the Free Software Foundation, Inc.,
 59 Temple Place - Suite 330, Boston, MA 02111-1307, USA.
 http://www.gnu.org/copyleft/gpl.html
*/

if ( !defined( 'MEDIAWIKI' ) ) {
	echo "OAuth extension\n";
	exit( 1 ) ;
}

$wgExtensionCredits['other'][] = array(
	'path'           => __FILE__,
	'name'           => 'OAuth 1.0a API authentication',
	'descriptionmsg' => 'mwoauth-desc',
	'author'         => array( 'Aaron Schulz' ),
	'url'            => 'https://www.mediawiki.org/wiki/Extension:OAuth',
);

# Load default config variables
require( __DIR__ . '/OAuth.config.php' );

# Define were PHP files and i18n files are located
require( __DIR__ . '/OAuth.setup.php' );
MWOAuthSetup::defineSourcePaths( $wgAutoloadClasses, $wgExtensionMessagesFiles );

# Define JS/CSS modules and file locations
MWOAuthUISetup::defineResourceModules( $wgResourceModules );

$wgLogTypes[] = 'mwoauthconsumer';
# Log name and description as well as action handlers
MWOAuthUISetup::defineLogBasicDescription( $wgLogNames, $wgLogHeaders, $wgFilterLogTypes );
MWOAuthUISetup::defineLogActionHandlers( $wgLogActions, $wgLogActionsHandlers );

# Basic hook handlers
MWOAuthSetup::defineHookHandlers( $wgHooks );
# GUI-related hook handlers
MWOAuthUISetup::defineHookHandlers( $wgHooks );
# API-related hook handlers
MWOAuthAPISetup::defineHookHandlers( $wgHooks );

# Actually register special pages and set default $wgMWOAuthCentralWiki
$wgExtensionFunctions[] = function() {
	global $wgMWOAuthCentralWiki, $wgSpecialPages, $wgSpecialPageGroups;

	if ( $wgMWOAuthCentralWiki === false ) {
		$wgMWOAuthCentralWiki = wfWikiId(); // default
	}
	MWOAuthUISetup::defineSpecialPages( $wgSpecialPages, $wgSpecialPageGroups );
};
