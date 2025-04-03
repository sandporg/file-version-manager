<?php

namespace FVM\FileVersionManager;

class FVM_Activate {

	public static function activate() {
		global $wpdb;

		$table_name = $wpdb->prefix . FILE_TABLE_NAME;
		$cat_table_name = $wpdb->prefix . CAT_TABLE_NAME;
		$rel_table_name = $wpdb->prefix . REL_TABLE_NAME;
		$charset_collate = $wpdb->get_charset_collate();

		self::create_tables( $wpdb, $table_name, $cat_table_name, $rel_table_name, $charset_collate );

		require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );

		dbDelta( self::$sql );
		dbDelta( self::$cat_sql );
		dbDelta( self::$file_category_sql );

		update_option( 'fvm_flush_rewrite_rules', true );
	}

	private static $sql;
	private static $cat_sql;
	private static $file_category_sql;
	private static function create_tables( $wpdb, $table_name, $cat_table_name, $rel_table_name, $charset_collate ) {

		// Files table
		self::$sql =
			"CREATE TABLE IF NOT EXISTS $table_name (
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
				file_category_id mediumint(9),
				date_uploaded datetime NOT NULL,
				date_modified datetime NOT NULL,
				PRIMARY KEY  (id)
			) $charset_collate;"
		;

		// Categories table
		self::$cat_sql =
			"CREATE TABLE IF NOT EXISTS $cat_table_name (
				id mediumint(9) NOT NULL AUTO_INCREMENT,
				cat_name varchar(255) NOT NULL,
				cat_slug varchar(255) NOT NULL,
				cat_description text,
				cat_parent_id mediumint(9) DEFAULT 0 NOT NULL,
				cat_exclude_browser tinyint(1) NOT NULL DEFAULT 0,
				PRIMARY KEY (id),
				UNIQUE KEY cat_slug (cat_slug)
			) $charset_collate;"
		;

		// File-Category Relationship table
		self::$file_category_sql =
			"CREATE TABLE IF NOT EXISTS $rel_table_name (
				id mediumint(9) NOT NULL AUTO_INCREMENT,
				file_id mediumint(9) NOT NULL,
				category_id mediumint(9) NOT NULL,
				PRIMARY KEY (id),
				UNIQUE KEY file_category (file_id, category_id),
				FOREIGN KEY (file_id) REFERENCES $table_name(id) ON DELETE CASCADE,
				FOREIGN KEY (category_id) REFERENCES $cat_table_name(id) ON DELETE CASCADE
			) $charset_collate;"
		;
	}
}