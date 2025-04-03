<?php

namespace FVM\FileVersionManager;

class FVM_Database_Upgrade {
	private $wpdb;
	private $file_table_name;
	private $cat_table_name;
	private $rel_table_name;

	public function __construct( \wpdb $wpdb ) {
		$this->wpdb = $wpdb;
		$this->file_table_name = esc_sql( $wpdb->prefix . FILE_TABLE_NAME );
		$this->cat_table_name = esc_sql( $wpdb->prefix . CAT_TABLE_NAME );
		$this->rel_table_name = esc_sql( $wpdb->prefix . REL_TABLE_NAME );
	}

	public function upgrade_database() {
		$this->upgrade_file_table();
		$this->upgrade_category_table();
		$this->upgrade_relationship_table();
	}

	private function upgrade_file_table() {
		$table_name = $this->file_table_name;
		$charset_collate = $this->wpdb->get_charset_collate();

		$sql = "CREATE TABLE $table_name (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            file_name varchar(255) NOT NULL,
            file_display_name varchar(255),
            file_path varchar(255) NOT NULL,
            file_url varchar(255) NOT NULL,
            file_size bigint(20) NOT NULL,
            file_type varchar(100) NOT NULL,
            file_description text,
            file_hash_md5 char(32),
            file_hash_sha256 char(64),
            file_added_by mediumint(9),
            file_offline tinyint(1) NOT NULL DEFAULT 0,
            file_password varchar(255),
            file_version DECIMAL(5,1) NOT NULL,
            date_uploaded datetime NOT NULL,
            date_modified datetime NOT NULL,
            PRIMARY KEY  (id)
        ) $charset_collate;";

		$this->maybe_add_column( $table_name, $sql );
	}

	private function upgrade_category_table() {
		$table_name = $this->cat_table_name;
		$charset_collate = $this->wpdb->get_charset_collate();

		$sql = "CREATE TABLE $table_name (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            cat_name varchar(255) NOT NULL,
            cat_slug varchar(255) NOT NULL,
            cat_description text,
            cat_parent_id mediumint(9) DEFAULT 0 NOT NULL,
            cat_exclude_browser tinyint(1) NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            UNIQUE KEY cat_slug (cat_slug)
        ) $charset_collate;";

		$this->maybe_add_column( $table_name, $sql );
	}

	private function upgrade_relationship_table() {
		$table_name = $this->rel_table_name;
		$charset_collate = $this->wpdb->get_charset_collate();

		$sql = "CREATE TABLE $table_name (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            file_id mediumint(9) NOT NULL,
            category_id mediumint(9) NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY file_category (file_id, category_id)
        ) $charset_collate;";

		$this->maybe_add_column( $table_name, $sql );
	}

	private function maybe_add_column( $table_name, $create_sql ) {
		$table_exists = $this->wpdb->get_var( "SHOW TABLES LIKE '$table_name'" ) == $table_name;

		if ( ! $table_exists ) {
			require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
			dbDelta( $create_sql );
			return;
		}

		$existing_columns = $this->wpdb->get_col( "DESC $table_name" );
		preg_match_all( "/^\s*(\w+)\s+/m", $create_sql, $desired_columns );

		$columns_to_add = array_diff( $desired_columns[1], $existing_columns );

		if ( ! empty( $columns_to_add ) ) {
			foreach ( $columns_to_add as $column ) {
				preg_match( "/^\s*$column\s+(.*?)(?:,|$)/m", $create_sql, $column_def );
				if ( ! empty( $column_def[1] ) ) {
					$this->wpdb->query( "ALTER TABLE $table_name ADD COLUMN $column {$column_def[1]}" );
				}
			}
		}
	}

	// Add this method to the DatabaseUpgrade class
	public function needs_upgrade() {
		$current_version = get_option( 'fvm_db_version', '0' );
		$plugin_version = $this->get_plugin_version();
		return version_compare( $current_version, $plugin_version, '<' );
	}

	private function get_plugin_version() {
		if ( ! function_exists( 'get_plugin_data' ) ) {
			require_once( ABSPATH . 'wp-admin/includes/plugin.php' );
		}
		$plugin_data = get_plugin_data( plugin_dir_path( __DIR__ ) . 'file-version-manager.php' );
		return $plugin_data['Version'];
	}
}