<?php

/**
 * Mouse data endpoint.
 *
 * One file, three modes (selected by `mode` param) so we keep the related
 * AJAX endpoints discoverable in a single place:
 *
 *   mode=list           GET   - paginated/sortable mouse list for mouse_dash.php
 *   mode=parent_search  GET   - typeahead lookup for the sire/dam pickers in
 *                                mouse_addn.php / mouse_edit.php (sex-filtered)
 *   mode=create_cage    POST  - inline "+ Add new cage" used by Aaron's UX.
 *                                Creates a minimal cage row so the parent form
 *                                can immediately reference it. CSRF-protected.
 */

require 'session_config.php';
require 'dbcon.php';
require_once 'log_activity.php';

header('Content-Type: application/json');

if (!isset($_SESSION['username'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'unauthorized']);
    exit;
}

$mode = $_REQUEST['mode'] ?? 'list';

if ($mode === 'create_cage') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['ok' => false, 'error' => 'POST required']);
        exit;
    }
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== ($_SESSION['csrf_token'] ?? '')) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'CSRF validation failed']);
        exit;
    }
    $cage_id = trim($_POST['cage_id'] ?? '');
    $room    = trim($_POST['room'] ?? '');
    $rack    = trim($_POST['rack'] ?? '');
    if ($cage_id === '') {
        echo json_encode(['ok' => false, 'error' => 'cage_id is required']);
        exit;
    }
    $check = $con->prepare("SELECT 1 FROM cages WHERE cage_id = ?");
    $check->bind_param("s", $cage_id);
    $check->execute();
    if ($check->get_result()->num_rows > 0) {
        echo json_encode(['ok' => false, 'error' => 'Cage ID already exists']);
        exit;
    }
    $check->close();

    $room_v = $room === '' ? null : $room;
    $rack_v = $rack === '' ? null : $rack;
    $ins = $con->prepare("INSERT INTO cages (cage_id, room, rack, status) VALUES (?, ?, ?, 'active')");
    $ins->bind_param("sss", $cage_id, $room_v, $rack_v);
    if ($ins->execute()) {
        log_activity($con, 'create', 'cage', $cage_id, 'Inline-created from mouse form');
        echo json_encode(['ok' => true, 'cage_id' => $cage_id]);
    } else {
        echo json_encode(['ok' => false, 'error' => 'Insert failed']);
    }
    $ins->close();
    exit;
}

if ($mode === 'parent_search') {
    $term = trim($_GET['search'] ?? '');
    $sex  = strtolower(trim($_GET['sex'] ?? ''));
    if ($term === '') {
        echo json_encode(['results' => []]);
        exit;
    }
    $like = '%' . $term . '%';
    $sql = "SELECT mouse_id, dob, sex, status FROM mice
             WHERE mouse_id LIKE ?
               " . (in_array($sex, ['male','female'], true) ? "AND sex = ?" : "") . "
             ORDER BY mouse_id LIMIT 25";
    $stmt = $con->prepare($sql);
    if (in_array($sex, ['male','female'], true)) {
        $stmt->bind_param("ss", $like, $sex);
    } else {
        $stmt->bind_param("s", $like);
    }
    $stmt->execute();
    $res = $stmt->get_result();
    $rows = [];
    while ($r = $res->fetch_assoc()) $rows[] = $r;
    echo json_encode(['results' => $rows]);
    exit;
}

// Default: paginated list for mouse_dash.php
$page    = max(1, (int)($_GET['page'] ?? 1));
$limit   = (int)($_GET['limit'] ?? 25);
if (!in_array($limit, [10, 25, 50, 100], true)) $limit = 25;
$offset  = ($page - 1) * $limit;
$search  = trim($_GET['search'] ?? '');
$sort    = $_GET['sort'] ?? 'mouse_id_asc';
$status  = $_GET['status'] ?? 'all';   // all|alive|sacrificed|archived|transferred_out
$sex     = $_GET['sex'] ?? 'all';      // all|male|female|unknown
$cageF   = trim($_GET['cage'] ?? '');

$where = [];
$args  = [];
$types = '';

if ($search !== '') {
    $where[] = "(m.mouse_id LIKE ? OR m.genotype LIKE ? OR m.ear_code LIKE ? OR m.current_cage_id LIKE ?)";
    $like = "%$search%";
    array_push($args, $like, $like, $like, $like);
    $types .= 'ssss';
}
if (in_array($status, ['alive','sacrificed','archived','transferred_out'], true)) {
    $where[] = "m.status = ?";
    $args[] = $status;
    $types .= 's';
}
if (in_array($sex, ['male','female','unknown'], true)) {
    $where[] = "m.sex = ?";
    $args[] = $sex;
    $types .= 's';
}
if ($cageF !== '') {
    $where[] = "m.current_cage_id = ?";
    $args[] = $cageF;
    $types .= 's';
}

$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$sortMap = [
    'mouse_id_asc'   => 'm.mouse_id ASC',
    'mouse_id_desc'  => 'm.mouse_id DESC',
    'dob_asc'        => 'm.dob ASC',
    'dob_desc'       => 'm.dob DESC',
    'created_desc'   => 'm.created_at DESC',
    'created_asc'    => 'm.created_at ASC',
    'cage_asc'       => 'm.current_cage_id ASC',
];
$orderBy = $sortMap[$sort] ?? 'm.mouse_id ASC';

