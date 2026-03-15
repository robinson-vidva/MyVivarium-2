<?php
/**
 * Calendar Page
 *
 * Displays a monthly calendar view of tasks and reminders using FullCalendar v6.
 * Events are fetched via AJAX from calendar_events.php.
 * Color-coded by status: Pending (red), In Progress (yellow), Completed (green), Reminders (purple).
 * Admins can toggle between "My" and "All" views.
 */

ob_start();
require 'session_config.php';
require 'dbcon.php';

// Auth check
if (!isset($_SESSION['name'])) {
    header("Location: index.php");
    exit;
}

// Fetch lab name for title
$labName = '';
$labQuery = "SELECT value FROM settings WHERE name = 'lab_name'";
$labResult = $con->query($labQuery);
if ($labResult && $row = $labResult->fetch_assoc()) {
    $labName = $row['value'];
}

$isAdmin = (isset($_SESSION['role']) && $_SESSION['role'] === 'admin');

require 'header.php';
ob_end_flush();
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Calendar | <?php echo htmlspecialchars($labName); ?></title>

    <!-- FullCalendar v6 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.11/index.global.min.css" rel="stylesheet">

    <style>
        .calendar-container {
            max-width: 900px;
            margin: 0 auto;
            padding: 20px;
        }

        /* FullCalendar overrides */
        .fc {
            font-family: 'Poppins', sans-serif;
        }

        /* Toolbar layout */
        .fc .fc-toolbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 20px;
            padding: 12px 0;
        }

        .fc .fc-toolbar-title {
            font-size: 1.5rem;
            font-weight: 600;
        }

        .fc .fc-button {
            font-size: 0.85rem;
            border-radius: 6px;
            padding: 6px 14px;
        }

        .fc .fc-button-group {
            gap: 0;
        }

        /* Today button — match the today cell highlight color */
        .fc .fc-today-button {
            background-color: #fcf0c1 !important;
            border-color: #e6d88a !important;
            color: #665d1e !important;
        }

        .fc .fc-today-button:hover {
            background-color: #f5e6a0 !important;
            border-color: #d4c56e !important;
        }

        .fc .fc-today-button:disabled {
            opacity: 0.65;
        }

        .fc-event {
            cursor: pointer;
            font-size: 0.78rem;
            border-radius: 4px;
            padding: 2px 5px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        /* "+N more" link styling */
        .fc .fc-daygrid-more-link {
            font-size: 0.75rem;
            font-weight: 600;
            color: var(--bs-primary);
            padding: 2px 4px;
        }

        /* More-events popover */
        .fc .fc-more-popover {
            max-width: 280px;
            border-radius: 8px;
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.15);
        }

        .fc .fc-more-popover .fc-popover-body {
            max-height: 250px;
            overflow-y: auto;
        }

        /* List view — rich event content */
        .fc .fc-list-event-title {
            width: 100%;
        }

        .list-event-rich {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            padding: 4px 0;
            width: 100%;
        }

        .list-event-rich .event-main {
            flex: 1;
            min-width: 0;
        }

        .list-event-rich .event-title-row {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 3px;
        }

        .list-event-rich .event-name {
            font-weight: 600;
            font-size: 0.9rem;
        }

        .list-event-rich .event-status {
            display: inline-block;
            padding: 1px 8px;
            border-radius: 10px;
            font-size: 0.7rem;
            font-weight: 500;
            color: #fff;
            flex-shrink: 0;
        }

        .list-event-rich .event-meta {
            font-size: 0.78rem;
            color: var(--bs-secondary-color);
            display: flex;
            flex-wrap: wrap;
            gap: 6px 16px;
        }

        .list-event-rich .event-meta span i {
            width: 14px;
            text-align: center;
            margin-right: 3px;
            opacity: 0.7;
        }

        /* On mobile keep list view compact */
        @media (max-width: 576px) {
            .list-event-rich .event-meta {
                display: none;
            }
            .list-event-rich .event-name {
                font-size: 0.82rem;
            }
        }

        .fc .fc-daygrid-day-number {
            font-size: 0.85rem;
            padding: 4px 8px;
        }

        /* Clickable day cells */
        .fc .fc-daygrid-day {
            cursor: pointer;
        }

        .fc .fc-daygrid-day:hover {
            background-color: var(--bs-tertiary-bg);
        }

        /* Grid view — slightly taller day cells */
        .fc .fc-daygrid-day-frame {
            min-height: 90px;
        }

        /* List view — hide "all-day" time column for cleaner look */
        .fc .fc-list-event-time {
            display: none;
        }

        /* List view — full width */
        .fc .fc-view-harness {
            width: 100% !important;
        }

        .fc .fc-scroller {
            width: 100%;
        }

        .fc .fc-list {
            width: 100%;
        }

        .fc .fc-list-table {
            width: 100%;
            table-layout: fixed;
        }

        .fc .fc-listMonth-view {
            width: 100%;
        }

        /* Calendar top bar — legend + actions */
        .calendar-top-bar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 16px;
            padding: 10px 16px;
            border-radius: 8px;
            background-color: var(--bs-tertiary-bg);
            border: 1px solid var(--bs-border-color);
            gap: 12px;
        }

        .calendar-legend {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 12px;
            margin: 0;
        }

        .calendar-actions {
            display: flex;
            gap: 8px;
            flex-shrink: 0;
            align-items: center;
        }

        .calendar-actions .btn {
            font-size: 0.8rem;
            padding: 4px 12px;
            white-space: nowrap;
        }

        .legend-item {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            font-size: 0.8rem;
            color: var(--bs-secondary-color);
        }

        .legend-dot {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            display: inline-block;
            flex-shrink: 0;
        }

        /* View toggle buttons */
        .view-toggle .btn {
            font-size: 0.75rem;
            padding: 3px 10px;
        }

        .view-toggle .btn.active {
            pointer-events: none;
        }

        /* Mobile adjustments */
        @media (max-width: 576px) {
            .calendar-container {
                padding: 10px;
            }
            .fc .fc-toolbar {
                flex-direction: column;
                gap: 8px;
            }
            .fc .fc-toolbar-title {
                font-size: 1.1rem;
            }
            .fc .fc-button {
                font-size: 0.75rem;
                padding: 4px 8px;
            }
            .calendar-top-bar {
                flex-direction: column;
                padding: 8px 10px;
            }
            .calendar-legend {
                justify-content: center;
                gap: 10px;
            }
            .calendar-actions {
                justify-content: center;
                width: 100%;
                flex-wrap: wrap;
            }
            .legend-item {
                font-size: 0.72rem;
            }
            .legend-dot {
                width: 8px;
                height: 8px;
            }
        }

        /* ===== DARK MODE ===== */
        [data-bs-theme="dark"] .fc {
            --fc-border-color: #565e66;
            --fc-page-bg-color: #212529;
            --fc-neutral-bg-color: #2b3035;
            --fc-list-event-hover-bg-color: rgba(255, 255, 255, 0.08);
            --fc-today-bg-color: rgba(13, 110, 253, 0.15);
        }

        [data-bs-theme="dark"] .fc th {
            color: #adb5bd;
        }

        [data-bs-theme="dark"] .fc td {
            color: #dee2e6;
        }

        [data-bs-theme="dark"] .fc .fc-daygrid-day-number {
            color: #dee2e6;
        }

        [data-bs-theme="dark"] .fc .fc-day-other .fc-daygrid-day-number {
            color: #6c757d;
        }

        [data-bs-theme="dark"] .fc .fc-button-primary {
            background-color: #454d55;
            border-color: #565e66;
            color: #dee2e6;
        }

        /* Dark mode Today button */
        [data-bs-theme="dark"] .fc .fc-today-button {
            background-color: rgba(13, 110, 253, 0.25) !important;
            border-color: rgba(13, 110, 253, 0.4) !important;
            color: #6ea8fe !important;
        }

        [data-bs-theme="dark"] .fc .fc-today-button:hover {
            background-color: rgba(13, 110, 253, 0.35) !important;
        }

        [data-bs-theme="dark"] .fc .fc-button-primary:hover {
            background-color: #565e66;
        }

        [data-bs-theme="dark"] .fc .fc-button-primary:not(:disabled).fc-button-active,
        [data-bs-theme="dark"] .fc .fc-button-primary:not(:disabled):active {
            background-color: #0d6efd;
            border-color: #0d6efd;
            color: #fff;
        }

        [data-bs-theme="dark"] .fc .fc-toolbar-title {
            color: #dee2e6;
        }

        [data-bs-theme="dark"] .fc .fc-list {
            border-color: #565e66;
        }

        [data-bs-theme="dark"] .fc .fc-list-day-cushion {
            background-color: #2b3035;
            color: #dee2e6;
        }

        [data-bs-theme="dark"] .fc .fc-list-event td {
            border-color: #565e66;
        }

        [data-bs-theme="dark"] .fc .fc-popover,
        [data-bs-theme="dark"] .fc .fc-more-popover {
            background-color: #2b3035;
            border-color: #565e66;
        }

        [data-bs-theme="dark"] .fc .fc-popover-header {
            background-color: #212529;
            color: #dee2e6;
        }

        [data-bs-theme="dark"] .fc .fc-daygrid-more-link {
            color: #6ea8fe;
        }

        [data-bs-theme="dark"] .fc .fc-col-header-cell {
            background-color: #2b3035;
        }

        /* ===== POPUP STYLES ===== */
        .cal-popup-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 999;
        }

        .cal-popup {
            display: none;
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            background-color: var(--bs-body-bg);
            border: 1px solid var(--bs-border-color);
            z-index: 1000;
            box-shadow: 0 8px 30px rgba(0, 0, 0, 0.2);
            border-radius: 12px;
            width: 90%;
            max-width: 480px;
            overflow: hidden;
            max-height: 85vh;
            display: none;
        }

        .cal-popup-header {
            padding: 16px 20px;
            border-bottom: 1px solid var(--bs-border-color);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .cal-popup-header h5 {
            margin: 0;
            font-weight: 600;
            font-size: 1rem;
        }

        .cal-popup-header .close-btn {
            font-size: 1.4rem;
            cursor: pointer;
            color: var(--bs-secondary-color);
            background: none;
            border: none;
            line-height: 1;
            padding: 0;
        }

        .cal-popup-header .close-btn:hover {
            color: var(--bs-body-color);
        }

        .cal-popup-body {
            padding: 16px 20px;
            overflow-y: auto;
            max-height: calc(85vh - 130px);
        }

        .cal-popup-footer {
            padding: 12px 20px;
            border-top: 1px solid var(--bs-border-color);
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }

        .cal-popup-footer .btn {
            font-size: 0.82rem;
        }

        /* Day popup sections */
        .day-section-label {
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: var(--bs-secondary-color);
            margin-bottom: 6px;
            margin-top: 12px;
        }

        .day-section-label:first-child {
            margin-top: 0;
        }

        .day-event-item {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 8px 10px;
            border-radius: 6px;
            cursor: pointer;
            transition: background-color 0.15s;
            margin-bottom: 2px;
        }

        .day-event-item:hover {
            background-color: var(--bs-tertiary-bg);
        }

        .day-event-dot {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            flex-shrink: 0;
        }

        .day-event-info {
            flex: 1;
            min-width: 0;
        }

        .day-event-title {
            font-size: 0.88rem;
            font-weight: 500;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .day-event-sub {
            font-size: 0.72rem;
            color: var(--bs-secondary-color);
        }

        .day-event-badge {
            display: inline-block;
            padding: 1px 8px;
            border-radius: 10px;
            font-size: 0.68rem;
            font-weight: 500;
            color: #fff;
            flex-shrink: 0;
        }

        .day-empty {
            text-align: center;
            padding: 20px 10px;
            color: var(--bs-secondary-color);
            font-size: 0.9rem;
        }

        .day-empty i {
            display: block;
            font-size: 1.6rem;
            margin-bottom: 8px;
            opacity: 0.4;
        }

        /* Event detail view inside popup */
        .event-detail-row {
            display: flex;
            padding: 6px 0;
            font-size: 0.88rem;
            border-bottom: 1px solid var(--bs-border-color-translucent);
        }

        .event-detail-row:last-child {
            border-bottom: none;
        }

        .event-detail-label {
            font-weight: 600;
            width: 110px;
            flex-shrink: 0;
            color: var(--bs-secondary-color);
            font-size: 0.82rem;
        }

        .event-detail-value {
            flex: 1;
        }

        .event-detail-back {
            font-size: 0.82rem;
            cursor: pointer;
            color: var(--bs-primary);
            display: inline-flex;
            align-items: center;
            gap: 4px;
            margin-bottom: 12px;
        }

        .event-detail-back:hover {
            text-decoration: underline;
        }

        /* Status badge */
        .status-badge {
            display: inline-block;
            padding: 2px 10px;
            border-radius: 12px;
            font-size: 0.78rem;
            font-weight: 500;
            color: #fff;
        }
        .status-badge.pending { background-color: #dc3545; }
        .status-badge.in-progress { background-color: #ffc107; color: #000; }
        .status-badge.completed { background-color: #198754; }
        .status-badge.reminder { background-color: #6f42c1; }
    </style>
</head>

<body>
    <div class="calendar-container content">
        <?php include('message.php'); ?>

        <!-- Legend + Actions -->
        <div class="calendar-top-bar">
            <div class="calendar-legend">
                <span class="legend-item"><span class="legend-dot" style="background:#dc3545;"></span>Pending</span>
                <span class="legend-item"><span class="legend-dot" style="background:#ffc107;"></span>In Progress</span>
                <span class="legend-item"><span class="legend-dot" style="background:#198754;"></span>Completed</span>
                <span class="legend-item"><span class="legend-dot" style="background:#6f42c1;"></span>Reminder</span>
            </div>
            <div class="calendar-actions">
                <?php if ($isAdmin): ?>
                <div class="btn-group view-toggle" role="group" aria-label="View toggle">
                    <button type="button" class="btn btn-sm btn-outline-secondary active" id="viewMineBtn" onclick="setCalView('mine')">
                        <i class="fas fa-user me-1"></i>Mine
                    </button>
                    <button type="button" class="btn btn-sm btn-outline-secondary" id="viewAllBtn" onclick="setCalView('all')">
                        <i class="fas fa-users me-1"></i>All
                    </button>
                </div>
                <?php endif; ?>
                <a href="manage_tasks.php" class="btn btn-sm btn-outline-primary"><i class="fas fa-tasks me-1"></i>Tasks</a>
                <a href="manage_reminder.php" class="btn btn-sm btn-outline-secondary"><i class="fas fa-bell me-1"></i>Reminders</a>
            </div>
        </div>

        <!-- FullCalendar mount point -->
        <div id="calendar"></div>
    </div>

    <!-- Unified Popup -->
    <div class="cal-popup-overlay" id="calOverlay"></div>
    <div class="cal-popup" id="calPopup">
        <div class="cal-popup-header">
            <h5 id="calPopupTitle"></h5>
            <button class="close-btn" id="calCloseBtn" aria-label="Close">&times;</button>
        </div>
        <div class="cal-popup-body" id="calPopupBody"></div>
        <div class="cal-popup-footer" id="calPopupFooter"></div>
    </div>

    <!-- FullCalendar v6 JS -->
    <script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.11/index.global.min.js"></script>

    <script>
    var calViewMode = <?= $isAdmin ? "'all'" : "'mine'" ?>;
    var isAdmin = <?= $isAdmin ? 'true' : 'false' ?>;
    var calendar;

    function setCalView(mode) {
        calViewMode = mode;
        // Update toggle buttons
        document.getElementById('viewMineBtn').classList.toggle('active', mode === 'mine');
        document.getElementById('viewAllBtn').classList.toggle('active', mode === 'all');
        // Refetch events with new view
        calendar.refetchEvents();
    }

    document.addEventListener('DOMContentLoaded', function() {
        var calendarEl = document.getElementById('calendar');
        var isMobile = window.innerWidth <= 576;

        calendar = new FullCalendar.Calendar(calendarEl, {
            initialView: isMobile ? 'listMonth' : 'dayGridMonth',
            headerToolbar: {
                left: 'prev,next today',
                center: 'title',
                right: 'dayGridMonth,listMonth'
            },
            buttonText: {
                today: 'Today',
                month: 'Month',
                list: 'List'
            },

            // Event source with view parameter
            events: function(info, successCallback, failureCallback) {
                var xhr = new XMLHttpRequest();
                var url = 'calendar_events.php?start=' + info.startStr + '&end=' + info.endStr + '&view=' + calViewMode;
                xhr.open('GET', url, true);
                xhr.onload = function() {
                    if (xhr.status === 200) {
                        try {
                            successCallback(JSON.parse(xhr.responseText));
                        } catch (e) {
                            failureCallback(e);
                        }
                    } else {
                        failureCallback(new Error('Request failed'));
                    }
                };
                xhr.onerror = function() { failureCallback(new Error('Network error')); };
                xhr.send();
            },

            // Display
            dayMaxEvents: 3,
            fixedWeekCount: false,
            navLinks: false,
            editable: false,
            selectable: false,
            height: 'auto',
            firstDay: 0,

            // Responsive view switch
            windowResize: function() {
                var newMobile = window.innerWidth <= 576;
                if (newMobile && calendar.view.type !== 'listMonth') {
                    calendar.changeView('listMonth');
                } else if (!newMobile && calendar.view.type === 'listMonth') {
                    calendar.changeView('dayGridMonth');
                }
            },

            // Rich content for list view
            eventContent: function(arg) {
                if (arg.view.type !== 'listMonth') return;

                var props = arg.event.extendedProps;
                var statusColors = {
                    'Pending': '#dc3545',
                    'In Progress': '#ffc107',
                    'Completed': '#198754'
                };
                var statusTextColors = { 'In Progress': '#000' };

                var html = '<div class="list-event-rich">';
                html += '<div class="event-main">';

                html += '<div class="event-title-row">';
                html += '<span class="event-name">' + esc(arg.event.title) + '</span>';
                if (props.status) {
                    var bg = statusColors[props.status] || '#6f42c1';
                    var txtColor = statusTextColors[props.status] || '#fff';
                    html += '<span class="event-status" style="background-color:' + bg + ';color:' + txtColor + ';">' + esc(props.status) + '</span>';
                }
                html += '</div>';

                html += '<div class="event-meta">';
                if (props.type === 'task') {
                    if (props.description) {
                        html += '<span><i class="fas fa-align-left"></i>' + esc(props.description) + '</span>';
                    }
                    if (props.assignedTo) {
                        html += '<span><i class="fas fa-user"></i>' + esc(props.assignedTo) + '</span>';
                    }
                    if (props.cageId) {
                        html += '<span><i class="fas fa-box"></i>Cage ' + esc(props.cageId) + '</span>';
                    }
                } else if (props.type === 'reminder') {
                    if (props.description) {
                        html += '<span><i class="fas fa-align-left"></i>' + esc(props.description) + '</span>';
                    }
                    if (props.recurrenceType) {
                        html += '<span><i class="fas fa-redo"></i>' + esc(capitalize(props.recurrenceType)) + '</span>';
                    }
                    if (props.timeOfDay) {
                        html += '<span><i class="fas fa-clock"></i>' + formatTime(props.timeOfDay) + '</span>';
                    }
                    if (props.assignedTo) {
                        html += '<span><i class="fas fa-user"></i>' + esc(props.assignedTo) + '</span>';
                    }
                }
                html += '</div>';

                html += '</div></div>';

                return { html: html };
            },

            // Event click — show event detail
            eventClick: function(info) {
                info.jsEvent.preventDefault();
                var dateStr = info.event.startStr ? info.event.startStr.substring(0, 10) : '';
                showEventDetail(info.event, dateStr);
            },

            // Date click — always open day popup
            dateClick: function(info) {
                showDayPopup(info.dateStr);
            }
        });

        calendar.render();

        // ===== DAY POPUP =====

        function showDayPopup(dateStr) {
            var d = new Date(dateStr + 'T12:00:00');
            var formatted = d.toLocaleDateString('en-US', {
                weekday: 'long', month: 'long', day: 'numeric', year: 'numeric'
            });

            var events = calendar.getEvents().filter(function(event) {
                var eventStart = event.startStr ? event.startStr.substring(0, 10) : '';
                return eventStart === dateStr;
            });

            var tasks = events.filter(function(e) { return e.extendedProps.type === 'task'; });
            var reminders = events.filter(function(e) { return e.extendedProps.type === 'reminder'; });

            var html = '';

            if (events.length === 0) {
                html += '<div class="day-empty">';
                html += '<i class="fas fa-calendar-day"></i>';
                html += 'No tasks or reminders for this day.';
                html += '</div>';
            } else {
                if (tasks.length > 0) {
                    html += '<div class="day-section-label"><i class="fas fa-tasks me-1"></i>Tasks (' + tasks.length + ')</div>';
                    tasks.forEach(function(event, idx) {
                        html += buildDayEventItem(event, 'task', idx, dateStr);
                    });
                }
                if (reminders.length > 0) {
                    html += '<div class="day-section-label"><i class="fas fa-bell me-1"></i>Reminders (' + reminders.length + ')</div>';
                    reminders.forEach(function(event, idx) {
                        html += buildDayEventItem(event, 'reminder', idx, dateStr);
                    });
                }
            }

            // Store events for click handling
            window._dayEvents = events;
            window._dayDateStr = dateStr;

            document.getElementById('calPopupTitle').textContent = formatted;
            document.getElementById('calPopupBody').innerHTML = html;

            // Footer with quick-add buttons
            var footer = '<a href="manage_tasks.php?add=1&date=' + dateStr + '" class="btn btn-primary btn-sm">' +
                '<i class="fas fa-plus me-1"></i>Add Task</a>' +
                '<a href="manage_reminder.php" class="btn btn-outline-secondary btn-sm">' +
                '<i class="fas fa-plus me-1"></i>Add Reminder</a>' +
                '<button type="button" class="btn btn-secondary btn-sm ms-auto" onclick="closeCalPopup()">Close</button>';
            document.getElementById('calPopupFooter').innerHTML = footer;

            openCalPopup();
        }

        function buildDayEventItem(event, type, idx, dateStr) {
            var props = event.extendedProps;
            var bg = event.backgroundColor || '#0d6efd';
            var statusColors = { 'Pending': '#dc3545', 'In Progress': '#ffc107', 'Completed': '#198754' };
            var statusTextColors = { 'In Progress': '#000' };

            var sub = '';
            if (type === 'task') {
                var parts = [];
                if (props.assignedTo) parts.push(props.assignedTo);
                if (props.cageId) parts.push('Cage ' + props.cageId);
                sub = parts.join(' &middot; ');
            } else {
                var parts = [];
                if (props.recurrenceType) parts.push(capitalize(props.recurrenceType));
                if (props.timeOfDay) parts.push(formatTime(props.timeOfDay));
                if (props.assignedTo) parts.push(props.assignedTo);
                sub = parts.join(' &middot; ');
            }

            var badgeHtml = '';
            if (type === 'task' && props.status) {
                var badgeBg = statusColors[props.status] || bg;
                var badgeColor = statusTextColors[props.status] || '#fff';
                badgeHtml = '<span class="day-event-badge" style="background-color:' + badgeBg + ';color:' + badgeColor + ';">' + esc(props.status) + '</span>';
            }

            return '<div class="day-event-item" onclick="window._showDayEvent(' + idx + ', \'' + type + '\')">' +
                '<span class="day-event-dot" style="background-color:' + bg + ';"></span>' +
                '<div class="day-event-info">' +
                '<div class="day-event-title">' + esc(event.title) + '</div>' +
                (sub ? '<div class="day-event-sub">' + sub + '</div>' : '') +
                '</div>' +
                badgeHtml +
                '</div>';
        }

        // Click handler for day event items
        window._showDayEvent = function(idx, type) {
            var events = window._dayEvents;
            var typed = events.filter(function(e) { return e.extendedProps.type === type; });
            if (typed[idx]) {
                showEventDetail(typed[idx], window._dayDateStr);
            }
        };

        // ===== EVENT DETAIL =====

        function showEventDetail(event, dateStr) {
            var props = event.extendedProps;
            var html = '';

            // Back button if coming from day popup
            if (dateStr) {
                html += '<div class="event-detail-back" onclick="window._backToDay(\'' + dateStr + '\')">' +
                    '<i class="fas fa-arrow-left"></i> Back to ' + formatShortDate(dateStr) +
                    '</div>';
            }

            if (props.type === 'task') {
                var statusClass = props.status.toLowerCase().replace(' ', '-');
                html += '<div class="event-detail-row"><span class="event-detail-label">Status</span><span class="event-detail-value"><span class="status-badge ' + statusClass + '">' + esc(props.status) + '</span></span></div>';
                html += '<div class="event-detail-row"><span class="event-detail-label">Description</span><span class="event-detail-value">' + esc(props.description || 'N/A') + '</span></div>';
                html += '<div class="event-detail-row"><span class="event-detail-label">Assigned By</span><span class="event-detail-value">' + esc(props.assignedBy) + '</span></div>';
                html += '<div class="event-detail-row"><span class="event-detail-label">Assigned To</span><span class="event-detail-value">' + esc(props.assignedTo) + '</span></div>';
                html += '<div class="event-detail-row"><span class="event-detail-label">Due Date</span><span class="event-detail-value">' + esc(props.completionDate || 'Not set') + '</span></div>';
                html += '<div class="event-detail-row"><span class="event-detail-label">Created</span><span class="event-detail-value">' + esc(props.creationDate || 'N/A') + '</span></div>';
                if (props.cageId) {
                    html += '<div class="event-detail-row"><span class="event-detail-label">Cage ID</span><span class="event-detail-value">' + esc(props.cageId) + '</span></div>';
                }
            } else if (props.type === 'reminder') {
                html += '<div class="event-detail-row"><span class="event-detail-label">Recurrence</span><span class="event-detail-value">' + esc(capitalize(props.recurrenceType)) + '</span></div>';
                html += '<div class="event-detail-row"><span class="event-detail-label">Time</span><span class="event-detail-value">' + formatTime(props.timeOfDay) + '</span></div>';
                html += '<div class="event-detail-row"><span class="event-detail-label">Description</span><span class="event-detail-value">' + esc(props.description || 'N/A') + '</span></div>';
                html += '<div class="event-detail-row"><span class="event-detail-label">Assigned By</span><span class="event-detail-value">' + esc(props.assignedBy) + '</span></div>';
                html += '<div class="event-detail-row"><span class="event-detail-label">Assigned To</span><span class="event-detail-value">' + esc(props.assignedTo) + '</span></div>';
                if (props.cageId) {
                    html += '<div class="event-detail-row"><span class="event-detail-label">Cage ID</span><span class="event-detail-value">' + esc(props.cageId) + '</span></div>';
                }
            }

            // Set header with type icon
            var icon = props.type === 'reminder' ? '<i class="fas fa-bell me-2" style="color:#6f42c1;"></i>' : '<i class="fas fa-tasks me-2" style="color:' + (event.backgroundColor || '#0d6efd') + ';"></i>';
            document.getElementById('calPopupTitle').innerHTML = icon + esc(event.title);
            document.getElementById('calPopupBody').innerHTML = html;

            // Footer with action links
            var footer = '';
            if (props.type === 'task') {
                footer += '<a href="manage_tasks.php?search=' + encodeURIComponent(event.title) + '" class="btn btn-primary btn-sm">' +
                    '<i class="fas fa-external-link-alt me-1"></i>View in Tasks</a>';
            } else {
                footer += '<a href="manage_reminder.php" class="btn btn-primary btn-sm">' +
                    '<i class="fas fa-external-link-alt me-1"></i>View in Reminders</a>';
            }
            if (dateStr) {
                footer += '<a href="manage_tasks.php?add=1&date=' + dateStr + '" class="btn btn-success btn-sm">' +
                    '<i class="fas fa-plus me-1"></i>Add Task</a>';
            }
            footer += '<button type="button" class="btn btn-secondary btn-sm ms-auto" onclick="closeCalPopup()">Close</button>';
            document.getElementById('calPopupFooter').innerHTML = footer;

            openCalPopup();
        }

        // Back to day view
        window._backToDay = function(dateStr) {
            showDayPopup(dateStr);
        };

        // ===== POPUP OPEN/CLOSE =====

        window.openCalPopup = function() {
            document.getElementById('calOverlay').style.display = 'block';
            document.getElementById('calPopup').style.display = 'block';
        };

        window.closeCalPopup = function() {
            document.getElementById('calOverlay').style.display = 'none';
            document.getElementById('calPopup').style.display = 'none';
        };

        document.getElementById('calCloseBtn').addEventListener('click', closeCalPopup);
        document.getElementById('calOverlay').addEventListener('click', closeCalPopup);
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') closeCalPopup();
        });

        // ===== UTILITY =====
        function esc(text) {
            if (!text) return '';
            var div = document.createElement('div');
            div.appendChild(document.createTextNode(text));
            return div.innerHTML;
        }

        function capitalize(str) {
            return str ? str.charAt(0).toUpperCase() + str.slice(1) : '';
        }

        function formatTime(t) {
            if (!t) return 'N/A';
            var parts = t.split(':');
            var h = parseInt(parts[0], 10);
            var m = parts[1] || '00';
            var ampm = h >= 12 ? 'PM' : 'AM';
            h = h % 12 || 12;
            return h + ':' + m + ' ' + ampm;
        }

        function formatShortDate(dateStr) {
            var d = new Date(dateStr + 'T12:00:00');
            return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
        }
    });
    </script>

    <?php require 'footer.php'; ?>
</body>

</html>
