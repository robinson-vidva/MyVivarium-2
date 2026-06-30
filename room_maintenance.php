<?php

/**
 * Recent Maintenance Records (room view)
 *
 * A read-only, login-required page that aggregates the most recent maintenance
 * records across BOTH holding and breeding cages, newest first, optionally
 * filtered to a single room. Built for IACUC inspections: a QR code on the
 * room door points here (room_maintenance.php?room=<room>) so vets, IACUC
 * members and vivarium managers can scan and see the room's maintenance log.
 *
 * Every authenticated role may view this page (it changes nothing). The QR
 * scan reuses the standard login -> redirect-back flow when the visitor is not
 * already signed in.
 *
 * Schema note: the maintenance table gained `deleted_at` / `note_type` in a
 * later migration. This page detects those columns at runtime so it works on
 * both pre- and post-migration databases.
 */

// Start a new session or resume the existing session
require 'session_config.php';

// Include the database connection file
require 'dbcon.php';

// Login required — any approved, logged-in role may view.
if (!isset($_SESSION['username'])) {
    $currentUrl = urlencode($_SERVER['REQUEST_URI']);
    header("Location: index.php?redirect=$currentUrl");
    exit;
}

// ---- Inputs ---------------------------------------------------------------
$room = trim($_GET['room'] ?? '');

$allowed_per_page = [10, 25, 50];
$per_page = (int)($_GET['per_page'] ?? 25);
if (!in_array($per_page, $allowed_per_page, true)) {
    $per_page = 25;
}
$page   = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $per_page;

// ---- Distinct rooms for the picker ---------------------------------------
$rooms = [];
if ($roomRes = $con->query("SELECT DISTINCT room FROM cages WHERE room IS NOT NULL AND room <> '' ORDER BY room")) {
    while ($r = $roomRes->fetch_assoc()) {
        $rooms[] = $r['room'];
    }
}

// ---- Schema-adaptive column handling -------------------------------------
$maintCols = [];
if ($colRes = $con->query("SHOW COLUMNS FROM maintenance")) {
    while ($c = $colRes->fetch_assoc()) {
        $maintCols[$c['Field']] = true;
    }
}
$hasDeletedAt = isset($maintCols['deleted_at']);
$hasNoteType  = isset($maintCols['note_type']);

$noteTypeSelect = $hasNoteType ? "m.note_type" : "NULL AS note_type";
$activeClause   = $hasDeletedAt ? "m.deleted_at IS NULL" : "1=1";

// ---- Build WHERE (shared by count + data) --------------------------------
$where      = $activeClause;
$baseTypes  = '';
$baseParams = [];
if ($room !== '') {
    $where        .= " AND c.room = ?";
    $baseTypes    .= 's';
    $baseParams[]  = $room;
}

// ---- Total count for pagination ------------------------------------------
$total = 0;
$countSql = "SELECT COUNT(*) AS n
               FROM maintenance m
               JOIN cages c ON m.cage_id = c.cage_id
              WHERE $where";
$countStmt = $con->prepare($countSql);
if ($baseTypes !== '') {
    $countStmt->bind_param($baseTypes, ...$baseParams);
}
$countStmt->execute();
$total = (int)($countStmt->get_result()->fetch_assoc()['n'] ?? 0);
$countStmt->close();
$total_pages = max(1, (int)ceil($total / $per_page));

// ---- Fetch the page of records -------------------------------------------
$records = [];
$dataSql = "SELECT m.id, m.cage_id, m.comments, $noteTypeSelect, m.timestamp,
                   c.room, c.rack,
                   (CASE WHEN EXISTS (SELECT 1 FROM breeding b WHERE b.cage_id = c.cage_id)
                         THEN 'breeding' ELSE 'holding' END) AS cage_type,
                   COALESCE(u.name, 'Unknown') AS user_name
              FROM maintenance m
              JOIN cages c ON m.cage_id = c.cage_id
              LEFT JOIN users u ON m.user_id = u.id
             WHERE $where
          ORDER BY m.timestamp DESC
             LIMIT ? OFFSET ?";
$dataTypes  = $baseTypes . 'ii';
$dataParams = array_merge($baseParams, [$per_page, $offset]);
$dataStmt   = $con->prepare($dataSql);
$dataStmt->bind_param($dataTypes, ...$dataParams);
$dataStmt->execute();
$dataRes = $dataStmt->get_result();
while ($row = $dataRes->fetch_assoc()) {
    $records[] = $row;
}
$dataStmt->close();

