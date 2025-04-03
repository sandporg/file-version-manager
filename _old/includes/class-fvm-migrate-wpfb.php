<?php

namespace FVM\FileVersionManager;

use Exception;

/**
 * This class handles the process of updating file IDs based on a CSV input.
 * - Grabs file data from the wp_wpfb_files and wp_wpfb_cats tables and imports it into the plugin's tables.
 * - Logs the processes for debugging
 */
class FVM_Migrate_WPFB {
	private $log = [];
	private $highest_id = 0;
	private $wpdb;
	private $file_table_name;
	private $category_table_name;
	private $rel_table_name;
	private $file_manager;

	public function __construct( $wpdb ) {
		$this->wpdb = $wpdb;
		$this->file_table_name = esc_sql( $wpdb->prefix . FILE_TABLE_NAME );
		$this->category_table_name = esc_sql( $wpdb->prefix . CAT_TABLE_NAME );
		$this->rel_table_name = esc_sql( $wpdb->prefix . REL_TABLE_NAME );
		$this->file_manager = new FVM_File_Manager( $wpdb );
	}

	/**
	 * Imports data from wp_wpfb_cats and wp_wpfb_files tables to the plugin's tables.
	 * @return array
	 */
	public function import_from_wpfilebase() {
		$this->wpdb->query( 'START TRANSACTION' );

		try {
			$this->log[] = "----------------------------";
			$this->log[] = "---- Starting Migration ----";
			$this->log[] = "----------------------------\n";

			// Run scan_files method before starting the migration
			$this->log[] = "Scanning files...";
			$this->file_manager->scan_files();
			$this->log[] = "File scan completed.\n";

			$this->import_categories();
			$this->import_files();
			$this->update_remaining_files();

			$this->wpdb->query( 'COMMIT' );

			return [ 'success' => true, 'message' => "Import completed successfully. Highest ID: {$this->highest_id}" ];
		} catch (Exception $e) {
			$this->wpdb->query( 'ROLLBACK' );
			return [ 'success' => false, 'message' => $e->getMessage() ];
		}
	}

	/**
	 * Imports categories from wp_wpfb_cats table.
	 * @return void
	 */
	private function import_categories() {
		$wpfb_cats_table = $this->wpdb->prefix . 'wpfb_cats';
		$categories = $this->wpdb->get_results( $this->wpdb->prepare( "SELECT * FROM %i", $wpfb_cats_table ) );

		if ( empty( $categories ) ) {
			$this->log[] = "No categories found in the WP-Filebase table.";
			return;
		}

		$this->log[] = "\n----------------------------";
		$this->log[] = "--- Importing categories ---";
		$this->log[] = "----------------------------\n";

		$existing_categories = $this->wpdb->get_results( $this->wpdb->prepare( "SELECT id, cat_slug FROM %i", $this->category_table_name ), OBJECT_K );
		$values = [];
		$placeholders = [];

		foreach ( $categories as $category ) {
			$unique_slug = $this->get_unique_slug( $category->cat_name, $existing_categories );
			$placeholders[] = "(%d, %s, %s, %s, %d, %d)";
			$values = array_merge( $values, [ 
				$category->cat_id,
				$category->cat_name,
				$category->cat_description,
				$unique_slug,
				$category->cat_parent ? $category->cat_parent : 0,
				$category->cat_exclude_browser ? 1 : 0,
			] );

			$this->log[] = "Imported category: ({$category->cat_id}) " . htmlspecialchars( $category->cat_name );
		}

		$query = $this->wpdb->prepare(
			"INSERT INTO %i
			(id, cat_name, cat_description, cat_slug, cat_parent_id, cat_exclude_browser) 
			VALUES " . implode( ', ', $placeholders ) . "
			ON DUPLICATE KEY UPDATE 
			cat_name = VALUES(cat_name), 
			cat_description = VALUES(cat_description), 
			cat_slug = VALUES(cat_slug), 
			cat_parent_id = VALUES(cat_parent_id),
			cat_exclude_browser = VALUES(cat_exclude_browser)",
			array_merge( [ $this->category_table_name ], $values )
		);

		$result = $this->wpdb->query( $query );

		if ( $result === false ) {
			$this->log[] = "Error importing categories: " . $this->wpdb->last_error;
		} else {
			$this->log[] = "\n-------------------------------";
			$this->log[] = "--- Imported " . count( $categories ) . " categories. ---";
			$this->log[] = "-------------------------------\n";
		}
	}

	/**
	 * Generates a unique slug for a category.
	 * @param string $category_name
	 * @return string
	 */
	private function get_unique_slug( $category_name, &$existing_categories ) {
		$base_slug = sanitize_title( $category_name );
		$slug = $base_slug;
		$counter = 1;
		while ( isset( $existing_categories[ $slug ] ) ) {
			$slug = $base_slug . '-' . $counter;
			$counter++;
		}
		$existing_categories[ $slug ] = true;
		return $slug;
	}

