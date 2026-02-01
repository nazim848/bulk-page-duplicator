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
		add_action('wp_ajax_bpd_save_preferences', array($this, 'save_preferences'));
		add_action('wp_ajax_bpd_get_preferences', array($this, 'get_preferences'));
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
			$options[] = array(
				'id'    => $post->ID,
				'title' => $post->post_title . ' (ID: ' . $post->ID . ')' . $status_label,
			);
		}

		wp_send_json_success(array(
			'posts' => $options,
			'label' => $post_type_obj->labels->singular_name,
			'is_hierarchical' => $post_type_obj->hierarchical,
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
			'post_type' => isset($_POST['post_type']) ? sanitize_text_field(wp_unslash($_POST['post_type'])) : 'page',
			'template_id' => isset($_POST['template_id']) ? intval(wp_unslash($_POST['template_id'])) : '',
			'page_status' => isset($_POST['page_status']) ? sanitize_text_field(wp_unslash($_POST['page_status'])) : 'publish',
			'parent_page' => isset($_POST['parent_page']) ? sanitize_text_field(wp_unslash($_POST['parent_page'])) : '0',
			'replace_title' => isset($_POST['replace_title']) && $_POST['replace_title'] === 'true',
			'replace_slug' => isset($_POST['replace_slug']) && $_POST['replace_slug'] === 'true',
			'replace_content' => isset($_POST['replace_content']) && $_POST['replace_content'] === 'true',
			'replace_elementor' => isset($_POST['replace_elementor']) && $_POST['replace_elementor'] === 'true',
			'replace_seo' => isset($_POST['replace_seo']) && $_POST['replace_seo'] === 'true',
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
