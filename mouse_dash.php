<?php

/**
 * Mouse Dashboard
 *
 * Lists every mouse in the colony as a first-class entity, independent of
 * any cage. Filters: status (alive/sacrificed/archived/transferred_out),
 * sex, free-text search across mouse_id/genotype/ear_code/cage. Cage is
 * shown as a link into hc_view.php for the current location, and the row
 * itself links to mouse_view.php.
 */

require 'session_config.php';
require 'dbcon.php';

if (!isset($_SESSION['username'])) {
    $currentUrl = urlencode($_SERVER['REQUEST_URI']);
    header("Location: index.php?redirect=$currentUrl");
    exit;
}

require 'header.php';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Mice | <?= htmlspecialchars($labName); ?></title>
    <style>
        .container { max-width: 1100px; background-color: var(--bs-tertiary-bg); padding: 20px; border-radius: 8px; margin-top: 20px; }
        .filter-row { gap: 8px; }
        .filter-row select, .filter-row input { width: auto; }
        .table-wrapper { overflow-x: auto; }
        @media (max-width: 768px) {
            .table th, .table td { padding: 10px 6px; font-size: 0.875rem; }
        }
    </style>
    <script>
        var currentLimit = 25;
        var currentSort = 'mouse_id_asc';
        var currentStatus = 'alive';
        var currentSex = 'all';
        var currentCage = '';

        function fetchData(page = 1) {
            var search = document.getElementById('searchInput').value;
            var qs = new URLSearchParams({
                page: page, search: search, limit: currentLimit, sort: currentSort,
                status: currentStatus, sex: currentSex, cage: currentCage
            });
            fetch('mouse_fetch_data.php?' + qs.toString())
                .then(r => r.json())
                .then(d => {
                    document.getElementById('tableBody').innerHTML = d.tableRows;
                    document.getElementById('paginationLinks').innerHTML = d.paginationLinks;
                    var info = document.getElementById('searchResultInfo');
                    if (search) {
                        info.textContent = (d.totalRecords || 0) + ' mouse/mice found for "' + search + '"';
                        info.style.display = 'block';
                    } else {
                        info.style.display = 'none';
                    }
                    var url = new URL(window.location);
                    url.searchParams.set('page', page);
                    url.searchParams.set('search', search);
                    url.searchParams.set('limit', currentLimit);
                    url.searchParams.set('sort', currentSort);
                    url.searchParams.set('status', currentStatus);
                    url.searchParams.set('sex', currentSex);
                    if (currentCage) url.searchParams.set('cage', currentCage); else url.searchParams.delete('cage');
                    history.replaceState({}, '', url.toString());
                });
        }

        var searchTimer;
        function onSearch() {
            clearTimeout(searchTimer);
            searchTimer = setTimeout(function () { fetchData(1); }, 300);
        }

        document.addEventListener('DOMContentLoaded', function () {
            var p = new URLSearchParams(window.location.search);
            currentLimit  = parseInt(p.get('limit')) || 25;
            currentSort   = p.get('sort') || 'mouse_id_asc';
            currentStatus = p.get('status') || 'alive';
            currentSex    = p.get('sex') || 'all';
            currentCage   = p.get('cage') || '';
            document.getElementById('searchInput').value = p.get('search') || '';
            document.getElementById('limitSel').value = currentLimit;
            document.getElementById('sortSel').value  = currentSort;
            document.getElementById('statusSel').value = currentStatus;
            document.getElementById('sexSel').value    = currentSex;
            document.getElementById('cageFilter').value = currentCage;
            fetchData(parseInt(p.get('page')) || 1);
        });
    </script>
</head>
<body>
    <div class="container content">
        <?php include 'message.php'; ?>
        <div class="card">
            <div class="card-header d-flex flex-column flex-md-row justify-content-between align-items-md-center">
                <h1 class="mb-0">Mice</h1>
                <div class="action-icons mt-2 mt-md-0">
                    <a href="mouse_addn.php" class="btn btn-primary btn-icon" data-bs-toggle="tooltip" title="Register New Mouse">
                        <i class="fas fa-plus"></i>
                    </a>
                </div>
            </div>
            <div class="card-body">
                <div class="input-group mb-3">
                    <input type="text" id="searchInput" class="form-control" placeholder="Search by Mouse ID, Genotype, Ear Code, or Cage" onkeyup="onSearch()">
                    <button class="btn btn-primary" onclick="fetchData(1)"><i class="fas fa-search"></i></button>
                </div>
                <div id="searchResultInfo" class="mb-2 text-muted small" style="display:none;"></div>

                <div class="d-flex flex-wrap align-items-center filter-row mb-3">
                    <select id="statusSel" class="form-select form-select-sm" onchange="currentStatus=this.value; fetchData(1);">
                        <option value="all">All statuses</option>
                        <option value="alive">Alive</option>
                        <option value="sacrificed">Sacrificed</option>
                        <option value="transferred_out">Transferred out</option>
                        <option value="archived">Archived</option>
                    </select>
                    <select id="sexSel" class="form-select form-select-sm" onchange="currentSex=this.value; fetchData(1);">
                        <option value="all">All sexes</option>
                        <option value="male">Male</option>
                        <option value="female">Female</option>
                        <option value="unknown">Unknown</option>
                    </select>
                    <input type="text" id="cageFilter" class="form-control form-control-sm" placeholder="Filter by cage…" style="width: 160px;"
                           onchange="currentCage=this.value.trim(); fetchData(1);">
                    <select id="sortSel" class="form-select form-select-sm" onchange="currentSort=this.value; fetchData(1);">
                        <option value="mouse_id_asc">Mouse ID (A-Z)</option>
                        <option value="mouse_id_desc">Mouse ID (Z-A)</option>
                        <option value="dob_desc">DOB (Newest)</option>
                        <option value="dob_asc">DOB (Oldest)</option>
                        <option value="created_desc">Added (Newest)</option>
                        <option value="cage_asc">Cage (A-Z)</option>
                    </select>
                    <select id="limitSel" class="form-select form-select-sm" onchange="currentLimit=parseInt(this.value); fetchData(1);">
                        <option value="10">10</option>
                        <option value="25">25</option>
                        <option value="50">50</option>
                        <option value="100">100</option>
                    </select>
                </div>

                <div class="table-wrapper">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>Mouse ID</th>
                                <th>Sex</th>
                                <th>DOB</th>
                                <th>Age</th>
                                <th>Cage</th>
                                <th>Genotype</th>
                                <th>Status</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody id="tableBody"></tbody>
                    </table>
                </div>

                <nav><ul class="pagination justify-content-center" id="paginationLinks"></ul></nav>
            </div>
        </div>
    </div>
    <?php include 'footer.php'; ?>
</body>
</html>
