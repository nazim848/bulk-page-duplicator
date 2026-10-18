<?php

/**
 * Template sync: push template changes to the items generated from it.
 *
 * @package BulkPageDuplicator
 */

if (!defined('ABSPATH')) exit;

class Bulk_Page_Duplicator_Sync {
	/**
	 * Option that stores recent syncs.
	 */
	const HISTORY_OPTION = 'bpd_sync_history';

	/**
	 * Revision meta key holding the pre-sync meta, thumbnail and terms.
	 */
	const SNAPSHOT_META_KEY = '_bpd_sync_snapshot';

	/**
	 * Seconds after generation during which edits still count as part of generation.
	 * Only used for items generated before output hashes existed.
	 */
	const LEGACY_EDIT_GRACE = 60;

	/**
	 * Core instance used for rendering.
	 *
	 * @var Bulk_Page_Duplicator_Core
	 */
	private $core;

	/**
	 * Constructor.
	 *
	 * @param Bulk_Page_Duplicator_Core|null $core Core instance.
	 */
	public function __construct($core = null) {
		$this->core = $core ? $core : new Bulk_Page_Duplicator_Core();
	}

	/**
	 * Register hooks needed outside the sync screen.
	 */
	public function init() {
		add_action('wp_restore_post_revision', array($this, 'restore_snapshot'), 20, 2);
	}

	/**
	 * Core instance.
	 *
	 * @return Bulk_Page_Duplicator_Core
	 */
	public function get_core() {
		return $this->core;
	}

	/**
	 * Fields a sync can push, with labels.
	 *
	 * @return array Field => label.
	 */
	public function get_field_labels() {
		return array(
			'content'        => __('Content', 'bulk-page-duplicator'),
			'excerpt'        => __('Excerpt', 'bulk-page-duplicator'),
			'elementor'      => __('Elementor data', 'bulk-page-duplicator'),
			'beaver'         => __('Beaver Builder data', 'bulk-page-duplicator'),
			'bricks'         => __('Bricks Builder data', 'bulk-page-duplicator'),
			'seo'            => __('SEO meta', 'bulk-page-duplicator'),
			'custom_fields'  => __('Custom fields', 'bulk-page-duplicator'),
			'featured_image' => __('Featured image', 'bulk-page-duplicator'),
			'page_template'  => __('Page template', 'bulk-page-duplicator'),
			'terms'          => __('Terms', 'bulk-page-duplicator'),
			'title'          => __('Title', 'bulk-page-duplicator'),
			'slug'           => __('Slug', 'bulk-page-duplicator'),
		);
	}

	/**
	 * Fields that change URLs or names and need an extra confirmation.
	 *
	 * @return string[]
	 */
	public function get_risky_fields() {
		return array('title', 'slug');
	}

	/**
	 * Fields offered on this site (only detected builders and SEO plugins).
	 *
	 * @return string[]
	 */
	public function get_available_fields() {
		$fields = array('content', 'excerpt');
		foreach ($this->core->detect_page_builders() as $builder) {
			$fields[] = $builder['id'];
		}
		if (!empty($this->core->detect_seo_plugins())) {
			$fields[] = 'seo';
		}
		return array_merge($fields, array('custom_fields', 'featured_image', 'page_template', 'terms', 'title', 'slug'));
	}

	/**
	 * Filter requested fields down to known sync fields.
	 *
	 * @param array $fields Requested fields.
	 * @return string[]
	 */
	public function sanitize_fields($fields) {
		$fields = array_map('sanitize_key', (array) $fields);
		return array_values(array_intersect(array_keys($this->get_field_labels()), $fields));
	}

	/**
	 * Post statuses that are considered for sync.
	 *
	 * @return string[]
	 */
	private function get_syncable_statuses() {
		return array('publish', 'future', 'draft', 'pending', 'private');
	}

