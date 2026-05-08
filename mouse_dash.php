<?php

/**
 * Mouse Dashboard
 *
 * Lists every mouse in the colony as a first-class entity, independent of
 * any cage. Always shows Mouse ID / Cage / Status / Actions; lets the
 * user pick up to two optional columns from {Sex, DOB, Age, Genotype}
 * via the Columns dropdown — same pattern hc_dash.php uses, so the
 * 900px container width can stay consistent across dashboards.
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
        .container { max-width: 900px; background-color: var(--bs-tertiary-bg); padding: 20px; border-radius: 8px; margin-top: 20px; box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1); }
        .filter-row { gap: 8px; }
        .table-wrapper { margin-bottom: 50px; overflow-x: auto; }
        @media (max-width: 768px) {
            .table th, .table td { padding: 12px 8px; font-size: 0.875rem; text-align: center; }
        }
    </style>
    <script>
        var currentLimit = 10;
        var currentSort = 'mouse_id_asc';
        var currentStatus = 'alive';
        var currentSex = 'all';
        var currentCage = '';

        // Optional columns (mirrors the hc_dash pattern). Mouse ID / Cage /
        // Status / Actions are always present; users pick up to two
        // additional columns from this list.
        var allColumns = ['sex', 'dob', 'age', 'genotype'];
        var visibleColumns = ['sex', 'age']; // default

        function fetchData(page = 1) {
            var search = document.getElementById('searchInput').value;
            var qs = new URLSearchParams({
                page: page, search: search, limit: currentLimit, sort: currentSort,
                status: currentStatus, sex: currentSex, cage: currentCage,
                columns: visibleColumns.join(',')
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
                    updateTableHeaders();
                    var url = new URL(window.location);
                    url.searchParams.set('page', page);
                    url.searchParams.set('search', search);
                    url.searchParams.set('limit', currentLimit);
                    url.searchParams.set('sort', currentSort);
                    url.searchParams.set('status', currentStatus);
                    url.searchParams.set('sex', currentSex);
                    url.searchParams.set('columns', visibleColumns.join(','));
                    if (currentCage) url.searchParams.set('cage', currentCage); else url.searchParams.delete('cage');
                    history.replaceState({}, '', url.toString());
                });
        }

        // Toggle a column on/off, capped at 2 optional columns.
        function toggleColumn(col, checkbox) {
            if (checkbox.checked) {
                if (visibleColumns.length >= 2) {
                    checkbox.checked = false;
                    alert('Up to 2 optional columns. Uncheck one first.');
                    return;
                }
                visibleColumns.push(col);
            } else {
                visibleColumns = visibleColumns.filter(function (c) { return c !== col; });
            }
            fetchData(1);
        }

        function updateTableHeaders() {
            var labels = { sex: 'Sex', dob: 'DOB', age: 'Age', genotype: 'Genotype' };
            var head = document.querySelector('#miceTable thead tr');
            var html = '<th>Mouse ID</th>';
            visibleColumns.forEach(function (c) { html += '<th>' + (labels[c] || c) + '</th>'; });
            html += '<th>Cage</th><th>Status</th><th class="text-end">Actions</th>';
            head.innerHTML = html;
        }

        function syncColumnCheckboxes() {
            document.querySelectorAll('.col-toggle-check').forEach(function (cb) {
                cb.checked = visibleColumns.indexOf(cb.value) !== -1;
            });
        }

        var searchTimer;
        function onSearch() {
            clearTimeout(searchTimer);
            searchTimer = setTimeout(function () { fetchData(1); }, 300);
        }

        document.addEventListener('DOMContentLoaded', function () {
            var p = new URLSearchParams(window.location.search);
            currentLimit  = parseInt(p.get('limit')) || 10;
            currentSort   = p.get('sort')   || 'mouse_id_asc';
            currentStatus = p.get('status') || 'alive';
            currentSex    = p.get('sex')    || 'all';
            currentCage   = p.get('cage')   || '';
            var colsParam = p.get('columns');
            if (colsParam) {
                visibleColumns = colsParam.split(',').filter(function (c) { return allColumns.indexOf(c) !== -1; });
            }
            document.getElementById('searchInput').value = p.get('search') || '';
            document.getElementById('limitSel').value  = currentLimit;
            document.getElementById('sortSel').value   = currentSort;
            document.getElementById('statusSel').value = currentStatus;
            document.getElementById('sexSel').value    = currentSex;
            document.getElementById('cageFilter').value = currentCage;
            syncColumnCheckboxes();
            updateTableHeaders();
            fetchData(parseInt(p.get('page')) || 1);
        });
    </script>
</head>
<body>
    <div class="container content">
        <?php include 'message.php'; ?>
        <div class="row">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-header d-flex flex-column flex-md-row justify-content-between align-items-center">
                        <h1 class="mb-0">Mice</h1>
                        <div class="action-icons mt-3 mt-md-0">
                            <a href="mouse_addn.php" class="btn btn-primary btn-icon" data-bs-toggle="tooltip" data-bs-placement="top" title="Register New Mouse">
                                <i class="fas fa-plus"></i>
                            </a>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="input-group mb-3">
                            <input type="text" id="searchInput" class="form-control" placeholder="Search by Mouse ID, Genotype, Ear Code, or Cage" onkeyup="onSearch()">
                            <button class="btn btn-primary" type="button" onclick="fetchData(1)"><i class="fas fa-search"></i> Search</button>
                        </div>
                        <div id="searchResultInfo" class="mb-2 text-muted small" style="display:none;"></div>

                        <div class="d-flex flex-wrap align-items-center filter-row mb-3">
                            <div class="d-flex align-items-center">
                                <label for="limitSel" class="form-label mb-0 me-2 text-nowrap" style="font-size: 0.875rem;">Show</label>
                                <select id="limitSel" class="form-select form-select-sm" style="width: auto;" onchange="currentLimit=parseInt(this.value); fetchData(1);">
                                    <option value="10">10</option>
                                    <option value="20">20</option>
                                    <option value="30">30</option>
                                    <option value="50">50</option>
                                </select>
                            </div>
                            <select id="statusSel" class="form-select form-select-sm" style="width: auto;" onchange="currentStatus=this.value; fetchData(1);">
                                <option value="all">All statuses</option>
                                <option value="alive">Alive</option>
                                <option value="sacrificed">Sacrificed</option>
                                <option value="transferred_out">Transferred out</option>
                                <option value="archived">Archived</option>
                            </select>
                            <select id="sexSel" class="form-select form-select-sm" style="width: auto;" onchange="currentSex=this.value; fetchData(1);">
                                <option value="all">All sexes</option>
                                <option value="male">Male</option>
                                <option value="female">Female</option>
                                <option value="unknown">Unknown</option>
                            </select>
                            <select id="sortSel" class="form-select form-select-sm" style="width: auto;" onchange="currentSort=this.value; fetchData(1);">
                                <option value="mouse_id_asc">Mouse ID (A-Z)</option>
                                <option value="mouse_id_desc">Mouse ID (Z-A)</option>
                                <option value="dob_desc">DOB (Newest)</option>
                                <option value="dob_asc">DOB (Oldest)</option>
                                <option value="created_desc">Added (Newest)</option>
                                <option value="cage_asc">Cage (A-Z)</option>
                            </select>
                            <input type="text" id="cageFilter" class="form-control form-control-sm" placeholder="Filter by cage…" style="width: 140px;"
                                   onchange="currentCage=this.value.trim(); fetchData(1);">
                            <div class="dropdown">
                                <button class="btn btn-sm btn-outline-info dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                                    <i class="fas fa-columns me-1"></i> Columns
                                </button>
                                <ul class="dropdown-menu">
                                    <li><label class="dropdown-item"><input type="checkbox" class="col-toggle-check form-check-input me-2" value="sex"      onchange="toggleColumn('sex', this)"> Sex</label></li>
                                    <li><label class="dropdown-item"><input type="checkbox" class="col-toggle-check form-check-input me-2" value="dob"      onchange="toggleColumn('dob', this)"> DOB</label></li>
                                    <li><label class="dropdown-item"><input type="checkbox" class="col-toggle-check form-check-input me-2" value="age"      onchange="toggleColumn('age', this)"> Age</label></li>
                                    <li><label class="dropdown-item"><input type="checkbox" class="col-toggle-check form-check-input me-2" value="genotype" onchange="toggleColumn('genotype', this)"> Genotype</label></li>
                                </ul>
                            </div>
                        </div>

                        <div class="table-wrapper">
                            <table class="table" id="miceTable">
                                <thead><tr></tr></thead>
                                <tbody id="tableBody"></tbody>
                            </table>
                        </div>

                        <nav><ul class="pagination justify-content-center" id="paginationLinks"></ul></nav>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php include 'footer.php'; ?>
</body>
</html>
