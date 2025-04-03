<?php

namespace FVM\FileVersionManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Handles plugin upgrades and database schema migrations.
 */
class FVM_Upgrade {

	/**
	 * The database version option key.
	 */
	const DB_VERSION_OPTION = 'fvm_db_version';

	/**
	 * The current database version expected by the code.
	 * Increment this when you make schema changes.
	 */
	const CURRENT_DB_VERSION = '2';

	/**
	 * Hook into WP.
	 */
	public function init_hooks() {
		// Check for upgrades on admin pages
		add_action( 'admin_init', [ $this, 'check_for_upgrade' ] );

		// Handle the manual upgrade action (still needed for settings page button)
		add_action( 'admin_post_fvm_manual_upgrade', [ $this, 'handle_manual_upgrade' ] );

		// Display admin notices - REMOVED (Handled by FVM_Admin_Notices)
		// add_action( 'admin_notices', [ $this, 'display_all_admin_notices' ] );
	}

	/**
	 * Check if the database needs upgrading.
	 */
	public function check_for_upgrade() {
		$current_db_version = get_option( self::DB_VERSION_OPTION, '1.0' ); // Default to 1.0 if not set

		if ( version_compare( $current_db_version, self::CURRENT_DB_VERSION, '<' ) ) {
			// Don't automatically perform the upgrade here.
			// The persistent admin notice will inform the user to upgrade manually.
			// $this->perform_upgrade( $current_db_version ); // Removed this line
		}
	}

	/**
	 * Perform the database upgrade tasks.
	 *
	 * @param string $from_version The version we are upgrading from.
	 */
	private function perform_upgrade( $from_version ) {
		global $wpdb;

		// Upgrade to 1.1: Modify VARCHAR column sizes
		if ( version_compare( $from_version, '2', '<' ) ) {
			require_once( ABSPATH . 'wp-admin/includes/upgrade.php' ); // Needed for maybe_convert_table_to_utf8mb4

			$table_name = $wpdb->prefix . FVM_FILE_TABLE_NAME;
			$cat_table_name = $wpdb->prefix . FVM_CAT_TABLE_NAME;
			$charset_collate = $wpdb->get_charset_collate();

			// Ensure tables are using the recommended charset/collate first
			maybe_convert_table_to_utf8mb4( $table_name );
			maybe_convert_table_to_utf8mb4( $cat_table_name );

			// --- Files Table Modifications ---
			$this->maybe_modify_column( $table_name, 'file_name', 'VARCHAR(180)', 'NOT NULL' );
			$this->maybe_modify_column( $table_name, 'file_display_name', 'VARCHAR(180)', 'DEFAULT NULL' ); // Assuming nullable
			$this->maybe_modify_column( $table_name, 'file_path', 'VARCHAR(180)', 'NOT NULL' );
			$this->maybe_modify_column( $table_name, 'file_url', 'VARCHAR(180)', 'NOT NULL' );
			$this->maybe_modify_column( $table_name, 'file_password', 'VARCHAR(180)', 'DEFAULT NULL' ); // Assuming nullable

			// --- Categories Table Modifications ---
			$this->maybe_modify_column( $cat_table_name, 'cat_name', 'VARCHAR(180)', 'NOT NULL' );
			$this->maybe_modify_column( $cat_table_name, 'cat_slug', 'VARCHAR(180)', 'NOT NULL' );

			// Add more upgrade steps here for future versions inside similar version_compare blocks
			// if ( version_compare( $from_version, '1.2', '<' ) ) { ... }
		}

		// Update the DB version option to the latest version
		update_option( self::DB_VERSION_OPTION, self::CURRENT_DB_VERSION );

		return true; // Indicate success
	}

	/**
	 * Helper function to modify a column only if it exists and has a different definition.
	 * NOTE: This is a basic check and might need refinement based on exact needs (e.g., checking default values, collation).
	 *
	 * @param string $table_name  The table name.
	 * @param string $column_name The column name.
	 * @param string $column_type The target column type (e.g., 'VARCHAR(191)').
	 * @param string $attributes  Additional attributes like 'NOT NULL', 'DEFAULT NULL', etc.
	 */
	private function maybe_modify_column( $table_name, $column_name, $column_type, $attributes = '' ) {
		global $wpdb;
		$column_info = $wpdb->get_row(
			$wpdb->prepare(
				"SHOW COLUMNS FROM `{$table_name}` LIKE %s",
				$column_name
			)
		);

		if ( $column_info ) {
			// Basic check: Modify if type is different (case-insensitive)
			// More robust checks could compare NULL status, default values, etc.
			$current_type_normalized = strtolower( preg_replace( '/\(\d+\)/', '', $column_info->Type ) ); // e.g., varchar
			$target_type_normalized = strtolower( preg_replace( '/\(\d+\)/', '', $column_type ) ); // e.g., varchar
			$current_size = preg_match( '/\(\d+\)/', $column_info->Type, $matches ) ? $matches[0] : '';
			$target_size = preg_match( '/\(\d+\)/', $column_type, $matches ) ? $matches[0] : '';

			// Only alter if the base type is the same but the size is different,
			// OR if we are specifically targeting varchar(255) from legacy installs.
			// This avoids accidentally changing INT to VARCHAR etc. if types were mismatched.
			$needs_alter = false;
			if ( $current_type_normalized === $target_type_normalized && $current_size !== $target_size ) {
				$needs_alter = true;
			} elseif ( strtolower( $column_info->Type ) === 'varchar(255)' && $target_type_normalized === 'varchar' ) {
				// Specifically target changing old varchar(255)
				$needs_alter = true;
			}


			if ( $needs_alter ) {
				$sql = "ALTER TABLE `{$table_name}` MODIFY COLUMN `{$column_name}` {$column_type} {$attributes}";
				$wpdb->query( $sql );
				// Optional: Add error checking here based on $wpdb->last_error
			}
		}
	}

	/**
	 * Handle the manual upgrade request.
	 */
	public function handle_manual_upgrade() {
		// Check nonce using POST since the form uses method="post"
		if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( $_POST['_wpnonce'], 'fvm_manual_upgrade_nonce' ) ) {
			wp_die( __( 'Security check failed.', 'file-version-manager' ) );
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( __( 'You do not have permission to perform this action.', 'file-version-manager' ) );
		}

		// Perform the upgrade logic
		$current_db_version = get_option( self::DB_VERSION_OPTION, '1.0' );
		$success = $this->perform_upgrade( $current_db_version ); // Pass the current DB version

		// Set an admin notice
		$message = $success
			? __( 'Database upgrade process completed.', 'file-version-manager' )
			: __( 'Database upgrade failed. Check logs for details.', 'file-version-manager' ); // Basic message
		$type = $success ? 'success' : 'error';

		// Use the new notice manager to set the transient and redirect
		FVM_Admin_Notices::add_notice_and_redirect( 'fvm_settings', $type, $message );
		// The exit is handled within add_notice_and_redirect
	}

	// Remove old notice display methods
	// /**
	//  * Wrapper function to display all relevant admin notices.
	//  */
	// public function display_all_admin_notices() {
	// 	$this->display_persistent_upgrade_notice();
	// 	$this->display_manual_upgrade_result_notice();
	// }
	//
	// /**
	//  * Display admin notices set by the *manual* upgrade process (transient).
	//  */
	// public function display_manual_upgrade_result_notice() { ... }
	//
	// /**
	//  * Display a persistent admin notice if the DB version is out of date.
	//  * Note: This logic is now within FVM_Admin_Notices::display_persistent_upgrade_notice_if_needed
	//  */
	// public function display_persistent_upgrade_notice() { ... }
}