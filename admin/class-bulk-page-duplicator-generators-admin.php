<?php

/**
 * Admin screens and AJAX endpoints for saved generators.
 *
 * @package BulkPageDuplicator
 */

if (!defined('ABSPATH')) exit;

class Bulk_Page_Duplicator_Generators_Admin {
	/**
	 * Admin page slug.
	 */
	const PAGE_SLUG = 'bpd-generators';

	/**
	 * Generator model.
	 *
	 * @var Bulk_Page_Duplicator_Generators
	 */
	private $generators;

	/**
	 * Constructor.
	 *
	 * @param Bulk_Page_Duplicator_Generators $generators Generator model.
	 */
	public function __construct($generators) {
		$this->generators = $generators;
	}

	/**
	 * Initialize admin hooks.
	 */
	public function init() {
		add_action('admin_menu', array($this, 'add_admin_menu'));
		add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
		add_action('admin_post_bpd_save_generator', array($this, 'handle_save'));
		add_action('admin_post_bpd_delete_generator', array($this, 'handle_delete'));
		add_action('wp_ajax_bpd_generator_plan', array($this, 'ajax_plan'));
		add_action('wp_ajax_bpd_generator_run', array($this, 'ajax_run'));
		add_action('wp_ajax_bpd_save_generator_from_form', array($this, 'ajax_save_from_form'));
	}

	/**
	 * Register the Tools > Page Generators screen.
	 */
	public function add_admin_menu() {
		add_management_page(
			__('Page Generators', 'bulk-page-duplicator'),
			__('Page Generators', 'bulk-page-duplicator'),
			'manage_options',
			self::PAGE_SLUG,
			array($this, 'render_page')
		);
	}

	/**
	 * URL of the generators screen.
	 *
	 * @param array $args Extra query args.
	 * @return string
	 */
	public static function page_url($args = array()) {
		return add_query_arg(array_merge(array('page' => self::PAGE_SLUG), $args), admin_url('tools.php'));
	}

