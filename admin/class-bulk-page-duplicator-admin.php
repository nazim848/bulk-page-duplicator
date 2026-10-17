<?php

/**
 * Admin functionality for Bulk Page Duplicator
 *
 * @package BulkPageDuplicator
 */

if (!defined('ABSPATH')) exit;

class Bulk_Page_Duplicator_Admin {
	/**
	 * Initialize admin hooks
	 */
	public function init() {
		add_action('admin_menu', array($this, 'add_admin_menu'));
		add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
		add_action('wp_ajax_process_bulk_duplication', array($this, 'process_bulk_duplication'));
		add_action('wp_ajax_bpd_get_posts_by_type', array($this, 'get_posts_by_type'));
		add_action('wp_ajax_bpd_get_template_data', array($this, 'get_template_data'));
		add_action('wp_ajax_bpd_dry_run', array($this, 'process_dry_run'));
		add_action('wp_ajax_bpd_save_preferences', array($this, 'save_preferences'));
		add_action('wp_ajax_bpd_get_preferences', array($this, 'get_preferences'));
		add_action('wp_ajax_bpd_get_taxonomies', array($this, 'get_taxonomies'));
		add_action('wp_ajax_bpd_get_history', array($this, 'get_history'));
		add_action('wp_ajax_bpd_rollback', array($this, 'rollback'));
	}

	/**
	 * Add admin menu page
	 */
	public function add_admin_menu() {
		add_management_page(
			__('Bulk Page Duplicator', 'bulk-page-duplicator'),
			__('Bulk Page Duplicator', 'bulk-page-duplicator'),
			'manage_options',
			'bulk-page-duplicator',
			array($this, 'admin_page')
		);
	}

	/**
	 * Enqueue admin scripts and styles
	 * @param string $hook
	 */
	public function enqueue_admin_scripts($hook) {
		if ('tools_page_bulk-page-duplicator' !== $hook) {
			return;
		}
		wp_enqueue_style('bulk-page-duplicator-css', plugin_dir_url(__FILE__) . 'assets/css/bulk-page-duplicator.css', array(), BULK_PAGE_DUPLICATOR_VERSION);
		wp_enqueue_script('bulk-page-duplicator-js', plugin_dir_url(__FILE__) . 'assets/js/bulk-page-duplicator.js', array('jquery', 'wp-i18n'), BULK_PAGE_DUPLICATOR_VERSION, true);
		wp_set_script_translations('bulk-page-duplicator-js', 'bulk-page-duplicator', BULK_PAGE_DUPLICATOR_PATH . 'languages');
		
		// Get saved user preferences
		$user_prefs = $this->get_user_preferences();
		
		wp_localize_script('bulk-page-duplicator-js', 'bulk_page_dup_ajax', array(
			'ajax_url' => admin_url('admin-ajax.php'),
			'nonce' => wp_create_nonce('bulk_page_duplication'),
			'user_preferences' => $user_prefs
		));
	}

	/**
	 * Render the admin page
	 */
	public function admin_page() {
		include dirname(__FILE__) . '/views/admin-page.php';
	}

	/**
	 * AJAX handler for bulk duplication
	 */
	public function process_bulk_duplication() {
		// The actual logic will be delegated to the core class
		if (!class_exists('Bulk_Page_Duplicator_Core')) {
			require_once dirname(dirname(__FILE__)) . '/includes/class-bulk-page-duplicator.php';
		}
		$core = new Bulk_Page_Duplicator_Core();
		$core->process_bulk_duplication();
	}

