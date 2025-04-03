<?php

namespace FVM\FileVersionManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Handles the display and management of admin notices for the plugin.
 */
class FVM_Admin_Notices {

	private static $transient_key = 'fvm_admin_notice';
	private static $notices = []; // For notices added programmatically during the same request

	/**
	 * Initialize hooks.
	 */
	public function init() {
		// Remove hooks - Rendering will be called manually
		// add_action( 'admin_notices', [ $this, 'display_notices' ] );
		add_action( 'admin_notices', [ $this, 'display_persistent_upgrade_notice_if_needed' ] );
	}

	/**
	 * Adds a notice to be displayed on the next admin page load via transient.
	 *
	 * @param string $message        The notice message.
	 * @param string $type           Notice type (success, error, warning, info). Default 'info'.
	 * @param bool   $is_dismissible Is the notice dismissible? Default true.
	 * @param string|null $title     Optional title for the notice.
	 */
	private static function set_transient_notice( $message, $type = 'info', $is_dismissible = true, $title = null ) {
		set_transient( self::$transient_key, [ 
			'message' => $message,
			'type' => $type,
			'is_dismissible' => $is_dismissible,
			'title' => $title,
		], 60 ); // Store for 60 seconds
	}

	/**
	 * Sets a notice in a transient and performs a safe redirect.
	 * Replaces fvm_redirect_with_message.
	 *
	 * @param string $page           The admin page slug (e.g., 'fvm_files').
	 * @param string $type           Notice type (success, error, warning, info).
	 * @param string $message        The notice message.
	 * @param bool   $is_dismissible Is the notice dismissible? Default true.
	 * @param string|null $title     Optional title for the notice.
	 */
	public static function add_notice_and_redirect( $page, $type, $message, $is_dismissible = true, $title = null ) {
		self::set_transient_notice( $message, $type, $is_dismissible, $title );
		$url = admin_url( 'admin.php?page=' . $page );
		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Add a notice programmatically for the *current* page load.
	 * Useful for notices generated after page load starts but before admin_notices fires.
	 *
	 * @param string $message        The message.
	 * @param string $type           Type (success, error, warning, info).
	 * @param bool   $is_dismissible Is it dismissible?
	 * @param string|null $title     Optional title for the notice.
	 */
	public static function add_notice( $message, $type = 'info', $is_dismissible = true, $title = null ) {
		self::$notices[] = [ 
			'message' => $message,
			'type' => $type,
			'is_dismissible' => $is_dismissible,
			'title' => $title,
		];
	}

	/**
	 * Renders FVM-specific notices (transient, added, settings API).
	 * Call this manually from page rendering functions.
	 */
	public static function render_fvm_notices() {
		// Display transient notice first (if any)
		if ( $notice = get_transient( self::$transient_key ) ) {
			$title = isset( $notice['title'] ) ? $notice['title'] : null;
			self::render_notice_html( $notice['message'], $notice['type'], $notice['is_dismissible'], $title );
			delete_transient( self::$transient_key );
		}

		// Display programmatically added notices
		foreach ( self::$notices as $notice ) {
			$title = isset( $notice['title'] ) ? $notice['title'] : null;
			self::render_notice_html( $notice['message'], $notice['type'], $notice['is_dismissible'], $title );
		}
		self::$notices = []; // Clear after displaying

		// Display Settings API notices 
		// Since standard admin_notices might be suppressed, manually render settings errors/notices here.
		settings_errors( 'fvm_settings', false, true ); // Use the correct settings group name
	}

	/**
	 * Renders the HTML for a single admin notice.
	 *
	 * @param string $message        The notice message.
	 * @param string $type           Notice type (success, error, warning, info).
	 * @param bool   $is_dismissible Should the notice be dismissible?
	 * @param string|null $title     Optional title for the notice.
	 */
	private static function render_notice_html( $message, $type = 'info', $is_dismissible = true, $title = null ) {
		$class = 'notice notice-' . esc_attr( $type );
		if ( $is_dismissible ) {
			$class .= ' is-dismissible';
		}
		?>
		<div id="message" class="<?php echo esc_attr( $class ); ?>">
			<?php if ( ! empty( $title ) ) : ?>
				<h4><?php echo esc_html( $title ); ?></h4>
			<?php endif; ?>
			<p><?php echo wp_kses_post( $message ); // Allow safe HTML in messages ?></p>
		</div>
		<?php
	}

	// --- Specific Notices (Example: Upgrade Notice Check) ---

	/**
	 * Checks and displays the persistent upgrade notice if needed.
	 * Hooked to 'admin_notices'.
	 */
	public function display_persistent_upgrade_notice_if_needed() {
		// Only run if the FVM_Upgrade class exists and user has caps
		if ( ! class_exists( 'FVM\FileVersionManager\FVM_Upgrade' ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$current_db_version = get_option( FVM_Upgrade::DB_VERSION_OPTION, '1.0' );

		// If the current DB version is lower than the code expects
		if ( version_compare( $current_db_version, FVM_Upgrade::CURRENT_DB_VERSION, '<' ) ) {
			$settings_url = admin_url( 'admin.php?page=fvm_settings' );
			$message = sprintf(
				/* translators: %s: Link to settings page */
				wp_kses_post( __( 'A database upgrade is required in order to keep things running smoothly. <a href="%s">Click here</a> to start.', 'file-version-manager' ) ),
				esc_url( $settings_url )
			);
			// Display using the standard renderer, making it non-dismissible
			$title = __( 'Database Update Required', 'file-version-manager' ); // Example title
			self::render_notice_html( $message, 'warning', false, $title );
		}
	}
}