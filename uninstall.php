<?php
// If uninstall not called from WordPress, exit
if (!defined('WP_UNINSTALL_PLUGIN')) {
	exit;
}

delete_option('bpd_batch_history');
delete_metadata('user', 0, 'bpd_preferences', '', true);

// Remove transients created by versions prior to 1.1.1.
global $wpdb;
$bulk_page_duplicator_transient_prefix = $wpdb->esc_like('_transient_bpd_current_session_') . '%';
$bulk_page_duplicator_timeout_prefix = $wpdb->esc_like('_transient_timeout_bpd_current_session_') . '%';
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Uninstall must remove legacy per-user transients whose complete keys are not stored.
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
		$bulk_page_duplicator_transient_prefix,
		$bulk_page_duplicator_timeout_prefix
	)
);
// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
