<?php

/**
 * Core logic for Bulk Page Duplicator
 *
 * @package BulkPageDuplicator
 */

if (!defined('ABSPATH')) exit;

class Bulk_Page_Duplicator_Core {
	/**
	 * Whether Elementor's global asset cache needs to be cleared after this batch.
	 *
	 * @var bool
	 */
	private $elementor_cache_needs_clear = false;

	/**
	 * Handle AJAX duplication request
	 */
	public function process_bulk_duplication() {
		// Verify nonce (unslash and sanitize)
		$nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';
		if (empty($nonce) || !wp_verify_nonce($nonce, 'bulk_page_duplication')) {
			wp_send_json_error(__('Security check failed', 'bulk-page-duplicator'));
		}

		// Add capability check
		if (!current_user_can('manage_options')) {
			wp_send_json_error(__('You do not have permission to perform this action.', 'bulk-page-duplicator'));
		}

		// Get data from AJAX request (validate, unslash, sanitize)
		$template_id = isset($_POST['template_id']) ? intval(wp_unslash($_POST['template_id'])) : 0;
		// Support multiple placeholders (array)
		$placeholders = isset($_POST['placeholders']) ? array_map('sanitize_text_field', (array) wp_unslash($_POST['placeholders'])) : [];
		// Values can now be arrays (for multiple placeholders) or strings
		$batch_values = [];
		if (isset($_POST['values'])) {
			$raw_values = map_deep(wp_unslash($_POST['values']), 'sanitize_text_field');
			foreach ((array) $raw_values as $value_set) {
				if (is_array($value_set)) {
					$batch_values[] = $value_set;
				} else {
					$batch_values[] = [$value_set];
				}
			}
		}
		$page_status = isset($_POST['status']) ? sanitize_key(wp_unslash($_POST['status'])) : 'draft';
		$replace_options = isset($_POST['replace_options']) ? array_map('sanitize_key', (array) wp_unslash($_POST['replace_options'])) : [];
		$post_type = isset($_POST['post_type']) ? sanitize_key(wp_unslash($_POST['post_type'])) : 'page';
		$parent_page = isset($_POST['parent_page']) ? sanitize_text_field(wp_unslash($_POST['parent_page'])) : '0';
		$batch_index = isset($_POST['batch_index']) ? absint(wp_unslash($_POST['batch_index'])) : 0;
		$operation_id = isset($_POST['operation_id']) ? sanitize_key(wp_unslash($_POST['operation_id'])) : '';
		$operation_complete = isset($_POST['operation_complete']) && 'true' === sanitize_text_field(wp_unslash($_POST['operation_complete']));

		// Get taxonomy terms to assign
		$taxonomy_terms = [];
		if (isset($_POST['taxonomy_terms']) && is_array($_POST['taxonomy_terms'])) {
			$raw_taxonomy_terms = map_deep(wp_unslash($_POST['taxonomy_terms']), 'absint');
			foreach ($raw_taxonomy_terms as $taxonomy => $term_ids) {
				$taxonomy_terms[sanitize_key($taxonomy)] = array_map('absint', (array) $term_ids);
			}
		}

		$context = $this->prepare_generation_context([
			'template_id'     => $template_id,
			'post_type'       => $post_type,
			'status'          => $page_status,
			'parent_page'     => $parent_page,
			'placeholders'    => $placeholders,
			'replace_options' => $replace_options,
			'taxonomy_terms'  => $taxonomy_terms,
		]);
		if (is_wp_error($context)) {
			wp_send_json_error($context->get_error_message());
		}

		$results = [];
		$is_last_batch = false;

		if (empty($batch_values)) {
			$is_last_batch = true;
		} else {
			// Process this batch
			foreach ($batch_values as $value_set) {
				// Skip empty values
				if (empty($value_set) || (is_array($value_set) && empty(array_filter($value_set)))) continue;

				$results[] = $this->create_item($context, $value_set, $operation_id);
			}
		}

		$this->flush_builder_caches();

		// Store batch history for undo/rollback functionality
		$created_ids = array_filter(array_map(function($r) {
			return isset($r['status']) && $r['status'] === 'success' && isset($r['id']) ? $r['id'] : null;
		}, $results));

		$this->save_batch_history($template_id, $post_type, $created_ids, $batch_index, $operation_id, $operation_complete);

		wp_send_json_success([
			'results' => $results,
			'is_last_batch' => $is_last_batch
		]);
	}

	/**
	 * Validate the settings shared by every item of a generation run.
	 *
	 * Checks the post type, status, template, parent and taxonomy terms against
	 * the current user's capabilities and returns a context array consumed by
	 * create_item() and update_item().
	 *
	 * @param array $args {
	 *     @type int    $template_id     Template post ID.
	 *     @type string $post_type       Post type to create.
	 *     @type string $status          'draft' or 'publish'.
	 *     @type string $parent_page     Parent ID, '0' or 'template'.
	 *     @type array  $placeholders    Placeholder strings.
	 *     @type array  $replace_options Replace/copy option keys.
	 *     @type array  $taxonomy_terms  Term IDs keyed by taxonomy.
	 * }
	 * @return array|WP_Error
	 */
	public function prepare_generation_context($args) {
		$args = wp_parse_args($args, [
			'template_id'     => 0,
			'post_type'       => 'page',
			'status'          => 'draft',
			'parent_page'     => '0',
			'placeholders'    => [],
			'replace_options' => [],
			'taxonomy_terms'  => [],
		]);

		$template_id = absint($args['template_id']);
		$post_type = sanitize_key($args['post_type']);
		$page_status = sanitize_key($args['status']);

		// Validate post type exists and is public
		$post_type_obj = get_post_type_object($post_type);
		if (!$post_type_obj || !$post_type_obj->public || 'attachment' === $post_type) {
			return new WP_Error('bpd_invalid_post_type', __('Invalid post type', 'bulk-page-duplicator'));
		}

		$create_posts_cap = isset($post_type_obj->cap->create_posts) ? $post_type_obj->cap->create_posts : $post_type_obj->cap->edit_posts;
		if (!current_user_can($create_posts_cap)) {
			return new WP_Error('bpd_create_permission', __('You do not have permission to create this type of content.', 'bulk-page-duplicator'));
		}

		if (!in_array($page_status, array('draft', 'publish'), true)) {
			return new WP_Error('bpd_invalid_status', __('Invalid post status.', 'bulk-page-duplicator'));
		}

		if ('publish' === $page_status && !current_user_can($post_type_obj->cap->publish_posts)) {
			return new WP_Error('bpd_publish_permission', __('You do not have permission to publish this type of content.', 'bulk-page-duplicator'));
		}

		// Validate template post exists
		$template_page = get_post($template_id);
		if (!$template_page || $template_page->post_type !== $post_type) {
			return new WP_Error('bpd_invalid_template', __('Template not found', 'bulk-page-duplicator'));
		}

		if (!current_user_can('edit_post', $template_id)) {
			return new WP_Error('bpd_template_permission', __('You do not have permission to use this template.', 'bulk-page-duplicator'));
		}

		$post_parent = $this->validate_parent_page((string) $args['parent_page'], $template_page, $post_type_obj);
		if (is_wp_error($post_parent)) {
			return $post_parent;
		}

		$taxonomy_terms = $this->validate_taxonomy_terms((array) $args['taxonomy_terms'], $post_type);
		if (is_wp_error($taxonomy_terms)) {
			return $taxonomy_terms;
		}

		return [
			'template'        => $template_page,
			'template_id'     => $template_id,
			'post_type'       => $post_type,
			'status'          => $page_status,
			'post_parent'     => $post_parent,
			'placeholders'    => array_values((array) $args['placeholders']),
			'replace_options' => array_values((array) $args['replace_options']),
			'taxonomy_terms'  => $taxonomy_terms,
		];
	}

