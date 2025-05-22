jQuery(document).ready(function ($) {
	let isProcessing = false;
	let cancelRequested = false;

	$("#start-duplication").on("click", function (e) {
		e.preventDefault();

		// Validate inputs
		const templateId = $("#template-page").val();
		const placeholder = $("#placeholder-text").val();
		const values = $("#replacement-values")
			.val()
			.split("\n")
			.filter(val => val.trim() !== "");

		if (!templateId) {
			alert("Please select a template page.");
			return;
		}

		if (!placeholder) {
			alert("Please enter placeholder text.");
			return;
		}

		if (values.length === 0) {
			alert("Please enter at least one replacement value.");
			return;
		}

		// Confirm if a large number of pages will be created
		if (
			values.length > 50 &&
			!confirm(
				"You are about to create " + values.length + " pages. Continue?"
			)
		) {
			return;
		}

		// Get replacement options
		const replaceOptions = [];
		if ($("#replace-title").is(":checked")) replaceOptions.push("title");
		if ($("#replace-slug").is(":checked")) replaceOptions.push("slug");
		if ($("#replace-content").is(":checked")) replaceOptions.push("content");
		if ($("#replace-elementor").is(":checked"))
			replaceOptions.push("elementor");
		if ($("#replace-seo").is(":checked")) replaceOptions.push("seo");

		// Initialize UI for processing
		isProcessing = true;
		cancelRequested = false;
		$(".bulk-page-dup-progress-container").show();
		$(".bulk-page-dup-log-container").show();
		$(".bulk-page-dup-log").empty();
		$("#start-duplication").hide();
		$("#cancel-duplication").show();

		// Process in batches
		processBatch(
			templateId,
			placeholder,
			values,
			$("#page-status").val(),
			replaceOptions,
			0
		);
	});

	$("#cancel-duplication").on("click", function (e) {
		e.preventDefault();
		cancelRequested = true;
		$(this).text("Cancelling...");
		$(".bulk-page-dup-status-text").text("Cancelling the operation...");
	});

	function processBatch(
		templateId,
		placeholder,
		allValues,
		status,
		replaceOptions,
		batchIndex
	) {
		if (cancelRequested) {
			finishProcessing("Operation cancelled by user.");
			return;
		}

		// Calculate progress
		const totalValues = allValues.length;
		const processedValues = batchIndex;
		const progress = Math.round((processedValues / totalValues) * 100);

		// Update progress UI
		$(".bulk-page-dup-progress-bar-inner").css("width", progress + "%");
		$(".bulk-page-dup-progress-text").text(
			progress + "% (" + processedValues + " of " + totalValues + ")"
		);
		$(".bulk-page-dup-status-text").text("Processing pages...");

		// Get current batch of values
		const batchSize = 10;
		const startIndex = batchIndex;
		const endIndex = Math.min(startIndex + batchSize, totalValues);
		const currentBatch = allValues.slice(startIndex, endIndex);

		// If we've processed all values, finish
		if (startIndex >= totalValues) {
			finishProcessing("All pages have been processed successfully!");
			return;
		}

		// Send AJAX request to process current batch
		$.ajax({
			url: bulk_page_dup_ajax.ajax_url,
			type: "POST",
			data: {
				action: "process_bulk_duplication",
				nonce: bulk_page_dup_ajax.nonce,
				template_id: templateId,
				placeholder: placeholder,
				values: currentBatch,
				status: status,
				replace_options: replaceOptions,
				batch_index: batchIndex
			},
			success: function (response) {
				if (response.success) {
					// Log results
					if (response.data.results && response.data.results.length > 0) {
						response.data.results.forEach(function (result) {
							let logClass = "bulk-page-dup-log-" + result.status;
							let message = result.value + ": " + result.message;

							if (result.edit_url) {
								message +=
									' (<a href="' +
									result.edit_url +
									'" target="_blank">Edit</a>)';
							}

							$(".bulk-page-dup-log").prepend(
								'<div class="bulk-page-dup-log-entry ' +
									logClass +
									'">' +
									message +
									"</div>"
							);
						});
					}

					// If this is the last batch or operation was cancelled, finish
					if (response.data.is_last_batch || cancelRequested) {
						finishProcessing(
							"All pages have been processed successfully!"
						);
					} else {
						// Process next batch
						processBatch(
							templateId,
							placeholder,
							allValues,
							status,
							replaceOptions,
							endIndex
						);
					}
				} else {
					// Handle error
					$(".bulk-page-dup-log").prepend(
						'<div class="bulk-page-dup-log-entry bulk-page-dup-log-error">Error: ' +
							response.data +
							"</div>"
					);
					finishProcessing("An error occurred during processing.");
				}
			},
			error: function (xhr, status, error) {
				$(".bulk-page-dup-log").prepend(
					'<div class="bulk-page-dup-log-entry bulk-page-dup-log-error">AJAX Error: ' +
						error +
						"</div>"
				);
				finishProcessing("An error occurred during processing.");
			}
		});
	}

	function finishProcessing(message) {
		isProcessing = false;
		$(".bulk-page-dup-status-text").text(message);
		$("#cancel-duplication").hide();
		$("#start-duplication").show();

		if (cancelRequested) {
			$("#cancel-duplication").text("Cancel");
		}
	}
});
