/**
 * Adds "Save as Generator" to the one-off bulk duplication form.
 *
 * Reads the existing form fields directly so bulk-page-duplicator.js stays untouched.
 */
jQuery(function ($) {
	const __ = wp.i18n.__;
	const sprintf = wp.i18n.sprintf;
	const cfg = window.bpd_save_generator || {};
	const $dryRun = $("#dry-run");

	if (!$dryRun.length) {
		return;
	}

	// Link to the generators screen next to the page title.
	const $title = $(".wrap > h1").first();
	$title.addClass("wp-heading-inline");
	$("<a>", { href: cfg.list_url, class: "page-title-action", text: __("Page Generators", "bulk-page-duplicator") }).insertAfter($title);

	const $button = $("<button>", {
		type: "button",
		id: "bpd-save-as-generator",
		class: "button button-secondary button-large",
		text: __("Save as Generator", "bulk-page-duplicator")
	});
	$dryRun.after($button).after(" ");

	// Mirrors getReplaceOptions() in bulk-page-duplicator.js.
	function getReplaceOptions() {
		const options = [];
		if ($("#replace-title").is(":checked")) options.push("title");
		if ($("#replace-slug").is(":checked")) options.push("slug");
		if ($("#replace-content").is(":checked")) options.push("content");
		if ($("#copy-featured-image").is(":checked")) options.push("featured_image");
		if ($("#copy-template-terms").is(":checked")) options.push("template_terms");
		$("input[id^='replace-']:checked").each(function () {
			const id = $(this).attr("id").replace("replace-", "");
			if (options.indexOf(id) === -1 && ["title", "slug", "content", "seo"].indexOf(id) === -1) {
				options.push(id);
			}
		});
		if ($("#replace-seo").is(":checked")) options.push("seo");
		return options;
	}

	$button.on("click", function (e) {
		e.preventDefault();

		const templateId = $("#template-page").val();
		const placeholders = bpdCsv.parsePlaceholders($("#placeholder-text").val());
		const lines = bpdCsv.getLines($("#replacement-values").val());

		if (!templateId) {
			alert(__("Please select a template.", "bulk-page-duplicator"));
			return;
		}
		if (placeholders.length === 0) {
			alert(__("Please enter at least one placeholder.", "bulk-page-duplicator"));
			return;
		}

		const values = lines.map(line => bpdCsv.parseLine(line, placeholders.length));
		if (placeholders.length > 1 && values.some(v => v.length !== placeholders.length)) {
			alert(sprintf(
				__("Each line must have %1$d comma-separated values (one for each placeholder).\n\nPlaceholders: %2$s", "bulk-page-duplicator"),
				placeholders.length,
				placeholders.join(", ")
			));
			return;
		}

		const templateTitle = $("#template-title").text().replace(/\s*\(ID: \d+\)\s*$/, "");
		const name = window.prompt(__("Name this generator:", "bulk-page-duplicator"), templateTitle);
		if (name === null) {
			return;
		}

		const taxonomyTerms = {};
		$(".taxonomy-term:checked").each(function () {
			const taxonomy = $(this).data("taxonomy");
			taxonomyTerms[taxonomy] = taxonomyTerms[taxonomy] || [];
			taxonomyTerms[taxonomy].push(parseInt($(this).val(), 10));
		});

		$button.prop("disabled", true).text(__("Saving...", "bulk-page-duplicator"));

		$.ajax({
			url: cfg.ajax_url,
			type: "POST",
			data: {
				action: "bpd_save_generator_from_form",
				nonce: cfg.nonce,
				name: name,
				post_type: $("#post-type").val(),
				template_id: templateId,
				placeholders: placeholders,
				values: values,
				status: $("#page-status").val(),
				parent_page: $("#parent-page").val() || "0",
				replace_options: getReplaceOptions(),
				taxonomy_terms: taxonomyTerms
			}
		}).done(function (response) {
			if (response.success) {
				window.location.href = response.data.edit_url;
				return;
			}
			alert(sprintf(__("Error: %s", "bulk-page-duplicator"), String(response.data || "")));
			$button.prop("disabled", false).text(__("Save as Generator", "bulk-page-duplicator"));
		}).fail(function () {
			alert(__("An error occurred. Please try again.", "bulk-page-duplicator"));
			$button.prop("disabled", false).text(__("Save as Generator", "bulk-page-duplicator"));
		});
	});
});
