<?php

/**
 * Header and Navigation Menu
 *
 * This script generates a header and navigation menu for the web application. The header displays the lab name and logo,
 * and the navigation menu includes links to various dashboards and settings, with additional options for admin users.
 *
 */

// Security Headers - Protect against common web vulnerabilities
header("X-Frame-Options: SAMEORIGIN"); // Prevent clickjacking
header("X-Content-Type-Options: nosniff"); // Prevent MIME type sniffing
header("X-XSS-Protection: 1; mode=block"); // Enable browser XSS protection
header("Referrer-Policy: strict-origin-when-cross-origin"); // Control referrer information
header("Permissions-Policy: geolocation=(), microphone=(), camera=()"); // Restrict powerful features

// Include the database connection file
require 'dbcon.php';

// Query to fetch settings from the database
$query = "SELECT * FROM settings";
$result = mysqli_query($con, $query);

// Default lab name if the query fails or returns no result
$labName = "My Vivarium";

// Initialize sensor variables
$r1_temp = $r1_humi = $r1_illu = $r1_pres = $r2_temp = $r2_humi = $r2_illu = $r2_pres = "";

// Fetch the settings from the database
$settings = [];
while ($row = mysqli_fetch_assoc($result)) {
    $settings[$row['name']] = $row['value'];
}

// Set variables based on fetched settings
if (isset($settings['lab_name'])) {
    $labName = $settings['lab_name'];
}
if (isset($settings['url'])) {
    $url = $settings['url'];
}
if (isset($settings['r1_temp'])) {
    $r1_temp = $settings['r1_temp'];
}
if (isset($settings['r1_humi'])) {
    $r1_humi = $settings['r1_humi'];
}
if (isset($settings['r1_illu'])) {
    $r1_illu = $settings['r1_illu'];
}
if (isset($settings['r1_pres'])) {
    $r1_pres = $settings['r1_pres'];
}
if (isset($settings['r2_temp'])) {
    $r2_temp = $settings['r2_temp'];
}
if (isset($settings['r2_humi'])) {
    $r2_humi = $settings['r2_humi'];
}
if (isset($settings['r2_illu'])) {
    $r2_illu = $settings['r2_illu'];
}
if (isset($settings['r2_pres'])) {
    $r2_pres = $settings['r2_pres'];
}
?>

