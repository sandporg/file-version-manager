<?php

namespace FVM\FileVersionManager;

class FVM_Plugin {
	private $file_manager;
	private $file_page;
	private $category_manager;
	private $category_page;
	private $settings_page;
	private $shortcode;
	private $update_ids;
	private $database_upgrade;

	public function __construct(
		FVM_File_Manager $file_manager,
		FVM_File_Page $file_page,
		FVM_Category_Manager $category_manager,
		FVM_Category_Page $category_page,
		FVM_Settings_Page $settings_page,
		FVM_Shortcode $shortcode,
		FVM_Database_Upgrade $database_upgrade
	) {
		$this->file_manager = $file_manager;
		$this->file_page = $file_page;
		$this->category_manager = $category_manager;
		$this->category_page = $category_page;
		$this->settings_page = $settings_page;
		$this->shortcode = $shortcode;
		$this->database_upgrade = $database_upgrade;
	}

	public function init() {
		add_action( 'init', [ $this, 'maybe_flush_rewrite_rules' ] );
		add_action( 'plugins_loaded', [ $this, 'setup' ] );
		add_action( 'wp', [ $this, 'handle_file_request' ] );
		add_action( 'wp', [ $this, 'handle_legacy_download_url' ] );
		// add_action( 'admin_notices', [ $this, 'display_server_notice' ] );
		add_action( 'admin_notices', [ $this, 'display_upgrade_notice' ] );
		add_action( 'admin_post_fvm_upgrade_database', [ $this, 'handle_database_upgrade' ] );
	}

	public function setup() {
		$this->file_manager->init();
		$this->file_page->init();
		$this->category_page->init();
		$this->settings_page->init();
		$this->shortcode->init();

		add_filter( 'admin_footer_text', [ $this, 'custom_admin_footer_text' ], 9999 );
	}

	// public function display_server_notice() {
	// 	if ( is_nginx() && ! get_option( 'fvm_nginx_rewrite_rules' ) ) {
	// 		$class = 'notice notice-error';
	// 		$settings_url = admin_url( 'admin.php?page=fvm_settings#nginx' );
	// 		$message = sprintf(
	// 			/* translators: %s: URL to plugin settings */
	// 			__( 'Your website is running on an Nginx server. Additional setup is required for File Version Manager to work correctly. Please check the <a href="%s">plugin settings</a> for Nginx configuration instructions.', 'file-version-manager' ),
	// 			esc_url( $settings_url )
	// 		);
	// 		printf( '<div class="%1$s"><p>%2$s</p></div>', esc_attr( $class ), wp_kses_post( $message ) );
	// 	}
	// }

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
		$request_uri = $_SERVER['REQUEST_URI'];

		if ( strpos( $request_uri, '/download/' ) === 0 ) {
			$file_name = basename( $request_uri );
			$new_url = add_query_arg( 'file', $file_name, home_url() );
			wp_redirect( $new_url, 301 );
			exit;
		}
	}

	public function maybe_flush_rewrite_rules() {
		if ( get_option( 'fvm_flush_rewrite_rules' ) ) {
			flush_rewrite_rules();
			delete_option( 'fvm_flush_rewrite_rules' );
		}
	}

	public function handle_database_upgrade() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Unauthorized' );
		}

		check_admin_referer( 'fvm_upgrade_database', 'fvm_upgrade_database_nonce' );

		$this->database_upgrade->upgrade_database();
		update_option( 'fvm_db_version', $this->get_plugin_version() );

		wp_safe_redirect( add_query_arg( [ 'page' => 'fvm_settings', 'upgraded' => '1' ], admin_url( 'admin.php' ) ) );
		exit;
	}

	private function get_plugin_version() {
		if ( ! function_exists( 'get_plugin_data' ) ) {
			require_once( ABSPATH . 'wp-admin/includes/plugin.php' );
		}
		$plugin_data = get_plugin_data( plugin_dir_path( __DIR__ ) . 'file-version-manager.php' );
		return $plugin_data['Version'];
	}

	public function handle_file_request() {
		if ( ! isset( $_GET['file'] ) ) {
			return;
		}

		$file_name = sanitize_file_name( $_GET['file'] );

		if ( ! $file_name ) {
			return;
		}

		global $wpdb;
		$table_name = $wpdb->prefix . FILE_TABLE_NAME;
		$file = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table_name WHERE file_name = %s", $file_name ) );

		if ( ! $file ) {
			return;
		}

		$file_path = $file->file_path;
		$absolute_path = ABSPATH . $file_path;

		if ( ! file_exists( $absolute_path ) ) {
			return;
		}

		$mime_type = $this->get_mime_type( $file->file_type );

		$inline_types = array( 'pdf', 'txt', 'html', 'xml', 'css', 'js', 'json', 'jpg', 'jpeg', 'png', 'gif', 'svg', 'webp', 'bmp', 'tiff', 'mp3', 'ogg', 'wav', 'mp4', 'webm' );
		$disposition = in_array( $file->file_type, $inline_types ) ? 'inline' : 'attachment';

		header( "Content-Type: $mime_type" );
		header( "Content-Disposition: $disposition; filename=\"$file_name\"" );
		header( "Content-Length: " . filesize( $absolute_path ) );

		nocache_headers();

		readfile( $absolute_path );
		exit;
	}

	private function get_mime_type( $file_type ) {
		$mime_types = array(
			'pdf' => 'application/pdf',
			'txt' => 'text/plain',
			'html' => 'text/html',
			'xml' => 'text/xml',
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
			'tiff' => 'image/tiff',
			'mp3' => 'audio/mpeg',
			'ogg' => 'audio/ogg',
			'wav' => 'audio/wav',
			'mp4' => 'video/mp4',
			'webm' => 'video/webm',
		);

		return isset( $mime_types[ $file_type ] ) ? $mime_types[ $file_type ] : 'application/octet-stream';
	}

	public function custom_admin_footer_text( $text ) {
		$screen = get_current_screen();
		if ( $screen && ( strpos( $screen->id, 'fvm_files' ) !== false || $screen->id === 'files_page_fvm_settings' ) ) {
			$upload_dir = wp_upload_dir();
			$custom_folder = get_option( 'fvm_custom_directory' );

			if ( ! empty( $custom_folder ) ) {
				$custom_dir = trailingslashit( $upload_dir['basedir'] ) . trim( $custom_folder, '/' );
			} else {
				$custom_dir = $upload_dir['basedir'] . '/filebase';
			}

			$total_size = $this->get_directory_size( $custom_dir );
			$formatted_size = size_format( $total_size, 2 );
			return "Total size of current directory: {$formatted_size}";
		} elseif ( $screen && strpos( $screen->id, 'files_page_fvm_update_ids' ) !== false ) {
			$custom_folder = get_option( 'fvm_custom_directory' );
			$upload_dir = wp_upload_dir();
			$current_dir = ! empty( $custom_folder )
				? trailingslashit( $upload_dir['basedir'] ) . trim( $custom_folder, '/' )
				: trailingslashit( $upload_dir['basedir'] ) . 'filebase';
			return "Modifying files in this directory: " . $current_dir;
		}

		return $text;
	}

	private function get_directory_size( $path ) {
		$total_size = 0;
		$files = scandir( $path );
		foreach ( $files as $file ) {
			if ( $file !== '.' && $file !== '..' ) {
				$file_path = $path . '/' . $file;
				if ( is_file( $file_path ) ) {
					$total_size += filesize( $file_path );
				} elseif ( is_dir( $file_path ) ) {
					$total_size += $this->get_directory_size( $file_path );
				}
			}
		}
		return $total_size;
	}
}