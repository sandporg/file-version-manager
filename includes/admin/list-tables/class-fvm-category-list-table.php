<?php

#todo: clicking on count will redirect to the files page showing all files in that category

namespace FVM\FileVersionManager;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once( ABSPATH . 'wp-admin/includes/class-wp-list-table.php' );
}

/**
 * Class FVM_Category_List_Table
 * Handles the display of categories in a WP_List_Table format.
 */
class FVM_Category_List_Table extends \WP_List_Table {
	/**
	 * Category manager instance.
	 * @var FVM_Category_Manager
	 */
	private $category_manager;

	/**
	 * Screen option ID for items per page.
	 * @var string
	 */
	private $screen_option_id;

	/**
	 * Constructor.
	 *
	 * @param FVM_Category_Manager $category_manager Instance of the category manager.
	 * @param string $screen_option_id The screen option ID for items per page.
	 */
	public function __construct( FVM_Category_Manager $category_manager, $screen_option_id ) {
		parent::__construct( [ 
			'singular' => 'category',
			'plural' => 'categories',
			'ajax' => false, // Consider setting to true if implementing AJAX sorting/pagination
		] );
		$this->category_manager = $category_manager;
		$this->screen_option_id = $screen_option_id;
	}

	/**
	 * Prepare the items for the table to process.
	 * Retrieves categories, sets pagination, and column headers.
	 */
	public function prepare_items() {
		$search = isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) : ''; // Added wp_unslash

		$per_page = $this->get_items_per_page( $this->screen_option_id, 20 );
		$current_page = $this->get_pagenum();

		$orderby = isset( $_REQUEST['orderby'] ) ? sanitize_key( $_REQUEST['orderby'] ) : 'id';
		$order = isset( $_REQUEST['order'] ) ? sanitize_key( $_REQUEST['order'] ) : 'asc';

		// Note: Fetching all categories and then slicing might be inefficient for large datasets.
		// Ideally, pagination should happen at the database query level in FVM_Category_Manager::get_categories.
		$all_categories = $this->category_manager->get_categories(
			$search,
			$orderby,
			$order
		);
		$total_items = count( $all_categories );

		$this->set_pagination_args( [ 
			'total_items' => $total_items,
			'per_page' => $per_page,
			'total_pages' => ceil( $total_items / $per_page ),
		] );

		$this->_column_headers = [ $this->get_columns(), [], $this->get_sortable_columns() ];