<!DOCTYPE html>
<html lang="en" data-bs-theme="light">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <!-- Favicon and icons for different devices -->
    <link rel="icon" href="./icons/favicon.ico" type="image/x-icon">
    <link rel="apple-touch-icon" sizes="180x180" href="./icons/apple-touch-icon.png">
    <link rel="icon" type="image/png" sizes="32x32" href="./icons/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="./icons/favicon-16x16.png">
    <link rel="icon" sizes="192x192" href="./icons/android-chrome-192x192.png">
    <link rel="icon" sizes="512x512" href="./icons/android-chrome-512x512.png">

    <!-- PWA Manifest -->
    <link rel="manifest" href="manifest.json" crossorigin="use-credentials">

    <!-- PWA Meta Tags -->
    <meta name="theme-color" content="#0d6efd">
    <meta name="mobile-web-app-capable" content="yes">

    <!-- Apple PWA Meta Tags -->
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="apple-mobile-web-app-title" content="MyVivarium">

    <!-- Microsoft Tile -->
    <meta name="msapplication-TileImage" content="./icons/android-chrome-192x192.png">
    <meta name="msapplication-TileColor" content="#0d6efd">

    <!-- Bootstrap 5.3 CSS (supports dark mode via data-bs-theme) -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">

    <!-- Font Awesome for icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.3/css/all.min.css">

    <!-- Google Font: Poppins -->
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;700&display=swap" rel="stylesheet">

    <!-- Select2 CSS (loaded here so dark mode overrides below take effect) -->
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />

    <style>
        .header {
            display: flex;
            flex-wrap: wrap;
            justify-content: center;
            align-items: center;
            background-color: #343a40;
            color: white;
            padding: 1rem;
            text-align: center;
            margin: 0;
        }

        .header .logo-container {
            padding: 0;
            margin: 0;
        }

        .header img.header-logo {
            width: 300px;
            height: auto;
            display: block;
            margin: 0;
        }

        .header h2 {
            margin-left: 15px;
            margin-bottom: 0;
            margin-top: 12px;
            font-size: clamp(1.4rem, 5vw, 3.5rem);
            white-space: nowrap;
            font-family: 'Poppins', sans-serif;
            font-weight: 500;
        }

        /* Responsive styling for smaller screens */
        @media (max-width: 576px) {
            .header {
                padding: 0.5rem;
            }

            .header h2 {
                margin-left: 8px;
                margin-top: 5px;
            }

            .header img.header-logo {
                width: 120px;
            }
        }

        /* Styling for navigation container */
        .nav-container {
            background-color: #343a40;
            padding: 0px 0px 20px 0px;
            text-align: center;
            margin: 0;
        }

        .nav .btn {
            margin: 0 5px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            height: 40px;
            padding: 0 14px;
            font-size: 0.9rem;
        }

        /* Icon-only buttons: square, same height */
        #darkModeToggle {
            width: 40px;
            padding: 0;
        }

        .nav .btn-danger {
            width: 40px;
            padding: 0;
        }

        .dropdown-menu {
            min-width: auto;
        }

        /* Desktop nav: horizontal layout with labels */
        @media (min-width: 577px) {
            .nav-collapsible {
                display: flex !important;
                flex-wrap: wrap;
                justify-content: center;
                align-items: center;
            }
        }

        /* Mobile nav: icon-only bar */
        @media (max-width: 576px) {
            .header {
                flex-direction: column;
                align-items: center;
            }
            .header h2 {
                margin-left: 0;
                margin-top: 6px;
                text-align: center;
            }
            .nav-container {
                padding: 8px 0 12px 0;
            }
            .nav-collapsible {
                display: flex !important;
                flex-direction: row;
                justify-content: center;
                align-items: center;
                gap: 8px;
                width: auto;
                padding: 0;
            }
            /* Hide text labels on mobile */
            .nav-collapsible .nav-label {
                display: none;
            }
            /* Replace default caret with a small dot indicator on mobile */
            .nav-collapsible .dropdown-toggle::after {
                content: '';
                display: block;
                position: absolute;
                bottom: 4px;
                left: 50%;
                transform: translateX(-50%);
                width: 5px;
                height: 5px;
                border: none;
                border-radius: 50%;
                background-color: rgba(255, 255, 255, 0.6);
                margin: 0;
                vertical-align: unset;
            }
            /* Compact icon-only buttons */
            .nav-collapsible > .btn,
            .nav-collapsible > .dropdown > .btn,
            .nav-collapsible > #darkModeToggle {
                width: 44px;
                height: 44px;
                padding: 0;
                display: inline-flex;
                align-items: center;
                justify-content: center;
                font-size: 1.1rem;
                margin: 0;
                border-radius: 8px;
                position: relative;
            }
        }

        /* Scroll-to-top floating button */
        .scroll-to-top-btn {
            display: none;
            position: fixed;
            bottom: 80px;
            right: 20px;
            width: 44px;
            height: 44px;
            border-radius: 50%;
            background-color: #0d6efd;
            color: white;
            border: none;
            font-size: 1.2rem;
            cursor: pointer;
            z-index: 1050;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.25);
            transition: opacity 0.3s ease, transform 0.3s ease;
            align-items: center;
            justify-content: center;
        }
        .scroll-to-top-btn.visible {
            display: inline-flex;
        }
        .scroll-to-top-btn:hover {
            background-color: #0b5ed7;
            transform: translateY(-2px);
        }
    </style>
</head>

