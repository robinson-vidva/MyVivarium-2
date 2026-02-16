<?php
/**
 * Calendar Events API Endpoint
 *
 * Returns a JSON array of events for FullCalendar.
 * Fetches tasks and reminders for the logged-in user within a date range.
 * FullCalendar sends ?start=YYYY-MM-DD&end=YYYY-MM-DD automatically.
 */

require 'session_config.php';
require 'dbcon.php';

header('Content-Type: application/json');

// Auth check
if (!isset($_SESSION['username'])) {
    echo json_encode(['error' => 'Authentication required.']);
    exit;
}

$currentUserId = $_SESSION['user_id'];
$isAdmin = (isset($_SESSION['role']) && $_SESSION['role'] === 'admin');

// Parse date range from FullCalendar
$start = $_GET['start'] ?? date('Y-m-01');
$end = $_GET['end'] ?? date('Y-m-t');

// Validate date format
if (!preg_match('/^\d{4}-\d{2}-\d{2}/', $start) || !preg_match('/^\d{4}-\d{2}-\d{2}/', $end)) {
    echo json_encode(['error' => 'Invalid date format.']);
    exit;
}

// Extract just the date part (FullCalendar may send datetime strings)
$start = substr($start, 0, 10);
$end = substr($end, 0, 10);

// Fetch users lookup for name resolution
$userQuery = "SELECT id, name FROM users";
$userResult = $con->query($userQuery);
$users = $userResult ? array_column($userResult->fetch_all(MYSQLI_ASSOC), 'name', 'id') : [];

$events = [];

// Status color map
$colorMap = [
    'Pending'     => '#dc3545',
    'In Progress' => '#ffc107',
    'Completed'   => '#198754',
];
$textColorMap = [
    'Pending'     => '#ffffff',
    'In Progress' => '#000000',
    'Completed'   => '#ffffff',
];

// ============================================================
// FETCH TASKS WITH completion_date IN RANGE
// ============================================================
if ($isAdmin) {
    $taskSQL = "SELECT * FROM tasks
                WHERE completion_date IS NOT NULL
                AND completion_date BETWEEN ? AND ?
                ORDER BY completion_date ASC";
    $stmt = $con->prepare($taskSQL);
    $stmt->bind_param("ss", $start, $end);
} else {
    $taskSQL = "SELECT * FROM tasks
                WHERE completion_date IS NOT NULL
                AND completion_date BETWEEN ? AND ?
                AND (assigned_by = ? OR FIND_IN_SET(?, assigned_to))
                ORDER BY completion_date ASC";
    $stmt = $con->prepare($taskSQL);
    $stmt->bind_param("ssii", $start, $end, $currentUserId, $currentUserId);
}
$stmt->execute();
$taskResult = $stmt->get_result();

while ($task = $taskResult->fetch_assoc()) {
    $events[] = buildTaskEvent($task, $task['completion_date'], $users, $colorMap, $textColorMap);
}
$stmt->close();

// ============================================================
// FETCH TASKS WITHOUT completion_date (show on creation_date)
// ============================================================
if ($isAdmin) {
    $taskSQL2 = "SELECT * FROM tasks
                 WHERE completion_date IS NULL
                 AND DATE(creation_date) BETWEEN ? AND ?
                 ORDER BY creation_date ASC";
    $stmt2 = $con->prepare($taskSQL2);
    $stmt2->bind_param("ss", $start, $end);
} else {
    $taskSQL2 = "SELECT * FROM tasks
                 WHERE completion_date IS NULL
                 AND DATE(creation_date) BETWEEN ? AND ?
                 AND (assigned_by = ? OR FIND_IN_SET(?, assigned_to))
                 ORDER BY creation_date ASC";
    $stmt2 = $con->prepare($taskSQL2);
    $stmt2->bind_param("ssii", $start, $end, $currentUserId, $currentUserId);
}
$stmt2->execute();
$taskResult2 = $stmt2->get_result();

while ($task = $taskResult2->fetch_assoc()) {
    $eventDate = date('Y-m-d', strtotime($task['creation_date']));
    $events[] = buildTaskEvent($task, $eventDate, $users, $colorMap, $textColorMap);
}
$stmt2->close();

