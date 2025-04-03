<?php

#todo: clicking on count will redirect to the files page showing all files in that category

namespace FVM\FileVersionManager;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once( ABSPATH . 'wp-admin/includes/class-wp-list-table.php' );
}

class FVM_Category_List_Table extends \WP_List_Table {
	private $wpdb;
	private $category_manager;
	private $category_table_name;
	private $file_table_name;
	private $rel_table_name;

	public function __construct( FVM_Category_Manager $category_manager ) {
		parent::__construct( [ 
			'singular' => 'category',
			'plural' => 'categories',
			'ajax' => false,
		] );
		global $wpdb;
		$this->wpdb = $wpdb;
		$this->category_manager = $category_manager;
		$this->category_table_name = esc_sql( $wpdb->prefix . CAT_TABLE_NAME );
		$this->file_table_name = esc_sql( $wpdb->prefix . FILE_TABLE_NAME );
		$this->rel_table_name = esc_sql( $wpdb->prefix . REL_TABLE_NAME );
	}

	public function prepare_items() {
		if ( ! $this->ensure_table_exists() ) {
			return;
		}

		$search = isset( $_REQUEST['s'] ) ? sanitize_text_field( $_REQUEST['s'] ) : '';

		$per_page = $this->get_items_per_page( 'fvm_categories_per_page', 40 );
		$current_page = $this->get_pagenum();

		$orderby = isset( $_REQUEST['orderby'] ) ? $this->sanitize_orderby( $_REQUEST['orderby'] ) : 'cat_name';
		$order = isset( $_REQUEST['order'] ) ? $this->sanitize_order( $_REQUEST['order'] ) : 'ASC';

		$all_categories = $this->category_manager->get_categories( $search, $orderby, $order );
		$total_items = count( $all_categories );

		$this->set_pagination_args( [ 
			'total_items' => $total_items,
			'per_page' => $per_page,
		] );

		$this->_column_headers = [ $this->get_columns(), [], $this->get_sortable_columns() ];

		$offset = ( $current_page - 1 ) * $per_page;
		$this->items = array_slice( $all_categories, $offset, $per_page );
	}

	public function get_bulk_actions() {
		return [ 
			'delete' => 'Delete',
		];
	}

	public function get_total_items( $search = '' ) {
		$categories = $this->category_manager->get_categories( $search );
		return count( $categories );
	}

	public function search_box( $text, $input_id ) {
		if ( empty( $_REQUEST['s'] ) && ! $this->has_items() ) {
			return;
		}

		$input_id = $input_id . '-search-input';

		if ( ! empty( $_REQUEST['orderby'] ) ) {
			echo '<input type="hidden" name="orderby" value="' . esc_attr( $_REQUEST['orderby'] ) . '" />';
		}
		if ( ! empty( $_REQUEST['order'] ) ) {
			echo '<input type="hidden" name="order" value="' . esc_attr( $_REQUEST['order'] ) . '" />';
		}
		if ( ! empty( $_REQUEST['post_mime_type'] ) ) {
			echo '<input type="hidden" name="post_mime_type" value="' . esc_attr( $_REQUEST['post_mime_type'] ) . '" />';
		}
		if ( ! empty( $_REQUEST['detached'] ) ) {
			echo '<input type="hidden" name="detached" value="' . esc_attr( $_REQUEST['detached'] ) . '" />';
		}
		?>
		<p class="search-box">
			<label class="screen-reader-text"
				for="<?php echo esc_attr( $input_id ); ?>"><?php echo esc_html( $text ); ?>:</label>
			<input type="search" id="<?php echo esc_attr( $input_id ); ?>" name="s" value="<?php _admin_search_query(); ?>" />
			<?php submit_button( esc_html( $text ), '', '', false, array( 'id' => 'search-submit' ) ); ?>
		</p>
		<?php
	}

	private function ensure_table_exists() {
		$table = $this->wpdb->esc_like( $this->category_table_name );
		$sql = $this->wpdb->prepare( "SHOW TABLES LIKE %s", $table );
		if ( $this->wpdb->get_var( $sql ) != $this->category_table_name ) {
			error_log( "Table $this->category_table_name does not exist." );
			return false;
		}
		return true;
	}

