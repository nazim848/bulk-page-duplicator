<?php

/**
 * Plugin Name: Bulk Page Generator
 * Description: Create multiple pages by duplicating an existing page and replacing specific text with different values.
 * Version: 1.0.0
 * Author: Nazim Husain
 * Text Domain: bulk-page-generator
 */

// Exit if accessed directly
if (!defined('ABSPATH')) exit;

class Bulk_Page_Generator {

	public function __construct() {
		add_action('admin_menu', array($this, 'add_admin_menu'));
		add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
		add_action('wp_ajax_process_bulk_duplication', array($this, 'process_bulk_duplication'));
	}

	public function add_admin_menu() {
		add_management_page(
			'Bulk Page Generator',
			'Bulk Page Generator',
			'manage_options',
			'bulk-page-generator',
			array($this, 'admin_page')
		);
	}

	public function enqueue_admin_scripts($hook) {
		if ('tools_page_bulk-page-generator' !== $hook) {
			return;
		}

		wp_enqueue_style('bulk-generator-css', plugin_dir_url(__FILE__) . 'assets/css/bulk-page-generator.css', array(), '1.0.0');
		wp_enqueue_script('bulk-generator-js', plugin_dir_url(__FILE__) . 'assets/js/bulk-page-generator.js', array('jquery'), '1.0.0', true);
		wp_localize_script('bulk-generator-js', 'bpg_ajax', array(
			'ajax_url' => admin_url('admin-ajax.php'),
			'nonce' => wp_create_nonce('bulk_page_duplication')
		));
	}

	public function admin_page() {
		// Get all pages for the dropdown
		$pages = get_pages(array(
			'sort_column' => 'post_title',
			'sort_order' => 'ASC',
		));

		// SEO plugins detection
		$seo_plugins = $this->detect_seo_plugins();

?>
		<div class="wrap">
			<h1>Bulk Page Generator</h1>

			<div class="bpg-container">
				<div class="bpg-panel">
					<h2>Select Template Page</h2>
					<p>Choose the page you want to use as a template for duplication:</p>

					<select id="template-page" class="widefat">
						<option value="">Select a page</option>
						<?php foreach ($pages as $page) : ?>
							<option value="<?php echo esc_attr($page->ID); ?>">
								<?php echo esc_html($page->post_title); ?> (ID: <?php echo esc_html($page->ID); ?>)
							</option>
						<?php endforeach; ?>
					</select>

					<h2>Text to Replace</h2>
					<p>Enter the placeholder text in your template that should be replaced with each value:</p>
					<input type="text" id="placeholder-text" class="widefat" placeholder="London">

					<h2>Replacement Values</h2>
					<p>Enter one value per line. Each value will create a new page:</p>
					<textarea id="replacement-values" class="widefat" rows="10" placeholder="New York&#10;Los Angeles&#10;Chicago"></textarea>

					<h2>Status</h2>
					<p>Select status for the created pages:</p>
					<select id="page-status" class="widefat">
						<option value="publish">Published</option>
						<option value="draft">Draft</option>
					</select>

					<h2>Where to Replace Text</h2>
					<p>Select where the text should be replaced:</p>
					<div class="bpg-checkbox-group">
						<label><input type="checkbox" id="replace-title" checked> Page Title</label>
						<label><input type="checkbox" id="replace-slug" checked> Page Slug</label>
						<label><input type="checkbox" id="replace-content" checked> Page Content</label>
						<label><input type="checkbox" id="replace-elementor" checked> Elementor Data (if exists)</label>
						<?php if (!empty($seo_plugins)) : ?>
							<label><input type="checkbox" id="replace-seo" checked> SEO Meta Data</label>
						<?php endif; ?>
					</div>

					<div class="bpg-progress-container" style="display: none;">
						<h3>Progress</h3>
						<div class="bpg-progress-bar">
							<div class="bpg-progress-bar-inner"></div>
						</div>
						<p class="bpg-progress-text">0%</p>
						<p class="bpg-status-text"></p>
					</div>

					<p class="submit">
						<button id="start-duplication" class="button button-primary button-large">Start Duplication</button>
						<button id="cancel-duplication" class="button button-secondary button-large" style="display: none;">Cancel</button>
					</p>
				</div>

				<div class="bpg-panel">
					<h2>Instructions</h2>
					<ol>
						<li>Select the page you want to duplicate.</li>
						<li>Enter the placeholder text that exists in your template page.</li>
						<li>Enter all the values you want to replace the placeholder with (one per line).</li>
						<li>Choose where the text should be replaced.</li>
						<li>Click "Start Duplication" to begin the process.</li>
					</ol>


					<h3>Tips</h3>
					<ul>
						<li>Make sure your template page contains the placeholder text in all areas you want to replace.</li>
						<li>Large numbers of pages will be processed in batches to avoid timeout issues.</li>
						<li>Processing will continue in the background - don't close the browser tab.</li>
					</ul>

					<div class="bpg-log-container" style="display: none;">
						<h3>Results Log</h3>
						<div class="bpg-log"></div>
					</div>
				</div>
			</div>
		</div>
<?php
	}

