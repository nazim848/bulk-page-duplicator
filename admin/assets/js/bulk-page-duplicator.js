jQuery(document).ready(function ($) {
	let isProcessing = false;
	let cancelRequested = false;
	let templateData = null; // Cache for template title/slug
	let availableTemplates = []; // Cache for template list
	let highlightedIndex = -1; // For keyboard navigation

	// Helper function to escape HTML
	function escapeHtml(text) {
		const div = document.createElement('div');
		div.textContent = text;
		return div.innerHTML;
	}

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

	// === Enhanced Template Selector ===

	// Render dropdown with filtered templates
	function renderTemplateDropdown(filter = '') {
		const $dropdown = $('#template-dropdown');
		$dropdown.empty();
		highlightedIndex = -1;

		const filterLower = filter.toLowerCase();
		const filtered = availableTemplates.filter(t => 
			t.title.toLowerCase().includes(filterLower) || 
			String(t.id).includes(filter)
		);

		if (filtered.length === 0) {
			$dropdown.html('<div class="template-dropdown-empty">No templates found</div>');
		} else {
			filtered.forEach((template, index) => {
				const thumbHtml = template.thumbnail 
					? `<img class="template-dropdown-thumb" src="${escapeHtml(template.thumbnail)}" alt="">`
					: `<div class="template-dropdown-thumb no-thumb">No img</div>`;

				const html = `
					<div class="template-dropdown-item" data-id="${template.id}" data-index="${index}">
						${thumbHtml}
						<div class="template-dropdown-info">
							<div class="template-dropdown-title">${escapeHtml(template.title)}</div>
							<div class="template-dropdown-meta">
								<span class="template-dropdown-status ${template.status}">${escapeHtml(template.status_label)}</span>
								<span>Modified: ${escapeHtml(template.modified)}</span>
							</div>
						</div>
					</div>
				`;
				$dropdown.append(html);
			});
		}

		$dropdown.show();
	}

	// Select a template
	function selectTemplate(templateId) {
		const template = availableTemplates.find(t => t.id == templateId);
		if (!template) return;

		$('#template-page').val(templateId).trigger('change');
		$('#template-search').val('').hide();
		$('#template-dropdown').hide();

		// Show selected template info
		if (template.thumbnail) {
			$('#template-thumb').attr('src', template.thumbnail).show();
			$('#template-no-thumb').hide();
		} else {
			$('#template-thumb').hide();
			$('#template-no-thumb').show();
		}
		$('#template-title').text(template.title + ' (ID: ' + template.id + ')');
		$('#template-status').text(template.status_label).attr('class', 'template-status ' + template.status);
		$('#template-modified').text('Modified: ' + template.modified);
		$('#selected-template-info').show();

		// Fetch full template data for preview
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
				}
			}
		});
	}

	// Clear template selection
	$('#clear-template').on('click', function() {
		$('#template-page').val('').trigger('change');
		$('#selected-template-info').hide();
		$('#template-search').val('').show();
		templateData = null;
		$('#preview-panel').hide();
	});

	// Handle search input
	$('#template-search').on('input', function() {
		const query = $(this).val();
		if (availableTemplates.length > 0) {
			renderTemplateDropdown(query);
		}
	});

	// Handle focus on search
	$('#template-search').on('focus', function() {
		if (availableTemplates.length > 0) {
			renderTemplateDropdown($(this).val());
		}
	});

	// Handle click outside to close dropdown
	$(document).on('click', function(e) {
		if (!$(e.target).closest('.template-selector-wrapper').length) {
			$('#template-dropdown').hide();
		}
	});

	// Handle template item click
	$(document).on('click', '.template-dropdown-item', function() {
		const templateId = $(this).data('id');
		selectTemplate(templateId);
	});

	// Keyboard navigation
	$('#template-search').on('keydown', function(e) {
		const $items = $('.template-dropdown-item');
		const itemCount = $items.length;

		if (e.key === 'ArrowDown') {
			e.preventDefault();
			highlightedIndex = Math.min(highlightedIndex + 1, itemCount - 1);
			$items.removeClass('highlighted');
			$items.eq(highlightedIndex).addClass('highlighted');
		} else if (e.key === 'ArrowUp') {
			e.preventDefault();
			highlightedIndex = Math.max(highlightedIndex - 1, 0);
			$items.removeClass('highlighted');
			$items.eq(highlightedIndex).addClass('highlighted');
		} else if (e.key === 'Enter') {
			e.preventDefault();
			if (highlightedIndex >= 0) {
				const templateId = $items.eq(highlightedIndex).data('id');
				selectTemplate(templateId);
			}
		} else if (e.key === 'Escape') {
			$('#template-dropdown').hide();
		}
	});

	// Update preview when placeholder or values change
	$("#placeholder-text, #replacement-values").on("input", function () {
		updatePreview();
	});

	// Handle post type change - reload templates and parent pages
	$("#post-type").on("change", function () {
		const postType = $(this).val();
		const $parentSelect = $("#parent-page");
		const $loading = $("#template-loading");
		const $parentSection = $("#parent-page-section");

		// Clear current selection
		$('#template-page').val('');
		$('#selected-template-info').hide();
		$('#template-search').val('').show();
		templateData = null;
		$('#preview-panel').hide();
		availableTemplates = [];

		// Show loading state
		$('#template-search').prop('disabled', true).attr('placeholder', 'Loading templates...');
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
					// Store templates for search
					availableTemplates = response.data.posts;

					// Update parent page dropdown if hierarchical
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
								'<option value="' + post.id + '">' + escapeHtml(post.title) + "</option>"
							);
						});
					} else {
						$parentSection.hide();
					}
				} else {
					alert("Error loading templates: " + response.data);
				}
			},
			error: function () {
				alert("Error loading templates. Please try again.");
			},
			complete: function () {
				$('#template-search').prop('disabled', false).attr('placeholder', 'Type to search templates...');
				$loading.hide();
			}
		});
	});

	// Load templates on page load
	(function loadInitialTemplates() {
		const postType = $('#post-type').val();
		const $loading = $('#template-loading');

		$('#template-search').prop('disabled', true).attr('placeholder', 'Loading templates...');
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
					availableTemplates = response.data.posts;
				}
			},
			complete: function () {
				$('#template-search').prop('disabled', false).attr('placeholder', 'Type to search templates...');
				$loading.hide();
			}
		});
	})();

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