	public function get_columns() {
		return [ 
			'cb' => '<input type="checkbox" />',
			'cat_name' => 'Name',
			'cat_description' => 'Description',
			'cat_parent_id' => 'Parent Category',
			'total_files' => 'Count',
		];
	}

	public function get_sortable_columns() {
		return [ 
			'cat_name' => [ 'cat_name', true ],
		];
	}

	public function column_default( $item, $column_name ) {
		switch ( $column_name ) {
			case 'cat_description':
				return empty( $item[ $column_name ] ) ? '—' : esc_html( $item[ $column_name ] );
			case 'cat_parent_id':
				return $this->get_parent_category_name( $item[ $column_name ] );
			case 'total_files':
				return intval( $item['total_files'] );
			default:
				return esc_html( $item[ $column_name ] ?? '' );
		}
	}

	public function column_cb( $item ) {
		return sprintf(
			'<input type="checkbox" name="category[]" value="%s" />',
			$item['id']
		);
	}

	public function column_cat_name( $item ) {
		$indent = str_repeat( '— ', $item['level'] );
		$actions = [ 
			'id' => sprintf( '<span>ID: %d</span>', $item['id'] ),
			'edit' => sprintf( '<a href="#" class="edit-category" data-category-id="%d">Edit</a>', $item['id'] ),
			'delete' => sprintf(
				'<a href="%s" onclick="return confirm(\'Are you sure you want to delete this category?\')">Delete</a>',
				wp_nonce_url(
					admin_url( 'admin-post.php?action=delete_category&category_id=' . $item['id'] ),
					'delete_category_' . $item['id']
				)
			),
		];

		$cat_name = sprintf( '<div style="display: flex;"><div>%s %s</div><div>%s</div></div>', $indent, $item['cat_name'], $item['cat_exclude_browser'] ? '<div class="fvm-file-offline"></div>' : '' );

		return sprintf(
			'<div class="file-row" id="cat-row-%d">
				<div class="file-info">%s %s</div>
			</div>',
			$item['id'],
			$cat_name,
			$this->row_actions( $actions )
		);
	}

	private function display_category_options( $categories, $selected_id, $current_id, $depth = 0 ) {
		foreach ( $categories as $category ) {
			if ( $category->id != $current_id ) {
				$padding = str_repeat( '&nbsp;', $depth * 3 );
				$selected = ( $selected_id == $category->id ) ? 'selected' : '';
				echo "<option value='" . esc_attr( $category->id ) . "' " . esc_attr( $selected ) . ">" . wp_kses_post( $padding ) . esc_html( $category->cat_name ) . "</option>";
				if ( ! empty( $category->children ) ) {
					$this->display_category_options( $category->children, $selected_id, $current_id, $depth + 1 );
				}
			}
		}
	}

	private function get_parent_category_name( $parent_id ) {
		if ( $parent_id == 0 ) {
			return '—';
		}

		$parent_name = $this->wpdb->get_var( $this->wpdb->prepare( "SELECT cat_name FROM %i WHERE id = %d", $this->category_table_name, $parent_id ) );

		return $parent_name ? $parent_name : 'Unknown';
	}

	private function sanitize_orderby( $orderby ) {
		$allowed_columns = array_keys( $this->get_sortable_columns() );
		return in_array( $orderby, $allowed_columns ) ? $orderby : 'cat_name';
	}

	private function sanitize_order( $order ) {
		return in_array( strtoupper( $order ), [ 'ASC', 'DESC' ] ) ? strtoupper( $order ) : 'ASC';
	}

	private function delete_category( $id ) {
		$this->wpdb->delete( $this->category_table_name, array( 'id' => $id ), array( '%d' ) );
	}

	private function add_admin_notice( $type, $message ) {
		$notices = get_transient( 'fvm_admin_notices' ) ?: array();
		$notices[] = array( 'type' => $type, 'message' => $message );
		set_transient( 'fvm_admin_notices', $notices, 60 );
	}
}