// Total count
$countSql = "SELECT COUNT(*) AS n FROM mice m $whereSql";
$cstmt = $con->prepare($countSql);
if ($args) $cstmt->bind_param($types, ...$args);
$cstmt->execute();
$total = (int)$cstmt->get_result()->fetch_assoc()['n'];
$cstmt->close();

// Page rows
$listSql = "SELECT m.*, s.str_name
              FROM mice m
              LEFT JOIN strains s ON s.str_id = m.strain
              $whereSql
              ORDER BY $orderBy
              LIMIT ? OFFSET ?";
$stmt = $con->prepare($listSql);
$listArgs  = $args;
$listTypes = $types . 'ii';
$listArgs[] = $limit;
$listArgs[] = $offset;
$stmt->bind_param($listTypes, ...$listArgs);
$stmt->execute();
$res = $stmt->get_result();

// Optional columns the dashboard's "Columns" dropdown lets the user toggle.
// Always-on columns are Mouse ID / Cage / Status / Actions; everything else
// is opt-in. Same shape as hc_dash so widths and behavior stay consistent.
$allowedOptional = ['sex', 'dob', 'age', 'genotype'];
$colsParam = $_GET['columns'] ?? 'sex,age';
$visibleColumns = array_values(array_intersect(explode(',', $colsParam), $allowedOptional));

$rows = '';
$nowDate = new DateTime('today');
while ($m = $res->fetch_assoc()) {
    $age = '';
    if (!empty($m['dob'])) {
        $d = new DateTime($m['dob']);
        $diff = $d->diff($nowDate);
        $age = ($diff->y ? $diff->y . 'y ' : '') . ($diff->m ? $diff->m . 'm ' : '') . $diff->d . 'd';
    }
    $statusBadge = [
        'alive'           => 'bg-success',
        'sacrificed'      => 'bg-secondary',
        'archived'        => 'bg-dark',
        'transferred_out' => 'bg-warning text-dark',
    ][$m['status']] ?? 'bg-light text-dark';

    $cageCell = $m['current_cage_id']
        ? '<a href="hc_view.php?id=' . urlencode($m['current_cage_id']) . '">' . htmlspecialchars($m['current_cage_id']) . '</a>'
        : '<span class="text-muted">—</span>';

    $optionalRender = [
        'sex'      => htmlspecialchars(ucfirst($m['sex'])),
        'dob'      => htmlspecialchars($m['dob'] ?? '—'),
        'age'      => $age,
        'genotype' => htmlspecialchars($m['genotype'] ?? ''),
    ];

    $rows .= '<tr>';
    $rows .= '<td data-label="Mouse ID"><a href="mouse_view.php?id=' . urlencode($m['mouse_id']) . '"><strong>' . htmlspecialchars($m['mouse_id']) . '</strong></a></td>';
    foreach ($visibleColumns as $c) {
        $rows .= '<td data-label="' . ucfirst($c) . '">' . $optionalRender[$c] . '</td>';
    }
    $rows .= '<td data-label="Cage">' . $cageCell . '</td>';
    $rows .= '<td data-label="Status"><span class="badge ' . $statusBadge . '">' . htmlspecialchars($m['status']) . '</span></td>';
    $rows .= '<td data-label="Actions" class="text-end">'
          .  '<a href="mouse_view.php?id=' . urlencode($m['mouse_id']) . '" class="btn btn-sm btn-info" title="View"><i class="fas fa-eye"></i></a> '
          .  '<a href="mouse_edit.php?id=' . urlencode($m['mouse_id']) . '" class="btn btn-sm btn-warning" title="Edit"><i class="fas fa-edit"></i></a>'
          .  '</td>';
    $rows .= '</tr>';
}
$stmt->close();

$colspan = 4 + count($visibleColumns); // Mouse ID + optional + Cage + Status + Actions
if ($total === 0) {
    $rows = '<tr><td colspan="' . $colspan . '" class="text-center text-muted py-4">No mice found.</td></tr>';
}

// Pagination
$totalPages = max(1, (int)ceil($total / $limit));
$paginationLinks = '';
if ($totalPages > 1) {
    $prevDisabled = $page <= 1 ? 'disabled' : '';
    $nextDisabled = $page >= $totalPages ? 'disabled' : '';
    $paginationLinks .= '<li class="page-item ' . $prevDisabled . '"><a class="page-link" href="javascript:void(0)" onclick="fetchData(' . ($page - 1) . ')">«</a></li>';
    $start = max(1, $page - 2);
    $end   = min($totalPages, $page + 2);
    for ($p = $start; $p <= $end; $p++) {
        $active = $p === $page ? 'active' : '';
        $paginationLinks .= '<li class="page-item ' . $active . '"><a class="page-link" href="javascript:void(0)" onclick="fetchData(' . $p . ')">' . $p . '</a></li>';
    }
    $paginationLinks .= '<li class="page-item ' . $nextDisabled . '"><a class="page-link" href="javascript:void(0)" onclick="fetchData(' . ($page + 1) . ')">»</a></li>';
}

echo json_encode([
    'tableRows'       => $rows,
    'paginationLinks' => $paginationLinks,
    'totalRecords'    => $total,
]);
