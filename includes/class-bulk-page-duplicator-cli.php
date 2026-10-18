<?php

/**
 * WP-CLI commands: run page generators and template syncs from the command line.
 *
 * @package BulkPageDuplicator
 */

if (!defined('ABSPATH')) exit;

/**
 * Manage Bulk Page Duplicator generators and template syncs.
 *
 * Commands act with the permissions of the WordPress user given in --user,
 * exactly as the admin screens do.
 *
 * ## EXAMPLES
 *
 *     wp bulk-page-duplicator generator list --user=admin
 *     wp bulk-page-duplicator generator run 42 --user=admin
 *     wp bulk-page-duplicator sync 17 --fields=content,excerpt --dry-run --user=admin
 */
class Bulk_Page_Duplicator_CLI {
	/**
	 * Items processed per batch.
	 */
	const BATCH_SIZE = 20;

	/**
	 * Core logic.
	 *
	 * @var Bulk_Page_Duplicator_Core
	 */
	private $core;

	/**
	 * Generators service.
	 *
	 * @var Bulk_Page_Duplicator_Generators
	 */
	private $generators;

	/**
	 * Sync service.
	 *
	 * @var Bulk_Page_Duplicator_Sync
	 */
	private $sync;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->core = new Bulk_Page_Duplicator_Core();
		$this->generators = new Bulk_Page_Duplicator_Generators($this->core);
		$this->sync = new Bulk_Page_Duplicator_Sync($this->core);
	}

	/**
	 * Manage page generators.
	 *
	 * ## OPTIONS
	 *
	 * <action>
	 * : What to do.
	 * ---
	 * options:
	 *   - list
	 *   - run
	 * ---
	 *
	 * [<id>]
	 * : Generator ID (for run).
	 *
	 * [--dry-run]
	 * : Show what a run would create and update without writing anything.
	 *
	 * [--no-update]
	 * : Only create missing items; leave items of changed rows as they are.
	 *
	 * [--format=<format>]
	 * : Output format for list.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 *   - ids
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     # List generators.
	 *     wp bulk-page-duplicator generator list --user=admin
	 *
	 *     # Create missing items and re-render changed rows of generator 42.
	 *     wp bulk-page-duplicator generator run 42 --user=admin
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function generator($args, $assoc_args) {
		$this->require_admin();
		$action = isset($args[0]) ? $args[0] : '';

		if ('list' === $action) {
			$this->list_generators(WP_CLI\Utils\get_flag_value($assoc_args, 'format', 'table'));
			return;
		}

		if ('run' !== $action || empty($args[1])) {
			WP_CLI::error('Usage: wp bulk-page-duplicator generator run <id> [--dry-run] [--no-update]');
		}

		$generator = $this->generators->get_generator(absint($args[1]));
		if (!$generator) {
			WP_CLI::error(sprintf('Generator %d not found.', absint($args[1])));
		}

		$plan = $this->generators->get_run_plan($generator);
		$allow_updates = !WP_CLI\Utils\get_flag_value($assoc_args, 'no-update', false);
		$row_keys = $allow_updates ? array_merge($plan['create'], $plan['update']) : $plan['create'];

		WP_CLI::log(sprintf(
			'Generator "%1$s": %2$d to create, %3$d changed, %4$d up to date, %5$d in the trash.',
			$generator['name'],
			count($plan['create']),
			count($plan['update']),
			$plan['current'],
			$plan['trashed']
		));

		if (WP_CLI\Utils\get_flag_value($assoc_args, 'dry-run', false) || empty($row_keys)) {
			WP_CLI::success(empty($row_keys) ? 'Nothing to do.' : 'Dry run: nothing was written.');
			return;
		}

		$operation_id = 'cli-' . strtolower(wp_generate_password(12, false));
		$batches = array_chunk($row_keys, self::BATCH_SIZE);
		$counts = array();
		$progress = WP_CLI\Utils\make_progress_bar('Running generator', count($row_keys));

		foreach ($batches as $index => $batch) {
			// Re-read the generator so each batch sees items linked by the previous one.
			$results = $this->generators->run_rows($this->generators->get_generator($generator['id']), $batch, $operation_id, $index, $index === count($batches) - 1, $allow_updates);
			if (is_wp_error($results)) {
				$progress->finish();
				WP_CLI::error($results->get_error_message());
			}
			foreach ($results as $result) {
				$counts[$result['status']] = isset($counts[$result['status']]) ? $counts[$result['status']] + 1 : 1;
				if ('error' === $result['status']) {
					WP_CLI::warning(sprintf('%1$s: %2$s', $result['value'], $result['message']));
				}
				$progress->tick();
			}
		}
		$progress->finish();

		WP_CLI::success($this->format_counts($counts));
	}

	/**
	 * Push template changes to the items generated from it.
	 *
	 * ## OPTIONS
	 *
	 * <template_id>
	 * : Template post ID.
	 *
	 * --fields=<fields>
	 * : Comma-separated parts to sync: content, excerpt, elementor, beaver, bricks, seo, custom_fields, featured_image, page_template, terms, title, slug.
	 *
	 * [--overwrite-edited]
	 * : Also overwrite items edited by hand since they were generated or last synced.
	 *
	 * [--yes]
	 * : Confirm syncing titles or slugs, which can change names and URLs.
	 *
	 * [--dry-run]
	 * : Show which items would change without writing anything.
	 *
	 * ## EXAMPLES
	 *
	 *     wp bulk-page-duplicator sync 17 --fields=content,excerpt --dry-run --user=admin
	 *     wp bulk-page-duplicator sync 17 --fields=content,seo --user=admin
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function sync($args, $assoc_args) {
		$this->require_admin();

		$template = $this->sync->validate_template(absint($args[0]));
		if (is_wp_error($template)) {
			WP_CLI::error($template->get_error_message());
		}

		$fields = $this->sync->sanitize_fields(explode(',', (string) WP_CLI\Utils\get_flag_value($assoc_args, 'fields', '')));
		if (empty($fields)) {
			WP_CLI::error('Choose what to sync with --fields, for example --fields=content,excerpt.');
		}
		if (array_intersect($fields, $this->sync->get_risky_fields()) && !WP_CLI\Utils\get_flag_value($assoc_args, 'yes', false)) {
			WP_CLI::error('Syncing titles or slugs changes names and URLs. Add --yes to confirm.');
		}

		$dry_run = WP_CLI\Utils\get_flag_value($assoc_args, 'dry-run', false);
		$overwrite = WP_CLI\Utils\get_flag_value($assoc_args, 'overwrite-edited', false);
		$counts = array();
		$rows = array();
		$paged = 1;

		do {
			$page = $this->sync->get_generated_item_ids($template->ID, $paged, self::BATCH_SIZE);
			foreach ($page['ids'] as $post_id) {
				if ($dry_run) {
					$preview = $this->sync->preview_item($template->ID, $post_id, $fields);
					$status = '' !== $preview['error'] ? 'error' : ($preview['will_change'] ? ('clean' === $preview['state'] || $overwrite ? 'will update' : 'skip (edited)') : 'up to date');
					$rows[] = array(
						'ID'      => $post_id,
						'title'   => $preview['title'],
						'state'   => $preview['state'],
						'changes' => implode(', ', (array) $preview['changes']),
						'result'  => $status,
					);
				} else {
					$result = $this->sync->sync_item($template->ID, $post_id, $fields, $overwrite);
					$status = $result['status'];
					if ('error' === $status) {
						WP_CLI::warning(sprintf('#%1$d: %2$s', $post_id, $result['message']));
					}
				}
				$counts[$status] = isset($counts[$status]) ? $counts[$status] + 1 : 1;
			}
			$paged++;
		} while (!empty($page['has_more']));

		if ($dry_run) {
			if (!empty($rows)) {
				WP_CLI\Utils\format_items('table', $rows, array('ID', 'title', 'state', 'changes', 'result'));
			}
			WP_CLI::success('Dry run: ' . $this->format_counts($counts));
			return;
		}

		$this->core->flush_builder_caches();
		$this->sync->record_history($template, 'cli-' . strtolower(wp_generate_password(12, false)), $fields, array(
			'updated'   => isset($counts['updated']) ? $counts['updated'] : 0,
			'unchanged' => isset($counts['unchanged']) ? $counts['unchanged'] : 0,
			'skipped'   => isset($counts['skipped']) ? $counts['skipped'] : 0,
			'error'     => isset($counts['error']) ? $counts['error'] : 0,
		));

		WP_CLI::success($this->format_counts($counts));
	}

	/**
	 * Print the generators table.
	 *
	 * @param string $format Output format.
	 */
	private function list_generators($format) {
		$items = array();
		foreach ($this->generators->get_generators() as $generator) {
			$last_run = (int) get_post_meta($generator['id'], '_bpd_generator_last_run', true);
			$items[] = array(
				'ID'        => $generator['id'],
				'name'      => $generator['name'],
				'template'  => $generator['template_id'],
				'post_type' => $generator['post_type'],
				'rows'      => count($generator['rows']),
				'generated' => $this->generators->count_live_items($generator),
				'last_run'  => $last_run ? gmdate('Y-m-d H:i', $last_run) . ' UTC' : '',
			);
		}

		if ('ids' === $format) {
			WP_CLI::log(implode(' ', wp_list_pluck($items, 'ID')));
			return;
		}

		WP_CLI\Utils\format_items($format, $items, array('ID', 'name', 'template', 'post_type', 'rows', 'generated', 'last_run'));
	}

	/**
	 * Stop unless the command runs as a user who may use the plugin screens.
	 */
	private function require_admin() {
		if (!current_user_can('manage_options')) {
			WP_CLI::error('Run this command as an administrator, for example with --user=admin.');
		}
	}

	/**
	 * Summarise result counts, e.g. "3 success, 1 updated".
	 *
	 * @param array $counts Counts keyed by status.
	 * @return string
	 */
	private function format_counts($counts) {
		if (empty($counts)) {
			return 'No items.';
		}

		$parts = array();
		foreach ($counts as $status => $count) {
			$parts[] = $count . ' ' . $status;
		}
		return implode(', ', $parts) . '.';
	}
}
