<?php
/**
 * Partial for the FVM Admin Header
 *
 * @package FVM\FileVersionManager\Admin\Partials
 */

namespace FVM\FileVersionManager;

// Ensure this file is called within WordPress context
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Determine the current page title based on the 'page' query parameter
$page_title = '';
$current_page_slug = isset( $_GET['page'] ) ? sanitize_key( $_GET['page'] ) : '';

switch ( $current_page_slug ) {
	case 'fvm_files':
		$page_title = esc_html__( 'All Files', 'file-version-manager' );
		break;
	case 'fvm_categories':
		$page_title = esc_html__( 'Categories', 'file-version-manager' );
		break;
	case 'fvm_settings':
		$page_title = esc_html__( 'Settings', 'file-version-manager' );
		break;
	// Add more cases for other plugin pages if needed
	default:
		$page_title = esc_html__( 'File Version Manager', 'file-version-manager' );
}

?>
<div class="fvm-admin-header">
	<h1><?php echo $page_title; ?></h1>
</div>