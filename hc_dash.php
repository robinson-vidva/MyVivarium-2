<?php

/**
 * Holding Cage Dashboard Script
 * 
 * This script displays the holding cage dashboard for logged-in users. It includes functionalities such as 
 * adding new cages, printing cage cards, searching cages, and pagination. The page content is dynamically
 * loaded using JavaScript and AJAX.
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
    <!-- Required meta tags for character encoding and responsive design -->
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <!-- FontAwesome for icons -->
    <!-- Font Awesome loaded via header.php -->
    <!-- Bootstrap 5 CSS is already loaded via header.php -->

    <script>
        // State variables for pagination, sorting, and archive filtering
        var currentLimit = 10;
        var currentSort = 'asc';
        var showArchived = '0';

        // Initialize tooltips when the document is ready
        var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'))
        var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
          return new bootstrap.Tooltip(tooltipTriggerEl)
        })

        // Confirm archive function with a dialog
        function confirmDeletion(id) {
            var confirmArchive = confirm("Are you sure you want to archive cage - '" + id + "' and related mouse data?");
            if (confirmArchive) {
                window.location.href = "hc_drop.php?id=" + id + "&action=archive&confirm=true";
            }
        }

        // Fetch data function to load data dynamically
        function fetchData(page = 1, search = '') {
            var xhr = new XMLHttpRequest();
            var url = 'hc_fetch_data.php?page=' + page
                + '&search=' + encodeURIComponent(search)
                + '&limit=' + currentLimit
                + '&sort=' + currentSort
                + '&show_archived=' + showArchived;
            xhr.open('GET', url, true);
            xhr.onload = function() {
                if (xhr.status === 200) {
                    try {
                        var response = JSON.parse(xhr.responseText);
                        if (response.tableRows && response.paginationLinks) {
                            document.getElementById('tableBody').innerHTML = response.tableRows;
                            document.getElementById('paginationLinks').innerHTML = response.paginationLinks;
                            document.getElementById('searchInput').value = search;

                            // Update the URL with all current parameters
                            const newUrl = new URL(window.location.href);
                            newUrl.searchParams.set('page', page);
                            newUrl.searchParams.set('search', search);
                            newUrl.searchParams.set('limit', currentLimit);
                            newUrl.searchParams.set('sort', currentSort);
                            newUrl.searchParams.set('show_archived', showArchived);
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

        // Toggle sort order and re-fetch
        function toggleSort() {
            currentSort = (currentSort === 'asc') ? 'desc' : 'asc';
            // Update sort icon
            var icon = document.getElementById('sortIcon');
            if (currentSort === 'asc') {
                icon.className = 'fas fa-sort-alpha-down';
            } else {
                icon.className = 'fas fa-sort-alpha-up';
            }
            var searchQuery = document.getElementById('searchInput').value;
            fetchData(1, searchQuery);
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
            currentSort = urlParams.get('sort') || 'asc';
            showArchived = urlParams.get('show_archived') || '0';

            // Sync UI controls with URL params
            document.getElementById('pageSizeSelect').value = currentLimit;

            var icon = document.getElementById('sortIcon');
            if (currentSort === 'desc') {
                icon.className = 'fas fa-sort-alpha-up';
            }

            if (showArchived === '1') {
                var btn = document.getElementById('archiveToggleBtn');
                btn.innerHTML = '<i class="fas fa-box-open me-1"></i> Show Active';
                btn.classList.remove('btn-outline-secondary');
                btn.classList.add('btn-outline-warning');
            }

            fetchData(page, search);
        });
    </script>



    <title>Dashboard Holding Cage | <?php echo htmlspecialchars($labName); ?></title>

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

        .btn-sm {
            margin-right: 5px;
        }

        .btn-icon {
            width: 30px;
            height: 30px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 0;
        }

        .btn-icon i {
            font-size: 16px;
            margin: 0;
        }

        .action-icons a {
            margin-right: 10px;
            margin-bottom: 10px;
        }

        .action-icons a:last-child {
            margin-right: 0;
        }

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
        <!-- Include message file for displaying messages -->
        <?php include('message.php'); ?>
        <div class="row">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-header d-flex flex-column flex-md-row justify-content-between align-items-center">
                        <h4>Holding Cage Dashboard</h4>
                        <div class="action-icons mt-3 mt-md-0">
                            <!-- Add new cage button with tooltip -->
                            <a href="hc_addn.php" class="btn btn-primary btn-icon" data-bs-toggle="tooltip" data-bs-placement="top" title="Add New Cage">
                                <i class="fas fa-plus"></i>
                            </a>
                            <!-- Print cage card button with tooltip -->
                            <a href="hc_slct_crd.php" class="btn btn-success btn-icon" data-bs-toggle="tooltip" data-bs-placement="top" title="Print Cage Card">
                                <i class="fas fa-print"></i>
                            </a>
                            <!-- Maintenance button with tooltip -->
                            <a href="maintenance.php?from=hc_dash" class="btn btn-warning btn-icon" data-bs-toggle="tooltip" data-bs-placement="top" title="Cage Maintenance">
                                <i class="fas fa-wrench"></i>
                            </a>
                        </div>
                    </div>


                    <div class="card-body">
                        <!-- Holding Cage Search Box -->
                        <div class="input-group mb-3">
                            <input type="text" id="searchInput" class="form-control" placeholder="Enter Cage ID" onkeyup="searchCages()"> <!-- Call search function on keyup -->
                            <button class="btn btn-primary" type="button" onclick="searchCages()">Search</button>
                        </div>

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
                            <button class="btn btn-sm btn-outline-primary" onclick="toggleSort()" title="Toggle sort order">
                                <i id="sortIcon" class="fas fa-sort-alpha-down"></i> Sort
                            </button>
                            <button id="archiveToggleBtn" class="btn btn-sm btn-outline-secondary" onclick="toggleArchive()">
                                <i class="fas fa-archive me-1"></i> Show Archived
                            </button>
                        </div>

                        <div class="table-wrapper" id="tableContainer">
                            <table class="table table-bordered" id="mouseTable">
                                <thead>
                                    <tr>
                                        <th style="width: 50%;">Cage ID</th>
                                        <th style="width: 50%;">Action</th>
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
    <?php include 'footer.php'; ?> <!-- Include footer file -->

    <!-- Bootstrap 5 JS and jQuery already loaded via header.php -->
</body>

</html>