	/**
	 * Enqueue assets for the generators screen and the bulk form's "Save as Generator" button.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_admin_scripts($hook) {
		if ('tools_page_bulk-page-duplicator' === $hook) {
			wp_enqueue_script('bpd-save-generator-js', BULK_PAGE_DUPLICATOR_URL . 'admin/assets/js/save-generator.js', array('jquery', 'wp-i18n'), BULK_PAGE_DUPLICATOR_VERSION, true);
			wp_set_script_translations('bpd-save-generator-js', 'bulk-page-duplicator', BULK_PAGE_DUPLICATOR_PATH . 'languages');
			wp_localize_script('bpd-save-generator-js', 'bpd_save_generator', array(
				'ajax_url' => admin_url('admin-ajax.php'),
				'nonce'    => wp_create_nonce('bpd_generators'),
				'list_url' => self::page_url(),
			));
			return;
		}

		if ('tools_page_' . self::PAGE_SLUG !== $hook) {
			return;
		}

		wp_enqueue_style('bpd-generators-css', BULK_PAGE_DUPLICATOR_URL . 'admin/assets/css/generators.css', array(), BULK_PAGE_DUPLICATOR_VERSION);

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only screen routing; the run flag is verified below.
		$action = isset($_GET['action']) ? sanitize_key(wp_unslash($_GET['action'])) : '';
		$generator_id = isset($_GET['generator']) ? absint(wp_unslash($_GET['generator'])) : 0;
		$run_nonce = isset($_GET['bpd_run']) ? sanitize_text_field(wp_unslash($_GET['bpd_run'])) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$generator = $generator_id && 'edit' === $action ? $this->generators->get_generator($generator_id) : null;
		$settings = $generator ? $generator : array(
			'id'          => 0,
			'post_type'   => 'page',
			'template_id' => 0,
			'options'     => Bulk_Page_Duplicator_Generators::default_options(),
		);

		wp_enqueue_script('bpd-generators-js', BULK_PAGE_DUPLICATOR_URL . 'admin/assets/js/generators.js', array('jquery', 'wp-i18n'), BULK_PAGE_DUPLICATOR_VERSION, true);
		wp_set_script_translations('bpd-generators-js', 'bulk-page-duplicator', BULK_PAGE_DUPLICATOR_PATH . 'languages');
		wp_localize_script('bpd-generators-js', 'bpd_generators', array(
			'ajax_url'       => admin_url('admin-ajax.php'),
			'nonce'          => wp_create_nonce('bpd_generators'),
			'bpd_nonce'      => wp_create_nonce('bulk_page_duplication'),
			'generator_id'   => (int) $settings['id'],
			'template_id'    => (int) $settings['template_id'],
			'parent_page'    => (string) $settings['options']['parent_page'],
			'taxonomy_terms' => (object) $settings['options']['taxonomy_terms'],
			'autorun'        => $generator && $run_nonce && wp_verify_nonce($run_nonce, 'bpd_run_generator_' . $generator['id']) ? 1 : 0,
		));
	}

	/**
	 * Render the list or editor screen.
	 */
	public function render_page() {
		if (!current_user_can('manage_options')) {
			wp_die(esc_html__('You do not have permission to access this page.', 'bulk-page-duplicator'));
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only screen routing.
		$action = isset($_GET['action']) ? sanitize_key(wp_unslash($_GET['action'])) : '';
		$generator_id = isset($_GET['generator']) ? absint(wp_unslash($_GET['generator'])) : 0;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$bpd_generators_model = $this->generators;
		$bpd_generators_notice = $this->get_notice();

		if ('edit' === $action || 'new' === $action) {
			$bpd_generator = $generator_id ? $this->generators->get_generator($generator_id) : null;
			if ($generator_id && !$bpd_generator) {
				wp_die(esc_html__('Generator not found.', 'bulk-page-duplicator'));
			}
			include BULK_PAGE_DUPLICATOR_PATH . 'admin/views/generator-edit.php';
			return;
		}

		$bpd_generators_list = $this->generators->get_generators();
		include BULK_PAGE_DUPLICATOR_PATH . 'admin/views/generators-page.php';
	}

	/**
	 * Build the admin notice requested by the redirect query args.
	 *
	 * @return array|null Keys: type, message.
	 */
	private function get_notice() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Display-only notice flags.
		$error = isset($_GET['bpd_error']) ? sanitize_key(wp_unslash($_GET['bpd_error'])) : '';
		$notice = isset($_GET['bpd_notice']) ? sanitize_key(wp_unslash($_GET['bpd_notice'])) : '';
		$row = isset($_GET['bpd_row']) ? absint(wp_unslash($_GET['bpd_row'])) : 0;
		$count = isset($_GET['bpd_count']) ? absint(wp_unslash($_GET['bpd_count'])) : 0;
		$trashed = isset($_GET['bpd_trashed']) ? absint(wp_unslash($_GET['bpd_trashed'])) : 0;
		$failed = isset($_GET['bpd_failed']) ? absint(wp_unslash($_GET['bpd_failed'])) : 0;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ($error) {
			$messages = array(
				'bpd_invalid_post_type'   => __('Invalid post type', 'bulk-page-duplicator'),
				'bpd_invalid_template'    => __('Please select a template of the chosen post type.', 'bulk-page-duplicator'),
				'bpd_template_permission' => __('You do not have permission to use this template.', 'bulk-page-duplicator'),
				'bpd_no_placeholders'     => __('Please enter at least one placeholder.', 'bulk-page-duplicator'),
				'bpd_confirm_trash'       => __('Removing rows moves their items to the trash. Please confirm the removal and save again.', 'bulk-page-duplicator'),
				'bpd_not_found'           => __('Generator not found.', 'bulk-page-duplicator'),
				'bpd_row_value_count'     => sprintf(
					/* translators: %1$d: row number, %2$d: number of placeholders. */
					__('Row %1$d must have %2$d comma-separated values (one for each placeholder).', 'bulk-page-duplicator'),
					$row,
					$count
				),
			);

			return array(
				'type'    => 'error',
				'message' => isset($messages[$error]) ? $messages[$error] : __('The generator could not be saved.', 'bulk-page-duplicator'),
			);
		}

		if ('saved' === $notice) {
			$message = __('Generator saved.', 'bulk-page-duplicator');
			if ($trashed) {
				/* translators: %d: number of items moved to the trash. */
				$message .= ' ' . sprintf(_n('%d item of a removed row was moved to the trash.', '%d items of removed rows were moved to the trash.', $trashed, 'bulk-page-duplicator'), $trashed);
			}
			if ($failed) {
				/* translators: %d: number of items that could not be trashed. */
				$message .= ' ' . sprintf(_n('%d item could not be moved to the trash.', '%d items could not be moved to the trash.', $failed, 'bulk-page-duplicator'), $failed);
			}
			return array('type' => $failed ? 'warning' : 'success', 'message' => $message);
		}

		if ('deleted' === $notice) {
			return array('type' => 'success', 'message' => __('Generator deleted. Its generated items were kept.', 'bulk-page-duplicator'));
		}

		return null;
	}

