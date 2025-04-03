<?php

namespace FVM\FileVersionManager;

/**
 * Manages file operations for the File Version Manager plugin.
 * Handles uploading, updating, deleting, scanning, and serving files,
 * interacting with the database and filesystem.
 */
class FVM_File_Manager {
	/**
	 * Relative path to the upload directory (from ABSPATH).
	 * @var string
	 */
	private $upload_dir;

	/**
	 * Absolute path to the upload directory.
	 * @var string
	 */
	private $upload_dir_abs;

	/**
	 * Name of the custom upload subfolder.
	 * @var string
	 */
	private $custom_folder;

	/**
	 * WordPress database object.
	 * @var \wpdb
	 */
	private $wpdb;

	/**
	 * Name of the files database table.
	 * @var string
	 */
	private $file_table_name;

	/**
	 * Name of the categories database table.
	 * @var string
	 */
	private $cat_table_name;

	/**
	 * Name of the file-category relationship database table.
	 * @var string
	 */
	private $rel_table_name;

	/**
	 * Constructor.
	 * Initializes database table names and sets up the upload directory.
	 *
	 * @param \wpdb $wpdb WordPress database object.
	 */
	public function __construct( \wpdb $wpdb ) {
		$this->wpdb = $wpdb;
		// Table names are prefixed and stored. No need for esc_sql here as they are used directly in prepared statements later.
		$this->file_table_name = $wpdb->prefix . FVM_FILE_TABLE_NAME;
		$this->cat_table_name = $wpdb->prefix . FVM_CAT_TABLE_NAME;
		$this->rel_table_name = $wpdb->prefix . FVM_REL_TABLE_NAME;
		$this->custom_folder = 'filebase';
		$this->set_upload_dir();
	}

	/**
	 * Initializes hooks for the file manager.
	 * Hooks into admin actions for file scanning and updates.
	 *
	 * @return void
	 */
	public function init() {
		add_action( 'load-toplevel_page_fvm_files', [ $this, 'scan_files' ] );
		add_action( 'admin_post_update_file', [ $this, 'handle_file_update' ] );
	}

	/**
	 * Sets the absolute and relative paths for the custom upload directory.
	 * Creates the directory if it doesn't exist.
	 * 
	 * @return void
	 */
	private function set_upload_dir() {
		$upload_dir_info = wp_upload_dir();
		// Ensure custom_folder is sanitized if it comes from options in the future
		// $this->custom_folder = sanitize_file_name( get_option('fvm_custom_folder', 'filebase') ); 
		$this->custom_folder = sanitize_file_name( $this->custom_folder ); // Using the default for now

		$this->upload_dir_abs = trailingslashit( $upload_dir_info['basedir'] ) . $this->custom_folder;
		// Store relative path for database/consistency if needed, or adjust usage elsewhere
		$this->upload_dir = str_replace( trailingslashit( ABSPATH ), '', $this->upload_dir_abs );

		wp_mkdir_p( $this->upload_dir_abs );
	}

	/**
	 * Gets the absolute path to the FVM upload directory.
	 *
	 * @return string The absolute directory path.
	 */
	public function get_upload_dir_path(): string {
		// Ensure upload_dir_abs is set
		if ( empty( $this->upload_dir_abs ) ) {
			$this->set_upload_dir();
		}
		return $this->upload_dir_abs;
	}

	/**
	 * Customizes the WordPress upload directory information array.
	 * Used as a filter callback to direct uploads to the custom folder.
	 * 
	 * @param array $uploads Array containing upload directory information ('path', 'url', 'subdir', 'basedir', 'baseurl', 'error').
	 * @return array Modified array with customized upload directory path information.
	 */
	public function custom_upload_dir( $uploads ) {
		$custom_folder = 'filebase';
		$uploads['subdir'] = '/' . trim( $custom_folder, '/' );
		$uploads['path'] = $uploads['basedir'] . $uploads['subdir'];
		$uploads['url'] = $uploads['baseurl'] . $uploads['subdir'];
		return $uploads;
	}

