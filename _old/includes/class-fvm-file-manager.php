<?php

namespace FVM\FileVersionManager;

class FVM_File_Manager {
	private $upload_dir;
	private $custom_folder;
	private $wpdb;
	private $file_table_name;
	private $cat_table_name;
	private $rel_table_name;

	public function __construct( \wpdb $wpdb ) {
		$this->wpdb = $wpdb;
		$this->file_table_name = esc_sql( $wpdb->prefix . FILE_TABLE_NAME );
		$this->cat_table_name = esc_sql( $wpdb->prefix . CAT_TABLE_NAME );
		$this->rel_table_name = esc_sql( $wpdb->prefix . REL_TABLE_NAME );
		$this->custom_folder = 'filebase';
		$this->set_upload_dir();
	}

	public function init() {
		add_action( 'load-toplevel_page_fvm_files', [ $this, 'scan_files' ] );
		add_action( 'admin_post_update_file', [ $this, 'handle_file_update' ] );
	}

	/**
	 * Set the upload directory
	 * 
	 * @return void
	 */
	private function set_upload_dir() {
		$upload_dir = wp_upload_dir();
		$this->custom_folder = sanitize_file_name( $this->custom_folder );
		$this->upload_dir = str_replace( ABSPATH, '', trailingslashit( $upload_dir['basedir'] ) . $this->custom_folder );
		wp_mkdir_p( ABSPATH . $this->upload_dir );
	}

	/**
	 * Customizes the upload directory path based on the custom folder option set in the settings page.
	 * 
	 * @param array $uploads Array containing upload directory information.
	 * @return array Modified array with customized upload directory path.
	 */
	public function custom_upload_dir( $uploads ) {
		$custom_folder = 'filebase';
		$uploads['subdir'] = '/' . trim( $custom_folder, '/' );
		$uploads['path'] = $uploads['basedir'] . $uploads['subdir'];
		$uploads['url'] = $uploads['baseurl'] . $uploads['subdir'];
		return $uploads;
	}

	/**
	 * Scans the directory for new files and updates the database accordingly.
	 * This method compares the files in the database with the files in the upload directory.
	 * If a file is found in the directory but not in the database, it adds the file to the database.
	 * If a file is found in the database but not in the directory, it removes the file from the database.
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

		$db_files = $this->wpdb->get_results( "SELECT id, file_path FROM {$this->file_table_name}", ARRAY_A );
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
			$query = "INSERT INTO {$this->file_table_name} 
					  (file_name, file_display_name, file_path, file_url, file_size, file_type, file_version, date_uploaded, date_modified) 
					  VALUES " . implode( ', ', $placeholders );

			$this->wpdb->query( $this->wpdb->prepare( $query, $values ) );
		}
	}

	private function batch_delete_files( $file_ids ) {
		if ( ! empty( $file_ids ) ) {
			$placeholders = implode( ',', array_fill( 0, count( $file_ids ), '%d' ) );
			$query = "DELETE FROM {$this->file_table_name} WHERE id IN ($placeholders)";
			$this->wpdb->query( $this->wpdb->prepare( $query, $file_ids ) );
		}
	}

	/**
	 * Scan a directory recursively and return an array of file paths.
	 * Does not return hidden files or directories.
	 * 
	 * @param string $dir
	 * @return array
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
	 * Upload a file
	 * 
	 * @param array $file
	 * @param int|null $file_id
	 * @param string $file_version
	 * @return int|false
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
				$this->wpdb->update( $this->file_table_name, $metadata, [ 'id' => $file_id ] );
			} else {
				$metadata['date_uploaded'] = $current_time;
				$this->wpdb->insert( $this->file_table_name, $metadata );
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
	 * Upload multiple files
	 * 
	 * @param array $files
	 * @return array
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
	 * Update a file
	 * 
	 * @param int $file_id
	 * @param array $file
	 * @param string $file_version
	 * @return bool
	 */
	public function update_file( $file_id, $new_file, $new_version, $file_display_name, $file_description, $file_categories, $file_offline ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Unauthorized access' );
		}

		$existing_file = $this->wpdb->get_row( $this->wpdb->prepare( "SELECT * FROM $this->file_table_name WHERE id = %d", $file_id ), ARRAY_A );

		if ( ! $existing_file ) {
			return false;
		}

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
			$current_version = $existing_file['file_version'];
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

			// Delete the old file
			$old_file_path = ABSPATH . $existing_file['file_path'];

			// if ( file_exists( $old_file_path ) ) {
			// 	if ( unlink( $old_file_path ) ) {
			// 	} else {
			// 	}
			// }

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

				$update_data['file_name'] = $new_file_name;
				$update_data['file_path'] = $new_file_path;
				$update_data['file_url'] = $file_url;
				$update_data['file_type'] = $file_type;
				$update_data['file_size'] = $file_size;

