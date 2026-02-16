<?php
/**
 * Calendar Page
 *
 * Displays a monthly calendar view of tasks and reminders using FullCalendar v6.
 * Events are fetched via AJAX from calendar_events.php.
 * Color-coded by status: Pending (red), In Progress (yellow), Completed (green), Reminders (purple).
 */

ob_start();
require 'session_config.php';
require 'dbcon.php';

// Auth check
if (!isset($_SESSION['name'])) {
    header("Location: index.php");
    exit;
}

$isAdmin = (isset($_SESSION['role']) && $_SESSION['role'] === 'admin');

// Fetch lab name for title
$labName = '';
$labQuery = "SELECT value FROM settings WHERE name = 'lab_name'";
$labResult = $con->query($labQuery);
if ($labResult && $row = $labResult->fetch_assoc()) {
    $labName = $row['value'];
}

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

        /* Toolbar layout — spread items across full width */
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

        /* Calendar top bar — actions + legend */
        .calendar-top-bar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 16px;
            padding: 10px 16px;
            border-radius: 8px;
            background-color: var(--bs-tertiary-bg);
            border: 1px solid var(--bs-border-color);
        }

        .calendar-actions {
            display: flex;
            gap: 8px;
            flex-shrink: 0;
        }

        .calendar-legend {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 12px;
            margin: 0;
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
                align-items: stretch;
                gap: 10px;
                padding: 10px 12px;
            }
            .calendar-actions {
                justify-content: center;
            }
            .calendar-legend {
                justify-content: center;
                gap: 10px;
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

        /* Event detail popup */
        .event-popup-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 999;
        }

        .event-popup {
            display: none;
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            background-color: var(--bs-body-bg);
            padding: 24px;
            border: 1px solid var(--bs-border-color);
            z-index: 1000;
            box-shadow: 0 8px 30px rgba(0, 0, 0, 0.2);
            border-radius: 10px;
            width: 85%;
            max-width: 500px;
            overflow-y: auto;
            max-height: 85vh;
        }

        .event-popup h4 {
            margin: 0 0 16px 0;
            font-weight: 600;
            padding-right: 30px;
        }

        .event-popup p {
            margin-bottom: 8px;
            font-size: 0.9rem;
        }

        .event-popup .close-btn {
            position: absolute;
            top: 12px;
            right: 16px;
            font-size: 1.5rem;
            cursor: pointer;
            color: var(--bs-secondary-color);
            background: none;
            border: none;
            line-height: 1;
        }

        .event-popup .close-btn:hover {
            color: var(--bs-body-color);
        }

        /* Status badges */
        .status-badge {
            display: inline-block;
            padding: 2px 10px;
            border-radius: 12px;
            font-size: 0.8rem;
            font-weight: 500;
            color: #fff;
        }
        .status-badge.pending { background-color: #dc3545; }
        .status-badge.in-progress { background-color: #ffc107; color: #000; }
        .status-badge.completed { background-color: #198754; }
        .status-badge.reminder { background-color: #6f42c1; }

        /* Event type icon */
        .event-type-badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            font-size: 0.75rem;
            padding: 2px 8px;
            border-radius: 4px;
            background-color: var(--bs-tertiary-bg);
            color: var(--bs-secondary-color);
            margin-bottom: 12px;
        }

        /* Date summary list */
        .date-event-list {
            list-style: none;
            padding: 0;
            margin: 0;
        }

        .date-event-list li {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 12px;
            border-bottom: 1px solid var(--bs-border-color);
            cursor: pointer;
            border-radius: 6px;
            transition: background-color 0.15s;
        }

        .date-event-list li:last-child {
            border-bottom: none;
        }

        .date-event-list li:hover {
            background-color: var(--bs-tertiary-bg);
        }

        .date-event-list .event-dot {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            flex-shrink: 0;
        }
    </style>
</head>

<body>
    <div class="calendar-container content">
        <?php include('message.php'); ?>

        <!-- Top Bar: Actions + Legend -->
        <div class="calendar-top-bar">
            <div class="calendar-actions">
                <a href="manage_tasks.php" class="btn btn-primary btn-sm">
                    <i class="fas fa-tasks"></i> Manage Tasks
                </a>
                <?php if ($isAdmin) : ?>
                <a href="manage_reminder.php" class="btn btn-sm" style="background-color: #6f42c1; color: #fff;">
                    <i class="fas fa-bell"></i> Manage Reminders
                </a>
                <?php endif; ?>
            </div>
            <div class="calendar-legend">
                <span class="legend-item"><span class="legend-dot" style="background:#dc3545;"></span>Pending</span>
                <span class="legend-item"><span class="legend-dot" style="background:#ffc107;"></span>In Progress</span>
                <span class="legend-item"><span class="legend-dot" style="background:#198754;"></span>Completed</span>
                <span class="legend-item"><span class="legend-dot" style="background:#6f42c1;"></span>Reminder</span>
            </div>
        </div>

        <!-- FullCalendar mount point -->
        <div id="calendar"></div>
    </div>

    <!-- Event Detail Popup -->
    <div class="event-popup-overlay" id="eventOverlay"></div>
    <div class="event-popup" id="eventPopup">
        <button class="close-btn" id="eventCloseBtn" aria-label="Close">&times;</button>
        <h4 id="eventTitle"></h4>
        <div id="eventDetails"></div>
        <div class="d-flex gap-2 mt-3">
            <a id="eventViewLink" href="#" class="btn btn-primary btn-sm" style="display:none;">
                <i class="fas fa-external-link-alt"></i> View in Tasks
            </a>
            <button type="button" class="btn btn-secondary btn-sm" id="eventCloseButton">Close</button>
        </div>
    </div>

    <!-- FullCalendar v6 JS -->
    <script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.11/index.global.min.js"></script>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        var calendarEl = document.getElementById('calendar');
        var isMobile = window.innerWidth <= 576;

        var calendar = new FullCalendar.Calendar(calendarEl, {
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

            // Event source
            events: {
                url: 'calendar_events.php',
                method: 'GET',
                failure: function() {
                    console.error('Failed to load calendar events.');
                }
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
                if (arg.view.type !== 'listMonth') return; // grid view uses default

                var props = arg.event.extendedProps;
                var statusColors = {
                    'Pending': '#dc3545',
                    'In Progress': '#ffc107',
                    'Completed': '#198754'
                };
                var statusTextColors = { 'In Progress': '#000' };

                var html = '<div class="list-event-rich">';
                html += '<div class="event-main">';

                // Title row with status badge
                html += '<div class="event-title-row">';
                html += '<span class="event-name">' + esc(arg.event.title) + '</span>';
                if (props.status) {
                    var bg = statusColors[props.status] || '#6f42c1';
                    var txtColor = statusTextColors[props.status] || '#fff';
                    html += '<span class="event-status" style="background-color:' + bg + ';color:' + txtColor + ';">' + esc(props.status) + '</span>';
                }
                html += '</div>';

                // Meta row with details
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

            // Event click
            eventClick: function(info) {
                info.jsEvent.preventDefault();
                showEventPopup(info.event);
            },

            // Date click
            dateClick: function(info) {
                var events = calendar.getEvents().filter(function(event) {
                    var eventStart = event.startStr ? event.startStr.substring(0, 10) : '';
                    return eventStart === info.dateStr;
                });
                if (events.length === 1) {
                    showEventPopup(events[0]);
                } else if (events.length > 1) {
                    showDateSummary(info.dateStr, events);
                }
            }
        });

        calendar.render();

        // ===== POPUP FUNCTIONS =====

        function showEventPopup(event) {
            var props = event.extendedProps;
            var html = '';

            if (props.type === 'task') {
                var statusClass = props.status.toLowerCase().replace(' ', '-');
                html =
                    '<div class="event-type-badge"><i class="fas fa-tasks"></i> Task</div>' +
                    '<p><strong>Status:</strong> <span class="status-badge ' + statusClass + '">' +
                        esc(props.status) + '</span></p>' +
                    '<p><strong>Description:</strong> ' + esc(props.description || 'N/A') + '</p>' +
                    '<p><strong>Assigned By:</strong> ' + esc(props.assignedBy) + '</p>' +
                    '<p><strong>Assigned To:</strong> ' + esc(props.assignedTo) + '</p>' +
                    '<p><strong>Due Date:</strong> ' + esc(props.completionDate || 'Not set') + '</p>' +
                    '<p><strong>Created:</strong> ' + esc(props.creationDate || 'N/A') + '</p>' +
                    (props.cageId ? '<p><strong>Cage ID:</strong> ' + esc(props.cageId) + '</p>' : '');

                document.getElementById('eventViewLink').href =
                    'manage_tasks.php?search=' + encodeURIComponent(event.title);
                document.getElementById('eventViewLink').innerHTML =
                    '<i class="fas fa-external-link-alt"></i> View in Tasks';
                document.getElementById('eventViewLink').style.display = 'inline-block';
            } else if (props.type === 'reminder') {
                html =
                    '<div class="event-type-badge"><i class="fas fa-bell"></i> Reminder</div>' +
                    '<p><strong>Recurrence:</strong> ' + esc(capitalize(props.recurrenceType)) + '</p>' +
                    '<p><strong>Time:</strong> ' + formatTime(props.timeOfDay) + '</p>' +
                    '<p><strong>Description:</strong> ' + esc(props.description || 'N/A') + '</p>' +
                    '<p><strong>Assigned By:</strong> ' + esc(props.assignedBy) + '</p>' +
                    '<p><strong>Assigned To:</strong> ' + esc(props.assignedTo) + '</p>' +
                    (props.cageId ? '<p><strong>Cage ID:</strong> ' + esc(props.cageId) + '</p>' : '');

                document.getElementById('eventViewLink').href = 'manage_reminder.php';
                document.getElementById('eventViewLink').innerHTML =
                    '<i class="fas fa-external-link-alt"></i> View in Reminders';
                document.getElementById('eventViewLink').style.display = 'inline-block';
            }

            document.getElementById('eventTitle').textContent = event.title;
            document.getElementById('eventDetails').innerHTML = html;
            openPopup();
        }

        function showDateSummary(dateStr, events) {
            var d = new Date(dateStr + 'T12:00:00');
            var formatted = d.toLocaleDateString('en-US', {
                weekday: 'long', year: 'numeric', month: 'long', day: 'numeric'
            });

            var html = '<p class="text-body-secondary mb-3">' + events.length + ' events</p>';
            html += '<ul class="date-event-list">';

            events.forEach(function(event, idx) {
                var bg = event.backgroundColor || '#0d6efd';
                var icon = event.extendedProps.type === 'reminder' ? 'fa-bell' : 'fa-tasks';
                html += '<li onclick="window._selectEvent(' + idx + ')">' +
                    '<span class="event-dot" style="background-color:' + bg + ';"></span>' +
                    '<i class="fas ' + icon + '" style="color:' + bg + '; font-size:0.8rem;"></i> ' +
                    '<span>' + esc(event.title) + '</span>' +
                    '</li>';
            });
            html += '</ul>';

            window._dateSummaryEvents = events;
            window._selectEvent = function(idx) {
                closePopup();
                setTimeout(function() {
                    showEventPopup(window._dateSummaryEvents[idx]);
                }, 200);
            };

            document.getElementById('eventTitle').textContent = formatted;
            document.getElementById('eventDetails').innerHTML = html;
            document.getElementById('eventViewLink').style.display = 'none';
            openPopup();
        }

        function openPopup() {
            document.getElementById('eventOverlay').style.display = 'block';
            document.getElementById('eventPopup').style.display = 'block';
        }

        function closePopup() {
            document.getElementById('eventOverlay').style.display = 'none';
            document.getElementById('eventPopup').style.display = 'none';
        }

        // Close listeners
        document.getElementById('eventCloseBtn').addEventListener('click', closePopup);
        document.getElementById('eventCloseButton').addEventListener('click', closePopup);
        document.getElementById('eventOverlay').addEventListener('click', closePopup);
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') closePopup();
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
    });
    </script>

    <?php require 'footer.php'; ?>
</body>

</html>
