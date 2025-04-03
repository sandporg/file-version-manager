<?php
namespace FVM\FileVersionManager;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once( ABSPATH . 'wp-admin/includes/class-wp-list-table.php' );
}

/**
 * Class FVM_File_List_Table
 * Handles the display of files in a WP_List_Table format.
 */
class FVM_File_List_Table extends \WP_List_Table {
	/**
	 * File manager instance.
	 * @var FVM_File_Manager
	 */
	private $file_manager;

	/**
	 * Screen option ID for items per page.
	 * @var string
	 */
	private $screen_option_id;

	/**
	 * Constructor.
	 *
	 * @param FVM_File_Manager $file_manager Instance of the file manager.
	 * @param string $screen_option_id The screen option ID for items per page.
	 */
	public function __construct( FVM_File_Manager $file_manager, $screen_option_id ) {
		parent::__construct( [ 
			'singular' => 'file',
			'plural' => 'files',
			'ajax' => false,
		] );
		$this->file_manager = $file_manager;
		$this->screen_option_id = $screen_option_id;
	}

	/**
	 * Prepare the items for the table to process.
	 */
	public function prepare_items() {
		$search = isset( $_REQUEST['s'] ) ? sanitize_text_field( $_REQUEST['s'] ) : '';
		$orderby = isset( $_REQUEST['orderby'] ) ? sanitize_key( $_REQUEST['orderby'] ) : 'date_modified';
		$order = isset( $_REQUEST['order'] ) ? sanitize_key( $_REQUEST['order'] ) : 'desc';
		$current_page = $this->get_pagenum();
		$per_page = $this->get_items_per_page( $this->screen_option_id, 50 );

		$columns = $this->get_columns();
		$sortable = $this->get_sortable_columns();
		$hidden = get_hidden_columns( get_current_screen() );
		$this->_column_headers = array( $columns, $hidden, $sortable );

		$query_args = [ 'search' => $search ];
		$total_items = $this->file_manager->get_total_files( $query_args );

		$this->set_pagination_args( [ 
			'total_items' => $total_items,
			'per_page' => $per_page,
			'total_pages' => ceil( $total_items / $per_page ),
		] );

		$query_args = array_merge( $query_args, [ 
			'posts_per_page' => $per_page,
			'paged' => $current_page,
			'orderby' => $orderby,
			'order' => $order,
		] );
		$this->items = $this->file_manager->get_files( $query_args );
	}

	/**
	 * Define bulk actions.
	 *
	 * @return array An associative array of bulk actions.
	 */
	public function get_bulk_actions() {
		return [ 
			'delete' => 'Delete',
		];
	}

	/**
	 * Display the search box.
	 *
	 * @param string $text The 'submit' button label.
	 * @param string $input_id ID attribute value for the search input field.
	 */
	public function search_box( $text, $input_id ) {
		// if ( empty( $_REQUEST['s'] ) && ! $this->has_items() ) {
		// }
		// The above condition was empty, removed.

		$input_id = $input_id . '-search-input';
		$search_value = isset( $_REQUEST['s'] ) ? esc_attr( $_REQUEST['s'] ) : '';

		?>
		<p class="search-box">
			<label class="screen-reader-text"
				for="<?php echo esc_attr( $input_id ); ?>"><?php echo esc_html( $text ); ?>:</label>
			<input type="search" id="<?php echo esc_attr( $input_id ); ?>" name="s" value="<?php echo $search_value; ?>" />
			<?php submit_button( $text, '', '', false, array( 'id' => 'search-submit' ) );
			?>
		</p>
		<?php
	}

	/**
	 * Get the list of columns.
	 *
	 * @return array An associative array of columns.
	 */
	public function get_columns() {
		return [ 
			'cb' => '<input type="checkbox" />',
			'file_name' => 'File Name',
			'file_type' => 'Type',
			'file_size' => 'Size',
			'file_version' => 'Version',
			'shortcode' => 'Shortcode',
			'date_modified' => 'Last Modified',
		];
	}

	/**
	 * Get the list of sortable columns.
	 *
	 * @return array An associative array of sortable columns.
	 */
	public function get_sortable_columns() {
		return [ 
			'file_name' => [ 'file_name', true ],
			'file_type' => [ 'file_type', true ],
			'file_size' => [ 'file_size', true ],
			'file_version' => [ 'file_version', true ],
			'date_modified' => [ 'date_modified', true ],
		];
	}

	/**
	 * Default column rendering.
	 *
	 * @param object $item The current item data.
	 * @param string $column_name The name of the current column.
	 * @return string The column content.
	 */
	public function column_default( $item, $column_name ) {
		switch ( $column_name ) {
			case 'file_type':
				return isset( $item->file_type ) ? esc_html( strtoupper( $item->file_type ) ) : 'N/A';
			case 'date_modified':
				$modified_date = ! empty( $item->date_modified ) ? $item->date_modified : ( ! empty( $item->date_uploaded ) ? $item->date_uploaded : null );
				return $modified_date ? esc_html( date( 'Y/m/d g:i a', strtotime( $modified_date ) ) ) : 'N/A';
			case 'file_size':
				return isset( $item->file_size ) ? esc_html( $this->format_file_size( $item->file_size ) ) : 'N/A';
			case 'file_version':
				return isset( $item->file_version ) ? esc_html( $item->file_version ) : '1.0';
			case 'shortcode':
				if ( ! isset( $item->id ) )
					return '';
				$shortcode = '[fvm id="' . esc_attr( $item->id ) . '"]';
				return sprintf(
					'<div class="shortcode-column-container">
						<code>%s</code>
						<button type="button" class="button button-small copy-shortcode" data-shortcode="%s">Copy</button>
					</div>',
					esc_html( $shortcode ),
					esc_attr( $shortcode )
				);
			default:
				return '';
		}
	}

