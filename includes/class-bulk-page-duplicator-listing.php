<?php

/**
 * Front-end list of generated pages: [bpd_generated_pages] shortcode and block.
 *
 * @package BulkPageDuplicator
 */

if (!defined('ABSPATH')) exit;

class Bulk_Page_Duplicator_Listing {
	/**
	 * Maximum number of items one list may show.
	 */
	const MAX_ITEMS = 500;

	/**
	 * Register hooks.
	 */
	public function init() {
		add_shortcode('bpd_generated_pages', array($this, 'render_shortcode'));
		add_action('init', array($this, 'register_block'));
	}

	/**
	 * Default list options.
	 *
	 * @return array
	 */
	public function get_defaults() {
		return array(
			'template'        => 0,
			'generator'       => 0,
			'orderby'         => 'title',
			'order'           => 'ASC',
			'limit'           => 100,
			'columns'         => 1,
			'exclude_current' => true,
			'show_excerpt'    => false,
		);
	}

	/**
	 * Shortcode handler.
	 *
	 * @param array|string $atts Shortcode attributes.
	 * @return string
	 */
	public function render_shortcode($atts) {
		$atts = shortcode_atts($this->get_defaults(), (array) $atts, 'bpd_generated_pages');
		$atts['exclude_current'] = $this->to_bool($atts['exclude_current']);
		$atts['show_excerpt'] = $this->to_bool($atts['show_excerpt']);

		return $this->render_list($atts);
	}

	/**
	 * Register the block (WordPress 5.0+; the editor preview needs server-side rendering).
	 */
	public function register_block() {
		if (!function_exists('register_block_type')) {
			return;
		}

		$deps = array('wp-blocks', 'wp-element', 'wp-components', 'wp-i18n');
		$deps[] = wp_script_is('wp-block-editor', 'registered') ? 'wp-block-editor' : 'wp-editor';
		if (wp_script_is('wp-server-side-render', 'registered')) {
			$deps[] = 'wp-server-side-render';
		}

		wp_register_script('bulk-page-duplicator-block', BULK_PAGE_DUPLICATOR_URL . 'admin/assets/js/block-generated-pages.js', $deps, BULK_PAGE_DUPLICATOR_VERSION, true);
		wp_set_script_translations('bulk-page-duplicator-block', 'bulk-page-duplicator', BULK_PAGE_DUPLICATOR_PATH . 'languages');

		register_block_type('bulk-page-duplicator/generated-pages', array(
			'api_version'     => 3,
			'editor_script'   => 'bulk-page-duplicator-block',
			'render_callback' => array($this, 'render_block'),
			'attributes'      => array(
				'template'       => array('type' => 'number', 'default' => 0),
				'generator'      => array('type' => 'number', 'default' => 0),
				'orderby'        => array('type' => 'string', 'default' => 'title'),
				'order'          => array('type' => 'string', 'default' => 'ASC'),
				'limit'          => array('type' => 'number', 'default' => 100),
				'columns'        => array('type' => 'number', 'default' => 1),
				'excludeCurrent' => array('type' => 'boolean', 'default' => true),
				'showExcerpt'    => array('type' => 'boolean', 'default' => false),
				'className'      => array('type' => 'string', 'default' => ''),
			),
		));
	}

	/**
	 * Block render callback.
	 *
	 * @param array $attributes Block attributes.
	 * @return string
	 */
	public function render_block($attributes) {
		$attributes = wp_parse_args((array) $attributes, array('excludeCurrent' => true, 'showExcerpt' => false, 'className' => ''));

		return $this->render_list(array(
			'template'        => isset($attributes['template']) ? $attributes['template'] : 0,
			'generator'       => isset($attributes['generator']) ? $attributes['generator'] : 0,
			'orderby'         => isset($attributes['orderby']) ? $attributes['orderby'] : 'title',
			'order'           => isset($attributes['order']) ? $attributes['order'] : 'ASC',
			'limit'           => isset($attributes['limit']) ? $attributes['limit'] : 100,
			'columns'         => isset($attributes['columns']) ? $attributes['columns'] : 1,
			'exclude_current' => (bool) $attributes['excludeCurrent'],
			'show_excerpt'    => (bool) $attributes['showExcerpt'],
			'class'           => $attributes['className'],
		));
	}

