/**
 * Torrent Browse — Client-Side Table Sort
 *
 * Lightweight vanilla JS for sorting table columns by clicking headers.
 * Works with <th data-sort="column" data-sort-type="string|number|date">
 * and <td data-value="..."> for sort values.
 */
(function () {
    'use strict';

    function initBrowseSort() {
        var table = document.getElementById('tp-browse-table');
        if (!table) return;

        var headers = table.querySelectorAll('th.tp-sortable');
        if (!headers.length) return;

        headers.forEach(function (th) {
            th.style.cursor = 'pointer';
            th.style.userSelect = 'none';

            th.addEventListener('click', function () {
                sortTable(table, th, headers);
            });

            th.addEventListener('keydown', function (e) {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    sortTable(table, th, headers);
                }
            });
        });
    }

    function sortTable(table, clickedTh, allHeaders) {
        var sortKey  = clickedTh.getAttribute('data-sort');
        var sortType = clickedTh.getAttribute('data-sort-type') || 'string';
        var currentDir = clickedTh.getAttribute('aria-sort');
        var newDir = (currentDir === 'ascending') ? 'descending' : 'ascending';

        // Reset all headers
        allHeaders.forEach(function (th) {
            th.classList.remove('tp-sort-asc', 'tp-sort-desc');
            th.setAttribute('aria-sort', 'none');
        });

        // Set active
        clickedTh.setAttribute('aria-sort', newDir);
        clickedTh.classList.add(newDir === 'ascending' ? 'tp-sort-asc' : 'tp-sort-desc');

        // Get column index
        var colIndex = Array.from(clickedTh.parentNode.children).indexOf(clickedTh);

        // Sort rows
        var tbody = table.querySelector('tbody');
        if (!tbody) return;

        var rows = Array.from(tbody.querySelectorAll('tr'));

        rows.sort(function (a, b) {
            var cellA = a.children[colIndex];
            var cellB = b.children[colIndex];
            if (!cellA || !cellB) return 0;

            var valA = cellA.getAttribute('data-value') || cellA.textContent.trim();
            var valB = cellB.getAttribute('data-value') || cellB.textContent.trim();

            var result = 0;

            switch (sortType) {
                case 'number':
                    result = parseFloat(valA || '0') - parseFloat(valB || '0');
                    break;
                case 'date':
                    result = new Date(valA).getTime() - new Date(valB).getTime();
                    break;
                case 'string':
                default:
                    result = valA.localeCompare(valB, undefined, { sensitivity: 'base' });
                    break;
            }

            return newDir === 'ascending' ? result : -result;
        });

        // Re-append sorted rows
        rows.forEach(function (row) {
            tbody.appendChild(row);
        });
    }

    // Initialize on DOM ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initBrowseSort);
    } else {
        initBrowseSort();
    }
})();
