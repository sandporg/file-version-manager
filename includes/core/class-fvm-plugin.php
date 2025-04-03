<?php

namespace FVM\FileVersionManager;

/**
 * Main Plugin Orchestration Class
 */
class FVM_Plugin {

	private $file_manager;
	private $file_page;
	private $category_manager;
	private $category_page;
	private $settings_page;
	private $shortcode;
	private $ajax_handler;
	private $plugin_url;
	private $plugin_dir;
	private $plugin_version;

	public function __construct(
		FVM_File_Manager $file_manager,
		FVM_File_Page $file_page,
		FVM_Category_Manager $category_manager,
		FVM_Category_Page $category_page,
		FVM_Settings_Page $settings_page,
		FVM_Shortcode $shortcode,
		FVM_Ajax_Handler $ajax_handler,
		string $plugin_url,
		string $plugin_dir,
		string $plugin_version
	) {
		$this->file_manager = $file_manager;
		$this->file_page = $file_page;
		$this->category_manager = $category_manager;
		$this->category_page = $category_page;
		$this->settings_page = $settings_page;
		$this->shortcode = $shortcode;
		$this->ajax_handler = $ajax_handler;
		$this->plugin_url = $plugin_url;
		$this->plugin_dir = $plugin_dir;
		$this->plugin_version = $plugin_version;
	}

	/**
	 * Initialize the plugin: Register hooks.
	 */
	public function init() {
		add_action( 'plugins_loaded', [ $this, 'on_plugins_loaded' ] );
		add_action( 'init', [ $this, 'load_textdomain' ] );
		add_action( 'init', [ $this, 'maybe_flush_rewrite_rules' ] );
		add_action( 'wp', [ $this, 'handle_file_request' ] );
		add_action( 'wp', [ $this, 'handle_legacy_download_url' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_styles' ] );
		add_action( 'admin_notices', [ $this, 'display_upgrade_notice' ] );
		add_filter( 'admin_footer_text', [ $this, 'custom_admin_footer_text' ], 9999 );
		add_action( 'init', [ $this->shortcode, 'init' ] );
		// Add filter for admin body class
		add_filter( 'admin_body_class', [ $this, 'add_fvm_admin_body_class' ] );

		// Initialize components
		$this->file_manager->init();
		$this->file_page->init();
		$this->category_page->init();
		$this->settings_page->init();
		$this->ajax_handler->init();

		// Load admin assets (CSS/JS)
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_assets' ] );

		// Hook to potentially add admin toolbar/header (will check screen)
		add_action( 'in_admin_header', [ $this, 'include_fvm_admin_elements' ] );
	}

	/**
	 * Actions to perform once all plugins are loaded.
	 */
	public function on_plugins_loaded() {
		// Check DB version potentially here instead of a separate hook if appropriate
		$this->check_db_version();
	}

	/**
	 * Load the plugin text domain for translation.
	 */
	public function load_textdomain() {
		load_plugin_textdomain( 'file-version-manager', false, dirname( plugin_basename( $this->plugin_dir . 'file-version-manager.php' ) ) . '/languages/' );
	}

	/**
	 * Check the database version and set up upgrade notice if needed.
	 */
	private function check_db_version() {
		$installed_version = get_option( 'fvm_db_version', '0.0.0' );
		if ( version_compare( $installed_version, $this->plugin_version, '<' ) ) {
			// Add notice or flag for upgrade if needed
			// For now, just ensures the upgrade object is ready if needed by admin actions
		}
	}

	public function display_upgrade_notice() {
		if ( isset( $_GET['page'] ) && $_GET['page'] === 'fvm_settings' && isset( $_GET['upgraded'] ) ) {
			?>
						<div class="notice notice-success is-dismissible">
							<p><?php esc_html_e( 'Database upgraded successfully.', 'file-version-manager' ); ?></p>
						</div>
						<?php
		}
	}

	public function handle_legacy_download_url() {
		if ( ! isset( $_SERVER['REQUEST_URI'] ) || ! is_string( $_SERVER['REQUEST_URI'] ) ) {
			return;
		}

		$request_uri = esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) );

		if ( strpos( $request_uri, '/download/' ) === 0 ) {
			$file_name = basename( $request_uri );
			$file_name = sanitize_file_name( $file_name );
			if ( empty( $file_name ) )
				return;

			$new_url = $this->file_manager->get_file_url( $file_name );
			if ( $new_url ) {
				wp_safe_redirect( $new_url, 301 );
				exit;
			}
		}
	}

	public function maybe_flush_rewrite_rules() {
		if ( get_option( 'fvm_flush_rewrite_rules' ) ) {
			flush_rewrite_rules();
			delete_option( 'fvm_flush_rewrite_rules' );
		}
	}

	public function handle_file_request() {
		if ( ! isset( $_GET['file'] ) || ! is_string( $_GET['file'] ) ) {
			return;
		}

		$file_name = sanitize_file_name( $_GET['file'] );

		if ( empty( $file_name ) ) {
			return;
		}

		$this->file_manager->serve_file( $file_name );
	}

