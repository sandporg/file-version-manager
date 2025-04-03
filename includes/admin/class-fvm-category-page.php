<?php
namespace FVM\FileVersionManager;

#todo: - Update page layout
#todo: - Update edit modal layout

/**
 * Class FVM_Category_Page
 * Manages the admin page for categories, including display, creation, editing, and deletion.
 */
class FVM_Category_Page {
	/**
	 * Category manager instance.
	 * @var FVM_Category_Manager
	 */
	private $category_manager;

	/**
	 * Instance of the WP_List_Table for displaying categories.
	 * @var FVM_Category_List_Table
	 */
	private $category_list_table;

	/**
	 * The hook suffix for the category admin page.
	 * @var string|false
	 */
	private $page_hook;

	/**
	 * Plugin base URL.
	 * @var string
	 */
	private $plugin_url;

	/**
	 * Plugin base path.
	 * @var string
	 */
	private $plugin_path;

	/**
	 * Screen option ID for items per page.
	 * @var string
	 */
	private $screen_option_id = 'fvm_categories_per_page';

	/**
	 * Notice manager instance.
	 * @var FVM_Admin_Notices
	 */
	private $notice_manager;

	/**
	 * Constructor.
	 *
	 * @param FVM_Category_Manager $category_manager Instance of the category manager.
	 * @param string $plugin_url Plugin base URL.
	 * @param string $plugin_path Plugin base path.
	 * @param FVM_Admin_Notices $notice_manager Instance of the notice manager.
	 */
	public function __construct( FVM_Category_Manager $category_manager, $plugin_url, $plugin_path, FVM_Admin_Notices $notice_manager ) {
		$this->category_manager = $category_manager;
		$this->plugin_url = $plugin_url;
		$this->plugin_path = $plugin_path;
		$this->notice_manager = $notice_manager;
	}

	/**
	 * Initialize hooks.
	 */
	public function init() {
		add_action( 'admin_menu', [ $this, 'add_category_page' ] );

		// Actions hooked to the specific page load
		add_action( 'load-files_page_fvm_categories', [ $this, 'on_load_page' ] );

		// AJAX action for fetching category data for the modal
		add_action( 'wp_ajax_get_category_data', [ $this, 'ajax_get_category_data' ] ); // Note: Renamed from get_category_files

		// Screen options filter
		add_filter( 'set-screen-option', [ $this, 'set_screen_option' ], 10, 3 );

		// Handle form submissions via admin-post.php
		add_action( 'admin_post_add_category', [ $this, 'handle_add_category' ] );
		add_action( 'admin_post_update_category', [ $this, 'handle_update_category' ] );
		add_action( 'admin_post_delete_category', [ $this, 'handle_delete_category' ] );
	}

	/**
	 * Actions to perform when the category admin page loads.
	 * Sets up list table, screen options, handles bulk actions.
	 */
	public function on_load_page() {
		$this->setup_list_table();
		$this->add_screen_options();
		$this->handle_bulk_actions(); // Handle bulk actions before potential redirects
	}

	/**
	 * Add the categories submenu page under 'Files'.
	 */
	public function add_category_page() {
		$this->page_hook = add_submenu_page(
			'fvm_files',
			esc_html__( 'Categories', 'file-version-manager' ),
			esc_html__( 'Categories', 'file-version-manager' ),
			'manage_options', // Capability required
			'fvm_categories',
			[ $this, 'display_admin_page' ]
		);

		// Optional: Enqueue scripts specific to this page using the hook suffix
		if ( $this->page_hook ) {
			// add_action( 'admin_print_styles-' . $this->page_hook, [ $this, 'enqueue_category_styles' ] );
			add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_category_scripts' ] );
		}
	}

