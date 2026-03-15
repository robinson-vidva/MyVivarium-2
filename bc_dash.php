<?php

/**
 * Breeding Cage Dashboard Script
 *
 * This script displays a dashboard for managing breeding cages. It starts a session, checks if the user is logged in,
 * and includes the necessary header and database connection files. The HTML part of the script includes the structure
 * for displaying breeding cages, search functionality, and actions such as adding a new cage or printing cage cards.
 * The script uses JavaScript for handling search, pagination, and confirmation dialogs.
 *
 */

// Start a new session or resume the existing session
require 'session_config.php';

// Include the database connection file
require 'dbcon.php';

// Check if the user is not logged in, redirect them to index.php with the current URL for redirection after login
if (!isset($_SESSION['username'])) {
    $currentUrl = urlencode($_SERVER['REQUEST_URI']);
    header("Location: index.php?redirect=$currentUrl");
    exit; // Exit to ensure no further code is executed
}

// Include the header file
require 'header.php';
?>

<!doctype html>
<html lang="en">

<head>
    <!-- Required meta tags for responsive design -->
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <!-- FontAwesome for icons -->
    <!-- Font Awesome loaded via header.php -->
    <!-- Bootstrap 5 CSS is already loaded via header.php -->

    <script>
        // State variables for pagination, sorting, archive filtering, and visible columns
        var currentLimit = 10;
        var currentSort = 'cage_id_asc';
        var showArchived = '0';
        // Available optional columns for breeding dashboard (max 2 visible at a time)
        var allColumns = ['cross', 'male_female'];
        var visibleColumns = ['cross']; // default: show cross only

        // Initialize tooltips when the document is ready
        var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'))
        var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
          return new bootstrap.Tooltip(tooltipTriggerEl)
        })

        // Confirm archive function with a dialog
        function confirmDeletion(id) {
            var confirmAction = confirm("Are you sure you want to archive cage '" + id + "'?");
            if (confirmAction) {
                window.location.href = "bc_drop.php?id=" + id + "&action=archive&confirm=true";
            }
        }

        // Confirm restore function
        function confirmRestore(id) {
            var confirmAction = confirm("Restore cage '" + id + "' back to active?");
            if (confirmAction) {
                window.location.href = "bc_drop.php?id=" + id + "&action=restore&confirm=true";
            }
        }

        // Confirm permanent delete function
        function confirmPermanentDelete(id) {
            var confirmAction = confirm("PERMANENTLY delete cage '" + id + "' and ALL related data?\n\nThis action CANNOT be undone.");
            if (confirmAction) {
                var doubleConfirm = confirm("Are you absolutely sure? This will permanently remove all data for cage '" + id + "'.");
                if (doubleConfirm) {
                    window.location.href = "bc_drop.php?id=" + id + "&action=permanent_delete&confirm=true";
                }
            }
        }

        // Fetch data function to load data dynamically
        function fetchData(page = 1, search = '') {
            var xhr = new XMLHttpRequest();
            var url = 'bc_fetch_data.php?page=' + page
                + '&search=' + encodeURIComponent(search)
                + '&limit=' + currentLimit
                + '&sort=' + currentSort
                + '&show_archived=' + showArchived
                + '&columns=' + encodeURIComponent(visibleColumns.join(','));
            xhr.open('GET', url, true);
            xhr.onload = function() {
                if (xhr.status === 200) {
                    try {
                        var response = JSON.parse(xhr.responseText);
                        if (response.tableRows && response.paginationLinks) {
                            document.getElementById('tableBody').innerHTML = response.tableRows;
                            document.getElementById('paginationLinks').innerHTML = response.paginationLinks;
                            document.getElementById('searchInput').value = search;

                            // Show search result info
                            var infoEl = document.getElementById('searchResultInfo');
                            if (search && search.trim() !== '') {
                                var count = response.totalRecords || 0;
                                if (count === 0) {
                                    infoEl.innerHTML = '<span class="text-warning"><i class="fas fa-exclamation-circle"></i> No results found for "<strong>' + search.replace(/</g, '&lt;') + '</strong>"</span>';
                                } else {
                                    infoEl.innerHTML = '<span class="text-muted"><i class="fas fa-check-circle"></i> ' + count + ' cage' + (count !== 1 ? 's' : '') + ' found</span>';
                                }
                                infoEl.style.display = 'block';
                            } else {
                                infoEl.style.display = 'none';
                            }

                            // Re-initialize tooltips on dynamically loaded content
                            document.querySelectorAll('#tableBody [data-bs-toggle="tooltip"]').forEach(function(el) {
                                new bootstrap.Tooltip(el);
                            });

                            // Update the URL with all current parameters
                            const newUrl = new URL(window.location.href);
                            newUrl.searchParams.set('page', page);
                            newUrl.searchParams.set('search', search);
                            newUrl.searchParams.set('limit', currentLimit);
                            newUrl.searchParams.set('sort', currentSort);
                            newUrl.searchParams.set('show_archived', showArchived);
                            newUrl.searchParams.set('columns', visibleColumns.join(','));
                            // Update table headers based on visible columns
                            updateTableHeaders();
                            window.history.replaceState({
                                path: newUrl.href
                            }, '', newUrl.href);
                        } else {
                            console.error('Invalid response format:', response);
                        }
                    } catch (e) {
                        console.error('Error parsing JSON response:', e);
                    }
                } else {
                    console.error('Request failed. Status:', xhr.status);
                }
            };
            xhr.onerror = function() {
                console.error('Request failed. An error occurred during the transaction.');
            };
            xhr.send();
        }

        // Change page size and re-fetch from page 1
        function changeLimit(newLimit) {
            currentLimit = parseInt(newLimit);
            var searchQuery = document.getElementById('searchInput').value;
            fetchData(1, searchQuery);
        }

        // Change sort and re-fetch
        function changeSort(value) {
            currentSort = value;
            var searchQuery = document.getElementById('searchInput').value;
            fetchData(1, searchQuery);
        }

        // Toggle a column on/off (max 2 optional columns)
        function toggleColumn(col, checkbox) {
            if (checkbox.checked) {
                if (visibleColumns.length >= 2) {
                    checkbox.checked = false;
                    alert('Maximum 2 columns allowed. Uncheck one first.');
                    return;
                }
                visibleColumns.push(col);
            } else {
                visibleColumns = visibleColumns.filter(function(c) { return c !== col; });
            }
            var searchQuery = document.getElementById('searchInput').value;
            fetchData(1, searchQuery);
        }

        // Update table headers to match visible columns
        function updateTableHeaders() {
            var columnLabels = { 'cross': 'Cross', 'male_female': 'Male / Female' };
            var headerRow = document.querySelector('#mouseTable thead tr');
            // Rebuild: Cage ID + visible columns + Action
            var html = '<th>Cage ID</th>';
            visibleColumns.forEach(function(col) {
                html += '<th>' + (columnLabels[col] || col) + '</th>';
            });
            html += '<th style="width: 220px;">Action</th>';
            headerRow.innerHTML = html;
        }

        // Sync column checkboxes with state
        function syncColumnCheckboxes() {
            document.querySelectorAll('.col-toggle-check').forEach(function(cb) {
                cb.checked = visibleColumns.indexOf(cb.value) !== -1;
            });
        }

        // Toggle archive view and re-fetch
        function toggleArchive() {
            showArchived = (showArchived === '0') ? '1' : '0';
            var btn = document.getElementById('archiveToggleBtn');
            if (showArchived === '1') {
                btn.innerHTML = '<i class="fas fa-box-open me-1"></i> Show Active';
                btn.classList.remove('btn-outline-secondary');
                btn.classList.add('btn-outline-warning');
            } else {
                btn.innerHTML = '<i class="fas fa-archive me-1"></i> Show Archived';
                btn.classList.remove('btn-outline-warning');
                btn.classList.add('btn-outline-secondary');
            }
            var searchQuery = document.getElementById('searchInput').value;
            fetchData(1, searchQuery);
        }

        // Search function with debounce to avoid excessive requests
        var searchTimeout = null;
        function searchCages() {
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(function() {
                var searchQuery = document.getElementById('searchInput').value;
                fetchData(1, searchQuery);
            }, 300);
        }

        // Fetch initial data when the DOM content is loaded
        document.addEventListener('DOMContentLoaded', function() {
            const urlParams = new URLSearchParams(window.location.search);
            const page = urlParams.get('page') || 1;
            const search = urlParams.get('search') || '';
            currentLimit = parseInt(urlParams.get('limit')) || 10;
            currentSort = urlParams.get('sort') || 'cage_id_asc';
            showArchived = urlParams.get('show_archived') || '0';
            var colsParam = urlParams.get('columns');
            if (colsParam) {
                visibleColumns = colsParam.split(',').filter(function(c) { return allColumns.indexOf(c) !== -1; });
            }

            // Sync UI controls with URL params
            document.getElementById('pageSizeSelect').value = currentLimit;
            document.getElementById('sortSelect').value = currentSort;
            syncColumnCheckboxes();

            if (showArchived === '1') {
                var btn = document.getElementById('archiveToggleBtn');
                btn.innerHTML = '<i class="fas fa-box-open me-1"></i> Show Active';
                btn.classList.remove('btn-outline-secondary');
                btn.classList.add('btn-outline-warning');
            }

            fetchData(page, search);
        });
    </script>


    <title>Dashboard Breeding Cage | <?php echo htmlspecialchars($labName); ?></title>

    <style>
        body {
            margin: 0;
            padding: 0;
            font-family: Arial, sans-serif;
        }

        .container {
            max-width: 900px;
            background-color: var(--bs-tertiary-bg);
            padding: 20px;
            border-radius: 8px;
            margin-top: 20px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }

        .table-wrapper {
            margin-bottom: 50px;
            overflow-x: auto;
        }

        /* Action icon/button styles handled by unified styles in header.php */

        @media (max-width: 768px) {

            .table-wrapper th,
            .table-wrapper td {
                padding: 12px 8px;
                text-align: center;
            }
        }
    </style>
