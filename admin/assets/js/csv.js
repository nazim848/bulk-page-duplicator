/**
 * Shared parsing of replacement values for the bulk form and page generators.
 *
 * One line is one item. With several placeholders a line is a CSV record, so a
 * value containing a comma can be wrapped in double quotes ("Paris, France").
 * With a single placeholder the whole line is the value, unless it is quoted.
 */
(function (window) {
	"use strict";

	// Parse one CSV record into trimmed fields.
	function parseRecord(line) {
		const fields = [];
		let field = "";
		let quoted = false;

		for (let i = 0; i < line.length; i++) {
			const ch = line.charAt(i);
			if (quoted) {
				if (ch === '"' && line.charAt(i + 1) === '"') {
					field += '"';
					i++;
				} else if (ch === '"') {
					quoted = false;
				} else {
					field += ch;
				}
			} else if (ch === '"' && field.trim() === "") {
				field = "";
				quoted = true;
			} else if (ch === ",") {
				fields.push(field.trim());
				field = "";
			} else {
				field += ch;
			}
		}

		fields.push(field.trim());
		return fields;
	}

	// Values for one line, given the number of placeholders.
	function parseLine(line, placeholderCount) {
		const text = String(line || "").trim();
		if (placeholderCount > 1) {
			return parseRecord(text);
		}

		if (text.length > 1 && text.charAt(0) === '"' && text.charAt(text.length - 1) === '"') {
			return [parseRecord(text)[0]];
		}

		return [text];
	}

	// Non-empty lines of a textarea or file.
	function getLines(text) {
		return String(text || "").split(/\r?\n/).filter(line => line.trim() !== "");
	}

	// Turn values back into one line, quoting values that need it.
	function formatLine(values) {
		return values.map(function (value) {
			value = String(value);
			return /[",]/.test(value) ? '"' + value.replace(/"/g, '""') + '"' : value;
		}).join(", ");
	}

	// Placeholders typed in a comma-separated field.
	function parsePlaceholders(text) {
		return String(text || "").split(",").map(p => p.trim()).filter(p => p !== "");
	}

	/**
	 * If the first line looks like column names, return them as {{name}} tokens.
	 * A single column only counts when it is a lower-case identifier such as "city".
	 */
	function detectHeaderTokens(firstLine) {
		const cells = parseRecord(String(firstLine || "").trim());
		const identifier = /^[A-Za-z_][A-Za-z0-9 _-]{0,40}$/;

		if (cells.length === 0 || !cells.every(cell => identifier.test(cell))) {
			return null;
		}
		if (cells.length === 1 && !/^[a-z_][a-z0-9_]*$/.test(cells[0])) {
			return null;
		}

		return cells.map(cell => "{{" + cell.trim().toLowerCase().replace(/[\s-]+/g, "_") + "}}");
	}

	window.bpdCsv = {
		parseLine: parseLine,
		getLines: getLines,
		formatLine: formatLine,
		parsePlaceholders: parsePlaceholders,
		detectHeaderTokens: detectHeaderTokens
	};
})(window);
