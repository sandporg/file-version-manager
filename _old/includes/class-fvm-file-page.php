<?php

namespace FVM\FileVersionManager;

class FVM_File_Page {
	private $file_manager;
	private $wp_list_table;
	private $file_list_table;

	public function __construct( FVM_File_Manager $file_manager ) {
		$this->file_manager = $file_manager;
	}

	public function init() {
		add_action( 'admin_menu', [ $this, 'add_admin_menu' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_styles' ] );
		add_action( 'load-toplevel_page_fvm_files', [ $this, 'setup_list_table' ] );
		add_action( 'load-toplevel_page_fvm_files', [ $this, 'handle_file_upload' ] );
		add_action( 'load-toplevel_page_fvm_files', [ $this, 'handle_file_deletion' ] );
		add_action( 'load-toplevel_page_fvm_files', [ $this, 'handle_file_update' ] );
		add_action( 'load-toplevel_page_fvm_files', [ $this, 'handle_bulk_actions' ] );
	}

	public function add_admin_menu() {
		add_menu_page(
			'File Version Manager',
			'Files',
			'manage_options',
			'fvm_files',
			array( $this, 'display_admin_page' ),
			'dashicons-media-default',
			11
		);

		add_submenu_page(
			'fvm_files',
			'All Files',
			'All Files',
			'manage_options',
			'fvm_files',
			array( $this, 'display_admin_page' )
		);
	}

	/**
	 * Enqueue scripts and styles for the admin page
	 * @return void
	 */
	public function enqueue_styles() {
		if ( function_exists( 'get_current_screen' ) ) {
			$screen = get_current_screen();
			if ( $screen && $screen->id === 'toplevel_page_fvm_files' ) {
				wp_enqueue_style( 'file-version-manager-styles', plugin_dir_url( dirname( __FILE__ ) ) . 'assets/css/fvm-admin.css', [], '1.0.0' );
			}
		}
	}

	public function setup_list_table() {
		$this->wp_list_table = new FVM_File_List_Table( $this->file_manager );
	}

	/**
	 * Display admin page
	 * @return void
	 */
	public function display_admin_page() {

		$this->setup_list_table();

		ob_start();

		$this->wp_list_table->prepare_items();
		$this->handle_file_upload();
		$this->handle_file_deletion();
		$this->handle_file_update();

		?>

		<div class="wrap">
			<h1>File Version Manager</h1>

			<?php
			if ( isset( $_GET['update'] ) && isset( $_GET['message'] ) ) {
				$status = $_GET['update'] === 'success' ? 'updated' : 'error';
				$message = urldecode( $_GET['message'] );
				echo "<div class='notice " . esc_attr( $status ) . " is-dismissible'><p>" . esc_html( $message ) . "</p></div>";
			}
			?>

			<div id="fvm-upload-container" class="fvm-upload-container">
				<form method="post" enctype="multipart/form-data" id="fvm-upload-form">
					<?php wp_nonce_field( 'fvm_file_upload', 'fvm_file_upload_nonce' ); ?>
					<div class="fvm-dropzone">
						<div class="upload-ui">
							<h2 class="fvm-upload-instructions">Drop files to upload</h2>
							<p class="fvm-upload-instructions">or</p>
							<div class="fvm-file-name-container" style="display: none;">
								<p id="fvm-file-name"></p>
								<span class="fvm-clear-file dashicons dashicons-no-alt"></span>
							</div>
							<input type="file" name="file[]" id="fvm-file-input" style="display: none;" multiple>
							<button type="button" id="fvm-select-file" class="browser button button-hero">Select Files</button>
							<input type="submit" name="fvm_upload_file" id="fvm-upload-button" value="Upload Files"
								class="button button-primary button-hero" style="display: none;">
						</div>
						<div class="post-upload-ui" id="post-upload-info">
							<p class="fvm-upload-instructions">
								Maximum upload file size: <?php echo esc_html( size_format( wp_max_upload_size() ) ); ?>.

								<?php
								// Check if Big File Uploads plugin is active
								if ( class_exists( 'BigFileUploads' ) ) {
									$bfu_settings_url = admin_url( 'options-general.php?page=big_file_uploads' );
									echo ' <small><a href="' . esc_url( $bfu_settings_url ) . '" style="text-decoration:none;">' . esc_html__( 'Change', 'file-version-manager' ) . '</a></small>';
								}
								?>

							</p>
						</div>
					</div>
				</form>
			</div>

			<div id="edit-modal" class="edit-modal" style="display:none;">
				<form id="edit-form" method="post" enctype="multipart/form-data">

					<?php wp_nonce_field( 'edit_file', 'edit_file_nonce' ); ?>
					<input type="hidden" name="file_id" id="edit-file-id" value="">

					<div class="edit-modal-content-container">
						<div class="edit-modal-content">
							<span class="close">&times;</span>

							<div class="fvm-edit-modal-title">
								<h2>Edit File</h2>
								<h3 id="file_id"></h3>
							</div>

							<div id="fvm-dropzone-edit" class="fvm-dropzone">
								<div class="upload-ui">
									<h2 class="fvm-upload-instructions">Drop files to upload</h2>
									<p class="fvm-upload-instructions">or</p>
									<div class="fvm-file-name-container" style="display: none;">
										<p id="fvm-edit-file-name"></p>
										<span class="fvm-clear-file dashicons dashicons-no-alt"></span>
									</div>
									<input type="file" name="new_file" id="new_file" style="display: none;">
									<button type="button" id="fvm-edit-select-file" class="browser button button-hero">Select
										File</button>
								</div>
								<div class="post-upload-ui" id="post-upload-info">
									<p class="fvm-upload-instructions">
										Maximum upload file size:
										<?php echo esc_html( size_format( wp_max_upload_size() ) ); ?>.

										<?php
										// Check if Big File Uploads plugin is active
										if ( class_exists( 'BigFileUploads' ) ) {
											$bfu_settings_url = admin_url( 'options-general.php?page=big_file_uploads' );
											echo ' <small><a href="' . esc_url( $bfu_settings_url ) . '" style="text-decoration:none;">' . esc_html__( 'Change', 'file-version-manager' ) . '</a></small>';
										}
										?>
									</p>
								</div>
							</div>

							<table class="form-table">
								<tr>
									<th scope="row"><label for="file_name">File Name</label></th>
									<td>
										<input type="text" id="file_name" name="file_name" value="" class="regular-text"
											readonly disabled>
									</td>
								</tr>
								<tr>
									<th scope="row"><label for="file_display_name">Display Name</label></th>
									<td>
										<input type="text" name="file_display_name" id="file_display_name" value=""
											class="regular-text">
									</td>
								</tr>
								<tr>
									<th scope="row"><label for="file_description">Description</label></th>
									<td>
										<textarea name="file_description" id="file_description" class="regular-text"
											rows="3"></textarea>
									</td>
								</tr>
								<tr>
									<th scope="row"><label for="file_categories">Categories</label></th>
									<td>
										<div class="fvm-file-categories-container">
											<div class="fvm-file-categories-container-inner" id="file_categories">
												<!-- Categories will be populated dynamically -->
											</div>
										</div>
									</td>
								</tr>
								<?php if ( ! get_option( 'fvm_auto_increment_version', 1 ) ) : ?>
									<tr>
										<th scope="row"><label for="file_version">Version</label></th>
										<td>
											<input type="number" step="0.1" min="0" name="file_version" id="file_version" value=""
												class="regular-text">
										</td>
									</tr>
								<?php endif; ?>
								<tr>
									<th scope="row"><label for="file_url">File URL</label></th>
									<td>
										<input type="text" id="file_url" name="file_url" value="" class="regular-text" readonly
											disabled>
									</td>
								</tr>
								<tr>
									<th scope="row"><label for="md5_hash">MD5 Hash</label></th>
									<td>
										<input type="text" id="md5_hash" name="md5_hash" value="" class="regular-text" readonly
											disabled>
									</td>
								</tr>
								<tr>
									<th scope="row"><label for="sha256_hash">SHA256 Hash</label></th>
									<td>
										<input type="text" id="sha256_hash" name="sha256_hash" value="" class="regular-text"
											readonly disabled>
									</td>
								</tr>
							</table>
						</div>
						<div class="fvm-edit-modal-footer">
							<div class="fvm-edit-modal-footer-inner">
								<label class="switch">
									<input type="checkbox" name="file_offline" id="file_offline" value="1">
									<span class="slider round"></span>
								</label>
								<span>Disabled</span>
							</div>
							<p class="submit">
								<button type="button" class="button cancel-edit">Cancel</button>
								<input type="submit" name="update_file" id="update_file" class="button button-primary"
									value="Update File">
							</p>
						</div>
					</div>
				</form>
			</div>
			<div id="edit-modal-overlay" class="edit-modal-overlay"></div>

			<form method="get">
				<input type="hidden" name="page" value="fvm_files" />
				<?php
				$this->wp_list_table->search_box( 'Search Files', 'search' );
				?>
			</form>

			<form method="post">
				<?php
				wp_nonce_field( 'bulk-files' );
				$this->wp_list_table->display_bulk_action_result();
				$this->wp_list_table->display();
				?>
			</form>

			<script type="text/javascript">
				document.addEventListener('DOMContentLoaded', function () {
					const setupDropzone = (formId, fileInputId, selectFileBtnId, uploadButtonId, fileNameDisplayId) => {
						const form = document.getElementById(formId);
						const dropzone = form.querySelector('.fvm-dropzone');
						const fileInput = form.querySelector('#' + fileInputId);
						const selectFileBtn = form.querySelector('#' + selectFileBtnId);
						const uploadButton = uploadButtonId ? form.querySelector('#' + uploadButtonId) : null;
						const fileNameDisplay = form.querySelector('#' + fileNameDisplayId);
						const uploadInstructions = form.querySelectorAll('.fvm-upload-instructions');
						const postUploadInfo = form.querySelector('.post-upload-ui');
						const fileNameContainer = form.querySelector('.fvm-file-name-container');
						const clearFileBtn = form.querySelector('.fvm-clear-file');

						// Drag and drop functionality
						['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
							dropzone.addEventListener(eventName, preventDefaults, false);
						});

						function preventDefaults(e) {
							e.preventDefault();
							e.stopPropagation();
						}

						['dragenter', 'dragover'].forEach(eventName => {
							dropzone.addEventListener(eventName, () => dropzone.classList.add('highlight'), false);
						});

						['dragleave', 'drop'].forEach(eventName => {
							dropzone.addEventListener(eventName, () => dropzone.classList.remove('highlight'), false);
						});

						dropzone.addEventListener('drop', handleDrop, false);

						function handleDrop(e) {
							const dt = e.dataTransfer;
							const files = dt.files;
							handleFiles(files);
						}

						selectFileBtn.addEventListener('click', () => {
							fileInput.click();
						});

						fileInput.addEventListener('change', () => {
							handleFiles(fileInput.files);
						});

						function handleFiles(files) {
							if (files.length > 0) {
								const fileNames = Array.from(files).map(file => file.name).join(', ');
								fileNameDisplay.textContent = files.length > 1 ? `${files.length} files selected` : fileNames;
								fileNameContainer.style.display = 'flex';
								selectFileBtn.style.display = 'none';
								if (uploadButton) {
									uploadButton.style.display = 'inline-block';
									uploadButton.value = files.length > 1 ? 'Upload Files' : 'Upload File';
								}
								// Hide upload instructions and post-upload info
								uploadInstructions.forEach(el => el.style.display = 'none');
								if (postUploadInfo) {
									postUploadInfo.style.display = 'none';
								}

								// Create a new FileList object
								const dataTransfer = new DataTransfer();
								Array.from(files).forEach(file => dataTransfer.items.add(file));
								fileInput.files = dataTransfer.files;
							}
						}

						if (clearFileBtn) {
							clearFileBtn.addEventListener('click', () => {
								fileInput.value = '';
								fileNameContainer.style.display = 'none';
								selectFileBtn.style.display = 'inline-block';
								if (uploadButton) {
									uploadButton.style.display = 'none';
								}
								// Show upload instructions and post-upload info
								uploadInstructions.forEach(el => el.style.display = 'block');
								if (postUploadInfo) {
									postUploadInfo.style.display = 'block';
								}
							});
						}
					};

					// Setup main upload form
					setupDropzone('fvm-upload-form', 'fvm-file-input', 'fvm-select-file', 'fvm-upload-button', 'fvm-file-name');

					// Setup edit modal dropzone
					setupDropzone('edit-form', 'new_file', 'fvm-edit-select-file', null, 'fvm-edit-file-name');
				});
			</script>

			<script type="text/javascript">
				document.addEventListener('DOMContentLoaded', function () {
					document.querySelectorAll('.copy-shortcode').forEach(button => {
						button.addEventListener('click', function (e) {
							e.preventDefault();
							const shortcode = this.getAttribute('data-shortcode');
							copyToClipboard(shortcode, this);
						});
					});

					function copyToClipboard(text, button) {
						const textArea = document.createElement("textarea");
						textArea.value = text;
						document.body.appendChild(textArea);
						textArea.select();

						try {
							document.execCommand('copy');
							const originalText = button.textContent;
							button.textContent = 'Copied!';
							setTimeout(function () {
								button.textContent = originalText;
							}, 2000);
						} catch (err) {
							console.error('Unable to copy to clipboard', err);
						}

						document.body.removeChild(textArea);
					}

					document.querySelectorAll('.edit-file').forEach(link => {
						link.addEventListener('click', function (e) {
							e.preventDefault();
							const fileId = this.getAttribute('data-file-id');
							showEditModal(fileId);
							populateEditModal(fileId);
						});
					});

					function showEditModal(fileId) {
						const modal = document.getElementById('edit-modal');
						const overlay = document.getElementById('edit-modal-overlay');
						modal.style.display = 'flex';
						overlay.style.display = 'block';

						// Clear previous data and show loading indicators
						document.getElementById('file_id').textContent = 'Loading...';
						document.getElementById('new_file').value = '';
						document.getElementById('file_name').value = 'Loading...';
						document.getElementById('file_display_name').value = 'Loading...';
						document.getElementById('file_description').value = 'Loading...';
						document.getElementById('file_url').value = 'Loading...';
						document.getElementById('md5_hash').value = 'Loading...';
						document.getElementById('sha256_hash').value = 'Loading...';
						document.getElementById('file_categories').innerHTML = 'Loading...';
						if (document.getElementById('file_version')) {
							document.getElementById('file_version').value = '';
						}
						document.getElementById('file_offline').checked = false;
					}

					// Function to populate the edit modal
					function populateEditModal(fileId) {
						fetch(`<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>?action=get_file_data&file_id=${fileId}&_ajax_nonce=<?php echo esc_js( wp_create_nonce( 'get_file_data' ) ); ?>`)
							.then(response => response.json())
							.then(data => {
								if (data.success) {
									const file = data.data;
									document.getElementById('edit-file-id').value = file.id;
									document.getElementById('file_id').textContent = 'ID: ' + file.id;
									document.getElementById('edit_file_nonce').value = file.nonce;
									document.getElementById('file_name').value = file.file_name;
									document.getElementById('file_display_name').value = file.file_display_name || '';
									document.getElementById('file_description').value = file.file_description || '';
									document.getElementById('file_url').value = file.file_url;
									document.getElementById('md5_hash').value = file.file_hash_md5 || '';
									document.getElementById('sha256_hash').value = file.file_hash_sha256 || '';
									if (document.getElementById('file_version')) {
										document.getElementById('file_version').value = file.file_version;
									}
									document.getElementById('file_offline').checked = file.file_offline == '1';

									// Populate categories
									const categoriesContainer = document.getElementById('file_categories');
									categoriesContainer.innerHTML = '';

									// Build category tree
									const buildCategoryTree = (categories, parentId = 0) => {
										return categories
											.filter(category => category.parent_id == parentId)
											.map(category => ({
												...category,
												children: buildCategoryTree(categories, category.id)
											}));
									};

									// Render category tree
									const renderCategoryTree = (categories, depth = 0) => {
										categories.forEach(category => {
											const label = document.createElement('label');
											label.style.paddingLeft = `${depth * 10}px`;
											label.style.display = 'block';
											label.style.marginBottom = '5px';

											const checkbox = document.createElement('input');
											checkbox.type = 'checkbox';
											checkbox.name = 'file_categories[]';
											checkbox.value = category.id;
											checkbox.checked = category.checked;

											label.appendChild(checkbox);
											label.appendChild(document.createTextNode(` ${category.name}`));
											categoriesContainer.appendChild(label);

											if (category.children && category.children.length > 0) {
												renderCategoryTree(category.children, depth + 1);
											}
										});
									};

									// Build and render the category tree
									const categoryTree = buildCategoryTree(file.categories);
									renderCategoryTree(categoryTree);
								} else {
									console.error('Failed to fetch file data');
								}
							})
							.catch(error => console.error('Error:', error));
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

						// Reset the file input and related elements
						const fileInput = document.getElementById('new_file');
						const fileNameDisplay = document.getElementById('fvm-edit-file-name');
						const fileNameContainer = document.querySelector('#edit-form .fvm-file-name-container');
						const selectFileBtn = document.getElementById('fvm-edit-select-file');
						const uploadInstructions = document.querySelectorAll('#edit-form .fvm-upload-instructions');
						const postUploadInfo = document.querySelector('#edit-form .post-upload-ui');

						if (fileInput) {
							fileInput.value = '';
						}
						if (fileNameContainer) {
							fileNameContainer.style.display = 'none';
						}
						if (selectFileBtn) {
							selectFileBtn.style.display = 'inline-block';
						}
						if (uploadInstructions) {
							uploadInstructions.forEach(el => el.style.display = 'block');
						}
						if (postUploadInfo) {
							postUploadInfo.style.display = 'block';
						}
						if (fileNameDisplay) {
							fileNameDisplay.textContent = '';
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
		</div>
		<?php
		ob_end_flush();
	}

	/**
	 * Get file icon class
	 * @param string $file_type
	 * @return string
	 */
	private function get_file_icon_class( $file_type ) {
		$icon_map = [ 
			'image' => 'dashicons-format-image',
			'audio' => 'dashicons-format-audio',
			'video' => 'dashicons-format-video',
			'application/pdf' => 'dashicons-pdf',
			'text' => 'dashicons-text',
			'application/msword' => 'dashicons-media-document',
			'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'dashicons-media-document',
			'application/vnd.ms-excel' => 'dashicons-spreadsheet',
			'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'dashicons-spreadsheet',
			'application/zip' => 'dashicons-media-archive',
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

	/**
	 * Handle file upload, redirect with message
	 * 
	 * @return void
	 */
	public function handle_file_upload() {
		if ( isset( $_POST['fvm_upload_file'] ) ) {
			check_admin_referer( 'fvm_file_upload', 'fvm_file_upload_nonce' );

			if ( ! isset( $_FILES['file'] ) || ! is_array( $_FILES['file'] ) ) {
				fvm_redirect_with_message( 'fvm_files', 'error', 'No file data received.' );
				return;
			}

			if ( empty( $_FILES['file']['name'][0] ) ) {
				fvm_redirect_with_message( 'fvm_files', 'error', 'No file selected for upload.' );
				return;
			}

			$upload_results = $this->file_manager->upload_files( $_FILES['file'] );

			if ( ! empty( $upload_results ) ) {
				$count = count( $upload_results );
				/* translators: %s: number of files uploaded */
				$message = sprintf( _n( '%s file uploaded successfully.', '%s files uploaded successfully.', $count, 'file-version-manager' ), number_format_i18n( $count ) );
				fvm_redirect_with_message( 'fvm_files', 'success', $message );
			} else {
				fvm_redirect_with_message( 'fvm_files', 'error', 'Error uploading files. Please check file permissions and PHP upload settings.' );
			}
		}
	}

	/**
	 * Handle file deletion, redirect with message
	 * 
	 * @return void
	 */
	public function handle_file_deletion() {
		if ( isset( $_GET['action'] ) && $_GET['action'] === 'delete' && isset( $_GET['file_id'] ) ) {
			$file_id = intval( $_GET['file_id'] );
			$delete_result = $this->file_manager->handleFileDeletion( $file_id, 'single' );

			if ( $delete_result ) {
				fvm_redirect_with_message( 'fvm_files', 'success', 'File deleted successfully.' );
			} else {
				fvm_redirect_with_message( 'fvm_files', 'error', 'Error deleting file.' );
			}
		}
	}

	/**
	 * Handle file update, redirect with message
	 * 
	 * @return void
	 */
	public function handle_file_update() {
		if ( isset( $_POST['update_file'] ) && isset( $_POST['file_id'] ) ) {
			$file_id = intval( $_POST['file_id'] );

			check_admin_referer( 'edit_file_' . $file_id, 'edit_file_nonce' );

			$file_id = intval( $_POST['file_id'] );
			$new_file = isset( $_FILES['new_file'] ) && ! empty( $_FILES['new_file']['tmp_name'] ) ? $_FILES['new_file'] : null;
			$file_version = isset( $_POST['file_version'] ) ? sanitize_text_field( $_POST['file_version'] ) : '';
			$file_display_name = isset( $_POST['file_display_name'] ) ? sanitize_text_field( $_POST['file_display_name'] ) : '';
			$file_description = isset( $_POST['file_description'] ) ? sanitize_textarea_field( $_POST['file_description'] ) : '';
			$file_categories = isset( $_POST['file_categories'] ) ? array_map( 'intval', $_POST['file_categories'] ) : array();
			$file_offline = isset( $_POST['file_offline'] ) ? 1 : 0;

			// If auto-increment is enabled and a new file is uploaded, pass an empty version
			$auto_increment_version = get_option( 'fvm_auto_increment_version', 1 );
			if ( $auto_increment_version && $new_file ) {
				$file_version = '';
			}

			$update_result = $this->file_manager->update_file( $file_id, $new_file, $file_version, $file_display_name, $file_description, $file_categories, $file_offline );

			if ( $update_result ) {
				$message = 'File updated successfully.';
				if ( $new_file ) {
					$message .= ' New file uploaded.';
				}
				fvm_redirect_with_message( 'fvm_files', 'success', $message );
			} else {
				$error_message = 'Error updating file.';
				if ( $new_file ) {
					$error_message .= ' File upload failed.';
				}
				fvm_redirect_with_message( 'fvm_files', 'error', $error_message );
			}
		}
	}

	/**
	 * Handle bulk actions
	 * 
	 * @return void
	 */
	public function handle_bulk_actions() {
		$this->setup_list_table();
		$action = $this->wp_list_table->current_action();

		if ( $action && in_array( $action, [ 'delete', 'bulk-delete' ] ) ) {
			// Verify bulk action nonce
			check_admin_referer( 'bulk-files' );

			$file_ids = isset( $_REQUEST['file'] ) ? (array) $_REQUEST['file'] : [];
			$file_ids = array_map( 'absint', $file_ids );

			if ( ! empty( $file_ids ) ) {
				$deleted_count = 0;
				foreach ( $file_ids as $file_id ) {
					// Pass true to indicate this is a bulk action
					if ( $this->file_manager->handleFileDeletion( $file_id, 'bulk' ) ) {
						$deleted_count++;
					}
				}

				if ( $deleted_count > 0 ) {
					$message = sprintf(
						_n( '%s file deleted successfully.', '%s files deleted successfully.', $deleted_count, 'file-version-manager' ),
						number_format_i18n( $deleted_count )
					);
					fvm_redirect_with_message( 'fvm_files', 'success', $message );
				} else {
					fvm_redirect_with_message( 'fvm_files', 'error', 'No files were deleted.' );
				}
			}
		}
	}
}