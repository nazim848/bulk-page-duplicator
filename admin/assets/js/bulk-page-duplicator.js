jQuery(document).ready(function ($) {
	let isProcessing = false;
	let cancelRequested = false;
	let templateData = null; // Cache for template title/slug
	let availableTemplates = []; // Cache for template list
	let highlightedIndex = -1; // For keyboard navigation
	let resultsData = []; // Store results for filtering/export
	let processingStartTime = null; // Track when processing started
	let batchTimings = []; // Track timing for each batch to estimate remaining time
	let savePreferencesTimeout = null; // Debounce timer for saving preferences

	// Load saved user preferences on page load
	function loadUserPreferences() {
		const prefs = bulk_page_dup_ajax.user_preferences;
		if (!prefs) return;

		// Set post type first (this triggers template reload)
		if (prefs.post_type) {
			$("#post-type").val(prefs.post_type);
			// Trigger change to load templates for this post type
			$("#post-type").trigger("change");
		}

		// Set page status
		if (prefs.page_status) {
			$("#page-status").val(prefs.page_status);
		}

		// Set checkbox preferences
		$("#replace-title").prop("checked", prefs.replace_title !== false);
		$("#replace-slug").prop("checked", prefs.replace_slug !== false);
		$("#replace-content").prop("checked", prefs.replace_content !== false);
		$("#replace-elementor").prop("checked", prefs.replace_elementor !== false);
		$("#replace-seo").prop("checked", prefs.replace_seo !== false);

		// Set template and parent after templates are loaded (delayed)
		if (prefs.template_id || prefs.parent_page) {
			setTimeout(function() {
				if (prefs.template_id) {
					// For Enhanced Template Selector, we need to find the template in availableTemplates
					selectTemplate(prefs.template_id);
				}
				if (prefs.parent_page) {
					$("#parent-page").val(prefs.parent_page);
				}
			}, 1000); // Wait for AJAX to complete (increased delay for reliability)
		}
	}

	// Save user preferences (debounced)
	function saveUserPreferences() {
		// Clear existing timeout
		if (savePreferencesTimeout) {
			clearTimeout(savePreferencesTimeout);
		}

		// Debounce to avoid too many AJAX calls
		savePreferencesTimeout = setTimeout(function() {
			$.ajax({
				url: bulk_page_dup_ajax.ajax_url,
				type: "POST",
				data: {
					action: "bpd_save_preferences",
					nonce: bulk_page_dup_ajax.nonce,
					post_type: $("#post-type").val(),
					template_id: $("#template-page").val(),
					page_status: $("#page-status").val(),
					parent_page: $("#parent-page").val() || "0",
					replace_title: $("#replace-title").is(":checked").toString(),
					replace_slug: $("#replace-slug").is(":checked").toString(),
					replace_content: $("#replace-content").is(":checked").toString(),
					replace_elementor: $("#replace-elementor").is(":checked").toString(),
					replace_seo: $("#replace-seo").is(":checked").toString()
				}
			});
		}, 1000); // Save after 1 second of inactivity
	}

	// Bind save preferences to relevant form changes
	function bindPreferenceSaving() {
		// Save on select changes
		$("#post-type, #template-page, #page-status, #parent-page").on("change", function() {
			saveUserPreferences();
		});

		// Save on checkbox changes
		$("#replace-title, #replace-slug, #replace-content, #replace-elementor, #replace-seo").on("change", function() {
			saveUserPreferences();
		});
	}

	// Request notification permission on page load
	function requestNotificationPermission() {
		if ("Notification" in window && Notification.permission === "default") {
			Notification.requestPermission();
		}
	}
	requestNotificationPermission();

	// Show browser notification
	function showNotification(title, body) {
		if ("Notification" in window && Notification.permission === "granted") {
			const notification = new Notification(title, {
				body: body,
				icon: "/wp-admin/images/wordpress-logo.svg"
			});
			// Auto-close after 5 seconds
			setTimeout(() => notification.close(), 5000);
		}
	}

	// Format time in human readable format
	function formatTime(seconds) {
		if (seconds < 60) {
			return Math.round(seconds) + " seconds";
		} else if (seconds < 3600) {
			const mins = Math.floor(seconds / 60);
			const secs = Math.round(seconds % 60);
			return mins + " min" + (mins > 1 ? "s" : "") + (secs > 0 ? " " + secs + "s" : "");
		} else {
			const hours = Math.floor(seconds / 3600);
			const mins = Math.round((seconds % 3600) / 60);
			return hours + " hour" + (hours > 1 ? "s" : "") + (mins > 0 ? " " + mins + " min" : "");
		}
	}

	// Calculate estimated time remaining
	function calculateETA(processedCount, totalCount) {
		if (batchTimings.length === 0 || processedCount === 0) {
			return "Calculating...";
		}

		// Calculate average time per item based on recent batches
		const totalTime = batchTimings.reduce((a, b) => a + b, 0);
		const totalItems = batchTimings.length * 10; // Each batch is 10 items
		const avgTimePerItem = totalTime / Math.min(processedCount, totalItems);

		const remainingItems = totalCount - processedCount;
		const estimatedSeconds = remainingItems * avgTimePerItem / 1000;

		return formatTime(estimatedSeconds);
	}

	// Helper function to escape HTML
	function escapeHtml(text) {
		const div = document.createElement('div');
		div.textContent = text;
		return div.innerHTML;
	}

	// Initialize preferences on page load
	loadUserPreferences();
	bindPreferenceSaving();

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
					validatePlaceholder(); // Re-validate when template loaded
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
		validatePlaceholder(); // Re-validate when template cleared
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
				'<strong>Warning:</strong> Placeholder"' + notFound.join('", "') + '" not found in template content. '
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

	// Handle post type change - reload templates, parent pages, and taxonomies
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
								'<option value="' + post.id + '">' + escapeHtml(post.title) + '</option>'
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

		// Load taxonomies for the post type
		loadTaxonomies(postType);
	});

	// Function to load taxonomies for a post type
	function loadTaxonomies(postType) {
		const $section = $("#taxonomy-section");
		const $loading = $("#taxonomy-loading");
		const $list = $("#taxonomy-list");

		$loading.show();
		$list.empty();

		$.ajax({
			url: bulk_page_dup_ajax.ajax_url,
			type: "POST",
			data: {
				action: "bpd_get_taxonomies",
				nonce: bulk_page_dup_ajax.nonce,
				post_type: postType
			},
			success: function (response) {
				$loading.hide();

				if (response.success && response.data.taxonomies.length > 0) {
					$section.show();

					response.data.taxonomies.forEach(function (taxonomy) {
						let termsHtml = '';

						if (taxonomy.terms.length > 0) {
							taxonomy.terms.forEach(function (term) {
								termsHtml += `
									<label>
										<input type="checkbox" class="taxonomy-term" 
											data-taxonomy="${taxonomy.name}" 
											value="${term.id}">
										${escapeHtml(term.name)}
									</label>
								`;
							});
						} else {
							termsHtml = '<p class="taxonomy-empty">No terms available</p>';
						}

						const html = `
							<div class="taxonomy-group" data-taxonomy="${taxonomy.name}">
								<h4>${escapeHtml(taxonomy.label)}</h4>
								<div class="taxonomy-terms">
									${termsHtml}
								</div>
							</div>
						`;
						$list.append(html);
					});
				} else {
					$section.hide();
				}
			},
			error: function () {
				$loading.hide();
				$section.hide();
			}
		});
	}

	// Load templates and taxonomies on page load
	(function loadInitialData() {
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

		loadTaxonomies(postType);
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

	// ===== CSV Import Functionality =====
	const $dropZone = $("#csv-drop-zone");
	const $fileInput = $("#csv-file-input");
	const $csvPreview = $("#csv-preview");
	const $csvFileName = $("#csv-file-name");
	const $csvRowCount = $("#csv-row-count");
	const $csvClear = $("#csv-clear");

	// Parse CSV content
	function parseCSV(content) {
		const lines = content.split(/\r?\n/).filter(line => line.trim() !== "");
		return lines;
	}

	// Handle file processing
	function processFile(file) {
		if (!file) return;

		const validTypes = ["text/csv", "text/plain", "application/vnd.ms-excel"];
		const validExtensions = [".csv", ".txt"];
		const fileName = file.name.toLowerCase();
		const hasValidExtension = validExtensions.some(ext => fileName.endsWith(ext));

		if (!validTypes.includes(file.type) && !hasValidExtension) {
			alert("Please upload a CSV or TXT file.");
			return;
		}

		const reader = new FileReader();
		reader.onload = function (e) {
			const content = e.target.result;
			const lines = parseCSV(content);

			if (lines.length === 0) {
				alert("The file appears to be empty.");
				return;
			}

			// Populate textarea
			$("#replacement-values").val(lines.join("\n"));

			// Show preview
			$csvFileName.text(file.name);
			$csvRowCount.text(lines.length + " values loaded");
			$dropZone.hide();
			$csvPreview.show();

			// Update preview
			updatePreview();
			validateValues(); // Re-validate when CSV loaded
		};
		reader.readAsText(file);
	}

	// File input change
	$fileInput.on("change", function () {
		processFile(this.files[0]);
		$(this).val(""); // Reset input
	});

	// Drag and drop handlers
	$dropZone.on("dragover dragenter", function (e) {
		e.preventDefault();
		e.stopPropagation();
		$(this).addClass("drag-over");
	});

	$dropZone.on("dragleave dragend drop", function (e) {
		e.preventDefault();
		e.stopPropagation();
		$(this).removeClass("drag-over");
	});

	$dropZone.on("drop", function (e) {
		const files = e.originalEvent.dataTransfer.files;
		if (files.length > 0) {
			processFile(files[0]);
		}
	});

	// Clear CSV
	$csvClear.on("click", function () {
		$("#replacement-values").val("");
		$csvPreview.hide();
		$dropZone.show();
		updatePreview();
		validateValues(); // Re-validate when cleared
	});

	// Update preview when textarea changes manually
	$("#replacement-values").on("input", function () {
		// If user manually edits, hide CSV preview
		if ($csvPreview.is(":visible")) {
			const lines = $(this).val().split("\n").filter(v => v.trim() !== "");
			$csvRowCount.text(lines.length + " values");
		}
	});
	// ===== End CSV Import =====

	// Dry Run functionality
	$("#dry-run").on("click", function (e) {
		e.preventDefault();

		// Validate inputs first
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

		// Parse values
		const values = rawValues.map(line => {
			if (placeholders.length > 1) {
				return line.split(",").map(p => p.trim());
			}
			return [line.trim()];
		});

		// Get replacement options
		const replaceOptions = [];
		if ($("#replace-title").is(":checked")) replaceOptions.push("title");
		if ($("#replace-slug").is(":checked")) replaceOptions.push("slug");
		if ($("#replace-content").is(":checked")) replaceOptions.push("content");
		if ($("#replace-elementor").is(":checked")) replaceOptions.push("elementor");
		if ($("#replace-seo").is(":checked")) replaceOptions.push("seo");

		// Show modal with loading state
		$("#dry-run-modal").show();
		$("#dry-run-loading").show();
		$("#dry-run-results").hide();

		// Make AJAX request for dry run
		$.ajax({
			url: bulk_page_dup_ajax.ajax_url,
			type: "POST",
			data: {
				action: "bpd_dry_run",
				nonce: bulk_page_dup_ajax.nonce,
				template_id: templateId,
				placeholders: placeholders,
				values: values,
				post_type: $("#post-type").val(),
				replace_options: replaceOptions
			},
			success: function (response) {
				if (response.success) {
					displayDryRunResults(response.data);
				} else {
					alert("Error: " + response.data);
					$("#dry-run-modal").hide();
				}
			},
			error: function () {
				alert("Error performing dry run. Please try again.");
				$("#dry-run-modal").hide();
			}
		});
	});

	// Display dry run results in modal
	function displayDryRunResults(data) {
		// Update summary
		$("#dry-run-total").text(data.summary.total);
		$("#dry-run-create").text(data.summary.will_create);
		$("#dry-run-skip").text(data.summary.will_skip);

		// Build table rows
		const $tbody = $("#dry-run-table-body");
		$tbody.empty();

		data.items.forEach(function (item) {
			const statusClass = item.status === 'create' ? 'bpd-status-create' : 'bpd-status-skip';
			const statusIcon = item.status === 'create' ? '✓' : '⚠';
			const statusText = item.status === 'create' ? 'Create' : 'Skip';

			let row = '<tr class="' + statusClass + '">';
			row += '<td><span class="bpd-status-badge bpd-status-' + item.status + '">' + statusIcon + ' ' + statusText + '</span></td>';
			row += '<td>' + escapeHtml(item.value) + '</td>';
			row += '<td>' + escapeHtml(item.title) + '</td>';
			row += '<td><code>' + escapeHtml(item.slug) + '</code></td>';
			row += '</tr>';

			if (item.reason) {
				row += '<tr class="bpd-reason-row"><td colspan="4"><small>' + escapeHtml(item.reason) + '</small></td></tr>';
			}

			$tbody.append(row);
		});

		// Show results, hide loading
		$("#dry-run-loading").hide();
		$("#dry-run-results").show();

		// Disable proceed button if nothing to create
		if (data.summary.will_create === 0) {
			$("#dry-run-proceed").prop("disabled", true).text("Nothing to create");
		} else {
			$("#dry-run-proceed").prop("disabled", false).text("Proceed with Duplication (" + data.summary.will_create + " items)");
		}
	}

	// Close modal handlers
	$(".bpd-modal-close, .bpd-modal-close-btn").on("click", function () {
		$("#dry-run-modal").hide();
	});

	// Close modal on overlay click
	$("#dry-run-modal").on("click", function (e) {
		if ($(e.target).is("#dry-run-modal")) {
			$(this).hide();
		}
	});

	// Close modal on Escape key
	$(document).on("keydown", function (e) {
		if (e.key === "Escape" && $("#dry-run-modal").is(":visible")) {
			$("#dry-run-modal").hide();
		}
	});

	// Proceed button - close modal and start duplication
	$("#dry-run-proceed").on("click", function () {
		$("#dry-run-modal").hide();
		$("#start-duplication").trigger("click");
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
		if ($("#copy-featured-image").is(":checked")) replaceOptions.push("featured_image");

		// Get selected taxonomy terms
		const taxonomyTerms = {};
		$(".taxonomy-term:checked").each(function () {
			const taxonomy = $(this).data("taxonomy");
			const termId = parseInt($(this).val());
			if (!taxonomyTerms[taxonomy]) {
				taxonomyTerms[taxonomy] = [];
			}
			taxonomyTerms[taxonomy].push(termId);
		});

		// Initialize UI for processing
		isProcessing = true;
		cancelRequested = false;
		resultsData = []; // Reset results
		processingStartTime = Date.now();
		batchTimings = [];
		$(".bulk-page-dup-progress-container").show();
		$(".bulk-page-dup-log-container").show();
		$(".bulk-page-dup-log").empty();
		$("#results-summary").hide();
		$("#count-success, #count-skipped, #count-error").text("0");
		$(".log-filter").removeClass("active").filter('[data-filter="all"]').addClass("active");
		$("#start-duplication").hide();
		$("#cancel-duplication").show();
		$("#progress-eta").text("");
		$("#progress-current-item").text("");

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
			taxonomyTerms,
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
		taxonomyTerms,
		batchIndex
	) {
		const batchStartTime = Date.now();

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

		// Update ETA
		const eta = calculateETA(processedValues, totalValues);
		$("#progress-eta").text(processedValues > 0 ? "Estimated time remaining: " + eta : "");

		// Get current batch of values
		const batchSize = 10;
		const startIndex = batchIndex;
		const endIndex = Math.min(startIndex + batchSize, totalValues);
		const currentBatch = allValues.slice(startIndex, endIndex);

		// Show current item being processed
		if (currentBatch.length > 0) {
			const currentItemName = Array.isArray(currentBatch[0]) ? currentBatch[0][0] : currentBatch[0];
			$("#progress-current-item").text("Processing: " + currentItemName + "...");
		}

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
				taxonomy_terms: taxonomyTerms,
				batch_index: batchIndex
			},
			success: function (response) {
				if (response.success) {
					// Log results
					if (response.data.results && response.data.results.length > 0) {
						response.data.results.forEach(function (result) {
							// Store result for filtering/export
							resultsData.push(result);

							let logClass = "bulk-page-dup-log-" + result.status;
							let message = result.value + ": " + result.message;

							if (result.edit_url) {
								message +=
									' (<a href="' +
										result.edit_url +
										'" target="_blank">Edit</a>)';
							}

							$(".bulk-page-dup-log").prepend(
								'<div class="bulk-page-dup-log-entry " +
										logClass +
										'" data-status="' + result.status + '">' +
										message +
										"</div>"
							);
						});

						// Update summary counts
						updateResultsSummary();
					}

					// Track batch timing for ETA calculation
					const batchDuration = Date.now() - batchStartTime;
					batchTimings.push(batchDuration);
					// Keep only last 5 batches for more accurate recent average
					if (batchTimings.length > 5) {
						batchTimings.shift();
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
							taxonomyTerms,
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
		$("#progress-eta").text("");
		$("#progress-current-item").text("");

		// Calculate total time taken
		if (processingStartTime && !cancelRequested) {
			const totalTime = (Date.now() - processingStartTime) / 1000;
			$(".bulk-page-dup-status-text").text(message + " (Completed in " + formatTime(totalTime) + ")");

			// Show browser notification
			showNotification(
				"Bulk Page Duplicator",
				"Duplication complete! " + formatTime(totalTime)
			);
		}

		if (cancelRequested) {
			$("#cancel-duplication").text("Cancel");
		}

		// Show summary
		$("#results-summary").show();

		// Reset timing variables
		processingStartTime = null;
		batchTimings = [];

		// Refresh history after operation completes
		loadHistory();
	}

	// Update results summary counts
	function updateResultsSummary() {
		const counts = { success: 0, skipped: 0, error: 0 };
		resultsData.forEach(r => {
			if (counts.hasOwnProperty(r.status)) {
				counts[r.status]++;
			}
		});
		$("#count-success").text(counts.success);
		$("#count-skipped").text(counts.skipped);
		$("#count-error").text(counts.error);
	}

	// Filter log entries
	$(document).on("click", ".log-filter", function() {
		const filter = $(this).data("filter");
		$(".log-filter").removeClass("active");
		$(this).addClass("active");

		if (filter === "all") {
			$(".bulk-page-dup-log-entry").show();
		} else {
			$(".bulk-page-dup-log-entry").hide();
			$('.bulk-page-dup-log-entry[data-status="' + filter + '"]').show();
		}
	});

	// Export results to CSV
	$("#export-results").on("click", function() {
		if (resultsData.length === 0) {
			alert("No results to export.");
			return;
		}

		// Build CSV content
		const headers = ["Value", "Status", "Message", "Edit URL"];
		const rows = resultsData.map(r => [
			'"' + (r.value || '').replace(/"/g, '""') + '"',
			r.status,
			'"' + (r.message || '').replace(/"/g, '""') + '"',
			r.edit_url || ''
		]);

		const csv = [headers.join(",")].concat(rows.map(r => r.join(","))).join("\n");

		// Download
		const blob = new Blob([csv], { type: "text/csv;charset=utf-8;" });
		const link = document.createElement("a");
		const url = URL.createObjectURL(blob);
		link.setAttribute("href", url);
		link.setAttribute("download", "bulk-duplication-results.csv");
		link.style.visibility = "hidden";
		document.body.appendChild(link);
		link.click();
		document.body.removeChild(link);
	});

	// === History/Rollback functionality ===

	function loadHistory() {
		const $loading = $("#history-loading");
		const $list = $("#history-list");
		const $empty = $("#history-empty");

		$loading.show();
		$list.empty();
		$empty.hide();

		$.ajax({
			url: bulk_page_dup_ajax.ajax_url,
			type: "POST",
			data: {
				action: "bpd_get_history",
				nonce: bulk_page_dup_ajax.nonce
			},
			success: function (response) {
				$loading.hide();

				if (response.success && response.data.history.length > 0) {
					response.data.history.forEach(function (item) {
						const countClass = item.existing_count === 0 ? 'none' : 
							(item.existing_count < item.total_count ? 'partial' : '');
						const countText = item.existing_count === item.total_count ? 
							item.total_count + ' items' : 
							item.existing_count + ' of ' + item.total_count + ' remain';

						const html = `
							<div class="bulk-page-dup-history-item" data-key="${item.key}">
								<div class="history-item-header">
									<div class="history-item-info">
										<div class="history-item-title">Template: ${escapeHtml(item.template_title)}</div>
										<div class="history-item-meta">
											<span>${item.date}</span>
											<span>${item.post_type_label}</span>
										</div>
									</div>
									<span class="history-item-count ${countClass}">${countText}</span>
								</div>
								<div class="history-item-actions">
									<button type="button" class="button button-small rollback-btn" 
										${!item.can_rollback ? 'disabled' : ''} 
										data-key="${item.key}" 
										data-count="${item.existing_count}">
										Rollback (Delete All)
									</button>
								</div>
							</div>
						`;
						$list.append(html);
					});
				} else {
					$empty.show();
				}
			},
			error: function () {
				$loading.hide();
				$empty.text("Error loading history.").show();
			}
		});
	}

	// Handle rollback button click
	$(document).on("click", ".rollback-btn", function () {
		const $btn = $(this);
		const sessionKey = $btn.data("key");
		const count = $btn.data("count");

		if (!confirm("Are you sure you want to delete " + count + " items? This action cannot be undone.")) {
			return;
		}

		$btn.prop("disabled", true).text("Deleting...");

		$.ajax({
			url: bulk_page_dup_ajax.ajax_url,
			type: "POST",
			data: {
				action: "bpd_rollback",
				nonce: bulk_page_dup_ajax.nonce,
				session_key: sessionKey
			},
			success: function (response) {
				if (response.success) {
					// Remove the item from UI
					$btn.closest(".bulk-page-dup-history-item").fadeOut(300, function () {
						$(this).remove();
						// Check if list is empty
						if ($("#history-list .bulk-page-dup-history-item").length === 0) {
							$("#history-empty").show();
						}
					});
					alert(response.data.message);
				} else {
					$btn.prop("disabled", false).text("Rollback (Delete All)");
					alert("Error: " + response.data);
				}
			},
			error: function () {
				$btn.prop("disabled", false).text("Rollback (Delete All)");
				alert("An error occurred. Please try again.");
			}
		});
	});

	// Handle refresh history button
	$("#refresh-history").on("click", function () {
		loadHistory();
	});

	// Load history on page load
	loadHistory();
});