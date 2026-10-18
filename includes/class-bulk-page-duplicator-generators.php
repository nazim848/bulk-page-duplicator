<?php

/**
 * Saved generators: persistent template + dataset definitions that can be
 * re-run to create missing items and re-render changed ones.
 *
 * Generators are stored as a private custom post type so each one has its own
 * ID, author and modified date, its dataset lives in non-autoloaded post meta
 * (large datasets never bloat the options table), and WordPress cleans up the
 * meta when a generator is deleted.
 *
 * @package BulkPageDuplicator
 */

if (!defined('ABSPATH')) exit;

class Bulk_Page_Duplicator_Generators {
	/**
	 * Generator post type.
	 */
	const POST_TYPE = 'bpd_generator';

	/**
	 * Generator meta: settings array.
	 */
	const META_CONFIG = '_bpd_generator_config';

	/**
	 * Generator meta: dataset rows.
	 */
	const META_ROWS = '_bpd_generator_rows';

	/**
	 * Generator meta: timestamp of the last run.
	 */
	const META_LAST_RUN = '_bpd_generator_last_run';

	/**
	 * Generated item meta: owning generator ID.
	 */
	const META_GENERATOR_ID = '_bpd_generator_id';

	/**
	 * Generated item meta: dataset row key.
	 */
	const META_ROW_KEY = '_bpd_row_key';

	/**
	 * Generated item meta: hash of the generator settings it was rendered with.
	 */
	const META_RENDER_HASH = '_bpd_generator_hash';

	/**
	 * Statuses in which a generated item is considered to exist.
	 *
	 * @var string[]
	 */
	private static $item_statuses = array('publish', 'draft', 'pending', 'private', 'future', 'trash');

	/**
	 * Core duplication engine.
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
	 * Hook registration.
	 */
	public function init() {
		add_action('init', array(__CLASS__, 'register_post_type'));
	}

	/**
	 * Register the private generator post type.
	 */
	public static function register_post_type() {
		register_post_type(self::POST_TYPE, array(
			'labels'              => array(
				'name'          => __('Generators', 'bulk-page-duplicator'),
				'singular_name' => __('Generator', 'bulk-page-duplicator'),
			),
			'public'              => false,
			'publicly_queryable'  => false,
			'exclude_from_search' => true,
			'show_ui'             => false,
			'show_in_menu'        => false,
			'show_in_nav_menus'   => false,
			'show_in_admin_bar'   => false,
			'show_in_rest'        => false,
			'has_archive'         => false,
			'rewrite'             => false,
			'query_var'           => false,
			'can_export'          => true,
			'delete_with_user'    => false,
			'supports'            => array('title', 'author'),
			// Nobody holds these primitive capabilities, so core screens and
			// endpoints cannot edit generators; this plugin's screens are gated
			// by manage_options instead.
			'capability_type'     => 'bpd_generator',
			'map_meta_cap'        => true,
		));
	}

	/**
	 * Default generator option values.
	 *
	 * @return array
	 */
	public static function default_options() {
		return array(
			'status'          => 'draft',
			'parent_page'     => '0',
			'replace_options' => array('title', 'slug', 'content', 'elementor', 'beaver', 'bricks', 'seo', 'featured_image', 'template_terms'),
			'taxonomy_terms'  => array(),
		);
	}

	/**
	 * Load a generator.
	 *
	 * @param int $generator_id Generator ID.
	 * @return array|null
	 */
	public function get_generator($generator_id) {
		$post = get_post(absint($generator_id));
		if (!$post || self::POST_TYPE !== $post->post_type || 'trash' === $post->post_status) {
			return null;
		}

		$config = get_post_meta($post->ID, self::META_CONFIG, true);
		$config = is_array($config) ? $config : array();
		$rows = get_post_meta($post->ID, self::META_ROWS, true);

		return array(
			'id'           => $post->ID,
			'name'         => $post->post_title,
			'post_type'    => isset($config['post_type']) ? $config['post_type'] : 'page',
			'template_id'  => isset($config['template_id']) ? absint($config['template_id']) : 0,
			'placeholders' => isset($config['placeholders']) ? array_values((array) $config['placeholders']) : array(),
			'options'      => wp_parse_args(isset($config['options']) ? (array) $config['options'] : array(), self::default_options()),
			'rows'         => is_array($rows) ? array_values($rows) : array(),
			'last_run'     => absint(get_post_meta($post->ID, self::META_LAST_RUN, true)),
			'modified'     => $post->post_modified_gmt,
		);
	}