	/**
	 * Handle the editor form submission.
	 */
	public function handle_save() {
		if (!current_user_can('manage_options')) {
			wp_die(esc_html__('You do not have permission to perform this action.', 'bulk-page-duplicator'), 403);
		}

		check_admin_referer('bpd_save_generator');

		$generator_id = isset($_POST['generator_id']) ? absint(wp_unslash($_POST['generator_id'])) : 0;
		$existing = $generator_id ? $this->generators->get_generator($generator_id) : null;
		if ($generator_id && !$existing) {
			$this->redirect_with(array('bpd_error' => 'bpd_not_found'));
		}

		$back_args = $generator_id ? array('action' => 'edit', 'generator' => $generator_id) : array('action' => 'new');

		$rows = array();
		$rows_json = isset($_POST['rows_json']) ? trim((string) wp_unslash($_POST['rows_json'])) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Decoded JSON is sanitized by sanitize_rows().
		if ('' !== $rows_json) {
			$decoded = json_decode($rows_json, true);
			$rows = is_array($decoded) ? $decoded : array();
		} elseif (isset($_POST['rows']) && is_array($_POST['rows'])) {
			$rows = wp_unslash($_POST['rows']); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized by sanitize_rows().
		}

		$bulk_rows = isset($_POST['bulk_rows']) ? sanitize_textarea_field(wp_unslash($_POST['bulk_rows'])) : '';
		foreach (preg_split('/\r\n|\r|\n/', $bulk_rows) as $line) {
			if ('' !== trim($line)) {
				$rows[] = $line;
			}
		}

		$placeholders = isset($_POST['placeholders']) ? sanitize_text_field(wp_unslash($_POST['placeholders'])) : '';
		$options = isset($_POST['options']) && is_array($_POST['options']) ? wp_unslash($_POST['options']) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized by sanitize_generator_data().

		$data = $this->generators->sanitize_generator_data(array(
			'name'         => isset($_POST['name']) ? sanitize_text_field(wp_unslash($_POST['name'])) : '',
			'post_type'    => isset($_POST['post_type']) ? sanitize_key(wp_unslash($_POST['post_type'])) : 'page',
			'template_id'  => isset($_POST['template_id']) ? absint(wp_unslash($_POST['template_id'])) : 0,
			'placeholders' => array_map('trim', explode(',', $placeholders)),
			'options'      => $options,
			'rows'         => $rows,
		));

		if (is_wp_error($data)) {
			$error_args = array('bpd_error' => $data->get_error_code());
			if ('bpd_row_value_count' === $data->get_error_code()) {
				preg_match('/\d+/', $data->get_error_message(), $matches);
				$error_args['bpd_row'] = isset($matches[0]) ? absint($matches[0]) : 0;
				$error_args['bpd_count'] = count(array_filter(array_map('trim', explode(',', $placeholders)), 'strlen'));
			}
			$this->redirect_with(array_merge($back_args, $error_args));
		}

		$removed = $existing ? $this->generators->get_removed_rows_with_items($existing, $data['rows']) : array();
		$confirmed = isset($_POST['confirm_trash']) && '1' === sanitize_text_field(wp_unslash($_POST['confirm_trash']));
		if (!empty($removed) && !$confirmed) {
			$this->redirect_with(array_merge($back_args, array('bpd_error' => 'bpd_confirm_trash')));
		}

		$saved_id = $this->generators->save_generator($data, $generator_id);
		if (is_wp_error($saved_id)) {
			$this->redirect_with(array_merge($back_args, array('bpd_error' => 'bpd_save_failed')));
		}

		$counts = !empty($removed) ? $this->generators->trash_row_items($existing, $removed) : array('trashed' => 0, 'failed' => 0);

		$args = array(
			'action'      => 'edit',
			'generator'   => $saved_id,
			'bpd_notice'  => 'saved',
			'bpd_trashed' => $counts['trashed'],
			'bpd_failed'  => $counts['failed'],
		);

		$submit = isset($_POST['bpd_submit']) ? sanitize_key(wp_unslash($_POST['bpd_submit'])) : 'save';
		if ('run' === $submit) {
			$args['bpd_run'] = wp_create_nonce('bpd_run_generator_' . $saved_id);
		}

		$this->redirect_with($args);
	}

