jQuery(document).ready(function ($) {
	const __ = wp.i18n.__;
	const _n = wp.i18n._n;
	const sprintf = wp.i18n.sprintf;
	let isProcessing = false;
	let cancelRequested = false;
	let templateData = null; // Cache for template title/slug
	let availableTemplates = []; // Cache for template list
	let highlightedIndex = -1; // For keyboard navigation
	let resultsData = []; // Store results for filtering/export
	let processingStartTime = null; // Track when processing started
	let batchTimings = []; // Track timing for each batch to estimate remaining time
	let savePreferencesTimeout = null; // Debounce timer for saving preferences
	let templateSearchTimeout = null;
	let templateRequest = null;
	let currentOperationId = null;

	function createOperationId() {
		if (window.crypto && typeof window.crypto.randomUUID === "function") {
			return window.crypto.randomUUID();
		}

		return Date.now().toString(36) + "-" + Math.random().toString(36).slice(2);
	}

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
		$("#copy-featured-image").prop("checked", prefs.copy_featured_image !== false);

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
					replace_seo: $("#replace-seo").is(":checked").toString(),
					copy_featured_image: $("#copy-featured-image").is(":checked").toString()
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
		$("#replace-title, #replace-slug, #replace-content, #replace-elementor, #replace-seo, #copy-featured-image").on("change", function() {
			saveUserPreferences();
		});
	}

	// Request notification permission on page load
	function requestNotificationPermission() {
		if ("Notification" in window && Notification.permission === "default") {
			Notification.requestPermission();
		}
	}
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
			const roundedSeconds = Math.round(seconds);
			return sprintf(_n("%d second", "%d seconds", roundedSeconds, "bulk-page-duplicator"), roundedSeconds);
		} else if (seconds < 3600) {
			const mins = Math.floor(seconds / 60);
			const secs = Math.round(seconds % 60);
			let output = sprintf(_n("%d min", "%d mins", mins, "bulk-page-duplicator"), mins);
			if (secs > 0) {
				output += " " + sprintf(_n("%d sec", "%d secs", secs, "bulk-page-duplicator"), secs);
			}
			return output;
		} else {
			const hours = Math.floor(seconds / 3600);
			const mins = Math.round((seconds % 3600) / 60);
			let output = sprintf(_n("%d hour", "%d hours", hours, "bulk-page-duplicator"), hours);
			if (mins > 0) {
				output += " " + sprintf(_n("%d min", "%d mins", mins, "bulk-page-duplicator"), mins);
			}
			return output;
		}
	}

	// Calculate estimated time remaining
	function calculateETA(processedCount, totalCount) {
		if (batchTimings.length === 0 || processedCount === 0) {
			return __("Calculating...", "bulk-page-duplicator");
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

	// Helper function to get all checked replace options
	function getReplaceOptions() {
		const replaceOptions = [];
		if ($("#replace-title").is(":checked")) replaceOptions.push("title");
		if ($("#replace-slug").is(":checked")) replaceOptions.push("slug");
		if ($("#replace-content").is(":checked")) replaceOptions.push("content");
		if ($("#copy-featured-image").is(":checked")) replaceOptions.push("featured_image");
		// Dynamically get page builder options
		$("input[id^='replace-']:checked").each(function () {
			const id = $(this).attr("id").replace("replace-", "");
			// Skip already added and special cases
			if (!replaceOptions.includes(id) && id !== "title" && id !== "slug" && id !== "content" && id !== "seo") {
				replaceOptions.push(id);
			}
		});
		if ($("#replace-seo").is(":checked")) replaceOptions.push("seo");
		return replaceOptions;
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
		if (!template) {
			requestTemplates($("#post-type").val(), "", {
				templateId: templateId,
				onSuccess: function (posts) {
					if (posts.length > 0) {
						availableTemplates = availableTemplates.concat(posts.filter(function (post) {
							return !availableTemplates.some(existing => existing.id == post.id);
						}));
						selectTemplate(templateId);
					}
				}
			});
			return;
		}

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
		$('#template-modified').text(sprintf(__("Modified: %s", "bulk-page-duplicator"), template.modified));
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

	function requestTemplates(postType, search, options = {}) {
		const isAbortable = options.abortPrevious !== false;
		if (templateRequest && isAbortable) {
			templateRequest.abort();
		}

		const request = $.ajax({
			url: bulk_page_dup_ajax.ajax_url,
			type: "POST",
			data: {
				action: "bpd_get_posts_by_type",
				nonce: bulk_page_dup_ajax.nonce,
				post_type: postType,
				search: search || "",
				paged: options.page || 1,
				per_page: options.perPage || 50,
				template_id: options.templateId || 0
			},
			success: function (response) {
				if (response.success && typeof options.onSuccess === "function") {
					options.onSuccess(response.data.posts, response.data);
				} else if (!response.success && typeof options.onError === "function") {
					options.onError(response.data);
				}
			},
			error: function (xhr, status) {
				if (status !== "abort" && typeof options.onError === "function") {
					options.onError();
				}
			},
			complete: function () {
				if (isAbortable && templateRequest === request) {
					templateRequest = null;
				}
				if (typeof options.onComplete === "function") {
					options.onComplete();
				}
			}
		});

		if (isAbortable) {
			templateRequest = request;
		}
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
		clearTimeout(templateSearchTimeout);
		templateSearchTimeout = setTimeout(function () {
			requestTemplates($("#post-type").val(), query, {
				onSuccess: function (posts) {
					availableTemplates = posts;
					renderTemplateDropdown("");
				}
			});
		}, 250);
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
				'<strong>Warning:</strong> Placeholder "' + escapeHtml(notFound.join('", "')) + '" not found in template content. ' +
				__("Make sure it exists in the title, slug, or content.", "bulk-page-duplicator")
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
		$count.text(sprintf(_n("%d item to create", "%d items to create", lines.length, "bulk-page-duplicator"), lines.length));
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
			let duplicateMessage = sprintf(
				__('Duplicate values found: "%s"', "bulk-page-duplicator"),
				duplicates.slice(0, 3).join('", "')
			);
			if (duplicates.length > 3) {
				duplicateMessage += " " + sprintf(__("and %d more", "bulk-page-duplicator"), duplicates.length - 3);
			}
			warnings.push(duplicateMessage);
		}

		// Check for long slugs (>50 chars could cause issues)
		const longValues = lines.filter(line => {
			const slug = toSlug(line.split(',')[0].trim());
			return slug.length > 50;
		});

		if (longValues.length > 0) {
			$slugWarning.text(sprintf(_n("%d value may create a long slug (>50 chars)", "%d values may create long slugs (>50 chars)", longValues.length, "bulk-page-duplicator"), longValues.length)).show();
		}

		// Check for empty lines in middle of text
		const hasEmptyInMiddle = value.split("\n").some((line, index, arr) => {
			return line.trim() === "" && index > 0 && index < arr.length - 1 && arr.slice(index + 1).some(l => l.trim() !== "");
		});

		if (hasEmptyInMiddle) {
			warnings.push(__("Empty lines found between values (they will be skipped)", "bulk-page-duplicator"));
		}

		if (warnings.length > 0) {
			$textarea.addClass("has-warning");
			$validation.addClass("warning").html(
				'<strong>Warning:</strong> ' + escapeHtml(warnings.join('. ')) + '.'
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
		$('#template-search').prop('disabled', true).attr('placeholder', __("Loading templates...", "bulk-page-duplicator"));
		$loading.show();

		requestTemplates(postType, "", {
			onSuccess: function (posts, data) {
				availableTemplates = posts;
				if (data.is_hierarchical) {
					$parentSection.show();
					loadParentOptions(postType, 1, true);
				} else {
					$parentSection.hide();
				}
			},
			onError: function (message) {
				alert(message || __("Error loading templates. Please try again.", "bulk-page-duplicator"));
			},
			onComplete: function () {
				$('#template-search').prop('disabled', false).attr('placeholder', __("Type to search templates...", "bulk-page-duplicator"));
				$loading.hide();
			}
		});

		// Load taxonomies for the post type
		loadTaxonomies(postType);
	});

	function loadParentOptions(postType, page, reset) {
		const $parentSelect = $("#parent-page");
		if (reset) {
			$parentSelect.empty();
			$parentSelect.append($("<option>", { value: "0", text: __("No parent (top level)", "bulk-page-duplicator") }));
			$parentSelect.append($("<option>", { value: "template", text: __("Same as template", "bulk-page-duplicator") }));
		}

		requestTemplates(postType, "", {
			page: page,
			perPage: 100,
			abortPrevious: false,
			onSuccess: function (posts, data) {
				posts.forEach(function (post) {
					$parentSelect.append($("<option>", { value: post.id, text: post.title }));
				});
				if (data.has_more) {
					loadParentOptions(postType, page + 1, false);
				}
			}
		});
	}

	// Function to load taxonomies for a post type
	function loadTaxonomies(postType, page = 1) {
		const $section = $("#taxonomy-section");
		const $loading = $("#taxonomy-loading");
		const $list = $("#taxonomy-list");

		$loading.show();
		if (page === 1) {
			$list.empty();
		}

		$.ajax({
			url: bulk_page_dup_ajax.ajax_url,
			type: "POST",
			data: {
				action: "bpd_get_taxonomies",
				nonce: bulk_page_dup_ajax.nonce,
				post_type: postType,
				paged: page,
				per_page: 100
			},
			success: function (response) {
				if (response.success && response.data.taxonomies.length > 0) {
					$section.show();

					response.data.taxonomies.forEach(function (taxonomy) {
						let $group = $list.find('.taxonomy-group[data-taxonomy="' + taxonomy.name + '"]');
						if ($group.length === 0) {
							$group = $("<div>", { class: "taxonomy-group" }).attr("data-taxonomy", taxonomy.name);
							$group.append($("<h4>").text(taxonomy.label));
							$group.append($("<div>", { class: "taxonomy-terms" }));
							$list.append($group);
						}

						const $terms = $group.find(".taxonomy-terms");
						taxonomy.terms.forEach(function (term) {
							const $label = $("<label>");
							$label.append($("<input>", {
								type: "checkbox",
								class: "taxonomy-term",
								value: term.id
							}).attr("data-taxonomy", taxonomy.name));
							$label.append(document.createTextNode(" " + term.name));
							$terms.append($label);
						});

						if (page === 1 && taxonomy.terms.length === 0) {
							$terms.append($("<p>", { class: "taxonomy-empty", text: __("No terms available", "bulk-page-duplicator") }));
						}
					});

					if (response.data.has_more) {
						loadTaxonomies(postType, page + 1);
					} else {
						$loading.hide();
					}
				} else {
					$loading.hide();
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

		$('#template-search').prop('disabled', true).attr('placeholder', __("Loading templates...", "bulk-page-duplicator"));
		$loading.show();

		requestTemplates(postType, "", {
			onSuccess: function (posts, data) {
				availableTemplates = posts;
				if (data.is_hierarchical) {
					loadParentOptions(postType, 1, true);
				}
			},
			onComplete: function () {
				$('#template-search').prop('disabled', false).attr('placeholder', __("Type to search templates...", "bulk-page-duplicator"));
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
			alert(__("Please upload a CSV or TXT file.", "bulk-page-duplicator"));
			return;
		}

		const reader = new FileReader();
		reader.onload = function (e) {
			const content = e.target.result;
			const lines = parseCSV(content);

			if (lines.length === 0) {
				alert(__("The file appears to be empty.", "bulk-page-duplicator"));
				return;
			}

			// Populate textarea
			$("#replacement-values").val(lines.join("\n"));

			// Show preview
			$csvFileName.text(file.name);
			$csvRowCount.text(sprintf(_n("%d value loaded", "%d values loaded", lines.length, "bulk-page-duplicator"), lines.length));
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
			$csvRowCount.text(sprintf(_n("%d value", "%d values", lines.length, "bulk-page-duplicator"), lines.length));
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
			alert(__("Please select a template.", "bulk-page-duplicator"));
			return;
		}

		if (placeholders.length === 0) {
			alert(__("Please enter at least one placeholder.", "bulk-page-duplicator"));
			return;
		}

		if (rawValues.length === 0) {
			alert(__("Please enter at least one replacement value.", "bulk-page-duplicator"));
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

		processDryRunBatches({
			templateId: templateId,
			placeholders: placeholders,
			values: values,
			postType: $("#post-type").val(),
			parentPage: $("#parent-page").val() || "0",
			replaceOptions: replaceOptions
		}, 0, []);
	});

	function processDryRunBatches(config, startIndex, items) {
		const batchSize = 100;
		const currentBatch = config.values.slice(startIndex, startIndex + batchSize);

		$.ajax({
			url: bulk_page_dup_ajax.ajax_url,
			type: "POST",
			data: {
				action: "bpd_dry_run",
				nonce: bulk_page_dup_ajax.nonce,
				template_id: config.templateId,
				placeholders: config.placeholders,
				values: currentBatch,
				post_type: config.postType,
				parent_page: config.parentPage,
				replace_options: config.replaceOptions
			},
			success: function (response) {
				if (!response.success) {
					alert(sprintf(__("Error: %s", "bulk-page-duplicator"), response.data));
					$("#dry-run-modal").hide();
					return;
				}

				items = items.concat(response.data.items);
				const nextIndex = startIndex + currentBatch.length;
				if (nextIndex < config.values.length) {
					processDryRunBatches(config, nextIndex, items);
					return;
				}

				const seenSlugs = new Set();
				items.forEach(function (item) {
					if (item.status === "create" && seenSlugs.has(item.slug)) {
						item.status = "skip";
						item.reason = sprintf(__("Slug \"%s\" is duplicated in these values", "bulk-page-duplicator"), item.slug);
					}
					if (item.status === "create") {
						seenSlugs.add(item.slug);
					}
				});

				displayDryRunResults({
					items: items,
					summary: {
						total: items.length,
						will_create: items.filter(item => item.status === "create").length,
						will_skip: items.filter(item => item.status !== "create").length
					}
				});
			},
			error: function () {
				alert(__("Error performing dry run. Please try again.", "bulk-page-duplicator"));
				$("#dry-run-modal").hide();
			}
		});
	}

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
			const statusText = item.status === 'create' ? __("Create", "bulk-page-duplicator") : __("Skip", "bulk-page-duplicator");

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
			$("#dry-run-proceed").prop("disabled", true).text(__("Nothing to create", "bulk-page-duplicator"));
		} else {
			$("#dry-run-proceed").prop("disabled", false).text(
				sprintf(__("Proceed with Duplication (%d items)", "bulk-page-duplicator"), data.summary.will_create)
			);
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
		requestNotificationPermission();

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
			alert(__("Please select a template.", "bulk-page-duplicator"));
			return;
		}

		if (placeholders.length === 0) {
			alert(__("Please enter at least one placeholder.", "bulk-page-duplicator"));
			return;
		}

		if (rawValues.length === 0) {
			alert(__("Please enter at least one replacement value.", "bulk-page-duplicator"));
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
					sprintf(
						__("Each line must have %1$d comma-separated values (one for each placeholder).\n\nPlaceholders: %2$s", "bulk-page-duplicator"),
						placeholders.length,
						placeholders.join(", ")
					)
				);
				return;
			}
		}

		// Confirm if a large number of pages will be created
		if (
			values.length > 50 &&
			!confirm(
				sprintf(__("You are about to create %d pages. Continue?", "bulk-page-duplicator"), values.length)
			)
		) {
			return;
		}

		// Get replacement options
		const replaceOptions = getReplaceOptions();

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
		currentOperationId = createOperationId();
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
		$(this).text(__("Cancelling...", "bulk-page-duplicator"));
		$(".bulk-page-dup-status-text").text(__("Cancelling the operation...", "bulk-page-duplicator"));
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
			finishProcessing(__("Operation cancelled by user.", "bulk-page-duplicator"));
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
		$(".bulk-page-dup-status-text").text(__("Processing pages...", "bulk-page-duplicator"));

		// Update ETA
		const eta = calculateETA(processedValues, totalValues);
		$("#progress-eta").text(processedValues > 0 ? sprintf(__("Estimated time remaining: %s", "bulk-page-duplicator"), eta) : "");

		// Get current batch of values
		const batchSize = 10;
		const startIndex = batchIndex;
		const endIndex = Math.min(startIndex + batchSize, totalValues);
		const currentBatch = allValues.slice(startIndex, endIndex);

		// Show current item being processed
		if (currentBatch.length > 0) {
			const currentItemName = Array.isArray(currentBatch[0]) ? currentBatch[0][0] : currentBatch[0];
			$("#progress-current-item").text(sprintf(__("Processing: %s...", "bulk-page-duplicator"), currentItemName));
		}

		// If we've processed all values, finish
		if (startIndex >= totalValues) {
			finishProcessing(__("All pages have been processed successfully!", "bulk-page-duplicator"));
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
					batch_index: batchIndex,
					operation_id: currentOperationId,
					operation_complete: endIndex >= totalValues ? "true" : "false"
			},
			success: function (response) {
				if (response.success) {
					// Log results
					if (response.data.results && response.data.results.length > 0) {
						response.data.results.forEach(function (result) {
							// Store result for filtering/export
							resultsData.push(result);

							const allowedStatuses = ["success", "skipped", "error"];
							const safeStatus = allowedStatuses.includes(result.status) ? result.status : "error";
							const $entry = $("<div>", {
								class: "bulk-page-dup-log-entry bulk-page-dup-log-" + safeStatus
							}).attr("data-status", safeStatus);
							$entry.text(String(result.value || "") + ": " + String(result.message || ""));

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

							$(".bulk-page-dup-log").prepend($entry);
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
							__("All pages have been processed successfully!", "bulk-page-duplicator")
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
					$("<div>", {
						class: "bulk-page-dup-log-entry bulk-page-dup-log-error",
						text: sprintf(__("Error: %s", "bulk-page-duplicator"), String(response.data || ""))
					}).prependTo(".bulk-page-dup-log");
					finishProcessing(__("An error occurred during processing.", "bulk-page-duplicator"));
				}
			},
			error: function (xhr, status, error) {
				$("<div>", {
					class: "bulk-page-dup-log-entry bulk-page-dup-log-error",
					text: sprintf(__("AJAX Error: %s", "bulk-page-duplicator"), String(error || ""))
				}).prependTo(".bulk-page-dup-log");
				finishProcessing(__("An error occurred during processing.", "bulk-page-duplicator"));
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
			$(".bulk-page-dup-status-text").text(
				sprintf(__("%1$s (Completed in %2$s)", "bulk-page-duplicator"), message, formatTime(totalTime))
			);

			// Show browser notification
			showNotification(
				__("Bulk Page Duplicator", "bulk-page-duplicator"),
				sprintf(__("Duplication complete! %s", "bulk-page-duplicator"), formatTime(totalTime))
			);
		}

		if (cancelRequested) {
			$("#cancel-duplication").text(__("Cancel", "bulk-page-duplicator"));
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
			alert(__("No results to export.", "bulk-page-duplicator"));
			return;
		}

		function csvCell(value) {
			let text = String(value || "");
			if (/^[\s]*[=+\-@]/.test(text)) {
				text = "'" + text;
			}
			return '"' + text.replace(/"/g, '""') + '"';
		}

		// Build CSV content while preventing spreadsheet formula execution.
		const headers = [
			__("Value", "bulk-page-duplicator"),
			__("Status", "bulk-page-duplicator"),
			__("Message", "bulk-page-duplicator"),
			__("Edit URL", "bulk-page-duplicator")
		];
		const rows = resultsData.map(r => [
			csvCell(r.value),
			csvCell(r.status),
			csvCell(r.message),
			csvCell(r.edit_url)
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
		URL.revokeObjectURL(url);
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
						const countText = item.existing_count === item.total_count
							? sprintf(_n("%d item", "%d items", item.total_count, "bulk-page-duplicator"), item.total_count)
							: sprintf(__("%1$d of %2$d remain", "bulk-page-duplicator"), item.existing_count, item.total_count);

						const $item = $("<div>", { class: "bulk-page-dup-history-item" }).attr("data-key", item.key);
						const $header = $("<div>", { class: "history-item-header" });
						const $info = $("<div>", { class: "history-item-info" });
						$info.append($("<div>", {
							class: "history-item-title",
							text: sprintf(__("Template: %s", "bulk-page-duplicator"), item.template_title)
						}));
						const $meta = $("<div>", { class: "history-item-meta" });
						$meta.append($("<span>", { text: item.date }));
						$meta.append($("<span>", { text: item.post_type_label }));
						$info.append($meta);
						$header.append($info);
						$header.append($("<span>", { class: "history-item-count " + countClass, text: countText }));
						$item.append($header);

						const $actions = $("<div>", { class: "history-item-actions" });
						$actions.append($("<button>", {
							type: "button",
							class: "button button-small rollback-btn",
							disabled: !item.can_rollback,
							text: __("Rollback (Delete All)", "bulk-page-duplicator")
						}).attr("data-key", item.key).attr("data-count", item.existing_count));
						$item.append($actions);
						$list.append($item);
					});
				} else {
					$empty.show();
				}
			},
			error: function () {
				$loading.hide();
				$empty.text(__("Error loading history.", "bulk-page-duplicator")).show();
			}
		});
	}

	// Handle rollback button click
	$(document).on("click", ".rollback-btn", function () {
		const $btn = $(this);
		const sessionKey = $btn.data("key");
		const count = $btn.data("count");

		if (!confirm(sprintf(__("Are you sure you want to delete %d items? This action cannot be undone.", "bulk-page-duplicator"), count))) {
			return;
		}

		$btn.prop("disabled", true).text(__("Deleting...", "bulk-page-duplicator"));

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
					$btn.prop("disabled", false).text(__("Rollback (Delete All)", "bulk-page-duplicator"));
					alert(sprintf(__("Error: %s", "bulk-page-duplicator"), response.data));
				}
			},
			error: function () {
				$btn.prop("disabled", false).text(__("Rollback (Delete All)", "bulk-page-duplicator"));
				alert(__("An error occurred. Please try again.", "bulk-page-duplicator"));
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
