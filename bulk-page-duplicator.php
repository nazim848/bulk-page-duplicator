<?php

/**
 * Plugin Name: Bulk Page Duplicator
 * Description: Create multiple pages by duplicating an existing page and replacing specific text with different values.
 * Version: 1.0.0
 * Author: Nazim Husain
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: bulk-page-duplicator
 *
 * @package BulkPageDuplicator
 */

if (!defined('ABSPATH')) exit;

define('BULK_PAGE_DUPLICATOR_VERSION', '1.0.0');

define('BULK_PAGE_DUPLICATOR_PATH', plugin_dir_path(__FILE__));
define('BULK_PAGE_DUPLICATOR_URL', plugin_dir_url(__FILE__));

// Load core class
require_once BULK_PAGE_DUPLICATOR_PATH . 'includes/class-bulk-page-duplicator.php';
// Load admin class if in admin
if (is_admin()) {
	require_once BULK_PAGE_DUPLICATOR_PATH . 'admin/class-bulk-page-duplicator-admin.php';
	$bulk_page_duplicator_admin = new Bulk_Page_Duplicator_Admin();
	$bulk_page_duplicator_admin->init();
}