	public function process_bulk_duplication() {
		// Verify nonce
		if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'bulk_page_duplication')) {
			wp_send_json_error('Security check failed');
		}

		// Get data from AJAX request
		$template_id = intval($_POST['template_id']);
		$placeholder = sanitize_text_field($_POST['placeholder']);
		$batch_values = array_map('sanitize_text_field', $_POST['values']);
		$page_status = sanitize_text_field($_POST['status']);
		$replace_options = $_POST['replace_options'];
		$batch_index = intval($_POST['batch_index']);
		$batch_size = 10; // Process 10 pages at a time

		// Validate template page exists
		$template_page = get_post($template_id);
		if (!$template_page) {
			wp_send_json_error('Template page not found');
		}

		$results = [];
		$is_last_batch = false;

		if (empty($batch_values)) {
			$is_last_batch = true;
		} else {
			// Get Elementor data if exists
			$elementor_data = '';
			if (in_array('elementor', $replace_options)) {
				$elementor_data = get_post_meta($template_id, '_elementor_data', true);
			}

			// Process this batch
			foreach ($batch_values as $value) {
				// Skip empty values
				if (empty($value)) continue;

				// Create title
				$title = $template_page->post_title;
				if (in_array('title', $replace_options)) {
					$title = $this->smart_replace($title, $placeholder, $value);
				}

				$slug = $template_page->post_name;
				if (in_array('slug', $replace_options)) {
					$new_slug = $this->smart_replace($slug, $placeholder, $value);
					$slug = sanitize_title($new_slug);
				}

				// Create content
				$content = $template_page->post_content;
				if (in_array('content', $replace_options)) {
					$content = $this->smart_replace($content, $placeholder, $value);
				}

				// Check if page with this slug already exists
				$existing_page = get_page_by_path($slug);
				if ($existing_page) {
					$results[] = [
						'value' => $value,
						'status' => 'skipped',
						'message' => 'Page with slug "' . $slug . '" already exists'
					];
					continue;
				}

				// Create the duplicated page
				$page_id = wp_insert_post([
					'post_title'     => $title,
					'post_name'      => $slug,
					'post_content'   => $content,
					'post_status'    => $page_status,
					'post_type'      => 'page',
					'post_author'    => $template_page->post_author,
					'comment_status' => $template_page->comment_status,
					'ping_status'    => $template_page->ping_status,
				]);

				if (is_wp_error($page_id)) {
					$results[] = [
						'value' => $value,
						'status' => 'error',
						'message' => $page_id->get_error_message()
					];
					continue;
				}

				// Copy post meta
				$this->copy_post_meta($template_id, $page_id, $placeholder, $value, $replace_options);

				// Apply Elementor data if exists and option selected
				if (in_array('elementor', $replace_options)) {
					// First, ensure this is an Elementor page
					$is_elementor_page = get_post_meta($template_id, '_elementor_edit_mode', true) === 'builder';

					if ($is_elementor_page) {
						// 1. Copy _elementor_data with placeholders replaced
						$elementor_data = get_post_meta($template_id, '_elementor_data', true);
						if (!empty($elementor_data)) {
							$new_elementor_data = $this->smart_replace($elementor_data, $placeholder, $value);
							update_post_meta($page_id, '_elementor_data', wp_slash($new_elementor_data)); // Important: wp_slash for JSON
						}

						// 2. Get ALL post meta (including Elementor-specific ones)
						$all_meta = get_post_meta($template_id);

						// 3. Copy specific Elementor meta fields
						$elementor_meta_keys = [
							'_elementor_edit_mode',
							'_elementor_version',
							'_elementor_template_type',
							'_elementor_page_settings',
							'_wp_page_template',
							'_elementor_page_meta',
							'_elementor_controls_usage'
						];

						foreach ($elementor_meta_keys as $meta_key) {
							if (isset($all_meta[$meta_key]) && !empty($all_meta[$meta_key][0])) {
								$meta_value = $all_meta[$meta_key][0];
								update_post_meta($page_id, $meta_key, maybe_unserialize($meta_value));
							}
						}

						// 4. Force regeneration of CSS
						delete_post_meta($page_id, '_elementor_css');

						// 5. Clear Elementor cache for this page
						if (class_exists('\Elementor\Plugin')) {
							\Elementor\Plugin::$instance->files_manager->clear_cache();
						}

						// 6. Set page type to elementor
						update_post_meta($page_id, '_elementor_edit_mode', 'builder');
					}

					// 7. Ensure the post content is properly set for Elementor
					// Sometimes Elementor uses a special placeholder in post_content
					if (empty($content) && $is_elementor_page) {
						wp_update_post([
							'ID' => $page_id,
							'post_content' => '<!-- wp:shortcode -->[elementor-template id="' . $page_id . '"]<!-- /wp:shortcode -->'
						]);
					}
				}

				$results[] = [
					'value' => $value,
					'status' => 'success',
					'message' => 'Created page: "' . $title . '"',
					'id' => $page_id,
					'edit_url' => get_edit_post_link($page_id, '')
				];
			}
		}

		wp_send_json_success([
			'results' => $results,
			'is_last_batch' => $is_last_batch
		]);
	}

	private function copy_post_meta($from_id, $to_id, $placeholder, $replacement, $replace_options) {
		// Copy template meta to new page
		$post_meta = get_post_meta($from_id);

		// SEO plugins detection
		$seo_plugins = $this->detect_seo_plugins();

		foreach ($post_meta as $key => $values) {
			// Skip _edit_lock and _edit_last
			if (in_array($key, ['_edit_lock', '_edit_last'])) {
				continue;
			}

			// Skip _elementor_data as it's handled separately
			if ($key === '_elementor_data') {
				continue;
			}

			// SEO meta data replacement
			$is_seo_field = false;
			foreach ($seo_plugins as $plugin) {
				if (strpos($key, $plugin['prefix']) === 0) {
					$is_seo_field = true;
					break;
				}
			}

			foreach ($values as $value) {
				// Handle serialized data for SEO fields
				if ($is_seo_field && in_array('seo', $replace_options)) {
					// For serialized data
					if (is_serialized($value)) {
						$unserialized = maybe_unserialize($value);
						$this->replace_in_array_recursive($unserialized, $placeholder, $replacement);
						$value = maybe_serialize($unserialized);
					}
					// For JSON data (common in newer SEO plugins)
					else if ($this->is_json($value)) {
						$decoded = json_decode($value, true);
						if (is_array($decoded)) {
							$this->replace_in_array_recursive($decoded, $placeholder, $replacement);
							$value = json_encode($decoded);
						}
					}
					// For simple string values
					else {
						$value = $this->smart_replace($value, $placeholder, $replacement);
					}
				}

				update_post_meta($to_id, $key, maybe_unserialize($value));
			}
		}
	}

	/**
	 * Helper function to replace text in a nested array
	 *
	 * @param array  &$array  The array to process
	 * @param string $search  The search string
	 * @param string $replace The replacement string
	 */
	private function replace_in_array_recursive(&$array, $search, $replace) {
		if (!is_array($array)) {
			return;
		}

		foreach ($array as $key => &$value) {
			if (is_array($value)) {
				$this->replace_in_array_recursive($value, $search, $replace);
			} else if (is_string($value)) {
				$value = $this->smart_replace($value, $search, $replace);
			}
		}
	}

	/**
	 * Replaces text while preserving the case format
	 *
	 * @param string $text The text to process
	 * @param string $search The search string
	 * @param string $replace The replacement string
	 * @return string The processed text
	 */
	private function smart_replace($text, $search, $replace) {
		// Skip empty values
		if (empty($text) || empty($search) || empty($replace)) {
			return $text;
		}

		// 1. Prepare patterns and replacements for each case
		$patterns = [];
		$replacements = [];

		// Case 1: All uppercase
		$patterns[] = '/' . preg_quote(strtoupper($search), '/') . '/';
		$replacements[] = strtoupper($replace);

		// Case 2: Title case (first letter uppercase)
		$patterns[] = '/' . preg_quote(ucfirst(strtolower($search)), '/') . '/';
		$replacements[] = ucfirst(strtolower($replace));

		// Case 3: Exact match (as provided)
		$patterns[] = '/' . preg_quote($search, '/') . '/';
		$replacements[] = $replace;

		// Case 4: Lowercase match (must be last to avoid overriding other cases)
		$patterns[] = '/' . preg_quote(strtolower($search), '/') . '/i';
		$replacements[] = strtolower($replace);

		// 2. Apply all replacements
		return preg_replace($patterns, $replacements, $text);
	}

	/**
	 * Check if a string is a JSON string
	 *
	 * @param mixed $string The string to check
	 * @return bool Whether the string is valid JSON
	 */
	private function is_json($string) {
		if (!is_string($string)) {
			return false;
		}

		json_decode($string);
		return (json_last_error() == JSON_ERROR_NONE);
	}

	private function detect_seo_plugins() {
		$seo_plugins = [];

		// Yoast SEO
		if (defined('WPSEO_VERSION')) {
			$seo_plugins[] = [
				'name' => 'Yoast SEO',
				'prefix' => '_yoast_wpseo_'
			];
		}

		// Rank Math
		if (class_exists('RankMath')) {
			$seo_plugins[] = [
				'name' => 'Rank Math',
				'prefix' => 'rank_math_'
			];
		}

		// All in One SEO Pack
		if (class_exists('AIOSEO\\Plugin\\AIOSEO')) {
			$seo_plugins[] = [
				'name' => 'All in One SEO Pack',
				'prefix' => '_aioseo_'
			];
		}

		// SEOPress
		if (defined('SEOPRESS_VERSION')) {
			$seo_plugins[] = [
				'name' => 'SEOPress',
				'prefix' => '_seopress_'
			];
		}

		return $seo_plugins;
	}
}

// Initialize the plugin
new Bulk_Page_Generator();

// Create the necessary directories on plugin activation
//register_activation_hook(__FILE__, 'bpg_create_directories');

// function bpg_create_directories() {
// 	// Create the assets directory and subdirectories if they don't exist
// 	$base_dir = plugin_dir_path(__FILE__) . 'assets';
// 	$css_dir = $base_dir . '/css';
// 	$js_dir = $base_dir . '/js';

// 	if (!file_exists($base_dir)) {
// 		mkdir($base_dir, 0755);
// 	}

// 	if (!file_exists($css_dir)) {
// 		mkdir($css_dir, 0755);
// 	}

// 	if (!file_exists($js_dir)) {
// 		mkdir($js_dir, 0755);
// 	}
// }