	/**
	 * Render the title, slug, content and excerpt of an item from its template.
	 *
	 * Thin wrapper over render_post_fields() using the generation form's options.
	 *
	 * @param WP_Post $template_page   Template post.
	 * @param array   $placeholders    Placeholder strings.
	 * @param array   $value_set       Replacement values.
	 * @param array   $replace_options Replace option keys.
	 * @return array Keys: title, slug, content, excerpt.
	 */
	public function render_item_fields($template_page, $placeholders, $value_set, $replace_options) {
		$fields = $this->render_post_fields($template_page, $placeholders, $value_set, $this->get_generation_replace_fields($replace_options));

		return [
			'title'   => $fields['post_title'],
			'slug'    => $fields['post_name'],
			'content' => $fields['post_content'],
			'excerpt' => $fields['post_excerpt'],
		];
	}

	/**
	 * Render arguments for create_item() and update_item().
	 *
	 * @param array $context Context from prepare_generation_context().
	 * @return array
	 */
	private function get_generation_render_args($context) {
		return [
			'extra_terms'     => $context['taxonomy_terms'],
			'replace'         => $this->get_generation_replace_fields($context['replace_options']),
			// Generation only adds a featured image; it never removes one.
			'clear_thumbnail' => false,
		];
	}

	/**
	 * Create one item from a template, skipping it if its slug already exists.
	 *
	 * @param array  $context      Context from prepare_generation_context().
	 * @param array  $value_set    Replacement values.
	 * @param string $operation_id Operation ID.
	 * @param array  $extra_meta   Additional meta to store on the new item.
	 * @return array Result with value, status (success|skipped|error) and message.
	 */
	public function create_item($context, $value_set, $operation_id, $extra_meta = []) {
		$template_page = $context['template'];
		$template_id = $context['template_id'];
		$post_type = $context['post_type'];
		$post_parent = $context['post_parent'];
		$placeholders = $context['placeholders'];
		$replace_options = $context['replace_options'];

		// For display purposes, use first value or joined values
		$display_value = is_array($value_set) ? implode(', ', $value_set) : $value_set;

		$post_fields = $this->render_post_fields($template_page, $placeholders, $value_set, $this->get_generation_replace_fields($replace_options));
		$title = $post_fields['post_title'];
		$slug = $post_fields['post_name'];

		// Check if this slug already exists under the requested parent.
		$existing_post = $this->get_post_by_slug($slug, $post_type, $post_parent);
		if ($existing_post) {
			return [
				'value' => $display_value,
				'status' => 'skipped',
				// translators: %s: The slug that already exists.
				'message' => sprintf(__('Item with slug "%s" already exists', 'bulk-page-duplicator'), $slug),
				'existing_id' => $existing_post->ID,
			];
		}

		// Create the duplicated post
		$new_post_id = wp_insert_post(wp_slash(array_merge($post_fields, [
			'post_status'    => $context['status'],
			'post_type'      => $post_type,
			'post_parent'    => $post_parent,
			'menu_order'     => $template_page->menu_order,
			'post_author'    => $template_page->post_author,
			'comment_status' => $template_page->comment_status,
			'ping_status'    => $template_page->ping_status,
		])), true);

		if (is_wp_error($new_post_id)) {
			return [
				'value' => $display_value,
				'status' => 'error',
				'message' => $new_post_id->get_error_message()
			];
		}

		$inserted_slug = get_post_field('post_name', $new_post_id);

		$this->save_generation_meta($new_post_id, $template_id, $operation_id, $placeholders, $value_set, false, $context['taxonomy_terms']);
		foreach ($extra_meta as $meta_key => $meta_value) {
			update_post_meta($new_post_id, $meta_key, wp_slash($meta_value));
		}

		// Metadata, builder data, SEO meta, featured image and terms (stores the output hash).
		$post_warnings = [];
		$render = $this->render_item($template_id, $new_post_id, $placeholders, $value_set, $this->get_generation_render_fields($replace_options), $this->get_generation_render_args($context));
		if (is_wp_error($render)) {
			$post_warnings[] = $render->get_error_message();
		} else {
			$post_warnings = $render['warnings'];
		}

		/* translators: %s: title of the newly created item. */
		$success_message = sprintf(__('Created: "%s"', 'bulk-page-duplicator'), $title);
		if ($inserted_slug !== $slug) {
			/* translators: %s: actual unique slug assigned by WordPress. */
			$post_warnings[] = sprintf(__('WordPress assigned the unique slug "%s".', 'bulk-page-duplicator'), $inserted_slug);
		}
		if (!empty($post_warnings)) {
			/* translators: %1$s: creation message, %2$s: warning details. */
			$success_message = sprintf(__('%1$s Warning: %2$s', 'bulk-page-duplicator'), $success_message, implode(' ', $post_warnings));
		}

		return [
			'value' => $display_value,
			'status' => 'success',
			'message' => $success_message,
			'id' => $new_post_id,
			'slug' => $inserted_slug,
			'edit_url' => esc_url_raw(get_edit_post_link($new_post_id, ''))
		];
	}

	/**
	 * Re-render an existing generated item from its template with new values.
	 *
	 * Title, slug (when slug replacement is enabled), content, excerpt, parent,
	 * metadata, builder data, featured image and terms are rewritten. The
	 * item's status, author and date are left untouched. The output hash is
	 * refreshed, so template sync treats the result as not edited by hand.
	 *
	 * @param array  $context      Context from prepare_generation_context().
	 * @param int    $post_id      Item to update.
	 * @param array  $value_set    Replacement values.
	 * @param string $operation_id Operation ID.
	 * @return array Result with value, status (updated|error) and message.
	 */
	public function update_item($context, $post_id, $value_set, $operation_id) {
		$display_value = is_array($value_set) ? implode(', ', $value_set) : $value_set;
		$post = get_post($post_id);

		if (!$post || $post->post_type !== $context['post_type']) {
			return [
				'value' => $display_value,
				'status' => 'error',
				'message' => __('The item to update was not found.', 'bulk-page-duplicator'),
			];
		}

		if (!current_user_can('edit_post', $post->ID)) {
			return [
				'value' => $display_value,
				'status' => 'error',
				'message' => __('You do not have permission to edit this item.', 'bulk-page-duplicator'),
			];
		}

		// Title, content and excerpt are always rewritten (copied unchanged when not replaced); the slug only when replaced.
		$fields = array_merge(['title', 'content', 'excerpt'], $this->get_generation_render_fields($context['replace_options']));
		if (in_array('slug', $context['replace_options'], true)) {
			$fields[] = 'slug';
		}

		$args = $this->get_generation_render_args($context);
		$args['postarr'] = ['post_parent' => $context['post_parent']];

		$render = $this->render_item($context['template_id'], $post->ID, $context['placeholders'], $value_set, $fields, $args);
		if (is_wp_error($render)) {
			return [
				'value' => $display_value,
				'status' => 'error',
				'message' => $render->get_error_message(),
			];
		}

		$this->save_generation_meta($post->ID, $context['template_id'], $operation_id, $context['placeholders'], $value_set, true, $context['taxonomy_terms']);

		$title = get_post_field('post_title', $post->ID);
		/* translators: %s: title of the updated item. */
		$message = sprintf(__('Updated: "%s"', 'bulk-page-duplicator'), $title);
		if (!empty($render['warnings'])) {
			/* translators: %1$s: update message, %2$s: warning details. */
			$message = sprintf(__('%1$s Warning: %2$s', 'bulk-page-duplicator'), $message, implode(' ', $render['warnings']));
		}

		return [
			'value' => $display_value,
			'status' => 'updated',
			'message' => $message,
			'id' => $post->ID,
			'slug' => get_post_field('post_name', $post->ID),
			'edit_url' => esc_url_raw(get_edit_post_link($post->ID, '')),
		];
	}

