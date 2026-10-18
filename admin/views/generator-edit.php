<?php

/**
 * Generator editor and generated items view.
 *
 * Expects $bpd_generator (array|null), $bpd_generators_model and $bpd_generators_notice.
 *
 * @package BulkPageDuplicator
 */
if (!defined('ABSPATH')) exit;

$bpd_gen = $bpd_generator ? $bpd_generator : array(
	'id'           => 0,
	'name'         => '',
	'post_type'    => 'page',
	'template_id'  => 0,
	'placeholders' => array(),
	'options'      => Bulk_Page_Duplicator_Generators::default_options(),
	'rows'         => array(),
	'last_run'     => 0,
);

$bpd_post_types = get_post_types(array('public' => true), 'objects');
unset($bpd_post_types['attachment']);

$bpd_core = new Bulk_Page_Duplicator_Core();
$bpd_page_builders = $bpd_core->detect_page_builders();
$bpd_seo_plugins = $bpd_core->detect_seo_plugins();

$bpd_replace_choices = array(
	'title'   => __('Title', 'bulk-page-duplicator'),
	'slug'    => __('Slug', 'bulk-page-duplicator'),
	'content' => __('Content and excerpt', 'bulk-page-duplicator'),
);
foreach ($bpd_page_builders as $bpd_builder) {
	/* translators: %s: page builder name. */
	$bpd_replace_choices[$bpd_builder['id']] = sprintf(__('%s data (if exists)', 'bulk-page-duplicator'), $bpd_builder['name']);
}
if (!empty($bpd_seo_plugins)) {
	$bpd_replace_choices['seo'] = __('SEO meta data', 'bulk-page-duplicator');
}
$bpd_copy_choices = array(
	'featured_image' => __('Copy featured image', 'bulk-page-duplicator'),
	'template_terms' => __('Copy template terms', 'bulk-page-duplicator'),
);
$bpd_saved_replace = (array) $bpd_gen['options']['replace_options'];
$bpd_hidden_replace = array_diff($bpd_saved_replace, array_keys($bpd_replace_choices), array_keys($bpd_copy_choices));

$bpd_template = $bpd_gen['template_id'] ? get_post($bpd_gen['template_id']) : null;
$bpd_parent_value = (string) $bpd_gen['options']['parent_page'];
$bpd_parent_post = 'template' !== $bpd_parent_value && absint($bpd_parent_value) ? get_post(absint($bpd_parent_value)) : null;
$bpd_post_type_obj = get_post_type_object($bpd_gen['post_type']);