	/**
	 * Query arguments for the items generated from a template.
	 *
	 * @param WP_Post $template Template post.
	 * @return array
	 */
	private function get_items_query_args($template) {
		return array(
			'post_type'              => $template->post_type,
			'post_status'            => $this->get_syncable_statuses(),
			'meta_key'               => '_bpd_template_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_query_meta_key -- Required to find generated items.
			'meta_value'             => (string) absint($template->ID), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_query_meta_value -- Required to find generated items.
			'post__not_in'           => array($template->ID), // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_post__not_in -- Single ID.
			'orderby'                => 'ID',
			'order'                  => 'ASC',
			'fields'                 => 'ids',
			'ignore_sticky_posts'    => true,
			'update_post_term_cache' => false,
			'suppress_filters'       => true,
		);
	}

	/**
	 * Count the items generated from a template.
	 *
	 * @param int $template_id Template post ID.
	 * @return int
	 */
	public function count_generated_items($template_id) {
		$template = get_post($template_id);
		if (!$template) {
			return 0;
		}

		$query = new WP_Query(array_merge($this->get_items_query_args($template), array(
			'posts_per_page'         => 1,
			'update_post_meta_cache' => false,
		)));

		return (int) $query->found_posts;
	}

	/**
	 * Get one page of item IDs generated from a template.
	 *
	 * @param int $template_id Template post ID.
	 * @param int $paged       Page number, from 1.
	 * @param int $per_page    Items per page (max 100).
	 * @return array 'ids', 'total' and 'has_more'.
	 */
	public function get_generated_item_ids($template_id, $paged = 1, $per_page = 20) {
		$template = get_post($template_id);
		if (!$template) {
			return array('ids' => array(), 'total' => 0, 'has_more' => false);
		}

		$query = new WP_Query(array_merge($this->get_items_query_args($template), array(
			'posts_per_page' => min(100, max(1, absint($per_page))),
			'paged'          => max(1, absint($paged)),
		)));

		return array(
			'ids'      => array_map('absint', $query->posts),
			'total'    => (int) $query->found_posts,
			'has_more' => max(1, absint($paged)) < (int) $query->max_num_pages,
		);
	}

	/**
	 * List templates that have generated items, most items first.
	 *
	 * @param int $limit Maximum number of templates.
	 * @return array List of arrays with 'template_id' and 'count'.
	 */
	public function get_templates_with_items($limit = 100) {
		global $wpdb;

		$statuses = $this->get_syncable_statuses();
		$placeholders = implode(', ', array_fill(0, count($statuses), '%s'));

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Aggregate over generated items; status placeholders are built above.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT pm.meta_value AS template_id, COUNT(*) AS item_count
				FROM {$wpdb->postmeta} pm
				INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				WHERE pm.meta_key = %s AND p.post_status IN ($placeholders)
				GROUP BY pm.meta_value
				ORDER BY item_count DESC
				LIMIT %d",
				array_merge(array('_bpd_template_id'), $statuses, array(absint($limit)))
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$templates = array();
		foreach ((array) $rows as $row) {
			$template_id = absint($row['template_id']);
			if ($template_id) {
				$templates[] = array(
					'template_id' => $template_id,
					'count'       => absint($row['item_count']),
				);
			}
		}
		return $templates;
	}

	/**
	 * Whether a template can be synced by the current user.
	 *
	 * @param int $template_id Template post ID.
	 * @return WP_Post|WP_Error
	 */
	public function validate_template($template_id) {
		$template = get_post($template_id);
		if (!$template || 'trash' === $template->post_status || 'auto-draft' === $template->post_status || 'revision' === $template->post_type) {
			return new WP_Error('bpd_sync_template', __('Template not found', 'bulk-page-duplicator'));
		}
		if (!current_user_can('edit_post', $template->ID)) {
			return new WP_Error('bpd_sync_template_permission', __('You do not have permission to use this template.', 'bulk-page-duplicator'));
		}
		return $template;
	}