// Helper to preserve filters across pagination links.
function rmQueryString(array $overrides = []): string
{
    $params = [
        'room'     => $_GET['room'] ?? '',
        'per_page' => $_GET['per_page'] ?? 25,
        'page'     => $_GET['page'] ?? 1,
    ];
    return http_build_query(array_merge($params, $overrides));
}

// Include the header (defines $labName and $url from the settings table).
require 'header.php';

// Build the absolute URL a door QR code should encode for the current room.
// $url (settings 'url') is a bare host like prnt_crd.php uses; fall back to the
// request host for local development.
$qrHost   = !empty($url) ? $url : ($_SERVER['HTTP_HOST'] ?? 'localhost');
$qrScheme = !empty($url) ? 'https'
          : ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http');
$roomScanUrl = $qrScheme . '://' . $qrHost . '/room_maintenance.php?room=' . urlencode($room);
$qrImgSrc    = 'https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=' . urlencode($roomScanUrl);
?>

<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Recent Maintenance | <?php echo htmlspecialchars($labName); ?></title>
    <style>
        .container { max-width: 1000px; }
        .note-cell { white-space: pre-wrap; word-break: break-word; }
        .qr-panel { border: 1px dashed var(--bs-border-color); border-radius: 8px; }
        @media print {
            .no-print { display: none !important; }
            .qr-panel { border: none; }
        }
    </style>
</head>