	/**
	 * AJAX handler to get posts by post type
	 */
	public function get_posts_by_type() {
		// Verify nonce
		$nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';
		if (empty($nonce) || !wp_verify_nonce($nonce, 'bulk_page_duplication')) {
			wp_send_json_error(__('Security check failed', 'bulk-page-duplicator'));
		}

		// Check capability
		if (!current_user_can('manage_options')) {
			wp_send_json_error(__('You do not have permission to perform this action.', 'bulk-page-duplicator'));
		}

		$post_type = isset($_POST['post_type']) ? sanitize_key(wp_unslash($_POST['post_type'])) : 'page';
		$search = isset($_POST['search']) ? sanitize_text_field(wp_unslash($_POST['search'])) : '';
		$paged = isset($_POST['paged']) ? max(1, absint(wp_unslash($_POST['paged']))) : 1;
		$per_page = isset($_POST['per_page']) ? absint(wp_unslash($_POST['per_page'])) : 50;
		$per_page = min(100, max(1, $per_page));
		$template_id = isset($_POST['template_id']) ? absint(wp_unslash($_POST['template_id'])) : 0;

		// Validate post type exists and is public
		$post_type_obj = get_post_type_object($post_type);
		if (!$post_type_obj || !$post_type_obj->public || 'attachment' === $post_type) {
			wp_send_json_error(__('Invalid post type', 'bulk-page-duplicator'));
		}

		$create_posts_cap = isset($post_type_obj->cap->create_posts) ? $post_type_obj->cap->create_posts : $post_type_obj->cap->edit_posts;
		if (!current_user_can($create_posts_cap)) {
			wp_send_json_error(__('You do not have permission to create this type of content.', 'bulk-page-duplicator'));
		}

		// Get posts of the specified type
		$args = array(
			'post_type'      => $post_type,
			'posts_per_page' => $per_page,
			'paged'          => $paged,
			'orderby'        => 'title',
			'order'          => 'ASC',
			'post_status'    => array('publish', 'draft', 'private'),
			's'              => $search,
		);

		if ($template_id) {
			$args['post__in'] = array($template_id);
			$args['s'] = '';
		}

		$query = new WP_Query($args);
		$posts = $query->posts;
		$options = array();

		foreach ($posts as $post) {
			if (!current_user_can('edit_post', $post->ID)) {
				continue;
			}
			$status_label = '';
			if ($post->post_status !== 'publish') {
				$status_label = ' [' . ucfirst($post->post_status) . ']';
			}

			// Get featured image thumbnail
			$thumbnail_url = '';
			if (has_post_thumbnail($post->ID)) {
				$thumbnail_url = get_the_post_thumbnail_url($post->ID, 'thumbnail');
			}

			// Get last modified date
			$modified_date = get_the_modified_date('M j, Y', $post->ID);

			$options[] = array(
				'id'            => $post->ID,
				'title'         => $post->post_title,
				'display_title' => $post->post_title . ' (ID: ' . $post->ID . ')' . $status_label,
				'status'        => $post->post_status,
				'status_label'  => ucfirst($post->post_status),
				'thumbnail'     => $thumbnail_url,
				'modified'      => $modified_date,
			);
		}

		wp_send_json_success(array(
			'posts' => $options,
			'label' => $post_type_obj->labels->singular_name,
			'is_hierarchical' => $post_type_obj->hierarchical,
			'page' => $paged,
			'has_more' => $paged < (int) $query->max_num_pages,
		));
	}

	/**
	 * Get user preferences from user meta
	 * @return array
	 */
	private function get_user_preferences() {
		$user_id = get_current_user_id();
		if (!$user_id) {
			return array();
		}

		$defaults = array(
			'post_type' => 'page',
			'template_id' => '',
			'page_status' => 'publish',
			'parent_page' => '0',
			'replace_title' => true,
			'replace_slug' => true,
			'replace_content' => true,
			'replace_elementor' => true,
			'replace_seo' => true,
			'copy_featured_image' => true,
		);

		$saved = get_user_meta($user_id, 'bpd_preferences', true);
		if (!is_array($saved)) {
			return $defaults;
		}

		return wp_parse_args($saved, $defaults);
	}

