/* global jQuery, wp, bpd_sync */
jQuery(document).ready(function ($) {
	const { __, _n, sprintf } = wp.i18n;

	if (typeof bpd_sync === "undefined") {
		return;
	}

	let previewItems = [];
	let previewFields = null;
	let isProcessing = false;
	let cancelRequested = false;
	let operationId = "";

	const $preview = $("#bpd-sync-preview");
	const $apply = $("#bpd-sync-apply");
	const $cancel = $("#bpd-sync-cancel");
	const $progress = $("#bpd-sync-progress");
	const $tableBody = $("#bpd-sync-table-body");
	const $includeEdited = $("#bpd-sync-include-edited");

	function createOperationId() {
		if (window.crypto && typeof window.crypto.randomUUID === "function") {
			return window.crypto.randomUUID().replace(/-/g, "");
		}
		return Date.now().toString(36) + Math.random().toString(36).slice(2);
	}

	function getSelectedFields() {
		return $(".bpd-sync-field:checked").map(function () {
			return $(this).val();
		}).get();
	}

	function hasRiskyFields(fields) {
		return fields.some(function (field) {
			return bpd_sync.risky_fields.indexOf(field) !== -1;
		});
	}

	function setProgress(done, total, status) {
		const percent = total > 0 ? Math.round((done / total) * 100) : 100;
		$progress.show();
		$progress.find(".bulk-page-dup-progress-bar-inner").css("width", percent + "%");
		$progress.find(".bulk-page-dup-progress-text").text(
			/* translators: 1: percent done, 2: items done, 3: total items. */
			sprintf(__("%1$d%% (%2$d of %3$d)", "bulk-page-duplicator"), percent, done, total)
		);
		if (status) {
			$progress.find(".bulk-page-dup-status-text").text(status);
		}
	}

	function setBusy(busy) {
		isProcessing = busy;
		cancelRequested = false;
		$preview.prop("disabled", busy);
		$(".bpd-sync-field, #bpd-sync-include-edited, .bpd-sync-override").prop("disabled", busy);
		$cancel.toggle(busy).text(__("Cancel", "bulk-page-duplicator"));
		updateApplyButton();
	}

	function isOverridden(item) {
		return $includeEdited.is(":checked") || item.override === true;
	}

	function getGroup(item) {
		if (item.state === "error") {
			return "error";
		}
		if (!item.will_change) {
			return "same";
		}
		if (item.state !== "clean" && !isOverridden(item)) {
			return "edited";
		}
		return "change";
	}

	function getStateLabel(state) {
		switch (state) {
			case "clean":
				return __("Not edited", "bulk-page-duplicator");
			case "edited":
				return __("Edited by hand", "bulk-page-duplicator");
			case "unknown":
				return __("Possibly edited", "bulk-page-duplicator");
			default:
				return __("Cannot sync", "bulk-page-duplicator");
		}
	}

	function updateCounts() {
		const counts = { change: 0, same: 0, edited: 0, error: 0 };
		previewItems.forEach(function (item) {
			counts[getGroup(item)]++;
		});
		$("#bpd-sync-count-change").text(counts.change);
		$("#bpd-sync-count-same").text(counts.same);
		$("#bpd-sync-count-edited").text(counts.edited);
		$("#bpd-sync-count-error").text(counts.error);

		$tableBody.children("tr").each(function () {
			const item = previewItems[$(this).data("index")];
			if (item) {
				$(this).attr("data-group", getGroup(item));
			}
		});
		applyFilter();
		updateApplyButton();
		return counts;
	}

	function updateApplyButton() {
		const count = previewItems.filter(function (item) {
			return getGroup(item) === "change";
		}).length;
		$apply.prop("disabled", isProcessing || previewFields === null || count === 0);
		$apply.text(count > 0
			? sprintf(_n("Sync %d item", "Sync %d items", count, "bulk-page-duplicator"), count)
			: __("Sync changes", "bulk-page-duplicator"));
	}

	function applyFilter() {
		const filter = $(".bpd-sync-filter.active").data("filter") || "all";
		$tableBody.children("tr").each(function () {
			const group = $(this).attr("data-group");
			const visible = filter === "all" || group === filter;
			$(this).toggle(visible);
		});
	}

	function renderRow(item, index) {
		const $row = $("<tr>").attr("data-index", index).attr("data-id", item.id);

		const $itemCell = $("<td>");
		if (item.edit_url) {
			$itemCell.append($("<a>", {
				href: item.edit_url,
				target: "_blank",
				rel: "noopener noreferrer",
				text: item.title
			}));
		} else {
			$itemCell.text(item.title);
		}
		$itemCell.append($("<span>", { class: "bpd-sync-id", text: " #" + item.id }));
		if (item.generator) {
			$itemCell.append($("<div>", {
				class: "bpd-sync-generator",
				/* translators: %s: generator name. */
				text: sprintf(__("Generator: %s", "bulk-page-duplicator"), item.generator)
			}));
		}
		$row.append($itemCell);

		const stateClass = item.state === "clean" ? "bpd-status-create" : "bpd-status-skip";
		$row.append($("<td>").append($("<span>", {
			class: "bpd-status-badge " + stateClass + " bpd-sync-state-" + item.state,
			text: getStateLabel(item.state)
		})));

		let changesText;
		if (item.state === "error") {
			changesText = item.error;
		} else if (item.will_change) {
			changesText = item.changes.join(", ");
		} else {
			changesText = __("No changes", "bulk-page-duplicator");
		}
		$row.append($("<td>", { class: "bpd-sync-changes", text: changesText }));

		const $overrideCell = $("<td>");
		if (item.will_change && item.state !== "clean" && item.state !== "error") {
			const $label = $("<label>");
			$label.append($("<input>", {
				type: "checkbox",
				class: "bpd-sync-override"
			}).attr("data-index", index));
			$label.append($("<span>", { class: "screen-reader-text", text: sprintf(__("Overwrite %s", "bulk-page-duplicator"), item.title) }));
			$overrideCell.append($label);
		}
		$row.append($overrideCell);

		return $row;
	}

	function resetPreview() {
		previewItems = [];
		previewFields = null;
		$tableBody.empty();
		$("#bpd-sync-preview-panel").hide();
		$includeEdited.prop("checked", false);
		updateApplyButton();
	}

	function showError(message) {
		$progress.show().find(".bulk-page-dup-status-text").text(sprintf(__("Error: %s", "bulk-page-duplicator"), String(message || "")));
	}

	// Ask before enabling title or slug sync.
	$(".bpd-sync-risky-field").on("change", function () {
		if (!$(this).is(":checked")) {
			return;
		}
		const message = $(this).val() === "slug"
			? __("Syncing slugs changes the URLs of the generated items. Links to the old addresses may break. Continue?", "bulk-page-duplicator")
			: __("Syncing titles overwrites the titles of the generated items, including any you changed by hand. Continue?", "bulk-page-duplicator");
		if (!window.confirm(message)) {
			$(this).prop("checked", false);
		}
	});

	// A preview only applies to the fields it was made with.
	$(".bpd-sync-field").on("change", function () {
		if (previewFields !== null) {
			resetPreview();
			$progress.hide();
		}
	});

	$includeEdited.on("change", function () {
		$(".bpd-sync-override").prop("checked", $(this).is(":checked"));
		previewItems.forEach(function (item) {
			item.override = $includeEdited.is(":checked");
		});
		updateCounts();
	});

	$(document).on("change", ".bpd-sync-override", function () {
		const item = previewItems[$(this).data("index")];
		if (item) {
			item.override = $(this).is(":checked");
		}
		if (!$(this).is(":checked")) {
			$includeEdited.prop("checked", false);
		}
		updateCounts();
	});

	$(document).on("click", ".bpd-sync-filter", function () {
		$(".bpd-sync-filter").removeClass("active");
		$(this).addClass("active");
		applyFilter();
	});

	$cancel.on("click", function () {
		cancelRequested = true;
		$(this).text(__("Cancelling...", "bulk-page-duplicator"));
	});

	$(window).on("beforeunload", function () {
		if (isProcessing) {
			return __("A sync is in progress.", "bulk-page-duplicator");
		}
	});

	// Preview: page through every generated item.
	$preview.on("click", function () {
		const fields = getSelectedFields();
		if (fields.length === 0) {
			window.alert(__("Select at least one thing to sync.", "bulk-page-duplicator"));
			return;
		}

		resetPreview();
		$("#bpd-sync-log-container").hide();
		$("#bpd-sync-preview-panel").show();
		setBusy(true);
		setProgress(0, parseInt($("#bpd-sync-count").data("count"), 10) || 0, __("Building preview...", "bulk-page-duplicator"));
		loadPreviewPage(fields, 1);
	});

	function loadPreviewPage(fields, page) {
		if (cancelRequested) {
			setBusy(false);
			resetPreview();
			$progress.find(".bulk-page-dup-status-text").text(__("Preview cancelled.", "bulk-page-duplicator"));
			return;
		}

		$.ajax({
			url: bpd_sync.ajax_url,
			type: "POST",
			data: {
				action: "bpd_sync_preview",
				nonce: bpd_sync.nonce,
				template_id: bpd_sync.template_id,
				fields: fields,
				confirm_risky: hasRiskyFields(fields) ? "true" : "false",
				paged: page
			},
			success: function (response) {
				if (!response.success) {
					setBusy(false);
					resetPreview();
					showError(response.data);
					return;
				}

				response.data.items.forEach(function (item) {
					item.override = false;
					const index = previewItems.length;
					previewItems.push(item);
					$tableBody.append(renderRow(item, index));
				});
				updateCounts();
				setProgress(previewItems.length, response.data.total, __("Building preview...", "bulk-page-duplicator"));

				if (response.data.has_more) {
					loadPreviewPage(fields, page + 1);
					return;
				}

				previewFields = fields;
				setBusy(false);
				const counts = updateCounts();
				$progress.find(".bulk-page-dup-status-text").text(
					/* translators: 1: items that will be updated, 2: items edited by hand. */
					sprintf(__("Preview ready: %1$d will be updated, %2$d edited by hand.", "bulk-page-duplicator"), counts.change, counts.edited)
				);
			},
			error: function (xhr, status, error) {
				setBusy(false);
				resetPreview();
				showError(error);
			}
		});
	}

	// Apply: sync items in batches.
	$apply.on("click", function () {
		if (previewFields === null) {
			return;
		}

		const queue = previewItems.filter(function (item) {
			return item.will_change && item.state !== "error";
		});
		const toUpdate = queue.filter(function (item) {
			return getGroup(item) === "change";
		});
		const overwriting = toUpdate.filter(function (item) {
			return item.state !== "clean";
		}).length;

		let message = sprintf(
			_n("Sync %d item from the template? A revision is saved first.", "Sync %d items from the template? A revision of each is saved first.", toUpdate.length, "bulk-page-duplicator"),
			toUpdate.length
		);
		if (overwriting > 0) {
			message += "\n\n" + sprintf(
				_n("%d of them was edited by hand and its changes will be overwritten.", "%d of them were edited by hand and their changes will be overwritten.", overwriting, "bulk-page-duplicator"),
				overwriting
			);
		}
		if (!window.confirm(message)) {
			return;
		}

		operationId = createOperationId();
		$("#bpd-sync-log").empty();
		$("#bpd-sync-result-updated, #bpd-sync-result-skipped, #bpd-sync-result-unchanged, #bpd-sync-result-error").text("0");
		$("#bpd-sync-log-container").show();
		setBusy(true);
		setProgress(0, queue.length, __("Syncing items...", "bulk-page-duplicator"));
		applyBatch(queue, 0, previewFields, { updated: 0, unchanged: 0, skipped: 0, error: 0 });
	});

	function logResult(result) {
		const statusClass = {
			updated: "success",
			unchanged: "skipped",
			skipped: "skipped",
			error: "error"
		}[result.status] || "error";
		const $entry = $("<div>", { class: "bulk-page-dup-log-entry bulk-page-dup-log-" + statusClass });
		$entry.text(String(result.title || "") + " (#" + result.id + "): " + String(result.message || ""));
		if (result.edit_url) {
			$entry.append(document.createTextNode(" ("));
			$entry.append($("<a>", {
				href: result.edit_url,
				target: "_blank",
				rel: "noopener noreferrer",
				text: __("Edit", "bulk-page-duplicator")
			}));
			$entry.append(document.createTextNode(")"));
		}
		$("#bpd-sync-log").prepend($entry);
	}

	function applyBatch(queue, start, fields, totals) {
		if (cancelRequested || start >= queue.length) {
			finishApply(queue, start, totals);
			return;
		}

		const batch = queue.slice(start, start + (parseInt(bpd_sync.apply_batch, 10) || 10));
		const overrideIds = batch.filter(function (item) {
			return item.override === true;
		}).map(function (item) {
			return item.id;
		});

		$.ajax({
			url: bpd_sync.ajax_url,
			type: "POST",
			data: {
				action: "bpd_sync_apply",
				nonce: bpd_sync.nonce,
				template_id: bpd_sync.template_id,
				fields: fields,
				confirm_risky: hasRiskyFields(fields) ? "true" : "false",
				post_ids: batch.map(function (item) { return item.id; }),
				override_ids: overrideIds,
				include_edited: $includeEdited.is(":checked") ? "true" : "false",
				operation_id: operationId
			},
			success: function (response) {
				if (!response.success) {
					showError(response.data);
					finishApply(queue, start, totals);
					return;
				}

				response.data.results.forEach(function (result) {
					if (Object.prototype.hasOwnProperty.call(totals, result.status)) {
						totals[result.status]++;
					}
					logResult(result);
				});
				$("#bpd-sync-result-updated").text(totals.updated);
				$("#bpd-sync-result-skipped").text(totals.skipped);
				$("#bpd-sync-result-unchanged").text(totals.unchanged);
				$("#bpd-sync-result-error").text(totals.error);

				const done = start + batch.length;
				setProgress(done, queue.length, __("Syncing items...", "bulk-page-duplicator"));
				applyBatch(queue, done, fields, totals);
			},
			error: function (xhr, status, error) {
				showError(error);
				finishApply(queue, start, totals);
			}
		});
	}

	function finishApply(queue, processed, totals) {
		const cancelled = cancelRequested;
		setBusy(false);
		// Items have changed: a new preview is needed before syncing again.
		previewFields = null;
		updateApplyButton();
		$tableBody.find(".bpd-sync-override").prop("disabled", true);

		/* translators: 1: updated, 2: skipped, 3: up to date, 4: errors. */
		const summary = sprintf(
			__("%1$d updated, %2$d skipped, %3$d up to date, %4$d errors.", "bulk-page-duplicator"),
			totals.updated,
			totals.skipped,
			totals.unchanged,
			totals.error
		);
		$progress.find(".bulk-page-dup-status-text").text(
			cancelled
				/* translators: 1: items processed, 2: total items, 3: result summary. */
				? sprintf(__("Sync cancelled after %1$d of %2$d items. %3$s", "bulk-page-duplicator"), processed, queue.length, summary)
				: sprintf(__("Sync finished. %s Preview again to check the result.", "bulk-page-duplicator"), summary)
		);
		loadHistory();
	}

	function loadHistory() {
		const $list = $("#bpd-sync-history");
		const $empty = $("#bpd-sync-history-empty");

		$.ajax({
			url: bpd_sync.ajax_url,
			type: "POST",
			data: {
				action: "bpd_sync_history",
				nonce: bpd_sync.nonce,
				template_id: bpd_sync.template_id
			},
			success: function (response) {
				$list.empty();
				if (!response.success || response.data.history.length === 0) {
					$empty.show();
					return;
				}
				$empty.hide();
				response.data.history.forEach(function (entry) {
					const $item = $("<div>", { class: "bulk-page-dup-history-item" });
					const $info = $("<div>", { class: "history-item-info" });
					$info.append($("<div>", {
						class: "history-item-title",
						text: sprintf(__("Synced: %s", "bulk-page-duplicator"), entry.fields)
					}));
					const $meta = $("<div>", { class: "history-item-meta" });
					$meta.append($("<span>", { text: entry.date }));
					$meta.append($("<span>", { text: entry.user }));
					$info.append($meta);
					$item.append($("<div>", { class: "history-item-header" }).append($info).append($("<span>", {
						class: "history-item-count",
						/* translators: 1: updated, 2: skipped, 3: up to date, 4: errors. */
						text: sprintf(
							__("%1$d updated, %2$d skipped, %3$d up to date, %4$d errors", "bulk-page-duplicator"),
							entry.counts.updated || 0,
							entry.counts.skipped || 0,
							entry.counts.unchanged || 0,
							entry.counts.error || 0
						)
					})));
					$list.append($item);
				});
			},
			error: function () {
				$list.empty();
				$empty.text(__("Error loading history.", "bulk-page-duplicator")).show();
			}
		});
	}

	loadHistory();
});
