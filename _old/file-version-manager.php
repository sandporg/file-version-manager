<?php
/*
Plugin Name: File Version Manager
Description: Conveniently upload and update files site-wide.
Version: 0.14.2
Author: Riley Sandborg
Author URI: https://rileysandb.org/
License: GPLv2 or later
Plugin URI: https://github.com/rsandb/file-version-manager/
Text Domain: file-version-manager
*/

#NOTE: This plugin is compatible with the Big File Uploads plugin and displays a link to change the upload size if it is active

namespace FVM\FileVersionManager;

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

// Define constants
define( 'FVM_VERSION', '0.14.2' );
define( 'FILE_TABLE_NAME', 'fvm_files' );
define( 'CAT_TABLE_NAME', 'fvm_categories' );
define( 'REL_TABLE_NAME', 'fvm_relationships' );
define( 'UPLOAD_DIR', 'filebase' );
define( 'PLUGIN_DIR', __DIR__ . '/../' );

define( 'FVM\FileVersionManager\PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/ajax-handler.php';

require_once __DIR__ . '/includes/class-fvm-plugin.php';
require_once __DIR__ . '/includes/class-fvm-file-manager.php';
require_once __DIR__ . '/includes/class-fvm-file-page.php';
require_once __DIR__ . '/includes/class-fvm-settings-page.php';
require_once __DIR__ . '/includes/class-fvm-shortcode.php';
require_once __DIR__ . '/includes/class-fvm-activate.php';
require_once __DIR__ . '/includes/class-fvm-deactivate.php';
require_once __DIR__ . '/includes/class-fvm-file-list-table.php';
require_once __DIR__ . '/includes/class-fvm-migrate-wpfb.php';
require_once __DIR__ . '/includes/class-fvm-category-page.php';
require_once __DIR__ . '/includes/class-fvm-category-list-table.php';
require_once __DIR__ . '/includes/class-fvm-category-manager.php';
require_once __DIR__ . '/includes/class-fvm-db-upgrade.php';

global $wpdb;
$update_ids = new FVM_Migrate_WPFB( $wpdb );
$file_manager = new FVM_File_Manager( $wpdb );
$shortcode = new FVM_Shortcode( $wpdb );
$file_page = new FVM_File_Page( $file_manager );
$settings_page = new FVM_Settings_Page( $update_ids );
$category_manager = new FVM_Category_Manager( $wpdb );
$category_page = new FVM_Category_Page( $category_manager );
$database_upgrade = new FVM_Database_Upgrade( $wpdb );

$plugin = new FVM_Plugin(
	$file_manager,
	$file_page,
	$category_manager,
	$category_page,
	$settings_page,
	$shortcode,
	$database_upgrade
);

$plugin->init();

register_activation_hook( __FILE__, [ FVM_Activate::class, 'activate' ] );
register_deactivation_hook( __FILE__, [ FVM_Deactivate::class, 'deactivate' ] );

add_filter( 'upload_mimes', function ($mimes) {
	$mimes['xml'] = 'application/xml';
	return $mimes;
} );

/**
 * Add a link to the settings page in the plugins page
 */
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), function ($links) {
	$settings_link = '<a href="' . admin_url( 'admin.php?page=fvm_settings' ) . '">' . __( 'Settings', 'file-version-manager' ) . '</a>';
	array_unshift( $links, $settings_link );
	return $links;
} );

require 'vendor/plugin-update-checker/plugin-update-checker.php';
use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

$myUpdateChecker = PucFactory::buildUpdateChecker(
	'https://github.com/rsandb/file-version-manager/',
	__FILE__,
	'file-version-manager'
);

//Set the branch that contains the stable release.
$myUpdateChecker->setBranch( 'main' );

//Optional: If you're using a private repository, specify the access token like this:
// $myUpdateChecker->setAuthentication( 'your-token-here' );