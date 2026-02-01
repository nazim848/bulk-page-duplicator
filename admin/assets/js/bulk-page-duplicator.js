jQuery(document).ready(function ($) {
	let isProcessing = false;
	let cancelRequested = false;
	let templateData = null; // Cache for template title/slug

	// Helper function to simulate smart_replace (case-preserving)
	function smartReplace(text, search, replace) {
		if (!text || !search || !replace) return text;

		// Replace uppercase version
		text = text.split(search.toUpperCase()).join(replace.toUpperCase());
		// Replace title case version
		const titleSearch = search.charAt(0).toUpperCase() + search.slice(1).toLowerCase();
		const titleReplace = replace.charAt(0).toUpperCase() + replace.slice(1).toLowerCase();
		text = text.split(titleSearch).join(titleReplace);
		// Replace exact match
		text = text.split(search).join(replace);
		// Replace lowercase version
		text = text.split(search.toLowerCase()).join(replace.toLowerCase());

		return text;
	}

	// Helper function to convert text to slug
	function toSlug(text) {
		return text
			.toLowerCase()
			.replace(/[^a-z0-9\s-]/g, '')
			.replace(/\s+/g, '-')
			.replace(/-+/g, '-')
			.trim();
	}

	// Update preview function
	function updatePreview() {
		const templateId = $("#template-page").val();
		const placeholderInput = $("#placeholder-text").val();
		const rawValues = $("#replacement-values").val().split("\n").filter(v => v.trim() !== "");

		// Hide preview if no template selected
		if (!templateId || !templateData) {
			$("#preview-panel").hide();
			return;
		}

		// Parse placeholders and first value
		const placeholders = placeholderInput.split(",").map(p => p.trim()).filter(p => p !== "");
		let firstValues = [];

		if (rawValues.length > 0) {
			if (placeholders.length > 1) {
				firstValues = rawValues[0].split(",").map(v => v.trim());
			} else {
				firstValues = [rawValues[0].trim()];
			}
		}

		// Generate preview title and slug
		let previewTitle = templateData.title;
		let previewSlug = templateData.slug;

		if (placeholders.length > 0 && firstValues.length > 0) {
			placeholders.forEach((placeholder, index) => {
				const value = firstValues[index] || '';
				if (placeholder && value) {
					previewTitle = smartReplace(previewTitle, placeholder, value);
					previewSlug = smartReplace(previewSlug, placeholder, value);
					// Also replace slugified placeholder
					const placeholderSlug = toSlug(placeholder);
					const valueSlug = toSlug(value);
					previewSlug = previewSlug.split(placeholderSlug).join(valueSlug);
				}
			});
		}

		// Update preview panel
		$("#preview-title").text(previewTitle || templateData.title);
		$("#preview-slug").text(toSlug(previewSlug) || templateData.slug);
		$("#preview-count").text(rawValues.length);
		$("#preview-panel").show();
	}

	// Fetch template data when template changes
	$("#template-page").on("change", function () {
		const templateId = $(this).val();

		if (!templateId) {
			templateData = null;
			$("#preview-panel").hide();
			validatePlaceholder(); // Re-validate when template cleared
			return;
		}

		$.ajax({
			url: bulk_page_dup_ajax.ajax_url,
			type: "POST",
			data: {
				action: "bpd_get_template_data",
				nonce: bulk_page_dup_ajax.nonce,
				template_id: templateId
			},
			success: function (response) {
				if (response.success) {
					templateData = response.data;
					updatePreview();
					validatePlaceholder(); // Re-validate when template loaded
				}
			}
		});
	});

	// Update preview when placeholder or values change
	$("#placeholder-text, #replacement-values").on("input", function () {
		updatePreview();
	});

	// === Validation Functions ===

	// Check if placeholder exists in template content
	function checkPlaceholderInContent(placeholder) {
		if (!templateData || !templateData.searchable_content || !placeholder) {
			return null; // Can't determine
		}
		const content = templateData.searchable_content.toLowerCase();
		return content.includes(placeholder.toLowerCase());
	}

	// Validate placeholder field
	function validatePlaceholder() {
		const $input = $("#placeholder-text");
		const $validation = $("#placeholder-validation");
		const value = $input.val().trim();

		// Clear previous state
		$input.removeClass("has-error has-warning has-success");
		$validation.hide().removeClass("error warning success info");

		if (!value) {
			return; // Empty, no validation needed
		}

		const placeholders = value.split(",").map(p => p.trim()).filter(p => p !== "");

		if (!templateData || !templateData.searchable_content) {
			// Template not loaded yet, can't validate
			return;
		}

		// Check if placeholders exist in template
		const notFound = placeholders.filter(p => !checkPlaceholderInContent(p));

		if (notFound.length > 0) {
			$input.addClass("has-warning");
			$validation.addClass("warning").html(
				'<strong>Warning:</strong> Placeholder"' + notFound.join('", "') + '" not found in template content. ' +
				'Make sure it exists in the title, slug, or content.'
			).show();
		} else {
			$input.addClass("has-success");
			$validation.addClass("success").html(
				'<strong>✓</strong> Placeholder found in template.'
			).show();
		}
	}

	// Validate replacement values
	function validateValues() {
		const $textarea = $("#replacement-values");
		const $validation = $("#values-validation");
		const $info = $("#values-info");
		const $count = $("#values-count");
		const $slugWarning = $("#slug-length-warning");
		const value = $textarea.val();

		// Clear previous state
		$textarea.removeClass("has-error has-warning");
		$validation.hide().removeClass("error warning");
		$info.hide();
		$slugWarning.hide();

		if (!value.trim()) {
			return; // Empty, no validation needed
		}

		const lines = value.split("\n").filter(v => v.trim() !== "");
		const warnings = [];

		// Show count
		$count.text(lines.length + " item" + (lines.length !== 1 ? "s" : "") + " to create");
		$info.show();

		// Check for duplicates
		const seen = new Set();
		const duplicates = [];
		lines.forEach(line => {
			const normalized = line.trim().toLowerCase();
			if (seen.has(normalized)) {
				duplicates.push(line.trim());
			} else {
				seen.add(normalized);
			}
		});

		if (duplicates.length > 0) {
			warnings.push('Duplicate values found: "' + duplicates.slice(0, 3).join('", "') + '"' + 
				(duplicates.length > 3 ? ' and ' + (duplicates.length - 3) + ' more' : ''));
		}

		// Check for long slugs (>50 chars could cause issues)
		const longValues = lines.filter(line => {
			const slug = toSlug(line.split(',')[0].trim());
			return slug.length > 50;
		});

		if (longValues.length > 0) {
			$slugWarning.text(longValues.length + " value(s) may create long slugs (>50 chars)").show();
		}

		// Check for empty lines in middle of text
		const hasEmptyInMiddle = value.split("\n").some((line, index, arr) => {
			return line.trim() === "" && index > 0 && index < arr.length - 1 && arr.slice(index + 1).some(l => l.trim() !== "");
		});

		if (hasEmptyInMiddle) {
			warnings.push('Empty lines found between values (they will be skipped)');
		}

		if (warnings.length > 0) {
			$textarea.addClass("has-warning");
			$validation.addClass("warning").html(
				'<strong>Warning:</strong> ' + warnings.join('. ') + '.'
			).show();
		}
	}

	// Bind validation to input events
	$("#placeholder-text").on("input blur", function() {
		validatePlaceholder();
	});

	$("#replacement-values").on("input blur", function() {
		validateValues();
	});

	// Handle post type change - reload templates and parent pages
	$("#post-type").on("change", function () {
		const postType = $(this).val();
		const $templateSelect = $("#template-page");
		const $parentSelect = $("#parent-page");
		const $loading = $("#template-loading");
		const $parentSection = $("#parent-page-section");

		// Show/hide parent section based on whether post type is hierarchical
		const hierarchicalTypes = ["page"]; // Add more as needed
		if (hierarchicalTypes.includes(postType)) {
			$parentSection.show();
		} else {
			$parentSection.hide();
		}

		// Show loading state
		$templateSelect.prop("disabled", true);
		$loading.show();

		$.ajax({
			url: bulk_page_dup_ajax.ajax_url,
			type: "POST",
			data: {
				action: "bpd_get_posts_by_type",
				nonce: bulk_page_dup_ajax.nonce,
				post_type: postType
			},
			success: function (response) {
				if (response.success) {
					// Clear and rebuild template options
					$templateSelect.empty();
					$templateSelect.append(
						'<option value="">Select a template</option>'
					);

					response.data.posts.forEach(function (post) {
						$templateSelect.append(
							'<option value="' + post.id + '">' + post.title + "</option>"
						);
					});

					// Also update parent page dropdown if hierarchical
					if (response.data.is_hierarchical) {
						$parentSection.show();
						$parentSelect.empty();
						$parentSelect.append(
							'<option value="0">No parent (top level)</option>'
						);
						$parentSelect.append(
							'<option value="template">Same as template</option>'
						);
						response.data.posts.forEach(function (post) {
							$parentSelect.append(
								'<option value="' + post.id + '">' + post.title.replace(/ \(ID:.*\)/, '') + "</option>"
							);
						});
					} else {
						$parentSection.hide();
					}

					// Reset template data and preview when post type changes
					templateData = null;
					$("#preview-panel").hide();
				} else {
					alert("Error loading templates: " + response.data);
				}
			},
			error: function () {
				alert("Error loading templates. Please try again.");
			},
			complete: function () {
				$templateSelect.prop("disabled", false);
				$loading.hide();
			}
		});
	});

	// Show/hide multi-placeholder help based on input
	$("#placeholder-text").on("input", function () {
		const placeholders = $(this)
			.val()
			.split(",")
			.map(p => p.trim())
			.filter(p => p !== "");

		if (placeholders.length > 1) {
			$("#replacement-multi-help").show();
			$("#replacement-values").attr(
				"placeholder",
				"New York, USA\nLos Angeles, USA\nChicago, USA"
			);
		} else {
			$("#replacement-multi-help").hide();
			$("#replacement-values").attr(
				"placeholder",
				"New York\nLos Angeles\nChicago"
			);
		}
	});

	$("#start-duplication").on("click", function (e) {
		e.preventDefault();

		// Validate inputs
		const templateId = $("#template-page").val();
		const placeholderInput = $("#placeholder-text").val();
		const placeholders = placeholderInput
			.split(",")
			.map(p => p.trim())
			.filter(p => p !== "");

		const rawValues = $("#replacement-values")
			.val()
			.split("\n")
			.filter(val => val.trim() !== "");

		if (!templateId) {
			alert("Please select a template.");
			return;
		}

		if (placeholders.length === 0) {
			alert("Please enter at least one placeholder.");
			return;
		}

		if (rawValues.length === 0) {
			alert("Please enter at least one replacement value.");
			return;
		}

		// Parse values - for multiple placeholders, split each line by comma
		const values = rawValues.map(line => {
			if (placeholders.length > 1) {
				// Split by comma, but respect the number of placeholders
				const parts = line.split(",").map(p => p.trim());
				return parts;
			}
			return [line.trim()];
		});

		// Validate that each line has the correct number of values
		if (placeholders.length > 1) {
			const invalidLines = values.filter(v => v.length !== placeholders.length);
			if (invalidLines.length > 0) {
				alert(
					"Each line must have " +
						placeholders.length +
						" comma-separated values (one for each placeholder).\n\n" +
						"Placeholders: " +
						placeholders.join(", ")
				);
				return;
			}
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

		// Get selected post type and parent page
		const postType = $("#post-type").val();
		const parentPage = $("#parent-page").val() || "0";

		// Process in batches
		processBatch(
			templateId,
			placeholders,
			values,
			$("#page-status").val(),
			replaceOptions,
			postType,
			parentPage,
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
		placeholders,
		allValues,
		status,
		replaceOptions,
		postType,
		parentPage,
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
				placeholders: placeholders,
				values: currentBatch,
				status: status,
				replace_options: replaceOptions,
				post_type: postType,
				parent_page: parentPage,
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
							placeholders,
							allValues,
							status,
							replaceOptions,
							postType,
							parentPage,
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