	/**
	 * Checks if a slug already exists in the database.
	 * @param string $slug
	 * @return bool
	 */
	private function slug_exists( $slug ) {
		return $this->wpdb->get_var( $this->wpdb->prepare(
			"SELECT COUNT(*) FROM %i WHERE cat_slug = %s", $this->category_table_name, $slug ) ) > 0;
	}

	/**
	 * Imports files from wp_wpfb_files table.
	 * @return void
	 */
	private function import_files() {
		$wpfb_files_table = $this->wpdb->prefix . 'wpfb_files';
		$files = $this->wpdb->get_results( $this->wpdb->prepare( "SELECT * FROM %i", $wpfb_files_table ) );

		if ( empty( $files ) ) {
			$this->log[] = "No files found in the WP-Filebase table.";
			return;
		}

		$this->log[] = "\n-----------------------";
		$this->log[] = "--- Importing files ---";
		$this->log[] = "-----------------------\n";

		$file_map = $this->build_file_map( $files );
		$this->update_files_from_map( $file_map );

		$this->log[] = "Imported " . count( $files ) . " files.";
	}

	/**
	 * Builds a map of file names to their corresponding IDs.
	 * @param array $files
	 * @return array
	 */
	private function build_file_map( $files ) {
		$file_map = [];
		foreach ( $files as $file ) {
			$categories = [];

			// Check the main file_category column
			if ( ! empty( $file->file_category ) ) {
				$categories[] = intval( $file->file_category );
			}

			// Check additional category columns
			for ( $i = 1; $i <= 3; $i++ ) {
				$category_field = "file_sec_cat{$i}";
				if ( ! empty( $file->$category_field ) ) {
					$categories[] = intval( $file->$category_field );
				}
			}

			// Remove duplicates and ensure unique category IDs
			$categories = array_unique( $categories );

			$file_map[ $file->file_name ][] = [ 
				'id' => intval( $file->file_id ),
				'file_display_name' => $file->file_display_name,
				'file_categories' => $categories,
				'file_hash_md5' => $file->file_hash,
				'file_hash_sha256' => $file->file_hash_sha256,
				'file_added_by' => $file->file_added_by,
				'file_offline' => $file->file_offline,
				'file_password' => $file->file_password,
				'file_version' => $file->file_version,
				'file_description' => $file->file_description,
			];
			$this->highest_id = max( $this->highest_id, intval( $file->file_id ) );
		}

		foreach ( $file_map as &$entries ) {
			usort( $entries, function ($a, $b) {
				return $a['id'] - $b['id'];
			} );
		}

		$this->log[] = "File map: " . print_r( $file_map, true );

		return $file_map;
	}

	/**
	 * Updates existing files in the database based on the file map.
	 * @param array $file_map
	 * @return void
	 */
	private function update_files_from_map( $file_map ) {
		foreach ( $file_map as $file_name => $ids ) {
			$existing_files = $this->get_existing_files( $file_name );

			if ( count( $existing_files ) > 0 ) {
				$this->update_existing_files( $file_name, $ids, $existing_files );
			} else {
				$this->log[] = "Warning: File not found in the database: " . htmlspecialchars( $file_name ) . " with ID: " . $ids[0]['id'] . ". Skipping.";
			}
		}
	}

	/**
	 * Retrieves existing files from the database based on the file name.
	 * @param string $file_name
	 * @return array
	 */
	private function get_existing_files( $file_name ) {
		return $this->wpdb->get_results( $this->wpdb->prepare(
			"SELECT id FROM %i WHERE file_name = %s ORDER BY id", $this->file_table_name, $file_name
		) );
	}

	/**
	 * Updates existing files in the database based on the file map.
	 * @param string $file_name
	 * @param array $ids
	 * @param array $existing_files
	 * @return void
	 */
	private function update_existing_files( $file_name, array $ids, $existing_files ) {
		for ( $i = 0; $i < min( count( $ids ), count( $existing_files ) ); $i++ ) {
			$new_id = $ids[ $i ]['id'];
			$file_display_name = $ids[ $i ]['file_display_name'];
			$file_categories = $ids[ $i ]['file_categories'];
			$file_hash_md5 = $ids[ $i ]['file_hash_md5'];
			$file_hash_sha256 = $ids[ $i ]['file_hash_sha256'];
			$file_added_by = $ids[ $i ]['file_added_by'];
			$file_offline = $ids[ $i ]['file_offline'];
			$file_password = $ids[ $i ]['file_password'];
			$file_version = $ids[ $i ]['file_version'];
			$file_description = $ids[ $i ]['file_description'];
			$old_id = $existing_files[ $i ]->id;

			$this->handle_file_conflict( $new_id, $file_name );

			$this->log[] = "Updating existing file: " . htmlspecialchars( $file_name ) . " with ID: $new_id (was $old_id)";

			$this->update_file_data( $file_name, $new_id, $old_id, $file_display_name, $file_categories, $file_hash_md5, $file_hash_sha256, $file_added_by, $file_offline, $file_password, $file_version, $file_description );
		}

		$this->handle_extra_files( $file_name, $ids, $existing_files );
	}

