<?php

namespace FVM\FileVersionManager;

#todo: - Fix screen option notifications not appearing due to disabling wordpress admin notices
#todo: - Remove body padding and update footer layout
#todo: - Update edit modal layout

/**
 * Class FVM_File_Page
 * Manages the admin page for displaying and handling file uploads, edits, and deletions.
 */
class FVM_File_Page {
	/**
	 * File manager instance.
	 * @var FVM_File_Manager
	 */
	private $file_manager;

	/**
	 * Instance of the WP_List_Table for displaying files.
	 * @var FVM_File_List_Table
	 */
	private $wp_list_table;

	/**
	 * Plugin base URL.
	 * @var string
	 */
	private $plugin_url;

	/**
	 * Plugin base path.
	 * @var string
	 */
	private $plugin_path;

	/**
	 * Screen option ID for items per page.
	 * @var string
	 */
	private $screen_option_id = 'fvm_files_per_page';

	/**
	 * Notice manager instance.
	 * @var FVM_Admin_Notices
	 */
	private $notice_manager;

	/**
	 * Constructor.
	 *
	 * @param FVM_File_Manager $file_manager Instance of the file manager.
	 * @param string $plugin_url Plugin base URL.
	 * @param string $plugin_path Plugin base path.
	 * @param FVM_Admin_Notices $notice_manager Instance of the notice manager.
	 */
	public function __construct( FVM_File_Manager $file_manager, $plugin_url, $plugin_path, FVM_Admin_Notices $notice_manager ) {
		$this->file_manager = $file_manager;
		$this->plugin_url = $plugin_url;
		$this->plugin_path = $plugin_path;
		$this->notice_manager = $notice_manager;
	}

