<?php
namespace FVM\FileVersionManager;

class FVM_Category_Page {
	private $category_manager;
	private $category_list_table;
	private $page_hook;

	public function __construct( FVM_Category_Manager $category_manager ) {
		$this->category_manager = $category_manager;
	}

	public function init() {
		add_action( 'admin_menu', [ $this, 'add_category_page' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_scripts' ] );
		add_action( 'load-files_page_fvm_categories', [ $this, 'setup_list_table' ] );
		add_action( 'admin_post_add_category', [ $this, 'handle_add_category' ] );
		add_action( 'admin_post_delete_category', [ $this, 'handle_delete_category' ] );
		add_action( 'load-files_page_fvm_categories', [ $this, 'handle_update_category' ] );
		add_action( 'load-files_page_fvm_categories', [ $this, 'handle_bulk_actions' ] );
	}

	public function add_category_page() {
		add_submenu_page(
			'fvm_files',
			'Categories',
			'Categories',
			'manage_options',
			'fvm_categories',
			array( $this, 'display_admin_page' ),
		);
	}

	public function enqueue_scripts() {
		if ( function_exists( 'get_current_screen' ) ) {
			$screen = get_current_screen();
			if ( $screen && $screen->id === 'files_page_fvm_categories' ) {
				wp_enqueue_style( 'file-version-manager-styles', plugin_dir_url( dirname( __FILE__ ) ) . 'assets/css/fvm-categories.css', [], '1.0.0' );
				wp_enqueue_style( 'file-version-manager-admin-styles', plugin_dir_url( dirname( __FILE__ ) ) . 'assets/css/fvm-admin.css', [], '1.0.0' );
			}
		}
	}

	public function setup_list_table() {
		$this->category_list_table = new FVM_Category_List_Table( $this->category_manager );
	}

	public function display_admin_page() {

		$this->setup_list_table();

		ob_start();

		$this->category_list_table->prepare_items();
		$this->handle_update_category();

		?>
		<div class="wrap">
			<h1>File Categories</h1>
			<?php
			if ( isset( $_GET['update'] ) && isset( $_GET['message'] ) ) {
				$status = $_GET['update'] === 'success' ? 'updated' : 'error';
				$message = urldecode( $_GET['message'] );
				echo "<div class='notice " . esc_attr( $status ) . " is-dismissible'><p>" . esc_html( $message ) . "</p></div>";
			}
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
									<p id="cat_slug_description">The “slug” is the URL-friendly version of the name. It is
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

						<div id="edit-modal" class="edit-modal" style="display:none;">
							<form id="edit-form" method="post" enctype="multipart/form-data">

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

		<script type="text/javascript">
			document.addEventListener('DOMContentLoaded', function () {
				// Edit category
				document.querySelectorAll('.edit-category').forEach(link => {
					link.addEventListener('click', function (e) {
						e.preventDefault();
						const categoryId = this.getAttribute('data-category-id');
						showEditModal(categoryId);
						populateEditModal(categoryId);
					});
				});

				function showEditModal(categoryId) {
					const modal = document.getElementById('edit-modal');
					const overlay = document.getElementById('edit-modal-overlay');
					modal.style.display = 'flex';
					overlay.style.display = 'block';

					// Clear previous data and show loading indicators
					document.getElementById('category_id').textContent = 'Loading...';
					document.getElementById('edit_cat_name').value = 'Loading...';
					document.getElementById('edit_cat_slug').value = 'Loading...';
					document.getElementById('edit_cat_description').value = 'Loading...';
					document.getElementById('edit_cat_parent_id').innerHTML = '<option value="0">Loading...</option>';
					document.getElementById('edit_cat_exclude_browser').checked = false;
				}

				function populateEditModal(categoryId) {
					fetch(`<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>?action=get_category_data&category_id=${categoryId}&_ajax_nonce=<?php echo esc_js( wp_create_nonce( 'get_category_data' ) ); ?>`)
						.then(response => response.json())
						.then(data => {
							if (data.success) {
								const category = data.data;
								const sanitizedCategoryId = parseInt(category.id, 10);
								if (isNaN(sanitizedCategoryId)) {
									throw new Error('Invalid category ID');
								}
								document.getElementById('edit-category-id').value = sanitizedCategoryId;
								document.getElementById('category_id').textContent = 'ID: ' + sanitizedCategoryId;
								document.getElementById('edit_category_nonce').value = category.nonce;
								document.getElementById('edit_cat_name').value = sanitizeHTML(category.cat_name);
								document.getElementById('edit_cat_slug').value = sanitizeHTML(category.cat_slug);
								document.getElementById('edit_cat_description').value = sanitizeHTML(category.cat_description || '');
								document.getElementById('edit_cat_exclude_browser').checked = category.cat_exclude_browser == '1';

								// Populate parent category dropdown
								const parentSelect = document.getElementById('edit_cat_parent_id');
								parentSelect.innerHTML = '<option value="0">None</option>';
								if (category.parent_categories) {
									function addOptions(categories, depth = 0) {
										categories.forEach(cat => {
											const option = document.createElement('option');
											option.value = parseInt(cat.id, 10) || 0;
											option.textContent = '\u00A0'.repeat(depth * 3) + sanitizeHTML(cat.cat_name);
											option.selected = parseInt(cat.id, 10) === parseInt(category.cat_parent_id, 10);
											parentSelect.appendChild(option);

											if (cat.children && Array.isArray(cat.children) && cat.children.length > 0) {
												addOptions(cat.children, depth + 1);
											}
										});
									}
									addOptions(category.parent_categories);
								}
							} else {
								console.error('Failed to fetch category data:', data.message || 'Unknown error');
							}
						})
						.catch(error => console.error('Error:', error.message));
				}

				// Add this helper function for basic HTML sanitization
				function sanitizeHTML(str) {
					const temp = document.createElement('div');
					temp.textContent = str;
					return temp.innerHTML;
				}

				function closeEditModal() {
					const modal = document.getElementById('edit-modal');
					const overlay = document.getElementById('edit-modal-overlay');
					if (modal) {
						modal.style.display = 'none';
					}
					if (overlay) {
						overlay.style.display = 'none';
					}
				}

				// Update event listeners for closing the modal
				document.querySelectorAll('.close, .cancel-edit').forEach(button => {
					button.addEventListener('click', function (e) {
						e.preventDefault();
						closeEditModal();
					});
				});

				window.onclick = function (event) {
					if (event.target.classList.contains('edit-modal')) {
						closeEditModal();
					}
				}
			});
		</script>
		<?php
	}

	public function handle_add_category() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'You do not have sufficient permissions to access this page.' );
		}

