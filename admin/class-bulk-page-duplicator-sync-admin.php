<?php

/**
 * Admin screens for Template Sync
 *
 * @package BulkPageDuplicator
 */

if (!defined('ABSPATH')) exit;

class Bulk_Page_Duplicator_Sync_Admin {
	/**
	 * Admin page slug of the sync screen.
	 */
	const PAGE_SLUG = 'bpd-template-sync';

	/**
	 * Nonce action for sync requests.
	 */
	const NONCE_ACTION = 'bpd_template_sync';

	/**
	 * Capability for the sync screen, matching the other Bulk Page Duplicator screens.
	 * Each template and item is also checked with edit_post.
	 */
	const CAPABILITY = 'manage_options';

	/**
	 * Items previewed per request.
	 */
	const PREVIEW_PER_PAGE = 20;

	/**
	 * Maximum items applied per request.
	 */
	const APPLY_MAX_BATCH = 25;

	/**
	 * Sync service.
	 *
	 * @var Bulk_Page_Duplicator_Sync
	 */
	private $sync;

	/**
	 * Constructor.
	 *
	 * @param Bulk_Page_Duplicator_Sync $sync Sync service.
	 */
	public function __construct($sync) {
		$this->sync = $sync;
	}

	/**
	 * Register admin hooks.
	 */
	public function init() {
		add_action('admin_menu', array($this, 'add_admin_page'));
		add_action('admin_head', array($this, 'hide_admin_page'));
		add_filter('submenu_file', array($this, 'highlight_submenu'));
		add_action('admin_enqueue_scripts', array($this, 'enqueue_scripts'));
		add_action('add_meta_boxes', array($this, 'add_meta_box'), 10, 2);
		add_action('wp_ajax_bpd_sync_preview', array($this, 'ajax_preview'));
		add_action('wp_ajax_bpd_sync_apply', array($this, 'ajax_apply'));
		add_action('wp_ajax_bpd_sync_history', array($this, 'ajax_history'));
	}

	/**
	 * URL of the sync screen.
	 *
	 * @param int $template_id Optional template to open.
	 * @return string
	 */
	public static function get_page_url($template_id = 0) {
		$args = array('page' => self::PAGE_SLUG);
		if ($template_id) {
			$args['template_id'] = absint($template_id);
		}
		return add_query_arg($args, admin_url('tools.php'));
	}

	/**
	 * Register the sync screen under Tools.
	 */
	public function add_admin_page() {
		add_management_page(
			__('Template Sync', 'bulk-page-duplicator'),
			__('Template Sync', 'bulk-page-duplicator'),
			self::CAPABILITY,
			self::PAGE_SLUG,
			array($this, 'render_page')
		);
	}

	/**
	 * Keep the sync screen out of the menu; it is linked from Bulk Page Duplicator and the editor.
	 */
	public function hide_admin_page() {
		remove_submenu_page('tools.php', self::PAGE_SLUG);
	}

