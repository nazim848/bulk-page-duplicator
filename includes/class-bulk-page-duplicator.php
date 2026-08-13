<?php

/**
 * Core logic for Bulk Page Duplicator
 *
 * @package BulkPageDuplicator
 */

if (!defined('ABSPATH')) exit;

class Bulk_Page_Duplicator_Core {
	/**
	 * Whether Elementor's global asset cache needs to be cleared after this batch.
	 *
	 * @var bool
	 */
	private $elementor_cache_needs_clear = false;

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
			$raw_values = map_deep(wp_unslash($_POST['values']), 'sanitize_text_field');
			foreach ((array) $raw_values as $value_set) {
				if (is_array($value_set)) {
					$batch_values[] = $value_set;
				} else {
					$batch_values[] = [$value_set];
				}
			}
		}
		$page_status = isset($_POST['status']) ? sanitize_key(wp_unslash($_POST['status'])) : 'draft';
		$replace_options = isset($_POST['replace_options']) ? array_map('sanitize_key', (array) wp_unslash($_POST['replace_options'])) : [];
		$post_type = isset($_POST['post_type']) ? sanitize_key(wp_unslash($_POST['post_type'])) : 'page';
		$parent_page = isset($_POST['parent_page']) ? sanitize_text_field(wp_unslash($_POST['parent_page'])) : '0';
		$batch_index = isset($_POST['batch_index']) ? absint(wp_unslash($_POST['batch_index'])) : 0;
		$operation_id = isset($_POST['operation_id']) ? sanitize_key(wp_unslash($_POST['operation_id'])) : '';
		$operation_complete = isset($_POST['operation_complete']) && 'true' === sanitize_text_field(wp_unslash($_POST['operation_complete']));

		// Get taxonomy terms to assign
		$taxonomy_terms = [];
		if (isset($_POST['taxonomy_terms']) && is_array($_POST['taxonomy_terms'])) {
			$raw_taxonomy_terms = map_deep(wp_unslash($_POST['taxonomy_terms']), 'absint');
			foreach ($raw_taxonomy_terms as $taxonomy => $term_ids) {
				$taxonomy_terms[sanitize_key($taxonomy)] = array_map('absint', (array) $term_ids);
			}
		}

		// Validate post type exists and is public
		$post_type_obj = get_post_type_object($post_type);
		if (!$post_type_obj || !$post_type_obj->public || 'attachment' === $post_type) {
			wp_send_json_error(__('Invalid post type', 'bulk-page-duplicator'));
		}

		$create_posts_cap = isset($post_type_obj->cap->create_posts) ? $post_type_obj->cap->create_posts : $post_type_obj->cap->edit_posts;
		if (!current_user_can($create_posts_cap)) {
			wp_send_json_error(__('You do not have permission to create this type of content.', 'bulk-page-duplicator'));
		}

		if (!in_array($page_status, array('draft', 'publish'), true)) {
			wp_send_json_error(__('Invalid post status.', 'bulk-page-duplicator'));
		}

		if ('publish' === $page_status && !current_user_can($post_type_obj->cap->publish_posts)) {
			wp_send_json_error(__('You do not have permission to publish this type of content.', 'bulk-page-duplicator'));
		}

		// Validate template post exists
		$template_page = get_post($template_id);
		if (!$template_page || $template_page->post_type !== $post_type) {
			wp_send_json_error(__('Template not found', 'bulk-page-duplicator'));
		}

		if (!current_user_can('edit_post', $template_id)) {
			wp_send_json_error(__('You do not have permission to use this template.', 'bulk-page-duplicator'));
		}

		$post_parent = $this->validate_parent_page($parent_page, $template_page, $post_type_obj);
		if (is_wp_error($post_parent)) {
			wp_send_json_error($post_parent->get_error_message());
		}

		$taxonomy_terms = $this->validate_taxonomy_terms($taxonomy_terms, $post_type);
		if (is_wp_error($taxonomy_terms)) {
			wp_send_json_error($taxonomy_terms->get_error_message());
		}

		$results = [];
		$is_last_batch = false;

		if (empty($batch_values)) {
			$is_last_batch = true;
		} else {
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

				// Check if this slug already exists under the requested parent.
				$existing_post = $this->get_post_by_slug($slug, $post_type, $post_parent);
				if ($existing_post) {
					$results[] = [
						'value' => $display_value,
						'status' => 'skipped',
						// translators: %s: The slug that already exists.
						'message' => sprintf(__('Item with slug "%s" already exists', 'bulk-page-duplicator'), $slug)
					];
					continue;
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

				$inserted_slug = get_post_field('post_name', $new_post_id);

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
				$post_warnings = [];
				if (!empty($taxonomy_terms)) {
					foreach ($taxonomy_terms as $taxonomy => $term_ids) {
						if (!empty($term_ids)) {
							$term_result = wp_set_object_terms($new_post_id, $term_ids, $taxonomy);
							if (is_wp_error($term_result)) {
								$post_warnings[] = $term_result->get_error_message();
							}
						}
					}
				}

				// Apply Elementor data if exists and option selected
				if (in_array('elementor', $replace_options)) {
					$this->process_elementor_data($template_id, $new_post_id, $placeholders, $value_set, $content);
				}

				// Apply Beaver Builder data if exists and option selected
				if (in_array('beaver', $replace_options)) {
					$this->process_beaver_builder_data($template_id, $new_post_id, $placeholders, $value_set);
				}

				// Apply Bricks Builder data if exists and option selected
				if (in_array('bricks', $replace_options)) {
					$this->process_bricks_builder_data($template_id, $new_post_id, $placeholders, $value_set);
				}

				/* translators: %s: title of the newly created item. */
				$success_message = sprintf(__('Created: "%s"', 'bulk-page-duplicator'), $title);
				if ($inserted_slug !== $slug) {
					/* translators: %s: actual unique slug assigned by WordPress. */
					$post_warnings[] = sprintf(__('WordPress assigned the unique slug "%s".', 'bulk-page-duplicator'), $inserted_slug);
				}
				if (!empty($post_warnings)) {
					/* translators: %1$s: creation message, %2$s: warning details. */
					$success_message = sprintf(__('%1$s Warning: %2$s', 'bulk-page-duplicator'), $success_message, implode(' ', $post_warnings));
				}

				$results[] = [
					'value' => $display_value,
					'status' => 'success',
					// translators: %s: The title of the newly created item.
					'message' => $success_message,
					'id' => $new_post_id,
					'slug' => $inserted_slug,
					'edit_url' => esc_url_raw(get_edit_post_link($new_post_id, ''))
				];
			}
		}

		if ($this->elementor_cache_needs_clear && class_exists('\\Elementor\\Plugin')) {
			\Elementor\Plugin::$instance->files_manager->clear_cache();
			$this->elementor_cache_needs_clear = false;
		}

		// Store batch history for undo/rollback functionality
		$created_ids = array_filter(array_map(function($r) {
			return isset($r['status']) && $r['status'] === 'success' && isset($r['id']) ? $r['id'] : null;
		}, $results));

		$this->save_batch_history($template_id, $post_type, $created_ids, $batch_index, $operation_id, $operation_complete);

		wp_send_json_success([
			'results' => $results,
			'is_last_batch' => $is_last_batch
		]);
	}

	/**
	 * Validate and resolve a requested parent post.
	 *
	 * @param string  $parent_page   Requested parent value.
	 * @param WP_Post $template_page Template post.
	 * @param object  $post_type_obj Post type object.
	 * @return int|WP_Error
	 */
	private function validate_parent_page($parent_page, $template_page, $post_type_obj) {
		if (!$post_type_obj->hierarchical) {
			return 0;
		}

		$post_parent = 'template' === $parent_page ? absint($template_page->post_parent) : absint($parent_page);
		if (!$post_parent) {
			return 0;
		}

		$parent = get_post($post_parent);
		if (!$parent || $parent->post_type !== $post_type_obj->name) {
			return new WP_Error('bpd_invalid_parent', __('Invalid parent item.', 'bulk-page-duplicator'));
		}

		if (!current_user_can('edit_post', $post_parent)) {
			return new WP_Error('bpd_parent_permission', __('You do not have permission to use the selected parent.', 'bulk-page-duplicator'));
		}

		return $post_parent;
	}

	/**
	 * Validate taxonomy and term assignments for a post type.
	 *
	 * @param array  $taxonomy_terms Submitted taxonomy terms.
	 * @param string $post_type      Requested post type.
	 * @return array|WP_Error
	 */
	private function validate_taxonomy_terms($taxonomy_terms, $post_type) {
		$validated = [];

		foreach ($taxonomy_terms as $taxonomy => $term_ids) {
			$taxonomy_obj = get_taxonomy($taxonomy);
			if (!$taxonomy_obj || !is_object_in_taxonomy($post_type, $taxonomy)) {
				return new WP_Error('bpd_invalid_taxonomy', __('Invalid taxonomy selection.', 'bulk-page-duplicator'));
			}

			if (!current_user_can($taxonomy_obj->cap->assign_terms)) {
				return new WP_Error('bpd_taxonomy_permission', __('You do not have permission to assign the selected terms.', 'bulk-page-duplicator'));
			}

			$validated[$taxonomy] = [];
			foreach (array_unique(array_filter(array_map('absint', $term_ids))) as $term_id) {
				if (!term_exists($term_id, $taxonomy)) {
					return new WP_Error('bpd_invalid_term', __('One or more selected terms no longer exist.', 'bulk-page-duplicator'));
				}
				$validated[$taxonomy][] = $term_id;
			}
		}

		return $validated;
	}

	/**
	 * Find an existing post by slug and parent.
	 *
	 * @param string $slug        Post slug.
	 * @param string $post_type   Post type.
	 * @param int    $post_parent Parent post ID.
	 * @return WP_Post|null
	 */
	public function get_post_by_slug($slug, $post_type, $post_parent = 0) {
		$args = array(
			'post_type'              => $post_type,
			'name'                   => sanitize_title($slug),
			'post_status'            => array('publish', 'draft', 'pending', 'private', 'future', 'trash'),
			'posts_per_page'         => 1,
			'no_found_rows'          => true,
			'ignore_sticky_posts'    => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
		);

		$post_type_obj = get_post_type_object($post_type);
		if ($post_type_obj && $post_type_obj->hierarchical) {
			$args['post_parent'] = absint($post_parent);
		}

		$posts = get_posts($args);
		return !empty($posts) ? $posts[0] : null;
	}

	/**
	 * Save batch history for undo/rollback functionality
	 *
	 * @param int $template_id The template post ID
	 * @param string $post_type The post type created
	 * @param array $post_ids Array of created post IDs
	 * @param int    $batch_index  Current batch index (0 for first batch).
	 * @param string $operation_id       Unique client-generated operation ID.
	 * @param bool   $operation_complete Whether the operation sent its final batch.
	 */
	private function save_batch_history($template_id, $post_type, $post_ids, $batch_index, $operation_id = '', $operation_complete = false) {
		$history = get_option('bpd_batch_history', []);
		$user_id = get_current_user_id();

		if (empty($operation_id)) {
			$operation_id = 'legacy-' . wp_generate_uuid4();
		}

		$session_key = 'session_' . $user_id . '_' . $operation_id;

		// Create the operation on its first received batch, even if nothing was created.
		if (!isset($history[$session_key])) {
			$template = get_post($template_id);
			$post_type_obj = get_post_type_object($post_type);
			$history[$session_key] = [
				'timestamp' => time(),
				'user_id' => $user_id,
				'template_id' => $template_id,
				'template_title' => $template ? $template->post_title : __('Unknown', 'bulk-page-duplicator'),
				'post_type' => $post_type,
				'post_type_label' => $post_type_obj ? $post_type_obj->labels->name : $post_type,
				'post_ids' => array_values(array_map('absint', $post_ids)),
			];
		} else {
			$history[$session_key]['post_ids'] = array_values(array_unique(array_merge(
				$history[$session_key]['post_ids'],
				array_map('absint', $post_ids)
			)));
		}

		if ($operation_complete && empty($history[$session_key]['post_ids'])) {
			unset($history[$session_key]);
		}

		// Keep only the last 20 operations
		if (count($history) > 20) {
			$history = array_slice($history, -20, 20, true);
		}

		update_option('bpd_batch_history', $history, false);
	}

	/**
	 * Get batch history for display
	 *
	 * @param int $limit Number of history items to return
	 * @return array Array of history items
	 */
	public function get_batch_history($limit = 10) {
		$history = get_option('bpd_batch_history', []);
		$user_id = get_current_user_id();

		$history = array_filter($history, function($item) use ($user_id) {
			return isset($item['user_id'], $item['post_ids']) && (int) $item['user_id'] === $user_id && !empty($item['post_ids']);
		});

		// Sort by timestamp descending (most recent first)
		uasort($history, function($a, $b) {
			return $b['timestamp'] - $a['timestamp'];
		});

		// Limit results
		$history = array_slice($history, 0, $limit, true);

		// Format for display
		$formatted = [];
		foreach ($history as $key => $item) {
			// Count how many posts still exist
			$existing_count = 0;
			foreach ($item['post_ids'] as $post_id) {
				if (get_post($post_id)) {
					$existing_count++;
				}
			}

			$formatted[] = [
				'key' => $key,
				'timestamp' => $item['timestamp'],
				'date' => get_date_from_gmt(gmdate('Y-m-d H:i:s', $item['timestamp']), get_option('date_format') . ' ' . get_option('time_format')),
				'template_title' => $item['template_title'],
				'post_type_label' => $item['post_type_label'],
				'total_count' => count($item['post_ids']),
				'existing_count' => $existing_count,
				'can_rollback' => $existing_count > 0,
			];
		}

		return $formatted;
	}

	/**
	 * Rollback (delete) posts from a batch operation
	 *
	 * @param string $session_key The session key to rollback
	 * @return array Result with success status and message
	 */
	public function rollback_batch($session_key) {
		$history = get_option('bpd_batch_history', []);

		if (!isset($history[$session_key])) {
			return [
				'success' => false,
				'message' => __('Operation not found in history.', 'bulk-page-duplicator')
			];
		}

		$item = $history[$session_key];
		if (!isset($item['user_id']) || (int) $item['user_id'] !== get_current_user_id()) {
			return [
				'success' => false,
				'message' => __('You do not have permission to rollback this operation.', 'bulk-page-duplicator')
			];
		}

		$deleted_count = 0;
		$failed_count = 0;
		$remaining_ids = [];

		foreach ($item['post_ids'] as $post_id) {
			$post = get_post($post_id);
			if ($post) {
				if (!current_user_can('delete_post', $post_id)) {
					$failed_count++;
					$remaining_ids[] = $post_id;
					continue;
				}

				// Force delete (bypass trash)
				$result = wp_delete_post($post_id, true);
				if ($result) {
					$deleted_count++;
				} else {
					$failed_count++;
					$remaining_ids[] = $post_id;
				}
			}
		}

		if (empty($remaining_ids)) {
			unset($history[$session_key]);
		} else {
			$history[$session_key]['post_ids'] = $remaining_ids;
		}
		update_option('bpd_batch_history', $history, false);

		if ($failed_count > 0) {
			return [
				'success' => true,
				// translators: %1$d: number of deleted posts, %2$d: number of failed deletions
				'message' => sprintf(__('Deleted %1$d items. %2$d items could not be deleted.', 'bulk-page-duplicator'), $deleted_count, $failed_count)
			];
		}

		return [
			'success' => true,
			// translators: %d: number of deleted posts
			'message' => sprintf(__('Successfully deleted %d items.', 'bulk-page-duplicator'), $deleted_count)
		];
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
	 * Process Elementor data for a post
	 */
	private function process_elementor_data($template_id, $new_post_id, $placeholders, $value_set, $content) {
		// First, ensure this is an Elementor post
		$is_elementor_page = get_post_meta($template_id, '_elementor_edit_mode', true) === 'builder';

		if ($is_elementor_page) {
			// 1. Copy _elementor_data with placeholders replaced
			$elementor_data = get_post_meta($template_id, '_elementor_data', true);
			if (!empty($elementor_data)) {
				$new_elementor_data = $this->multi_replace($elementor_data, $placeholders, $value_set);
				update_post_meta($new_post_id, '_elementor_data', wp_slash($new_elementor_data));
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

			// 5. Defer Elementor's global cache clear until the batch is complete.
			$this->elementor_cache_needs_clear = true;

			// 6. Set post type to elementor
			update_post_meta($new_post_id, '_elementor_edit_mode', 'builder');
		}

		// 7. Ensure the post content is properly set for Elementor
		if (empty($content) && $is_elementor_page) {
			wp_update_post([
				'ID' => $new_post_id,
				'post_content' => '<!-- wp:shortcode -->[elementor-template id="' . $new_post_id . '"]<!-- /wp:shortcode -->'
			]);
		}
	}

	/**
	 * Process Beaver Builder data for a post
	 */
	private function process_beaver_builder_data($template_id, $new_post_id, $placeholders, $value_set) {
		// Check if this is a Beaver Builder post
		$fl_builder_data = get_post_meta($template_id, '_fl_builder_data', true);
		$fl_builder_draft = get_post_meta($template_id, '_fl_builder_draft', true);

		if (empty($fl_builder_data) && empty($fl_builder_draft)) {
			return;
		}

		// Process published layout data
		if (!empty($fl_builder_data) && is_array($fl_builder_data)) {
			$new_data = $this->replace_in_beaver_builder_data($fl_builder_data, $placeholders, $value_set);
			update_post_meta($new_post_id, '_fl_builder_data', $new_data);
		}

		// Process draft layout data
		if (!empty($fl_builder_draft) && is_array($fl_builder_draft)) {
			$new_draft = $this->replace_in_beaver_builder_data($fl_builder_draft, $placeholders, $value_set);
			update_post_meta($new_post_id, '_fl_builder_draft', $new_draft);
		}

		// Copy other Beaver Builder meta
		$bb_meta_keys = [
			'_fl_builder_enabled',
			'_fl_builder_data_settings',
			'_fl_builder_draft_settings'
		];

		foreach ($bb_meta_keys as $meta_key) {
			$meta_value = get_post_meta($template_id, $meta_key, true);
			if (!empty($meta_value)) {
				update_post_meta($new_post_id, $meta_key, $meta_value);
			}
		}

		// Clear Beaver Builder cache
		if (class_exists('FLBuilderModel')) {
			FLBuilderModel::delete_asset_cache($new_post_id);
		}
	}

	/**
	 * Helper function to replace placeholders in Beaver Builder data
	 */
	private function replace_in_beaver_builder_data($data, $placeholders, $values) {
		if (!is_array($data)) {
			return $data;
		}

		foreach ($data as $node_id => $node) {
			if (is_object($node)) {
				$node = (array) $node;
			}

			if (is_array($node)) {
				// Replace in settings
				if (isset($node['settings'])) {
					$settings = (array) $node['settings'];
					foreach ($settings as $key => $value) {
						if (is_string($value)) {
							$settings[$key] = $this->multi_replace($value, $placeholders, $values);
						}
					}
					$node['settings'] = (object) $settings;
				}

				$data[$node_id] = (object) $node;
			}
		}

		return $data;
	}

	/**
	 * Process Bricks Builder data for a post
	 */
	private function process_bricks_builder_data($template_id, $new_post_id, $placeholders, $value_set) {
		// Bricks stores data in _bricks_page_content_2 meta key
		$bricks_data = get_post_meta($template_id, '_bricks_page_content_2', true);

		if (empty($bricks_data)) {
			// Try the older meta key format
			$bricks_data = get_post_meta($template_id, '_bricks_page_content', true);
		}

		if (empty($bricks_data)) {
			return;
		}

		// Bricks data can be JSON string or array
		if (is_string($bricks_data)) {
			$bricks_data = json_decode($bricks_data, true);
		}

		if (!is_array($bricks_data)) {
			return;
		}

		// Process the Bricks data
		$new_data = $this->replace_in_bricks_data($bricks_data, $placeholders, $value_set);

		// Save the updated data
		update_post_meta($new_post_id, '_bricks_page_content_2', $new_data);

		// Copy other Bricks meta
		$bricks_meta_keys = [
			'_bricks_page_header_2',
			'_bricks_page_footer_2',
			'_bricks_page_settings',
			'_bricks_editor_mode'
		];

		foreach ($bricks_meta_keys as $meta_key) {
			$meta_value = get_post_meta($template_id, $meta_key, true);
			if (!empty($meta_value)) {
				// For content areas, also apply replacements
				if (strpos($meta_key, '_content') !== false || strpos($meta_key, '_header') !== false || strpos($meta_key, '_footer') !== false) {
					if (is_array($meta_value)) {
						$meta_value = $this->replace_in_bricks_data($meta_value, $placeholders, $value_set);
					}
				}
				update_post_meta($new_post_id, $meta_key, $meta_value);
			}
		}

		// Clear Bricks cache if available
		if (class_exists('Bricks\Assets')) {
			delete_post_meta($new_post_id, '_bricks_page_assets_css');
			delete_post_meta($new_post_id, '_bricks_page_assets_js');
		}
	}

	/**
	 * Helper function to replace placeholders in Bricks Builder data
	 */
	private function replace_in_bricks_data($data, $placeholders, $values) {
		if (!is_array($data)) {
			return $data;
		}

		foreach ($data as $index => $element) {
			if (is_array($element)) {
				// Replace in settings
				if (isset($element['settings']) && is_array($element['settings'])) {
					$data[$index]['settings'] = $this->replace_in_bricks_settings($element['settings'], $placeholders, $values);
				}

				// Process nested children
				if (isset($element['children']) && is_array($element['children'])) {
					$data[$index]['children'] = $this->replace_in_bricks_data($element['children'], $placeholders, $values);
				}
			}
		}

		return $data;
	}

	/**
	 * Helper function to replace placeholders in Bricks settings
	 */
	private function replace_in_bricks_settings($settings, $placeholders, $values) {
		foreach ($settings as $key => $value) {
			if (is_string($value)) {
				$settings[$key] = $this->multi_replace($value, $placeholders, $values);
			} elseif (is_array($value)) {
				$settings[$key] = $this->replace_in_bricks_settings($value, $placeholders, $values);
			}
		}
		return $settings;
	}

	/**
	 * Detect supported page builders
	 *
	 * @return array
	 */
	public function detect_page_builders() {
		$page_builders = [];

		// Elementor
		if (defined('ELEMENTOR_VERSION') || class_exists('\Elementor\Plugin')) {
			$page_builders[] = [
				'name' => 'Elementor',
				'id' => 'elementor',
			];
		}

		// Beaver Builder
		if (defined('FL_BUILDER_VERSION') || class_exists('FLBuilder')) {
			$page_builders[] = [
				'name' => 'Beaver Builder',
				'id' => 'beaver',
			];
		}

		// Bricks Builder
		if (defined('BRICKS_VERSION') || class_exists('Bricks\Elements')) {
			$page_builders[] = [
				'name' => 'Bricks Builder',
				'id' => 'bricks',
			];
		}

		return $page_builders;
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
			// Skip temporary editor data and handle the featured image separately.
			if (in_array($key, ['_edit_lock', '_edit_last', '_thumbnail_id'], true)) {
				continue;
			}

			// Elementor data is transformed separately only when replacement is enabled.
			if ($key === '_elementor_data' && in_array('elementor', $replace_options, true)) {
				continue;
			}

			// Recreate this key exactly so multi-valued metadata retains its row structure.
			delete_post_meta($to_id, $key);

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
							$meta_value = wp_json_encode($decoded);
						}
					}
					// For simple string values
					else {
						$meta_value = $this->multi_replace($meta_value, $placeholders, $values);
					}
				}

					add_post_meta($to_id, $key, wp_slash(maybe_unserialize($meta_value)), false);
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
			$replacements[] = ucwords(strtolower($replace), " 	\r\n\f\v-");
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
