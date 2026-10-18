jQuery(function ($) {
	const __ = wp.i18n.__;
	const _n = wp.i18n._n;
	const sprintf = wp.i18n.sprintf;
	const cfg = window.bpd_generators || {};

	// List screen: confirm deletion.
	$(document).on("click", ".bpd-delete-generator", function (e) {
		if (!window.confirm($(this).data("confirm"))) {
			e.preventDefault();
		}
	});

	const $form = $("#bpd-generator-form");
	if (!$form.length) {
		return;
	}

	const $postType = $("#bpd-gen-post-type");
	const $template = $("#bpd-gen-template");
	const $templateSearch = $("#bpd-gen-template-search");
	const $parent = $("#bpd-gen-parent");
	const $rows = $("#bpd-gen-rows");
	let savedTerms = cfg.taxonomy_terms || {};
	let templateSearchTimer = null;
	let templateRequest = null;
	let isRunning = false;

	function post(data) {
		return $.ajax({ url: cfg.ajax_url, type: "POST", data: data });
	}

	function getPlaceholders() {
		return String($("#bpd-gen-placeholders").val() || "")
			.split(",")
			.map(p => p.trim())
			.filter(p => p !== "");
	}

	// === Template, parent and taxonomy pickers (reuse the bulk form endpoints) ===

	function loadTemplates(search) {
		if (templateRequest) {
			templateRequest.abort();
		}

		const selected = String($template.val() || "");
		templateRequest = post({
			action: "bpd_get_posts_by_type",
			nonce: cfg.bpd_nonce,
			post_type: $postType.val(),
			search: search || "",
			per_page: 100
		}).done(function (response) {
			if (!response.success) {
				return;
			}

			const $selectedOption = selected ? $template.find('option[value="' + selected + '"]').first().clone() : null;
			$template.find("option").not('[value=""]').remove();
			if ($selectedOption && $selectedOption.length) {
				$template.append($selectedOption);
			}

			response.data.posts.forEach(function (item) {
				if (String(item.id) === selected) {
					return;
				}
				$template.append($("<option>", { value: item.id, text: item.display_title }));
			});
			$template.val(selected);
		}).always(function () {
			templateRequest = null;
		});
	}

	function loadParents(page, reset) {
		const selected = String($parent.val() || cfg.parent_page || "0");
		if (reset) {
			$parent.find("option").not('[value="0"], [value="template"]').filter(function () {
				return $(this).val() !== selected;
			}).remove();
		}

		post({
			action: "bpd_get_posts_by_type",
			nonce: cfg.bpd_nonce,
			post_type: $postType.val(),
			paged: page,
			per_page: 100
		}).done(function (response) {
			if (!response.success) {
				return;
			}

			$("#bpd-gen-parent-row").toggle(!!response.data.is_hierarchical);
			if (!response.data.is_hierarchical) {
				return;
			}

			response.data.posts.forEach(function (item) {
				if ($parent.find('option[value="' + item.id + '"]').length === 0) {
					$parent.append($("<option>", { value: item.id, text: item.title }));
				}
			});

			if (response.data.has_more) {
				loadParents(page + 1, false);
			}
		});
	}

	function loadTaxonomies(page, $target) {
		const $loading = $("#bpd-gen-taxonomy-loading");
		$loading.show();

		post({
			action: "bpd_get_taxonomies",
			nonce: cfg.bpd_nonce,
			post_type: $postType.val(),
			paged: page,
			per_page: 100
		}).done(function (response) {
			if (!response.success) {
				$loading.hide();
				return;
			}

			response.data.taxonomies.forEach(function (taxonomy) {
				let $group = $target.find('.bpd-gen-taxonomy[data-taxonomy="' + taxonomy.name + '"]');
				if ($group.length === 0) {
					$group = $("<fieldset>", { class: "bpd-gen-taxonomy" }).attr("data-taxonomy", taxonomy.name);
					$group.append($("<legend>").text(taxonomy.label));
					$target.append($group);
				}

				const selected = (savedTerms[taxonomy.name] || []).map(String);
				taxonomy.terms.forEach(function (term) {
					const $label = $("<label>");
					$label.append($("<input>", {
						type: "checkbox",
						name: "options[taxonomy_terms][" + taxonomy.name + "][]",
						value: term.id,
						checked: selected.indexOf(String(term.id)) !== -1
					}));
					$label.append(document.createTextNode(" " + term.name));
					$group.append($label);
				});
			});

			if (response.data.has_more) {
				loadTaxonomies(page + 1, $target);
				return;
			}

			$loading.hide();
			// Swap in the loaded checkboxes only once complete, so saved terms are never dropped mid-load.
			$("#bpd-gen-taxonomies").replaceWith($target.attr("id", "bpd-gen-taxonomies"));
			$("#bpd-gen-taxonomy-row").toggle($target.children().length > 0);
		}).fail(function () {
			$loading.hide();
		});
	}

	function loadPickers() {
		loadTemplates("");
		loadParents(1, true);
		loadTaxonomies(1, $("<div>"));
	}

	$postType.on("change", function () {
		$template.find("option").not('[value=""]').remove();
		$template.val("");
		$parent.find("option").not('[value="0"], [value="template"]').remove();
		$parent.val("0");
		savedTerms = {};
		$("#bpd-gen-taxonomies").empty();
		loadPickers();
	});

	$templateSearch.on("input", function () {
		const query = $(this).val();
		clearTimeout(templateSearchTimer);
		templateSearchTimer = setTimeout(function () {
			loadTemplates(query);
		}, 250);
	});

	loadPickers();

	// === Rows ===

	function renumberRows() {
		$rows.find("tr").each(function (index) {
			$(this).find(".bpd-gen-col-index").first().text(index + 1);
		});
		$("#bpd-gen-no-rows").toggle($rows.find("tr").length === 0);
	}

	$("#bpd-gen-add-row").on("click", function () {
		const index = $rows.find("tr").length;
		const $row = $("<tr>").attr("data-key", "").attr("data-has-item", "0");
		$row.append($("<td>", { class: "bpd-gen-col-index" }));
		const $valuesCell = $("<td>");
		$valuesCell.append($("<input>", { type: "hidden", class: "bpd-gen-row-key", name: "rows[n" + index + "][key]", value: "" }));
		$valuesCell.append($("<input>", {
			type: "text",
			class: "widefat bpd-gen-row-values",
			name: "rows[n" + index + "][values]",
			"aria-label": __("Row values", "bulk-page-duplicator")
		}));
		$row.append($valuesCell);
		$row.append($("<td>", { class: "bpd-gen-item-cell" }).append(
			$("<span>", { class: "bpd-gen-state bpd-gen-state-missing", text: __("Not generated yet", "bulk-page-duplicator") })
		));
		$row.append($("<td>", { class: "bpd-gen-col-actions" }).append(
			$("<button>", { type: "button", class: "button-link bpd-gen-remove-row", text: __("Remove", "bulk-page-duplicator") })
		));
		$rows.append($row);
		renumberRows();
		$row.find(".bpd-gen-row-values").trigger("focus");
	});

	$rows.on("click", ".bpd-gen-remove-row", function () {
		const $row = $(this).closest("tr");
		if ($row.attr("data-has-item") !== "1") {
			$row.remove();
			renumberRows();
			return;
		}

		// Rows with an item are only marked; the item is trashed when the form is saved.
		const removed = !$row.hasClass("bpd-gen-row-removed");
		$row.toggleClass("bpd-gen-row-removed", removed);
		$row.find(".bpd-gen-row-values").prop("readonly", removed);
		$(this).text(removed ? __("Undo", "bulk-page-duplicator") : __("Remove", "bulk-page-duplicator"));
	});

	$("#bpd-gen-csv").on("change", function () {
		const file = this.files && this.files[0];
		const input = this;
		if (!file) {
			return;
		}

		const reader = new FileReader();
		reader.onload = function (e) {
			const lines = String(e.target.result || "").split(/\r?\n/).filter(line => line.trim() !== "");
			const $bulk = $("#bpd-gen-bulk-rows");
			const current = $bulk.val().trim();
			$bulk.val((current ? current + "\n" : "") + lines.join("\n"));
			input.value = "";
		};
		reader.readAsText(file);
	});

	// === Save ===

	$form.on("click", ".bpd-gen-submit", function () {
		$("#bpd-gen-submit-action").val($(this).data("submit"));
	});

	$form.on("submit", function (e) {
		if (isRunning) {
			e.preventDefault();
			return;
		}

		const placeholders = getPlaceholders();
		if (!$template.val()) {
			e.preventDefault();
			alert(__("Please select a template.", "bulk-page-duplicator"));
			return;
		}
		if (placeholders.length === 0) {
			e.preventDefault();
			alert(__("Please enter at least one placeholder.", "bulk-page-duplicator"));
			return;
		}

		const rows = [];
		let invalidRow = 0;
		$rows.find("tr").not(".bpd-gen-row-removed").each(function (index) {
			const values = String($(this).find(".bpd-gen-row-values").val() || "");
			if (values.trim() === "") {
				return;
			}
			if (placeholders.length > 1 && values.split(",").length !== placeholders.length && !invalidRow) {
				invalidRow = index + 1;
			}
			rows.push({ key: String($(this).find(".bpd-gen-row-key").val() || ""), values: values });
		});

		if (placeholders.length > 1) {
			const badBulkLine = $("#bpd-gen-bulk-rows").val().split(/\r?\n/).filter(line => line.trim() !== "")
				.some(line => line.split(",").length !== placeholders.length);
			if (invalidRow || badBulkLine) {
				e.preventDefault();
				alert(sprintf(
					__("Each row must have %1$d comma-separated values (one for each placeholder).\n\nPlaceholders: %2$s", "bulk-page-duplicator"),
					placeholders.length,
					placeholders.join(", ")
				));
				return;
			}
		}

		const removedWithItems = $rows.find("tr.bpd-gen-row-removed[data-has-item='1']").length;
		if (removedWithItems > 0) {
			const ok = window.confirm(sprintf(
				_n(
					"%d removed row has a generated item. It will be moved to the trash. Continue?",
					"%d removed rows have generated items. They will be moved to the trash. Continue?",
					removedWithItems,
					"bulk-page-duplicator"
				),
				removedWithItems
			));
			if (!ok) {
				e.preventDefault();
				return;
			}
			$("#bpd-gen-confirm-trash").val("1");
		}

		// Send rows as one JSON field so large datasets are not cut off by max_input_vars.
		$("#bpd-gen-rows-json").val(JSON.stringify(rows));
		$rows.find("input").prop("disabled", true);
	});

	// === Run ===

	function createOperationId() {
		if (window.crypto && typeof window.crypto.randomUUID === "function") {
			return window.crypto.randomUUID();
		}
		return Date.now().toString(36) + "-" + Math.random().toString(36).slice(2);
	}

	function setRunning(running) {
		isRunning = running;
		$(".bpd-gen-submit").prop("disabled", running);
	}

	function logResult(result) {
		const allowed = ["success", "updated", "linked", "unchanged", "skipped", "error"];
		const status = allowed.indexOf(result.status) !== -1 ? result.status : "error";
		const $entry = $("<div>", { class: "bpd-gen-log-entry bpd-gen-log-" + status });
		$entry.text(String(result.value || "") + ": " + String(result.message || ""));
		$(".bpd-gen-log").prepend($entry);
	}

	function updateProgress(done, total) {
		const percent = total ? Math.round((done / total) * 100) : 100;
		$(".bpd-gen-progress-inner").css("width", percent + "%");
		$(".bpd-gen-run-status").text(sprintf(__("%1$d%% (%2$d of %3$d)", "bulk-page-duplicator"), percent, done, total));
	}

	function runBatches(keys, allowUpdates, operationId, start, counts) {
		const batchSize = 10;
		const batch = keys.slice(start, start + batchSize);
		const end = start + batch.length;

		post({
			action: "bpd_generator_run",
			nonce: cfg.nonce,
			generator_id: cfg.generator_id,
			row_keys: batch,
			operation_id: operationId,
			batch_index: start,
			operation_complete: end >= keys.length ? "true" : "false",
			allow_updates: allowUpdates ? "true" : "false"
		}).done(function (response) {
			if (!response.success) {
				finishRun(sprintf(__("Error: %s", "bulk-page-duplicator"), String(response.data || "")));
				return;
			}

			response.data.results.forEach(function (result) {
				logResult(result);
				counts[result.status] = (counts[result.status] || 0) + 1;
				if (result.row_key && typeof result.cell_html === "string") {
					// Row keys are sanitized server-side to [a-z0-9_-]; the cell HTML is escaped server-side.
					const $row = $rows.find('tr[data-key="' + result.row_key + '"]');
					$row.find(".bpd-gen-item-cell").html(result.cell_html);
					$row.attr("data-has-item", result.has_item ? "1" : "0");
				}
			});

			updateProgress(end, keys.length);

			if (end < keys.length) {
				runBatches(keys, allowUpdates, operationId, end, counts);
				return;
			}

			finishRun(sprintf(
				__("Done: %1$d created, %2$d updated, %3$d linked, %4$d skipped, %5$d errors.", "bulk-page-duplicator"),
				counts.success || 0,
				counts.updated || 0,
				counts.linked || 0,
				(counts.skipped || 0) + (counts.unchanged || 0),
				counts.error || 0
			));
		}).fail(function (xhr, status, error) {
			finishRun(sprintf(__("AJAX Error: %s", "bulk-page-duplicator"), String(error || status || "")));
		});
	}

	function finishRun(message) {
		setRunning(false);
		$(".bpd-gen-run-status").text(message);
	}

	function startRun() {
		if (!cfg.generator_id || isRunning) {
			return;
		}

		setRunning(true);
		$("#bpd-gen-run").show();
		$(".bpd-gen-log").empty();
		$(".bpd-gen-progress-inner").css("width", "0%");
		$(".bpd-gen-run-status").text(__("Checking rows...", "bulk-page-duplicator"));

		post({
			action: "bpd_generator_plan",
			nonce: cfg.nonce,
			generator_id: cfg.generator_id
		}).done(function (response) {
			if (!response.success) {
				finishRun(sprintf(__("Error: %s", "bulk-page-duplicator"), String(response.data || "")));
				return;
			}

			const plan = response.data;
			let allowUpdates = false;
			if (plan.update.length > 0) {
				allowUpdates = window.confirm(sprintf(
					_n(
						"%d existing item will be re-rendered from the template with its new values, overwriting manual edits to it. Press OK to update it, or Cancel to only create missing items.",
						"%d existing items will be re-rendered from the template with their new values, overwriting manual edits to them. Press OK to update them, or Cancel to only create missing items.",
						plan.update.length,
						"bulk-page-duplicator"
					),
					plan.update.length
				));
			}

			const keys = plan.create.concat(allowUpdates ? plan.update : []);
			if (keys.length === 0) {
				let message = __("Nothing to do: every row is up to date.", "bulk-page-duplicator");
				if (plan.trashed > 0) {
					message += " " + sprintf(_n("%d row's item is in the trash.", "%d rows' items are in the trash.", plan.trashed, "bulk-page-duplicator"), plan.trashed);
				}
				finishRun(message);
				return;
			}

			updateProgress(0, keys.length);
			runBatches(keys, allowUpdates, createOperationId(), 0, {});
		}).fail(function () {
			finishRun(__("An error occurred. Please try again.", "bulk-page-duplicator"));
		});
	}

	$(window).on("beforeunload", function () {
		if (isRunning) {
			return __("Generation is still running.", "bulk-page-duplicator");
		}
	});

	if (cfg.autorun && window.history && typeof window.history.replaceState === "function") {
		// Drop the one-time run flag so reloading the page does not start another run.
		const url = new URL(window.location.href);
		url.searchParams.delete("bpd_run");
		window.history.replaceState(null, "", url.toString());
	}

	if (cfg.autorun) {
		startRun();
	}
});