	/**
	 * Handle generator deletion.
	 */
	public function handle_delete() {
		if (!current_user_can('manage_options')) {
			wp_die(esc_html__('You do not have permission to perform this action.', 'bulk-page-duplicator'), 403);
		}

		$generator_id = isset($_GET['generator']) ? absint(wp_unslash($_GET['generator'])) : 0;
		check_admin_referer('bpd_delete_generator_' . $generator_id);

		if (!$this->generators->delete_generator($generator_id)) {
			$this->redirect_with(array('bpd_error' => 'bpd_not_found'));
		}

		$this->redirect_with(array('bpd_notice' => 'deleted'));
	}

	/**
	 * Redirect to the generators screen and exit.
	 *
	 * @param array $args Query args.
	 */
	private function redirect_with($args) {
		wp_safe_redirect(self::page_url($args));
		exit;
	}

	/**
	 * Verify the AJAX nonce and capability.
	 */
	private function verify_ajax() {
		$nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';
		if (empty($nonce) || !wp_verify_nonce($nonce, 'bpd_generators')) {
			wp_send_json_error(__('Security check failed', 'bulk-page-duplicator'));
		}

		if (!current_user_can('manage_options')) {
			wp_send_json_error(__('You do not have permission to perform this action.', 'bulk-page-duplicator'));
		}
	}

	/**
	 * Load the generator named in the AJAX request, or fail.
	 *
	 * @return array
	 */
	private function get_requested_generator() {
		// Nonce verified in verify_ajax().
		$generator_id = isset($_POST['generator_id']) ? absint(wp_unslash($_POST['generator_id'])) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$generator = $this->generators->get_generator($generator_id);
		if (!$generator) {
			wp_send_json_error(__('Generator not found.', 'bulk-page-duplicator'));
		}

		return $generator;
	}

	/**
	 * AJAX: list the rows a run would create or update.
	 */
	public function ajax_plan() {
		$this->verify_ajax();
		$generator = $this->get_requested_generator();

		$context = $this->generators->get_context($generator);
		if (is_wp_error($context)) {
			wp_send_json_error($context->get_error_message());
		}

		wp_send_json_success($this->generators->get_run_plan($generator));
	}

	/**
	 * AJAX: process one batch of rows.
	 */
	public function ajax_run() {
		$this->verify_ajax();
		$generator = $this->get_requested_generator();

		// Nonce verified in verify_ajax().
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$row_keys = isset($_POST['row_keys']) ? array_slice(array_map('sanitize_key', (array) wp_unslash($_POST['row_keys'])), 0, 50) : array();
		$operation_id = isset($_POST['operation_id']) ? sanitize_key(wp_unslash($_POST['operation_id'])) : '';
		$batch_index = isset($_POST['batch_index']) ? absint(wp_unslash($_POST['batch_index'])) : 0;
		$operation_complete = isset($_POST['operation_complete']) && 'true' === sanitize_text_field(wp_unslash($_POST['operation_complete']));
		$allow_updates = isset($_POST['allow_updates']) && 'true' === sanitize_text_field(wp_unslash($_POST['allow_updates']));
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$results = $this->generators->run_rows($generator, $row_keys, $operation_id, $batch_index, $operation_complete, $allow_updates);
		if (is_wp_error($results)) {
			wp_send_json_error($results->get_error_message());
		}

		// Reload to pick up the items just linked or created.
		$generator = $this->generators->get_generator($generator['id']);
		$items = $this->generators->get_linked_items($generator);
		$rows = array();
		foreach ($generator['rows'] as $row) {
			$rows[$row['key']] = $row;
		}

		foreach ($results as &$result) {
			$row_key = $result['row_key'];
			if (isset($rows[$row_key])) {
				$post = isset($items[$row_key]) ? $items[$row_key] : null;
				$result['has_item'] = $post && 'trash' !== $post->post_status;
				$result['cell_html'] = self::render_item_cell($post, $this->generators->get_row_state($generator, $rows[$row_key], $post));
			}
		}
		unset($result);

		wp_send_json_success(array('results' => $results));
	}

