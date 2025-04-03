<?php
namespace FVM\FileVersionManager;

class FVM_Category_Manager {
	private $wpdb;
	private $category_table_name;
	private $file_table_name;
	private $rel_table_name;

	public function __construct( \wpdb $wpdb ) {
		$this->wpdb = $wpdb;
		$this->category_table_name = esc_sql( $wpdb->prefix . CAT_TABLE_NAME );
		$this->file_table_name = esc_sql( $wpdb->prefix . FILE_TABLE_NAME );
		$this->rel_table_name = esc_sql( $wpdb->prefix . REL_TABLE_NAME );
	}

	/**
	 * Adds a new category to the database.
	 * 
	 * @param string $cat_name The name of the category.
	 * @param string $cat_slug Optional. The slug of the category.
	 * @param int $cat_parent_id Optional. The parent ID of the category.
	 * @param string $cat_description Optional. The description of the category.
	 * @return array Returns an array with 'success' and 'message' keys.
	 */
	public function add_category( $cat_name, $cat_slug = '', $cat_parent_id = 0, $cat_description = '' ) {
		$cat_slug = empty( $cat_slug ) ? sanitize_title( $cat_name ) : $cat_slug;

		// Check if category with same slug already exists
		$existing_category = $this->wpdb->get_var(
			$this->wpdb->prepare(
				"SELECT id FROM {$this->category_table_name} WHERE cat_slug = %s",
				$cat_slug
			)
		);

		if ( $existing_category ) {
			return [ 'success' => false, 'message' => sprintf( 'A category with the slug %s already exists.', $cat_slug ) ];
		}

		// Check if parent category exists if specified
		if ( $cat_parent_id > 0 ) {
			$parent_exists = $this->wpdb->get_var(
				$this->wpdb->prepare(
					"SELECT id FROM {$this->category_table_name} WHERE id = %d",
					$cat_parent_id
				)
			);

			if ( ! $parent_exists ) {
				return [ 'success' => false, 'message' => 'The specified parent category does not exist.' ];
			}
		}

		$result = $this->wpdb->insert(
			$this->category_table_name,
			compact( 'cat_name', 'cat_slug', 'cat_parent_id', 'cat_description' ),
			[ '%s', '%s', '%d', '%s' ]
		);

		if ( $result ) {
			$category_id = $this->wpdb->insert_id;

			// Log the category creation
			apply_filters(
				'simple_history_log',
				'Added new category (ID: {category_id}): {category_name}',
				[ 
					'category_id' => $category_id,
					'category_name' => $cat_name,
					'category_slug' => $cat_slug,
					'category_parent_id' => $cat_parent_id,
				],
				'info'
			);

			return [ 'success' => true ];
		}

		// Database insertion failed
		return [ 'success' => false, 'message' => 'Unable to create category.' ];
	}

	/**
	 * Updates a category in the database.
	 *
	 * @param int $category_id The ID of the category to update.
	 * @param string $cat_name The new name of the category.
	 * @param string $cat_description The new description of the category.
	 * @param int $cat_parent_id The new parent ID of the category.
	 * @return bool|int Returns true on success, false on failure.
	 */
	public function update_category( $category_id, $cat_name, $cat_description, $cat_parent_id, $cat_exclude_browser ) {
		$data = array_filter( [ 
			'cat_name' => $cat_name,
			'cat_description' => $cat_description,
			'cat_parent_id' => $cat_parent_id,
			'cat_exclude_browser' => $cat_exclude_browser,
		], function ($value) {
			return $value !== null;
		} );

		if ( empty( $data ) ) {
			return false;
		}

		$formats = array_map( function ($key) {
			return $key === 'cat_parent_id' || $key === 'cat_exclude_browser' ? '%d' : '%s';
		}, array_keys( $data ) );

		return $this->wpdb->update(
			$this->category_table_name,
			$data,
			[ 'id' => $category_id ],
			$formats,
			[ '%d' ]
		);
	}

	/**
	 * Deletes a category from the database.
	 *
	 * @param int $category_id The ID of the category to delete.
	 * @return bool|int Returns true on success, false on failure.
	 */
	public function delete_category( $category_id ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return false;
		}

		// Get category info before deletion for logging
		$category = $this->get_category( $category_id );
		if ( ! $category ) {
			return false;
		}

		$deleted = $this->wpdb->delete(
			$this->category_table_name,
			[ 'id' => $category_id ],
			[ '%d' ]
		);

		if ( $deleted ) {
			// Log the deletion
			apply_filters(
				'simple_history_log',
				'Deleted file category (ID: {category_id}): {category_name}',
				[ 
					'category_id' => $category_id,
					'category_name' => $category->cat_name,
				],
				'info'
			);

			return true;
		}