	/**
	 * Initialize hooks.
	 */
	public function init() {
		add_action( 'admin_menu', [ $this, 'add_file_page' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_scripts' ] );
		add_action( 'load-toplevel_page_fvm_files', [ $this, 'setup_list_table' ] );
		add_action( 'admin_post_fvm_handle_upload', [ $this, 'handle_file_upload' ] );
		add_action( 'load-toplevel_page_fvm_files', [ $this, 'handle_file_deletion' ] );
		add_action( 'admin_post_fvm_update_file_details', [ $this, 'handle_file_update' ] );
		add_action( 'load-toplevel_page_fvm_files', [ $this, 'handle_bulk_actions' ] );

		// Add actions for Screen Options
		add_action( 'load-toplevel_page_fvm_files', [ $this, 'add_screen_options' ] );
		add_filter( 'set-screen-option', [ $this, 'set_screen_option' ], 10, 3 );
	}

	/**
	 * Add the main menu and submenu pages.
	 */
	public function add_file_page() {
		// Determine current page
		$current_page = $_GET['page'] ?? '';
		$plugin_pages = [ 'fvm_files', 'fvm_categories', 'fvm_settings' ];

		// Set color based on current page
		$icon_color = in_array( $current_page, $plugin_pages, true ) ? '#FFFFFF' : '#a0a5aa';

		// Get the appropriate base64 encoded icon
		$icon_url = self::get_admin_icon_b64( $icon_color );

		add_menu_page(
			__( 'File Version Manager', 'file-version-manager' ), // Page title
			__( 'Files', 'file-version-manager' ),          // Menu title
			'manage_options',                        // Capability
			'fvm_files',                             // Menu slug
			[ $this, 'display_admin_page' ],          // Callback function
			$icon_url,                             // Icon URL (dynamic Base64 SVG)
			11                                       // Position
		);

		add_submenu_page(
			'fvm_files',
			'All Files',
			'All Files',
			'manage_options',
			'fvm_files',
			array( $this, 'display_admin_page' )
		);
	}

	/**
	 * Set up the WP_List_Table instance.
	 * This is called on the load hook for the admin page.
	 */
	public function setup_list_table() {
		// Ensure the WP_List_Table class is available
		if ( ! class_exists( 'FVM_File_List_Table' ) ) {
			require_once __DIR__ . '/list-tables/class-fvm-file-list-table.php';
		}
		// Use $this->wp_list_table consistently
		$this->wp_list_table = new FVM_File_List_Table( $this->file_manager, $this->screen_option_id );
	}

	/**
	 * Enqueue admin scripts and styles.
	 *
	 * @param string $hook_suffix The current admin page hook.
	 */
	public function enqueue_admin_scripts( $hook_suffix ) {
		// Only load on our plugin's page
		if ( 'toplevel_page_fvm_files' !== $hook_suffix ) {
			return;
		}

		$upload_script_path = 'assets/js/fvm-admin-upload.js';
		$modal_script_path = 'assets/js/fvm-admin-modal.js';

		// Enqueue Upload Script
		wp_enqueue_script(
			'fvm-admin-upload',
			$this->plugin_url . $upload_script_path,
			[], // Dependencies
			filemtime( $this->plugin_path . $upload_script_path ), // Version for cache busting
			true // Load in footer
		);

		// Enqueue Modal Script
		wp_enqueue_script(
			'fvm-admin-modal',
			$this->plugin_url . $modal_script_path,
			[], // Dependencies
			filemtime( $this->plugin_path . $modal_script_path ), // Version for cache busting
			true // Load in footer
		);

		// Localize data for Modal Script
		wp_localize_script(
			'fvm-admin-modal',
			'fvmAdminModalData',
			[ 
				'ajax_url' => admin_url( 'admin-ajax.php' ),
				// Pass a nonce for the AJAX action itself (optional, as the link click provides one too)
				'get_file_nonce' => wp_create_nonce( 'get_file_data' ),
			]
		);
	}

	/**
	 * Add Screen Options tab to the admin page.
	 * Hooked to load-{page_hook}.
	 */
	public function add_screen_options() {
		$option = 'per_page';
		$args = [ 
			'label' => 'Files per page',
			'default' => 50,
			'option' => $this->screen_option_id,
		];

		add_screen_option( $option, $args );
	}

	/**
	 * Save the screen options.
	 * Hooked to the 'set-screen-option' filter.
	 *
	 * @param mixed $status Screen option value. Default false to skip.
	 * @param string $option The option name.
	 * @param mixed $value The number of rows to use.
	 * @return mixed The option value to save, or $status if not our option.
	 */
	public function set_screen_option( $status, $option, $value ) {
		if ( $this->screen_option_id === $option ) {
			// Add a success notice
			add_settings_error(
				'fvm_screen_options',
				'settings_updated',
				esc_html__( 'Screen options saved.', 'file-version-manager' ),
				'success' // or 'updated'
			);
			return $value;
		}
		return $status;
	}

	/**
	 * Handle file uploads submitted via POST.
	 * Hooked to admin_post_fvm_handle_upload.
	 * 
	 * @return void
	 */
	public function handle_file_upload() {
		// Check nonce
		check_admin_referer( 'fvm_handle_upload', 'fvm_handle_upload_nonce' );

		// Check capability
		if ( ! current_user_can( 'manage_options' ) ) { // Adjust capability if needed
			$this->notice_manager->add_notice_and_redirect( 'fvm_files', 'error', 'You do not have permission to upload files.' );
			exit;
		}

		if ( ! isset( $_FILES['file'] ) || ! is_array( $_FILES['file'] ) ) {
			$this->notice_manager->add_notice_and_redirect( 'fvm_files', 'error', 'No file data received.' );
			exit; // Use exit for admin_post handlers
		}

		if ( empty( $_FILES['file']['name'][0] ) ) {
			$this->notice_manager->add_notice_and_redirect( 'fvm_files', 'error', 'No file selected for upload.' );
			exit; // Use exit for admin_post handlers
		}

		$upload_results = $this->file_manager->upload_files( $_FILES['file'] );

		if ( ! empty( $upload_results ) ) {
			$count = count( $upload_results );
			/* translators: %s: number of files uploaded */
			$message = sprintf( _n( '%s file uploaded successfully.', '%s files uploaded successfully.', $count, 'file-version-manager' ), number_format_i18n( $count ) );
			$this->notice_manager->add_notice_and_redirect( 'fvm_files', 'success', $message );
		} else {
			$this->notice_manager->add_notice_and_redirect( 'fvm_files', 'error', 'Error uploading files. Please check file permissions and PHP upload settings.' );
		}
		exit; // Crucial after handling admin_post action
	}

	/**
	 * Handle single file deletion requested via GET.
	 * Hooked to load-{page_hook}.
	 * 
	 * @return void
	 */
	public function handle_file_deletion() {
		if ( isset( $_GET['action'] ) && $_GET['action'] === 'delete' && isset( $_GET['file_id'] ) ) {
			$file_id = intval( $_GET['file_id'] );

			// Check nonce (assuming nonce action from list table is 'delete_file_{id}')
			// The nonce field name is typically '_wpnonce' in URLs
			check_admin_referer( 'delete_file_' . $file_id );

			// Check capability
			if ( ! current_user_can( 'manage_options' ) ) { // Adjust capability if needed
				// Using wp_die for errors on load hooks is sometimes preferred over redirecting
				wp_die( 'You do not have permission to delete this file.', 'Permission Denied', [ 'response' => 403 ] );
			}

			$delete_result = $this->file_manager->handleFileDeletion( $file_id, 'single' );

			if ( $delete_result ) {
				$this->notice_manager->add_notice_and_redirect( 'fvm_files', 'success', 'File deleted successfully.' );
			} else {
				$this->notice_manager->add_notice_and_redirect( 'fvm_files', 'error', 'Error deleting file.' );
			}
			// No exit needed here as it's on a load hook, redirect handles leaving the script
		}
	}

	/**
	 * Handle file details update submitted via POST.
	 * Hooked to admin_post_fvm_update_file_details.
	 * 
	 * @return void
	 */
	public function handle_file_update() {
		// Check nonce first
		check_admin_referer( 'fvm_update_file_details', 'fvm_update_file_nonce' );

		// Check user capability
		if ( ! current_user_can( 'manage_options' ) ) { // Adjust capability if needed
			$this->notice_manager->add_notice_and_redirect( 'fvm_files', 'error', 'You do not have permission to update files.' );
			exit;
		}

		// Check if file_id is set
		if ( ! isset( $_POST['file_id'] ) ) {
			$this->notice_manager->add_notice_and_redirect( 'fvm_files', 'error', 'Error: Missing file ID.' );
			exit;
		}

		$file_id = intval( $_POST['file_id'] );
		$new_file = isset( $_FILES['new_file'] ) && ! empty( $_FILES['new_file']['tmp_name'] ) ? $_FILES['new_file'] : null;
		$file_version = isset( $_POST['file_version'] ) ? sanitize_text_field( $_POST['file_version'] ) : '';
		$file_display_name = isset( $_POST['file_display_name'] ) ? sanitize_text_field( $_POST['file_display_name'] ) : '';
		$file_description = isset( $_POST['file_description'] ) ? sanitize_textarea_field( $_POST['file_description'] ) : '';
		$file_categories = isset( $_POST['file_categories'] ) ? array_map( 'intval', $_POST['file_categories'] ) : array();
		$file_offline = isset( $_POST['file_offline'] ) ? 1 : 0;

		// If auto-increment is enabled and a new file is uploaded, pass an empty version
		$auto_increment_version = get_option( 'fvm_auto_increment_version', 1 );
		if ( $auto_increment_version && $new_file ) {
			$file_version = '';
		}

		$update_result = $this->file_manager->update_file( $file_id, $new_file, $file_version, $file_display_name, $file_description, $file_categories, $file_offline );

		if ( $update_result ) {
			$message = 'File updated successfully.';
			if ( $new_file ) {
				$message .= ' New file uploaded.';
			}
			$this->notice_manager->add_notice_and_redirect( 'fvm_files', 'success', $message );
		} else {
			$error_message = 'Error updating file.';
			if ( $new_file ) {
				$error_message .= ' File upload failed.';
			}
			$this->notice_manager->add_notice_and_redirect( 'fvm_files', 'error', $error_message );
		}
		exit; // Crucial after handling admin_post action
	}

	/**
	 * Handle bulk file deletion actions.
	 * Hooked to load-{page_hook}.
	 * 
	 * @return void
	 */
	public function handle_bulk_actions() {
		$this->setup_list_table();
		$action = $this->wp_list_table->current_action();

		if ( $action && in_array( $action, [ 'delete', 'bulk-delete' ] ) ) {
			// Verify bulk action nonce
			check_admin_referer( 'bulk-files' );

			$file_ids = isset( $_REQUEST['file'] ) ? (array) $_REQUEST['file'] : [];
			$file_ids = array_map( 'absint', $file_ids );

			if ( ! empty( $file_ids ) ) {
				$deleted_count = 0;
				foreach ( $file_ids as $file_id ) {
					// Pass true to indicate this is a bulk action
					if ( $this->file_manager->handleFileDeletion( $file_id, 'bulk' ) ) {
						$deleted_count++;
					}
				}

				if ( $deleted_count > 0 ) {
					$message = sprintf(
						_n( '%s file deleted successfully.', '%s files deleted successfully.', $deleted_count, 'file-version-manager' ),
						number_format_i18n( $deleted_count )
					);
					$this->notice_manager->add_notice_and_redirect( 'fvm_files', 'success', $message );
				} else {
					$this->notice_manager->add_notice_and_redirect( 'fvm_files', 'error', 'No files were deleted.' );
				}
			}
		}
	}

	/**
	 * Display the admin page content.
	 */
	public function display_admin_page() {

		ob_start();

		// Ensure the list table is prepared.
		if ( ! $this->wp_list_table ) {
			// This should ideally not happen if the load hook works, but as a fallback:
			$this->setup_list_table();
		}
		$this->wp_list_table->prepare_items();

		?>

		<div class="wrap" style="margin-top: 50px;">

			<h1 class="wp-heading-inline"><?php echo esc_html( get_admin_page_title() ); ?></h1>
			<hr class="wp-header-end">

			<?php
			// Manually render notices here
			$this->notice_manager->display_persistent_upgrade_notice_if_needed();
			FVM_Admin_Notices::render_fvm_notices();
			?>

			<div id="fvm-upload-container" class="fvm-upload-container">
				<form method="post" enctype="multipart/form-data" id="fvm-upload-form"
					action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="fvm_handle_upload">
					<?php wp_nonce_field( 'fvm_handle_upload', 'fvm_handle_upload_nonce' ); ?>
					<div class="fvm-dropzone">
						<div class="upload-ui">
							<h2 class="fvm-upload-instructions">Drop files to upload</h2>
							<p class="fvm-upload-instructions">or</p>
							<div class="fvm-file-name-container" style="display: none;">
								<p id="fvm-file-name"></p>
								<span class="fvm-clear-file dashicons dashicons-no-alt"></span>
							</div>
							<input type="file" name="file[]" id="fvm-file-input" style="display: none;" multiple>
							<button type="button" id="fvm-select-file" class="browser button button-hero">Select Files</button>
							<input type="submit" name="fvm_upload_file" id="fvm-upload-button" value="Upload Files"
								class="button button-primary button-hero" style="display: none;">
						</div>
						<div class="post-upload-ui" id="post-upload-info">
							<p class="fvm-upload-instructions">
								Maximum upload file size: <?php echo esc_html( size_format( wp_max_upload_size() ) ); ?>.

								<?php
								// Check if Big File Uploads plugin is active
								if ( class_exists( 'BigFileUploads' ) ) {
									$bfu_settings_url = admin_url( 'options-general.php?page=big_file_uploads' );
									echo ' <small><a href="' . esc_url( $bfu_settings_url ) . '" style="text-decoration:none;">' . esc_html__( 'Change', 'file-version-manager' ) . '</a></small>';
								}
								?>

							</p>
						</div>
					</div>
				</form>
			</div>

			<div id="edit-modal" class="edit-modal">
				<form id="edit-form" method="post" enctype="multipart/form-data"
					action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">

					<input type="hidden" name="action" value="fvm_update_file_details">
					<?php wp_nonce_field( 'fvm_update_file_details', 'fvm_update_file_nonce' ); ?>
					<input type="hidden" name="file_id" id="edit-file-id" value="">

					<div class="edit-modal-content-container">
						<div class="edit-modal-content">
							<span class="close">&times;</span>

							<div class="fvm-edit-modal-title">
								<h2>Edit File</h2>
								<h3 id="file_id"></h3>
							</div>

							<div id="fvm-dropzone-edit" class="fvm-dropzone">
								<div class="upload-ui">
									<h2 class="fvm-upload-instructions">Drop files to upload</h2>
									<p class="fvm-upload-instructions">or</p>
									<div class="fvm-file-name-container" style="display: none;">
										<p id="fvm-edit-file-name"></p>
										<span class="fvm-clear-file dashicons dashicons-no-alt"></span>
									</div>
									<input type="file" name="new_file" id="new_file" style="display: none;">
									<button type="button" id="fvm-edit-select-file" class="browser button button-hero">Select
										File</button>
								</div>
								<div class="post-upload-ui" id="post-upload-info">
									<p class="fvm-upload-instructions">
										Maximum upload file size:
										<?php echo esc_html( size_format( wp_max_upload_size() ) ); ?>.

										<?php
										// Check if Big File Uploads plugin is active
										if ( class_exists( 'BigFileUploads' ) ) {
											$bfu_settings_url = admin_url( 'options-general.php?page=big_file_uploads' );
											echo ' <small><a href="' . esc_url( $bfu_settings_url ) . '" style="text-decoration:none;">' . esc_html__( 'Change', 'file-version-manager' ) . '</a></small>';
										}
										?>
									</p>
								</div>
							</div>

							<table class="form-table">
								<tr>
									<th scope="row"><label for="file_name">File Name</label></th>
									<td>
										<input type="text" id="file_name" name="file_name" value="" class="regular-text"
											readonly disabled>
									</td>
								</tr>
								<tr>
									<th scope="row"><label for="file_display_name">Display Name</label></th>
									<td>
										<input type="text" name="file_display_name" id="file_display_name" value=""
											class="regular-text">
									</td>
								</tr>
								<tr>
									<th scope="row"><label for="file_description">Description</label></th>
									<td>
										<textarea name="file_description" id="file_description" class="regular-text"
											rows="3"></textarea>
									</td>
								</tr>
								<tr>
									<th scope="row"><label for="file_categories">Categories</label></th>
									<td>
										<div class="fvm-file-categories-container">
											<div class="fvm-file-categories-container-inner" id="file_categories">
												<!-- Categories will be populated dynamically -->
											</div>
										</div>
									</td>
								</tr>
								<?php if ( ! get_option( 'fvm_auto_increment_version', 1 ) ) : ?>
									<tr>
										<th scope="row"><label for="file_version">Version</label></th>
										<td>
											<input type="number" step="0.1" min="0" name="file_version" id="file_version" value=""
												class="regular-text">
										</td>
									</tr>
								<?php endif; ?>
								<tr>
									<th scope="row"><label for="file_url">File URL</label></th>
									<td>
										<input type="text" id="file_url" name="file_url" value="" class="regular-text" readonly
											disabled>
									</td>
								</tr>
								<tr>
									<th scope="row"><label for="md5_hash">MD5 Hash</label></th>
									<td>
										<input type="text" id="md5_hash" name="md5_hash" value="" class="regular-text" readonly
											disabled>
									</td>
								</tr>
								<tr>
									<th scope="row"><label for="sha256_hash">SHA256 Hash</label></th>
									<td>
										<input type="text" id="sha256_hash" name="sha256_hash" value="" class="regular-text"
											readonly disabled>
									</td>
								</tr>
							</table>
						</div>
						<div class="fvm-edit-modal-footer">
							<div class="fvm-edit-modal-footer-inner">
								<label class="switch">
									<input type="checkbox" name="file_offline" id="file_offline" value="1">
									<span class="slider round"></span>
								</label>
								<span>Disabled</span>
							</div>
							<p class="submit">
								<button type="button" class="button cancel-edit">Cancel</button>
								<input type="submit" name="update_file" id="update_file" class="button button-primary"
									value="Update File">
							</p>
						</div>
					</div>
				</form>
			</div>
			<div id="edit-modal-overlay" class="edit-modal-overlay"></div>

			<form method="get">
				<input type="hidden" name="page" value="fvm_files" />
				<?php
				$this->wp_list_table->search_box( 'Search Files', 'search' );
				?>
			</form>

			<form method="post">
				<?php
				wp_nonce_field( 'bulk-files' );
				$this->wp_list_table->display_bulk_action_result();
				$this->wp_list_table->display();
				?>
			</form>

		</div>
		<?php
		ob_end_flush();
	}

	/**
	 * Returns the admin icon SVG string with a color placeholder.
	 *
	 * @return string The raw SVG string with '%s' for the fill color.
	 */
	public static function get_admin_icon_svg() {
		// Raw SVG code with %s placeholder for the fill color.
		// Make sure the fill="%s" is on the correct <path> elements for your SVG.
		return '<svg xmlns="http://www.w3.org/2000/svg" id="Layer_1" version="1.1" viewBox="0 0 612 612"><path d="M343.7 66.8v156.6c0 6.4 5.2 11.6 11.6 11.6h156.6L343.6 66.7Z" fill="%s" opacity=".4"/><path d="M343.7 223.4V66.8H160c-6.4 0-11.6 5.2-11.6 11.6V542c0 6.4 5.2 11.6 11.6 11.6h340.3c6.4 0 11.6-5.2 11.6-11.6V235.1H355.3c-6.4 0-11.6-5.2-11.6-11.6Z" fill="%s"/></svg>';
	}

	/**
	 * Returns the admin icon as a Base64 encoded data URI.
	 *
	 * @param string|false $color The hex color for the icon, or false for the default pre-encoded inactive icon.
	 * @return string The Base64 encoded data URI.
	 */
	public static function get_admin_icon_b64( $color = false ) {
		if ( $color ) {
			$svg_xml = self::get_admin_icon_svg();
			return 'data:image/svg+xml;base64,' . base64_encode( sprintf( $svg_xml, $color, $color ) );
		} else {
			// Return pre-encoded default (inactive #a0a5aa) version
			return 'data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIGlkPSJMYXllcl8xIiB2ZXJzaW9uPSIxLjEiIHZpZXdCb3g9IjAgMCA2MTIgNjEyIj48cGF0aCBkPSJNMzQzLjcgNjYuOHYxNTYuNmMwIDYuNCA1LjIgMTEuNiAxMS42IDExLjZoMTU2LjZMMzQzLjYgNjYuN1oiIGZpbGw9IiVzIiBvcGFjaXR5PSIuNCIvPjxwYXRoIGQ9Ik0zNDMuNyAyMjMuNFY2Ni44SDE2MGMtNi40IDAtMTEuNiA1LjItMTEuNiAxMS42VjU0MmMwIDYuNCA1LjIgMTEuNiAxMS42IDExLjZoMzQwLjNjNi40IDAgMTEuNi01LjIgMTEuNi0xMS42VjIzNS4xSDM1NS4zYy02LjQgMC0xMS42LTUuMi0xMS42LTExLjZaIiBmaWxsPSIlcyIvPjwvc3ZnPg==';
		}
	}
}