	/**
	 * List all generators, newest first.
	 *
	 * @return array[]
	 */
	public function get_generators() {
		$ids = get_posts(array(
			'post_type'      => self::POST_TYPE,
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'orderby'        => 'title',
			'order'          => 'ASC',
			'fields'         => 'ids',
			'no_found_rows'  => true,
		));

		$generators = array();
		foreach ($ids as $id) {
			$generator = $this->get_generator($id);
			if ($generator) {
				$generators[] = $generator;
			}
		}

		return $generators;
	}

	/**
	 * Validate and sanitize generator data.
	 *
	 * @param array $data Raw (unslashed) data.
	 * @return array|WP_Error
	 */
	public function sanitize_generator_data($data) {
		$name = isset($data['name']) ? sanitize_text_field($data['name']) : '';
		$post_type = isset($data['post_type']) ? sanitize_key($data['post_type']) : 'page';
		$template_id = isset($data['template_id']) ? absint($data['template_id']) : 0;

		$placeholders = isset($data['placeholders']) ? (array) $data['placeholders'] : array();
		$placeholders = array_values(array_filter(array_map('sanitize_text_field', $placeholders), 'strlen'));

		$post_type_obj = get_post_type_object($post_type);
		if (!$post_type_obj || !$post_type_obj->public || 'attachment' === $post_type) {
			return new WP_Error('bpd_invalid_post_type', __('Invalid post type', 'bulk-page-duplicator'));
		}

		$template = get_post($template_id);
		if (!$template || $template->post_type !== $post_type) {
			return new WP_Error('bpd_invalid_template', __('Please select a template of the chosen post type.', 'bulk-page-duplicator'));
		}

		if (!current_user_can('edit_post', $template_id)) {
			return new WP_Error('bpd_template_permission', __('You do not have permission to use this template.', 'bulk-page-duplicator'));
		}

		if (empty($placeholders)) {
			return new WP_Error('bpd_no_placeholders', __('Please enter at least one placeholder.', 'bulk-page-duplicator'));
		}

		if ('' === $name) {
			/* translators: %s: template title. */
			$name = sprintf(__('Generator for "%s"', 'bulk-page-duplicator'), $template->post_title);
		}

		$raw_options = isset($data['options']) && is_array($data['options']) ? $data['options'] : array();
		$options = array(
			'status'          => isset($raw_options['status']) && 'publish' === sanitize_key($raw_options['status']) ? 'publish' : 'draft',
			'parent_page'     => '0',
			'replace_options' => array(),
			'taxonomy_terms'  => array(),
		);

		if (isset($raw_options['parent_page'])) {
			$parent_page = sanitize_text_field($raw_options['parent_page']);
			$options['parent_page'] = 'template' === $parent_page ? 'template' : (string) absint($parent_page);
		}

		if (isset($raw_options['replace_options'])) {
			$options['replace_options'] = array_values(array_unique(array_filter(array_map('sanitize_key', (array) $raw_options['replace_options']))));
		}

		if (isset($raw_options['taxonomy_terms']) && is_array($raw_options['taxonomy_terms'])) {
			foreach ($raw_options['taxonomy_terms'] as $taxonomy => $term_ids) {
				$taxonomy = sanitize_key($taxonomy);
				$term_ids = array_values(array_unique(array_filter(array_map('absint', (array) $term_ids))));
				if ($taxonomy && !empty($term_ids)) {
					$options['taxonomy_terms'][$taxonomy] = $term_ids;
				}
			}
		}

		$rows = $this->sanitize_rows(isset($data['rows']) ? (array) $data['rows'] : array(), count($placeholders));
		if (is_wp_error($rows)) {
			return $rows;
		}

		return array(
			'name'         => $name,
			'post_type'    => $post_type,
			'template_id'  => $template_id,
			'placeholders' => $placeholders,
			'options'      => $options,
			'rows'         => $rows,
		);
	}