	/**
	 * AJAX handler to save user preferences
	 */
	public function save_preferences() {
		// Verify nonce
		$nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';
		if (empty($nonce) || !wp_verify_nonce($nonce, 'bulk_page_duplication')) {
			wp_send_json_error(__('Security check failed', 'bulk-page-duplicator'));
		}

		// Check capability
		if (!current_user_can('manage_options')) {
			wp_send_json_error(__('You do not have permission to perform this action.', 'bulk-page-duplicator'));
		}

		$user_id = get_current_user_id();
		if (!$user_id) {
			wp_send_json_error(__('User not logged in', 'bulk-page-duplicator'));
		}

		// Sanitize and save preferences
		$preferences = array(
			'post_type' => isset($_POST['post_type']) ? sanitize_key(wp_unslash($_POST['post_type'])) : 'page',
			'template_id' => isset($_POST['template_id']) ? intval(wp_unslash($_POST['template_id'])) : '',
			'page_status' => isset($_POST['page_status']) && 'draft' === sanitize_key(wp_unslash($_POST['page_status'])) ? 'draft' : 'publish',
			'parent_page' => isset($_POST['parent_page']) ? sanitize_text_field(wp_unslash($_POST['parent_page'])) : '0',
			'replace_title' => isset($_POST['replace_title']) && 'true' === sanitize_text_field(wp_unslash($_POST['replace_title'])),
			'replace_slug' => isset($_POST['replace_slug']) && 'true' === sanitize_text_field(wp_unslash($_POST['replace_slug'])),
			'replace_content' => isset($_POST['replace_content']) && 'true' === sanitize_text_field(wp_unslash($_POST['replace_content'])),
			'replace_elementor' => isset($_POST['replace_elementor']) && 'true' === sanitize_text_field(wp_unslash($_POST['replace_elementor'])),
			'replace_seo' => isset($_POST['replace_seo']) && 'true' === sanitize_text_field(wp_unslash($_POST['replace_seo'])),
			'copy_featured_image' => isset($_POST['copy_featured_image']) && 'true' === sanitize_text_field(wp_unslash($_POST['copy_featured_image'])),
		);

		update_user_meta($user_id, 'bpd_preferences', $preferences);

		wp_send_json_success(array('message' => __('Preferences saved', 'bulk-page-duplicator')));
	}

	/**
	 * AJAX handler to get user preferences
	 */
	public function get_preferences() {
		// Verify nonce
		$nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';
		if (empty($nonce) || !wp_verify_nonce($nonce, 'bulk_page_duplication')) {
			wp_send_json_error(__('Security check failed', 'bulk-page-duplicator'));
		}

		// Check capability
		if (!current_user_can('manage_options')) {
			wp_send_json_error(__('You do not have permission to perform this action.', 'bulk-page-duplicator'));
		}

		wp_send_json_success($this->get_user_preferences());
	}