	/**
	 * Render the checkbox column.
	 *
	 * @param object $item The current item data.
	 * @return string The checkbox HTML.
	 */
	public function column_cb( $item ) {
		if ( ! isset( $item->id ) )
			return '';
		return sprintf(
			'<input type="checkbox" name="file[]" value="%d" />',
			$item->id
		);
	}

	/**
	 * Render the file name column with actions.
	 *
	 * @param object $item The current item data.
	 * @return string The file name column content.
	 */
	public function column_file_name( $item ) {
		if ( ! isset( $item->id ) || ! isset( $item->file_name ) )
			return 'Invalid File Data';

		$file_id = (int) $item->id;
		$edit_nonce = wp_create_nonce( 'edit_file_' . $file_id );
		$delete_nonce = wp_create_nonce( 'delete_file_' . $file_id );

		$view_url = $this->file_manager->get_file_url( $item->file_name );

		$actions = [ 
			'id' => sprintf( '<span>ID: %d</span>', $file_id ),
			'edit' => sprintf( '<a href="#" class="edit-file" data-file-id="%d" data-nonce="%s">Edit</a>', $file_id, esc_attr( $edit_nonce ) ),
			'view' => sprintf( '<a href="%s" target="_blank">View</a>', esc_url( $view_url ) ),
			'download' => sprintf( '<a href="%s" download>Download</a>', esc_url( $view_url ) ),
			'delete' => sprintf( '<a href="%s" onclick="return confirm(\'Are you sure you want to delete file %s?\')">Delete</a>',
				esc_url( add_query_arg( [ 'action' => 'delete', 'file_id' => $file_id, '_wpnonce' => $delete_nonce ], admin_url( 'admin.php?page=fvm_files' ) ) ),
				esc_attr( $item->file_name )
			),
		];

		$file_icon = $this->get_file_icon_class( $item->file_type ?? '' );
		$display_name = ! empty( $item->file_display_name ) ? $item->file_display_name : $item->file_name;
		$offline_indicator = isset( $item->file_offline ) && $item->file_offline ? '<div class="fvm-file-offline" title="File is Offline"></div>' : '';

		$file_name_html = sprintf( '<div style="display: flex;"><div class="dashicons %s" style="margin-right: 6px;"></div><div>%s</div>%s</div>', esc_attr( $file_icon ), esc_html( $display_name ), $offline_indicator );

		return sprintf(
			'<div class="file-row" id="file-row-%d">
				<div class="file-info">%s %s</div>
			</div>',
			$item->id,
			$file_name_html,
			$this->row_actions( $actions )
		);
	}

	/**
	 * Format file size into human-readable format.
	 *
	 * @param int $size_in_bytes File size in bytes.
	 * @return string Formatted file size.
	 */
	private function format_file_size( $size_in_bytes ) {
		$size_in_bytes = intval( $size_in_bytes );
		if ( $size_in_bytes === 0 )
			return '0 B';

		$units = array( 'B', 'KB', 'MB', 'GB', 'TB' );
		$size = $size_in_bytes;
		$unit_index = 0;

		while ( $size >= 1024 && $unit_index < count( $units ) - 1 ) {
			$size /= 1024;
			$unit_index++;
		}

		return round( $size, 2 ) . ' ' . $units[ $unit_index ];
	}

	/**
	 * Get the appropriate Dashicon class for a file type.
	 *
	 * @param string $file_type The file MIME type or extension.
	 * @return string The Dashicon class name.
	 */
	private function get_file_icon_class( $file_type ) {
		$file_type = strtolower( $file_type ?? '' );
		$icon_map = [ 
			'jpg' => 'dashicons-format-image',
			'jpeg' => 'dashicons-format-image',
			'png' => 'dashicons-format-image',
			'tiff' => 'dashicons-format-image',
			'avif' => 'dashicons-format-image',
			'webp' => 'dashicons-format-image',
			'svg' => 'dashicons-format-image',
			'gif' => 'dashicons-format-image',
			'mp3' => 'dashicons-format-audio',
			'mp4' => 'dashicons-format-video',
			'pdf' => 'dashicons-pdf',
			'text' => 'dashicons-text',
			'doc' => 'dashicons-media-document',
			'docx' => 'dashicons-media-document',
			'xls' => 'dashicons-media-spreadsheet',
			'xlsm' => 'dashicons-media-spreadsheet',
			'xlsx' => 'dashicons-media-spreadsheet',
			'csv' => 'dashicons-media-spreadsheet',
			'zip' => 'dashicons-media-archive',
			'html' => 'dashicons-media-code',
			'css' => 'dashicons-media-code',
			'js' => 'dashicons-media-code',
			'php' => 'dashicons-media-code',
			'sql' => 'dashicons-media-code',
			'json' => 'dashicons-media-code',
			'xml' => 'dashicons-media-code',
			'yaml' => 'dashicons-media-code',
			'yml' => 'dashicons-media-code',
			'txt' => 'dashicons-media-text',
			'md' => 'dashicons-media-text',
		];

		$type_parts = explode( '/', $file_type );
		$general_type = $type_parts[0];

		if ( isset( $icon_map[ $file_type ] ) ) {
			return $icon_map[ $file_type ];
		} elseif ( isset( $icon_map[ $general_type ] ) ) {
			return $icon_map[ $general_type ];
		} else {
			return 'dashicons-media-default';
		}
	}
}