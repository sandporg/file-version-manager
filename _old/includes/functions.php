<?php

/**
 * Redirect with message
 * 
 * @param string $status
 * @param string $message
 * @return void
 */
function fvm_redirect_with_message( $page, $status, $message ) {

	$page = sanitize_text_field( $page );
	$status = sanitize_text_field( $status );
	$message = sanitize_text_field( $message );

	$redirect_url = add_query_arg(
		[ 
			'page' => $page,
			'update' => $status,
			'message' => urlencode( $message ),
		],
		admin_url( 'admin.php' )
	);

	if ( ! headers_sent() ) {
		wp_redirect( $redirect_url );
		exit;
	} else {
		echo '<script type="text/javascript">';
		echo 'window.location.href="' . esc_js( $redirect_url ) . '";';
		echo '</script>';
		echo '<noscript>';
		echo '<meta http-equiv="refresh" content="0;url=' . esc_url( $redirect_url ) . '" />';
		echo '</noscript>';
		exit;
	}
}

// function is_nginx() {
// 	// Check SERVER_SOFTWARE first
// 	if ( isset( $_SERVER['SERVER_SOFTWARE'] ) && stripos( $_SERVER['SERVER_SOFTWARE'], 'nginx' ) !== false ) {
// 		return true;
// 	}

// 	// Check for Nginx-specific headers
// 	$nginx_headers = array( 'X-Nginx-Cache', 'X-NginX-Proxy', 'X-Fastcgi-Cache' );
// 	foreach ( $nginx_headers as $header ) {
// 		if ( isset( $_SERVER[ 'HTTP_' . str_replace( '-', '_', strtoupper( $header ) ) ] ) ) {
// 			return true;
// 		}
// 	}

// 	// Check for WP Engine's Nginx setup
// 	if ( isset( $_SERVER['HTTP_X_WPE_REQUEST_ID'] ) || isset( $_SERVER['HTTP_X_WPE_CACHE_ZONE'] ) ) {
// 		return true;
// 	}

// 	// If all checks fail, assume it's not Nginx
// 	return false;
// }