	/**
	 * Enqueue scripts and styles specific to the category page.
	 *
	 * @param string $hook_suffix The current admin page hook.
	 */
	public function enqueue_category_scripts( $hook_suffix ) {
		if ( $this->page_hook !== $hook_suffix ) {
			return;
		}

		// Example: Enqueue a dedicated JS file for the category page modal/AJAX interactions
		$script_path = 'assets/js/fvm-admin-categories.js';
		// Ensure this file exists and contains the JS logic previously inline.
		if ( file_exists( $this->plugin_path . $script_path ) ) {
			wp_enqueue_script(
				'fvm-admin-categories',
				$this->plugin_url . $script_path,
				[ 'jquery' ], // Add dependencies like jQuery if needed
				filemtime( $this->plugin_path . $script_path ),
				true
			);

			// Localize script data (like nonces and AJAX URL)
			wp_localize_script( 'fvm-admin-categories', 'fvmCategoryData', [ 
				'ajax_url' => admin_url( 'admin-ajax.php' ),
				'get_category_nonce' => wp_create_nonce( 'get_category_data' ),
				'edit_category_base_nonce' => wp_create_nonce( 'edit_category' ), // Base nonce for editing, specific ID added in JS if needed
				'text' => [ 
					'loading' => esc_html__( 'Loading...', 'file-version-manager' ),
					'error' => esc_html__( 'Error', 'file-version-manager' ),
				],
			] );
		}

		// Example: Enqueue CSS
		// wp_enqueue_style( 'fvm-admin-categories-style', $this->plugin_url . 'assets/css/fvm-admin-categories.css', [], FVM_VERSION );
	}


	/**
	 * Set up the WP_List_Table instance.
	 */
	public function setup_list_table() {
		if ( ! class_exists( 'FVM_Category_List_Table' ) ) {
			require_once __DIR__ . '/list-tables/class-fvm-category-list-table.php';
		}
		$this->category_list_table = new FVM_Category_List_Table( $this->category_manager, $this->screen_option_id );
	}

	/**
	 * Add Screen Options tab.
	 */
	public function add_screen_options() {
		$option = 'per_page';
		$args = [ 
			'label' => esc_html__( 'Categories per page', 'file-version-manager' ),
			'default' => 20,
			'option' => $this->screen_option_id,
		];

		add_screen_option( $option, $args );
	}

	/**
	 * Save the screen options.
	 *
	 * @param mixed $status Screen option value. Default false to skip.
	 * @param string $option The option name.
	 * @param mixed $value The number of rows to use.
	 * @return mixed The option value to save, or $status if not our option.
	 */
	public function set_screen_option( $status, $option, $value ) {
		if ( $this->screen_option_id === $option ) {
			$value = intval( $value );
			if ( $value < 1 || $value > 100 ) { // Basic validation
				add_settings_error(
					'fvm_screen_options_categories',
					'settings_updated',
					esc_html__( 'Please enter a number between 1 and 100 for Categories per page.', 'file-version-manager' ),
					'error'
				);
				return $status; // Return original status if invalid
			}

			add_settings_error(
				'fvm_screen_options_categories',
				'settings_updated',
				esc_html__( 'Screen options saved.', 'file-version-manager' ),
				'success'
			);
			return $value;
		}
		return $status;
	}