	public function custom_admin_footer_text( $text ) {
		$screen = get_current_screen();
		$is_fvm_page = $screen && ( strpos( $screen->id, 'fvm_files' ) !== false || $screen->id === 'files_page_fvm_settings' || strpos( $screen->id, 'fvm_categories' ) !== false );

		if ( $is_fvm_page ) {
			$upload_dir_info = wp_upload_dir();
			$target_dir = $this->file_manager->get_upload_dir_path();

			if ( is_dir( $target_dir ) ) {
				try {
					$total_size = $this->get_directory_size( $target_dir );
					$formatted_size = size_format( $total_size, 2 );
					return esc_html( "Total size of FVM directory ($target_dir): {$formatted_size}" );
				} catch (\Exception $e) {
					return esc_html( "Could not calculate directory size: " . $e->getMessage() );
				}
			} else {
				return esc_html( "FVM directory does not exist: $target_dir" );
			}
		}
		return $text;
	}

	private function get_directory_size( $path ) {
		if ( ! is_dir( $path ) )
			return 0;

		$total_size = 0;
		$iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $path, \RecursiveDirectoryIterator::SKIP_DOTS ) );

		foreach ( $iterator as $file ) {
			if ( $file->isFile() ) {
				$total_size += $file->getSize();
			}
		}
		return $total_size;
	}

	/**
	 * Enqueue admin-specific stylesheets conditionally.
	 */
	public function enqueue_admin_styles( $hook_suffix ) {
		$screen = get_current_screen();
		if ( ! $screen ) {
			return;
		}

		$plugin_pages = [ 
			'toplevel_page_fvm_files',
			'files_page_fvm_categories',
			'files_page_fvm_settings',
		];

		if ( in_array( $screen->id, $plugin_pages ) ) {
			wp_enqueue_style( 'fvm-admin-general', $this->plugin_url . 'assets/css/fvm-admin.css', [], $this->plugin_version );

			if ( $screen->id === 'files_page_fvm_categories' ) {
				wp_enqueue_style( 'fvm-admin-categories', $this->plugin_url . 'assets/css/fvm-categories.css', [ 'fvm-admin-general' ], $this->plugin_version );
			} elseif ( $screen->id === 'files_page_fvm_settings' ) {
				wp_enqueue_style( 'fvm-admin-settings', $this->plugin_url . 'assets/css/fvm-settings.css', [ 'fvm-admin-general' ], $this->plugin_version );
			}
		}
	}

	/**
	 * Includes the FVM toolbar and header partials on relevant admin pages.
	 * Also enqueues the necessary JavaScript for DOM manipulation.
	 */
	public function include_fvm_admin_elements() {
		$screen = get_current_screen();

		// Define the screen IDs for your plugin pages
		// Note: Adjust these if your page slugs change
		$plugin_screen_ids = [ 
			'toplevel_page_fvm_files',        // Main Files page
			'files_page_fvm_categories',     // Categories sub-page (assuming under Files)
			'files_page_fvm_settings',       // Settings sub-page (assuming under Files)
		];

		// Only output if we are on one of the plugin screens
		if ( $screen && in_array( $screen->id, $plugin_screen_ids ) ) {

			// Enqueue the JS for moving elements (only on our pages)
			wp_enqueue_script(
				'fvm-admin-dom',
				$this->plugin_url . 'assets/js/fvm-admin-dom.js',
				[ 'jquery' ], // Depends on jQuery for the moving logic
				$this->plugin_version,
				true // Load in footer
			);

			// Include the toolbar partial
			$toolbar_path = $this->plugin_dir . 'includes/admin/partials/partial-fvm-toolbar.php';
			if ( file_exists( $toolbar_path ) ) {
				include $toolbar_path;
			}

			// Include the header partial
			$header_path = $this->plugin_dir . 'includes/admin/partials/partial-fvm-header.php';
			if ( file_exists( $header_path ) ) {
				include $header_path;
			}
		}
	}

	/**
	 * Enqueue scripts and styles for the admin area.
	 */
	public function enqueue_admin_assets( $hook_suffix ) {
		$screen = get_current_screen();
		if ( ! $screen ) {
			return;
		}

		$plugin_pages = [ 
			'toplevel_page_fvm_files',
			'files_page_fvm_categories',
			'files_page_fvm_settings',
		];

		if ( in_array( $screen->id, $plugin_pages ) ) {
			wp_enqueue_script( 'fvm-admin-general', $this->plugin_url . 'assets/js/fvm-admin-dom.js', [], $this->plugin_version, true );
		}
	}

	/**
	 * Adds custom CSS classes to the body tag on FVM admin pages.
	 *
	 * @param string $classes Space-separated list of CSS classes.
	 * @return string Modified list of CSS classes.
	 */
	public function add_fvm_admin_body_class( $classes ) {
		$screen = get_current_screen();
		if ( ! $screen ) {
			return $classes;
		}

		// Define the screen IDs for your plugin pages
		$plugin_screen_ids = [ 
			'toplevel_page_fvm_files',        // Main Files page
			'files_page_fvm_categories',     // Categories sub-page
			'files_page_fvm_settings',       // Settings sub-page
		];

		if ( in_array( $screen->id, $plugin_screen_ids ) ) {
			$classes .= ' fvm-admin-page';
		}

		return $classes;
	}
}