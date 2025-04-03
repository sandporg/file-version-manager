<?php
namespace FVM\FileVersionManager;

class FVM_Shortcode {
	private $wpdb;
	private $file_table_name;
	private $cat_table_name;
	private $rel_table_name;
	private $plugin_url;

	public function __construct( \wpdb $wpdb, $plugin_url ) {
		$this->wpdb = $wpdb;
		$this->plugin_url = $plugin_url;
		$this->file_table_name = esc_sql( $wpdb->prefix . FVM_FILE_TABLE_NAME );
		$this->cat_table_name = esc_sql( $wpdb->prefix . FVM_CAT_TABLE_NAME );
		$this->rel_table_name = esc_sql( $wpdb->prefix . FVM_REL_TABLE_NAME );
	}

	public function init() {
		add_shortcode( 'fvm', [ $this, 'shortcode' ] );
		add_action( 'wp_enqueue_scripts', [ $this, 'custom_shortcode_scripts' ] );
	}

	function custom_shortcode_scripts() {
		global $post;
		if ( isset( $post ) && is_a( $post, 'WP_Post' ) && has_shortcode( $post->post_content, 'fvm' ) ) {
			wp_enqueue_style( 'fvm-shortcode', $this->plugin_url . 'assets/css/fvm-shortcode.css', array(), '1.0.0', 'all' );
		}
	}

	/**
	 * Shortcode handler function
	 */
	public function shortcode( $atts ) {
		$atts = shortcode_atts( array(
			'tag' => '',
			'id' => 0,
			'tpl' => '',
			'title' => false,
			'show_description' => true,
			'show_version' => true,
			'show_size' => true,
			'show_categories' => true,
		), $atts, 'fvm' );

		if ( $atts['tag'] === 'file' || empty( $atts['tag'] ) ) {
			$ids = array_map( 'intval', explode( ',', $atts['id'] ) );
			$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

			$files = $this->wpdb->get_results( $this->wpdb->prepare(
				"SELECT * FROM $this->file_table_name WHERE id IN ($placeholders) AND file_offline != 1",
				$ids
			) );

			return $this->file( $files, $atts );

		} elseif ( $atts['tag'] === 'category' || $atts['tag'] === 'list' ) {
			$ids = array_map( 'intval', explode( ',', $atts['id'] ) );
			$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

			$files = $this->wpdb->get_results( $this->wpdb->prepare(
				"SELECT DISTINCT f.* 
				FROM $this->file_table_name f
				JOIN $this->rel_table_name r ON f.id = r.file_id
				WHERE r.category_id IN ($placeholders) AND f.file_offline != 1",
				$ids
			) );

			return $this->category( $files, $atts );
		}
	}

	/**
	 * Handles the file shortcodes.
	 */
	private function file( $files, $atts ) {
		if ( ! empty( $files ) ) {
			if ( $atts['tpl'] === 'urlonly' || $atts['tpl'] === 'url' ) {
				return esc_url( add_query_arg( 'file', $files[0]->file_name, home_url() ) );
			} elseif ( $atts['tpl'] === 'table' ) {
				return $this->table( $files, $atts );
			} else {
				if ( count( $files ) === 1 ) {
					$file = $files[0];
					ob_start();
					?>
					<a href="<?php echo esc_url( add_query_arg( 'file', $file->file_name, home_url() ) ); ?>" target="_blank">
						<?php echo esc_html( ! empty( $file->file_display_name ) ? $file->file_display_name : $file->file_name ); ?>
					</a>
					<?php if ( $file->file_type ) : ?>
						<span class="fvm-item-size uppercase"> <?php echo esc_html( $file->file_type ); ?></span>
					<?php endif; ?>
					<span class="fvm-item-size"> (<?php echo esc_html( size_format( $file->file_size ) ); ?>)</span>
					<?php
					return ob_get_clean();
				} else {
					return $this->list( $files );
				}
			}
		} else {
			return '<p>File(s) no longer available.</p>';
		}
	}

	/**
	 * Handles the category shortcodes.
	 */
	private function category( $files, $atts ) {

		if ( $atts['tpl'] === 'toggle' ) {
			return $this->toggle( $files, $atts );
		}

		if ( ! empty( $files ) ) {
			if ( $atts['tpl'] === 'thumbnail-grid-btns' || $atts['tpl'] === 'grid' ) {
				return $this->grid( $files );
			} elseif ( $atts['tpl'] === 'table' ) {
				return $this->table( $files, $atts );
			} else {
				return $this->list( $files );
			}
		} else {
			return '<p>No files available.</p>';
		}
	}