	/**
	 * Whether revisions will be saved for items of this post type.
	 *
	 * @param WP_Post $post Any post of the type.
	 * @return bool
	 */
	public function revisions_supported($post) {
		return post_type_supports($post->post_type, 'revisions') && wp_revisions_enabled($post);
	}

	/**
	 * Whether an item was edited since it was generated or last synced.
	 *
	 * @param WP_Post $post       Item.
	 * @param array   $generation Item's _bpd_generation meta.
	 * @return string 'clean', 'edited', or 'unknown' (no stored hash and modified after generation).
	 */
	public function get_edit_state($post, $generation) {
		$stored_hash = get_post_meta($post->ID, '_bpd_output_hash', true);
		if ('' !== $stored_hash) {
			return hash_equals((string) $stored_hash, $this->core->compute_output_hash($post->ID)) ? 'clean' : 'edited';
		}

		// Items generated before output hashes existed: compare with the last generation or generator update.
		$created = isset($generation['created']) ? absint($generation['created']) : 0;
		if (!empty($generation['updated'])) {
			$created = max($created, absint($generation['updated']));
		}
		$modified = (int) strtotime($post->post_modified_gmt . ' UTC');
		if ($created && $modified && $modified <= $created + self::LEGACY_EDIT_GRACE) {
			return 'clean';
		}
		return 'unknown';
	}

