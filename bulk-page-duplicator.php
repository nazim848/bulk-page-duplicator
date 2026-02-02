<?php
/*
Plugin Name: Bulk Page Duplicator
Description: Create multiple pages by duplicating an existing page and replacing specific text with different values.
Version: 1.1.0
Author: Nazim Husain
Author URI: https://nazimansari.com
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html
Text Domain: bulk-page-duplicator
Domain Path: /languages
Requires at least: 5.0
Tested up to: 6.9
Requires PHP: 7.2

Bulk Page Duplicator is free software: you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation, either version 2 of the License, or
any later version.

Bulk Page Duplicator is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with Bulk Page Duplicator. If not, see https://www.gnu.org/licenses/gpl-2.0.html.
*/

// If this file is called directly, abort.
if (!defined('WPINC')) {
	die;
}

define('BULK_PAGE_DUPLICATOR_VERSION', '1.1.0');

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
