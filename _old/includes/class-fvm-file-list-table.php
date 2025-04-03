<?php
namespace FVM\FileVersionManager;

#todo: add a category filter to the file list table

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once( ABSPATH . 'wp-admin/includes/class-wp-list-table.php' );
}

class FVM_File_List_Table extends \WP_List_Table {
	private $file_manager;
	private $wpdb;
	private $file_table_name;
	private $cat_table_name;
	private $rel_table_name;

	public function __construct( $file_manager ) {
		parent::__construct( [ 
			'singular' => 'file',
			'plural' => 'files',
			'ajax' => false,
		] );
		global $wpdb;
		$this->wpdb = $wpdb;
		$this->file_manager = $file_manager;
		$this->file_table_name = esc_sql( $wpdb->prefix . FILE_TABLE_NAME );
		$this->cat_table_name = esc_sql( $wpdb->prefix . CAT_TABLE_NAME );
		$this->rel_table_name = esc_sql( $wpdb->prefix . REL_TABLE_NAME );
	}

	private function get_table_name( $table ) {
		global $wpdb;
		if ( $table === 'category' ) {
			return esc_sql( $wpdb->prefix . CAT_TABLE_NAME );
		} elseif ( $table === 'files' ) {
			return esc_sql( $wpdb->prefix . FILE_TABLE_NAME );
		}
		return esc_sql( $wpdb->prefix . CAT_TABLE_NAME );
	}

	public function prepare_items() {
		if ( ! $this->ensure_table_exists() ) {
			return;
		}

		$search = isset( $_REQUEST['s'] ) ? sanitize_text_field( $_REQUEST['s'] ) : '';

		$columns = $this->get_columns();
		$sortable = $this->get_sortable_columns();
		$hidden = array();

		$this->_column_headers = array( $columns, $hidden, $sortable );

		$per_page = $this->get_items_per_page( 'fvm_files_per_page', 50 );
		$current_page = $this->get_pagenum();
		$total_items = $this->get_total_items( $search );

		$this->set_pagination_args( [ 
			'total_items' => $total_items,
			'per_page' => $per_page,
			'total_pages' => ceil( $total_items / $per_page ),
		] );

		$orderby = isset( $_REQUEST['orderby'] ) ? sanitize_key( $_REQUEST['orderby'] ) : 'date_modified';
		$order = isset( $_REQUEST['order'] ) ? sanitize_key( $_REQUEST['order'] ) : 'desc';

		$this->items = $this->get_files( $per_page, $current_page, $orderby, $order, $search );
	}

	public function get_files( $per_page, $current_page, $orderby = 'date_modified', $order = 'desc', $search = '' ) {
		$offset = ( $current_page - 1 ) * $per_page;

		// Validate $orderby to prevent SQL injection
		$allowed_columns = array_keys( $this->get_sortable_columns() );
		if ( ! in_array( $orderby, $allowed_columns ) ) {
			$orderby = 'date_modified';
		}

		// Validate $order
		$order = strtoupper( $order );
		if ( ! in_array( $order, [ 'ASC', 'DESC' ] ) ) {
			$order = 'DESC';
		}

		$search_query = '';
		if ( ! empty( $search ) ) {
			$search_query = $this->wpdb->prepare(
				"WHERE file_name LIKE %s OR file_display_name LIKE %s OR CAST(f.id AS CHAR) = %s",
				'%' . $this->wpdb->esc_like( $search ) . '%',
				'%' . $this->wpdb->esc_like( $search ) . '%',
				$search
			);
		}

		$query = "SELECT f.*, GROUP_CONCAT(c.cat_name SEPARATOR ', ') as categories
                  FROM {$this->file_table_name} f
                  LEFT JOIN {$this->rel_table_name} r ON f.id = r.file_id
                  LEFT JOIN {$this->cat_table_name} c ON r.category_id = c.id
                  $search_query
                  GROUP BY f.id
                  ORDER BY $orderby $order
                  LIMIT %d OFFSET %d";
		$query = $this->wpdb->prepare( $query, $per_page, $offset );

		$results = $this->wpdb->get_results( $query, ARRAY_A );

		return $results;
	}

	public function get_bulk_actions() {
		return [ 
			'delete' => 'Delete',
		];
	}

	public function get_total_items( $search = '' ) {
		$search_query = '';
		if ( ! empty( $search ) ) {
			$search_query = $this->wpdb->prepare(
				"WHERE file_name LIKE %s OR file_display_name LIKE %s OR CAST(f.id AS CHAR) = %s",
				'%' . $this->wpdb->esc_like( $search ) . '%',
				'%' . $this->wpdb->esc_like( $search ) . '%',
				$search
			);
		}

		return $this->wpdb->get_var( "SELECT COUNT(DISTINCT f.id) FROM {$this->file_table_name} f $search_query" );
	}

