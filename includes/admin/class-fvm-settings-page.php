<?php
namespace FVM\FileVersionManager;

class FVM_Settings_Page {
	private $update_ids;
	private $plugin_url;
	/**
	 * Notice manager instance.
	 * @var FVM_Admin_Notices
	 */
	private $notice_manager;

	public function __construct( FVM_Migrate_WPFB $update_ids, $plugin_url, FVM_Admin_Notices $notice_manager ) {
		$this->update_ids = $update_ids;
		$this->plugin_url = $plugin_url;
		$this->notice_manager = $notice_manager;
	}

	public function init() {
		add_action( 'admin_menu', [ $this, 'add_settings_page' ] );
		add_action( 'admin_init', [ $this, 'register_settings' ] );

		add_action( 'wp_ajax_export_wpfilebase_files', [ $this, 'ajax_export_wpfilebase_files' ] );
		add_action( 'wp_ajax_get_export_progress', [ $this, 'ajax_get_export_progress' ] );
		add_action( 'admin_post_fvm_update_ids', [ $this, 'handle_csv_upload' ] );
		add_action( 'admin_post_fvm_clear_log', [ $this, 'handle_clear_log' ] );
		add_action( 'admin_post_fvm_import_categories', [ $this, 'handle_category_import' ] );
		add_action( 'admin_post_fvm_clear_log_categories', [ $this, 'handle_clear_log_categories' ] );
		add_action( 'admin_post_fvm_import_wpfilebase', [ $this, 'handle_wpfilebase_import' ] );
		add_action( 'admin_post_fvm_clear_log_wpfilebase', [ $this, 'handle_clear_log_wpfilebase' ] );
		add_action( 'admin_post_fvm_set_default_options', [ $this, 'handle_set_default_options' ] );
	}

	public function add_settings_page() {
		add_submenu_page(
			'fvm_files',
			'File Version Manager Settings',
			'Settings',
			'manage_options',
			'fvm_settings',
			array( $this, 'render_settings_page' )
		);
	}

	public function register_settings() {
		// register_setting( 'fvm_settings', 'fvm_custom_directory' );
		register_setting( 'fvm_settings', 'fvm_debug_logs' );
		register_setting( 'fvm_settings', 'fvm_auto_increment_version' );
		register_setting( 'fvm_settings', 'fvm_nginx_rewrite_rules' );
		register_setting( 'fvm_settings', 'fvm_disable_file_scan' );
	}

	public function set_default_options() {
		if ( get_option( 'fvm_debug_logs' ) === false ) {
			update_option( 'fvm_debug_logs', 0 );
		}
		if ( get_option( 'fvm_auto_increment_version' ) === false ) {
			update_option( 'fvm_auto_increment_version', 0 );
		}
		if ( get_option( 'fvm_nginx_rewrite_rules' ) === false ) {
			update_option( 'fvm_nginx_rewrite_rules', 0 );
		}
		if ( get_option( 'fvm_disable_file_scan' ) === false ) {
			update_option( 'fvm_disable_file_scan', 0 );
		}
	}

	private function get_wpfilebase_file_count() {
		global $wpdb;
		$files_table = $wpdb->prefix . 'wpfb_files';
		$cache_key = 'fvm_wpfilebase_file_count';
		$file_count = wp_cache_get( $cache_key );

		if ( false === $file_count ) {
			$file_count = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i", $files_table ) );
			wp_cache_set( $cache_key, $file_count, '', 3600 ); // Cache for 1 hour
		}

		return $file_count;
	}

	private function get_wpfilebase_category_count() {
		global $wpdb;
		$cats_table = $wpdb->prefix . 'wpfb_cats';
		$cache_key = 'fvm_wpfilebase_category_count';
		$category_count = wp_cache_get( $cache_key );

		if ( false === $category_count ) {
			$category_count = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i", $cats_table ) );
			wp_cache_set( $cache_key, $category_count, '', 3600 ); // Cache for 1 hour
		}

		return $category_count;
	}