				$update_format = array_merge( $update_format, array( '%s', '%s', '%s', '%s', '%d' ) );
			} else {
				return false;
			}
		}

		$update_data['date_modified'] = current_time( 'mysql' );
		$update_format[] = '%s';

		$result = $this->wpdb->update(
			$this->file_table_name,
			$update_data,
			array( 'id' => $file_id ),
			$update_format,
			array( '%d' )
		);

		if ( $result === false ) {
			return false;
		}

		// Get the updated file data
		$updated_file = $this->wpdb->get_row( $this->wpdb->prepare( "SELECT * FROM $this->file_table_name WHERE id = %d", $file_id ) );

		// Update file categories
		$this->update_file_categories( $file_id, $file_categories );

		// Track specific changes
		$changes = [];
		if ( ! empty( $new_file['tmp_name'] ) ) {
			$changes[] = 'Replaced file with {file_name}';
			$changes[] = sprintf( 'Size changed to %s', size_format( $update_data['file_size'] ) );
		}
		if ( ! empty( $file_display_name ) && $file_display_name !== $existing_file['file_display_name'] ) {
			$changes[] = sprintf( 'Display name changed from "%s" to "%s"', $existing_file['file_display_name'], $file_display_name );
		}
		if ( isset( $update_data['file_description'] ) && $update_data['file_description'] !== $existing_file['file_description'] ) {
			$changes[] = 'Description updated';
		}
		if ( $file_offline != $existing_file['file_offline'] ) {
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
					'old_file_name' => $existing_file['file_name'],
					'changes' => $changes,
				],
				'info'
			);
		}

		return true;
	}

	private function update_file_categories( $file_id, $category_ids ) {
		// Delete existing category relationships
		$this->wpdb->delete( $this->rel_table_name, array( 'file_id' => $file_id ), array( '%d' ) );

		// Insert new category relationships
		foreach ( $category_ids as $category_id ) {
			$this->wpdb->insert(
				$this->rel_table_name,
				array(
					'file_id' => $file_id,
					'category_id' => $category_id,
				),
				array( '%d', '%d' )
			);
		}
	}

	private function update_file_metadata( $file_name, $file_display_name, $file_path, $file_url, $file_size, $file_type, $file_version, $date_uploaded, $date_modified, $file_description ) {

		$result = $this->wpdb->insert(
			$this->file_table_name,
			array(
				'file_name' => $file_name,
				'file_display_name' => $file_display_name,
				'file_path' => $file_path,
				'file_url' => $file_url,
				'file_size' => $file_size,
				'file_type' => $file_type,
				'file_version' => $file_version,
				'date_uploaded' => $date_uploaded,
				'date_modified' => $date_modified,
				'file_description' => $file_description,
			),
			array( '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s' )
		);

		// if ( $result === false ) {
		// 	error_log( 'Database insertion failed: ' . $this->wpdb->last_error );
		// }
	}

	/**
	 * Increment the version number
	 * 
	 * @param string $file_version
	 * @return string
	 */
	private function increment_version( $file_version ) {
		$parts = explode( '.', $file_version );
		$major = intval( $parts[0] );
		$major++;
		return $major . '.0';
	}

	/**
	 * Get a file by ID
	 * 
	 * @param int $file_id
	 * @return object|false
	 */
	public function get_file( $file_id ) {
		return $this->wpdb->get_row( $this->wpdb->prepare( "SELECT * FROM {$this->file_table_name} WHERE id = %d", $file_id ) );
	}

	/**
	 * Public interface for file deletion that enforces specific contexts
	 * 
	 * @param int $file_id
	 * @param string $context The context of deletion (e.g., 'bulk', 'single', 'admin')
	 * @return bool
	 */
	public function handleFileDeletion( $file_id, $context = 'single' ) {
		// Verify context is valid
		if ( ! in_array( $context, [ 'bulk', 'single', 'admin' ] ) ) {
			return false;
		}

		return $this->deleteFile( $file_id, $context );
	}

	/**
	 * Delete a file
	 * 
	 * @param int $file_id
	 * @param string $context
	 * @return bool
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
		$absolute_path = ABSPATH . $file->file_path;
		$upload_dir = ABSPATH . $this->upload_dir;
		if ( strpos( realpath( $absolute_path ), realpath( $upload_dir ) ) !== 0 ) {
			return false;
		}

		// Delete physical file
		if ( file_exists( $absolute_path ) ) {
			if ( ! unlink( $absolute_path ) ) {
				return false;
			}
		}

		// Delete from database
		$deleted = $this->wpdb->delete(
			$this->file_table_name,
			[ 'id' => $file_id ],
			[ '%d' ]
		);

		if ( $deleted ) {
			// Clean up category relationships
			$this->wpdb->delete(
				$this->rel_table_name,
				[ 'file_id' => $file_id ],
				[ '%d' ]
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
	 * Get file data
	 * 
	 * @param int $file_id
	 * @return array|false
	 */
	public function get_file_data( $file_id ) {

		if ( ! current_user_can( 'manage_options' ) ) {
			return false;
		}

		// Verify nonce
		if ( ! isset( $_REQUEST['_ajax_nonce'] ) || ! wp_verify_nonce( $_REQUEST['_ajax_nonce'], 'get_file_data' ) ) {
			return false;
		}

		$file = $this->get_file( $file_id );
		if ( ! $file ) {
			return false;
		}

		// Get categories for the file with parent information
		$categories = $this->wpdb->get_results( $this->wpdb->prepare(
			"SELECT c.id, c.cat_name, c.cat_parent_id, IF(r.file_id IS NOT NULL, 1, 0) as checked
			 FROM {$this->cat_table_name} c
			 LEFT JOIN {$this->rel_table_name} r ON c.id = r.category_id AND r.file_id = %d
			 ORDER BY c.cat_parent_id ASC, c.cat_name ASC",
			$file_id
		) );

		$file_data = (array) $file;
		$file_data['categories'] = array_map( function ($category) {
			return [ 
				'id' => $category->id,
				'name' => $category->cat_name,
				'parent_id' => $category->cat_parent_id,
				'checked' => $category->checked == 1
			];
		}, $categories );

		$file_data['nonce'] = wp_create_nonce( 'edit_file_' . $file_id );

		return $file_data;
	}
}