	/**
	 * Whether a set of fields rewrites everything the output hash covers.
	 *
	 * @param string[] $fields Sync fields.
	 * @return bool
	 */
	private function covers_hashed_output($fields) {
		if (!in_array('content', $fields, true)) {
			return false;
		}
		foreach ($this->core->detect_page_builders() as $builder) {
			if (!in_array($builder['id'], $fields, true)) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Load an item for sync and check it belongs to the template.
	 *
	 * @param int $template_id Template post ID.
	 * @param int $post_id     Item ID.
	 * @return array|WP_Error 'post' and 'generation'.
	 */
	private function load_item($template_id, $post_id) {
		$post = get_post($post_id);
		if (!$post || (int) $post->ID === (int) $template_id || !in_array($post->post_status, $this->get_syncable_statuses(), true)) {
			return new WP_Error('bpd_sync_item', __('Item not found.', 'bulk-page-duplicator'));
		}
		if ((int) get_post_meta($post->ID, '_bpd_template_id', true) !== (int) $template_id) {
			return new WP_Error('bpd_sync_item_template', __('This item was not generated from the selected template.', 'bulk-page-duplicator'));
		}
		if (!current_user_can('edit_post', $post->ID)) {
			return new WP_Error('bpd_sync_item_permission', __('You do not have permission to edit this item.', 'bulk-page-duplicator'));
		}

		$generation = get_post_meta($post->ID, '_bpd_generation', true);
		if (!is_array($generation) || empty($generation['placeholders']) || !isset($generation['values'])) {
			return new WP_Error('bpd_sync_item_generation', __('This item has no stored placeholder values.', 'bulk-page-duplicator'));
		}

		return array(
			'post'       => $post,
			'generation' => $generation,
		);
	}

	/**
	 * Base result row for an item.
	 *
	 * @param int          $post_id Item ID.
	 * @param WP_Post|null $post    Item, if loaded.
	 * @return array
	 */
	private function result_row($post_id, $post = null) {
		$title = $post ? get_the_title($post) : '';

		// Items managed by a saved generator take their values from its rows.
		$generator_title = '';
		$generator_id = $post ? absint(get_post_meta($post->ID, '_bpd_generator_id', true)) : 0;
		$generator = $generator_id ? get_post($generator_id) : null;
		if ($generator && 'bpd_generator' === $generator->post_type) {
			$generator_title = '' !== $generator->post_title ? $generator->post_title : __('(no title)', 'bulk-page-duplicator');
		}

		return array(
			'id'        => absint($post_id),
			'title'     => '' !== $title ? $title : __('(no title)', 'bulk-page-duplicator'),
			'edit_url'  => $post ? esc_url_raw((string) get_edit_post_link($post->ID, '')) : '',
			'generator' => $generator_title,
		);
	}

	/**
	 * Translate field names to labels.
	 *
	 * @param string[] $fields Field names.
	 * @return string[]
	 */
	private function field_labels_for($fields) {
		$labels = $this->get_field_labels();
		$out = array();
		foreach ($fields as $field) {
			$out[] = isset($labels[$field]) ? $labels[$field] : $field;
		}
		return $out;
	}

	/**
	 * Render arguments for an item, from its generation record.
	 *
	 * @param array $generation Item's _bpd_generation meta.
	 * @param array $args       Extra arguments.
	 * @return array
	 */
	private function get_render_args($generation, $args = array()) {
		// Terms chosen in the form at generation time are kept when terms are synced.
		$args['extra_terms'] = isset($generation['terms']) && is_array($generation['terms']) ? $generation['terms'] : array();
		return $args;
	}

	/**
	 * Preview what a sync would change on one item.
	 *
	 * @param int      $template_id Template post ID.
	 * @param int      $post_id     Item ID.
	 * @param string[] $fields      Sync fields.
	 * @return array Result row with 'state', 'changes', 'will_change' and 'error'.
	 */
	public function preview_item($template_id, $post_id, $fields) {
		$item = $this->load_item($template_id, $post_id);
		if (is_wp_error($item)) {
			return array_merge($this->result_row($post_id, get_post($post_id)), array(
				'state'       => 'error',
				'changes'     => array(),
				'will_change' => false,
				'error'       => $item->get_error_message(),
			));
		}

		$generation = $item['generation'];
		$plan = $this->core->build_render_plan($template_id, $post_id, $generation['placeholders'], $generation['values'], $fields, $this->get_render_args($generation));
		if (is_wp_error($plan)) {
			return array_merge($this->result_row($post_id, $item['post']), array(
				'state'       => 'error',
				'changes'     => array(),
				'will_change' => false,
				'error'       => $plan->get_error_message(),
			));
		}

		$changes = $this->core->get_plan_changes($post_id, $plan);

		return array_merge($this->result_row($post_id, $item['post']), array(
			'state'       => $this->get_edit_state($item['post'], $generation),
			'changes'     => $this->field_labels_for($changes),
			'will_change' => !empty($changes),
			'error'       => '',
		));
	}

	/**
	 * Re-render one item from the current template.
	 *
	 * @param int      $template_id  Template post ID.
	 * @param int      $post_id      Item ID.
	 * @param string[] $fields       Sync fields.
	 * @param bool     $allow_edited Whether to overwrite an item that was edited by hand.
	 * @return array Result row with 'status' (updated, unchanged, skipped, error) and 'message'.
	 */
	public function sync_item($template_id, $post_id, $fields, $allow_edited) {
		$item = $this->load_item($template_id, $post_id);
		if (is_wp_error($item)) {
			return array_merge($this->result_row($post_id, get_post($post_id)), array(
				'status'  => 'error',
				'message' => $item->get_error_message(),
			));
		}

		$post = $item['post'];
		$generation = $item['generation'];
		$row = $this->result_row($post_id, $post);

		$state = $this->get_edit_state($post, $generation);
		if ('clean' !== $state && !$allow_edited) {
			return array_merge($row, array(
				'status'  => 'skipped',
				'message' => __('Skipped: edited by hand since it was generated.', 'bulk-page-duplicator'),
			));
		}

		// Keep the hand-edited flag unless this sync rewrites everything the hash covers.
		$update_hash = 'clean' === $state || $this->covers_hashed_output($fields);

		$plan = $this->core->build_render_plan($template_id, $post_id, $generation['placeholders'], $generation['values'], $fields, $this->get_render_args($generation, array(
			'update_hash' => $update_hash,
		)));
		if (is_wp_error($plan)) {
			return array_merge($row, array(
				'status'  => 'error',
				'message' => $plan->get_error_message(),
			));
		}

		$changes = $this->core->get_plan_changes($post_id, $plan);
		if (empty($changes)) {
			return array_merge($row, array(
				'status'  => 'unchanged',
				'message' => __('Already up to date.', 'bulk-page-duplicator'),
			));
		}

		$revision_id = $this->save_revision($post, $plan);

		$result = $this->core->apply_render_plan($post_id, $plan);
		if (is_wp_error($result)) {
			return array_merge($row, array(
				'status'  => 'error',
				'message' => $result->get_error_message(),
			));
		}

		/**
		 * Fires after an item has been synced from its template.
		 *
		 * @param int      $post_id     Item ID.
		 * @param int      $template_id Template ID.
		 * @param string[] $changes     Fields that changed.
		 */
		do_action('bpd_after_sync_item', $post_id, $template_id, $changes);

		/* translators: %s: comma-separated list of updated fields. */
		$message = sprintf(__('Updated: %s.', 'bulk-page-duplicator'), implode(', ', $this->field_labels_for($changes)));
		if (!$revision_id) {
			$message .= ' ' . __('No revision was saved for this item.', 'bulk-page-duplicator');
		}
		if (!empty($result['warnings'])) {
			/* translators: %s: warning details. */
			$message .= ' ' . sprintf(__('Warning: %s', 'bulk-page-duplicator'), implode(' ', $result['warnings']));
		}

		return array_merge($row, array(
			'status'   => 'updated',
			'message'  => $message,
			'revision' => (int) $revision_id,
		));
	}

	/**
	 * Save a revision of an item before a sync overwrites it.
	 *
	 * WordPress revisions only hold post fields, so the meta, featured image and
	 * terms that the sync will overwrite are stored on the revision as well and
	 * put back by restore_snapshot() when the revision is restored.
	 *
	 * @param WP_Post $post Item.
	 * @param array   $plan Render plan that is about to be applied.
	 * @return int Revision ID, or 0 when revisions are unavailable.
	 */
	private function save_revision($post, $plan) {
		if (!$this->revisions_supported($post)) {
			return 0;
		}

		$revision_id = wp_save_post_revision($post->ID);
		if (!$revision_id || is_wp_error($revision_id)) {
			// Nothing changed since the latest revision: it already matches the current post fields.
			$revision_id = 0;
			foreach (wp_get_post_revisions($post->ID, array('posts_per_page' => 5)) as $revision) {
				if (!wp_is_post_autosave($revision)) {
					$revision_id = (int) $revision->ID;
					break;
				}
			}
		}
		if (!$revision_id) {
			return 0;
		}

		$snapshot = array(
			'meta'  => array(),
			'terms' => array(),
		);
		$meta_keys = array_merge(array_keys($plan['meta']), $this->core->get_output_hash_meta_keys(), array('_bpd_output_hash'));
		if (null !== $plan['thumbnail']) {
			$meta_keys[] = '_thumbnail_id';
		}
		foreach (array_unique($meta_keys) as $key) {
			$snapshot['meta'][$key] = get_post_meta($post->ID, $key, false);
		}
		if (null !== $plan['terms']) {
			foreach (array_keys($plan['terms']) as $taxonomy) {
				$term_ids = wp_get_object_terms($post->ID, $taxonomy, array('fields' => 'ids'));
				$snapshot['terms'][$taxonomy] = is_wp_error($term_ids) ? array() : array_map('absint', $term_ids);
			}
		}

		// Write to the revision itself; update_post_meta() would redirect to the parent.
		$this->core->write_meta_values($revision_id, self::SNAPSHOT_META_KEY, array($snapshot));

		return (int) $revision_id;
	}

	/**
	 * Put back the meta, featured image and terms saved with a sync revision.
	 *
	 * @param int $post_id     Restored post ID.
	 * @param int $revision_id Revision ID.
	 */
	public function restore_snapshot($post_id, $revision_id) {
		$snapshot = get_metadata('post', $revision_id, self::SNAPSHOT_META_KEY, true);
		if (!is_array($snapshot) || !current_user_can('edit_post', $post_id)) {
			return;
		}

		if (!empty($snapshot['meta']) && is_array($snapshot['meta'])) {
			foreach ($snapshot['meta'] as $key => $values) {
				$this->core->write_meta_values($post_id, $key, (array) $values);
			}
		}

		if (!empty($snapshot['terms']) && is_array($snapshot['terms'])) {
			foreach ($snapshot['terms'] as $taxonomy => $term_ids) {
				if (taxonomy_exists($taxonomy)) {
					wp_set_object_terms($post_id, array_map('absint', (array) $term_ids), $taxonomy);
				}
			}
		}

		// Builders regenerate their assets from the restored data.
		delete_post_meta($post_id, '_elementor_css');
		delete_post_meta($post_id, '_bricks_page_assets_css');
		delete_post_meta($post_id, '_bricks_page_assets_js');
		if (class_exists('FLBuilderModel')) {
			FLBuilderModel::delete_asset_cache($post_id);
		}
		if (class_exists('\\Elementor\\Plugin')) {
			\Elementor\Plugin::$instance->files_manager->clear_cache();
		}
	}

	/**
	 * Record a sync batch in the sync history.
	 *
	 * @param WP_Post  $template     Template post.
	 * @param string   $operation_id Client-generated operation ID.
	 * @param string[] $fields       Sync fields.
	 * @param array    $counts       Counts for this batch, keyed by result status.
	 */
	public function record_history($template, $operation_id, $fields, $counts) {
		$history = get_option(self::HISTORY_OPTION, array());
		if (!is_array($history)) {
			$history = array();
		}

		$user_id = get_current_user_id();
		$operation_id = '' !== $operation_id ? $operation_id : wp_generate_uuid4();
		$key = 'sync_' . $user_id . '_' . $operation_id;
		$statuses = array('updated', 'unchanged', 'skipped', 'error');

		if (!isset($history[$key])) {
			$history[$key] = array(
				'timestamp'      => time(),
				'user_id'        => $user_id,
				'template_id'    => (int) $template->ID,
				'template_title' => $template->post_title,
				'fields'         => array_values($fields),
				'counts'         => array_fill_keys($statuses, 0),
			);
		}

		foreach ($statuses as $status) {
			$history[$key]['counts'][$status] += isset($counts[$status]) ? absint($counts[$status]) : 0;
		}
		$history[$key]['updated_at'] = time();

		// Keep only the last 50 syncs.
		if (count($history) > 50) {
			$history = array_slice($history, -50, 50, true);
		}

		update_option(self::HISTORY_OPTION, $history, false);
	}

	/**
	 * Recent syncs of a template.
	 *
	 * @param int $template_id Template post ID.
	 * @param int $limit       Maximum entries.
	 * @return array
	 */
	public function get_history($template_id, $limit = 10) {
		$history = get_option(self::HISTORY_OPTION, array());
		if (!is_array($history)) {
			return array();
		}

		$entries = array_filter($history, function ($entry) use ($template_id) {
			return isset($entry['template_id']) && (int) $entry['template_id'] === (int) $template_id;
		});
		uasort($entries, function ($a, $b) {
			return $b['timestamp'] - $a['timestamp'];
		});

		$formatted = array();
		foreach (array_slice($entries, 0, $limit, true) as $entry) {
			$user = get_userdata($entry['user_id']);
			$formatted[] = array(
				'date'   => get_date_from_gmt(gmdate('Y-m-d H:i:s', $entry['timestamp']), get_option('date_format') . ' ' . get_option('time_format')),
				'user'   => $user ? $user->display_name : __('Unknown', 'bulk-page-duplicator'),
				'fields' => implode(', ', $this->field_labels_for((array) $entry['fields'])),
				'counts' => $entry['counts'],
			);
		}
		return $formatted;
	}
}