	public function handle_wpfilebase_import() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Unauthorized access' );
		}

		check_admin_referer( 'fvm_import_wpfilebase', 'fvm_import_wpfilebase_nonce' );

		$result = $this->update_ids->import_from_wpfilebase();

		$log_content = implode( "\n", $this->update_ids->get_log() );
		file_put_contents( WP_CONTENT_DIR . '/fvm_import_wpfilebase.log', $log_content );

		$message = $result['message'] . "\n\nCheck the log file for details.";
		$status = $result['success'] ? 'success' : 'error';
		set_transient( 'fvm_admin_notice', [ 'message' => $message, 'type' => $status, 'is_dismissible' => true ], 60 );

		wp_safe_redirect( admin_url( 'admin.php?page=fvm_settings&tab=wp-filebase-pro' ) );
		exit;
	}

	public function handle_clear_log_wpfilebase() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Unauthorized access' );
		}

		check_admin_referer( 'fvm_clear_log_wpfilebase', 'fvm_clear_log_wpfilebase_nonce' );

		$log_file = WP_CONTENT_DIR . '/fvm_import_wpfilebase.log';
		if ( file_exists( $log_file ) ) {
			unlink( $log_file );
		}

		$this->notice_manager->add_notice_and_redirect( 'fvm_settings', 'success', 'WP-Filebase import log cleared successfully' );
		exit;
	}

	private function write_log() {
		$log_file = WP_CONTENT_DIR . '/fvm_update_ids.log';
		file_put_contents( $log_file, implode( "\n", $this->update_ids->get_log() ) . "\n\n", FILE_APPEND );
	}

	public function render_settings_page() {
		$active_tab = isset( $_GET['tab'] ) ? $_GET['tab'] : 'settings';
		?>
		<div class="wrap" style="margin-top: 0;">

			<div class="fvm-settings-page-container">
				<div class="fvm-settings-tabs-container">
					<div class="fvm-settings-tabs">
						<a class="fvm-settings-tab <?php echo $active_tab === 'settings' ? 'active' : ''; ?>"
							href="?page=fvm_settings&tab=settings">Settings</a>
						<a class="fvm-settings-tab <?php echo $active_tab === 'wp-filebase-pro' ? 'active' : ''; ?>"
							href="?page=fvm_settings&tab=wp-filebase-pro">WP-Filebase Pro</a>
					</div>
				</div>
				<div class="fvm_settings-container">
					<h1 class="wp-heading-inline"><?php echo esc_html( get_admin_page_title() ); ?></h1>
					<hr class="wp-header-end">

					<?php
					// Manually render notices here
					$this->notice_manager->display_persistent_upgrade_notice_if_needed();

					// Check if settings were just updated and add our own notice
					if ( isset( $_GET['settings-updated'] ) && $_GET['settings-updated'] ) {
						// Use the static method from FVM_Admin_Notices to add a notice for the current request
						FVM_Admin_Notices::add_notice( __( 'Settings saved.', 'file-version-manager' ), 'success' );
					}

					FVM_Admin_Notices::render_fvm_notices();
					?>

					<div class="fvm-settings-content-container">
						<?php
						if ( $active_tab === 'settings' ) {
							$this->render_settings_tab();
						} elseif ( $active_tab === 'wp-filebase-pro' ) {
							$this->render_wp_filebase_pro_tab();
						}
						?>
					</div>
				</div>
			</div>
		</div>

		<?php
	}

	/**
	 * Render the settings tab
	 * 
	 * @return void
	 */
	private function render_settings_tab() {

		// Check if a database upgrade is needed
		if ( class_exists( 'FVM\FileVersionManager\FVM_Upgrade' ) ) {
			$current_db_version = get_option( FVM_Upgrade::DB_VERSION_OPTION, '1.0' );
			if ( version_compare( $current_db_version, FVM_Upgrade::CURRENT_DB_VERSION, '<' ) ) {
				?>
				<div class="fvm_settings-card fvm-database-upgrade-card">
					<div class="fvm_settings-card-header">
						<svg xmlns="http://www.w3.org/2000/svg" id="Layer_1" version="1.1" width="22" height="22" viewBox="0 0 100 100">
							<path
								d="m-323.1 42.49-11.15 5.37c-1.86.9-3.98-.64-3.7-2.69l1.66-12.27-8.55-8.95c-1.43-1.5-.62-3.98 1.41-4.35l12.18-2.21 5.87-10.9c.98-1.82 3.59-1.82 4.58 0l5.87 10.9 12.18 2.21c2.04.37 2.84 2.86 1.41 4.35l-8.55 8.95 1.66 12.27c.28 2.05-1.84 3.59-3.7 2.69l-11.15-5.37ZM-30.1 61.22l-7.75-5.48a2.24 2.24 0 0 1-.95-2.1c.15-1.2.24-2.41.24-3.64s-.09-2.45-.24-3.64c-.1-.82.27-1.63.95-2.1l7.75-5.48c.8-.56 1.16-1.58.85-2.51-.62-1.85-1.36-3.64-2.22-5.36-.43-.88-1.41-1.34-2.37-1.18l-9.4 1.62c-.81.14-1.62-.2-2.14-.84a31.39 31.39 0 0 0-5.12-5.12c-.65-.51-.98-1.32-.84-2.14l1.62-9.4c.17-.96-.3-1.94-1.18-2.37-1.73-.85-3.52-1.59-5.36-2.22-.93-.31-1.95.05-2.51.85l-5.48 7.75a2.24 2.24 0 0 1-2.1.95c-1.2-.15-2.41-.24-3.64-.24s-2.45.09-3.64.24c-.82.1-1.63-.27-2.1-.95l-5.48-7.75c-.56-.8-1.58-1.16-2.51-.85-1.85.62-3.64 1.36-5.36 2.22-.88.43-1.34 1.41-1.18 2.37l1.62 9.4c.14.81-.2 1.62-.84 2.14a31.39 31.39 0 0 0-5.12 5.12c-.51.65-1.32.98-2.14.84l-9.4-1.62c-.96-.17-1.94.3-2.37 1.18-.85 1.73-1.59 3.52-2.22 5.36-.31.93.05 1.95.85 2.51l7.75 5.48c.68.48 1.05 1.28.95 2.1-.15 1.2-.24 2.41-.24 3.64s.09 2.45.24 3.64c.1.82-.27 1.63-.95 2.1l-7.75 5.48c-.8.56-1.16 1.58-.85 2.51.62 1.85 1.36 3.64 2.22 5.36.43.88 1.41 1.34 2.37 1.18l9.4-1.62c.81-.14 1.62.2 2.14.84 1.5 1.9 3.22 3.61 5.12 5.12.65.51.98 1.32.84 2.14l-1.62 9.4c-.17.96.3 1.94 1.18 2.37 1.73.85 3.52 1.59 5.36 2.22.93.31 1.95-.05 2.51-.85l5.48-7.75a2.24 2.24 0 0 1 2.1-.95c1.2.15 2.41.24 3.64.24s2.45-.09 3.64-.24c.82-.1 1.63.27 2.1.95l5.48 7.75c.56.8 1.58 1.16 2.51.85 1.85-.62 3.64-1.36 5.36-2.22.88-.43 1.34-1.41 1.18-2.37l-1.62-9.4c-.14-.81.2-1.62.84-2.14 1.9-1.5 3.61-3.22 5.12-5.12.51-.65 1.32-.98 2.14-.84l9.4 1.62c.96.17 1.94-.3 2.37-1.18.85-1.73 1.59-3.52 2.22-5.36.31-.93-.05-1.95-.85-2.51ZM-70 69.96c-11.02 0-19.96-8.94-19.96-19.96S-81.02 30.04-70 30.04-50.04 38.98-50.04 50-58.98 69.96-70 69.96ZM50 6.95C26.23 6.95 6.95 26.23 6.95 50S26.22 93.05 50 93.05 93.05 73.78 93.05 50 73.77 6.95 50 6.95Zm-5.32 70.19c-2.59 0-4.46-1.88-4.46-4.46 0-3.12 2.63-5.22 5.08-5.22s4.46 1.92 4.46 4.47c0 3.12-2.63 5.22-5.08 5.22Zm14.89-48.43c-.32 1.35-1.33 4.48-2.6 8.44-2.36 7.34-5.58 17.4-7.38 24.96-.24 1.2-1.08 1.97-2.13 1.97-.1 0-.2 0-.3-.02a1.95 1.95 0 0 1-1.34-.81c-.24-.35-.5-.95-.32-1.86 1.58-7.25 2.96-17.12 3.96-24.33.65-4.66 1.16-8.34 1.51-9.8.59-2.76 2.37-4.41 4.78-4.41 1.31 0 2.43.51 3.15 1.44.86 1.1 1.1 2.68.66 4.41Z"
								fill="#dba617" />
						</svg>
						<h3>Database Upgrade</h3>
					</div>
					<div class="fvm_settings-card-content">
						<div class="fvm_field-group">
							<p>
								<?php
								printf(
									/* translators: 1: Current DB version, 2: Expected DB version */
									esc_html__( 'Your database is version %1$s, but version %2$s is expected. Click the button below to upgrade the database tables to the latest version.', 'file-version-manager' ),
									'<code>' . esc_html( $current_db_version ) . '</code>',
									'<code>' . esc_html( FVM_Upgrade::CURRENT_DB_VERSION ) . '</code>'
								);
								?>
								<br>
								<b>Be sure to make a backup of your database before running the upgrade.</b>
							</p>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
								<input type="hidden" name="action" value="fvm_manual_upgrade">
								<?php
								wp_nonce_field( 'fvm_manual_upgrade_nonce' );
								submit_button( __( 'Run Database Upgrade', 'file-version-manager' ), 'primary', 'fvm_manual_upgrade_submit' );
								?>
							</form>
						</div>
					</div>
				</div>
				<?php
			}
		}
		?>

		<form method="post" action="options.php">
			<?php settings_fields( 'fvm_settings' ); ?>
			<?php do_settings_sections( 'fvm_settings' ); ?>

			<div class="fvm_settings-card-container">
				<!-- General Settings Card -->
				<div class="fvm_settings-card">
					<div class="fvm_settings-card-header">
						<h3>Auto-Increment Version</h3>
					</div>
					<div class="fvm_settings-card-content">
						<div class="fvm_field-group">
							<p>Automatically increments the file version when a file is replaced.</p>
							<div class="fvm_input-group fvm_input-group-toggle">
								<label class="switch">
									<input type="checkbox" name="fvm_auto_increment_version" value="1" <?php checked( get_option( 'fvm_auto_increment_version' ), 1 ); ?> />
									<span class="slider round"></span>
								</label>
							</div>
						</div>
					</div>
				</div>

				<!-- File Scanning Card -->
				<div class="fvm_settings-card">
					<div class="fvm_settings-card-header">
						<h3>File Scanning</h3>
					</div>
					<div class="fvm_settings-card-content">
						<div class="fvm_field-group">
							<p>Automatically scan the upload directory for new or deleted files. Disabling this may improve
								performance on sites with many files.</p>
							<div class="fvm_input-group fvm_input-group-toggle">
								<label class="switch">
									<input type="checkbox" name="fvm_disable_file_scan" value="1" <?php checked( get_option( 'fvm_disable_file_scan' ), 1 ); ?> />
									<span class="slider round"></span>
								</label>
							</div>
						</div>
					</div>
				</div>

				<!-- Debug Logs Card -->
				<div class="fvm_settings-card">
					<div class="fvm_settings-card-header">
						<h3>Debug Logs</h3>
					</div>
					<div class="fvm_settings-card-content">
						<div class="fvm_field-group">
							<p>Enable debug logs to help with troubleshooting. These logs are stored in the
								<code>wp&#x2011;content</code> directory.
							</p>
							<div class="fvm_input-group fvm_input-group-toggle">
								<label class="switch">
									<input type="checkbox" name="fvm_debug_logs" value="1" <?php checked( get_option( 'fvm_debug_logs' ), 1 ); ?> />
									<span class="slider round"></span>
								</label>
							</div>
						</div>
					</div>
				</div>



			</div>

			<?php submit_button(); ?>
		</form>
		<?php
	}

	/**
	 * Render the WP-Filebase Pro settings tab
	 * 
	 * @return void
	 */
	private function render_wp_filebase_pro_tab() {
		global $wpdb;
		$files_table = $wpdb->prefix . 'wpfb_files';
		$cats_table = $wpdb->prefix . 'wpfb_cats';
		$file_count = $this->get_wpfilebase_file_count();
		$category_count = $this->get_wpfilebase_category_count();

		?>
		<div class="fvm_settings-card-container">

			<!-- Migrate WP-Filebase Pro Database Card -->
			<div class="fvm_settings-card" style="grid-column: 1 / 3;">
				<div class="fvm_settings-card-header">
					<h3>One-Click Migration</h3>
				</div>
				<div class="fvm_settings-card-content">
					<div class="fvm_field-group">
						<p>
							There are currently <b><?php echo esc_html( $category_count ); ?> categories</b> and
							<b><?php echo esc_html( $file_count ); ?> files</b> in the WP-Filebase tables.
							<br>
							This will affect the files in the custom directory:
							<code>/wp-content/uploads/<?php echo esc_html( get_option( 'fvm_custom_directory', 'file-version-manager' ) ); ?></code>
						</p>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<input type="hidden" name="action" value="fvm_import_wpfilebase">
							<?php wp_nonce_field( 'fvm_import_wpfilebase', 'fvm_import_wpfilebase_nonce' ); ?>
							<?php submit_button( 'Start Migration', 'primary', 'import_wpfilebase' ); ?>
						</form>
					</div>

					<?php
					if ( get_option( 'fvm_debug_logs' ) ) {
						$log_file = WP_CONTENT_DIR . '/fvm_import_wpfilebase.log';
						if ( file_exists( $log_file ) ) {
							$log_content = file_get_contents( $log_file );
							?>
							<div class="fvm_log-container">
								<pre><?php echo esc_html( $log_content ); ?></pre>
							</div>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
								<input type="hidden" name="action" value="fvm_clear_log_wpfilebase">
								<?php wp_nonce_field( 'fvm_clear_log_wpfilebase', 'fvm_clear_log_wpfilebase_nonce' ); ?>
								<?php submit_button( 'Clear Log', 'secondary', 'clear_log_wpfilebase', false ); ?>
							</form>
							<?php
						}
					}
					?>

				</div>
			</div>

			<!-- Update Shortcodes Card -->
			<div class="fvm_settings-card">
				<div class="fvm_settings-card-header">
					<h3>Update Shortcodes</h3>
				</div>
				<div class="fvm_settings-card-content">
					<div class="fvm_field-group">
						<p>Update all WP-Filebase Pro shortcodes to File Version Manager shortcodes.</p>
						<button class="button button-primary" disabled>Currently unavailable</button>
					</div>
				</div>
			</div>
		</div>
		<?php
	}
}