		check_admin_referer( 'add_category', 'add_category_nonce' );

		$cat_name = isset( $_POST['cat_name'] ) ? sanitize_text_field( $_POST['cat_name'] ) : '';
		$cat_slug = isset( $_POST['cat_slug'] ) ? sanitize_title( $_POST['cat_slug'] ) : '';
		$cat_parent_id = isset( $_POST['cat_parent_id'] ) ? intval( $_POST['cat_parent_id'] ) : 0;
		$cat_description = isset( $_POST['cat_description'] ) ? sanitize_textarea_field( $_POST['cat_description'] ) : '';

		if ( empty( $cat_name ) ) {
			fvm_redirect_with_message( 'fvm_categories', 'error', 'Category name is required.' );
			exit;
		}

		$result = $this->category_manager->add_category( $cat_name, $cat_slug, $cat_parent_id, $cat_description );

		if ( ! $result['success'] ) {
			$error_message = isset( $result['message'] ) ? $result['message'] : 'Failed to add category.';
			fvm_redirect_with_message( 'fvm_categories', 'error', $error_message );
		} else {
			fvm_redirect_with_message( 'fvm_categories', 'success', 'Category added successfully.' );
		}
	}

	public function handle_update_category() {
		if ( isset( $_POST['update_category'] ) && isset( $_POST['category_id'] ) ) {
			$category_id = intval( $_POST['category_id'] );

			if ( ! isset( $_POST['edit_category_nonce'] ) || ! wp_verify_nonce( $_POST['edit_category_nonce'], 'edit_category_' . $category_id ) ) {
				wp_die( 'Security check failed.', 'Invalid Nonce', array( 'response' => 403 ) );
			}

			$cat_name = sanitize_text_field( $_POST['edit_cat_name'] );
			$cat_description = sanitize_textarea_field( $_POST['edit_cat_description'] );
			$cat_parent_id = intval( $_POST['edit_cat_parent_id'] );
			$cat_exclude_browser = isset( $_POST['edit_cat_exclude_browser'] ) ? 1 : 0;

			$update_result = $this->category_manager->update_category( $category_id, $cat_name, $cat_description, $cat_parent_id, $cat_exclude_browser );

			if ( $update_result ) {
				fvm_redirect_with_message( 'fvm_categories', 'success', 'Category updated successfully.' );
			} else {
				fvm_redirect_with_message( 'fvm_categories', 'error', 'Failed to update category.' );
			}
		}
	}

	public function handle_delete_category() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'You do not have sufficient permissions to access this page.' );
		}

		if ( ! isset( $_GET['category_id'] ) || ! isset( $_GET['_wpnonce'] ) ) {
			wp_die( 'Invalid request.' );
		}

		$category_id = intval( $_GET['category_id'] );

		if ( ! wp_verify_nonce( $_GET['_wpnonce'], 'delete_category_' . $category_id ) ) {
			wp_die( 'Security check failed.', 'Invalid Nonce', array( 'response' => 403 ) );
		}

		$delete_result = $this->category_manager->delete_category( $category_id );

		if ( $delete_result === false ) {
			fvm_redirect_with_message( 'fvm_categories', 'error', 'Failed to delete category.' );
		} else {
			fvm_redirect_with_message( 'fvm_categories', 'success', 'Category deleted successfully.' );
		}
	}

	public function handle_bulk_actions() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'You do not have sufficient permissions to access this page.' );
		}

		$this->setup_list_table();

		$action = $this->category_list_table->current_action();

		if ( $action && in_array( $action, [ 'delete', 'bulk-delete' ] ) ) {
			check_admin_referer( 'bulk-categories' );

			$category_ids = isset( $_POST['category'] ) ? (array) $_POST['category'] : [];

			if ( ! empty( $category_ids ) ) {
				$deleted_count = 0;
				foreach ( $category_ids as $category_id ) {
					if ( $this->category_manager->delete_category( intval( $category_id ) ) ) {
						$deleted_count++;
					}
				}

				if ( $deleted_count > 0 ) {
					$message = sprintf(
						/* translators: %s: number of deleted categories */
						_n( '%s category deleted successfully.', '%s categories deleted successfully.', $deleted_count, 'file-version-manager' ),
						number_format_i18n( $deleted_count )
					);
					fvm_redirect_with_message( 'fvm_categories', 'success', $message );
				} else {
					fvm_redirect_with_message( 'fvm_categories', 'error', 'No categories were deleted.' );
				}
			}
		}
	}
}