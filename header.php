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
    <link rel="manifest" href="manifest.json" crossorigin="use-credentials">

    <!-- Bootstrap 5.3 CSS (supports dark mode via data-bs-theme) -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">

    <!-- Font Awesome for icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.3/css/all.min.css">

    <!-- Google Font: Poppins -->
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;700&display=swap" rel="stylesheet">

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
            font-size: 3.5rem;
            white-space: nowrap;
            font-family: 'Poppins', sans-serif;
            font-weight: 500;
        }

        /* Responsive styling for smaller screens */
        @media (max-width: 576px) {
            .header h2 {
                font-size: 1.8rem;
                margin-bottom: 5px;
            }

            .header img.header-logo {
                width: 150px;
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
        }

        .dropdown-menu {
            min-width: auto;
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
        <nav class="nav justify-content-center">
            <a href="home.php" class="btn btn-primary">
                <i class="fas fa-home"></i> Home
            </a>

            <!-- Dropdown for Dashboard -->
            <div class="dropdown">
                <button class="btn btn-primary dropdown-toggle" type="button" id="dashboardMenuButton" data-bs-toggle="dropdown" aria-expanded="false">
                    <i class="fas fa-tachometer-alt"></i> Dashboards
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

            <!-- Dropdown for Settings -->
            <div class="dropdown">
                <button class="btn btn-primary dropdown-toggle" type="button" id="settingsMenuButton" data-bs-toggle="dropdown" aria-expanded="false">
                    <i class="fas fa-cog"></i> Settings
                </button>
                <ul class="dropdown-menu" aria-labelledby="settingsMenuButton">
                    <li><a class="dropdown-item" href="user_profile.php">User Profile</a></li>
                    <li><a class="dropdown-item" href="manage_tasks.php">Tasks &amp; Reminders</a></li>
                    <?php
                    // Display Vivarium Manager menu for vivarium_manager and admin roles
                    if (isset($_SESSION['role']) &&
                        ($_SESSION['role'] === 'admin' || $_SESSION['role'] === 'vivarium_manager')) {
                        echo '<li><hr class="dropdown-divider"></li>';
                        echo '<li class="dropdown-header">Vivarium Management</li>';
                        echo '<li><a class="dropdown-item" href="vivarium_manager_notes.php"><i class="fas fa-clipboard-list"></i> Maintenance Notes</a></li>';
                        echo '<li><a class="dropdown-item" href="activity_log.php"><i class="fas fa-history"></i> Activity Log</a></li>';
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
            <button id="darkModeToggle" class="btn btn-outline-light" style="margin-left: 5px;">
                <i class="fas fa-moon"></i>
            </button>
        </nav>
    </div>

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

    <!-- Dark Mode Overrides -->
    <style>
    [data-bs-theme="dark"] .header { background-color: #1a1d21; }
    [data-bs-theme="dark"] .nav-container { background-color: #1a1d21; }
    [data-bs-theme="dark"] .container { background-color: #212529; }
    [data-bs-theme="dark"] .note-app-container { background-color: #2b3035; }
    [data-bs-theme="dark"] .popup-form { background-color: #212529; border-color: #495057; color: #dee2e6; }
    [data-bs-theme="dark"] .modal-header { background-color: #1a1d21; }
    </style>

    <!-- Unified Table Styles -->
    <style>
    .table-wrapper table,
    .table {
        width: 100%;
        border-collapse: collapse;
        border: 1px solid var(--bs-border-color);
    }
    .table-wrapper th,
    .table-wrapper td,
    .table th,
    .table td {
        border: 1px solid var(--bs-border-color);
        padding: 8px 10px;
        text-align: left;
        vertical-align: middle;
        word-wrap: break-word;
        overflow-wrap: break-word;
    }
    .table-wrapper thead,
    .table thead {
        background-color: #343a40;
        color: #ffffff;
    }
    .table-wrapper thead th,
    .table thead th {
        font-weight: bold;
        text-align: center;
        border: 1px solid #495057;
    }
    [data-bs-theme="dark"] .table-wrapper th,
    [data-bs-theme="dark"] .table-wrapper td,
    [data-bs-theme="dark"] .table th,
    [data-bs-theme="dark"] .table td { border-color: #495057; }
    [data-bs-theme="dark"] .table-wrapper thead,
    [data-bs-theme="dark"] .table thead { background-color: #1a1d21; }
    </style>
<!-- Note: Document structure (html/head/body) is managed by the including page -->