<body>
    <!-- Header Section -->
    <?php if ($demo === "yes") include('demo/demo-banner.php'); ?>
    <div class="header">
        <div class="logo-container">
            <a href="home.php">
                <img src="images/logo1.jpg" alt="Logo" class="header-logo">
            </a>
        </div>
        <h2><?php echo htmlspecialchars($labName); ?></h2>
    </div>

    <!-- Navigation Menu Section -->
    <div class="nav-container">
        <nav class="nav justify-content-center flex-column align-items-center">
            <div class="nav-collapsible" id="navCollapsible">
                <a href="home.php" class="btn btn-primary" aria-label="Home">
                    <i class="fas fa-home"></i> <span class="nav-label">Home</span>
                </a>

                <!-- Dropdown for Dashboard -->
                <div class="dropdown">
                    <button class="btn btn-primary dropdown-toggle" type="button" id="dashboardMenuButton" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Dashboards">
                        <i class="fas fa-tachometer-alt"></i> <span class="nav-label">Dashboards</span>
                    </button>
                    <ul class="dropdown-menu" aria-labelledby="dashboardMenuButton">
                        <li><a class="dropdown-item" href="hc_dash.php">Holding Cage</a></li>
                        <li><a class="dropdown-item" href="bc_dash.php">Breeding Cage</a></li>
                        <?php
                        if (!empty($r1_temp) || !empty($r1_humi) || !empty($r1_illu) || !empty($r1_pres) || !empty($r2_temp) || !empty($r2_humi) || !empty($r2_illu) || !empty($r2_pres)) {
                            echo '<li><a class="dropdown-item" href="iot_sensors.php">IOT Sensors</a></li>';
                        }
                        ?>
                        <li><a class="dropdown-item" href="cage_lineage.php">Cage Lineage</a></li>
                    </ul>
                </div>

                <!-- Dropdown for Calendar -->
                <div class="dropdown">
                    <button class="btn btn-primary dropdown-toggle" type="button" id="calendarMenuButton" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Calendar">
                        <i class="fas fa-calendar-alt"></i> <span class="nav-label">Calendar</span>
                    </button>
                    <ul class="dropdown-menu" aria-labelledby="calendarMenuButton">
                        <li><a class="dropdown-item" href="calendar.php">Calendar</a></li>
                        <li><a class="dropdown-item" href="manage_tasks.php">Tasks</a></li>
                        <li><a class="dropdown-item" href="manage_reminder.php">Reminders</a></li>
                    </ul>
                </div>

                <!-- Dropdown for Settings -->
                <div class="dropdown">
                    <button class="btn btn-primary dropdown-toggle" type="button" id="settingsMenuButton" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Settings">
                        <i class="fas fa-cog"></i> <span class="nav-label">Settings</span>
                    </button>
                    <ul class="dropdown-menu" aria-labelledby="settingsMenuButton">
                        <li><a class="dropdown-item" href="user_profile.php">User Profile</a></li>
                        <?php
                        // Display Vivarium Manager menu for vivarium_manager and admin roles
                        if (isset($_SESSION['role']) &&
                            ($_SESSION['role'] === 'admin' || $_SESSION['role'] === 'vivarium_manager')) {
                            echo '<li><hr class="dropdown-divider"></li>';
                            echo '<li class="dropdown-header">Vivarium Management</li>';
                            echo '<li><a class="dropdown-item" href="vivarium_manager_notes.php">Maintenance Notes</a></li>';
                            echo '<li><a class="dropdown-item" href="activity_log.php">Activity Log</a></li>';
                        }

                        // Display admin options if the user is an admin
                        if (isset($_SESSION['role']) && $_SESSION['role'] === 'admin') {
                            echo '<li><hr class="dropdown-divider"></li>';
                            echo '<li class="dropdown-header">Administration</li>';
                            echo '<li><a class="dropdown-item" href="manage_users.php">Manage Users</a></li>';
                            echo '<li><a class="dropdown-item" href="manage_iacuc.php">Manage IACUC</a></li>';
                            echo '<li><a class="dropdown-item" href="manage_strain.php">Manage Strain</a></li>';
                            echo '<li><a class="dropdown-item" href="manage_lab.php">Manage Lab</a></li>';
                            echo '<li><a class="dropdown-item" href="export_data.php">Export CSV</a></li>';
                            echo '<li><hr class="dropdown-divider"></li>';
                        }
                        ?>
                        <li><a class="dropdown-item" href="logout.php">Logout</a></li>
                    </ul>
                </div>

                <!-- Dark Mode Toggle -->
                <button id="darkModeToggle" class="btn btn-outline-light" aria-label="Toggle dark mode">
                    <i class="fas fa-moon"></i>
                </button>

                <!-- Logout Button -->
                <a href="logout.php" class="btn btn-danger" aria-label="Logout" title="Logout">
                    <i class="fas fa-sign-out-alt"></i>
                </a>
            </div>
        </nav>
    </div>

    <!-- Scroll to Top Button -->
    <button id="scrollToTopBtn" class="scroll-to-top-btn" aria-label="Scroll to top" title="Back to top">
        <i class="fas fa-chevron-up"></i>
    </button>

    <!-- Bootstrap and jQuery JS -->
    <script src="https://ajax.googleapis.com/ajax/libs/jquery/3.5.1/jquery.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>

    <!-- Dark Mode Toggle Script -->
    <script>
    (function() {
        // Apply saved theme immediately to prevent flash
        const savedTheme = localStorage.getItem('mv-theme') || 'light';
        document.documentElement.setAttribute('data-bs-theme', savedTheme);

        document.addEventListener('DOMContentLoaded', function() {
            const toggle = document.getElementById('darkModeToggle');
            if (!toggle) return;

            // Set initial icon
            updateToggleIcon(toggle, savedTheme);

            toggle.addEventListener('click', function() {
                const current = document.documentElement.getAttribute('data-bs-theme');
                const next = current === 'dark' ? 'light' : 'dark';
                document.documentElement.setAttribute('data-bs-theme', next);
                localStorage.setItem('mv-theme', next);
                updateToggleIcon(toggle, next);
            });
        });

        function updateToggleIcon(btn, theme) {
            btn.innerHTML = theme === 'dark'
                ? '<i class="fas fa-sun"></i>'
                : '<i class="fas fa-moon"></i>';
            btn.title = theme === 'dark' ? 'Switch to Light Mode' : 'Switch to Dark Mode';
        }
    })();
    </script>

    <!-- Select2 Dark Mode & Scroll-to-Top -->
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        // Scroll-to-top button
        var scrollBtn = document.getElementById('scrollToTopBtn');
        if (scrollBtn) {
            window.addEventListener('scroll', function() {
                if (window.scrollY > 300) {
                    scrollBtn.classList.add('visible');
                } else {
                    scrollBtn.classList.remove('visible');
                }
            }, { passive: true });
            scrollBtn.addEventListener('click', function() {
                window.scrollTo({ top: 0, behavior: 'smooth' });
            });
        }

        // Inject Select2 dark mode styles after all CSS has loaded
        var s2style = document.createElement('style');
        s2style.textContent =
            '[data-bs-theme="dark"] .select2-dropdown { background-color: #454d55 !important; border-color: #565e66 !important; }' +
            '[data-bs-theme="dark"] .select2-container--default .select2-results__option { color: #dee2e6 !important; }' +
            '[data-bs-theme="dark"] .select2-container--default .select2-results__option[aria-selected=true] { background-color: #565e66 !important; color: #adb5bd !important; }' +
            '[data-bs-theme="dark"] .select2-container--default .select2-results__option--selected { background-color: #565e66 !important; color: #adb5bd !important; }' +
            '[data-bs-theme="dark"] .select2-container--default .select2-results__option--highlighted { background-color: #0d6efd !important; color: #fff !important; }' +
            '[data-bs-theme="dark"] .select2-container--default .select2-results__option--highlighted[aria-selected] { background-color: #0d6efd !important; color: #fff !important; }' +
            '[data-bs-theme="dark"] .select2-container--default .select2-selection--single { background-color: #454d55 !important; border-color: #565e66 !important; }' +
            '[data-bs-theme="dark"] .select2-container--default .select2-selection--single .select2-selection__rendered { color: #dee2e6 !important; }' +
            '[data-bs-theme="dark"] .select2-container--default .select2-selection--single .select2-selection__arrow b { border-color: #dee2e6 transparent transparent transparent !important; }' +
            '[data-bs-theme="dark"] .select2-container--default .select2-selection--single .select2-selection__placeholder { color: #6c757d !important; }' +
            '[data-bs-theme="dark"] .select2-container--default .select2-selection--multiple { background-color: #454d55 !important; border-color: #565e66 !important; }' +
            '[data-bs-theme="dark"] .select2-container--default .select2-selection--multiple .select2-selection__choice { background-color: #565e66 !important; border-color: #6c757d !important; color: #dee2e6 !important; }' +
            '[data-bs-theme="dark"] .select2-container--default .select2-selection--multiple .select2-selection__choice__remove { color: #adb5bd !important; }' +
            '[data-bs-theme="dark"] .select2-container--default .select2-search--dropdown .select2-search__field { background-color: #3d444d !important; border-color: #565e66 !important; color: #dee2e6 !important; }' +
            '[data-bs-theme="dark"] .select2-container--default .select2-search--inline .select2-search__field { color: #dee2e6 !important; }';
        document.head.appendChild(s2style);
    });
    </script>

    <!-- Dark Mode Overrides -->
    <style>
    [data-bs-theme="dark"] { --bs-body-bg: #3d444d; --bs-tertiary-bg: #454d55; }
    [data-bs-theme="dark"] .header { background-color: #343a40; }
    [data-bs-theme="dark"] .nav-container { background-color: #343a40; }
    [data-bs-theme="dark"] .container { background-color: #3d444d; }
    [data-bs-theme="dark"] .note-app-container { background-color: #454d55; }
    [data-bs-theme="dark"] .popup-form { background-color: #3d444d; border-color: #565e66; color: #dee2e6; }
    [data-bs-theme="dark"] .modal-header { background-color: #343a40; }
    [data-bs-theme="dark"] .card { background-color: #454d55; border-color: #565e66; }
    [data-bs-theme="dark"] .card-header { background-color: #3d444d; border-color: #565e66; color: #dee2e6; }
    [data-bs-theme="dark"] .card-body { background-color: #454d55; color: #dee2e6; }

    /* Popup close button */
    .popup-close-btn {
        position: absolute;
        top: 10px;
        right: 15px;
        background: none;
        border: none;
        font-size: 1.5rem;
        line-height: 1;
        color: var(--bs-body-color);
        cursor: pointer;
        opacity: 0.6;
        padding: 0;
        z-index: 1;
    }
    .popup-close-btn:hover { opacity: 1; }
    .popup-form, .view-popup-form { position: relative; }

    /* Make Select2 full width inside forms */
    .popup-form .select2-container,
    .modal .select2-container { width: 100% !important; }

    /* Select2 Dark Mode */
    [data-bs-theme="dark"] .select2-container--default .select2-selection--single,
    [data-bs-theme="dark"] .select2-container--default .select2-selection--multiple {
        background-color: #454d55 !important;
        border-color: #565e66 !important;
        color: #dee2e6 !important;
    }
    [data-bs-theme="dark"] .select2-container--default .select2-selection--single .select2-selection__rendered,
    [data-bs-theme="dark"] .select2-container--default .select2-selection--multiple .select2-selection__rendered {
        color: #dee2e6 !important;
    }
    [data-bs-theme="dark"] .select2-container--default .select2-selection--single .select2-selection__arrow b {
        border-color: #dee2e6 transparent transparent transparent !important;
    }
    [data-bs-theme="dark"] .select2-container--default .select2-selection--single .select2-selection__placeholder {
        color: #6c757d !important;
    }
    [data-bs-theme="dark"] .select2-container--default .select2-selection--multiple .select2-selection__choice {
        background-color: #565e66 !important;
        border-color: #6c757d !important;
        color: #dee2e6 !important;
    }
    [data-bs-theme="dark"] .select2-container--default .select2-selection--multiple .select2-selection__choice__remove {
        color: #adb5bd !important;
    }
    [data-bs-theme="dark"] .select2-container--default .select2-selection--multiple .select2-selection__choice__remove:hover {
        color: #fff !important;
    }
    [data-bs-theme="dark"] .select2-container--default .select2-search--inline .select2-search__field {
        color: #dee2e6 !important;
    }
    [data-bs-theme="dark"] .select2-dropdown {
        background-color: #454d55 !important;
        border-color: #565e66 !important;
    }
    [data-bs-theme="dark"] .select2-container--default .select2-search--dropdown .select2-search__field {
        background-color: #3d444d !important;
        border-color: #565e66 !important;
        color: #dee2e6 !important;
    }
    [data-bs-theme="dark"] .select2-container--default .select2-results__option {
        color: #dee2e6 !important;
    }
    [data-bs-theme="dark"] .select2-container--default .select2-results__option--highlighted[aria-selected] {
        background-color: #0d6efd !important;
        color: #fff !important;
    }
    [data-bs-theme="dark"] .select2-container--default .select2-results__option[aria-selected="true"] {
        background-color: #565e66 !important;
        color: #adb5bd !important;
    }
    [data-bs-theme="dark"] .select2-results__option[aria-selected="true"] {
        background-color: #565e66 !important;
        color: #adb5bd !important;
    }
    [data-bs-theme="dark"] .select2-container--default .select2-results__option--disabled {
        color: #6c757d !important;
    }

    /* Form controls dark mode */
    [data-bs-theme="dark"] .form-control,
    [data-bs-theme="dark"] .form-select {
        background-color: #454d55 !important;
        border-color: #565e66 !important;
        color: #dee2e6 !important;
    }
    [data-bs-theme="dark"] .form-control::placeholder {
        color: #6c757d !important;
    }
    [data-bs-theme="dark"] .form-control:focus,
    [data-bs-theme="dark"] .form-select:focus {
        background-color: #454d55 !important;
        border-color: #86b7fe !important;
        color: #dee2e6 !important;
        box-shadow: 0 0 0 0.25rem rgba(13, 110, 253, 0.25) !important;
    }
    [data-bs-theme="dark"] option {
        background-color: #454d55;
        color: #dee2e6;
    }
    [data-bs-theme="dark"] .form-control:disabled,
    [data-bs-theme="dark"] .form-control[readonly] {
        background-color: #3d444d !important;
        color: #adb5bd !important;
    }

    /* Alert dark mode */
    [data-bs-theme="dark"] .alert-warning {
        background-color: #664d03 !important;
        border-color: #997404 !important;
        color: #fff3cd !important;
    }
    [data-bs-theme="dark"] .alert-info {
        background-color: #055160 !important;
        border-color: #087990 !important;
        color: #cff4fc !important;
    }

    /* Modal form inputs dark mode */
    [data-bs-theme="dark"] .modal-content {
        background-color: #3d444d;
        border-color: #565e66;
        color: #dee2e6;
    }
    [data-bs-theme="dark"] .modal-body .form-control,
    [data-bs-theme="dark"] .modal-body .form-select,
    [data-bs-theme="dark"] .popup-form .form-control,
    [data-bs-theme="dark"] .popup-form .form-select,
    [data-bs-theme="dark"] .popup-form input[type="text"],
    [data-bs-theme="dark"] .popup-form input[type="date"],
    [data-bs-theme="dark"] .popup-form textarea,
    [data-bs-theme="dark"] .popup-form select {
        background-color: #454d55 !important;
        border-color: #565e66 !important;
        color: #dee2e6 !important;
    }
    [data-bs-theme="dark"] .popup-form label,
    [data-bs-theme="dark"] .view-popup-form label {
        color: #dee2e6;
    }

    /* Nav tab / btn-group outline dark mode */
    [data-bs-theme="dark"] .btn-outline-primary {
        color: #6ea8fe;
        border-color: #6ea8fe;
    }
    [data-bs-theme="dark"] .btn-outline-primary:hover {
        background-color: #0d6efd;
        color: #fff;
    }

    /* Home page card borders in dark mode */
    [data-bs-theme="dark"] .card {
        border-color: #565e66;
    }

    /* Session timeout warning modal */
    #sessionTimeoutModal {
        display: none;
        position: fixed;
        top: 0; left: 0; right: 0; bottom: 0;
        background: rgba(0,0,0,0.5);
        z-index: 9999;
        justify-content: center;
        align-items: center;
    }
    #sessionTimeoutModal.show { display: flex; }
    #sessionTimeoutModal .timeout-box {
        background: var(--bs-body-bg, #fff);
        color: var(--bs-body-color, #212529);
        border-radius: 8px;
        padding: 30px;
        max-width: 400px;
        text-align: center;
        box-shadow: 0 4px 20px rgba(0,0,0,0.3);
    }
    </style>

    <!-- Global Typography Scale -->
    <style>
    .content h1 { font-size: 1.55rem; font-weight: 600; }
    .content h2 { font-size: 1.35rem; font-weight: 600; }
    .content h3 { font-size: 1.2rem; font-weight: 600; }
    .content h4 { font-size: 1.1rem; font-weight: 600; }
    .content h5 { font-size: 1.0rem; font-weight: 600; }
    .content h6 { font-size: 0.9rem; font-weight: 600; }
    .content label { font-size: 0.9rem; }
    .content .form-text { font-size: 0.8rem; }
    .content .details-table th,
    .content .details-table td { font-size: 0.92rem; }
    .content .section-header h5 { font-size: 1.0rem; }
    .content .section-header > i { font-size: 1.05rem; }
    .content .badge { font-size: 0.8rem; }
    .content .timestamp { font-size: 0.85rem; }
    .content .warning-text { font-size: 0.85rem; }
    .content .note, .content .note1 { font-size: 0.82rem; color: var(--bs-secondary-color); }
    .content .char-count { font-size: 0.8rem; }
    .content .filter-group label { font-size: 0.85rem; }
    </style>

    <!-- Unified Search/Filter Bar Styles -->
    <style>
    .header-actions {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 15px;
        margin-top: 20px;
        flex-wrap: wrap;
        gap: 10px;
    }
    .search-box {
        flex: 1;
        max-width: 400px;
    }
    .filter-row {
        display: flex;
        flex-wrap: wrap;
        gap: 10px;
        margin-bottom: 15px;
        align-items: flex-end;
    }
    .filter-group {
        display: flex;
        flex-direction: column;
        gap: 4px;
    }
    .filter-group label {
        font-weight: bold;
        color: var(--bs-secondary-color);
    }
    .pagination-info {
        margin: 15px 0;
        color: var(--bs-secondary-color);
    }
    @media (max-width: 768px) {
        .header-actions {
            flex-direction: column;
            align-items: stretch;
        }
        .header-actions .btn:not(.input-group .btn) {
            align-self: flex-start;
        }
        .search-box {
            max-width: 100%;
        }
        .filter-row {
            flex-direction: column;
        }
    }
    </style>

    <!-- Unified Table Styles -->
    <style>
    .table-wrapper table,
    .table {
        width: 100%;
        border-collapse: collapse;
        border: 1px solid #e2e8f0;
        border-radius: 8px;
    }
    .table-wrapper th,
    .table-wrapper td,
    .table th,
    .table td {
        border: 1px solid #e9ecef;
        padding: 11px 14px;
        text-align: left;
        vertical-align: middle;
        word-wrap: break-word;
        overflow-wrap: break-word;
    }
    .table-wrapper thead,
    .table thead {
        background-color: #f1f5f9;
    }
    .table-wrapper thead th,
    .table thead th {
        font-weight: 600;
        text-align: center;
        color: #475569;
        border: 1px solid #cbd5e1;
        border-bottom: 2px solid #cbd5e1;
        font-size: 0.82rem;
        text-transform: uppercase;
        letter-spacing: 0.04em;
        padding: 12px 14px;
    }
    .table-wrapper tbody tr:hover,
    .table tbody tr:hover {
        background-color: #f8fafc;
    }
    .table-wrapper tbody tr:last-child td,
    .table tbody tr:last-child td {
        border-bottom: none;
    }

    /* Dark mode table overrides */
    [data-bs-theme="dark"] .table-wrapper table,
    [data-bs-theme="dark"] .table { border-color: #565e66; }
    [data-bs-theme="dark"] .table-wrapper th,
    [data-bs-theme="dark"] .table-wrapper td,
    [data-bs-theme="dark"] .table th,
    [data-bs-theme="dark"] .table td { border-color: #565e66; }
    [data-bs-theme="dark"] .details-table th,
    [data-bs-theme="dark"] .details-table td { border-bottom-color: #565e66; }
    [data-bs-theme="dark"] .table-wrapper thead,
    [data-bs-theme="dark"] .table thead { background-color: #343a40; }
    [data-bs-theme="dark"] .table-wrapper thead th,
    [data-bs-theme="dark"] .table thead th { color: #adb5bd; border-bottom-color: #565e66; }
    [data-bs-theme="dark"] .table-wrapper tbody tr:hover,
    [data-bs-theme="dark"] .table tbody tr:hover { background-color: rgba(255, 255, 255, 0.05); }

    /* Unified Action Button Styles */
    .table-actions,
    .action-buttons,
    .action-icons {
        display: flex;
        gap: 10px;
        flex-wrap: wrap;
        align-items: center;
        justify-content: center;
    }
    .action-buttons .btn,
    .table-actions .btn,
    .action-icons .btn {
        width: 34px;
        height: 34px;
        min-width: 34px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        padding: 0;
        border-radius: 8px;
        font-size: 0.82rem;
        transition: all 0.2s ease;
        box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
    }
    .action-buttons .btn:hover,
    .table-actions .btn:hover,
    .action-icons .btn:hover {
        transform: translateY(-1px);
        box-shadow: 0 3px 8px rgba(0, 0, 0, 0.15);
    }
    .action-buttons .btn i,
    .table-actions .btn i,
    .action-icons .btn i {
        font-size: 14px;
        margin: 0;
    }
    /* Make inline forms transparent to flex layout so buttons align in one row */
    .action-buttons form,
    .table-actions form,
    .action-icons form {
        display: contents;
    }

    /* Mobile Table Card Layout — only for tables with data-label cells */
    @media (max-width: 576px) {
        /* Disable table-responsive overflow on mobile so cards aren't clipped */
        .table-responsive:has(td[data-label]) {
            overflow: visible;
        }
        .table:has(td[data-label]) {
            display: block;
            width: 100%;
        }
        .table:has(td[data-label]) thead {
            display: none;
        }
        .table:has(td[data-label]) tbody {
            display: block;
            width: 100%;
        }
        .table:has(td[data-label]) tbody tr {
            display: block;
            margin-bottom: 15px;
            border: 1px solid var(--bs-border-color);
            border-radius: 8px;
            padding: 10px;
        }
        .table td[data-label] {
            display: block;
            width: 100% !important;
            padding: 4px 14px;
            border: none;
            border-bottom: 1px solid var(--bs-border-color);
            text-align: left;
            overflow-wrap: normal;
            word-wrap: normal;
            padding-top: 4px;
            padding-bottom: 10px;
            color: var(--bs-body-color);
        }
        .table td[data-label]:last-child {
            border-bottom: none;
        }
        .table td[data-label]::before {
            content: attr(data-label);
            display: block;
            font-weight: 700;
            text-transform: uppercase;
            font-size: 0.75rem;
            letter-spacing: 0.05em;
            color: var(--bs-secondary-color);
            margin-top: 6px;
            margin-bottom: 2px;
        }
        .table td[data-label="Actions"],
        .table td[data-label="Action"] {
            padding-left: 10px;
        }
        .table td[data-label="Actions"]::before,
        .table td[data-label="Action"]::before {
            display: none;
        }
        /* Restore flex layout for action cells (overrides display:block from td[data-label]) */
        .table td[data-label="Actions"].action-icons,
        .table td[data-label="Action"].action-icons,
        .table td[data-label="Actions"].action-buttons,
        .table td[data-label="Actions"].table-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            justify-content: flex-end;
        }
        .table td[data-label="Actions"] .action-buttons,
        .table td[data-label="Actions"] .table-actions,
        .table td[data-label="Action"] .action-buttons,
        .table td[data-label="Action"] .table-actions {
            justify-content: flex-end;
        }
    }
    </style>
    <!-- Session Timeout Warning -->
    <div id="sessionTimeoutModal">
        <div class="timeout-box">
            <h5><i class="fas fa-clock"></i> Session Expiring</h5>
            <p>Your session will expire in <strong id="timeoutCountdown">2:00</strong> minutes due to inactivity.</p>
            <button class="btn btn-primary" id="stayLoggedIn">Stay Logged In</button>
        </div>
    </div>
    <script>
    (function() {
        var SESSION_TIMEOUT = 30 * 60 * 1000;  // 30 min
        var WARNING_BEFORE = 2 * 60 * 1000;    // Warn 2 min before
        var warnTimer, logoutTimer, countdownInterval;

        function resetTimers() {
            clearTimeout(warnTimer);
            clearTimeout(logoutTimer);
            clearInterval(countdownInterval);
            var modal = document.getElementById('sessionTimeoutModal');
            if (modal) modal.classList.remove('show');

            warnTimer = setTimeout(showWarning, SESSION_TIMEOUT - WARNING_BEFORE);
            logoutTimer = setTimeout(function() {
                window.location.href = 'logout.php';
            }, SESSION_TIMEOUT);
        }

        function showWarning() {
            var modal = document.getElementById('sessionTimeoutModal');
            if (!modal) return;
            modal.classList.add('show');
            var remaining = WARNING_BEFORE / 1000;
            var cd = document.getElementById('timeoutCountdown');
            countdownInterval = setInterval(function() {
                remaining--;
                if (remaining <= 0) { clearInterval(countdownInterval); return; }
                var m = Math.floor(remaining / 60);
                var s = remaining % 60;
                if (cd) cd.textContent = m + ':' + (s < 10 ? '0' : '') + s;
            }, 1000);
        }

        document.addEventListener('DOMContentLoaded', function() {
            var stayBtn = document.getElementById('stayLoggedIn');
            if (stayBtn) {
                stayBtn.addEventListener('click', function() {
                    // Ping server to reset session
                    fetch(window.location.href, { method: 'HEAD', credentials: 'same-origin' });
                    resetTimers();
                });
            }
            // Reset on user activity
            ['click', 'keypress', 'scroll', 'mousemove'].forEach(function(evt) {
                document.addEventListener(evt, function() {
                    var modal = document.getElementById('sessionTimeoutModal');
                    if (modal && !modal.classList.contains('show')) {
                        resetTimers();
                    }
                }, { passive: true });
            });
            resetTimers();
        });
    })();
    </script>

    <!-- Service Worker Registration -->
    <script>
    if ('serviceWorker' in navigator) {
        window.addEventListener('load', function() {
            navigator.serviceWorker.register('/sw.js', { scope: '/' })
                .then(function(registration) {
                    // Check for updates periodically
                    registration.addEventListener('updatefound', function() {
                        var newWorker = registration.installing;
                        newWorker.addEventListener('statechange', function() {
                            if (newWorker.state === 'activated') {
                                console.log('MyVivarium PWA updated.');
                            }
                        });
                    });
                })
                .catch(function(error) {
                    console.log('SW registration failed:', error);
                });
        });
    }
    </script>

<!-- Note: Document structure (html/head/body) is managed by the including page -->