	/**
	 * Handles file conflicts by temporarily updating the file ID.
	 * @param int $new_id
	 * @param string $file_name
	 * @return void
	 */
	private function handle_file_conflict( $new_id, $file_name ) {
		$conflict_file = $this->wpdb->get_row( $this->wpdb->prepare(
			"SELECT id, file_name FROM %i WHERE id = %d AND file_name != %s", $this->file_table_name, $new_id, $file_name
		) );

		if ( $conflict_file ) {
			$temp_id = $this->get_temporary_id();
			$this->wpdb->update(
				$this->file_table_name,
				[ 'id' => $temp_id ],
				[ 'id' => $new_id ],
				[ '%d' ],
				[ '%d' ]
			);
			$this->log[] = "Temporarily updated file '" . htmlspecialchars( $conflict_file->file_name ) . "' with ID $new_id to temporary ID $temp_id";
		}
	}

	/**
	 * Updates the file data in the database.
	 * @param string $file_name
	 * @param int $new_id
	 * @param int $old_id
	 * @param string $file_display_name
	 * @param int $file_category_id
	 * @param string $file_hash_md5
	 * @param string $file_hash_sha256
	 * @param int $file_added_by
	 * @param string $file_password
	 * @return void
	 */
	private function update_file_data( $file_name, $new_id, $old_id, $file_display_name, $file_categories, $file_hash_md5, $file_hash_sha256, $file_added_by, $file_offline, $file_password, $file_version, $file_description ) {
		$file_version = empty( $file_version ) ? '1.0' : $file_version;

		$result = $this->wpdb->update(
			$this->file_table_name,
			[ 
				'id' => $new_id,
				'date_modified' => current_time( 'mysql' ),
				'file_display_name' => $file_display_name,
				'file_hash_md5' => $file_hash_md5,
				'file_hash_sha256' => $file_hash_sha256,
				'file_added_by' => $file_added_by,
				'file_offline' => $file_offline,
				'file_password' => $file_password,
				'file_version' => $file_version,
				'file_description' => $file_description ?: null,
			],
			[ 'file_name' => $file_name, 'id' => $old_id ],
			[ '%d', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s' ],
			[ '%s', '%d' ]
		);

		if ( $result !== false ) {

			// Delete existing category relationships
			$this->wpdb->delete(
				$this->rel_table_name,
				[ 'file_id' => $new_id ],
				[ '%d' ]
			);

			// Insert new category relationships
			foreach ( $file_categories as $category_id ) {
				$this->wpdb->insert(
					$this->rel_table_name,
					[ 
						'file_id' => $new_id,
						'category_id' => $category_id,
					],
					[ '%d', '%d' ]
				);
			}

			$this->log[] = "Updated file: " . htmlspecialchars( $file_name ) . " with ID: $new_id (was $old_id)";
			$this->log[] = "----------------------------------";
		} else {
			$this->log[] = "Error updating file: " . htmlspecialchars( $file_name ) . " with ID: $new_id";
			throw new Exception( "Database error: " . esc_html( $this->wpdb->last_error ) );
		}
	}

	/**
	 * Handles extra files that are not found in the file map.
	 * @param string $file_name
	 * @param array $ids
	 * @param array $existing_files
	 * @return void
	 */
	private function handle_extra_files( $file_name, $ids, $existing_files ) {
		for ( $i = count( $ids ); $i < count( $existing_files ); $i++ ) {
			$temp_id = $this->get_temporary_id();
			$this->wpdb->update(
				$this->file_table_name,
				[ 'id' => $temp_id ],
				[ 'file_name' => $file_name, 'id' => $existing_files[ $i ]->id ],
				[ '%d' ],
				[ '%s', '%d' ]
			);
			$this->log[] = "Temporarily updated extra duplicate file: " . htmlspecialchars( $file_name ) . " with temporary ID: $temp_id";
		}
	}

	/**
	 * Retrieves the highest ID from the database and returns the next available ID.
	 * @return int
	 */
	private function get_temporary_id() {
		$temp_id = $this->wpdb->get_var( $this->wpdb->prepare(
			"SELECT MAX(id) FROM %i",
			$this->file_table_name
		) ) + 1;
		return $temp_id;
	}

	/**
	 * Updates the remaining files in the database.
	 * @return void
	 */
	private function update_remaining_files() {
		$this->wpdb->query( $this->wpdb->prepare(
			"UPDATE {$this->file_table_name} SET id = @new_id := @new_id + 1, date_modified = %s 
             WHERE id IS NULL OR id = 0
             ORDER BY file_name",
			current_time( 'mysql' )
		) );

		$this->highest_id = $this->wpdb->get_var( $this->wpdb->prepare( "SELECT MAX(id) FROM %i", $this->file_table_name ) );
		$this->log[] = "Updated remaining files. New highest ID: {$this->highest_id}";
	}

	/**
	 * Retrieves the log of the update process.
	 *
	 * @return string[] The log of the update process.
	 */
	public function get_log() {
		return $this->log;
	}
}