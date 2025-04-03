<?php
/**
 * Partial for the FVM Admin Toolbar
 *
 * @package FVM\FileVersionManager\Admin\Partials
 */

namespace FVM\FileVersionManager;

#todo: - Update toolbar to accept custom elements from other admin pages (ex. search bar from file page)

// Ensure this file is called within WordPress context
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$current_page = isset( $_GET['page'] ) ? sanitize_key( $_GET['page'] ) : '';

?>
<div class="fvm-admin-toolbar">
	<div class="fvm-admin-toolbar-left">
		<div class="fvm-toolbar-logo">
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=fvm_files' ) ); ?>" style="display: flex;">
				<img src="<?php echo FVM_PLUGIN_URL . 'assets/images/fvm-logo.svg'; ?>" alt="FVM Logo"
					class="fvm-toolbar-logo-image" width="200" height="auto">
			</a>
		</div>
		<nav class="fvm-toolbar-nav">
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=fvm_files' ) ); ?>"
				class="<?php echo $current_page === 'fvm_files' ? 'current' : ''; ?> fvm-toolbar-nav-link">
				<svg xmlns="http://www.w3.org/2000/svg" id="Layer_1" version="1.1" width="16" height="16"
					viewBox="0 0 612 612">
					<path d="M343.7 66.8v156.6c0 6.4 5.2 11.6 11.6 11.6h156.6L343.6 66.7Z" fill="currentColor"
						opacity=".6" />
					<path
						d="M343.7 223.4V66.8H160c-6.4 0-11.6 5.2-11.6 11.6V542c0 6.4 5.2 11.6 11.6 11.6h340.3c6.4 0 11.6-5.2 11.6-11.6V235.1H355.3c-6.4 0-11.6-5.2-11.6-11.6Z"
						fill="currentColor" />
				</svg>
				<?php esc_html_e( 'Files', 'file-version-manager' ); ?>
			</a>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=fvm_categories' ) ); ?>"
				class="<?php echo $current_page === 'fvm_categories' ? 'current' : ''; ?> fvm-toolbar-nav-link">
				<svg xmlns="http://www.w3.org/2000/svg" id="Layer_1" version="1.1" width="16" height="16"
					viewBox="0 0 100 100">
					<path
						d="M172.27 35.44V9.16h-30.83c-1.07 0-1.95.87-1.95 1.95v77.8c0 1.07.87 1.95 1.95 1.95h57.11c1.07 0 1.95-.87 1.95-1.95v-51.5h-26.28c-1.07 0-1.95-.87-1.95-1.95v-.02ZM10.127 60.032l29.696-6.643a2.612 2.612 0 0 1 3.117 1.977l6.641 29.687a2.612 2.612 0 0 1-1.977 3.117l-29.686 6.64a2.612 2.612 0 0 1-3.117-1.977L8.158 63.137a2.608 2.608 0 0 1 1.97-3.105Z"
						fill="currentColor" />
					<circle cx="73.01" cy="60.66" r="18.89" fill="currentColor" />
					<path
						d="m36.9 42.49-11.15 5.37c-1.86.9-3.98-.64-3.7-2.69l1.66-12.27-8.55-8.95c-1.43-1.5-.62-3.98 1.41-4.35l12.18-2.21 5.87-10.9c.98-1.82 3.59-1.82 4.58 0l5.87 10.9 12.18 2.21c2.04.37 2.84 2.86 1.41 4.35l-8.55 8.95 1.66 12.27c.28 2.05-1.84 3.59-3.7 2.69l-11.15-5.37Z"
						fill="currentColor" />
				</svg>
				<?php esc_html_e( 'Categories', 'file-version-manager' ); ?>
			</a>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=fvm_settings' ) ); ?>"
				class="<?php echo $current_page === 'fvm_settings' ? 'current' : ''; ?> fvm-toolbar-nav-link">
				<svg xmlns="http://www.w3.org/2000/svg" id="Layer_1" version="1.1" width="16" height="16"
					viewBox="0 0 100 100">
					<circle cx="-166.99" cy="60.66" r="18.89" fill="currentColor" />
					<path
						d="m-203.1 42.49-11.15 5.37c-1.86.9-3.98-.64-3.7-2.69l1.66-12.27-8.55-8.95c-1.43-1.5-.62-3.98 1.41-4.35l12.18-2.21 5.87-10.9c.98-1.82 3.59-1.82 4.58 0l5.87 10.9 12.18 2.21c2.04.37 2.84 2.86 1.41 4.35l-8.55 8.95 1.66 12.27c.28 2.05-1.84 3.59-3.7 2.69l-11.15-5.37ZM89.9 61.22l-7.75-5.48a2.24 2.24 0 0 1-.95-2.1c.15-1.2.24-2.41.24-3.64s-.09-2.45-.24-3.64c-.1-.82.27-1.63.95-2.1l7.75-5.48c.8-.56 1.16-1.58.85-2.51-.62-1.85-1.36-3.64-2.22-5.36-.43-.88-1.41-1.34-2.37-1.18l-9.4 1.62c-.81.14-1.62-.2-2.14-.84a31.39 31.39 0 0 0-5.12-5.12c-.65-.51-.98-1.32-.84-2.14l1.62-9.4c.17-.96-.3-1.94-1.18-2.37-1.73-.85-3.52-1.59-5.36-2.22-.93-.31-1.95.05-2.51.85l-5.48 7.75a2.24 2.24 0 0 1-2.1.95c-1.2-.15-2.41-.24-3.64-.24s-2.45.09-3.64.24c-.82.1-1.63-.27-2.1-.95l-5.48-7.75c-.56-.8-1.58-1.16-2.51-.85-1.85.62-3.64 1.36-5.36 2.22-.88.43-1.34 1.41-1.18 2.37l1.62 9.4c.14.81-.2 1.62-.84 2.14a31.39 31.39 0 0 0-5.12 5.12c-.51.65-1.32.98-2.14.84l-9.4-1.62c-.96-.17-1.94.3-2.37 1.18-.85 1.73-1.59 3.52-2.22 5.36-.31.93.05 1.95.85 2.51l7.75 5.48c.68.48 1.05 1.28.95 2.1-.15 1.2-.24 2.41-.24 3.64s.09 2.45.24 3.64c.1.82-.27 1.63-.95 2.1l-7.75 5.48c-.8.56-1.16 1.58-.85 2.51.62 1.85 1.36 3.64 2.22 5.36.43.88 1.41 1.34 2.37 1.18l9.4-1.62c.81-.14 1.62.2 2.14.84 1.5 1.9 3.22 3.61 5.12 5.12.65.51.98 1.32.84 2.14l-1.62 9.4c-.17.96.3 1.94 1.18 2.37 1.73.85 3.52 1.59 5.36 2.22.93.31 1.95-.05 2.51-.85l5.48-7.75a2.24 2.24 0 0 1 2.1-.95c1.2.15 2.41.24 3.64.24s2.45-.09 3.64-.24c.82-.1 1.63.27 2.1.95l5.48 7.75c.56.8 1.58 1.16 2.51.85 1.85-.62 3.64-1.36 5.36-2.22.88-.43 1.34-1.41 1.18-2.37l-1.62-9.4c-.14-.81.2-1.62.84-2.14 1.9-1.5 3.61-3.22 5.12-5.12.51-.65 1.32-.98 2.14-.84l9.4 1.62c.96.17 1.94-.3 2.37-1.18.85-1.73 1.59-3.52 2.22-5.36.31-.93-.05-1.95-.85-2.51ZM50 69.96c-11.02 0-19.96-8.94-19.96-19.96S38.98 30.04 50 30.04 69.96 38.98 69.96 50 61.02 69.96 50 69.96Z"
						fill="currentColor" />
				</svg>
				<?php esc_html_e( 'Settings', 'file-version-manager' ); ?>
			</a>
		</nav>
	</div>
	<div class="fvm-admin-toolbar-right">
		<div class="fvm-toolbar-version">
			<?php echo 'v' . esc_html( FVM_VERSION ); ?>
		</div>
		<a href="https://github.com/sandporg/file-version-manager" class="fvm-toolbar-social" target="_blank"
			title="<?php esc_attr_e( 'View on GitHub', 'file-version-manager' ); ?>">
			<svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
				<path
					d="M12 2C10.6868 2 9.38642 2.25866 8.17317 2.7612C6.95991 3.26375 5.85752 4.00035 4.92893 4.92893C3.05357 6.8043 2 9.34784 2 12C2 16.42 4.87 20.17 8.84 21.5C9.34 21.58 9.5 21.27 9.5 21V19.31C6.73 19.91 6.14 17.97 6.14 17.97C5.68 16.81 5.03 16.5 5.03 16.5C4.12 15.88 5.1 15.9 5.1 15.9C6.1 15.97 6.63 16.93 6.63 16.93C7.5 18.45 8.97 18 9.54 17.76C9.63 17.11 9.89 16.67 10.17 16.42C7.95 16.17 5.62 15.31 5.62 11.5C5.62 10.39 6 9.5 6.65 8.79C6.55 8.54 6.2 7.5 6.75 6.15C6.75 6.15 7.59 5.88 9.5 7.17C10.29 6.95 11.15 6.84 12 6.84C12.85 6.84 13.71 6.95 14.5 7.17C16.41 5.88 17.25 6.15 17.25 6.15C17.8 7.5 17.45 8.54 17.35 8.79C18 9.5 18.38 10.39 18.38 11.5C18.38 15.32 16.04 16.16 13.81 16.41C14.17 16.72 14.5 17.33 14.5 18.26V21C14.5 21.27 14.66 21.59 15.17 21.5C19.14 20.16 22 16.42 22 12C22 10.6868 21.7413 9.38642 21.2388 8.17317C20.7362 6.95991 19.9997 5.85752 19.0711 4.92893C18.1425 4.00035 17.0401 3.26375 15.8268 2.7612C14.6136 2.25866 13.3132 2 12 2Z"
					fill="currentColor" />
			</svg>
		</a>
	</div>
</div>