	/**
	 * Sanitize dataset rows.
	 *
	 * Each row is either an array with 'key' and 'values', or a raw line of
	 * comma-separated values. Empty rows are dropped and missing keys created.
	 *
	 * @param array $rows              Raw rows.
	 * @param int   $placeholder_count Number of placeholders.
	 * @return array|WP_Error
	 */
	public function sanitize_rows($rows, $placeholder_count) {
		$clean = array();
		$seen_keys = array();
		$line = 0;

		foreach ($rows as $row) {
			$line++;
			$key = '';
			if (is_array($row)) {
				$key = isset($row['key']) ? sanitize_key($row['key']) : '';
				$values = isset($row['values']) ? $row['values'] : array();
			} else {
				$values = (string) $row;
			}

			if (!is_array($values)) {
				$values = self::split_line((string) $values, $placeholder_count);
			}

			$values = array_values(array_map('sanitize_text_field', array_map('strval', $values)));
			if (empty(array_filter($values, 'strlen'))) {
				continue;
			}

			if ($placeholder_count > 1 && count($values) !== $placeholder_count) {
				return new WP_Error('bpd_row_value_count', sprintf(
					/* translators: %1$d: row number, %2$d: number of placeholders. */
					__('Row %1$d must have %2$d comma-separated values (one for each placeholder).', 'bulk-page-duplicator'),
					$line,
					$placeholder_count
				));
			}

			if ('' === $key || isset($seen_keys[$key])) {
				$key = self::generate_row_key();
			}
			$seen_keys[$key] = true;

			$clean[] = array(
				'key'    => $key,
				'values' => $values,
			);
		}

		return $clean;
	}

	/**
	 * Split a raw line into values, matching the bulk form's rules.
	 *
	 * @param string $line              Raw line.
	 * @param int    $placeholder_count Number of placeholders.
	 * @return string[]
	 */
	public static function split_line($line, $placeholder_count) {
		if ($placeholder_count > 1) {
			return array_map('trim', explode(',', $line));
		}

		return array(trim($line));
	}

	/**
	 * Create a new stable row key.
	 *
	 * @return string
	 */
	public static function generate_row_key() {
		return 'r' . substr(str_replace('-', '', wp_generate_uuid4()), 0, 15);
	}

	/**
	 * Create or update a generator.
	 *
	 * @param array $data         Sanitized data from sanitize_generator_data().
	 * @param int   $generator_id Existing generator ID, or 0 to create.
	 * @return int|WP_Error Generator ID.
	 */
	public function save_generator($data, $generator_id = 0) {
		$postarr = array(
			'post_title'  => $data['name'],
			'post_type'   => self::POST_TYPE,
			'post_status' => 'publish',
		);

		if ($generator_id) {
			$postarr['ID'] = absint($generator_id);
			$result = wp_update_post(wp_slash($postarr), true);
		} else {
			$postarr['post_author'] = get_current_user_id();
			$result = wp_insert_post(wp_slash($postarr), true);
		}

		if (is_wp_error($result)) {
			return $result;
		}

		update_post_meta($result, self::META_CONFIG, wp_slash(array(
			'post_type'    => $data['post_type'],
			'template_id'  => $data['template_id'],
			'placeholders' => $data['placeholders'],
			'options'      => $data['options'],
		)));
		update_post_meta($result, self::META_ROWS, wp_slash($data['rows']));

		return (int) $result;
	}

	/**
	 * Delete a generator. Its generated items are kept and simply unlinked.
	 *
	 * @param int $generator_id Generator ID.
	 * @return bool
	 */
	public function delete_generator($generator_id) {
		$generator = $this->get_generator($generator_id);
		if (!$generator) {
			return false;
		}

		foreach ($this->get_linked_item_ids($generator, true) as $post_id) {
			delete_post_meta($post_id, self::META_GENERATOR_ID);
			delete_post_meta($post_id, self::META_ROW_KEY);
			delete_post_meta($post_id, self::META_RENDER_HASH);
		}

		return (bool) wp_delete_post($generator['id'], true);
	}