$bpd_items = $bpd_gen['id'] ? $bpd_generators_model->get_linked_items($bpd_gen) : array();
$bpd_row_keys = wp_list_pluck($bpd_gen['rows'], 'key');
$bpd_orphans = 0;
foreach ($bpd_items as $bpd_item_key => $bpd_item) {
	if ('trash' !== $bpd_item->post_status && !in_array((string) $bpd_item_key, $bpd_row_keys, true)) {
		$bpd_orphans++;
	}
}
$bpd_placeholder_count = max(1, count($bpd_gen['placeholders']));
?>
<div class="wrap bpd-generators-wrap">
	<h1 class="wp-heading-inline">
		<?php echo esc_html($bpd_gen['id'] ? __('Edit Generator', 'bulk-page-duplicator') : __('New Generator', 'bulk-page-duplicator')); ?>
	</h1>
	<a href="<?php echo esc_url(Bulk_Page_Duplicator_Generators_Admin::page_url()); ?>" class="page-title-action"><?php esc_html_e('All Generators', 'bulk-page-duplicator'); ?></a>
	<hr class="wp-header-end">

	<?php if (!empty($bpd_generators_notice)) : ?>
		<div class="notice notice-<?php echo esc_attr($bpd_generators_notice['type']); ?> is-dismissible"><p><?php echo esc_html($bpd_generators_notice['message']); ?></p></div>
	<?php endif; ?>

	<form id="bpd-generator-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
		<input type="hidden" name="action" value="bpd_save_generator">
		<input type="hidden" name="generator_id" value="<?php echo esc_attr($bpd_gen['id']); ?>">
		<input type="hidden" name="rows_json" id="bpd-gen-rows-json" value="">
		<input type="hidden" name="confirm_trash" id="bpd-gen-confirm-trash" value="0">
		<input type="hidden" name="bpd_submit" id="bpd-gen-submit-action" value="save">
		<?php wp_nonce_field('bpd_save_generator'); ?>

		<div class="bpd-gen-panel">
			<h2><?php esc_html_e('Settings', 'bulk-page-duplicator'); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="bpd-gen-name"><?php esc_html_e('Name', 'bulk-page-duplicator'); ?></label></th>
					<td><input type="text" id="bpd-gen-name" name="name" class="regular-text" value="<?php echo esc_attr($bpd_gen['name']); ?>"></td>
				</tr>
				<tr>
					<th scope="row"><label for="bpd-gen-post-type"><?php esc_html_e('Post Type', 'bulk-page-duplicator'); ?></label></th>
					<td>
						<select id="bpd-gen-post-type" name="post_type">
							<?php foreach ($bpd_post_types as $bpd_post_type) : ?>
								<option value="<?php echo esc_attr($bpd_post_type->name); ?>" <?php selected($bpd_post_type->name, $bpd_gen['post_type']); ?>><?php echo esc_html($bpd_post_type->labels->singular_name); ?></option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="bpd-gen-template"><?php esc_html_e('Template', 'bulk-page-duplicator'); ?></label></th>
					<td>
						<input type="search" id="bpd-gen-template-search" class="regular-text" placeholder="<?php esc_attr_e('Search templates...', 'bulk-page-duplicator'); ?>" autocomplete="off">
						<br>
						<select id="bpd-gen-template" name="template_id" class="bpd-gen-wide-select">
							<option value=""><?php esc_html_e('Select a template', 'bulk-page-duplicator'); ?></option>
							<?php if ($bpd_template) : ?>
								<option value="<?php echo esc_attr($bpd_template->ID); ?>" selected>
									<?php
									/* translators: %1$s: template title, %2$d: template ID. */
									echo esc_html(sprintf(__('%1$s (ID: %2$d)', 'bulk-page-duplicator'), $bpd_template->post_title, $bpd_template->ID));
									?>
								</option>
							<?php endif; ?>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="bpd-gen-placeholders"><?php esc_html_e('Placeholders', 'bulk-page-duplicator'); ?></label></th>
					<td>
						<input type="text" id="bpd-gen-placeholders" name="placeholders" class="regular-text" value="<?php echo esc_attr(implode(', ', $bpd_gen['placeholders'])); ?>" placeholder="London" aria-describedby="bpd-gen-placeholders-help">
						<p class="description" id="bpd-gen-placeholders-help"><?php esc_html_e('Text to replace. Separate multiple placeholders with commas, e.g. "London, UK".', 'bulk-page-duplicator'); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="bpd-gen-status"><?php esc_html_e('Status for new items', 'bulk-page-duplicator'); ?></label></th>
					<td>
						<select id="bpd-gen-status" name="options[status]">
							<option value="draft" <?php selected('draft', $bpd_gen['options']['status']); ?>><?php esc_html_e('Draft', 'bulk-page-duplicator'); ?></option>
							<option value="publish" <?php selected('publish', $bpd_gen['options']['status']); ?>><?php esc_html_e('Published', 'bulk-page-duplicator'); ?></option>
						</select>
						<p class="description"><?php esc_html_e('Updating an existing item keeps its current status.', 'bulk-page-duplicator'); ?></p>
					</td>
				</tr>
				<tr id="bpd-gen-parent-row" <?php echo $bpd_post_type_obj && !$bpd_post_type_obj->hierarchical ? 'style="display: none;"' : ''; ?>>
					<th scope="row"><label for="bpd-gen-parent"><?php esc_html_e('Parent', 'bulk-page-duplicator'); ?></label></th>
					<td>
						<select id="bpd-gen-parent" name="options[parent_page]" class="bpd-gen-wide-select">
							<option value="0" <?php selected('0', $bpd_parent_value); ?>><?php esc_html_e('No parent (top level)', 'bulk-page-duplicator'); ?></option>
							<option value="template" <?php selected('template', $bpd_parent_value); ?>><?php esc_html_e('Same as template', 'bulk-page-duplicator'); ?></option>
							<?php if ($bpd_parent_post) : ?>
								<option value="<?php echo esc_attr($bpd_parent_post->ID); ?>" selected><?php echo esc_html($bpd_parent_post->post_title); ?></option>
							<?php endif; ?>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e('Replace text in', 'bulk-page-duplicator'); ?></th>
					<td>
						<fieldset>
							<?php foreach ($bpd_replace_choices as $bpd_choice => $bpd_label) : ?>
								<label><input type="checkbox" name="options[replace_options][]" value="<?php echo esc_attr($bpd_choice); ?>" <?php checked(in_array($bpd_choice, $bpd_saved_replace, true)); ?>> <?php echo esc_html($bpd_label); ?></label><br>
							<?php endforeach; ?>
							<?php foreach ($bpd_hidden_replace as $bpd_choice) : ?>
								<input type="hidden" name="options[replace_options][]" value="<?php echo esc_attr($bpd_choice); ?>">
							<?php endforeach; ?>
						</fieldset>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e('Additional options', 'bulk-page-duplicator'); ?></th>
					<td>
						<fieldset>
							<?php foreach ($bpd_copy_choices as $bpd_choice => $bpd_label) : ?>
								<label><input type="checkbox" name="options[replace_options][]" value="<?php echo esc_attr($bpd_choice); ?>" <?php checked(in_array($bpd_choice, $bpd_saved_replace, true)); ?>> <?php echo esc_html($bpd_label); ?></label><br>
							<?php endforeach; ?>
						</fieldset>
					</td>
				</tr>
				<tr id="bpd-gen-taxonomy-row">
					<th scope="row"><?php esc_html_e('Assign terms', 'bulk-page-duplicator'); ?></th>
					<td>
						<div id="bpd-gen-taxonomies">
							<?php foreach ((array) $bpd_gen['options']['taxonomy_terms'] as $bpd_taxonomy => $bpd_term_ids) : ?>
								<?php foreach ((array) $bpd_term_ids as $bpd_term_id) : ?>
									<input type="hidden" name="options[taxonomy_terms][<?php echo esc_attr($bpd_taxonomy); ?>][]" value="<?php echo esc_attr($bpd_term_id); ?>">
								<?php endforeach; ?>
							<?php endforeach; ?>
						</div>
						<p class="description" id="bpd-gen-taxonomy-loading" style="display: none;"><?php esc_html_e('Loading taxonomies...', 'bulk-page-duplicator'); ?></p>
					</td>
				</tr>
			</table>
		</div>

		<div class="bpd-gen-panel">
			<h2><?php esc_html_e('Rows and Generated Items', 'bulk-page-duplicator'); ?></h2>
			<p class="description" id="bpd-gen-rows-help">
				<?php esc_html_e('Each row creates one item. With multiple placeholders, separate the values in a row with commas. Editing a row re-renders its item on the next run; removing a row moves its item to the trash when you save.', 'bulk-page-duplicator'); ?>
			</p>
			<?php if ($bpd_orphans) : ?>
				<p class="description">
					<?php
					/* translators: %d: number of items no longer matching a row. */
					echo esc_html(sprintf(_n('%d item linked to this generator no longer matches a row and is left untouched.', '%d items linked to this generator no longer match a row and are left untouched.', $bpd_orphans, 'bulk-page-duplicator'), $bpd_orphans));
					?>
				</p>
			<?php endif; ?>

			<table class="widefat striped bpd-gen-rows-table" data-placeholder-count="<?php echo esc_attr($bpd_placeholder_count); ?>">
				<thead>
					<tr>
						<th scope="col" class="bpd-gen-col-index">#</th>
						<th scope="col"><?php esc_html_e('Values', 'bulk-page-duplicator'); ?></th>
						<th scope="col"><?php esc_html_e('Generated Item', 'bulk-page-duplicator'); ?></th>
						<th scope="col" class="bpd-gen-col-actions"><span class="screen-reader-text"><?php esc_html_e('Actions', 'bulk-page-duplicator'); ?></span></th>
					</tr>
				</thead>
				<tbody id="bpd-gen-rows">
					<?php foreach ($bpd_gen['rows'] as $bpd_index => $bpd_row) : ?>
						<?php
						$bpd_item = isset($bpd_items[$bpd_row['key']]) ? $bpd_items[$bpd_row['key']] : null;
						$bpd_state = $bpd_generators_model->get_row_state($bpd_gen, $bpd_row, $bpd_item);
						$bpd_has_item = $bpd_item && 'trash' !== $bpd_item->post_status;
						?>
						<tr data-key="<?php echo esc_attr($bpd_row['key']); ?>" data-has-item="<?php echo $bpd_has_item ? '1' : '0'; ?>">
							<td class="bpd-gen-col-index"><?php echo esc_html($bpd_index + 1); ?></td>
							<td>
								<input type="hidden" name="rows[<?php echo esc_attr($bpd_index); ?>][key]" class="bpd-gen-row-key" value="<?php echo esc_attr($bpd_row['key']); ?>">
								<input type="text" name="rows[<?php echo esc_attr($bpd_index); ?>][values]" class="widefat bpd-gen-row-values" value="<?php echo esc_attr(implode(', ', $bpd_row['values'])); ?>" aria-label="<?php esc_attr_e('Row values', 'bulk-page-duplicator'); ?>">
							</td>
							<td class="bpd-gen-item-cell"><?php echo wp_kses_post(Bulk_Page_Duplicator_Generators_Admin::render_item_cell($bpd_item, $bpd_state)); ?></td>
							<td class="bpd-gen-col-actions">
								<button type="button" class="button-link bpd-gen-remove-row"><?php esc_html_e('Remove', 'bulk-page-duplicator'); ?></button>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<p id="bpd-gen-no-rows" <?php echo empty($bpd_gen['rows']) ? '' : 'style="display: none;"'; ?>><?php esc_html_e('No rows yet. Add rows below.', 'bulk-page-duplicator'); ?></p>
			<p><button type="button" class="button" id="bpd-gen-add-row"><?php esc_html_e('Add Row', 'bulk-page-duplicator'); ?></button></p>

			<h3><label for="bpd-gen-bulk-rows"><?php esc_html_e('Add Many Rows', 'bulk-page-duplicator'); ?></label></h3>
			<p class="description"><?php esc_html_e('Paste one row per line, or load a CSV or TXT file. These rows are added when you save.', 'bulk-page-duplicator'); ?></p>
			<textarea id="bpd-gen-bulk-rows" name="bulk_rows" class="widefat" rows="5" placeholder="New York&#10;Los Angeles&#10;Chicago"></textarea>
			<p>
				<label class="button button-secondary">
					<?php esc_html_e('Load File', 'bulk-page-duplicator'); ?>
					<input type="file" id="bpd-gen-csv" accept=".csv,.txt" class="screen-reader-text">
				</label>
			</p>

			<div class="bpd-gen-run" id="bpd-gen-run" style="display: none;">
				<h3><?php esc_html_e('Run Progress', 'bulk-page-duplicator'); ?></h3>
				<div class="bpd-gen-progress"><div class="bpd-gen-progress-inner"></div></div>
				<p class="bpd-gen-run-status" aria-live="polite"></p>
				<div class="bpd-gen-log"></div>
			</div>

			<p class="submit">
				<button type="submit" class="button button-secondary button-large bpd-gen-submit" data-submit="save"><?php esc_html_e('Save Generator', 'bulk-page-duplicator'); ?></button>
				<button type="submit" class="button button-primary button-large bpd-gen-submit" data-submit="run"><?php esc_html_e('Save and Run', 'bulk-page-duplicator'); ?></button>
				<?php if ($bpd_gen['id']) : ?>
					<span class="bpd-gen-last-run">
						<?php
						if ($bpd_gen['last_run']) {
							/* translators: %s: date and time of the last run. */
							echo esc_html(sprintf(__('Last run: %s', 'bulk-page-duplicator'), get_date_from_gmt(gmdate('Y-m-d H:i:s', $bpd_gen['last_run']), get_option('date_format') . ' ' . get_option('time_format'))));
						} else {
							esc_html_e('Not run yet.', 'bulk-page-duplicator');
						}
						?>
					</span>
				<?php endif; ?>
			</p>
		</div>
	</form>
</div>