	/**
	 * List
	 * -- Renders a list of files with their names, sizes, and types.
	 */
	private function list( $files ) {
		ob_start();
		?>
		<ul class="fvm-file-list">
			<?php foreach ( $files as $file ) : ?>
				<li>
					<?php
					$file_type = $file->file_type;
					$dashicon_class = '';

					switch ( $file_type ) {
						case 'jpeg':
						case 'jpg':
						case 'png':
						case 'gif':
						case 'webp':
						case 'svg+xml':
							$dashicon_class = 'dashicons-format-image';
							break;
						case 'pdf':
							$dashicon_class = 'dashicons-media-document';
							break;
						case 'zip':
							$dashicon_class = 'dashicons-archive';
							break;
						case 'txt':
							$dashicon_class = 'dashicons-media-text';
							break;
						default:
							$dashicon_class = 'dashicons-media-default';
							break;
					}
					?>
					<span class="dashicons <?php echo esc_attr( $dashicon_class ); ?>"></span>
					<a href="<?php echo esc_url( add_query_arg( 'file', $file->file_name, home_url() ) ); ?>" target="_blank"
						rel="noopener noreferrer">
						<?php echo esc_html( ! empty( $file->file_display_name ) ? $file->file_display_name : $file->file_name ); ?>
					</a>
					<?php if ( $file->file_type ) : ?>
						<span class="fvm-item-size uppercase"> <?php echo esc_html( $file->file_type ); ?></span>
					<?php endif; ?>
					<span class="fvm-item-size"> (<?php echo esc_html( size_format( $file->file_size ) ); ?>)</span>
				</li>
			<?php endforeach; ?>
		</ul>
		<?php
		return ob_get_clean();
	}

	/**
	 * Grid
	 * -- Renders a grid of files with their names, sizes, and types.
	 */
	private function grid( $files ) {
		ob_start();
		?>
		<div class="fvm-grid">
			<?php foreach ( $files as $file ) : ?>
				<div class="fvm-grid-item">
					<?php
					$file_type = $file->file_type;
					$dashicon_class = '';

					switch ( $file_type ) {
						case 'image/jpeg':
						case 'image/png':
						case 'image/gif':
						case 'image/webp':
						case 'image/svg+xml':
							$dashicon_class = 'dashicons-format-image';
							break;
						case 'application/pdf':
							$dashicon_class = 'dashicons-media-document';
							break;
						case 'application/zip':
							$dashicon_class = 'dashicons-archive';
							break;
						case 'text/plain':
							$dashicon_class = 'dashicons-media-text';
							break;
						default:
							$dashicon_class = 'dashicons-media-default';
							break;
					}
					?>
					<div class="fvm-grid-item-content">
						<div class="fvm-grid-item-icon">
							<span class="dashicons <?php echo esc_attr( $dashicon_class ); ?>"></span>
						</div>
						<p class="fvm-grid-item-title">
							<?php echo esc_html( ! empty( $file->file_display_name ) ? $file->file_display_name : $file->file_name ); ?>
						</p>
						<div class="fvm-grid-item-meta">
							<?php if ( $file->file_type ) : ?>
								<span class="fvm-item-size uppercase"><?php echo esc_html( $file->file_type ); ?> </span>
							<?php endif; ?>
							<span class="fvm-item-size">(<?php echo esc_html( size_format( $file->file_size ) ); ?>)</span>
						</div>
					</div>
					<a class="fvm-grid-item-btn"
						href="<?php echo esc_url( add_query_arg( 'file', $file->file_name, home_url() ) ); ?>" target="_blank"
						rel="noopener noreferrer">View/Download</a>
				</div>
			<?php endforeach; ?>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Table
	 * -- Renders a table of files with their names, sizes, and types.
	 */
	private function table( $files, $atts ) {
		$files = is_array( $files ) ? $files : array( $files );

		$show_description = isset( $atts['show_description'] ) ? filter_var( $atts['show_description'], FILTER_VALIDATE_BOOLEAN ) : true;
		$show_version = isset( $atts['show_version'] ) ? filter_var( $atts['show_version'], FILTER_VALIDATE_BOOLEAN ) : true;
		$show_size = isset( $atts['show_size'] ) ? filter_var( $atts['show_size'], FILTER_VALIDATE_BOOLEAN ) : true;
		$show_categories = isset( $atts['show_categories'] ) ? filter_var( $atts['show_categories'], FILTER_VALIDATE_BOOLEAN ) : true;

		$has_description = $show_description && array_reduce( $files, function ($carry, $file) {
			return $carry || ! empty( $file->file_description );
		}, false );

		// Fetch categories for all files in a single query
		$file_ids = array_column( $files, 'id' );
		$placeholders = implode( ',', array_fill( 0, count( $file_ids ), '%d' ) );
		$categories = $this->wpdb->get_results( $this->wpdb->prepare(
			"SELECT r.file_id, c.cat_name 
            FROM $this->rel_table_name r
            JOIN $this->cat_table_name c ON r.category_id = c.id
            WHERE r.file_id IN ($placeholders)",
			$file_ids
		) );

		// Organize categories by file_id
		$file_categories = [];
		foreach ( $categories as $category ) {
			$file_categories[ $category->file_id ][] = $category->cat_name;
		}

		ob_start();
		?>
		<table class="fvm-file-table">
			<thead>
				<tr>
					<th><?php echo $atts['title'] ? esc_html( $atts['title'] ) : 'File Name'; ?></th>
					<?php if ( $has_description ) : ?>
						<th>Description</th>
					<?php endif; ?>
					<?php if ( $show_version ) : ?>
						<th>Version</th>
					<?php endif; ?>
					<?php if ( $show_size ) : ?>
						<th>File Size</th>
					<?php endif; ?>
					<?php if ( $show_categories ) : ?>
						<th>Categories</th>
					<?php endif; ?>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $files as $file ) : ?>
					<tr>
						<td><a href="<?php echo esc_url( add_query_arg( 'file', $file->file_name, home_url() ) ); ?>"
								target="_blank"
								rel="noopener noreferrer"><?php echo esc_html( ! empty( $file->file_display_name ) ? $file->file_display_name : $file->file_name ); ?></a>
						</td>
						<?php if ( $has_description ) : ?>
							<td><?php echo esc_html( $file->file_description ); ?></td>
						<?php endif; ?>
						<?php if ( $show_version ) : ?>
							<td><?php echo esc_html( $file->file_version ); ?></td>
						<?php endif; ?>
						<?php if ( $show_size ) : ?>
							<td><?php echo esc_html( size_format( $file->file_size, 1 ) ); ?></td>
						<?php endif; ?>
						<?php if ( $show_categories ) : ?>
							<td><?php echo esc_html( implode( ', ', $file_categories[ $file->id ] ?? [] ) ); ?></td>
						<?php endif; ?>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
		return ob_get_clean();
	}