	/**
	 * Handle adding a new category via admin-post.php.
	 */
	public function handle_add_category() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'file-version-manager' ) );
		}

		check_admin_referer( 'add_category', 'add_category_nonce' );

		$cat_name = isset( $_POST['cat_name'] ) ? sanitize_text_field( wp_unslash( $_POST['cat_name'] ) ) : '';
		$cat_slug = isset( $_POST['cat_slug'] ) ? sanitize_title( wp_unslash( $_POST['cat_slug'] ) ) : '';
		$cat_parent_id = isset( $_POST['cat_parent_id'] ) ? intval( $_POST['cat_parent_id'] ) : 0;
		// Use wp_kses_post for description if allowed HTML is intended
		$cat_description = isset( $_POST['cat_description'] ) ? wp_kses_post( wp_unslash( $_POST['cat_description'] ) ) : '';

		if ( empty( $cat_name ) ) {
			$this->notice_manager->add_notice_and_redirect( 'fvm_categories', 'error', esc_html__( 'Category name is required.', 'file-version-manager' ) );
			exit;
		}

		$result = $this->category_manager->add_category( $cat_name, $cat_slug, $cat_parent_id, $cat_description );

		if ( ! $result['success'] ) {
			$error_message = isset( $result['message'] ) ? $result['message'] : esc_html__( 'Failed to add category.', 'file-version-manager' );
			$this->notice_manager->add_notice_and_redirect( 'fvm_categories', 'error', $error_message );
		} else {
			$this->notice_manager->add_notice_and_redirect( 'fvm_categories', 'success', esc_html__( 'Category added successfully.', 'file-version-manager' ) );
		}
		exit; // Essential after admin-post handling
	}

	/**
	 * Handle updating an existing category via admin-post.php.
	 */
	public function handle_update_category() {
		if ( ! isset( $_POST['update_category'], $_POST['category_id'], $_POST['edit_category_nonce'] ) ) {
			// Optional: Redirect or show error if essential fields are missing, though nonce check often catches this.
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to update categories.', 'file-version-manager' ) );
		}

		$category_id = intval( $_POST['category_id'] );

		// Verify the nonce passed from the edit form
		if ( ! wp_verify_nonce( $_POST['edit_category_nonce'], 'edit_category_' . $category_id ) ) {
			wp_die( esc_html__( 'Security check failed.', 'file-version-manager' ), 'Invalid Nonce', [ 'response' => 403 ] );
		}

		$cat_name = isset( $_POST['edit_cat_name'] ) ? sanitize_text_field( wp_unslash( $_POST['edit_cat_name'] ) ) : '';
		$cat_description = isset( $_POST['edit_cat_description'] ) ? wp_kses_post( wp_unslash( $_POST['edit_cat_description'] ) ) : '';
		$cat_parent_id = isset( $_POST['edit_cat_parent_id'] ) ? intval( $_POST['edit_cat_parent_id'] ) : 0;
		$cat_exclude_browser = isset( $_POST['edit_cat_exclude_browser'] ) ? 1 : 0;

		if ( empty( $cat_name ) ) {
			$this->notice_manager->add_notice_and_redirect( 'fvm_categories', 'error', esc_html__( 'Category name cannot be empty.', 'file-version-manager' ) );
			exit;
		}

		$update_result = $this->category_manager->update_category( $category_id, $cat_name, $cat_description, $cat_parent_id, $cat_exclude_browser );

		if ( $update_result ) {
			$this->notice_manager->add_notice_and_redirect( 'fvm_categories', 'success', esc_html__( 'Category updated successfully.', 'file-version-manager' ) );
		} else {
			$this->notice_manager->add_notice_and_redirect( 'fvm_categories', 'error', esc_html__( 'Failed to update category.', 'file-version-manager' ) );
		}
		exit; // Essential after admin-post handling
	}

	/**
	 * Handle deleting a single category via admin-post.php.
	 */
	public function handle_delete_category() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to delete categories.', 'file-version-manager' ) );
		}

		if ( ! isset( $_GET['category_id'], $_GET['_wpnonce'] ) ) {
			$this->notice_manager->add_notice_and_redirect( 'fvm_categories', 'error', esc_html__( 'Invalid request for category deletion.', 'file-version-manager' ) );
			exit;
		}

		$category_id = intval( $_GET['category_id'] );

		if ( ! wp_verify_nonce( $_GET['_wpnonce'], 'delete_category_' . $category_id ) ) {
			wp_die( esc_html__( 'Security check failed.', 'file-version-manager' ), 'Invalid Nonce', [ 'response' => 403 ] );
		}

		$delete_result = $this->category_manager->delete_category( $category_id );

		if ( $delete_result === false ) {
			$this->notice_manager->add_notice_and_redirect( 'fvm_categories', 'error', esc_html__( 'Failed to delete category.', 'file-version-manager' ) );
		} else {
			$this->notice_manager->add_notice_and_redirect( 'fvm_categories', 'success', esc_html__( 'Category deleted successfully.', 'file-version-manager' ) );
		}
		exit; // Essential after admin-post handling
	}

	/**
	 * Handle bulk deletion actions.
	 * Hooked to load-{page_hook}.
	 */
	public function handle_bulk_actions() {
		if ( ! isset( $this->category_list_table ) ) {
			// Should have been set up by on_load_page, but check just in case.
			return;
		}

		$action = $this->category_list_table->current_action();

		if ( $action && in_array( $action, [ 'delete' ] ) ) { // Using 'delete' as defined in get_bulk_actions

			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( esc_html__( 'You do not have sufficient permissions to perform this action.', 'file-version-manager' ) );
			}

			check_admin_referer( 'bulk-categories' );

			$category_ids = isset( $_POST['category'] ) ? array_map( 'absint', (array) $_POST['category'] ) : [];

			if ( ! empty( $category_ids ) ) {
				$deleted_count = 0;
				foreach ( $category_ids as $category_id ) {
					if ( $this->category_manager->delete_category( $category_id ) ) {
						$deleted_count++;
					}
				}

				if ( $deleted_count > 0 ) {
					$message = sprintf(
						/* translators: %s: number of deleted categories */
						_n( '%s category deleted.', '%s categories deleted.', $deleted_count, 'file-version-manager' ),
						number_format_i18n( $deleted_count )
					);
					$this->notice_manager->add_notice_and_redirect( 'fvm_categories', 'success', $message );
				} else {
					$this->notice_manager->add_notice_and_redirect( 'fvm_categories', 'error', esc_html__( 'No categories were deleted.', 'file-version-manager' ) );
				}
				exit; // Redirect after processing bulk action
			} else {
				$this->notice_manager->add_notice_and_redirect( 'fvm_categories', 'warning', esc_html__( 'Please select categories to delete.', 'file-version-manager' ) );
				exit;
			}
		}
	}

	/**
	 * AJAX handler to fetch data for the category edit modal.
	 */
	public function ajax_get_category_data() {
		check_ajax_referer( 'get_category_data', '_ajax_nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => esc_html__( 'Permission denied.', 'file-version-manager' ) ], 403 );
		}

		if ( ! isset( $_GET['category_id'] ) ) {
			wp_send_json_error( [ 'message' => esc_html__( 'Missing category ID.', 'file-version-manager' ) ], 400 );
		}

		$category_id = intval( $_GET['category_id'] );
		$category = $this->category_manager->get_category( $category_id );

		if ( ! $category ) {
			wp_send_json_error( [ 'message' => esc_html__( 'Category not found.', 'file-version-manager' ) ], 404 );
		}

		// Prepare data for the modal
		$data = [ 
			'id' => $category->id,
			'cat_name' => $category->cat_name,
			'cat_slug' => $category->cat_slug,
			'cat_description' => $category->cat_description, // Raw data, escaped in JS if needed
			'cat_parent_id' => $category->cat_parent_id,
			'cat_exclude_browser' => $category->cat_exclude_browser,
			'parent_categories' => $this->category_manager->get_categories_hierarchical(), // Get ALL categories
			'nonce' => wp_create_nonce( 'edit_category_' . $category->id ) // Create nonce for form submission
		];

		wp_send_json_success( $data );
	}

	/**
	 * Display the admin page content.
	 */
	public function display_admin_page() {
		if ( ! isset( $this->category_list_table ) ) {
			// Fallback if load hook didn't fire correctly
			$this->setup_list_table();
		}

		ob_start();

		$this->category_list_table->prepare_items();
		// $this->handle_update_category(); // Update is handled via admin-post.php now

		?>
		<div class="wrap" style="margin-top: 50px;">
			<h1 class="wp-heading-inline"><?php echo esc_html( get_admin_page_title() ); ?></h1>
			<hr class="wp-header-end">

			<?php
			// Manually render notices here
			$this->notice_manager->display_persistent_upgrade_notice_if_needed();
			FVM_Admin_Notices::render_fvm_notices();
			?>

			<form class="search-form wp-clearfix" method="get">
				<input type="hidden" name="page" value="fvm_categories" />
				<?php $this->category_list_table->search_box( 'Search Categories', 'search' ); ?>
			</form>

			<div id="col-container" class="wp-clearfix">
				<div id="col-left">
					<div class="col-wrap">
						<div class="form-wrap">
							<h2>Add New Category</h2>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
								<input type="hidden" name="action" value="add_category">
								<?php wp_nonce_field( 'add_category', 'add_category_nonce' ); ?>

								<div class="form-field form-required term-name-wrap">
									<label for="cat_name">Name</label>
									<input name="cat_name" type="text" id="cat_name" value="" size="40" class="regular-text"
										required>
									<p id="cat_name_description">The name of the category.</p>
								</div>

								<div class="form-field">
									<label for="cat_slug">Slug</label>
									<input name="cat_slug" type="text" id="cat_slug" value="" size="40" class="regular-text">
									<p id="cat_slug_description">The "slug" is the URL-friendly version of the name. It is
										usually all lowercase and contains only letters, numbers, and hyphens. Leave blank to
										auto-generate.</p>
								</div>

								<div class="form-field term-parent-wrap">
									<label for="cat_parent_id">Parent Category</label>
									<select name="cat_parent_id" id="cat_parent_id">
										<option value="0">None</option>
										<?php
										$categories = $this->category_manager->get_categories_hierarchical();
										if ( ! empty( $categories ) ) {
											$this->category_manager->display_category_options( $categories );
										}
										?>
									</select>
									<p id="cat_parent_id_description">The parent category of the file. Select 'None' to create a
										top-level category.</p>
								</div>

								<div class="form-field">
									<label for="cat_description">Description</label>
									<textarea name="cat_description" id="cat_description" rows="5" cols="50"></textarea>
									<p id="cat_description_description">The description of the category.</p>
								</div>

								<p class="submit">
									<input type="submit" name="submit" id="submit" class="button button-primary"
										value="Add New Category">
								</p>
							</form>
						</div>
					</div>
				</div>
				<div id="col-right">
					<div class="col-wrap">

						<div id="edit-modal" class="edit-modal">
							<form id="edit-form" method="post" enctype="multipart/form-data">
								<input type="hidden" name="action" value="update_category">
								<?php wp_nonce_field( 'edit_category', 'edit_category_nonce' ); ?>
								<input type="hidden" name="category_id" id="edit-category-id" value="">

								<div class="edit-modal-content-container">
									<div class="edit-modal-content">
										<span class="close">&times;</span>

										<div class="fvm-edit-modal-title">
											<h2>Edit Category</h2>
											<h3 id="category_id"></h3>
										</div>

										<table class="form-table">
											<tr>
												<th scope="row"><label for="edit_cat_name">Category Name</label>
												</th>
												<td>
													<input type="text" name="edit_cat_name" id="edit_cat_name" value=""
														class="regular-text" required>
												</td>
											</tr>
											<tr>
												<th scope="row"><label for="edit_cat_slug">Category Slug</label>
												</th>
												<td>
													<input type="text" name="edit_cat_slug" id="edit_cat_slug" value=""
														class="regular-text" readonly disabled>
												</td>
											</tr>
											<tr>
												<th scope="row"><label for="cat_description">Description</label></th>
												<td>
													<textarea name="edit_cat_description" id="edit_cat_description"
														class="large-text" rows="4"></textarea>
												</td>
											</tr>
											<tr>
												<th scope="row"><label for="cat_parent_id">Parent
														Category</label></th>
												<td>
													<select name="edit_cat_parent_id" id="edit_cat_parent_id">
														<option value="0">None</option>
														<!-- Categories will be populated dynamically -->
													</select>
												</td>
											</tr>
										</table>
									</div>
									<div class="fvm-edit-modal-footer">
										<div class="fvm-edit-modal-footer-inner">
											<label class="switch">
												<input type="checkbox" name="edit_cat_exclude_browser"
													id="edit_cat_exclude_browser" value="1">
												<span class="slider round"></span>
											</label>
											<span>Disabled</span>
										</div>
										<p class="submit">
											<button type="button" class="button cancel-edit">Cancel</button>
											<input type="submit" name="update_category" id="update_category"
												class="button button-primary" value="Update Category">
										</p>
									</div>
								</div>
							</form>
						</div>
						<div id="edit-modal-overlay" class="edit-modal-overlay"></div>

						<form method="post">
							<?php
							wp_nonce_field( 'bulk-categories' );
							$this->category_list_table->display_bulk_action_result();
							$this->category_list_table->display();
							?>
						</form>

					</div>
				</div>
			</div>
		</div>

		<?php /* Removed inline JavaScript. It should be moved to a separate file (e.g., assets/js/fvm-admin-categories.js) and enqueued. */ ?>
		<?php
		ob_end_flush();
	}
}