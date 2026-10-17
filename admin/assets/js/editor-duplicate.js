(function (wp, settings) {
	if (!wp || !wp.plugins || !settings || !settings.url) {
		return;
	}

	const el = wp.element.createElement;
	const __ = wp.i18n.__;
	const PluginPostStatusInfo = (wp.editor && wp.editor.PluginPostStatusInfo) || (wp.editPost && wp.editPost.PluginPostStatusInfo);

	if (!PluginPostStatusInfo) {
		return;
	}

	wp.plugins.registerPlugin("bulk-page-duplicator-duplicate", {
		render: function () {
			return el(
				PluginPostStatusInfo,
				{ className: "bpd-editor-duplicate" },
				el(
					wp.components.Button,
					{ variant: "secondary", isSecondary: true, href: settings.url },
					__("Duplicate as new draft", "bulk-page-duplicator")
				)
			);
		}
	});
})(window.wp, window.bpdEditorDuplicate);