// ============================================================
// FETCH AND EXPAND REMINDERS
// ============================================================
if ($isAdmin) {
    $reminderSQL = "SELECT * FROM reminders WHERE status = 'active'";
    $rStmt = $con->prepare($reminderSQL);
} else {
    $reminderSQL = "SELECT * FROM reminders
                    WHERE status = 'active'
                    AND (assigned_by = ? OR FIND_IN_SET(?, assigned_to))";
    $rStmt = $con->prepare($reminderSQL);
    $rStmt->bind_param("ii", $currentUserId, $currentUserId);
}
$rStmt->execute();
$reminderResult = $rStmt->get_result();

$rangeStart = new DateTime($start);
$rangeEnd = new DateTime($end);

while ($reminder = $reminderResult->fetch_assoc()) {
    $occurrences = [];

    switch ($reminder['recurrence_type']) {
        case 'daily':
            $current = clone $rangeStart;
            while ($current <= $rangeEnd) {
                $occurrences[] = $current->format('Y-m-d');
                $current->modify('+1 day');
            }
            break;

        case 'weekly':
            $dayOfWeek = $reminder['day_of_week'];
            if ($dayOfWeek) {
                $current = clone $rangeStart;
                while ($current->format('l') !== $dayOfWeek) {
                    $current->modify('+1 day');
                }
                while ($current <= $rangeEnd) {
                    $occurrences[] = $current->format('Y-m-d');
                    $current->modify('+7 days');
                }
            }
            break;

        case 'monthly':
            $dayOfMonth = (int)$reminder['day_of_month'];
            if ($dayOfMonth > 0) {
                $current = clone $rangeStart;
                $current->setDate((int)$current->format('Y'), (int)$current->format('m'), 1);
                while ($current <= $rangeEnd) {
                    $lastDay = (int)$current->format('t');
                    $targetDay = min($dayOfMonth, $lastDay);
                    $occurrenceDate = clone $current;
                    $occurrenceDate->setDate(
                        (int)$current->format('Y'),
                        (int)$current->format('m'),
                        $targetDay
                    );
                    if ($occurrenceDate >= $rangeStart && $occurrenceDate <= $rangeEnd) {
                        $occurrences[] = $occurrenceDate->format('Y-m-d');
                    }
                    $current->modify('+1 month');
                }
            }
            break;
    }

    // Resolve assigned_to names
    $assignedToNames = array_map(function ($id) use ($users) {
        return $users[trim($id)] ?? 'Unknown';
    }, array_filter(explode(',', $reminder['assigned_to'])));

    foreach ($occurrences as $date) {
        $events[] = [
            'id'              => 'reminder-' . $reminder['id'] . '-' . $date,
            'title'           => $reminder['title'],
            'start'           => $date,
            'allDay'          => true,
            'backgroundColor' => '#6f42c1',
            'borderColor'     => '#6f42c1',
            'textColor'       => '#ffffff',
            'extendedProps'   => [
                'type'           => 'reminder',
                'reminderId'     => (int)$reminder['id'],
                'description'    => $reminder['description'],
                'recurrenceType' => $reminder['recurrence_type'],
                'timeOfDay'      => $reminder['time_of_day'],
                'assignedBy'     => $users[$reminder['assigned_by']] ?? 'Unknown',
                'assignedTo'     => implode(', ', $assignedToNames),
                'cageId'         => $reminder['cage_id'],
                'status'         => $reminder['status'],
            ],
        ];
    }
}
$rStmt->close();

// Output
echo json_encode($events);
$con->close();

// ============================================================
// HELPER FUNCTION
// ============================================================
function buildTaskEvent($task, $eventDate, $users, $colorMap, $textColorMap) {
    $assignedToNames = array_map(function ($id) use ($users) {
        return $users[trim($id)] ?? 'Unknown';
    }, array_filter(explode(',', $task['assigned_to'])));

    return [
        'id'              => 'task-' . $task['id'],
        'title'           => $task['title'],
        'start'           => $eventDate,
        'allDay'          => true,
        'backgroundColor' => $colorMap[$task['status']] ?? '#0d6efd',
        'borderColor'     => $colorMap[$task['status']] ?? '#0d6efd',
        'textColor'       => $textColorMap[$task['status']] ?? '#ffffff',
        'extendedProps'   => [
            'type'           => 'task',
            'taskId'         => (int)$task['id'],
            'description'    => $task['description'],
            'status'         => $task['status'],
            'assignedBy'     => $users[$task['assigned_by']] ?? 'Unknown',
            'assignedTo'     => implode(', ', $assignedToNames),
            'completionDate' => $task['completion_date'],
            'creationDate'   => $task['creation_date'],
            'cageId'         => $task['cage_id'],
        ],
    ];
}
?>