	/**
	 * Fields understood by render_item() and build_render_plan().
	 *
	 * - title, slug, content, excerpt: post fields, with placeholders replaced.
	 * - meta: copy every template meta key (generation only; SEO keys are replaced when "seo" is also set).
	 * - seo: the template's SEO plugin meta, with placeholders replaced.
	 * - elementor, beaver, bricks: page builder data, with placeholders replaced.
	 * - page_template: the template's _wp_page_template.
	 * - featured_image: the template's featured image (removed when the template has none).
	 * - terms: the template's terms, per taxonomy in which the template has terms.
	 *
	 * @return string[]
	 */
	public function get_render_fields() {
		return ['title', 'slug', 'content', 'excerpt', 'meta', 'seo', 'elementor', 'beaver', 'bricks', 'page_template', 'featured_image', 'terms'];
	}

	/**
	 * Map the generation form's replace options to render_post_fields() fields.
	 *
	 * @param string[] $replace_options Replace options from the generation form.
	 * @return string[]
	 */
	public function get_generation_replace_fields($replace_options) {
		$fields = array_values(array_intersect((array) $replace_options, ['title', 'slug', 'content']));
		if (in_array('content', $fields, true)) {
			$fields[] = 'excerpt';
		}
		return $fields;
	}

	/**
	 * Map the generation form's replace options to render_item() fields.
	 *
	 * @param string[] $replace_options Replace options from the generation form.
	 * @return string[]
	 */
	public function get_generation_render_fields($replace_options) {
		$fields = array_merge(['meta'], array_intersect((array) $replace_options, ['seo', 'elementor', 'beaver', 'bricks', 'featured_image']));
		if (in_array('template_terms', (array) $replace_options, true)) {
			$fields[] = 'terms';
		}
		return array_values($fields);
	}

	/**
	 * Render an item's title, slug, content and excerpt from a template.
	 *
	 * Fields not listed in $replace are copied from the template unchanged.
	 *
	 * @param WP_Post  $template     Template post.
	 * @param string[] $placeholders Placeholders.
	 * @param array    $values       Replacement values, in placeholder order.
	 * @param string[] $replace      Any of: title, slug, content, excerpt.
	 * @return array post_title, post_name, post_content and post_excerpt.
	 */
	public function render_post_fields($template, $placeholders, $values, $replace) {
		$placeholders = array_values((array) $placeholders);
		$values = array_values((array) $values);
		$replace = (array) $replace;

		return [
			'post_title'   => in_array('title', $replace, true) ? $this->multi_replace($template->post_title, $placeholders, $values) : $template->post_title,
			'post_name'    => in_array('slug', $replace, true) ? $this->render_slug($template->post_name, $placeholders, $values) : $template->post_name,
			'post_content' => in_array('content', $replace, true) ? $this->multi_replace($template->post_content, $placeholders, $values) : $template->post_content,
			'post_excerpt' => in_array('excerpt', $replace, true) ? $this->multi_replace($template->post_excerpt, $placeholders, $values) : $template->post_excerpt,
		];
	}

	/**
	 * Render a slug, also replacing slugified multi-word placeholders.
	 *
	 * @param string   $slug         Template slug.
	 * @param string[] $placeholders Placeholders.
	 * @param array    $values       Replacement values, in placeholder order.
	 * @return string
	 */
	public function render_slug($slug, $placeholders, $values) {
		$placeholders = array_values((array) $placeholders);
		$values = array_values((array) $values);
		$new_slug = $this->multi_replace($slug, $placeholders, $values);

		foreach ($placeholders as $index => $placeholder) {
			$value = isset($values[$index]) ? $values[$index] : '';
			$placeholder_slug = sanitize_title($placeholder);
			if ($placeholder_slug !== strtolower($placeholder)) {
				$new_slug = str_replace($placeholder_slug, sanitize_title($value), $new_slug);
			}
		}

		return sanitize_title($new_slug);
	}

	/**
	 * Render an existing item from a template and write the result.
	 *
	 * This is the single rendering path shared by generation and template sync.
	 *
	 * @param int      $template_id  Template post ID.
	 * @param int      $post_id      Item to write to.
	 * @param string[] $placeholders Placeholders.
	 * @param array    $values       Replacement values, in placeholder order.
	 * @param string[] $fields       Fields to render, see get_render_fields().
	 * @param array    $args {
	 *     Optional.
	 *
	 *     @type array      $extra_terms     Term IDs keyed by taxonomy, added to the template's terms.
	 *     @type bool       $update_hash     Whether to store the output hash afterwards. Default true.
	 *     @type array|null $replace         Fields among title, slug, content and excerpt to replace
	 *                                       placeholders in. Others listed in $fields are copied from
	 *                                       the template unchanged. Default null (replace in all).
	 *     @type array      $postarr         Extra post columns to write, e.g. post_parent.
	 *     @type bool       $clear_thumbnail Remove the item's featured image when the template has
	 *                                       none (with featured_image). Default true.
	 * }
	 * @return array|WP_Error Array with 'changed' (field names that differed) and 'warnings'.
	 */
	public function render_item($template_id, $post_id, $placeholders, $values, $fields, $args = []) {
		$plan = $this->build_render_plan($template_id, $post_id, $placeholders, $values, $fields, $args);
		if (is_wp_error($plan)) {
			return $plan;
		}

		$changed = $this->get_plan_changes($post_id, $plan);
		$result = $this->apply_render_plan($post_id, $plan);
		if (is_wp_error($result)) {
			return $result;
		}

		$result['changed'] = $changed;
		return $result;
	}

	/**
	 * Work out what render_item() would write, without writing anything.
	 *
	 * @param int      $template_id  Template post ID.
	 * @param int      $post_id      Item that will be written to.
	 * @param string[] $placeholders Placeholders.
	 * @param array    $values       Replacement values, in placeholder order.
	 * @param string[] $fields       Fields to render, see get_render_fields().
	 * @param array    $args         See render_item().
	 * @return array|WP_Error Render plan.
	 */
	public function build_render_plan($template_id, $post_id, $placeholders, $values, $fields, $args = []) {
		$template = get_post($template_id);
		$post = get_post($post_id);
		if (!$template || !$post) {
			return new WP_Error('bpd_render_missing', __('The template or item no longer exists.', 'bulk-page-duplicator'));
		}

		$args = wp_parse_args($args, [
			'extra_terms'     => [],
			'update_hash'     => true,
			'replace'         => null,
			'postarr'         => [],
			'clear_thumbnail' => true,
		]);
		$fields = array_values(array_intersect($this->get_render_fields(), (array) $fields));
		$placeholders = array_values((array) $placeholders);
		$values = array_values((array) $values);

		$plan = [
			'template_id'   => (int) $template->ID,
			'placeholders'  => $placeholders,
			'values'        => $values,
			'fields'        => $fields,
			'copy_all_meta' => in_array('meta', $fields, true),
			'post'          => [],
			'meta'          => [],
			'delete_meta'   => [],
			'thumbnail'     => null,
			'terms'         => null,
			'clear_caches'  => [],
			'update_hash'   => (bool) $args['update_hash'],
		];

		$replace = null === $args['replace'] ? $fields : (array) $args['replace'];
		$post_fields = $this->render_post_fields($template, $placeholders, $values, $replace);
		foreach (['title' => 'post_title', 'slug' => 'post_name', 'content' => 'post_content', 'excerpt' => 'post_excerpt'] as $field => $column) {
			if (in_array($field, $fields, true)) {
				$plan['post'][$column] = ['field' => $field, 'value' => $post_fields[$column]];
			}
		}
		foreach ((array) $args['postarr'] as $column => $value) {
			$plan['post'][sanitize_key($column)] = ['field' => sanitize_key($column), 'value' => $value];
		}

		// SEO meta on its own; with "meta", copy_post_meta_multi() handles SEO keys.
		if (in_array('seo', $fields, true) && !$plan['copy_all_meta']) {
			$prefixes = wp_list_pluck($this->detect_seo_plugins(), 'prefix');
			foreach (get_post_meta($template->ID) as $key => $raw_values) {
				if (!$this->key_has_prefix($key, $prefixes)) {
					continue;
				}
				$rendered = [];
				foreach ($raw_values as $raw_value) {
					$rendered[] = $this->render_meta_value($raw_value, $placeholders, $values);
				}
				$plan['meta'][$key] = ['field' => 'seo', 'values' => $rendered];
			}
		}

		if (in_array('elementor', $fields, true)) {
			$this->plan_elementor_data($plan, $template, $post);
		}
		if (in_array('beaver', $fields, true)) {
			$this->plan_beaver_builder_data($plan, $template);
		}
		if (in_array('bricks', $fields, true)) {
			$this->plan_bricks_builder_data($plan, $template);
		}

		if (in_array('page_template', $fields, true)) {
			$page_template = get_post_meta($template->ID, '_wp_page_template', true);
			$plan['meta']['_wp_page_template'] = ['field' => 'page_template', 'values' => '' !== $page_template ? [$page_template] : []];
		}

		if (in_array('featured_image', $fields, true)) {
			$thumbnail_id = (int) get_post_thumbnail_id($template->ID);
			if ($thumbnail_id || $args['clear_thumbnail']) {
				$plan['thumbnail'] = $thumbnail_id;
			}
		}

		$terms = in_array('terms', $fields, true) ? $this->get_post_term_ids($template->ID, $template->post_type) : [];
		foreach ((array) $args['extra_terms'] as $taxonomy => $term_ids) {
			$existing_ids = isset($terms[$taxonomy]) ? $terms[$taxonomy] : [];
			$terms[$taxonomy] = array_values(array_unique(array_merge($existing_ids, array_map('absint', (array) $term_ids))));
		}
		$terms = array_filter($terms);
		if (!empty($terms)) {
			$plan['terms'] = $terms;
		}

		return $plan;
	}

