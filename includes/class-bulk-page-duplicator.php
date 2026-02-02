<?php

/**
 * Core logic for Bulk Page Duplicator
 *
 * @package BulkPageDuplicator
 */

if (!defined('ABSPATH')) exit;

class Bulk_Page_Duplicator_Core {
	/**
	 * Handle AJAX duplication request
	 */
	public function process_bulk_duplication() {
		// Verify nonce (unslash and sanitize)
		$nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';
		if (empty($nonce) || !wp_verify_nonce($nonce, 'bulk_page_duplication')) {
			wp_send_json_error(__('Security check failed', 'bulk-page-duplicator'));
		}

		// Add capability check
		if (!current_user_can('manage_options')) {
			wp_send_json_error(__('You do not have permission to perform this action.', 'bulk-page-duplicator'));
		}

		// Get data from AJAX request (validate, unslash, sanitize)
		$template_id = isset($_POST['template_id']) ? intval(wp_unslash($_POST['template_id'])) : 0;
		// Support multiple placeholders (array)
		$placeholders = isset($_POST['placeholders']) ? array_map('sanitize_text_field', (array) wp_unslash($_POST['placeholders'])) : [];
		// Values can now be arrays (for multiple placeholders) or strings
		$batch_values = [];
		if (isset($_POST['values'])) {
			$raw_values = wp_unslash($_POST['values']);
			foreach ((array) $raw_values as $value_set) {
				if (is_array($value_set)) {
					$batch_values[] = array_map('sanitize_text_field', $value_set);
				} else {
					$batch_values[] = [sanitize_text_field($value_set)];
				}
			}
		}
		$page_status = isset($_POST['status']) ? sanitize_text_field(wp_unslash($_POST['status'])) : 'draft';
		$replace_options = isset($_POST['replace_options']) ? array_map('sanitize_text_field', (array) wp_unslash($_POST['replace_options'])) : [];
		$post_type = isset($_POST['post_type']) ? sanitize_text_field(wp_unslash($_POST['post_type'])) : 'page';
		$parent_page = isset($_POST['parent_page']) ? sanitize_text_field(wp_unslash($_POST['parent_page'])) : '0';
		$batch_index = isset($_POST['batch_index']) ? intval(wp_unslash($_POST['batch_index'])) : 0;
		$batch_size = 10; // Process 10 items at a time

		// Get taxonomy terms to assign
		$taxonomy_terms = [];
		if (isset($_POST['taxonomy_terms']) && is_array($_POST['taxonomy_terms'])) {
			foreach (wp_unslash($_POST['taxonomy_terms']) as $taxonomy => $term_ids) {
				$taxonomy_terms[sanitize_key($taxonomy)] = array_map('intval', (array) $term_ids);
			}
		}

		// Validate post type exists and is public
		$post_type_obj = get_post_type_object($post_type);
		if (!$post_type_obj || !$post_type_obj->public) {
			wp_send_json_error(__('Invalid post type', 'bulk-page-duplicator'));
		}

		// Validate template post exists
		$template_page = get_post($template_id);
		if (!$template_page) {
			wp_send_json_error(__('Template not found', 'bulk-page-duplicator'));
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
			foreach ($batch_values as $value_set) {
				// Skip empty values
				if (empty($value_set) || (is_array($value_set) && empty(array_filter($value_set)))) continue;

				// For display purposes, use first value or joined values
				$display_value = is_array($value_set) ? implode(', ', $value_set) : $value_set;

				// Create title - apply all placeholder replacements
				$title = $template_page->post_title;
				if (in_array('title', $replace_options)) {
					$title = $this->multi_replace($title, $placeholders, $value_set);
				}

				$slug = $template_page->post_name;
				if (in_array('slug', $replace_options)) {
					$new_slug = $this->multi_replace($slug, $placeholders, $value_set);

					// Also try replacing the slugified placeholders (for multi-word placeholders in slugs)
					foreach ($placeholders as $index => $placeholder) {
						$value = is_array($value_set) ? ($value_set[$index] ?? '') : $value_set;
						$placeholder_slug = sanitize_title($placeholder);
						if ($placeholder_slug !== strtolower($placeholder)) {
							$value_slug = sanitize_title($value);
							$new_slug = str_replace($placeholder_slug, $value_slug, $new_slug);
						}
					}

					$slug = sanitize_title($new_slug);
				}

				// Create content
				$content = $template_page->post_content;
				if (in_array('content', $replace_options)) {
					$content = $this->multi_replace($content, $placeholders, $value_set);
				}

				// Check if post with this slug already exists for the post type
				$existing_post = get_page_by_path($slug, OBJECT, $post_type);
				if ($existing_post) {
					$results[] = [
						'value' => $display_value,
						'status' => 'skipped',
						// translators: %s: The slug that already exists.
						'message' => sprintf(__('Item with slug "%s" already exists', 'bulk-page-duplicator'), $slug)
					];
					continue;
				}

				// Determine parent page
				$post_parent = 0;
				if ($post_type_obj->hierarchical) {
					if ($parent_page === 'template') {
						$post_parent = $template_page->post_parent;
					} elseif (is_numeric($parent_page) && intval($parent_page) > 0) {
						$post_parent = intval($parent_page);
					}
				}

				// Create the duplicated post
				$new_post_id = wp_insert_post([
					'post_title'     => $title,
					'post_name'      => $slug,
					'post_content'   => $content,
					'post_status'    => $page_status,
					'post_type'      => $post_type,
					'post_parent'    => $post_parent,
					'post_author'    => $template_page->post_author,
					'comment_status' => $template_page->comment_status,
					'ping_status'    => $template_page->ping_status,
				]);

				if (is_wp_error($new_post_id)) {
					$results[] = [
						'value' => $display_value,
						'status' => 'error',
						'message' => $new_post_id->get_error_message()
					];
					continue;
				}

				// Copy post meta
				$this->copy_post_meta_multi($template_id, $new_post_id, $placeholders, $value_set, $replace_options);

				// Copy featured image if option selected
				if (in_array('featured_image', $replace_options)) {
					$thumbnail_id = get_post_thumbnail_id($template_id);
					if ($thumbnail_id) {
						set_post_thumbnail($new_post_id, $thumbnail_id);
					}
				}

				// Assign taxonomy terms
				if (!empty($taxonomy_terms)) {
					foreach ($taxonomy_terms as $taxonomy => $term_ids) {
						if (!empty($term_ids) && taxonomy_exists($taxonomy)) {
							wp_set_object_terms($new_post_id, $term_ids, $taxonomy);
						}
					}
				}

				// Apply Elementor data if exists and option selected
				if (in_array('elementor', $replace_options)) {
					// First, ensure this is an Elementor post
					$is_elementor_page = get_post_meta($template_id, '_elementor_edit_mode', true) === 'builder';

					if ($is_elementor_page) {
						// 1. Copy _elementor_data with placeholders replaced
						$elementor_data = get_post_meta($template_id, '_elementor_data', true);
						if (!empty($elementor_data)) {
							$new_elementor_data = $this->multi_replace($elementor_data, $placeholders, $value_set);
							update_post_meta($new_post_id, '_elementor_data', wp_slash($new_elementor_data)); // Important: wp_slash for JSON
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
								update_post_meta($new_post_id, $meta_key, maybe_unserialize($meta_value));
							}
						}

						// 4. Force regeneration of CSS
						delete_post_meta($new_post_id, '_elementor_css');

						// 5. Clear Elementor cache for this post
						if (class_exists('\Elementor\Plugin')) {
							\Elementor\Plugin::$instance->files_manager->clear_cache();
						}

						// 6. Set post type to elementor
						update_post_meta($new_post_id, '_elementor_edit_mode', 'builder');
					}

					// 7. Ensure the post content is properly set for Elementor
					// Sometimes Elementor uses a special placeholder in post_content
					if (empty($content) && $is_elementor_page) {
						wp_update_post([
							'ID' => $new_post_id,
							'post_content' => '<!-- wp:shortcode -->[elementor-template id="' . $new_post_id . '"]<!-- /wp:shortcode -->'
						]);
					}
				}

				$results[] = [
					'value' => $display_value,
					'status' => 'success',
					// translators: %s: The title of the newly created item.
					'message' => sprintf(__('Created: "%s"', 'bulk-page-duplicator'), $title),
					'id' => $new_post_id,
					'edit_url' => get_edit_post_link($new_post_id, '')
				];
			}
		}

