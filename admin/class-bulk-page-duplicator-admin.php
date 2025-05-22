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
}
