// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Adds a two-level spanning header row to the rubric pivot report table.
 *
 * The Report Builder renders a flat <thead> with column names such as
 * "CriterionName - Score". This module:
 *   1. Inserts a group header row above (Student | CriterionName… | Grade summary)
 *   2. Shortens criterion sub-headers to just the sub-column label (Score, etc.)
 *   3. Re-applies after each AJAX table refresh via MutationObserver.
 *
 * Column grouping rules (left-to-right):
 *   - Column 0              → "Student" group (1 column)
 *   - Name contains " - "   → criterion group named by the prefix
 *   - All remaining columns → "Grade summary" group
 *
 * @module     report_rubricgrading/pivot_header
 * @copyright  2025 Marcus Green
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define([], function() {
    'use strict';

    var GROUP_ROW_CLASS = 'rubricpivot-groupheader';

    /**
     * Parse the existing flat header row, inject a spanning group row above it,
     * and strip the criterion-name prefix from individual sub-header cells.
     *
     * @param {HTMLTableElement} table
     */
    var applyGroupHeaders = function(table) {
        var thead = table.querySelector('thead');
        if (!thead) {
            return;
        }

        // Idempotent: remove any previously injected group row.
        var existing = thead.querySelector('.' + GROUP_ROW_CLASS);
        if (existing) {
            existing.parentNode.removeChild(existing);
        }

        var subRow = thead.querySelector('tr');
        if (!subRow) {
            return;
        }

        var cells = Array.from(subRow.querySelectorAll('th, td'));
        if (cells.length === 0) {
            return;
        }

        var groups = [];

        cells.forEach(function(th, idx) {
            var text = th.textContent.trim();
            var sepIdx = text.lastIndexOf(' - ');
            var last = groups[groups.length - 1];

            if (idx === 0) {
                // Always the student identity column.
                groups.push({label: 'Student', count: 1});

            } else if (sepIdx > -1) {
                // Criterion column: "CriterionName - SubColumnLabel"
                var groupName = text.substring(0, sepIdx);
                var colLabel  = text.substring(sepIdx + 3);

                // Shorten the sub-header to just the sub-column label.
                th.textContent = colLabel;

                if (last && last.isCriterion && last.label === groupName) {
                    last.count++;
                } else {
                    groups.push({label: groupName, count: 1, isCriterion: true});
                }

            } else {
                // Non-criterion trailing column → Grade summary.
                if (last && last.isGradeSummary) {
                    last.count++;
                } else {
                    groups.push({label: 'Grade summary', count: 1, isGradeSummary: true});
                }
            }
        });

        // Build and prepend the group row.
        var groupRow = document.createElement('tr');
        groupRow.className = GROUP_ROW_CLASS;

        groups.forEach(function(group) {
            var th = document.createElement('th');
            th.textContent = group.label;
            th.colSpan = group.count;
            groupRow.appendChild(th);
        });

        thead.insertBefore(groupRow, subRow);
    };

    /**
     * Initialise the pivot header enhancement.
     */
    var init = function() {
        var wrapper = document.getElementById('rubricpivot-table-wrap');
        if (!wrapper) {
            return;
        }

        var transform = function() {
            var table = wrapper.querySelector('table');
            if (table) {
                applyGroupHeaders(table);
            }
        };

        // Apply immediately on the server-rendered table.
        transform();

        // Re-apply after AJAX table refreshes (filter / sort / page changes).
        // Skip mutations caused by our own group-row insertion.
        var observer = new MutationObserver(function(mutations) {
            var ownOnly = mutations.every(function(m) {
                return Array.from(m.addedNodes).every(function(n) {
                    return n.nodeType === 1 && n.classList && n.classList.contains(GROUP_ROW_CLASS);
                });
            });
            if (!ownOnly) {
                transform();
            }
        });

        observer.observe(wrapper, {childList: true, subtree: true});
    };

    return {
        init: init
    };
});