	/**
	 * Highlight Bulk Page Duplicator in the menu while on the sync screen.
	 *
	 * @param string|null $submenu_file Current submenu file.
	 * @return string|null
	 */
	public function highlight_submenu($submenu_file) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only menu state.
		$page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
		if (self::PAGE_SLUG === $page) {
			return 'bulk-page-duplicator';
		}
		return $submenu_file;
	}

	/**
	 * Template requested on the sync screen.
	 *
	 * @return int
	 */
	private function get_requested_template_id() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only screen selection; permissions are checked before use.
		return isset($_GET['template_id']) ? absint(wp_unslash($_GET['template_id'])) : 0;
	}

	/**
	 * Enqueue sync screen assets.
	 *
	 * @param string $hook Admin page hook.
	 */
	public function enqueue_scripts($hook) {
		if ('tools_page_' . self::PAGE_SLUG !== $hook) {
			return;
		}

		wp_enqueue_style('bulk-page-duplicator-css', BULK_PAGE_DUPLICATOR_URL . 'admin/assets/css/bulk-page-duplicator.css', array(), BULK_PAGE_DUPLICATOR_VERSION);
		wp_enqueue_style('bulk-page-duplicator-sync-css', BULK_PAGE_DUPLICATOR_URL . 'admin/assets/css/sync.css', array('bulk-page-duplicator-css'), BULK_PAGE_DUPLICATOR_VERSION);

		$template_id = $this->get_requested_template_id();
		$template = $template_id ? $this->sync->validate_template($template_id) : null;
		if (!$template || is_wp_error($template)) {
			return;
		}

		wp_enqueue_script('bulk-page-duplicator-sync-js', BULK_PAGE_DUPLICATOR_URL . 'admin/assets/js/sync.js', array('jquery', 'wp-i18n'), BULK_PAGE_DUPLICATOR_VERSION, true);
		wp_set_script_translations('bulk-page-duplicator-sync-js', 'bulk-page-duplicator', BULK_PAGE_DUPLICATOR_PATH . 'languages');
		wp_localize_script('bulk-page-duplicator-sync-js', 'bpd_sync', array(
			'ajax_url'      => admin_url('admin-ajax.php'),
			'nonce'         => wp_create_nonce(self::NONCE_ACTION),
			'template_id'   => (int) $template->ID,
			'risky_fields'  => $this->sync->get_risky_fields(),
			'apply_batch'   => 10,
		));
	}

	/**
	 * Render the sync screen.
	 */
	public function render_page() {
		if (!current_user_can(self::CAPABILITY)) {
			wp_die(esc_html__('You do not have permission to access this page.', 'bulk-page-duplicator'));
		}

		$bulk_page_duplicator_sync = $this->sync;
		$bulk_page_duplicator_template_id = $this->get_requested_template_id();
		$bulk_page_duplicator_template = $bulk_page_duplicator_template_id ? $this->sync->validate_template($bulk_page_duplicator_template_id) : null;

		include dirname(__FILE__) . '/views/sync-page.php';
	}

	/**
	 * Add the Template Sync box to the editor of templates and generated items.
	 *
	 * Classic meta boxes are shown by both the classic and the block editor.
	 *
	 * @param string  $post_type Post type.
	 * @param WP_Post $post      Post being edited.
	 */
	public function add_meta_box($post_type, $post = null) {
		if (!$post instanceof WP_Post || 'auto-draft' === $post->post_status) {
			return;
		}
		if (!current_user_can(self::CAPABILITY) || !in_array($post_type, $this->sync->get_core()->get_duplicable_post_types(), true) || !current_user_can('edit_post', $post->ID)) {
			return;
		}

		$count = $this->sync->count_generated_items($post->ID);
		$generated_from = absint(get_post_meta($post->ID, '_bpd_template_id', true));
		if (!$count && !$generated_from) {
			return;
		}

		add_meta_box(
			'bpd-template-sync',
			__('Bulk Page Duplicator', 'bulk-page-duplicator'),
			array($this, 'render_meta_box'),
			$post_type,
			'side',
			'default',
			array(
				'count'          => $count,
				'generated_from' => $generated_from,
			)
		);
	}

	/**
	 * Render the Template Sync box.
	 *
	 * @param WP_Post $post Post being edited.
	 * @param array   $box  Meta box arguments.
	 */
	public function render_meta_box($post, $box) {
		$count = isset($box['args']['count']) ? absint($box['args']['count']) : 0;
		$generated_from = isset($box['args']['generated_from']) ? absint($box['args']['generated_from']) : 0;

		if ($count) {
			echo '<p>';
			echo esc_html(sprintf(
				/* translators: %s: number of generated items. */
				_n('%s item was generated from this template.', '%s items were generated from this template.', $count, 'bulk-page-duplicator'),
				number_format_i18n($count)
			));
			echo '</p>';
			echo '<p><a class="button button-secondary" href="' . esc_url(self::get_page_url($post->ID)) . '">' . esc_html__('Sync changes', 'bulk-page-duplicator') . '</a></p>';
			echo '<p class="description">' . esc_html__('Save your changes to this template first. You can preview the sync before anything is changed.', 'bulk-page-duplicator') . '</p>';
		}

		if ($generated_from) {
			$template = get_post($generated_from);
			echo '<p>';
			if ($template && current_user_can('edit_post', $template->ID)) {
				$template_title = '' !== $template->post_title ? $template->post_title : __('(no title)', 'bulk-page-duplicator');
				printf(
					/* translators: %s: link to the template. */
					esc_html__('Generated from the template %s.', 'bulk-page-duplicator'),
					'<a href="' . esc_url((string) get_edit_post_link($template->ID)) . '">' . esc_html($template_title) . '</a>'
				);
			} else {
				esc_html_e('Generated from a template.', 'bulk-page-duplicator');
			}
			echo '</p>';
			echo '<p class="description">' . esc_html__('If you edit this item by hand, template syncs skip it unless you choose to overwrite it.', 'bulk-page-duplicator') . '</p>';
		}
	}

	/**
	 * Validate a sync AJAX request and return the template and fields.
	 *
	 * @return array 'template' and 'fields'.
	 */
	private function validate_request() {
		$nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';
		if (empty($nonce) || !wp_verify_nonce($nonce, self::NONCE_ACTION)) {
			wp_send_json_error(__('Security check failed', 'bulk-page-duplicator'));
		}

		if (!current_user_can(self::CAPABILITY)) {
			wp_send_json_error(__('You do not have permission to perform this action.', 'bulk-page-duplicator'));
		}

		$template_id = isset($_POST['template_id']) ? absint(wp_unslash($_POST['template_id'])) : 0;
		$template = $this->sync->validate_template($template_id);
		if (is_wp_error($template)) {
			wp_send_json_error($template->get_error_message());
		}

		$fields = isset($_POST['fields']) ? $this->sync->sanitize_fields(wp_unslash($_POST['fields'])) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized by sanitize_fields().

		return array(
			'template' => $template,
			'fields'   => $fields,
		);
	}

	/**
	 * Validate the selected fields of a request that will write.
	 *
	 * @param string[] $fields Sanitized fields.
	 */
	private function validate_fields($fields) {
		if (empty($fields)) {
			wp_send_json_error(__('Select at least one thing to sync.', 'bulk-page-duplicator'));
		}

		$confirm_risky = isset($_POST['confirm_risky']) && 'true' === sanitize_text_field(wp_unslash($_POST['confirm_risky'])); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified in validate_request().
		if (array_intersect($fields, $this->sync->get_risky_fields()) && !$confirm_risky) {
			wp_send_json_error(__('Syncing titles or slugs needs to be confirmed.', 'bulk-page-duplicator'));
		}
	}

	/**
	 * AJAX: preview one page of generated items.
	 */
	public function ajax_preview() {
		$request = $this->validate_request();
		$this->validate_fields($request['fields']);

		$paged = isset($_POST['paged']) ? max(1, absint(wp_unslash($_POST['paged']))) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified in validate_request().
		$page = $this->sync->get_generated_item_ids($request['template']->ID, $paged, self::PREVIEW_PER_PAGE);

		$items = array();
		foreach ($page['ids'] as $post_id) {
			$items[] = $this->sync->preview_item($request['template']->ID, $post_id, $request['fields']);
		}

		wp_send_json_success(array(
			'items'    => $items,
			'page'     => $paged,
			'total'    => $page['total'],
			'has_more' => $page['has_more'],
		));
	}

	/**
	 * AJAX: sync a batch of items.
	 */
	public function ajax_apply() {
		$request = $this->validate_request();
		$this->validate_fields($request['fields']);

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Verified in validate_request().
		$post_ids = isset($_POST['post_ids']) ? array_values(array_unique(array_filter(array_map('absint', (array) wp_unslash($_POST['post_ids']))))) : array();
		$override_ids = isset($_POST['override_ids']) ? array_map('absint', (array) wp_unslash($_POST['override_ids'])) : array();
		$include_edited = isset($_POST['include_edited']) && 'true' === sanitize_text_field(wp_unslash($_POST['include_edited']));
		$operation_id = isset($_POST['operation_id']) ? sanitize_key(wp_unslash($_POST['operation_id'])) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if (count($post_ids) > self::APPLY_MAX_BATCH) {
			wp_send_json_error(__('Too many items in one request.', 'bulk-page-duplicator'));
		}

		$results = array();
		$counts = array_fill_keys(array('updated', 'unchanged', 'skipped', 'error'), 0);
		foreach ($post_ids as $post_id) {
			$allow_edited = $include_edited || in_array($post_id, $override_ids, true);
			$result = $this->sync->sync_item($request['template']->ID, $post_id, $request['fields'], $allow_edited);
			$counts[$result['status']]++;
			$results[] = $result;
		}

		$this->sync->get_core()->flush_builder_caches();

		if (!empty($results)) {
			$this->sync->record_history($request['template'], $operation_id, $request['fields'], $counts);
		}

		wp_send_json_success(array(
			'results' => $results,
			'counts'  => $counts,
		));
	}

	/**
	 * AJAX: recent syncs of a template.
	 */
	public function ajax_history() {
		$request = $this->validate_request();

		wp_send_json_success(array(
			'history' => $this->sync->get_history($request['template']->ID),
		));
	}
}
