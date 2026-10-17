<?php

/**
 * One-click duplication from post lists, the admin bar and the editor.
 *
 * @package BulkPageDuplicator
 */

if (!defined('ABSPATH')) exit;

class Bulk_Page_Duplicator_Quick_Duplicate {
	/**
	 * Core logic.
	 *
	 * @var Bulk_Page_Duplicator_Core
	 */
	private $core;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->core = new Bulk_Page_Duplicator_Core();
	}

	/**
	 * Register hooks.
	 */
	public function init() {
		add_filter('post_row_actions', array($this, 'add_row_actions'), 10, 2);
		add_filter('page_row_actions', array($this, 'add_row_actions'), 10, 2);
		add_action('admin_action_bpd_duplicate_post', array($this, 'handle_duplicate'));
		add_action('current_screen', array($this, 'register_bulk_action'));
		add_action('admin_notices', array($this, 'show_notices'));
		add_action('admin_bar_menu', array($this, 'add_admin_bar_node'), 80);
		add_action('post_submitbox_misc_actions', array($this, 'add_submitbox_link'));
		add_action('enqueue_block_editor_assets', array($this, 'enqueue_editor_script'));
	}

	/**
	 * Build a nonce-protected duplicate URL.
	 *
	 * @param int    $post_id  Post to duplicate.
	 * @param string $redirect Where to go afterwards: 'list' or 'edit'.
	 * @return string
	 */
	public function get_duplicate_url($post_id, $redirect = 'list') {
		$url = add_query_arg(
			array(
				'action'   => 'bpd_duplicate_post',
				'post'     => absint($post_id),
				'redirect' => 'edit' === $redirect ? 'edit' : 'list',
			),
			admin_url('admin.php')
		);

		return wp_nonce_url($url, 'bpd_duplicate_' . absint($post_id));
	}

	/**
	 * Build the Bulk Page Duplicator URL with an item preselected as the template.
	 *
	 * @param WP_Post $post Template item.
	 * @return string
	 */
	private function get_bulk_url($post) {
		return add_query_arg(
			array(
				// No post_type argument: WordPress treats it as a different admin page.
				'page'     => 'bulk-page-duplicator',
				'template' => $post->ID,
			),
			admin_url('tools.php')
		);
	}

	/**
	 * Add Duplicate links to list table rows.
	 *
	 * @param array   $actions Row actions.
	 * @param WP_Post $post    Current item.
	 * @return array
	 */
	public function add_row_actions($actions, $post) {
		if ('trash' === $post->post_status || !$this->core->can_duplicate_post($post)) {
			return $actions;
		}

		$actions['bpd_duplicate'] = sprintf(
			'<a href="%1$s" aria-label="%2$s">%3$s</a>',
			esc_url($this->get_duplicate_url($post->ID)),
			/* translators: %s: item title. */
			esc_attr(sprintf(__('Duplicate &#8220;%s&#8221;', 'bulk-page-duplicator'), get_the_title($post))),
			esc_html__('Duplicate', 'bulk-page-duplicator')
		);

		if (current_user_can('manage_options')) {
			$actions['bpd_bulk_duplicate'] = sprintf(
				'<a href="%1$s" aria-label="%2$s">%3$s</a>',
				esc_url($this->get_bulk_url($post)),
				/* translators: %s: item title. */
				esc_attr(sprintf(__('Use &#8220;%s&#8221; as a bulk duplication template', 'bulk-page-duplicator'), get_the_title($post))),
				esc_html__('Bulk Duplicate', 'bulk-page-duplicator')
			);
		}

		return $actions;
	}

	/**
	 * Duplicate a single item from a link.
	 */
	public function handle_duplicate() {
		$post_id = isset($_GET['post']) ? absint(wp_unslash($_GET['post'])) : 0;
		check_admin_referer('bpd_duplicate_' . $post_id);

		$new_post_id = $this->core->duplicate_single_post($post_id);
		if (is_wp_error($new_post_id)) {
			wp_die(esc_html($new_post_id->get_error_message()), esc_html__('Duplicate failed', 'bulk-page-duplicator'), array('response' => 403, 'back_link' => true));
		}

		$redirect = isset($_GET['redirect']) ? sanitize_key(wp_unslash($_GET['redirect'])) : 'list';
		$url = 'edit' === $redirect ? get_edit_post_link($new_post_id, 'url') : '';
		if (!$url) {
			$url = wp_get_referer();
			if (!$url || false !== strpos($url, 'action=bpd_duplicate_post')) {
				$url = admin_url('edit.php?post_type=' . get_post_type($new_post_id));
			}
			$url = remove_query_arg(array('bpd_duplicated', 'bpd_new_post', 'bpd_failed'), $url);
			$url = add_query_arg(array('bpd_duplicated' => 1, 'bpd_new_post' => $new_post_id), $url);
		}

		wp_safe_redirect($url);
		exit;
	}

	/**
	 * Register the Duplicate bulk action on supported list screens.
	 *
	 * @param WP_Screen $screen Current screen.
	 */
	public function register_bulk_action($screen) {
		if ('edit' !== $screen->base || !in_array($screen->post_type, $this->core->get_duplicable_post_types(), true)) {
			return;
		}

		add_filter('bulk_actions-' . $screen->id, array($this, 'add_bulk_action'));
		add_filter('handle_bulk_actions-' . $screen->id, array($this, 'handle_bulk_action'), 10, 3);
	}

	/**
	 * Add the Duplicate bulk action.
	 *
	 * @param array $actions Bulk actions.
	 * @return array
	 */
	public function add_bulk_action($actions) {
		$post_status = isset($_GET['post_status']) ? sanitize_key(wp_unslash($_GET['post_status'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only selects which actions to display.
		if ('trash' !== $post_status) {
			$actions['bpd_duplicate'] = __('Duplicate', 'bulk-page-duplicator');
		}

		return $actions;
	}

	/**
	 * Duplicate the selected items. WordPress verifies the bulk-posts nonce before this runs.
	 *
	 * @param string $redirect_to Redirect URL.
	 * @param string $action      Bulk action.
	 * @param int[]  $post_ids    Selected items.
	 * @return string
	 */
	public function handle_bulk_action($redirect_to, $action, $post_ids) {
		if ('bpd_duplicate' !== $action) {
			return $redirect_to;
		}

		$duplicated = 0;
		$failed = 0;
		foreach ((array) $post_ids as $post_id) {
			$result = $this->core->duplicate_single_post(absint($post_id));
			if (is_wp_error($result)) {
				$failed++;
			} else {
				$duplicated++;
			}
		}

		$redirect_to = remove_query_arg(array('bpd_duplicated', 'bpd_new_post', 'bpd_failed'), $redirect_to);
		return add_query_arg(array('bpd_duplicated' => $duplicated, 'bpd_failed' => $failed), $redirect_to);
	}

	/**
	 * Show the result of a duplicate action.
	 */
	public function show_notices() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only display of a redirect result.
		if (!isset($_GET['bpd_duplicated'])) {
			return;
		}

		$duplicated = absint(wp_unslash($_GET['bpd_duplicated']));
		$failed = isset($_GET['bpd_failed']) ? absint(wp_unslash($_GET['bpd_failed'])) : 0;
		$new_post_id = isset($_GET['bpd_new_post']) ? absint(wp_unslash($_GET['bpd_new_post'])) : 0;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ($duplicated > 0) {
			/* translators: %d: number of items duplicated. */
			$message = esc_html(sprintf(_n('%d item duplicated as a draft.', '%d items duplicated as drafts.', $duplicated, 'bulk-page-duplicator'), $duplicated));
			if ($new_post_id && 1 === $duplicated && current_user_can('edit_post', $new_post_id)) {
				$message .= sprintf(' <a href="%1$s">%2$s</a>', esc_url(get_edit_post_link($new_post_id, 'url')), esc_html__('Edit the copy', 'bulk-page-duplicator'));
			}
			printf('<div class="notice notice-success is-dismissible"><p>%s</p></div>', $message); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above.
		}

		if ($failed > 0) {
			/* translators: %d: number of items that could not be duplicated. */
			$message = sprintf(_n('%d item could not be duplicated.', '%d items could not be duplicated.', $failed, 'bulk-page-duplicator'), $failed);
			printf('<div class="notice notice-error is-dismissible"><p>%s</p></div>', esc_html($message));
		}
	}

	/**
	 * Add a Duplicate link to the admin bar on single item views and edit screens.
	 *
	 * @param WP_Admin_Bar $admin_bar Admin bar.
	 */
	public function add_admin_bar_node($admin_bar) {
		$post = null;

		if (is_admin()) {
			$screen = function_exists('get_current_screen') ? get_current_screen() : null;
			if ($screen && 'post' === $screen->base && isset($_GET['post'])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only reads the item being edited.
				$post = get_post(absint(wp_unslash($_GET['post']))); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			}
		} elseif (is_singular()) {
			$post = get_queried_object();
		}

		if (!$post instanceof WP_Post || 'auto-draft' === $post->post_status || !$this->core->can_duplicate_post($post)) {
			return;
		}

		$admin_bar->add_node(array(
			'id'    => 'bpd-duplicate',
			'title' => esc_html__('Duplicate', 'bulk-page-duplicator'),
			'href'  => $this->get_duplicate_url($post->ID, 'edit'),
		));
	}

	/**
	 * Add a Duplicate link to the classic editor's publish box.
	 *
	 * @param WP_Post $post Item being edited.
	 */
	public function add_submitbox_link($post) {
		if ('auto-draft' === $post->post_status || !$this->core->can_duplicate_post($post)) {
			return;
		}

		printf(
			'<div class="misc-pub-section bpd-duplicate"><a href="%1$s">%2$s</a></div>',
			esc_url($this->get_duplicate_url($post->ID, 'edit')),
			esc_html__('Duplicate as new draft', 'bulk-page-duplicator')
		);
	}

	/**
	 * Add a Duplicate button to the block editor's summary panel.
	 */
	public function enqueue_editor_script() {
		$post = get_post();
		if (!$post || 'auto-draft' === $post->post_status || !$this->core->can_duplicate_post($post)) {
			return;
		}

		wp_enqueue_script(
			'bulk-page-duplicator-editor',
			BULK_PAGE_DUPLICATOR_URL . 'admin/assets/js/editor-duplicate.js',
			array('wp-plugins', 'wp-edit-post', 'wp-element', 'wp-components', 'wp-data', 'wp-i18n'),
			BULK_PAGE_DUPLICATOR_VERSION,
			true
		);
		wp_set_script_translations('bulk-page-duplicator-editor', 'bulk-page-duplicator', BULK_PAGE_DUPLICATOR_PATH . 'languages');
		wp_localize_script('bulk-page-duplicator-editor', 'bpdEditorDuplicate', array(
			'url' => $this->get_duplicate_url($post->ID, 'edit'),
		));
	}
}