		$offset = ( $current_page - 1 ) * $per_page;
		$this->items = array_slice( $all_categories, $offset, $per_page );
	}

	/**
	 * Define bulk actions.
	 *
	 * @return array An associative array of bulk actions.
	 */
	public function get_bulk_actions() {
		return [ 
			'delete' => esc_html__( 'Delete', 'file-version-manager' ),
		];
	}

	/**
	 * Display the search box.
	 * Overrides parent method to potentially add customization later.
	 *
	 * @param string $text The 'submit' button label.
	 * @param string $input_id ID attribute value for the search input field.
	 */
	public function search_box( $text, $input_id ) {
		parent::search_box( $text, $input_id );
	}

	/**
	 * Get the list of columns.
	 *
	 * @return array An associative array of columns.
	 */
	public function get_columns() {
		return [ 
			'cb' => '<input type="checkbox" />',
			'cat_name' => esc_html__( 'Name', 'file-version-manager' ),
			'cat_description' => esc_html__( 'Description', 'file-version-manager' ),
			'cat_slug' => esc_html__( 'Slug', 'file-version-manager' ),
			'total_files' => esc_html__( 'Count', 'file-version-manager' ),
		];
	}

	/**
	 * Get the list of sortable columns.
	 *
	 * @return array An associative array of sortable columns.
	 */
	public function get_sortable_columns() {
		return [ 
			'cat_name' => [ 'cat_name', true ], // true means it's already sorted ascending by default
			'cat_slug' => [ 'cat_slug', false ],
			// 'total_files' => [ 'total_files', false ], // Sorting by count might require modification in get_categories
		];
	}

	/**
	 * Default column rendering.
	 *
	 * @param array $item The current item data (expecting an array).
	 * @param string $column_name The name of the current column.
	 * @return string The column content.
	 */
	public function column_default( $item, $column_name ) {
		switch ( $column_name ) {
			case 'cat_description':
				// Use wp_kses_post for description if it might contain allowed HTML
				return empty( $item['cat_description'] ) ? '—' : wp_kses_post( $item['cat_description'] );
			case 'cat_slug':
				return isset( $item['cat_slug'] ) ? esc_html( $item['cat_slug'] ) : 'N/A';
			case 'total_files':
				$count = isset( $item['total_files'] ) ? intval( $item['total_files'] ) : 0;
				$category_id = isset( $item['id'] ) ? intval( $item['id'] ) : 0;
				if ( $category_id > 0 ) {
					$files_page_url = add_query_arg( [ 
						'page' => 'fvm_files', // Assuming the files page slug is 'fvm_files'
						'category_filter' => $category_id,
					], admin_url( 'admin.php' ) );
					return sprintf( '<a href="%s">%d</a>', esc_url( $files_page_url ), $count );
				} else {
					return $count;
				}
			default:
				// Default sanitization for other potential columns
				return isset( $item[ $column_name ] ) ? esc_html( $item[ $column_name ] ) : '';
		}
	}

	/**
	 * Render the checkbox column.
	 *
	 * @param array $item The current item data.
	 * @return string The checkbox HTML.
	 */
	public function column_cb( $item ) {
		if ( ! isset( $item['id'] ) )
			return '';
		return sprintf(
			'<input type="checkbox" name="category[]" value="%d" />',
			intval( $item['id'] )
		);
	}

	/**
	 * Render the category name column with actions.
	 *
	 * @param array $item The current item data.
	 * @return string The category name column content.
	 */
	public function column_cat_name( $item ) {
		if ( ! isset( $item['id'] ) || ! isset( $item['cat_name'] ) )
			return esc_html__( 'Invalid Category Data', 'file-version-manager' );

		$category_id = intval( $item['id'] );
		// Note: Edit nonce is generated here but validated in the modal form handler (class-fvm-category-page.php)
		$edit_nonce = wp_create_nonce( 'edit_category_' . $category_id );
		$delete_nonce = wp_create_nonce( 'delete_category_' . $category_id );

		// Calculate indentation for hierarchical display
		$indent = isset( $item['level'] ) ? str_repeat( '— ', intval( $item['level'] ) ) : '';

		$actions = [ 
			'id' => sprintf( '<span>ID: %d</span>', $category_id ),
			'edit' => sprintf( '<a href="#" class="edit-category" data-category-id="%d" data-nonce="%s">%s</a>',
				$category_id,
				esc_attr( $edit_nonce ),
				esc_html__( 'Edit', 'file-version-manager' )
			),
			'delete' => sprintf(
				'<a href="%s" onclick="return confirm(\'%s\')">%s</a>',
				esc_url( add_query_arg( [ 
					'action' => 'delete_category', // This should match the admin-post action hook
					'category_id' => $category_id,
					'_wpnonce' => $delete_nonce,
				], admin_url( 'admin-post.php' ) ) ),
				esc_js( sprintf( __( 'Are you sure you want to delete category \"%s\"? This cannot be undone.', 'file-version-manager' ), $item['cat_name'] ) ), // Use esc_js for confirm message
				esc_html__( 'Delete', 'file-version-manager' )
			),
		];

		// Visual indicator if category is excluded/offline
		$offline_indicator = isset( $item['cat_exclude_browser'] ) && $item['cat_exclude_browser'] ? '<div class="fvm-file-offline" title="' . esc_attr__( 'Category is Offline/Hidden', 'file-version-manager' ) . '"></div>' : '';

		// Use wp_kses_post for name if it could potentially have formatting, otherwise esc_html is fine.
		$cat_name_display = esc_html( $item['cat_name'] );

		$cat_name_html = sprintf( '<div style="display: flex; align-items: center;">%s%s%s</div>',
			esc_html( $indent ),
			$cat_name_display,
			$offline_indicator // Already contains escaped title
		);

		return sprintf(
			'<div class="category-row" id="cat-row-%d">
				<div class="category-info">%s %s</div>
			</div>',
			$category_id,
			$cat_name_html, // Already escaped
			$this->row_actions( $actions ) // Contains escaped HTML
		);
	}
}