	/**
	 * Render the list of published generated items.
	 *
	 * Without a template or generator, the current item decides: a generated
	 * item lists its siblings, and a template lists the items made from it.
	 *
	 * @param array $args List options.
	 * @return string
	 */
	public function render_list($args) {
		$args = wp_parse_args($args, $this->get_defaults());
		$current_id = (int) get_the_ID();

		$generator_id = absint($args['generator']);
		$template_id = absint($args['template']);
		if (!$generator_id && !$template_id && $current_id) {
			$template_id = (int) get_post_meta($current_id, '_bpd_template_id', true);
			if (!$template_id) {
				$template_id = $current_id;
			}
		}

		if ($generator_id) {
			$meta_key = '_bpd_generator_id';
			$meta_value = $generator_id;
			$config = get_post_meta($generator_id, '_bpd_generator_config', true);
			$post_type = is_array($config) && !empty($config['post_type']) ? $config['post_type'] : '';
		} elseif ($template_id) {
			$meta_key = '_bpd_template_id';
			$meta_value = $template_id;
			$post_type = get_post_type($template_id);
		} else {
			return '';
		}

		if (!$post_type || !post_type_exists($post_type)) {
			return '';
		}

		$orderby = in_array($args['orderby'], array('title', 'menu_order', 'date', 'rand'), true) ? $args['orderby'] : 'title';
		$order = 'DESC' === strtoupper((string) $args['order']) ? 'DESC' : 'ASC';
		$query_args = array(
			'post_type'              => $post_type,
			'post_status'            => 'publish',
			'posts_per_page'         => min(self::MAX_ITEMS, max(1, absint($args['limit']))),
			'meta_key'               => $meta_key, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_query_meta_key -- Required to find generated items.
			'meta_value'             => (string) $meta_value, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_query_meta_value -- Required to find generated items.
			'orderby'                => $orderby,
			'order'                  => $order,
			'no_found_rows'          => true,
			'ignore_sticky_posts'    => true,
			'update_post_term_cache' => false,
		);
		if ($args['exclude_current'] && $current_id) {
			$query_args['post__not_in'] = array($current_id); // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_post__not_in -- Single ID.
		}

		/**
		 * Filter the query used by the generated pages list.
		 *
		 * @param array $query_args WP_Query arguments.
		 * @param array $args       List options.
		 */
		$posts = get_posts(apply_filters('bpd_generated_pages_query_args', $query_args, $args));
		if (empty($posts)) {
			return '';
		}

		$columns = min(4, max(1, absint($args['columns'])));
		$classes = array('bpd-generated-pages', 'bpd-generated-pages-columns-' . $columns);
		if (!empty($args['class'])) {
			$classes = array_merge($classes, array_map('sanitize_html_class', preg_split('/\s+/', (string) $args['class'])));
		}
		$style = $columns > 1 ? sprintf(' style="columns: %d; column-gap: 2em;"', $columns) : '';

		$html = sprintf('<ul class="%1$s"%2$s>', esc_attr(implode(' ', array_filter($classes))), $style);
		foreach ($posts as $post) {
			$html .= '<li style="break-inside: avoid;">';
			$html .= sprintf('<a href="%1$s">%2$s</a>', esc_url(get_permalink($post)), esc_html(get_the_title($post)));
			if ($args['show_excerpt']) {
				$excerpt = has_excerpt($post) ? $post->post_excerpt : wp_trim_words(wp_strip_all_tags(strip_shortcodes($post->post_content)), 25);
				if ('' !== trim($excerpt)) {
					$html .= '<p class="bpd-generated-pages-excerpt">' . esc_html($excerpt) . '</p>';
				}
			}
			$html .= '</li>';
		}
		$html .= '</ul>';

		/**
		 * Filter the generated pages list HTML.
		 *
		 * @param string    $html  List HTML.
		 * @param WP_Post[] $posts Listed items.
		 * @param array     $args  List options.
		 */
		return apply_filters('bpd_generated_pages_html', $html, $posts, $args);
	}

	/**
	 * Interpret a shortcode flag such as "yes", "true", "1" or "no".
	 *
	 * @param mixed $value Raw value.
	 * @return bool
	 */
	private function to_bool($value) {
		if (is_bool($value)) {
			return $value;
		}
		return in_array(strtolower(trim((string) $value)), array('1', 'yes', 'true', 'on'), true);
	}
}