<body>
    <div class="container content mt-4">
        <?php include('message.php'); ?>
        <div class="card">
            <div class="card-header d-flex flex-column flex-md-row justify-content-between align-items-center">
                <h1 class="mb-0 h3">
                    <i class="fas fa-clipboard-list me-2"></i>Recent Maintenance Records
                </h1>
                <form method="GET" action="room_maintenance.php" class="d-flex align-items-center gap-2 mt-3 mt-md-0 no-print">
                    <label for="roomSelect" class="form-label mb-0 text-nowrap">Room</label>
                    <select id="roomSelect" name="room" class="form-select form-select-sm" style="width:auto;" onchange="this.form.submit()">
                        <option value="">All rooms</option>
                        <?php foreach ($rooms as $rm): ?>
                            <option value="<?= htmlspecialchars($rm); ?>" <?= $room === $rm ? 'selected' : ''; ?>>
                                <?= htmlspecialchars($rm); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <input type="hidden" name="per_page" value="<?= (int)$per_page; ?>">
                </form>
            </div>

            <div class="card-body">
                <?php if ($room !== ''): ?>
                    <!-- Room-door QR panel: print and laminate for the room door. -->
                    <div class="qr-panel p-3 mb-4 d-flex flex-column flex-md-row align-items-center gap-3">
                        <img src="<?= htmlspecialchars($qrImgSrc); ?>" alt="QR code for room <?= htmlspecialchars($room); ?>" width="140" height="140">
                        <div class="text-center text-md-start">
                            <div class="fw-bold mb-1">Room <?= htmlspecialchars($room); ?> &mdash; door QR</div>
                            <div class="text-muted small mb-2">
                                Scan to open this maintenance log. Visitors who aren't signed in
                                are sent through login first.
                            </div>
                            <div class="small text-break"><a href="<?= htmlspecialchars($roomScanUrl); ?>"><?= htmlspecialchars($roomScanUrl); ?></a></div>
                            <button onclick="printRoomQr()" class="btn btn-sm btn-outline-secondary mt-2 no-print">
                                <i class="fas fa-print me-1"></i> Print QR
                            </button>
                        </div>
                    </div>
                <?php endif; ?>

                <div class="d-flex justify-content-between align-items-center mb-2">
                    <div class="text-muted small">
                        <?php if ($total > 0): ?>
                            Showing <?= $offset + 1; ?>&ndash;<?= min($offset + $per_page, $total); ?> of <?= $total; ?>
                            record<?= $total !== 1 ? 's' : ''; ?><?= $room !== '' ? ' in room ' . htmlspecialchars($room) : ' across all rooms'; ?>
                        <?php else: ?>
                            No maintenance records<?= $room !== '' ? ' for room ' . htmlspecialchars($room) : ''; ?> yet.
                        <?php endif; ?>
                    </div>
                </div>

                <div class="table-responsive">
                    <table class="table table-striped align-middle">
                        <thead>
                            <tr>
                                <th>Date / Time</th>
                                <th>Room</th>
                                <th>Cage</th>
                                <th>Type</th>
                                <th>Note</th>
                                <th>Logged by</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($records)): ?>
                                <tr><td colspan="6" class="text-center text-muted py-4">Nothing to show.</td></tr>
                            <?php else: foreach ($records as $rec):
                                $view = $rec['cage_type'] === 'breeding' ? 'bc_view.php' : 'hc_view.php'; ?>
                                <tr>
                                    <td class="text-nowrap"><?= htmlspecialchars(date('M j, Y g:i A', strtotime($rec['timestamp']))); ?></td>
                                    <td><?= htmlspecialchars($rec['room'] ?? ''); ?></td>
                                    <td>
                                        <a href="<?= $view; ?>?id=<?= urlencode($rec['cage_id']); ?>">
                                            <?= htmlspecialchars($rec['cage_id']); ?>
                                        </a>
                                    </td>
                                    <td>
                                        <span class="badge <?= $rec['cage_type'] === 'breeding' ? 'bg-info' : 'bg-secondary'; ?>">
                                            <?= htmlspecialchars(ucfirst($rec['cage_type'])); ?>
                                        </span>
                                        <?php if (!empty($rec['note_type'])): ?>
                                            <span class="badge bg-light text-dark"><?= htmlspecialchars($rec['note_type']); ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="note-cell"><?= $rec['comments'] !== null && $rec['comments'] !== '' ? htmlspecialchars($rec['comments']) : '<span class="text-muted">&mdash;</span>'; ?></td>
                                    <td class="text-nowrap"><?= htmlspecialchars($rec['user_name'] ?? ''); ?></td>
                                </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>

                <?php if ($total_pages > 1): ?>
                    <nav aria-label="Maintenance pagination" class="no-print">
                        <ul class="pagination justify-content-center">
                            <li class="page-item <?= $page <= 1 ? 'disabled' : ''; ?>">
                                <a class="page-link" href="?<?= rmQueryString(['page' => $page - 1]); ?>">Previous</a>
                            </li>
                            <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                                <li class="page-item <?= $i === $page ? 'active' : ''; ?>">
                                    <a class="page-link" href="?<?= rmQueryString(['page' => $i]); ?>"><?= $i; ?></a>
                                </li>
                            <?php endfor; ?>
                            <li class="page-item <?= $page >= $total_pages ? 'disabled' : ''; ?>">
                                <a class="page-link" href="?<?= rmQueryString(['page' => $page + 1]); ?>">Next</a>
                            </li>
                        </ul>
                    </nav>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <?php include 'footer.php'; ?>

    <?php if ($room !== ''): ?>
    <script>
        // Print only the room-door QR (a clean label), not the records table.
        var ROOM_QR = {
            img:  <?= json_encode($qrImgSrc); ?>,
            room: <?= json_encode($room); ?>,
            url:  <?= json_encode($roomScanUrl); ?>,
            lab:  <?= json_encode($labName); ?>
        };
        function printRoomQr() {
            var w = window.open('', '_blank', 'width=420,height=560');
            if (!w) return;
            var esc = function (s) {
                return String(s).replace(/[&<>"]/g, function (c) {
                    return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c];
                });
            };
            w.document.write(
                '<!doctype html><html><head><title>Room ' + esc(ROOM_QR.room) + ' QR</title>' +
                '<style>body{font-family:Arial,sans-serif;text-align:center;padding:24px;}' +
                'h1{font-size:20px;margin:0 0 4px;}h2{font-size:15px;color:#555;font-weight:normal;margin:0 0 16px;}' +
                'img{width:300px;height:300px;}p{font-size:11px;color:#777;word-break:break-all;margin-top:12px;}</style>' +
                '</head><body onload="window.print()">' +
                '<h1>' + esc(ROOM_QR.lab) + '</h1>' +
                '<h2>Room ' + esc(ROOM_QR.room) + ' &mdash; Maintenance Records</h2>' +
                '<img src="' + esc(ROOM_QR.img) + '" alt="QR">' +
                '<p>' + esc(ROOM_QR.url) + '</p>' +
                '</body></html>'
            );
            w.document.close();
        }
    </script>
    <?php endif; ?>
</body>

</html>
<?php mysqli_close($con); ?>