	/**
	 * AJAX: save the bulk duplication form as a new generator.
	 */
	public function ajax_save_from_form() {
		$this->verify_ajax();

		// Nonce verified in verify_ajax().
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$rows = array();
		if (isset($_POST['values']) && is_array($_POST['values'])) {
			$raw_values = map_deep(wp_unslash($_POST['values']), 'sanitize_text_field');
			foreach ($raw_values as $value_set) {
				$rows[] = array('values' => array_values((array) $value_set));
			}
		}

		$taxonomy_terms = array();
		if (isset($_POST['taxonomy_terms']) && is_array($_POST['taxonomy_terms'])) {
			$taxonomy_terms = map_deep(wp_unslash($_POST['taxonomy_terms']), 'absint');
		}

		$data = $this->generators->sanitize_generator_data(array(
			'name'         => isset($_POST['name']) ? sanitize_text_field(wp_unslash($_POST['name'])) : '',
			'post_type'    => isset($_POST['post_type']) ? sanitize_key(wp_unslash($_POST['post_type'])) : 'page',
			'template_id'  => isset($_POST['template_id']) ? absint(wp_unslash($_POST['template_id'])) : 0,
			'placeholders' => isset($_POST['placeholders']) ? array_map('sanitize_text_field', (array) wp_unslash($_POST['placeholders'])) : array(),
			'options'      => array(
				'status'          => isset($_POST['status']) ? sanitize_key(wp_unslash($_POST['status'])) : 'draft',
				'parent_page'     => isset($_POST['parent_page']) ? sanitize_text_field(wp_unslash($_POST['parent_page'])) : '0',
				'replace_options' => isset($_POST['replace_options']) ? array_map('sanitize_key', (array) wp_unslash($_POST['replace_options'])) : array(),
				'taxonomy_terms'  => $taxonomy_terms,
			),
			'rows'         => $rows,
		));
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if (is_wp_error($data)) {
			wp_send_json_error($data->get_error_message());
		}

		$generator_id = $this->generators->save_generator($data);
		if (is_wp_error($generator_id)) {
			wp_send_json_error($generator_id->get_error_message());
		}

		wp_send_json_success(array(
			'id'       => $generator_id,
			'edit_url' => self::page_url(array('action' => 'edit', 'generator' => $generator_id)),
		));
	}

	/**
	 * Render the "generated item" cell of a dataset row.
	 *
	 * @param WP_Post|null $post  Linked item.
	 * @param string       $state Row state from get_row_state().
	 * @return string HTML.
	 */
	public static function render_item_cell($post, $state) {
		if (!$post) {
			return '<span class="bpd-gen-state bpd-gen-state-missing">' . esc_html__('Not generated yet', 'bulk-page-duplicator') . '</span>';
		}

		$status_obj = get_post_status_object($post->post_status);
		$title = '' !== $post->post_title ? $post->post_title : __('(no title)', 'bulk-page-duplicator');
		$edit_url = get_edit_post_link($post->ID, 'raw');

		$html = '<span class="bpd-gen-item-title">';
		$html .= $edit_url && 'trash' !== $post->post_status ? '<a href="' . esc_url($edit_url) . '">' . esc_html($title) . '</a>' : esc_html($title);
		$html .= '</span> <span class="bpd-gen-post-status">' . esc_html($status_obj ? $status_obj->label : $post->post_status) . '</span>';

		$states = array(
			'trash'   => __('In trash', 'bulk-page-duplicator'),
			'changed' => __('Changed: updates on next run', 'bulk-page-duplicator'),
		);
		if (isset($states[$state])) {
			$html .= ' <span class="bpd-gen-state bpd-gen-state-' . esc_attr($state) . '">' . esc_html($states[$state]) . '</span>';
		}

		if ('trash' !== $post->post_status) {
			$links = array();
			if ($edit_url) {
				$links[] = '<a href="' . esc_url($edit_url) . '">' . esc_html__('Edit', 'bulk-page-duplicator') . '</a>';
			}
			$view_url = 'publish' === $post->post_status ? get_permalink($post) : get_preview_post_link($post);
			if ($view_url) {
				$links[] = '<a href="' . esc_url($view_url) . '" target="_blank" rel="noopener noreferrer">' . esc_html('publish' === $post->post_status ? __('View', 'bulk-page-duplicator') : __('Preview', 'bulk-page-duplicator')) . '</a>';
			}
			if (!empty($links)) {
				$html .= '<div class="bpd-gen-item-links">' . implode(' | ', $links) . '</div>';
			}
		}

		return $html;
	}
}