	/**
	 * List the fields whose stored value differs from a render plan.
	 *
	 * Copying all template meta (the "meta" field) is not compared.
	 *
	 * @param int   $post_id Item ID.
	 * @param array $plan    Plan from build_render_plan().
	 * @return string[] Field names.
	 */
	public function get_plan_changes($post_id, $plan) {
		$post = get_post($post_id);
		if (!$post) {
			return [];
		}

		$changed = [];

		foreach ($plan['post'] as $column => $entry) {
			if ((string) $post->$column !== (string) $entry['value']) {
				$changed[] = $entry['field'];
			}
		}

		foreach ($plan['meta'] as $key => $entry) {
			$current = array_map('maybe_serialize', get_post_meta($post_id, $key, false));
			$target = array_map('maybe_serialize', $entry['values']);
			if ($current !== $target) {
				$changed[] = $entry['field'];
			}
		}

		if (null !== $plan['thumbnail'] && (int) get_post_thumbnail_id($post_id) !== (int) $plan['thumbnail']) {
			$changed[] = 'featured_image';
		}

		if (null !== $plan['terms']) {
			foreach ($plan['terms'] as $taxonomy => $term_ids) {
				$current = wp_get_object_terms($post_id, $taxonomy, ['fields' => 'ids']);
				$current = is_wp_error($current) ? [] : array_map('absint', $current);
				$target = array_map('absint', $term_ids);
				sort($current);
				sort($target);
				if ($current !== $target) {
					$changed[] = 'terms';
					break;
				}
			}
		}

		return array_values(array_unique($changed));
	}

	/**
	 * Write a render plan to an item.
	 *
	 * @param int   $post_id Item ID.
	 * @param array $plan    Plan from build_render_plan().
	 * @return array|WP_Error Array with 'warnings'.
	 */
	public function apply_render_plan($post_id, $plan) {
		$warnings = [];

		if ($plan['copy_all_meta']) {
			$this->copy_post_meta_multi($plan['template_id'], $post_id, $plan['placeholders'], $plan['values'], $plan['fields']);
		}

		if (!empty($plan['post'])) {
			$postarr = ['ID' => $post_id];
			foreach ($plan['post'] as $column => $entry) {
				$postarr[$column] = $entry['value'];
			}
			$updated = wp_update_post(wp_slash($postarr), true);
			if (is_wp_error($updated)) {
				return $updated;
			}
		}

		foreach ($plan['meta'] as $key => $entry) {
			$this->write_meta_values($post_id, $key, $entry['values']);
		}

		foreach ($plan['delete_meta'] as $key) {
			delete_post_meta($post_id, $key);
		}

		if (null !== $plan['thumbnail']) {
			if ($plan['thumbnail'] > 0) {
				set_post_thumbnail($post_id, $plan['thumbnail']);
			} else {
				delete_post_thumbnail($post_id);
			}
		}

		if (null !== $plan['terms']) {
			foreach ($plan['terms'] as $taxonomy => $term_ids) {
				$term_result = wp_set_object_terms($post_id, $term_ids, $taxonomy);
				if (is_wp_error($term_result)) {
					$warnings[] = $term_result->get_error_message();
				}
			}
		}

		if (in_array('elementor', $plan['clear_caches'], true)) {
			// Defer Elementor's global cache clear until the batch is complete.
			$this->elementor_cache_needs_clear = true;
		}
		if (in_array('beaver', $plan['clear_caches'], true) && class_exists('FLBuilderModel')) {
			FLBuilderModel::delete_asset_cache($post_id);
		}

		if ($plan['update_hash']) {
			$this->update_output_hash($post_id);
		}

		return ['warnings' => $warnings];
	}

	/**
	 * Meta keys whose values make up the generated output hash, besides post_content.
	 *
	 * @return string[]
	 */
	public function get_output_hash_meta_keys() {
		return ['_elementor_data', '_fl_builder_data', '_bricks_page_content_2'];
	}

	/**
	 * Hash an item's content and builder data, used to detect manual edits.
	 *
	 * @param int $post_id Item ID.
	 * @return string
	 */
	public function compute_output_hash($post_id) {
		$parts = ['content' => (string) get_post_field('post_content', $post_id, 'raw')];
		foreach ($this->get_output_hash_meta_keys() as $key) {
			$parts[$key] = array_map('maybe_serialize', get_post_meta($post_id, $key, false));
		}
		return md5(serialize($parts)); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Only used as hash input.
	}

	/**
	 * Store the current output hash of an item.
	 *
	 * @param int $post_id Item ID.
	 */
	public function update_output_hash($post_id) {
		update_post_meta($post_id, '_bpd_output_hash', $this->compute_output_hash($post_id));
	}

	/**
	 * Replace every value of a meta key, keeping multi-valued keys intact.
	 *
	 * @param int    $post_id Post ID (revisions are written to directly).
	 * @param string $key     Meta key.
	 * @param array  $values  Unslashed values. Empty deletes the key.
	 */
	public function write_meta_values($post_id, $key, $values) {
		delete_metadata('post', $post_id, $key);
		foreach ((array) $values as $value) {
			add_metadata('post', $post_id, $key, $this->slash_meta_value($value));
		}
	}

	/**
	 * Slash a meta value, including strings inside objects (wp_slash() skips objects).
	 *
	 * @param mixed $value Value.
	 * @return mixed
	 */
	private function slash_meta_value($value) {
		if (is_string($value)) {
			return addslashes($value);
		}
		if (is_array($value)) {
			foreach ($value as $key => $item) {
				$value[$key] = $this->slash_meta_value($item);
			}
			return $value;
		}
		if (is_object($value) && !($value instanceof __PHP_Incomplete_Class)) {
			$copy = clone $value;
			foreach (get_object_vars($copy) as $property => $item) {
				$copy->$property = $this->slash_meta_value($item);
			}
			return $copy;
		}
		return $value;
	}