	/**
	 * Toggle
	 * -- Renders a toggle of categories with it's associated files
	 */
	public function toggle( $files, $atts ) {
		$category_id = intval( $atts['id'] );
		$title = isset( $atts['title'] ) ? sanitize_text_field( $atts['title'] ) : '';
		$max_depth = isset( $atts['max_depth'] ) ? intval( $atts['max_depth'] ) : 5;
		$show_version = isset( $atts['show_version'] ) ? filter_var( $atts['show_version'], FILTER_VALIDATE_BOOLEAN ) : true;
		$show_description = isset( $atts['show_description'] ) ? filter_var( $atts['show_description'], FILTER_VALIDATE_BOOLEAN ) : true;

		if ( $category_id === 0 ) {
			return '<p>Invalid category ID.</p>';
		}

		$categories = $this->get_category_hierarchy( $category_id, $max_depth );
		$direct_files = $this->get_direct_files( $category_id );

		if ( empty( $categories ) && empty( $direct_files ) ) {
			return '<p>No files available.</p>';
		}

		ob_start();
		?>
		<?php if ( ! empty( $title ) ) : ?>
			<h2 class="fvm-toggle-title"><?php echo esc_html( $title ); ?></h2>
		<?php endif; ?>
		<ul id="fvm-toggle-<?php echo esc_attr( $category_id ); ?>" class="fvm-toggle-container">
			<?php echo $this->render_category_toggle( $categories, $direct_files, 0, $category_id, $show_version, $show_description ); ?>
		</ul>

		<script>
			document.addEventListener("DOMContentLoaded", function () {
				const toggleContainer = document.getElementById('fvm-toggle-<?php echo esc_js( $category_id ); ?>');
				toggleContainer.addEventListener('click', function (e) {
					const toggle = e.target.closest('.fvm-toggle-category-title');
					if (toggle) {
						const content = toggle.nextElementSibling;
						const isExpanded = toggle.getAttribute('aria-expanded') === 'true';
						toggle.setAttribute('aria-expanded', !isExpanded);
						content.setAttribute('aria-hidden', isExpanded);
						content.classList.toggle('active');
						content.style.display = isExpanded ? 'none' : 'block';
					}
				});
			});
		</script>
		<?php
		return ob_get_clean();
	}