	/**
	 * Scans the custom upload directory for new or removed files and updates the database.
	 * Compares files on disk with database records.
	 * Adds new files found on disk to the DB.
	 * Removes files from the DB that are no longer on disk.
	 * Hooked to 'load-toplevel_page_fvm_files'.
	 * 
	 * @return void
	 */
	public function scan_files() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Unauthorized access' );
		}

		if ( get_option( 'fvm_disable_file_scan', 0 ) ) {
			return;
		}

		// Prepare the query even if it has no variables for consistency
		$db_files = $this->wpdb->get_results( $this->wpdb->prepare( "SELECT id, file_path FROM %i", $this->file_table_name ), ARRAY_A ); // Using %i for table name requires WP 6.2+
		// Fallback if %i is not supported or preferred:
		// $db_files = $this->wpdb->get_results( "SELECT id, file_path FROM {$this->file_table_name}", ARRAY_A );

		$db_file_paths = array_column( $db_files, 'file_path', 'id' );

		$existing_files = $this->scan_directory( $this->upload_dir );

		$to_insert = array_diff( $existing_files, $db_file_paths );
		$to_delete = array_diff( $db_file_paths, $existing_files );

		$this->batch_insert_files( $to_insert );
		$this->batch_delete_files( array_keys( $to_delete ) );

		// Log the scan results
		if ( count( $to_insert ) > 0 || count( $to_delete ) > 0 ) {
			$scan_summary = [];
			if ( count( $to_insert ) > 0 ) {
				$scan_summary[] = sprintf( '%d new files found', count( $to_insert ) );
			}
			if ( count( $to_delete ) > 0 ) {
				$scan_summary[] = sprintf( '%d files removed', count( $to_delete ) );
			}

			apply_filters(
				'simple_history_log',
				'File scan completed: ' . implode( ', ', $scan_summary ),
				[ 
					'files_found' => count( $existing_files ),
					'files_added' => count( $to_insert ),
					'files_removed' => count( $to_delete ),
					'scan_directory' => $this->upload_dir,
				],
				'info'
			);
		}
	}

	/**
	 * Inserts multiple file records into the database.
	 *
	 * @param array $file_paths Array of file paths (relative to ABSPATH) to insert.
	 * @return void
	 */
	private function batch_insert_files( $file_paths ) {
		if ( empty( $file_paths ) ) {
			return; // No files to insert, so we exit early
		}

		$values = [];
		$placeholders = [];
		foreach ( $file_paths as $file_path ) {
			$absolute_path = ABSPATH . $file_path;
			$file_size = file_exists( $absolute_path ) ? filesize( $absolute_path ) : 0;
			$file_type = wp_check_filetype( $absolute_path )['ext'];
			$current_time = current_time( 'mysql' );

			$values = array_merge( $values, [ 
				basename( $file_path ),
				null, // file_display_name
				$file_path,
				home_url( 'download/' . basename( $file_path ) ),
				$file_size,
				$file_type,
				'1.0',
				$current_time,
				$current_time,
			] );
			$placeholders[] = "(%s, %s, %s, %s, %d, %s, %s, %s, %s)";
		}

		if ( ! empty( $values ) ) {
			// Note: Table name is part of the query string, not a parameter. Already checked in constructor.
			$query = "INSERT INTO {$this->file_table_name}
					  (file_name, file_display_name, file_path, file_url, file_size, file_type, file_version, date_uploaded, date_modified)
					  VALUES " . implode( ', ', $placeholders );

			// query() is okay here as $query is fully prepared with placeholders for all values.
			$this->wpdb->query( $this->wpdb->prepare( $query, $values ) ); // WPCS: unprepared SQL OK.
		}
	}

	/**
	 * Deletes multiple file records from the database by their IDs.
	 *
	 * @param array $file_ids Array of file IDs to delete.
	 * @return void
	 */
	private function batch_delete_files( $file_ids ) {
		if ( ! empty( $file_ids ) ) {
			$placeholders = implode( ',', array_fill( 0, count( $file_ids ), '%d' ) );
			// Note: Table name is part of the query string, not a parameter. Already checked in constructor.
			$query = "DELETE FROM {$this->file_table_name} WHERE id IN ($placeholders)";
			// query() is okay here as $query is fully prepared with placeholders for all values.
			$this->wpdb->query( $this->wpdb->prepare( $query, $file_ids ) ); // WPCS: unprepared SQL OK.
		}
	}

	/**
	 * Scans a directory recursively and returns an array of file paths.
	 * Excludes hidden files (starting with '.') and directories.
	 * 
	 * @param string $dir Directory path relative to ABSPATH.
	 * @return array Array of file paths relative to ABSPATH.
	 */
	private function scan_directory( $dir ) {
		$files = [];
		$absolute_dir = ABSPATH . ltrim( $dir, '/' );
		if ( ! is_dir( $absolute_dir ) ) {
			return $files;
		}
		$iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $absolute_dir ) );
		foreach ( $iterator as $file ) {
			if ( $file->isFile() && substr( $file->getFilename(), 0, 1 ) !== '.' ) {
				$files[] = str_replace( ABSPATH, '', $file->getPathname() );
			}
		}
		return $files;
	}

	/**
	 * Handles uploading a single file.
	 * Uses wp_handle_upload to move the file to the custom directory.
	 * Inserts or updates the file record in the database.
	 * 
	 * @param array $file An item from the $_FILES array.
	 * @param int|null $file_id Optional. ID of the file to update. If null, a new file is inserted.
	 * @param string $file_version Optional. The version string for the file. Defaults to '1.0'.
	 * @return int|false The file ID on success, false on failure.
	 */
	public function upload_file( $file, $file_id = null, $file_version = '1.0' ) {
		if ( ! current_user_can( 'upload_files' ) ) {
			wp_die( 'Unauthorized access' );
		}

		add_filter( 'upload_dir', [ $this, 'custom_upload_dir' ] );
		$movefile = wp_handle_upload( $file, [ 'test_form' => false ] );
		remove_filter( 'upload_dir', [ $this, 'custom_upload_dir' ] );

		if ( $movefile && ! isset( $movefile['error'] ) ) {
			$file_name = basename( $movefile['file'] );
			$file_name = preg_replace( '/[\s\x{202F}\x{2009}]+/u', '-', $file_name );
			$file_path = str_replace( ABSPATH, '', $movefile['file'] );
			$absolute_path = ABSPATH . $file_path;
			$file_url = home_url( 'download/' . $file_name );
			$file_type = wp_check_filetype( ABSPATH . $file_path )['ext'];
			$file_size = filesize( ABSPATH . $file_path );
			$current_time = current_time( 'mysql' );

			// Generate MD5 and SHA256 hashes
			$md5_hash = md5_file( $absolute_path );
			$sha256_hash = hash_file( 'sha256', $absolute_path );

			$metadata = [ 
				'file_name' => $file_name,
				'file_path' => $file_path,
				'file_url' => $file_url,
				'file_size' => $file_size,
				'file_type' => $file_type,
				'file_version' => $file_version,
				'file_hash_md5' => $md5_hash,
				'file_hash_sha256' => $sha256_hash,
				'date_modified' => $current_time,
			];

			if ( $file_id ) {
				// Note: wpdb::update handles preparation internally.
				$update_result = $this->wpdb->update(
					$this->file_table_name,
					$metadata,
					[ 'id' => $file_id ],
					null, // Let WP determine format from data types
					[ '%d' ] // Format for WHERE clause
				);

				if ( $update_result === false ) {
					// Handle update error if necessary
					error_log( "FVM DB Error (upload_file - update): " . $this->wpdb->last_error );
				}

			} else {
				$metadata['date_uploaded'] = $current_time;
				// Note: wpdb::insert handles preparation internally.
				$insert_result = $this->wpdb->insert(
					$this->file_table_name,
					$metadata
					// Let WP determine format from data types
				);
				if ( $insert_result === false ) {
					// Handle insert error if necessary
					error_log( "FVM DB Error (upload_file - insert): " . $this->wpdb->last_error );
					return false; // Return false if initial insert fails
				}
				$file_id = $this->wpdb->insert_id;
			}

			// Log the file upload
			$changes = [ 
				sprintf( '%s', $file_name ),
				sprintf( 'Size: %s', size_format( $file_size ) ),
				sprintf( 'Type: %s', strtoupper( $file_type ) ),
			];

			apply_filters(
				'simple_history_log',
				'Uploaded new file (ID: {file_id}): ' . implode( '. ', $changes ),
				[ 
					'file_id' => $file_id,
					'file_name' => $file_name,
					'file_size' => $file_size,
					'file_type' => $file_type,
					'changes' => $changes,
				],
				'info'
			);

			return $file_id;
		}

		return false;
	}

	/**
	 * Handles uploading multiple files from a file input array.
	 * Iterates through the files array and calls upload_file() for each.
	 * 
	 * @param array $files The $_FILES array structure for a multiple file input.
	 * @return array An array of successfully uploaded file IDs.
	 */
	public function upload_files( $files ) {
		$uploaded_files = [];
		foreach ( $files['name'] as $key => $value ) {
			$file = [ 
				'name' => $files['name'][ $key ],
				'type' => $files['type'][ $key ],
				'tmp_name' => $files['tmp_name'][ $key ],
				'error' => $files['error'][ $key ],
				'size' => $files['size'][ $key ],
			];
			$upload_result = $this->upload_file( $file );
			if ( $upload_result ) {
				$uploaded_files[] = $upload_result;
			}
		}
		return $uploaded_files;
	}

	/**
	 * Updates an existing file record and optionally replaces the physical file.
	 * Handles metadata updates (display name, description, version, offline status)
	 * and category assignments.
	 * 
	 * @param int        $file_id           ID of the file to update.
	 * @param array|null $new_file          Item from $_FILES array if replacing the file, null otherwise.
	 * @param string     $new_version       New version string.
	 * @param string     $file_display_name New display name.
	 * @param string     $file_description  New description.
	 * @param array      $file_categories   Array of category IDs to assign.
	 * @param bool       $file_offline      Whether the file should be marked as offline.
	 * @return bool True on success, false on failure.
	 */
	public function update_file( $file_id, $new_file, $new_version, $file_display_name, $file_description, $file_categories, $file_offline ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Unauthorized access' );
		}

		$existing_file = $this->get_file( $file_id ); // Uses prepared statement internally

		if ( ! $existing_file ) {
			return false;
		}

		// Convert object to array for easier comparison later
		$existing_file_array = (array) $existing_file;

		$update_data = array();
		$update_format = array();

		$auto_increment_version = get_option( 'fvm_auto_increment_version' );

		// Update file display name if provided
		if ( ! empty( $file_display_name ) ) {
			$update_data['file_display_name'] = $file_display_name;
			$update_format[] = '%s';
		}

		// Update file description if provided
		if ( ! empty( $file_description ) ) {
			$update_data['file_description'] = $file_description;
			$update_format[] = '%s';
		}

		$update_data['file_offline'] = ! empty( $file_offline ) ? 1 : 0;
		$update_format[] = '%d';

		// Update version if provided or auto-increment if enabled
		if ( ! empty( $new_version ) ) {
			$update_data['file_version'] = $new_version;
		} elseif ( $auto_increment_version && $new_file ) {
			$current_version = $existing_file->file_version;
			$update_data['file_version'] = $this->increment_version( $current_version );
		}

		if ( isset( $update_data['file_version'] ) ) {
			$update_format[] = '%s';
		}

		// Handle file upload if a new file is provided
		if ( $new_file && ! empty( $new_file['tmp_name'] ) ) {

			$check_file = wp_check_filetype_and_ext( $new_file['tmp_name'], $new_file['name'] );
			if ( ! $check_file['ext'] || ! $check_file['type'] ) {
				return false;
			}

			// Delete the old file - Restore the unlink logic
			$old_file_path = ABSPATH . ltrim( $existing_file_array['file_path'], '/' );
			if ( file_exists( $old_file_path ) ) {
				if ( ! unlink( $old_file_path ) ) {
					// Handle error: Failed to delete old file. Log and continue, or return false?
					error_log( "[FVM] Update File Error: Failed to delete old file at path: " . $old_file_path . " for file ID: " . $file_id );
					// Depending on requirements, you might want to return false here
					// return false; 
				}
			}

			// Upload the new file to the custom directory
			add_filter( 'upload_dir', [ $this, 'custom_upload_dir' ] );
			$movefile = wp_handle_upload( $new_file, [ 'test_form' => false ] );
			remove_filter( 'upload_dir', [ $this, 'custom_upload_dir' ] );

			if ( $movefile && ! isset( $movefile['error'] ) ) {
				$new_file_name = basename( $movefile['file'] );
				$new_file_path = str_replace( ABSPATH, '', $movefile['file'] );
				$file_url = home_url( 'download/' . $new_file_name );
				$file_type = wp_check_filetype( ABSPATH . $new_file_path )['ext'];
				$file_size = filesize( ABSPATH . $new_file_path );

				// Generate MD5 and SHA256 hashes for the new file
				$absolute_path = ABSPATH . $new_file_path;
				$md5_hash = md5_file( $absolute_path );
				$sha256_hash = hash_file( 'sha256', $absolute_path );

				$update_data['file_name'] = $new_file_name;
				$update_data['file_path'] = $new_file_path;
				$update_data['file_url'] = $file_url;
				$update_data['file_type'] = $file_type;
				$update_data['file_size'] = $file_size;
				$update_data['file_hash_md5'] = $md5_hash;
				$update_data['file_hash_sha256'] = $sha256_hash;

				$update_format = array_merge( $update_format, array( '%s', '%s', '%s', '%s', '%d', '%s', '%s' ) );
			} else {
				return false;
			}
		}

		$update_data['date_modified'] = current_time( 'mysql' );
		$update_format[] = '%s';

		// Note: wpdb::update handles preparation internally.
		$result = $this->wpdb->update(
			$this->file_table_name,
			$update_data,
			array( 'id' => $file_id ),
			$update_format,
			array( '%d' ) // WHERE clause format
		);

		if ( $result === false ) {
			error_log( "FVM DB Error (update_file): " . $this->wpdb->last_error );
			return false;
		}

		// Get the updated file data - already prepared
		$updated_file = $this->get_file( $file_id ); // Use existing prepared method

		// Update file categories
		$this->update_file_categories( $file_id, $file_categories );

		// Track specific changes
		$changes = [];
		if ( ! empty( $new_file['tmp_name'] ) ) {
			$changes[] = 'Replaced file with {file_name}';
			$changes[] = sprintf( 'Size changed to %s', size_format( $update_data['file_size'] ) );
		}
		if ( ! empty( $file_display_name ) && $file_display_name !== $existing_file_array['file_display_name'] ) {
			$changes[] = sprintf( 'Display name changed from "%s" to "%s"', $existing_file_array['file_display_name'], $file_display_name );
		}
		if ( isset( $update_data['file_description'] ) && $update_data['file_description'] !== $existing_file_array['file_description'] ) {
			$changes[] = 'Description updated';
		}
		if ( $file_offline != $existing_file_array['file_offline'] ) {
			$changes[] = $file_offline ? 'Set to offline' : 'Set to online';
		}
		// if ( ! empty( $file_categories ) ) {
		// 	$changes[] = 'Categories updated';
		// }

		// Log changes if any were made
		if ( ! empty( $changes ) ) {
			apply_filters(
				'simple_history_log',
				'Updated file: {old_file_name} (ID: {file_id}): ' . implode( '. ', $changes ),
				[ 
					'file_id' => $file_id,
					'file_name' => $updated_file->file_name,
					'old_file_name' => $existing_file_array['file_name'],
					'changes' => $changes,
				],
				'info'
			);
		}

		return true;
	}

	/**
	 * Updates the category assignments for a specific file.
	 * Deletes existing relationships and inserts new ones based on the provided category IDs.
	 *
	 * @param int   $file_id      The ID of the file.
	 * @param array $category_ids An array of category IDs to assign to the file.
	 * @return bool True on success, false on failure (e.g., invalid file ID).
	 */
	private function update_file_categories( $file_id, $category_ids ) {
		$file_id = intval( $file_id );
		if ( $file_id <= 0 ) {
			return false; // Invalid file ID
		}

		// Ensure category_ids is an array, even if empty
		$category_ids = is_array( $category_ids ) ? array_map( 'intval', $category_ids ) : [];
		// Remove any invalid IDs (e.g., 0)
		$category_ids = array_filter( $category_ids, function ($id) {
			return $id > 0;
		} );

		// Delete existing category relationships
		// Note: wpdb::delete handles preparation internally.
		$delete_result = $this->wpdb->delete( $this->rel_table_name, array( 'file_id' => $file_id ), array( '%d' ) );

		// Add logging for delete operation
		if ( false === $delete_result ) {
			error_log( "FVM DB Error (update_file_categories): Failed to delete relationships for file_id: " . $file_id . " | DB Error: " . $this->wpdb->last_error );
			// Decide if we should stop here or try inserting anyway
			// return false; // Uncomment to stop on delete failure
		}

		// Insert new category relationships
		foreach ( $category_ids as $category_id ) {
			// Note: wpdb::insert handles preparation internally.
			$insert_result = $this->wpdb->insert(
				$this->rel_table_name,
				array(
					'file_id' => $file_id,
					'category_id' => $category_id,
				),
				array( '%d', '%d' )
			);

			// Add logging for each insert operation
			if ( false === $insert_result ) {
				error_log( "FVM DB Error (update_file_categories): Failed to insert relationship for file_id: " . $file_id . ", category_id: " . $category_id . " | DB Error: " . $this->wpdb->last_error );
				// Optionally, continue to try inserting others or return false immediately
				// return false; // Uncomment to stop on first insert failure
			}
		}

		return true; // Assume success if we got here without returning false earlier
	}

	/**
	 * Increments a version number string (e.g., "1.0" becomes "2.0").
	 * Assumes a simple Major.Minor format, only increments the major version.
	 * 
	 * @param string $file_version The current version string (e.g., "1.0", "2.5").
	 * @return string The incremented version string (e.g., "2.0", "3.0").
	 */
	private function increment_version( $file_version ) {
		$parts = explode( '.', $file_version );
		$major = intval( $parts[0] );
		$major++;
		return $major . '.0';
	}

	/**
	 * Retrieves a single file record from the database by its ID.
	 * 
	 * @param int $file_id The ID of the file to retrieve.
	 * @return object|false File data object on success, false if not found or on error.
	 */
	public function get_file( $file_id ) {
		// Note: Table name is part of the query string, not a parameter. Already checked in constructor.
		// Using %i for table name requires WP 6.2+
		$query = $this->wpdb->prepare( "SELECT * FROM %i WHERE id = %d", $this->file_table_name, $file_id );
		// Fallback:
		// $query = $this->wpdb->prepare( "SELECT * FROM {$this->file_table_name} WHERE id = %d", $file_id );
		return $this->wpdb->get_row( $query );
	}

	/**
	 * Public handler for file deletion requests.
	 * Validates the context before calling the private deletion method.
	 * 
	 * @param int $file_id ID of the file to delete.
	 * @param string $context Optional. The context of deletion ('bulk', 'single', 'admin'). Defaults to 'single'.
	 * @return bool True on successful deletion, false otherwise.
	 */
	public function handleFileDeletion( $file_id, $context = 'single' ) {
		// Verify context is valid
		if ( ! in_array( $context, [ 'bulk', 'single', 'admin' ] ) ) {
			return false;
		}

		return $this->deleteFile( $file_id, $context );
	}

	/**
	 * Deletes a file record from the database and the corresponding physical file.
	 * Performs capability checks, nonce verification (for single context), and path validation.
	 * Also cleans up associated category relationships.
	 * 
	 * @param int $file_id ID of the file to delete.
	 * @param string $context The context of deletion ('bulk', 'single', 'admin').
	 * @return bool True on successful deletion, false otherwise.
	 */
	private function deleteFile( $file_id, $context ) {
		// Verify user capabilities
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Unauthorized access' );
		}

		// Different nonce verification for bulk vs single deletion
		if ( $context === 'single' && isset( $_REQUEST['_wpnonce'] ) ) {
			check_admin_referer( 'delete_file_' . $file_id );
		}

		// Sanitize and validate file ID
		$file_id = absint( $file_id );
		if ( ! $file_id ) {
			return false;
		}

		// Get file info before deletion for logging
		$file = $this->get_file( $file_id );
		if ( ! $file ) {
			return false;
		}

		// Verify file is within allowed directory
		$absolute_path = ABSPATH . ltrim( $file->file_path, '/' );
		$upload_dir_base = $this->get_upload_dir_path(); // Get base upload dir absolute path
		if ( strpos( realpath( $absolute_path ), realpath( $upload_dir_base ) ) !== 0 ) {
			error_log( "[FVM] File deletion error: Attempted to delete file outside base upload directory. Path: " . $absolute_path );
			return false;
		}

		// Delete physical file
		if ( file_exists( $absolute_path ) ) {
			if ( ! unlink( $absolute_path ) ) {
				error_log( "[FVM] File deletion error: Failed to unlink file at path: " . $absolute_path );
				return false; // Stop if physical file deletion fails
			}
		}

		// Delete from database
		// Note: wpdb::delete handles preparation internally.
		$deleted = $this->wpdb->delete(
			$this->file_table_name,
			[ 'id' => $file_id ],
			[ '%d' ] // Format for WHERE clause
		);

		if ( $deleted ) {
			// Clean up category relationships
			// Note: wpdb::delete handles preparation internally.
			$this->wpdb->delete(
				$this->rel_table_name,
				[ 'file_id' => $file_id ],
				[ '%d' ] // Format for WHERE clause
			);

			// Log the deletion
			apply_filters(
				'simple_history_log',
				'Deleted file (ID: {file_id}): {file_name}',
				[ 
					'file_id' => $file_id,
					'file_name' => $file->file_name,
					'file_size' => $file->file_size,
					'file_type' => $file->file_type,
					'bulk_action' => $context,
				],
				'info'
			);

			return true;
		}

		return false;
	}

	/**
	 * Gets detailed data for a single file, including assigned categories and all available categories.
	 * Used for populating the file edit form/modal.
	 * Requires 'manage_options' capability.
	 * 
	 * @param int $file_id The ID of the file.
	 * @return array|false An array containing file data, assigned category IDs, all categories,
	 *                     and a nonce on success. False if file not found or user lacks capability.
	 */
	public function get_file_data( $file_id ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return false;
		}

		$file = $this->get_file( $file_id );
		if ( ! $file ) {
			return false;
		}

		// Get simple list of assigned category IDs
		// Note: Table name is part of the query string, not a parameter. Already checked in constructor.
		// Using %i for table name requires WP 6.2+
		$query = $this->wpdb->prepare(
			"SELECT category_id FROM %i WHERE file_id = %d",
			$this->rel_table_name,
			$file_id
		);
		// Fallback:
		// $query = $this->wpdb->prepare(
		// 	"SELECT category_id FROM {$this->rel_table_name} WHERE file_id = %d",
		// 	$file_id
		// );
		$assigned_category_ids = $this->wpdb->get_col( $query );
		// Ensure they are integers
		$assigned_category_ids = array_map( 'intval', $assigned_category_ids );

		$file_data = (array) $file;

		// Add assigned IDs to the data
		$file_data['assigned_category_ids'] = $assigned_category_ids;

		// Get all categories hierarchically for the dropdown selector
		// Inject Category Manager if not already available or ensure it's passed/accessible
		// Assuming $this->category_manager is available (based on Ajax Handler)
		// If not, it needs to be injected or accessed differently.
		global $fvm_category_manager; // Or use a better dependency injection method
		if ( isset( $fvm_category_manager ) ) {
			$file_data['all_categories'] = $fvm_category_manager->get_categories_hierarchical();
		} else {
			$file_data['all_categories'] = []; // Provide empty array if manager not found
			error_log( "FVM Error: Category Manager not available in FVM_File_Manager::get_file_data" );
		}

		$file_data['nonce'] = wp_create_nonce( 'edit_file_' . $file_id );

		return $file_data;
	}

	/**
	 * Retrieves files from the database based on specified arguments.
	 * Supports pagination, sorting, searching, and category filtering.
	 *
	 * @global \wpdb $wpdb WordPress database object.
	 * @param array $args { 
	 *     Optional. Arguments to filter the files retrieved.
	 * 
	 *     @type int    $posts_per_page Number of files per page. Default 50. Use -1 for no limit.
	 *     @type int    $paged          Current page number. Default 1.
	 *     @type string $orderby        Column to sort by. Allowed: 'id', 'file_name', 'file_display_name', 
	 *                                  'file_size', 'file_type', 'file_version', 'date_uploaded', 'date_modified'.
	 *                                  Default 'date_modified'.
	 *     @type string $order          Sort order ('ASC' or 'DESC'). Default 'DESC'.
	 *     @type string $search         Search term to filter by file_name, file_display_name, or ID.
	 *     @type int    $category_id    Filter files by a specific category ID.
	 * }
	 * @return array Array of file objects matching the criteria.
	 */
	public function get_files( array $args = [] ): array {
		global $wpdb;

		// Default arguments
		$defaults = [ 
			'posts_per_page' => 50,
			'paged' => 1,
			'orderby' => 'date_modified',
			'order' => 'DESC',
			'search' => '',
			'category_id' => null,
		];
		$args = wp_parse_args( $args, $defaults );

		// Sanitize and validate sorting parameters
		$allowed_orderby = [ 'id', 'file_name', 'file_display_name', 'file_size', 'file_type', 'file_version', 'date_uploaded', 'date_modified' ];
		$orderby = in_array( $args['orderby'], $allowed_orderby ) ? $args['orderby'] : 'date_modified';
		$order = strtoupper( $args['order'] ) === 'ASC' ? 'ASC' : 'DESC';

		// Calculate offset
		$posts_per_page = intval( $args['posts_per_page'] );
		$paged = intval( $args['paged'] );
		$offset = ( $paged - 1 ) * $posts_per_page;

		// Build query parts
		$select = "SELECT f.*";
		$from = "FROM {$this->file_table_name} f"; // Table name directly included
		$join = "";
		$where_clauses = [];
		$params = []; // Parameters for the WHERE clause
		$group_by = "GROUP BY f.id"; // Group by primary key of the main table
		$order_by = "ORDER BY f.{$orderby} {$order}"; // Use table alias `f` for clarity and validated values

		// Search clause
		if ( ! empty( $args['search'] ) ) {
			$search_term = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$where_clauses[] = "(f.file_name LIKE %s OR f.file_display_name LIKE %s OR CAST(f.id AS CHAR) = %s)";
			$params[] = $search_term;
			$params[] = $search_term;
			$params[] = $args['search'];
		}

		// Category clause
		if ( isset( $args['category_id'] ) && intval( $args['category_id'] ) > 0 ) {
			// Note: Table names embedded directly as they are considered safe internal properties.
			$join = " LEFT JOIN {$this->rel_table_name} r ON f.id = r.file_id LEFT JOIN {$this->cat_table_name} c ON r.category_id = c.id";
			// Using %i placeholder for table names (requires WP 6.2+)
			// $join = $wpdb->prepare( " LEFT JOIN %i r ON f.id = r.file_id LEFT JOIN %i c ON r.category_id = c.id", $this->rel_table_name, $this->cat_table_name );
			$select .= ", GROUP_CONCAT(c.cat_name SEPARATOR ', ') as categories";
			$where_clauses[] = "r.category_id = %d";
			$params[] = intval( $args['category_id'] );
		} else {
			// Ensure GROUP BY f.id exists even without category join if select includes aggregate or distinct might be needed
			// $group_by = "GROUP BY f.id"; // Already set
		}


		$where = ! empty( $where_clauses ) ? "WHERE " . implode( " AND ", $where_clauses ) : '';

		// Limit clause
		$limit_clause = '';
		if ( $posts_per_page > 0 ) {
			// Prepare limit clause separately, parameters are added here
			$limit_clause = $wpdb->prepare( "LIMIT %d OFFSET %d", $posts_per_page, $offset );
		}

		// Construct the final query string - orderby/order are validated, not parameterized.
		// Table names embedded directly.
		// Note: Concatenating a prepared string ($limit_clause) is safe.
		$query = "{$select} {$from} {$join} {$where} {$group_by} {$order_by} {$limit_clause}"; // WPCS: unprepared SQL OK (orderby/order validated, limit prepared separately, table names internal).

		// Only prepare the query if there are parameters for the WHERE clause
		if ( ! empty( $params ) ) {
			$prepared_query = $wpdb->prepare( $query, $params );
		} else {
			$prepared_query = $query; // Use the constructed query directly if no params
		}

		if ( ! $prepared_query ) {
			error_log( "FVM DB Error (get_files): Failed to prepare query or query construction failed. SQL fragment: " . $query . " Params: " . print_r( $params, true ) );
			return [];
		}

		$results = $wpdb->get_results( $prepared_query, OBJECT );

		return $results ? $results : [];
	}

	/**
	 * Gets the total number of files matching the specified criteria.
	 * Used for pagination calculation.
	 *
	 * @global \wpdb $wpdb WordPress database object.
	 * @param array $args { 
	 *     Optional. Arguments to filter the count.
	 * 
	 *     @type string $search      Search term to filter by file_name, file_display_name, or ID.
	 *     @type int    $category_id Filter files by a specific category ID.
	 * }
	 * @return int Total number of files matching the criteria.
	 */
	public function get_total_files( array $args = [] ): int {
		global $wpdb;

		$defaults = [ 
			'search' => '',
			'category_id' => null,
		];
		$args = wp_parse_args( $args, $defaults );

		// Build query parts
		$select = "SELECT COUNT(DISTINCT f.id)"; // Count distinct file IDs
		$from = "FROM {$this->file_table_name} f"; // Table name directly included
		$join = "";
		$where_clauses = [];
		$params = []; // Parameters for the WHERE clause

		// Search clause
		if ( ! empty( $args['search'] ) ) {
			$search_term = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$where_clauses[] = "(f.file_name LIKE %s OR f.file_display_name LIKE %s OR CAST(f.id AS CHAR) = %s)";
			$params[] = $search_term;
			$params[] = $search_term;
			$params[] = $args['search'];
		}

		// Category clause
		if ( $args['category_id'] !== null && intval( $args['category_id'] ) > 0 ) {
			// Need JOIN for category filtering in count
			// Note: Table names embedded directly as they are considered safe internal properties.
			$join = " LEFT JOIN {$this->rel_table_name} r ON f.id = r.file_id";
			// Using %i for table names requires WP 6.2+
			// Fallback: $join = $wpdb->prepare( " LEFT JOIN %i r ON f.id = r.file_id", $this->rel_table_name );
			$where_clauses[] = "r.category_id = %d";
			$params[] = intval( $args['category_id'] );
		}

		$where = ! empty( $where_clauses ) ? "WHERE " . implode( " AND ", $where_clauses ) : '';

		// Construct the final query string
		// Table names embedded directly.
		$query = "{$select} {$from} {$join} {$where}";

		// Only prepare the query if there are parameters for the WHERE clause
		if ( ! empty( $params ) ) {
			$prepared_query = $wpdb->prepare( $query, $params );
		} else {
			$prepared_query = $query; // Use the constructed query directly if no params
		}

		if ( ! $prepared_query ) {
			error_log( "FVM DB Error (get_total_files): Failed to prepare query or query construction failed. SQL fragment: " . $query . " Params: " . print_r( $params, true ) );
			return 0;
		}

		$count = $wpdb->get_var( $prepared_query );

		return intval( $count );
	}

	/**
	 * Gets the direct download URL for a file.
	 * Constructs a URL pointing to the WordPress root with a `?file=` query parameter.
	 *
	 * @param string $file_name The unique file name (typically basename of the file path).
	 * @return string The generated download URL.
	 */
	public function get_file_url( string $file_name ): string {
		// Construct the URL using the home_url and ?file query parameter
		return home_url( '?file=' . urlencode( $file_name ) );
	}

	/**
	 * Handles serving a requested file via a direct download link ('?file=...').
	 * Fetches file details from the database, performs security checks (offline status, path validation),
	 * sets appropriate headers, and outputs the file content.
	 * Terminates script execution after sending the file or an error message.
	 *
	 * @param string $file_name The sanitized name of the file requested via the `?file=` query parameter.
	 * @return void Terminates script execution after sending file or headers.
	 */
	public function serve_file( string $file_name ): void {
		// Fetch file data using a prepared statement
		// Using %i for table name requires WP 6.2+
		$query = $this->wpdb->prepare(
			"SELECT * FROM %i WHERE file_name = %s",
			$this->file_table_name,
			$file_name
		);
		// Fallback:
		// $query = $this->wpdb->prepare(
		// 	"SELECT * FROM {$this->file_table_name} WHERE file_name = %s",
		// 	$file_name
		// );
		$file = $this->wpdb->get_row( $query );

		if ( ! $file ) {
			status_header( 404 );
			echo "Error: File record not found in database.";
			error_log( "[FVM] File serve error: File '" . esc_html( $file_name ) . "' not found in database." );
			exit;
		}

		// Check if file is marked as offline
		if ( ! empty( $file->file_offline ) && ! current_user_can( 'read_private_posts' ) ) { // Allow admins/editors to access offline files? Adjust capability check as needed.
			status_header( 403 ); // Forbidden
			echo "Error: This file is currently offline.";
			error_log( "[FVM] File serve error: Access denied to offline file ID " . $file->id . " ('" . esc_html( $file_name ) . "')" );
			exit;
		}

		// Construct absolute path using the correct method
		// Use the file_path from DB, assuming it's relative to ABSPATH
		$absolute_path = ABSPATH . ltrim( $file->file_path, '/' );

		// Validate the path is within the expected upload directory
		$expected_base_path = $this->get_upload_dir_path(); // Get the absolute path to the custom upload folder
		// Use realpath carefully, it returns false for non-existent paths
		$real_base = realpath( $expected_base_path );
		$real_file = realpath( $absolute_path );

		if ( ! $real_base || ! $real_file || strpos( $real_file, $real_base ) !== 0 ) {
			status_header( 403 ); // Forbidden
			echo "Error: Access denied. File path validation failed.";
			error_log( "[FVM] File serve error: Access denied due to path validation failure. Path: " . $absolute_path . " for DB entry ID " . $file->id );
			exit;
		}


		if ( ! file_exists( $absolute_path ) || ! is_readable( $absolute_path ) ) {
			status_header( 404 );
			if ( current_user_can( 'manage_options' ) ) { // Show more info to admins
				echo "Error: File found in database but not found or not readable on server at path: " . esc_html( $absolute_path );
			} else {
				echo "Error: File not found.";
			}
			error_log( "[FVM] File serve error: File not found or unreadable at " . $absolute_path . " for DB entry ID " . $file->id );
			exit;
		}

		$mime_type = $this->get_mime_type( $file->file_type ?: pathinfo( $absolute_path, PATHINFO_EXTENSION ) );

		// Determine content disposition (inline for common web types, attachment otherwise)
		$inline_types = [ 'pdf', 'txt', 'html', 'xml', 'css', 'js', 'json', 'jpg', 'jpeg', 'png', 'gif', 'svg', 'webp', 'bmp', 'tiff', 'mp3', 'ogg', 'wav', 'mp4', 'webm' ];
		$disposition = in_array( strtolower( $file->file_type ), $inline_types ) ? 'inline' : 'attachment';

		// Set headers
		nocache_headers(); // Prevent caching
		header( "Content-Type: " . $mime_type );
		header( "Content-Disposition: " . $disposition . "; filename=\"" . $file->file_name . "\"" ); // Use the original filename
		header( "Content-Length: " . filesize( $absolute_path ) );
		header( "X-Content-Type-Options: nosniff" ); // Prevent MIME sniffing
		header( "Content-Security-Policy: default-src 'none'; script-src 'none'; object-src 'none'" ); // Restrictive CSP
		header( "X-Robots-Tag: noindex, nofollow", true );

		// Clear output buffer
		if ( ob_get_level() ) {
			ob_end_clean();
		}

		// Read the file and output its contents
		// Use wp_read_file() for better error handling/potential memory benefits? Check context.
		readfile( $absolute_path );
		exit; // Terminate script execution
	}

	/**
	 * Gets the MIME type for a given file extension.
	 * Prioritizes WordPress's built-in MIME types, then falls back to a simple map.
	 *
	 * @param string $file_type The file extension (e.g., 'pdf', 'jpg').
	 * @return string The corresponding MIME type (e.g., 'application/pdf') or 'application/octet-stream' if unknown.
	 */
	private function get_mime_type( string $file_type ): string {
		$file_type = strtolower( $file_type );
		// Use WordPress built-in function for better coverage and maintainability
		$wp_mime_types = wp_get_mime_types();

		// Find the mime type for the given extension in WordPress list
		$found_mime = '';
		foreach ( $wp_mime_types as $ext_pattern => $mime ) {
			// Simple check, might need adjustment for patterns like 'jpg|jpeg|jpe'
			if ( preg_match( '/\\b' . preg_quote( $file_type, '/' ) . '\\b/i', $ext_pattern ) ) {
				$found_mime = $mime;
				break;
			}
		}

		// Fallback to simple map or default if not found in WP list
		if ( $found_mime ) {
			return $found_mime;
		}

		// Simple mapping for common types (can be extended or made filterable) - Use as fallback
		$simple_map = apply_filters( 'fvm_mime_types_simple_map', [ 
			'pdf' => 'application/pdf',
			'txt' => 'text/plain',
			'html' => 'text/html',
			'htm' => 'text/html',
			'xml' => 'application/xml',
			'css' => 'text/css',
			'js' => 'application/javascript',
			'json' => 'application/json',
			'jpg' => 'image/jpeg',
			'jpeg' => 'image/jpeg',
			'png' => 'image/png',
			'gif' => 'image/gif',
			'svg' => 'image/svg+xml',
			'webp' => 'image/webp',
			'bmp' => 'image/bmp',
			'tif' => 'image/tiff',
			'tiff' => 'image/tiff',
			'mp3' => 'audio/mpeg',
			'ogg' => 'audio/ogg',
			'wav' => 'audio/wav',
			'mp4' => 'video/mp4',
			'webm' => 'video/webm',
			'doc' => 'application/msword',
			'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
			'xls' => 'application/vnd.ms-excel',
			'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
			'ppt' => 'application/vnd.ms-powerpoint',
			'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
			'zip' => 'application/zip',
			'rar' => 'application/x-rar-compressed',
		] );

		return $simple_map[ $file_type ] ?? 'application/octet-stream';
	}
}