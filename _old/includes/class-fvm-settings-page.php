<?php
namespace FVM\FileVersionManager;

class FVM_Settings_Page {
	private $update_ids;

	public function __construct( FVM_Migrate_WPFB $update_ids ) {
		$this->update_ids = $update_ids;
	}

	public function init() {
		add_action( 'admin_menu', [ $this, 'add_settings_page' ] );
		add_action( 'admin_init', [ $this, 'register_settings' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_styles' ] );
		add_action( 'admin_notices', [ $this, 'display_settings_updated_notice' ] );
		add_action( 'admin_notices', [ $this, 'display_admin_notification' ] );

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

	public function enqueue_styles() {
		if ( function_exists( 'get_current_screen' ) ) {
			$screen = get_current_screen();
			if ( $screen && $screen->id === 'files_page_fvm_settings' ) {
				wp_enqueue_style( 'file-version-manager-styles', plugin_dir_url( dirname( __FILE__ ) ) . 'assets/css/fvm-settings.css', [], '1.0.0' );
			}
		}
	}

	private function display_notification( $status = null, $message = null ) {
		if ( $status === null && $message === null ) {
			if ( isset( $_GET['notification'] ) ) {
				$status = $_GET['notification'];
				$message = isset( $_GET['message'] ) ? urldecode( $_GET['message'] ) : '';
			} else {
				return; // No notification to display
			}
		}

		$class = ( $status === 'success' ) ? 'notice-success' : 'notice-error';
		echo "<div class='notice " . esc_attr( $class ) . " is-dismissible'><p>" . esc_html( $message ) . "</p></div>";
	}

	private function redirect_with_notification( $status, $message ) {
		// Store the message in a transient
		set_transient( 'fvm_admin_notification', [ 
			'status' => $status,
			'message' => $message,
		], 60 );

		wp_safe_redirect( admin_url( 'admin.php?page=fvm_settings&tab=wp-filebase-pro' ) );
		exit;
	}

	public function display_admin_notification() {
		$notification = get_transient( 'fvm_admin_notification' );
		if ( $notification ) {
			$status = $notification['status'];
			$message = $notification['message'];
			?>
			<div class="notice notice-<?php echo esc_attr( $status === 'success' ? 'success' : 'error' ); ?> is-dismissible">
				<p><?php echo wp_kses_post( nl2br( $message ) ); ?></p>
			</div>
			<?php
			delete_transient( 'fvm_admin_notification' );
		}
	}

	public function display_settings_updated_notice() {
		// Check if we're on the correct settings page
		$screen = get_current_screen();
		if ( $screen && $screen->id === 'files_page_fvm_settings' ) {
			// Check if settings were just updated
			if ( isset( $_GET['settings-updated'] ) && $_GET['settings-updated'] == 'true' ) {
				$this->display_notification( 'success', __( 'Settings saved successfully.', 'file-version-manager' ) );
			}
		}
	}

	public function render_settings_page() {
		$active_tab = isset( $_GET['tab'] ) ? $_GET['tab'] : 'settings';
		?>
		<div class="wrap">
			<h1>File Version Manager</h1>
			<?php $this->display_notification(); ?>
			<div class="fvm-settings-tabs-container">
				<div class="fvm-settings-tabs">
					<a class="fvm-settings-tab <?php echo $active_tab === 'settings' ? 'active' : ''; ?>"
						href="?page=fvm_settings&tab=settings">Settings</a>
					<a class="fvm-settings-tab <?php echo $active_tab === 'wp-filebase-pro' ? 'active' : ''; ?>"
						href="?page=fvm_settings&tab=wp-filebase-pro">WP-Filebase Pro</a>
				</div>
			</div>
			<div class="fvm_settings-container">
				<?php
				if ( $active_tab === 'settings' ) {
					$this->render_settings_tab();
				} elseif ( $active_tab === 'wp-filebase-pro' ) {
					$this->render_wp_filebase_pro_tab();
				}
				?>
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
		?>
		<form method="post" action="options.php">
			<?php settings_fields( 'fvm_settings' ); ?>
			<?php do_settings_sections( 'fvm_settings' ); ?>
			<div class="fvm_settings-section">
				<h2>General Settings</h2>
				<div class="fvm_settings-section-content">

					<div class="fvm_field-group">
						<h3>Auto-Increment Version</h3>
						<div class="fvm_input-group">
							<input type="checkbox" name="fvm_auto_increment_version" value="1" <?php checked( get_option( 'fvm_auto_increment_version' ), 1 ); ?> />
							<span>Enable Auto-Increment Version</span>
						</div>
						<small class="description">Enable auto-increment version when files are replaced.</small>
					</div>

					<div class="fvm_field-group">
						<h3>File Scanning</h3>
						<div class="fvm_input-group">
							<input type="checkbox" name="fvm_disable_file_scan" value="1" <?php checked( get_option( 'fvm_disable_file_scan' ), 1 ); ?> />
							<span>Disable automatic file scanning</span>
						</div>
						<small class="description">Disable automatic scanning of the upload directory for new or deleted files.
							This may improve performance on sites with many files.</small>
					</div>

				</div>
			</div>

			<div class="fvm_settings-section">
				<h2>Developer Settings</h2>
				<div class="fvm_settings-section-content">
					<div class="fvm_field-group">
						<div class="fvm_input-group">
							<input type="checkbox" name="fvm_debug_logs" value="1" <?php checked( get_option( 'fvm_debug_logs' ), 1 ); ?> />
							<span>Enable debug logs</span>
						</div>
						<small class="description">Enable debug logs for migration and other methods in the plugin's
							settings.</small>
					</div>
				</div>
			</div>

			<?php submit_button(); ?>
		</form>

		<div class="fvm_settings-section" style="margin-top: 30px;">
			<h2>Database Upgrade</h2>
			<div class="fvm_settings-section-content">
				<div class="fvm_field-group">
					<h3>Upgrade Database Tables</h3>
					<div class="fvm_input-group">
						<p>
							Click the button below to upgrade the database tables to the latest version.
							This process will add any missing columns without losing existing data.
						</p>
					</div>
					<form method="post" action="<?php // echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="fvm_upgrade_database">
						<?php
						// wp_nonce_field( 'fvm_upgrade_database', 'fvm_upgrade_database_nonce' );
						// submit_button( 'Upgrade Database', 'disabled', 'upgrade_database' );
						// TODO: Update the database upgrade feature
						?>
						<button class="button button-primary" disabled>Upgrade Database</button>
					</form>
				</div>
			</div>
		</div>
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

		$import_message = get_transient( 'fvm_import_message' );
		if ( $import_message ) {
			$this->display_notification( $import_message['status'], $import_message['message'] );
			delete_transient( 'fvm_import_message' );
		}

		?>
		<div class="fvm_settings-section">
			<h2>Migrate WP-Filebase Pro Database</h2>
			<div class="fvm_settings-section-content">
				<div class="fvm_field-group">
					<h3>One-Click Migration</h3>
					<div class="fvm_input-group">
						<p>
							There are currently <?php echo esc_html( $category_count ); ?> categories and
							<?php echo esc_html( $file_count ); ?> files in the WP-Filebase tables.
							<br>
							This will affect the files in the custom directory:
							<?php echo esc_html( get_option( 'fvm_custom_directory', 'file-version-manager' ) ); ?>
						</p>
					</div>
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

		<div class="fvm_settings-section">
			<h2>Shortcodes</h2>
			<div class="fvm_settings-section-content">
				<div class="fvm_field-group">
					<h3>Update Shortcodes</h3>
					<div class="fvm_input-group">
						<p>
							Update all WP-Filebase Pro shortcodes to File Version Manager's format.
							<br>
							<strong>WARNING:</strong> Check to make sure your templates are compatible with File Version
							Manager's. This may cause some shortcodes to not display properly.
						</p>
					</div>
					<button class="button button-primary" disabled>Update</button>
				</div>
			</div>
		</div>
		<?php
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
		set_transient( 'fvm_import_message', [ 'status' => $status, 'message' => $message ], 60 );

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

		$this->redirect_with_notification( 'success', 'WP-Filebase import log cleared successfully' );
	}

	private function write_log() {
		$log_file = WP_CONTENT_DIR . '/fvm_update_ids.log';
		file_put_contents( $log_file, implode( "\n", $this->update_ids->get_log() ) . "\n\n", FILE_APPEND );
	}
}