		return false;
	}

	/**
	 * Retrieves categories from the database.
	 *
	 * @param string $search Optional. The search query.
	 * @param string $orderby The column to order by.
	 * @param string $order The order direction.
	 * @return array Returns an array of category data.
	 */
	public function get_categories( $search = '', $orderby = 'cat_name', $order = 'ASC' ) {
		$allowed_orderby = [ 'cat_name', 'id', 'cat_parent_id' ];
		$allowed_order = [ 'ASC', 'DESC' ];

		$orderby = in_array( $orderby, $allowed_orderby ) ? $orderby : 'cat_name';
		$order = in_array( strtoupper( $order ), $allowed_order ) ? strtoupper( $order ) : 'ASC';

		// Escape identifiers
		$orderby = esc_sql( $orderby );

		$query = "SELECT DISTINCT c.*, COUNT(DISTINCT r.file_id) as total_files
                  FROM {$this->category_table_name} c 
                  LEFT JOIN {$this->rel_table_name} r ON c.id = r.category_id";

		// If there's a search term, join with categories table twice to search through both parent and child categories
		if ( ! empty( $search ) ) {
			$query .= " LEFT JOIN {$this->category_table_name} p ON c.id = p.cat_parent_id
					   LEFT JOIN {$this->category_table_name} ch ON ch.cat_parent_id = c.id";
		}

		$query .= " WHERE 1=1";

		$params = [];
		if ( ! empty( $search ) ) {
			$search_term = '%' . $this->wpdb->esc_like( $search ) . '%';
			$query .= " AND (c.cat_name LIKE %s OR p.cat_name LIKE %s OR ch.cat_name LIKE %s)";
			$params[] = $search_term;
			$params[] = $search_term;
			$params[] = $search_term;
		}

		$query .= " GROUP BY c.id ORDER BY {$orderby} {$order}";

		$prepared_query = $params ? $this->wpdb->prepare( $query, $params ) : $query;
		$categories = $this->wpdb->get_results( $prepared_query, ARRAY_A );

		return $this->build_category_tree( $categories );
	}

	/**
	 * Builds a hierarchical category tree to display parent-child relationships.
	 *
	 * @param array $categories The array of category data.
	 * @param int $parent_id The parent ID to build the tree from.
	 * @param int $level The current level of the tree.
	 * @return array Returns the hierarchical category tree.
	 */
	public function build_category_tree( $categories, $parent_id = 0, $level = 0 ) {
		$tree = [];
		foreach ( $categories as $category ) {
			if ( $category['cat_parent_id'] == $parent_id ) {
				$category['level'] = $level;
				$tree[] = $category;
				$tree = array_merge( $tree, $this->build_category_tree( $categories, $category['id'], $level + 1 ) );
			}
		}
		return $tree;
	}

	/**
	 * Retrieves hierarchical categories from the database.
	 *
	 * @param int $parent_id The parent ID to retrieve categories from.
	 * @return array Returns an array of hierarchical category data.
	 */
	public function get_categories_hierarchical( $parent_id = 0 ) {
		$parent_id = intval( $parent_id );

		$query = $this->wpdb->prepare(
			"SELECT id, cat_name, cat_parent_id 
			 FROM {$this->category_table_name} 
			 WHERE cat_parent_id = %d 
			 ORDER BY cat_name ASC",
			$parent_id
		);

		$categories = $this->wpdb->get_results( $query );

		if ( $this->wpdb->last_error ) {
			return [];
		}

		foreach ( $categories as $category ) {
			$category->children = $this->get_categories_hierarchical( $category->id );
		}

		return $categories;
	}

	public function display_category_options( $categories, $depth = 0 ) {
		foreach ( $categories as $category ) {
			echo '<option value="' . esc_attr( $category->id ) . '">'
				. esc_html( str_repeat( '&nbsp;', $depth * 3 ) . $category->cat_name )
				. '</option>';

			if ( ! empty( $category->children ) ) {
				$this->display_category_options( $category->children, $depth + 1 );
			}
		}
	}

	public function get_category( $category_id ) {
		return $this->wpdb->get_row(
			$this->wpdb->prepare(
				"SELECT * FROM {$this->category_table_name} WHERE id = %d",
				$category_id
			)
		);
	}

	public function get_category_data( $category_id ) {

		// Verify user
		if ( ! current_user_can( 'manage_options' ) ) {
			return false;
		}

		// Verify nonce
		if ( ! isset( $_REQUEST['_ajax_nonce'] ) || ! wp_verify_nonce( $_REQUEST['_ajax_nonce'], 'get_category_data' ) ) {
			return false;
		}

		$category = $this->get_category( $category_id );
		if ( ! $category ) {
			return false;
		}

		$category_data = (array) $category;

		$categories = $this->get_categories_hierarchical();
		$category_data['parent_categories'] = $categories;

		$category_data['nonce'] = wp_create_nonce( 'edit_category_' . $category_id );

		return $category_data;
	}
}