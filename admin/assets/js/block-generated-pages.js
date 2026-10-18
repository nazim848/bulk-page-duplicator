(function (wp) {
	if (!wp || !wp.blocks || !wp.element) {
		return;
	}

	const el = wp.element.createElement;
	const __ = wp.i18n.__;
	const components = wp.components;
	const editor = wp.blockEditor || wp.editor;
	const ServerSideRender = wp.serverSideRender || components.ServerSideRender;
	// Block API v3 (iframe editor) needs useBlockProps, available since WordPress 5.6.
	const useBlockProps = editor && editor.useBlockProps;

	wp.blocks.registerBlockType("bulk-page-duplicator/generated-pages", {
		apiVersion: useBlockProps ? 3 : 1,
		title: __("Generated Pages List", "bulk-page-duplicator"),
		description: __("Lists the published pages generated from a template or page generator, for example an \"Areas we serve\" hub.", "bulk-page-duplicator"),
		icon: "list-view",
		category: "widgets",
		keywords: [__("bulk", "bulk-page-duplicator"), __("locations", "bulk-page-duplicator"), __("generator", "bulk-page-duplicator")],
		supports: { html: false },
		edit: function (props) {
			const attributes = props.attributes;
			const set = function (key) {
				return function (value) {
					const update = {};
					update[key] = value;
					props.setAttributes(update);
				};
			};

			return el(
				"div",
				useBlockProps ? useBlockProps() : {},
				el(
					editor.InspectorControls,
					null,
					el(
						components.PanelBody,
						{ title: __("Source", "bulk-page-duplicator") },
						el(components.TextControl, {
							label: __("Template ID", "bulk-page-duplicator"),
							help: __("Leave both IDs at 0 to use the current page: a generated page lists its siblings, a template lists its generated pages.", "bulk-page-duplicator"),
							type: "number",
							value: attributes.template,
							onChange: function (value) { set("template")(parseInt(value, 10) || 0); }
						}),
						el(components.TextControl, {
							label: __("Generator ID", "bulk-page-duplicator"),
							type: "number",
							value: attributes.generator,
							onChange: function (value) { set("generator")(parseInt(value, 10) || 0); }
						})
					),
					el(
						components.PanelBody,
						{ title: __("Display", "bulk-page-duplicator") },
						el(components.SelectControl, {
							label: __("Order by", "bulk-page-duplicator"),
							value: attributes.orderby,
							options: [
								{ label: __("Title", "bulk-page-duplicator"), value: "title" },
								{ label: __("Menu order", "bulk-page-duplicator"), value: "menu_order" },
								{ label: __("Date", "bulk-page-duplicator"), value: "date" },
								{ label: __("Random", "bulk-page-duplicator"), value: "rand" }
							],
							onChange: set("orderby")
						}),
						el(components.SelectControl, {
							label: __("Order", "bulk-page-duplicator"),
							value: attributes.order,
							options: [
								{ label: __("Ascending", "bulk-page-duplicator"), value: "ASC" },
								{ label: __("Descending", "bulk-page-duplicator"), value: "DESC" }
							],
							onChange: set("order")
						}),
						el(components.RangeControl, {
							label: __("Columns", "bulk-page-duplicator"),
							value: attributes.columns,
							min: 1,
							max: 4,
							onChange: set("columns")
						}),
						el(components.RangeControl, {
							label: __("Maximum items", "bulk-page-duplicator"),
							value: attributes.limit,
							min: 1,
							max: 500,
							onChange: set("limit")
						}),
						el(components.ToggleControl, {
							label: __("Hide the current page", "bulk-page-duplicator"),
							checked: attributes.excludeCurrent,
							onChange: set("excludeCurrent")
						}),
						el(components.ToggleControl, {
							label: __("Show excerpts", "bulk-page-duplicator"),
							checked: attributes.showExcerpt,
							onChange: set("showExcerpt")
						})
					)
				),
				el(ServerSideRender, {
					block: "bulk-page-duplicator/generated-pages",
					attributes: attributes,
					EmptyResponsePlaceholder: function () {
						return el("p", { className: "components-placeholder" }, __("No published generated pages found for this source yet.", "bulk-page-duplicator"));
					}
				})
			);
		},
		save: function () {
			return null;
		}
	});
})(window.wp);
