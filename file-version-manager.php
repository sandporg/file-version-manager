<?php
/*
Plugin Name: File Version Manager
Description: Conveniently upload and update files site-wide.
Version: 1.0.0
Author: Riley Sandborg
Author URI: https://rileysandb.org/
License: GPLv2 or later
Plugin URI: https://github.com/rsandb/file-version-manager/
Text Domain: file-version-manager
*/

#NOTE: This plugin is compatible with the Big File Uploads plugin and displays a link to change the upload size if it is active

namespace FVM\FileVersionManager;

// Prevent direct access
if ( ! defined( 'WPINC' ) ) {
	die;
}

// Plugin Constants
define( 'FVM_VERSION', '1.0.0' );
define( 'FVM_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'FVM_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'FVM_PLUGIN_FILE', __FILE__ ); // Define the main plugin file path

// Database Table Constants (Consider namespacing or prefixing further)
define( 'FVM_FILE_TABLE_NAME', 'fvm_files' );
define( 'FVM_CAT_TABLE_NAME', 'fvm_categories' );
define( 'FVM_REL_TABLE_NAME', 'fvm_relationships' );

// --- Core Includes --- 
// require_once FVM_PLUGIN_DIR . 'includes/functions.php'; // Removed - Functions moved or obsolete
require_once FVM_PLUGIN_DIR . 'includes/core/class-fvm-file-manager.php';
require_once FVM_PLUGIN_DIR . 'includes/core/class-fvm-category-manager.php';
require_once FVM_PLUGIN_DIR . 'includes/core/class-fvm-migrate-wpfb.php'; // Keep if still actively used
require_once FVM_PLUGIN_DIR . 'includes/core/class-fvm-activate.php';
require_once FVM_PLUGIN_DIR . 'includes/core/class-fvm-deactivate.php';
require_once FVM_PLUGIN_DIR . 'includes/core/class-fvm-upgrade.php'; // Include the upgrade class
require_once FVM_PLUGIN_DIR . 'includes/ajax/class-fvm-ajax-handler.php'; // New AJAX handler
require_once FVM_PLUGIN_DIR . 'includes/shortcodes/class-fvm-shortcode.php';
require_once FVM_PLUGIN_DIR . 'includes/admin/list-tables/class-fvm-file-list-table.php'; // Needs WP_List_Table
require_once FVM_PLUGIN_DIR . 'includes/admin/list-tables/class-fvm-category-list-table.php'; // Needs WP_List_Table

// --- Admin Page Includes ---
require_once FVM_PLUGIN_DIR . 'includes/admin/class-fvm-file-page.php';
require_once FVM_PLUGIN_DIR . 'includes/admin/class-fvm-category-page.php';
require_once FVM_PLUGIN_DIR . 'includes/admin/class-fvm-settings-page.php';
require_once FVM_PLUGIN_DIR . 'includes/admin/class-fvm-admin-notices.php';

// --- Main Plugin Class ---
require_once FVM_PLUGIN_DIR . 'includes/core/class-fvm-plugin.php';

// --- Vendor Includes ---
// Ensure the update checker is loaded correctly
if ( file_exists( FVM_PLUGIN_DIR . 'vendor/plugin-update-checker/plugin-update-checker.php' ) ) {
	require FVM_PLUGIN_DIR . 'vendor/plugin-update-checker/plugin-update-checker.php';
}

// --- Initialization --- 
global $wpdb;

// Instantiate Managers & Handlers (Dependencies)
$file_manager = new FVM_File_Manager( $wpdb );
$category_manager = new FVM_Category_Manager( $wpdb );
$ajax_handler = new FVM_Ajax_Handler( $file_manager, $category_manager );
$shortcode = new FVM_Shortcode( $wpdb, FVM_PLUGIN_URL );
$update_ids = new FVM_Migrate_WPFB( $wpdb ); // Keep if used by settings page
$fvm_upgrade = new FVM_Upgrade(); // Instantiate the upgrade handler

// Instantiate Notice Manager
$fvm_notice_manager = new FVM_Admin_Notices();
$fvm_notice_manager->init(); // Initialize notice hooks

// Instantiate Admin Pages
$file_page = new FVM_File_Page( $file_manager, FVM_PLUGIN_URL, FVM_PLUGIN_DIR, $fvm_notice_manager );
$category_page = new FVM_Category_Page( $category_manager, FVM_PLUGIN_URL, FVM_PLUGIN_DIR, $fvm_notice_manager );
$settings_page = new FVM_Settings_Page( $update_ids, FVM_PLUGIN_URL, $fvm_notice_manager );

// Instantiate Main Plugin Class
$plugin = new FVM_Plugin(
	$file_manager,
	$file_page,
	$category_manager,
	$category_page,
	$settings_page,
	$shortcode,
	$ajax_handler, // Pass AJAX handler
	FVM_PLUGIN_URL, // Pass URL
	FVM_PLUGIN_DIR, // Pass Dir
	FVM_VERSION // Pass Version
);

// Initialize the plugin (registers hooks)
$plugin->init();
$fvm_upgrade->init_hooks(); // Initialize upgrade handler hooks

// --- Activation / Deactivation Hooks --- 
register_activation_hook( __FILE__, [ FVM_Activate::class, 'activate' ] );
register_deactivation_hook( __FILE__, [ FVM_Deactivate::class, 'deactivate' ] );

// --- Other Hooks --- 
add_filter( 'upload_mimes', function ($mimes) {
	// Allow XML uploads if needed - consider if this is necessary
	$mimes['xml'] = 'application/xml';
	return $mimes;
} );

add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), function ($links) {
	$settings_link = '<a href="' . admin_url( 'admin.php?page=fvm_settings' ) . '">' . esc_html__( 'Settings', 'file-version-manager' ) . '</a>';
	array_unshift( $links, $settings_link );
	return $links;
} );

/**
 * Suppress standard admin notices on FVM pages.
 */
add_action( 'current_screen', function ($current_screen) {
	$fvm_pages = [ 
		'toplevel_page_fvm_files',
		'files_page_fvm_categories',
		'files_page_fvm_settings',
		// Add other FVM screen IDs if necessary
	];

	if ( in_array( $current_screen->id, $fvm_pages ) ) {
		// Remove the default admin_notices action output for this screen
		// This uses output buffering around the action execution.
		add_action( 'admin_print_scripts', function () {
			remove_all_actions( 'admin_notices' );
			remove_all_actions( 'all_admin_notices' );
		}, 1 );
	}
} );

// --- Update Checker Setup --- 
if ( class_exists( '\YahnisElsts\PluginUpdateChecker\v5\PucFactory' ) ) {
	$myUpdateChecker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
		'https://github.com/rsandb/file-version-manager/',
		__FILE__,
		'file-version-manager'
	);
	$myUpdateChecker->setBranch( 'main' );
	// $myUpdateChecker->setAuthentication('YOUR_TOKEN_HERE'); // Uncomment if private repo
}