		wp_send_json_success([
			'results' => $results,
			'is_last_batch' => $is_last_batch
		]);
	}

	/**
	 * Public wrapper for multi_replace (used by admin class for dry run)
	 *
	 * @param string $text The text to process
	 * @param array $placeholders Array of placeholder strings
	 * @param array $values Array of replacement values (matching placeholders order)
	 * @return string The processed text
	 */
	public function multi_replace_public($text, $placeholders, $values) {
		return $this->multi_replace($text, $placeholders, $values);
	}

	/**
	 * Apply multiple placeholder replacements to text
	 *
	 * @param string $text The text to process
	 * @param array $placeholders Array of placeholder strings
	 * @param array $values Array of replacement values (matching placeholders order)
	 * @return string The processed text
	 */
	private function multi_replace($text, $placeholders, $values) {
		if (empty($text) || empty($placeholders)) {
			return $text;
		}

		// Ensure values is an array
		if (!is_array($values)) {
			$values = [$values];
		}

		// Apply each placeholder replacement
		foreach ($placeholders as $index => $placeholder) {
			$value = isset($values[$index]) ? $values[$index] : '';
			if (!empty($placeholder) && !empty($value)) {
				$text = $this->smart_replace($text, $placeholder, $value);
			}
		}

		return $text;
	}

	/**
	 * Copy post meta from template to new post, with multiple placeholder replacements
	 */
	private function copy_post_meta_multi($from_id, $to_id, $placeholders, $values, $replace_options) {
		$post_meta = get_post_meta($from_id);

		// SEO plugins detection
		$seo_plugins = $this->detect_seo_plugins();

		foreach ($post_meta as $key => $meta_values) {
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

			foreach ($meta_values as $meta_value) {
				// Handle serialized data for SEO fields
				if ($is_seo_field && in_array('seo', $replace_options)) {
					// For serialized data
					if (is_serialized($meta_value)) {
						$unserialized = maybe_unserialize($meta_value);
						$this->replace_in_array_recursive_multi($unserialized, $placeholders, $values);
						$meta_value = maybe_serialize($unserialized);
					}
					// For JSON data (common in newer SEO plugins)
					else if ($this->is_json($meta_value)) {
						$decoded = json_decode($meta_value, true);
						if (is_array($decoded)) {
							$this->replace_in_array_recursive_multi($decoded, $placeholders, $values);
							$meta_value = json_encode($decoded);
						}
					}
					// For simple string values
					else {
						$meta_value = $this->multi_replace($meta_value, $placeholders, $values);
					}
				}

				update_post_meta($to_id, $key, maybe_unserialize($meta_value));
			}
		}
	}

	/**
	 * Helper function to replace multiple placeholders in a nested array
	 *
	 * @param array &$array The array to process
	 * @param array $placeholders Array of placeholder strings
	 * @param array $values Array of replacement values
	 */
	private function replace_in_array_recursive_multi(&$array, $placeholders, $values) {
		if (!is_array($array)) {
			return;
		}

		foreach ($array as $key => &$value) {
			if (is_array($value)) {
				$this->replace_in_array_recursive_multi($value, $placeholders, $values);
			} else if (is_string($value)) {
				$value = $this->multi_replace($value, $placeholders, $values);
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
		if (function_exists('mb_convert_case')) {
			$replacements[] = mb_convert_case($replace, MB_CASE_TITLE, 'UTF-8');
		} else {
			$replacements[] = ucwords(strtolower($replace), " \t\r\n\f\v-");
		}

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

	/**
	 * Detect supported SEO plugins and their meta prefixes
	 *
	 * @return array
	 */
	public function detect_seo_plugins() {
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
		if (class_exists('AIOSEO\Plugin\AIOSEO')) {
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