	private function get_category_hierarchy( $category_id, $max_depth = 5 ) {
		$query = $this->wpdb->prepare( "
		WITH RECURSIVE category_tree AS (
			SELECT c.*, 0 AS level
			FROM {$this->cat_table_name} c
			WHERE c.cat_parent_id = %d
			
			UNION ALL
			
			SELECT c.*, ct.level + 1
			FROM {$this->cat_table_name} c
			JOIN category_tree ct ON c.cat_parent_id = ct.id
			WHERE ct.level < %d
		)
		SELECT ct.*, f.id AS file_id, f.file_name, f.file_display_name, f.file_url, f.file_size, 
			   f.file_type, f.file_version, f.file_description
		FROM category_tree ct
		LEFT JOIN {$this->rel_table_name} r ON ct.id = r.category_id
		LEFT JOIN {$this->file_table_name} f ON r.file_id = f.id AND f.file_offline != 1
		ORDER BY ct.level, ct.cat_name
		LIMIT 1000
		", $category_id, $max_depth );

		return $this->wpdb->get_results( $query );
	}

	private function get_direct_files( $category_id ) {
		return $this->wpdb->get_results( $this->wpdb->prepare( "
			SELECT f.*
			FROM {$this->rel_table_name} r
			JOIN {$this->file_table_name} f ON r.file_id = f.id
			WHERE r.category_id = %d AND f.file_offline != 1
			ORDER BY f.file_name
		", $category_id ) );
	}

	private function render_category_toggle( $categories, $direct_files, $level = 0, $parent_id = 0, $show_version = false, $show_description = false ) {
		$output = '';
		$rendered_categories = [];

		foreach ( $categories as $category ) {
			if ( $category->cat_parent_id == $parent_id && ! in_array( $category->id, $rendered_categories ) ) {
				$rendered_categories[] = $category->id;
				if ( $category->cat_exclude_browser != 1 ) {
					$output .= $this->render_category( $category, $categories, $level, $show_version, $show_description );
				}
			}
		}

		if ( $level == 0 ) {
			foreach ( $direct_files as $file ) {
				$output .= $this->render_file_item( $file, $level, $show_version, $show_description );
			}
		}

		return $output;
	}

	private function render_category( $category, $all_categories, $level, $show_version = false, $show_description = false ) {
		$subcategories = $this->render_category_toggle( $all_categories, [], $level + 1, $category->id, $show_version, $show_description );
		$category_files = array_filter( $all_categories, function ($item) use ($category) {
			return $item->id == $category->id && $item->file_id;
		} );

		$files_output = '';
		foreach ( $category_files as $file ) {
			$files_output .= $this->render_file_item( $file, $level + 1, $show_version, $show_description );
		}

		$icons = '<div class="fvm-toggle-icon-wrapper">' .
			'<svg class="fvm-toggle-icon plus" width="20px" height="20px" stroke-width="1.5" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" color="#000000"><path d="M6 12H12M18 12H12M12 12V6M12 12V18" stroke="#000000" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"></path></svg>' .
			'<svg class="fvm-toggle-icon minus" width="20px" height="20px" stroke-width="1.5" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" color="#000000"><path d="M6 12H18" stroke="#000000" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"></path></svg>' .
			'</div>';

		// Check if there are no files and no subcategories
		$content = $subcategories . $files_output;
		if ( empty( $content ) ) {
			$content = '<li class="fvm-toggle-file"><span>No files available.</span></li>';
		}

		return sprintf(
			'<li id="fvm-category-%1$d" class="fvm-toggle-category" data-level="%2$d">
				<span class="fvm-toggle-category-title" aria-expanded="false">
					%6$s %3$s
				</span>
				<ul class="fvm-toggle-content" style="display: none !important;" aria-hidden="true">
					%4$s
				</ul>
			</li>',
			$category->id,
			$level,
			esc_html( $category->cat_name ),
			$content,
			$files_output,
			$icons
		);
	}

	private function render_file_item( $file, $level, $show_version = false, $show_description = false ) {
		$file_name = ! empty( $file->file_display_name ) ? $file->file_display_name : $file->file_name;

		$output = sprintf(
			'<li id="fvm-file-%1$d" class="fvm-toggle-file" data-level="%2$d">
				<span>
					<a href="%3$s" title="%4$s" target="_blank" rel="noopener noreferrer">%4$s</a>
					<span class="fvm-file-item-meta">
						%5$s (%6$s)
					</span>
				</span>
			</li>',
			$file->id,
			$level,
			esc_url( add_query_arg( 'file', $file->file_name, home_url() ) ),
			esc_html( $file_name ),
			$file->file_type ? '<span>' . esc_html( $file->file_type ) . '</span>' : '',
			esc_html( size_format( $file->file_size ) ),
		);

		return apply_filters( 'fvm_render_file_item', $output, $file, $level, $show_version, $show_description );
	}
}

