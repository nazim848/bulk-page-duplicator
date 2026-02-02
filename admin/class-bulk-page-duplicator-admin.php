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
		wp_enqueue_script('bulk-page-duplicator-js', plugin_dir_url(__FILE__) . 'assets/js/bulk-page-duplicator.js', array('jquery'), BULK_PAGE_DUPLICATOR_VERSION, true);
		wp_localize_script('bulk-page-duplicator-js', 'bulk_page_dup_ajax', array(
			'ajax_url' => admin_url('admin-ajax.php'),
			'nonce' => wp_create_nonce('bulk_page_duplication')
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

		$post_type = isset($_POST['post_type']) ? sanitize_text_field(wp_unslash($_POST['post_type'])) : 'page';

		// Validate post type exists and is public
		$post_type_obj = get_post_type_object($post_type);
		if (!$post_type_obj || !$post_type_obj->public) {
			wp_send_json_error(__('Invalid post type', 'bulk-page-duplicator'));
		}

		// Get posts of the specified type
		$args = array(
			'post_type'      => $post_type,
			'posts_per_page' => -1,
			'orderby'        => 'title',
			'order'          => 'ASC',
			'post_status'    => array('publish', 'draft', 'private'),
		);

		$posts = get_posts($args);
		$options = array();

		foreach ($posts as $post) {
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
		));
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
			$raw_values = wp_unslash($_POST['values']);
			foreach ((array) $raw_values as $value_set) {
				if (is_array($value_set)) {
					$batch_values[] = array_map('sanitize_text_field', $value_set);
				} else {
					$batch_values[] = [sanitize_text_field($value_set)];
				}
			}
		}
		$post_type = isset($_POST['post_type']) ? sanitize_text_field(wp_unslash($_POST['post_type'])) : 'page';
		$replace_options = isset($_POST['replace_options']) ? array_map('sanitize_text_field', (array) wp_unslash($_POST['replace_options'])) : [];

		// Validate template post exists
		$template_page = get_post($template_id);
		if (!$template_page) {
			wp_send_json_error(__('Template not found', 'bulk-page-duplicator'));
		}

		// Validate post type
		$post_type_obj = get_post_type_object($post_type);
		if (!$post_type_obj || !$post_type_obj->public) {
			wp_send_json_error(__('Invalid post type', 'bulk-page-duplicator'));
		}

		// Load core class for smart_replace
		if (!class_exists('Bulk_Page_Duplicator_Core')) {
			require_once dirname(dirname(__FILE__)) . '/includes/class-bulk-page-duplicator.php';
		}
		$core = new Bulk_Page_Duplicator_Core();

		$preview_items = [];
		$will_create = 0;
		$will_skip = 0;

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

			// Check if post with this slug already exists
			$existing_post = get_page_by_path($slug, OBJECT, $post_type);
			$will_be_skipped = ($existing_post !== null);

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
				'reason' => $will_be_skipped ? sprintf(__('Slug "%s" already exists', 'bulk-page-duplicator'), $slug) : ''
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

		wp_send_json_success(array(
			'title' => $template->post_title,
			'slug'  => $template->post_name,
		));
	}
}