	/**
	 * AJAX handler for dry run (preview without creating)
	 */
	public function process_dry_run() {
		// Verify nonce
		$nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';
		if (empty($nonce) || !wp_verify_nonce($nonce, 'bulk_page_duplication')) {
			wp_send_json_error(__('Security check failed', 'bulk-page-duplicator'));
		}

		// Check capability
		if (!current_user_can('manage_options')) {
			wp_send_json_error(__('You do not have permission to perform this action.', 'bulk-page-duplicator'));
		}

		// Get data from AJAX request
		$template_id = isset($_POST['template_id']) ? intval(wp_unslash($_POST['template_id'])) : 0;
		$placeholders = isset($_POST['placeholders']) ? array_map('sanitize_text_field', (array) wp_unslash($_POST['placeholders'])) : [];
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
		$post_type = isset($_POST['post_type']) ? sanitize_key(wp_unslash($_POST['post_type'])) : 'page';
		$replace_options = isset($_POST['replace_options']) ? array_map('sanitize_key', (array) wp_unslash($_POST['replace_options'])) : [];
		$parent_page = isset($_POST['parent_page']) ? sanitize_text_field(wp_unslash($_POST['parent_page'])) : '0';

		// Validate template post exists
		$template_page = get_post($template_id);
		if (!$template_page || $template_page->post_type !== $post_type) {
			wp_send_json_error(__('Template not found', 'bulk-page-duplicator'));
		}

		if (!current_user_can('edit_post', $template_id)) {
			wp_send_json_error(__('You do not have permission to use this template.', 'bulk-page-duplicator'));
		}

		// Validate post type
		$post_type_obj = get_post_type_object($post_type);
		if (!$post_type_obj || !$post_type_obj->public || 'attachment' === $post_type) {
			wp_send_json_error(__('Invalid post type', 'bulk-page-duplicator'));
		}

		$create_posts_cap = isset($post_type_obj->cap->create_posts) ? $post_type_obj->cap->create_posts : $post_type_obj->cap->edit_posts;
		if (!current_user_can($create_posts_cap)) {
			wp_send_json_error(__('You do not have permission to create this type of content.', 'bulk-page-duplicator'));
		}

		$post_parent = $this->resolve_parent_page($parent_page, $template_page, $post_type_obj);
		if (is_wp_error($post_parent)) {
			wp_send_json_error($post_parent->get_error_message());
		}

		// Load core class for smart_replace
		if (!class_exists('Bulk_Page_Duplicator_Core')) {
			require_once dirname(dirname(__FILE__)) . '/includes/class-bulk-page-duplicator.php';
		}
		$core = new Bulk_Page_Duplicator_Core();

		$preview_items = [];
		$will_create = 0;
		$will_skip = 0;
		$seen_slugs = array();

		foreach ($batch_values as $value_set) {
			// Skip empty values
			if (empty($value_set) || (is_array($value_set) && empty(array_filter($value_set)))) {
				continue;
			}

			// For display purposes
			$display_value = is_array($value_set) ? implode(', ', $value_set) : $value_set;

			// Generate title
			$title = $template_page->post_title;
			if (in_array('title', $replace_options)) {
				$title = $core->multi_replace_public($title, $placeholders, $value_set);
			}

			// Generate slug
			$slug = $template_page->post_name;
			if (in_array('slug', $replace_options)) {
				$new_slug = $core->multi_replace_public($slug, $placeholders, $value_set);

				// Also replace slugified placeholders
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

			// Check the database and values already generated in this preview batch.
			$slug_key = $post_parent . ':' . $slug;
			$existing_post = $core->get_post_by_slug($slug, $post_type, $post_parent);
			$will_be_skipped = ($existing_post !== null) || isset($seen_slugs[$slug_key]);
			$seen_slugs[$slug_key] = true;

			if ($will_be_skipped) {
				$will_skip++;
			} else {
				$will_create++;
			}

			$preview_items[] = [
				'value' => $display_value,
				'title' => $title,
				'slug' => $slug,
				'status' => $will_be_skipped ? 'skip' : 'create',
				'reason' => $will_be_skipped ? sprintf(
					/* translators: %s: page slug */
					__('Slug "%s" already exists', 'bulk-page-duplicator'),
					$slug
				) : ''
			];
		}

		wp_send_json_success([
			'items' => $preview_items,
			'summary' => [
				'total' => count($preview_items),
				'will_create' => $will_create,
				'will_skip' => $will_skip
			]
		]);
	}

	/**
	 * Resolve and validate the parent selected for a hierarchical post type.
	 *
	 * @param string  $parent_page   Requested parent value.
	 * @param WP_Post $template_page Template post.
	 * @param object  $post_type_obj Post type object.
	 * @return int|WP_Error
	 */
	private function resolve_parent_page($parent_page, $template_page, $post_type_obj) {
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
	 * AJAX handler to get template data for preview
	 */
	public function get_template_data() {
		// Verify nonce
		$nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';
		if (empty($nonce) || !wp_verify_nonce($nonce, 'bulk_page_duplication')) {
			wp_send_json_error(__('Security check failed', 'bulk-page-duplicator'));
		}

		// Check capability
		if (!current_user_can('manage_options')) {
			wp_send_json_error(__('You do not have permission to perform this action.', 'bulk-page-duplicator'));
		}

		$template_id = isset($_POST['template_id']) ? intval(wp_unslash($_POST['template_id'])) : 0;

		if (!$template_id) {
			wp_send_json_error(__('No template selected', 'bulk-page-duplicator'));
		}

		$template = get_post($template_id);
		if (!$template) {
			wp_send_json_error(__('Template not found', 'bulk-page-duplicator'));
		}

		if (!current_user_can('edit_post', $template_id)) {
			wp_send_json_error(__('You do not have permission to use this template.', 'bulk-page-duplicator'));
		}

		// Get Elementor data if exists
		$elementor_data = get_post_meta($template_id, '_elementor_data', true);

		// Combine content for placeholder checking
		$searchable_content = $template->post_title . ' ' . $template->post_name . ' ' . $template->post_content;
		if (!empty($elementor_data)) {
			$searchable_content .= ' ' . $elementor_data;
		}

		wp_send_json_success(array(
			'title' => $template->post_title,
			'slug'  => $template->post_name,
			'searchable_content' => $searchable_content,
		));
	}

	/**
	 * AJAX handler to get taxonomies for a post type
	 */
	public function get_taxonomies() {
		// Verify nonce
		$nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';
		if (empty($nonce) || !wp_verify_nonce($nonce, 'bulk_page_duplication')) {
			wp_send_json_error(__('Security check failed', 'bulk-page-duplicator'));
		}

		// Check capability
		if (!current_user_can('manage_options')) {
			wp_send_json_error(__('You do not have permission to perform this action.', 'bulk-page-duplicator'));
		}

		$post_type = isset($_POST['post_type']) ? sanitize_key(wp_unslash($_POST['post_type'])) : 'post';
		$page = isset($_POST['paged']) ? max(1, absint(wp_unslash($_POST['paged']))) : 1;
		$per_page = isset($_POST['per_page']) ? absint(wp_unslash($_POST['per_page'])) : 100;
		$per_page = min(200, max(1, $per_page));
		$post_type_obj = get_post_type_object($post_type);

		if (!$post_type_obj || !$post_type_obj->public || 'attachment' === $post_type) {
			wp_send_json_error(__('Invalid post type', 'bulk-page-duplicator'));
		}

		$create_posts_cap = isset($post_type_obj->cap->create_posts) ? $post_type_obj->cap->create_posts : $post_type_obj->cap->edit_posts;
		if (!current_user_can($create_posts_cap)) {
			wp_send_json_error(__('You do not have permission to create this type of content.', 'bulk-page-duplicator'));
		}

		// Get taxonomies for the post type
		$taxonomies = get_object_taxonomies($post_type, 'objects');
		$result = array();

		foreach ($taxonomies as $taxonomy) {
			// Skip non-public taxonomies and post_format
			if (!$taxonomy->public || $taxonomy->name === 'post_format' || !current_user_can($taxonomy->cap->assign_terms)) {
				continue;
			}

			// Get terms for this taxonomy
			$terms = get_terms(array(
				'taxonomy'   => $taxonomy->name,
				'hide_empty' => false,
				'number'     => $per_page + 1,
				'offset'     => ($page - 1) * $per_page,
				'orderby'    => 'name',
				'order'      => 'ASC',
			));

			$term_list = array();
			$has_more = false;
			if (!is_wp_error($terms)) {
				$has_more = count($terms) > $per_page;
				if ($has_more) {
					array_pop($terms);
				}
				foreach ($terms as $term) {
					$term_list[] = array(
						'id'   => $term->term_id,
						'name' => $term->name,
						'slug' => $term->slug,
					);
				}
			}

			$result[] = array(
				'name'         => $taxonomy->name,
				'label'        => $taxonomy->labels->name,
				'hierarchical' => $taxonomy->hierarchical,
				'terms'        => $term_list,
				'has_more'     => $has_more,
			);
		}

		wp_send_json_success(array(
			'taxonomies' => $result,
			'page' => $page,
			'has_more' => !empty(array_filter(wp_list_pluck($result, 'has_more'))),
		));
	}

	/**
	 * AJAX handler to get batch history
	 */
	public function get_history() {
		// Verify nonce
		$nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';
		if (empty($nonce) || !wp_verify_nonce($nonce, 'bulk_page_duplication')) {
			wp_send_json_error(__('Security check failed', 'bulk-page-duplicator'));
		}

		// Check capability
		if (!current_user_can('manage_options')) {
			wp_send_json_error(__('You do not have permission to perform this action.', 'bulk-page-duplicator'));
		}

		if (!class_exists('Bulk_Page_Duplicator_Core')) {
			require_once dirname(dirname(__FILE__)) . '/includes/class-bulk-page-duplicator.php';
		}
		$core = new Bulk_Page_Duplicator_Core();
		$history = $core->get_batch_history(10);

		wp_send_json_success(array(
			'history' => $history,
		));
	}

	/**
	 * AJAX handler to rollback a batch operation
	 */
	public function rollback() {
		// Verify nonce
		$nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';
		if (empty($nonce) || !wp_verify_nonce($nonce, 'bulk_page_duplication')) {
			wp_send_json_error(__('Security check failed', 'bulk-page-duplicator'));
		}

		// Check capability
		if (!current_user_can('manage_options')) {
			wp_send_json_error(__('You do not have permission to perform this action.', 'bulk-page-duplicator'));
		}

		$session_key = isset($_POST['session_key']) ? sanitize_text_field(wp_unslash($_POST['session_key'])) : '';

		if (empty($session_key)) {
			wp_send_json_error(__('No operation specified', 'bulk-page-duplicator'));
		}

		if (!class_exists('Bulk_Page_Duplicator_Core')) {
			require_once dirname(dirname(__FILE__)) . '/includes/class-bulk-page-duplicator.php';
		}
		$core = new Bulk_Page_Duplicator_Core();
		$result = $core->rollback_batch($session_key);

		if ($result['success']) {
			wp_send_json_success(array(
				'message' => $result['message'],
			));
		} else {
			wp_send_json_error($result['message']);
		}
	}
}
