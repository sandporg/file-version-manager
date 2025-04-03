<?php
namespace FVM\FileVersionManager;

class FVM_Ajax_Handler {
	private $file_manager;
	private $category_manager;

	public function __construct( FVM_File_Manager $file_manager, FVM_Category_Manager $category_manager ) {
		$this->file_manager = $file_manager;
		$this->category_manager = $category_manager;
	}

	public function init() {
		// Hook for getting category data (used in edit modal) - Assuming this exists or is needed
		add_action( 'wp_ajax_get_category_data', [ $this, 'ajax_get_category_data' ] );

		// Hook for getting files within a category (needed for the modal)
		add_action( 'wp_ajax_get_category_files', [ $this, 'ajax_get_category_files' ] );

		// Hook for getting individual file data (used in file edit modal)
		add_action( 'wp_ajax_get_file_data', [ $this, 'ajax_get_file_data' ] );

		// Add other AJAX hooks here as needed
	}

	/**
	 * AJAX handler to get files associated with a specific category.
	 * Used in category management screen (e.g., edit modal).
	 */
	public function ajax_get_category_files() {
		// Verify nonce and capability
		if ( ! current_user_can( 'manage_options' ) || ! check_ajax_referer( 'fvm_get_category_files', 'nonce', false ) ) {
			wp_send_json_error( [ 'message' => 'Unauthorized or invalid nonce.' ], 403 );
			wp_die();
		}

		$category_id = isset( $_GET['category_id'] ) ? intval( $_GET['category_id'] ) : 0;
		if ( $category_id <= 0 ) {
			wp_send_json_error( [ 'message' => 'Invalid Category ID provided.' ], 400 );
			wp_die();
		}

		// Use the injected file manager to get files filtered by category
		$files = $this->file_manager->get_files( [ 'category_id' => $category_id, 'posts_per_page' => -1 ] );

		if ( ! empty( $files ) ) {
			$file_list = [];
			foreach ( $files as $file ) {
				// Make sure the properties exist on the $file object
				$display_name = ! empty( $file->file_display_name ) ? $file->file_display_name : $file->file_name;
				$version = ! empty( $file->file_version ) ? $file->file_version : '1.0';
				$modified_date = ! empty( $file->date_modified ) ? $file->date_modified : $file->date_uploaded;
				$date_formatted = $modified_date ? mysql2date( get_option( 'date_format' ), $modified_date ) : 'N/A';
				$size_formatted = ! empty( $file->file_size ) ? size_format( $file->file_size, 2 ) : '0 B';
				$url = $this->file_manager->get_file_url( $file->file_name );

				$file_list[] = [ 
					'id' => $file->id,
					'name' => $display_name,
					'version' => $version,
					'date' => $date_formatted,
					'size' => $size_formatted,
					'url' => $url,
				];
			}
			wp_send_json_success( $file_list );
		} else {
			wp_send_json_error( [ 'message' => 'No files found for this category.' ], 404 );
		}
		wp_die(); // this is required to terminate immediately and return a proper response
	}

	/**
	 * AJAX handler to get data for a specific category.
	 * Used in category edit modal.
	 */
	public function ajax_get_category_data() {
		if ( ! current_user_can( 'manage_options' ) || ! check_ajax_referer( 'get_category_data', '_ajax_nonce', false ) ) {
			wp_send_json_error( 'Unauthorized or invalid nonce.', 403 );
			wp_die();
		}

		$category_id = isset( $_GET['category_id'] ) ? intval( $_GET['category_id'] ) : 0;
		if ( $category_id <= 0 ) {
			wp_send_json_error( 'Invalid category ID.', 400 );
			wp_die();
		}

		$category = $this->category_manager->get_category( $category_id );
		if ( ! $category ) {
			wp_send_json_error( 'Category not found.', 404 );
			wp_die();
		}

		// Prepare data for the modal
		$data = (array) $category; // Convert stdClass object to array if needed by JS
		$data['nonce'] = wp_create_nonce( 'edit_category_' . $category_id );
		$data['parent_categories'] = $this->category_manager->get_categories_hierarchical(); // Get all categories for dropdown

		wp_send_json_success( $data );
		wp_die();
	}

	/**
	 * AJAX handler to get data for a specific file.
	 * Used in file edit modal.
	 */
	public function ajax_get_file_data() {
		// Verify capability first
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => 'Capability check failed.' ], 403 );
			wp_die();
		}

		// Get file_id early to use in nonce check
		$file_id = isset( $_GET['file_id'] ) ? intval( $_GET['file_id'] ) : 0;
		if ( $file_id <= 0 ) {
			wp_send_json_error( [ 'message' => 'Invalid File ID provided.' ], 400 );
			wp_die();
		}

		// Verify nonce using the correct action string
		// Nonce is passed via _ajax_nonce in the GET request (see file-page.php JS)
		if ( ! check_ajax_referer( 'edit_file_' . $file_id, '_ajax_nonce', false ) ) {
			wp_send_json_error( [ 'message' => 'Nonce verification failed.' ], 403 );
			wp_die();
		}

		// Get file data using the file manager
		$file_data = $this->file_manager->get_file_data( $file_id );

		if ( ! $file_data ) {
			wp_send_json_error( [ 'message' => 'File not found.' ], 404 );
			wp_die();
		}

		// Prepare data for the modal
		$data = (array) $file_data; // Convert stdClass object to array

		// Get all categories for the dropdown selector
		$data['all_categories'] = $this->category_manager->get_categories_hierarchical();

		// Add a nonce for the update action
		$data['update_nonce'] = wp_create_nonce( 'fvm_update_file_' . $file_id );

		wp_send_json_success( $data );
		wp_die();
	}

	// Add other AJAX handler methods here...
}