	public function search_box( $text, $input_id ) {
		if ( empty( $_REQUEST['s'] ) && ! $this->has_items() ) {
			return;
		}

		$input_id = $input_id . '-search-input';

		?>
		<p class="search-box">
			<label class="screen-reader-text"
				for="<?php echo esc_attr( $input_id ); ?>"><?php echo esc_html( $text ); ?>:</label>
			<input type="search" id="<?php echo esc_attr( $input_id ); ?>" name="s"
				value="<?php echo esc_attr( get_query_var( 's' ) ); ?>" />
			<?php submit_button( $text, 'button', false, false, array( 'id' => 'search-submit' ) ); ?>
		</p>
		<?php
	}

	private function ensure_table_exists() {
		global $wpdb;
		$file_table_name = $this->get_table_name( 'files' );
		if ( $wpdb->get_var( "SHOW TABLES LIKE '$file_table_name'" ) != $file_table_name ) {
			error_log( "Table $file_table_name does not exist." );
			return false;
		}
		return true;
	}

	public function get_columns() {
		return [ 
			'cb' => '<input type="checkbox" />',
			'file_name' => 'File Name',
			'file_type' => 'File Type',
			'file_size' => 'Size',
			'shortcode' => 'Shortcode',
			'date_modified' => 'Date Modified',
		];
	}

	public function get_sortable_columns() {
		return [ 
			'file_name' => [ 'file_name', true ],
			'file_type' => [ 'file_type', true ],
			'file_size' => [ 'file_size', true ],
			'date_modified' => [ 'date_modified', true ],
		];
	}

	public function column_default( $item, $column_name ) {
		switch ( $column_name ) {
			case 'file_name':
			case 'file_type':
				return esc_html( $item[ $column_name ] ?? '' );
			case 'date_modified':
				return $item[ $column_name ] ? esc_html( date( 'Y/m/d \a\t g:i a', strtotime( $item[ $column_name ] ) ) ) : '';
			case 'file_size':
				return $item[ $column_name ] ? esc_html( $this->format_file_size( $item[ $column_name ] ) ) : '';
			case 'shortcode':
				$shortcode = '[fvm id="' . esc_attr( $item['id'] ?? '' ) . '"]';
				return sprintf(
					'<div class="shortcode-column-container">
						<code style="font-size: 12px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; max-width: 200px;">%s</code>
						<button type="button" class="button button-small copy-shortcode" data-shortcode="%s">Copy</button>
					</div>',
					esc_html( $shortcode ),
					esc_attr( $shortcode )
				);
			default:
				return esc_html( print_r( $item, true ) );
		}
	}

	public function column_cb( $item ) {
		return sprintf(
			'<input type="checkbox" name="file[]" value="%s" />',
			$item['id']
		);
	}

	public function column_file_name( $item ) {
		$actions = [ 
			'id' => sprintf( '<span>ID: %d</span>', $item['id'] ),
			'edit' => sprintf( '<a href="#" class="edit-file" data-file-id="%d">Edit</a>', $item['id'] ),
			'view' => sprintf( '<a href="%s" target="_blank">View</a>', esc_url( add_query_arg( 'file', $item['file_name'], home_url() ) ) ),
			'download' => sprintf( '<a href="%s" download>Download</a>', esc_url( add_query_arg( 'file', $item['file_name'], home_url() ) ) ),
			'delete' => sprintf( '<a href="%s" onclick="return confirm(\'Are you sure you want to delete this file?\')">Delete</a>', wp_nonce_url( admin_url( 'admin.php?page=fvm_files&action=delete&file_id=' . $item['id'] ), 'delete_file_' . $item['id'] ) ),
		];

		$file_icon = $this->get_file_icon_class( $item['file_type'] );
		$file_name = sprintf( '<div style="display: flex;"><div class="dashicons %s" style="margin-right: 6px;"></div> <div>%s</div><div>%s</div></div>', $file_icon, ! empty( $item['file_display_name'] ) ? $item['file_display_name'] : $item['file_name'], $item['file_offline'] ? '<div class="fvm-file-offline"></div>' : '' );

		return sprintf(
			'<div class="file-row" id="file-row-%d">
				<div class="file-info">%s %s</div>
			</div>',
			$item['id'],
			$file_name,
			$this->row_actions( $actions )
		);
	}

	private function get_categories() {
		return $this->wpdb->get_results( "SELECT id, cat_name FROM {$this->cat_table_name} ORDER BY cat_name ASC" );
	}

	private function format_file_size( $size_in_bytes ) {
		$units = array( 'B', 'KB', 'MB', 'GB', 'TB' );
		$size = $size_in_bytes;
		$unit_index = 0;

		while ( $size >= 1024 && $unit_index < count( $units ) - 1 ) {
			$size /= 1024;
			$unit_index++;
		}

		return round( $size, 2 ) . ' ' . $units[ $unit_index ];
	}

	private function get_file_icon_class( $file_type ) {
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