	/**
	 * Whether a meta key starts with one of the given prefixes.
	 *
	 * @param string   $key      Meta key.
	 * @param string[] $prefixes Prefixes.
	 * @return bool
	 */
	private function key_has_prefix($key, $prefixes) {
		foreach ($prefixes as $prefix) {
			if (0 === strpos($key, $prefix)) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Replace placeholders in a raw (possibly serialized or JSON) meta value.
	 *
	 * @param string   $raw_value    Raw meta value as stored.
	 * @param string[] $placeholders Placeholders.
	 * @param array    $values       Replacement values.
	 * @return mixed Unserialized value.
	 */
	private function render_meta_value($raw_value, $placeholders, $values) {
		if (is_serialized($raw_value)) {
			$unserialized = maybe_unserialize($raw_value);
			$this->replace_in_array_recursive_multi($unserialized, $placeholders, $values);
			return $unserialized;
		}

		if ($this->is_json($raw_value)) {
			$decoded = json_decode($raw_value, true);
			if (is_array($decoded)) {
				$this->replace_in_array_recursive_multi($decoded, $placeholders, $values);
				return wp_json_encode($decoded);
			}
			return $raw_value;
		}

		return $this->multi_replace($raw_value, $placeholders, $values);
	}

	/**
	 * Add Elementor data to a render plan.
	 *
	 * @param array   $plan     Render plan (modified).
	 * @param WP_Post $template Template post.
	 * @param WP_Post $post     Target item.
	 */
	private function plan_elementor_data(&$plan, $template, $post) {
		if ('builder' !== get_post_meta($template->ID, '_elementor_edit_mode', true)) {
			return;
		}

		$elementor_data = get_post_meta($template->ID, '_elementor_data', true);
		if (!empty($elementor_data)) {
			$plan['meta']['_elementor_data'] = ['field' => 'elementor', 'values' => [$this->multi_replace($elementor_data, $plan['placeholders'], $plan['values'])]];
		}

		$elementor_meta_keys = [
			'_elementor_version',
			'_elementor_template_type',
			'_elementor_page_settings',
			'_elementor_page_meta',
			'_elementor_controls_usage',
		];
		foreach ($elementor_meta_keys as $meta_key) {
			$meta_value = get_post_meta($template->ID, $meta_key, true);
			if (!empty($meta_value)) {
				$plan['meta'][$meta_key] = ['field' => 'elementor', 'values' => [$meta_value]];
			}
		}
		$plan['meta']['_elementor_edit_mode'] = ['field' => 'elementor', 'values' => ['builder']];

		// Force regeneration of CSS.
		$plan['delete_meta'][] = '_elementor_css';
		$plan['clear_caches'][] = 'elementor';

		// Elementor items need some post content.
		$content = isset($plan['post']['post_content']) ? $plan['post']['post_content']['value'] : $post->post_content;
		if (empty($content)) {
			$plan['post']['post_content'] = [
				'field' => 'elementor',
				'value' => '<!-- wp:shortcode -->[elementor-template id="' . (int) $post->ID . '"]<!-- /wp:shortcode -->',
			];
		}
	}

	/**
	 * Add Beaver Builder data to a render plan.
	 *
	 * @param array   $plan     Render plan (modified).
	 * @param WP_Post $template Template post.
	 */
	private function plan_beaver_builder_data(&$plan, $template) {
		$fl_builder_data = get_post_meta($template->ID, '_fl_builder_data', true);
		$fl_builder_draft = get_post_meta($template->ID, '_fl_builder_draft', true);

		if (empty($fl_builder_data) && empty($fl_builder_draft)) {
			return;
		}

		// Published and draft layout data.
		if (!empty($fl_builder_data) && is_array($fl_builder_data)) {
			$plan['meta']['_fl_builder_data'] = ['field' => 'beaver', 'values' => [$this->replace_in_beaver_builder_data($fl_builder_data, $plan['placeholders'], $plan['values'])]];
		}
		if (!empty($fl_builder_draft) && is_array($fl_builder_draft)) {
			$plan['meta']['_fl_builder_draft'] = ['field' => 'beaver', 'values' => [$this->replace_in_beaver_builder_data($fl_builder_draft, $plan['placeholders'], $plan['values'])]];
		}

		foreach (['_fl_builder_enabled', '_fl_builder_data_settings', '_fl_builder_draft_settings'] as $meta_key) {
			$meta_value = get_post_meta($template->ID, $meta_key, true);
			if (!empty($meta_value)) {
				$plan['meta'][$meta_key] = ['field' => 'beaver', 'values' => [$meta_value]];
			}
		}

		$plan['clear_caches'][] = 'beaver';
	}

	/**
	 * Add Bricks Builder data to a render plan.
	 *
	 * @param array   $plan     Render plan (modified).
	 * @param WP_Post $template Template post.
	 */
	private function plan_bricks_builder_data(&$plan, $template) {
		// Bricks stores data in _bricks_page_content_2, older versions in _bricks_page_content.
		$bricks_data = get_post_meta($template->ID, '_bricks_page_content_2', true);
		if (empty($bricks_data)) {
			$bricks_data = get_post_meta($template->ID, '_bricks_page_content', true);
		}
		if (empty($bricks_data)) {
			return;
		}

		// Bricks data can be JSON string or array.
		if (is_string($bricks_data)) {
			$bricks_data = json_decode($bricks_data, true);
		}
		if (!is_array($bricks_data)) {
			return;
		}

		$plan['meta']['_bricks_page_content_2'] = ['field' => 'bricks', 'values' => [$this->replace_in_bricks_data($bricks_data, $plan['placeholders'], $plan['values'])]];

		foreach (['_bricks_page_header_2', '_bricks_page_footer_2', '_bricks_page_settings', '_bricks_editor_mode'] as $meta_key) {
			$meta_value = get_post_meta($template->ID, $meta_key, true);
			if (empty($meta_value)) {
				continue;
			}
			// Header and footer areas also get replacements.
			if (is_array($meta_value) && (false !== strpos($meta_key, '_header') || false !== strpos($meta_key, '_footer'))) {
				$meta_value = $this->replace_in_bricks_data($meta_value, $plan['placeholders'], $plan['values']);
			}
			$plan['meta'][$meta_key] = ['field' => 'bricks', 'values' => [$meta_value]];
		}

		// Clear Bricks asset cache.
		$plan['delete_meta'][] = '_bricks_page_assets_css';
		$plan['delete_meta'][] = '_bricks_page_assets_js';
	}

	/**
	 * Clear builder caches deferred while processing a batch.
	 */
	public function flush_builder_caches() {
		if ($this->elementor_cache_needs_clear && class_exists('\\Elementor\\Plugin')) {
			\Elementor\Plugin::$instance->files_manager->clear_cache();
		}
		$this->elementor_cache_needs_clear = false;
	}

	/**
	 * Validate and resolve a requested parent post.
	 *
	 * @param string  $parent_page   Requested parent value.
	 * @param WP_Post $template_page Template post.
	 * @param object  $post_type_obj Post type object.
	 * @return int|WP_Error
	 */
	private function validate_parent_page($parent_page, $template_page, $post_type_obj) {
		if (!$post_type_obj->hierarchical) {
			return 0;
		}

		$post_parent = 'template' === $parent_page ? absint($template_page->post_parent) : absint($parent_page);
		if (!$post_parent) {
			return 0;
		}

		$parent = get_post($post_parent);
		if (!$parent || $parent->post_type !== $post_type_obj->name) {
			return new WP_Error('bpd_invalid_parent', __('Invalid parent item.', 'bulk-page-duplicator'));
		}

		if (!current_user_can('edit_post', $post_parent)) {
			return new WP_Error('bpd_parent_permission', __('You do not have permission to use the selected parent.', 'bulk-page-duplicator'));
		}

		return $post_parent;
	}

	/**
	 * Validate taxonomy and term assignments for a post type.
	 *
	 * @param array  $taxonomy_terms Submitted taxonomy terms.
	 * @param string $post_type      Requested post type.
	 * @return array|WP_Error
	 */
	private function validate_taxonomy_terms($taxonomy_terms, $post_type) {
		$validated = [];

		foreach ($taxonomy_terms as $taxonomy => $term_ids) {
			$taxonomy_obj = get_taxonomy($taxonomy);
			if (!$taxonomy_obj || !is_object_in_taxonomy($post_type, $taxonomy)) {
				return new WP_Error('bpd_invalid_taxonomy', __('Invalid taxonomy selection.', 'bulk-page-duplicator'));
			}

			if (!current_user_can($taxonomy_obj->cap->assign_terms)) {
				return new WP_Error('bpd_taxonomy_permission', __('You do not have permission to assign the selected terms.', 'bulk-page-duplicator'));
			}

			$validated[$taxonomy] = [];
			foreach (array_unique(array_filter(array_map('absint', $term_ids))) as $term_id) {
				if (!term_exists($term_id, $taxonomy)) {
					return new WP_Error('bpd_invalid_term', __('One or more selected terms no longer exist.', 'bulk-page-duplicator'));
				}
				$validated[$taxonomy][] = $term_id;
			}
		}

		return $validated;
	}

	/**
	 * Find an existing post by slug and parent.
	 *
	 * @param string $slug        Post slug.
	 * @param string $post_type   Post type.
	 * @param int    $post_parent Parent post ID.
	 * @return WP_Post|null
	 */
	public function get_post_by_slug($slug, $post_type, $post_parent = 0) {
		$args = array(
			'post_type'              => $post_type,
			'name'                   => sanitize_title($slug),
			'post_status'            => array('publish', 'draft', 'pending', 'private', 'future', 'trash'),
			'posts_per_page'         => 1,
			'no_found_rows'          => true,
			'ignore_sticky_posts'    => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
		);

		$post_type_obj = get_post_type_object($post_type);
		if ($post_type_obj && $post_type_obj->hierarchical) {
			$args['post_parent'] = absint($post_parent);
		}

		$posts = get_posts($args);
		return !empty($posts) ? $posts[0] : null;
	}

	/**
	 * Save batch history for undo/rollback functionality
	 *
	 * @param int $template_id The template post ID
	 * @param string $post_type The post type created
	 * @param array $post_ids Array of created post IDs
	 * @param int    $batch_index  Current batch index (0 for first batch).
	 * @param string $operation_id       Unique client-generated operation ID.
	 * @param bool   $operation_complete Whether the operation sent its final batch.
	 */
	public function save_batch_history($template_id, $post_type, $post_ids, $batch_index, $operation_id = '', $operation_complete = false) {
		$history = get_option('bpd_batch_history', []);
		$user_id = get_current_user_id();

		if (empty($operation_id)) {
			$operation_id = 'legacy-' . wp_generate_uuid4();
		}

		$session_key = 'session_' . $user_id . '_' . $operation_id;

		// Create the operation on its first received batch, even if nothing was created.
		if (!isset($history[$session_key])) {
			$template = get_post($template_id);
			$post_type_obj = get_post_type_object($post_type);
			$history[$session_key] = [
				'timestamp' => time(),
				'user_id' => $user_id,
				'template_id' => $template_id,
				'template_title' => $template ? $template->post_title : __('Unknown', 'bulk-page-duplicator'),
				'post_type' => $post_type,
				'post_type_label' => $post_type_obj ? $post_type_obj->labels->name : $post_type,
				'post_ids' => array_values(array_map('absint', $post_ids)),
			];
		} else {
			$history[$session_key]['post_ids'] = array_values(array_unique(array_merge(
				$history[$session_key]['post_ids'],
				array_map('absint', $post_ids)
			)));
		}

		if ($operation_complete && empty($history[$session_key]['post_ids'])) {
			unset($history[$session_key]);
		}

		// Keep only the last 20 operations
		if (count($history) > 20) {
			$history = array_slice($history, -20, 20, true);
		}

		update_option('bpd_batch_history', $history, false);
	}

	/**
	 * Get batch history for display
	 *
	 * @param int $limit Number of history items to return
	 * @return array Array of history items
	 */
	public function get_batch_history($limit = 10) {
		$history = get_option('bpd_batch_history', []);
		$user_id = get_current_user_id();

		$history = array_filter($history, function($item) use ($user_id) {
			return isset($item['user_id'], $item['post_ids']) && (int) $item['user_id'] === $user_id && !empty($item['post_ids']);
		});

		// Sort by timestamp descending (most recent first)
		uasort($history, function($a, $b) {
			return $b['timestamp'] - $a['timestamp'];
		});

		// Limit results
		$history = array_slice($history, 0, $limit, true);

		// Format for display
		$formatted = [];
		foreach ($history as $key => $item) {
			// Count how many posts still exist
			$existing_count = 0;
			foreach ($item['post_ids'] as $post_id) {
				if (get_post($post_id)) {
					$existing_count++;
				}
			}

			$formatted[] = [
				'key' => $key,
				'timestamp' => $item['timestamp'],
				'date' => get_date_from_gmt(gmdate('Y-m-d H:i:s', $item['timestamp']), get_option('date_format') . ' ' . get_option('time_format')),
				'template_title' => $item['template_title'],
				'post_type_label' => $item['post_type_label'],
				'total_count' => count($item['post_ids']),
				'existing_count' => $existing_count,
				'can_rollback' => $existing_count > 0,
			];
		}

		return $formatted;
	}

	/**
	 * Rollback (delete) posts from a batch operation
	 *
	 * @param string $session_key The session key to rollback
	 * @return array Result with success status and message
	 */
	public function rollback_batch($session_key) {
		$history = get_option('bpd_batch_history', []);

		if (!isset($history[$session_key])) {
			return [
				'success' => false,
				'message' => __('Operation not found in history.', 'bulk-page-duplicator')
			];
		}

		$item = $history[$session_key];
		if (!isset($item['user_id']) || (int) $item['user_id'] !== get_current_user_id()) {
			return [
				'success' => false,
				'message' => __('You do not have permission to rollback this operation.', 'bulk-page-duplicator')
			];
		}

		$deleted_count = 0;
		$failed_count = 0;
		$remaining_ids = [];

		foreach ($item['post_ids'] as $post_id) {
			$post = get_post($post_id);
			if ($post) {
				if (!current_user_can('delete_post', $post_id)) {
					$failed_count++;
					$remaining_ids[] = $post_id;
					continue;
				}

				// Force delete (bypass trash)
				$result = wp_delete_post($post_id, true);
				if ($result) {
					$deleted_count++;
				} else {
					$failed_count++;
					$remaining_ids[] = $post_id;
				}
			}
		}

		if (empty($remaining_ids)) {
			unset($history[$session_key]);
		} else {
			$history[$session_key]['post_ids'] = $remaining_ids;
		}
		update_option('bpd_batch_history', $history, false);

		if ($failed_count > 0) {
			return [
				'success' => true,
				// translators: %1$d: number of deleted posts, %2$d: number of failed deletions
				'message' => sprintf(__('Deleted %1$d items. %2$d items could not be deleted.', 'bulk-page-duplicator'), $deleted_count, $failed_count)
			];
		}

		return [
			'success' => true,
			// translators: %d: number of deleted posts
			'message' => sprintf(__('Successfully deleted %d items.', 'bulk-page-duplicator'), $deleted_count)
		];
	}

	/**
	 * Create a draft copy of a single item, including its metadata, terms and featured image.
	 *
	 * @param int $source_id ID of the item to copy.
	 * @return int|WP_Error New post ID, or an error.
	 */
	public function duplicate_single_post($source_id) {
		$source = get_post($source_id);
		if (!$source || 'trash' === $source->post_status || 'auto-draft' === $source->post_status) {
			return new WP_Error('bpd_invalid_source', __('The item to duplicate was not found.', 'bulk-page-duplicator'));
		}

		if (!$this->can_duplicate_post($source)) {
			return new WP_Error('bpd_permission', __('You do not have permission to duplicate this item.', 'bulk-page-duplicator'));
		}

		/* translators: %s: title of the item being duplicated. */
		$title = '' !== $source->post_title ? sprintf(__('%s (Copy)', 'bulk-page-duplicator'), $source->post_title) : '';

		/**
		 * Filter the title given to a quick duplicate.
		 *
		 * @param string  $title  Title for the copy.
		 * @param WP_Post $source Item being duplicated.
		 */
		$title = apply_filters('bpd_duplicate_post_title', $title, $source);

		$new_post_id = wp_insert_post(wp_slash([
			'post_title'     => $title,
			'post_content'   => $source->post_content,
			'post_excerpt'   => $source->post_excerpt,
			'post_status'    => 'draft',
			'post_type'      => $source->post_type,
			'post_parent'    => $source->post_parent,
			'menu_order'     => $source->menu_order,
			'post_password'  => $source->post_password,
			'post_author'    => get_current_user_id(),
			'comment_status' => $source->comment_status,
			'ping_status'    => $source->ping_status,
		]), true);

		if (is_wp_error($new_post_id)) {
			return $new_post_id;
		}

		$this->copy_post_meta_multi($source->ID, $new_post_id, [], [], []);

		$thumbnail_id = get_post_thumbnail_id($source->ID);
		if ($thumbnail_id) {
			set_post_thumbnail($new_post_id, $thumbnail_id);
		}

		foreach ($this->get_post_term_ids($source->ID, $source->post_type) as $taxonomy => $term_ids) {
			wp_set_object_terms($new_post_id, $term_ids, $taxonomy);
		}

		// Builders regenerate their CSS for the new ID.
		delete_post_meta($new_post_id, '_elementor_css');
		delete_post_meta($new_post_id, '_bricks_page_assets_css');
		delete_post_meta($new_post_id, '_bricks_page_assets_js');
		if (class_exists('FLBuilderModel')) {
			FLBuilderModel::delete_asset_cache($new_post_id);
		}

		update_post_meta($new_post_id, '_bpd_duplicated_from', $source->ID);

		/**
		 * Fires after an item has been quick-duplicated.
		 *
		 * @param int     $new_post_id ID of the new draft.
		 * @param WP_Post $source      Item that was duplicated.
		 */
		do_action('bpd_after_duplicate_post', $new_post_id, $source);

		return $new_post_id;
	}

	/**
	 * Whether the current user may quick-duplicate an item.
	 *
	 * @param WP_Post|int $post Item to check.
	 * @return bool
	 */
	public function can_duplicate_post($post) {
		$post = get_post($post);
		if (!$post || !in_array($post->post_type, $this->get_duplicable_post_types(), true)) {
			return false;
		}

		$post_type_obj = get_post_type_object($post->post_type);
		$create_posts_cap = isset($post_type_obj->cap->create_posts) ? $post_type_obj->cap->create_posts : $post_type_obj->cap->edit_posts;

		return current_user_can('edit_post', $post->ID) && current_user_can($create_posts_cap);
	}

	/**
	 * Post types that offer quick duplication.
	 *
	 * @return string[]
	 */
	public function get_duplicable_post_types() {
		$excluded = ['attachment'];

		// WooCommerce ships its own product duplicator that handles SKUs and lookup tables.
		if (class_exists('WooCommerce')) {
			$excluded[] = 'product';
		}

		$post_types = array_values(array_diff(get_post_types(['public' => true]), $excluded));

		/**
		 * Filter the post types that show Duplicate links.
		 *
		 * @param string[] $post_types Post type names.
		 */
		return (array) apply_filters('bpd_duplicable_post_types', $post_types);
	}

	/**
	 * Get an item's term IDs for every taxonomy registered to its post type.
	 *
	 * @param int    $post_id   Post ID.
	 * @param string $post_type Post type.
	 * @return array Term IDs keyed by taxonomy.
	 */
	private function get_post_term_ids($post_id, $post_type) {
		$terms = [];

		foreach (get_object_taxonomies($post_type) as $taxonomy) {
			// Translation plugins manage language assignments themselves.
			if (in_array($taxonomy, ['language', 'post_translations', 'term_language', 'term_translations'], true)) {
				continue;
			}

			$term_ids = wp_get_object_terms($post_id, $taxonomy, ['fields' => 'ids']);
			if (!is_wp_error($term_ids) && !empty($term_ids)) {
				$terms[$taxonomy] = array_map('absint', $term_ids);
			}
		}

		return $terms;
	}

	/**
	 * Record which template and values produced a generated item.
	 *
	 * @param int    $post_id      Generated post ID.
	 * @param int    $template_id  Template post ID.
	 * @param string $operation_id Operation ID.
	 * @param array  $placeholders Placeholders used.
	 * @param array  $value_set    Replacement values used.
	 * @param bool   $is_update    Whether an existing item was re-rendered.
	 * @param array  $extra_terms  Term IDs chosen in the form, keyed by taxonomy.
	 */
	private function save_generation_meta($post_id, $template_id, $operation_id, $placeholders, $value_set, $is_update = false, $extra_terms = []) {
		$generation = [
			'operation_id' => $operation_id,
			'placeholders' => array_values($placeholders),
			'values'       => array_values((array) $value_set),
			'created'      => time(),
			'terms'        => array_filter((array) $extra_terms),
		];

		if ($is_update) {
			$previous = get_post_meta($post_id, '_bpd_generation', true);
			if (is_array($previous) && !empty($previous['created'])) {
				$generation['created'] = absint($previous['created']);
			}
			$generation['updated'] = time();
		}

		update_post_meta($post_id, '_bpd_template_id', absint($template_id));
		update_post_meta($post_id, '_bpd_generation', wp_slash($generation));
	}

	/**
	 * Public wrapper for multi_replace (used by admin class for dry run)
	 *
	 * @param string $text The text to process
	 * @param array $placeholders Array of placeholder strings
	 * @param array $values Array of replacement values (matching placeholders order)
	 * @return string The processed text
	 */
	public function multi_replace_public($text, $placeholders, $values) {
		return $this->multi_replace($text, $placeholders, $values);
	}

	/**
	 * Helper function to replace placeholders in Beaver Builder data
	 */
	private function replace_in_beaver_builder_data($data, $placeholders, $values) {
		if (!is_array($data)) {
			return $data;
		}

		foreach ($data as $node_id => $node) {
			if (is_object($node)) {
				$node = (array) $node;
			}

			if (is_array($node)) {
				// Replace in settings
				if (isset($node['settings'])) {
					$settings = (array) $node['settings'];
					foreach ($settings as $key => $value) {
						if (is_string($value)) {
							$settings[$key] = $this->multi_replace($value, $placeholders, $values);
						}
					}
					$node['settings'] = (object) $settings;
				}

				$data[$node_id] = (object) $node;
			}
		}

		return $data;
	}

	/**
	 * Helper function to replace placeholders in Bricks Builder data
	 */
	private function replace_in_bricks_data($data, $placeholders, $values) {
		if (!is_array($data)) {
			return $data;
		}

		foreach ($data as $index => $element) {
			if (is_array($element)) {
				// Replace in settings
				if (isset($element['settings']) && is_array($element['settings'])) {
					$data[$index]['settings'] = $this->replace_in_bricks_settings($element['settings'], $placeholders, $values);
				}

				// Process nested children
				if (isset($element['children']) && is_array($element['children'])) {
					$data[$index]['children'] = $this->replace_in_bricks_data($element['children'], $placeholders, $values);
				}
			}
		}

		return $data;
	}

	/**
	 * Helper function to replace placeholders in Bricks settings
	 */
	private function replace_in_bricks_settings($settings, $placeholders, $values) {
		foreach ($settings as $key => $value) {
			if (is_string($value)) {
				$settings[$key] = $this->multi_replace($value, $placeholders, $values);
			} elseif (is_array($value)) {
				$settings[$key] = $this->replace_in_bricks_settings($value, $placeholders, $values);
			}
		}
		return $settings;
	}

	/**
	 * Detect supported page builders
	 *
	 * @return array
	 */
	public function detect_page_builders() {
		$page_builders = [];

		// Elementor
		if (defined('ELEMENTOR_VERSION') || class_exists('\Elementor\Plugin')) {
			$page_builders[] = [
				'name' => 'Elementor',
				'id' => 'elementor',
			];
		}

		// Beaver Builder
		if (defined('FL_BUILDER_VERSION') || class_exists('FLBuilder')) {
			$page_builders[] = [
				'name' => 'Beaver Builder',
				'id' => 'beaver',
			];
		}

		// Bricks Builder
		if (defined('BRICKS_VERSION') || class_exists('Bricks\Elements')) {
			$page_builders[] = [
				'name' => 'Bricks Builder',
				'id' => 'bricks',
			];
		}

		return $page_builders;
	}

	/**
	 * Apply multiple placeholder replacements to text
	 *
	 * @param string $text The text to process
	 * @param array $placeholders Array of placeholder strings
	 * @param array $values Array of replacement values (matching placeholders order)
	 * @return string The processed text
	 */
	private function multi_replace($text, $placeholders, $values) {
		if (empty($text) || empty($placeholders)) {
			return $text;
		}

		// Ensure values is an array
		if (!is_array($values)) {
			$values = [$values];
		}

		// Apply each placeholder replacement
		foreach ($placeholders as $index => $placeholder) {
			$value = isset($values[$index]) ? $values[$index] : '';
			if (!empty($placeholder) && !empty($value)) {
				$text = $this->smart_replace($text, $placeholder, $value);
			}
		}

		return $text;
	}

	/**
	 * Copy post meta from template to new post, with multiple placeholder replacements
	 */
	private function copy_post_meta_multi($from_id, $to_id, $placeholders, $values, $replace_options) {
		$post_meta = get_post_meta($from_id);

		// SEO plugins detection
		$seo_plugins = $this->detect_seo_plugins();

		foreach ($post_meta as $key => $meta_values) {
			// Skip temporary editor data and handle the featured image separately.
			if (in_array($key, ['_edit_lock', '_edit_last', '_thumbnail_id', '_wp_old_slug', '_wp_old_date'], true)) {
				continue;
			}

			// Never inherit this plugin's own tracking data from the source item.
			if (0 === strpos($key, '_bpd_')) {
				continue;
			}

			// Elementor data is transformed separately only when replacement is enabled.
			if ($key === '_elementor_data' && in_array('elementor', $replace_options, true)) {
				continue;
			}

			// Recreate this key exactly so multi-valued metadata retains its row structure.
			delete_post_meta($to_id, $key);

			// SEO meta data replacement
			$is_seo_field = false;
			foreach ($seo_plugins as $plugin) {
				if (strpos($key, $plugin['prefix']) === 0) {
					$is_seo_field = true;
					break;
				}
			}

			foreach ($meta_values as $meta_value) {
				// Replace placeholders in SEO fields (serialized, JSON or plain values).
				if ($is_seo_field && in_array('seo', $replace_options, true)) {
					$meta_value = $this->render_meta_value($meta_value, $placeholders, $values);
				} else {
					$meta_value = maybe_unserialize($meta_value);
				}

				add_post_meta($to_id, $key, $this->slash_meta_value($meta_value), false);
			}
		}
	}

	/**
	 * Helper function to replace multiple placeholders in a nested array
	 *
	 * @param array &$array The array to process
	 * @param array $placeholders Array of placeholder strings
	 * @param array $values Array of replacement values
	 */
	private function replace_in_array_recursive_multi(&$array, $placeholders, $values) {
		if (!is_array($array)) {
			return;
		}

		foreach ($array as $key => &$value) {
			if (is_array($value)) {
				$this->replace_in_array_recursive_multi($value, $placeholders, $values);
			} else if (is_string($value)) {
				$value = $this->multi_replace($value, $placeholders, $values);
			}
		}
	}

	/**
	 * Helper function to replace text in a nested array
	 *
	 * @param array  &$array  The array to process
	 * @param string $search  The search string
	 * @param string $replace The replacement string
	 */
	private function replace_in_array_recursive(&$array, $search, $replace) {
		if (!is_array($array)) {
			return;
		}

		foreach ($array as $key => &$value) {
			if (is_array($value)) {
				$this->replace_in_array_recursive($value, $search, $replace);
			} else if (is_string($value)) {
				$value = $this->smart_replace($value, $search, $replace);
			}
		}
	}

	/**
	 * Replaces text while preserving the case format
	 *
	 * @param string $text The text to process
	 * @param string $search The search string
	 * @param string $replace The replacement string
	 * @return string The processed text
	 */
	private function smart_replace($text, $search, $replace) {
		// Skip empty values
		if (empty($text) || empty($search) || empty($replace)) {
			return $text;
		}

		// 1. Prepare patterns and replacements for each case
		$patterns = [];
		$replacements = [];

		// Case 1: All uppercase
		$patterns[] = '/' . preg_quote(strtoupper($search), '/') . '/';
		$replacements[] = strtoupper($replace);

		// Case 2: Title case (first letter uppercase)
		$patterns[] = '/' . preg_quote(ucfirst(strtolower($search)), '/') . '/';
		if (function_exists('mb_convert_case')) {
			$replacements[] = mb_convert_case($replace, MB_CASE_TITLE, 'UTF-8');
		} else {
			$replacements[] = ucwords(strtolower($replace), " 	\r\n\f\v-");
		}

		// Case 3: Exact match (as provided)
		$patterns[] = '/' . preg_quote($search, '/') . '/';
		$replacements[] = $replace;

		// Case 4: Lowercase match (must be last to avoid overriding other cases)
		$patterns[] = '/' . preg_quote(strtolower($search), '/') . '/i';
		$replacements[] = strtolower($replace);

		// 2. Apply all replacements
		return preg_replace($patterns, $replacements, $text);
	}

	/**
	 * Check if a string is a JSON string
	 *
	 * @param mixed $string The string to check
	 * @return bool Whether the string is valid JSON
	 */
	private function is_json($string) {
		if (!is_string($string)) {
			return false;
		}

		json_decode($string);
		return (json_last_error() == JSON_ERROR_NONE);
	}

	/**
	 * Detect supported SEO plugins and their meta prefixes
	 *
	 * @return array
	 */
	public function detect_seo_plugins() {
		$seo_plugins = [];

		// Yoast SEO
		if (defined('WPSEO_VERSION')) {
			$seo_plugins[] = [
				'name' => 'Yoast SEO',
				'prefix' => '_yoast_wpseo_'
			];
		}

		// Rank Math
		if (class_exists('RankMath')) {
			$seo_plugins[] = [
				'name' => 'Rank Math',
				'prefix' => 'rank_math_'
			];
		}

		// All in One SEO Pack
		if (class_exists('AIOSEO\Plugin\AIOSEO')) {
			$seo_plugins[] = [
				'name' => 'All in One SEO Pack',
				'prefix' => '_aioseo_'
			];
		}

		// SEOPress
		if (defined('SEOPRESS_VERSION')) {
			$seo_plugins[] = [
				'name' => 'SEOPress',
				'prefix' => '_seopress_'
			];
		}

		return $seo_plugins;
	}
}