</head>

<body>
    <div class="container content mt-4">
        <!-- Include message for user notifications -->
        <?php include('message.php'); ?>
        <div class="row">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-header d-flex flex-column flex-md-row justify-content-between align-items-center">
                        <h1 class="mb-0">Breeding Cage Dashboard</h1>
                        <div class="action-icons mt-3 mt-md-0">
                            <!-- Add new cage button with tooltip -->
                            <a href="bc_addn.php" class="btn btn-primary btn-icon" data-bs-toggle="tooltip" data-bs-placement="top" title="Add New Cage">
                                <i class="fas fa-plus"></i>
                            </a>
                            <!-- Print cage card button with tooltip -->
                            <a href="bc_slct_crd.php" class="btn btn-success btn-icon" data-bs-toggle="tooltip" data-bs-placement="top" title="Print Cage Card">
                                <i class="fas fa-print"></i>
                            </a>
                            <!-- Maintenance button with tooltip -->
                            <a href="maintenance.php?from=bc_dash" class="btn btn-warning btn-icon" data-bs-toggle="tooltip" data-bs-placement="top" title="Cage Maintenance">
                                <i class="fas fa-wrench"></i>
                            </a>
                        </div>
                    </div>


                    <div class="card-body">
                        <!-- Breeding Cage Search Box -->
                        <div class="input-group mb-3">
                            <input type="text" id="searchInput" class="form-control" placeholder="Search by Cage ID or Cross" onkeyup="searchCages()">
                            <button class="btn btn-primary" type="button" onclick="searchCages()"><i class="fas fa-search"></i> Search</button>
                        </div>

                        <div id="searchResultInfo" class="mb-2" style="display:none;"></div>

                        <!-- Controls row: page size, sort toggle, archive toggle -->
                        <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
                            <div class="d-flex align-items-center">
                                <label for="pageSizeSelect" class="form-label mb-0 me-2 text-nowrap" style="font-size: 0.875rem;">Show</label>
                                <select id="pageSizeSelect" class="form-select form-select-sm" style="width: auto;" onchange="changeLimit(this.value)">
                                    <option value="10">10</option>
                                    <option value="20">20</option>
                                    <option value="30">30</option>
                                    <option value="50">50</option>
                                </select>
                            </div>
                            <select id="sortSelect" class="form-select form-select-sm" style="width: auto;" onchange="changeSort(this.value)">
                                <option value="cage_id_asc">Cage ID (A-Z)</option>
                                <option value="cage_id_desc">Cage ID (Z-A)</option>
                                <option value="created_at_desc">Date Added (Newest)</option>
                                <option value="created_at_asc">Date Added (Oldest)</option>
                            </select>
                            <div class="dropdown">
                                <button class="btn btn-sm btn-outline-info dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                                    <i class="fas fa-columns me-1"></i> Columns
                                </button>
                                <ul class="dropdown-menu">
                                    <li><label class="dropdown-item"><input type="checkbox" class="col-toggle-check form-check-input me-2" value="cross" checked onchange="toggleColumn('cross', this)"> Cross</label></li>
                                    <li><label class="dropdown-item"><input type="checkbox" class="col-toggle-check form-check-input me-2" value="male_female" onchange="toggleColumn('male_female', this)"> Male / Female</label></li>
                                </ul>
                            </div>
                            <button id="archiveToggleBtn" class="btn btn-sm btn-outline-secondary" onclick="toggleArchive()">
                                <i class="fas fa-archive me-1"></i> Show Archived
                            </button>
                        </div>

                        <div class="table-wrapper" id="tableContainer">
                            <table class="table" id="mouseTable">
                                <thead>
                                    <tr>
                                        <th>Cage ID</th>
                                        <th>Cross</th>
                                        <th style="width: 220px;">Action</th>
                                    </tr>
                                </thead>
                                <tbody id="tableBody">
                                    <!-- Table rows will be inserted here by JavaScript -->
                                </tbody>
                            </table>
                        </div>

                        <!-- Pagination -->
                        <nav aria-label="Page navigation">
                            <ul class="pagination justify-content-center" id="paginationLinks">
                                <!-- Pagination links will be inserted here by JavaScript -->
                            </ul>
                        </nav>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <!-- Include footer -->
    <?php include 'footer.php'; ?>

    <!-- Bootstrap 5 JS and jQuery already loaded via header.php -->
</body>

</html>