	/**
	 * IDs of every item linked to a generator, in any status.
	 *
	 * @param array $generator     Generator.
	 * @param bool  $any_post_type Include items of other post types (e.g. after the generator's post type changed).
	 * @return int[]
	 */
	public function get_linked_item_ids($generator, $any_post_type = false) {
		return array_map('absint', get_posts(array(
			'post_type'              => $any_post_type ? 'any' : $generator['post_type'],
			'post_status'            => self::$item_statuses,
			'posts_per_page'         => -1,
			'fields'                 => 'ids',
			'no_found_rows'          => true,
			'update_post_term_cache' => false,
			'meta_query'             => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Generated items are looked up by owner.
				array(
					'key'   => self::META_GENERATOR_ID,
					'value' => (string) $generator['id'],
				),
			),
		)));
	}

	/**
	 * Map row keys to their generated items.
	 *
	 * @param array $generator Generator.
	 * @return WP_Post[] Items keyed by row key.
	 */
	public function get_linked_items($generator) {
		$items = array();
		$ids = $this->get_linked_item_ids($generator);
		if (empty($ids)) {
			return $items;
		}

		_prime_post_caches($ids, false, true);

		foreach ($ids as $post_id) {
			$row_key = (string) get_post_meta($post_id, self::META_ROW_KEY, true);
			if ('' === $row_key) {
				continue;
			}
			$post = get_post($post_id);
			// Prefer a live item over a trashed one if a key is ever shared.
			if ($post && (!isset($items[$row_key]) || 'trash' === $items[$row_key]->post_status)) {
				$items[$row_key] = $post;
			}
		}

		return $items;
	}

	/**
	 * Count a generator's live (non-trashed) items.
	 *
	 * @param array $generator Generator.
	 * @return int
	 */
	public function count_live_items($generator) {
		$count = 0;
		foreach ($this->get_linked_items($generator) as $post) {
			if ('trash' !== $post->post_status) {
				$count++;
			}
		}

		return $count;
	}

	/**
	 * Hash of the settings that affect how items are rendered.
	 *
	 * @param array $generator Generator.
	 * @return string
	 */
	public function get_render_hash($generator) {
		$options = $generator['options'];
		$replace_options = (array) $options['replace_options'];
		sort($replace_options);
		$taxonomy_terms = (array) $options['taxonomy_terms'];
		ksort($taxonomy_terms);

		return md5(wp_json_encode(array(
			'template_id'     => (int) $generator['template_id'],
			'placeholders'    => array_values($generator['placeholders']),
			'parent_page'     => (string) $options['parent_page'],
			'replace_options' => $replace_options,
			'taxonomy_terms'  => $taxonomy_terms,
		)));
	}

	/**
	 * Determine the state of one row.
	 *
	 * @param array        $generator Generator.
	 * @param array        $row       Row.
	 * @param WP_Post|null $post      Linked item, if any.
	 * @return string missing|trash|changed|current
	 */
	public function get_row_state($generator, $row, $post) {
		if (!$post) {
			return 'missing';
		}

		if ('trash' === $post->post_status) {
			return 'trash';
		}

		$generation = get_post_meta($post->ID, '_bpd_generation', true);
		$stored_values = is_array($generation) && isset($generation['values']) ? array_map('strval', array_values((array) $generation['values'])) : null;
		$row_values = array_map('strval', array_values($row['values']));

		if ($stored_values !== $row_values) {
			return 'changed';
		}

		if ((string) get_post_meta($post->ID, self::META_RENDER_HASH, true) !== $this->get_render_hash($generator)) {
			return 'changed';
		}

		return 'current';
	}

	/**
	 * Work out which rows a run must create or update.
	 *
	 * @param array $generator Generator.
	 * @return array Keys: create, update (row keys), trashed, current (counts).
	 */
	public function get_run_plan($generator) {
		$items = $this->get_linked_items($generator);
		$plan = array(
			'create'  => array(),
			'update'  => array(),
			'trashed' => 0,
			'current' => 0,
		);

		foreach ($generator['rows'] as $row) {
			$post = isset($items[$row['key']]) ? $items[$row['key']] : null;
			switch ($this->get_row_state($generator, $row, $post)) {
				case 'missing':
					$plan['create'][] = $row['key'];
					break;
				case 'changed':
					$plan['update'][] = $row['key'];
					break;
				case 'trash':
					$plan['trashed']++;
					break;
				default:
					$plan['current']++;
			}
		}

		return $plan;
	}

	/**
	 * Build the duplication context for a generator.
	 *
	 * @param array $generator Generator.
	 * @return array|WP_Error
	 */
	public function get_context($generator) {
		return $this->core->prepare_generation_context(array(
			'template_id'     => $generator['template_id'],
			'post_type'       => $generator['post_type'],
			'status'          => $generator['options']['status'],
			'parent_page'     => $generator['options']['parent_page'],
			'placeholders'    => $generator['placeholders'],
			'replace_options' => $generator['options']['replace_options'],
			'taxonomy_terms'  => $generator['options']['taxonomy_terms'],
		));
	}

	/**
	 * Create or update the items for a batch of rows.
	 *
	 * Rows without an item are created through the core engine (which skips
	 * slugs that already exist). An existing item made earlier from the same
	 * template, but not owned by any generator, is adopted. Rows whose values
	 * or rendering settings changed are re-rendered. Up-to-date rows are left
	 * alone.
	 *
	 * @param array    $generator          Generator.
	 * @param string[] $row_keys           Row keys to process.
	 * @param string   $operation_id       Operation ID.
	 * @param int      $batch_index        Batch index.
	 * @param bool     $operation_complete Whether this is the final batch.
	 * @param bool     $allow_updates      Whether changed rows may be re-rendered.
	 * @return array|WP_Error Results.
	 */
	public function run_rows($generator, $row_keys, $operation_id, $batch_index, $operation_complete, $allow_updates = true) {
		$context = $this->get_context($generator);
		if (is_wp_error($context)) {
			return $context;
		}

		$rows = array();
		foreach ($generator['rows'] as $row) {
			$rows[$row['key']] = $row;
		}

		$items = $this->get_linked_items($generator);
		$hash = $this->get_render_hash($generator);
		$results = array();
		$created_ids = array();

		foreach ($row_keys as $row_key) {
			if (!isset($rows[$row_key])) {
				continue;
			}

			$row = $rows[$row_key];
			$post = isset($items[$row_key]) ? $items[$row_key] : null;
			$state = $this->get_row_state($generator, $row, $post);
			$display_value = implode(', ', $row['values']);

			if ('trash' === $state) {
				$result = array(
					'value'   => $display_value,
					'status'  => 'skipped',
					'message' => __('The item for this row is in the trash. Restore it or remove the row.', 'bulk-page-duplicator'),
				);
			} elseif ('current' === $state) {
				$result = array(
					'value'   => $display_value,
					'status'  => 'unchanged',
					'message' => __('Already up to date.', 'bulk-page-duplicator'),
					'id'      => $post->ID,
				);
			} elseif ('changed' === $state) {
				$result = $allow_updates ? $this->core->update_item($context, $post->ID, $row['values'], $operation_id) : array(
					'value'   => $display_value,
					'status'  => 'skipped',
					'message' => __('Changed row left as is.', 'bulk-page-duplicator'),
					'id'      => $post->ID,
				);
			} else {
				$result = $this->core->create_item($context, $row['values'], $operation_id, array(
					self::META_GENERATOR_ID => $generator['id'],
					self::META_ROW_KEY      => $row_key,
				));

				if ('success' === $result['status']) {
					$created_ids[] = $result['id'];
				} elseif ('skipped' === $result['status'] && !empty($result['existing_id']) && $this->can_adopt($generator, $result['existing_id'])) {
					$result = $this->adopt_item($generator, $context, $row, $result['existing_id'], $operation_id, $allow_updates);
				}
			}

			if (in_array($result['status'], array('success', 'updated', 'linked'), true) && !empty($result['id'])) {
				update_post_meta($result['id'], self::META_RENDER_HASH, $hash);
			}

			$result['row_key'] = $row_key;
			$results[] = $result;
		}

		$this->core->flush_builder_caches();
		$this->core->save_batch_history($generator['template_id'], $generator['post_type'], $created_ids, $batch_index, $operation_id, $operation_complete);
		update_post_meta($generator['id'], self::META_LAST_RUN, time());

		return $results;
	}

	/**
	 * Whether an existing item with a clashing slug may be linked to a row.
	 *
	 * @param array $generator Generator.
	 * @param int   $post_id   Existing item.
	 * @return bool
	 */
	private function can_adopt($generator, $post_id) {
		$post = get_post($post_id);
		if (!$post || 'trash' === $post->post_status || $post->post_type !== $generator['post_type']) {
			return false;
		}

		$owner = absint(get_post_meta($post->ID, self::META_GENERATOR_ID, true));
		if ($owner && $owner === (int) $generator['id']) {
			// Never take an item away from another row of this generator.
			$row_key = (string) get_post_meta($post->ID, self::META_ROW_KEY, true);
			foreach ($generator['rows'] as $row) {
				if ($row['key'] === $row_key) {
					return false;
				}
			}
		} elseif ($owner && $this->get_generator($owner)) {
			return false;
		}

		return absint(get_post_meta($post->ID, '_bpd_template_id', true)) === (int) $generator['template_id']
			&& current_user_can('edit_post', $post->ID);
	}

	/**
	 * Link an existing item to a row and bring it up to date.
	 *
	 * @param array  $generator     Generator.
	 * @param array  $context       Duplication context.
	 * @param array  $row           Row.
	 * @param int    $post_id       Existing item.
	 * @param string $operation_id  Operation ID.
	 * @param bool   $allow_updates Whether the item may be re-rendered.
	 * @return array Result.
	 */
	private function adopt_item($generator, $context, $row, $post_id, $operation_id, $allow_updates) {
		update_post_meta($post_id, self::META_GENERATOR_ID, $generator['id']);
		update_post_meta($post_id, self::META_ROW_KEY, $row['key']);

		$generation = get_post_meta($post_id, '_bpd_generation', true);
		$stored_values = is_array($generation) && isset($generation['values']) ? array_map('strval', array_values((array) $generation['values'])) : null;

		if ($allow_updates && $stored_values !== array_map('strval', $row['values'])) {
			$result = $this->core->update_item($context, $post_id, $row['values'], $operation_id);
			if ('updated' === $result['status']) {
				/* translators: %s: result message. */
				$result['message'] = sprintf(__('Linked existing item. %s', 'bulk-page-duplicator'), $result['message']);
			}
			return $result;
		}

		return array(
			'value'    => implode(', ', $row['values']),
			'status'   => 'linked',
			/* translators: %s: title of the existing item. */
			'message'  => sprintf(__('Linked existing item "%s".', 'bulk-page-duplicator'), get_the_title($post_id)),
			'id'       => $post_id,
			'edit_url' => esc_url_raw(get_edit_post_link($post_id, '')),
		);
	}

	/**
	 * Move the items of removed rows to the trash.
	 *
	 * @param array    $generator Generator (before the rows were removed).
	 * @param string[] $row_keys  Removed row keys.
	 * @return array Keys: trashed, failed (counts).
	 */
	public function trash_row_items($generator, $row_keys) {
		$items = $this->get_linked_items($generator);
		$counts = array('trashed' => 0, 'failed' => 0);

		foreach ($row_keys as $row_key) {
			if (!isset($items[$row_key]) || 'trash' === $items[$row_key]->post_status) {
				continue;
			}

			$post_id = $items[$row_key]->ID;
			if (current_user_can('delete_post', $post_id) && wp_trash_post($post_id)) {
				$counts['trashed']++;
			} else {
				$counts['failed']++;
			}
		}

		return $counts;
	}

	/**
	 * Row keys that are in the old row list but not the new one and have a live item.
	 *
	 * @param array $generator Existing generator.
	 * @param array $new_rows  New rows.
	 * @return string[]
	 */
	public function get_removed_rows_with_items($generator, $new_rows) {
		$kept = array();
		foreach ($new_rows as $row) {
			$kept[$row['key']] = true;
		}

		$items = $this->get_linked_items($generator);
		$removed = array();
		foreach ($generator['rows'] as $row) {
			if (!isset($kept[$row['key']]) && isset($items[$row['key']]) && 'trash' !== $items[$row['key']]->post_status) {
				$removed[] = $row['key'];
			}
		}

		return $removed;
	}
}
