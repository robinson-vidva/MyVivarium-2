<?php

/**
 * Manage Lab Page
 * 
 * This script allows logged-in users to view and update lab details, including the lab name, URL, timezone, IoT sensor links for two rooms,
 * and Cloudflare Turnstile secret and site keys.
 * 
 */

// Start a new session or resume the existing session
require 'session_config.php';

// Include the database connection file
require 'dbcon.php';

// Check if the user is logged in and has admin role
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    // Redirect non-admin users to the index page
    header("Location: index.php");
    exit; // Ensure no further code is executed
}

// CSRF token generation
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Fetch lab details from the database
$query = "SELECT * FROM settings";
$result = mysqli_query($con, $query);
$labData = [];
while ($row = mysqli_fetch_assoc($result)) {
    $labData[$row['name']] = $row['value'];
}

// Provide default values if no data is found
$defaultLabData = [
    'lab_name' => '',
    'url' => '',
    'timezone' => '',
    'r1_temp' => '',
    'r1_humi' => '',
    'r1_illu' => '',
    'r1_pres' => '',
    'r2_temp' => '',
    'r2_humi' => '',
    'r2_illu' => '',
    'r2_pres' => '',
    'cf-turnstile-secretKey' => '',
    'cf-turnstile-sitekey' => ''
];

$labData = array_merge($defaultLabData, $labData);

$updateMessage = '';

// Handle form submission for lab data update
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['update_lab'])) {

    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        die('CSRF token validation failed');
    }

    // Sanitize and fetch form inputs
    $inputFields = ['lab_name', 'url', 'timezone', 'r1_temp', 'r1_humi', 'r1_illu', 'r1_pres', 'r2_temp', 'r2_humi', 'r2_illu', 'r2_pres', 'cf-turnstile-secretKey', 'cf-turnstile-sitekey'];
    $inputData = [];
    foreach ($inputFields as $field) {
        $inputData[$field] = trim($_POST[$field] ?? '');
    }

    // Update or insert new data
    foreach ($inputData as $name => $value) {
        $checkQuery = "SELECT COUNT(*) as count FROM settings WHERE name = ?";
        $checkStmt = $con->prepare($checkQuery);
        $checkStmt->bind_param("s", $name);
        $checkStmt->execute();
        $checkStmt->bind_result($count);
        $checkStmt->fetch();
        $checkStmt->close();

        if ($count > 0) {
            // Update existing setting
            $updateQuery = "UPDATE settings SET value = ? WHERE name = ?";
            $updateStmt = $con->prepare($updateQuery);
            $updateStmt->bind_param("ss", $value, $name);
        } else {
            // Insert new setting
            $insertQuery = "INSERT INTO settings (name, value) VALUES (?, ?)";
            $updateStmt = $con->prepare($insertQuery);
            $updateStmt->bind_param("ss", $name, $value);
        }
        $updateStmt->execute();
        $updateStmt->close();
    }

    // Refresh lab data
    $result = mysqli_query($con, $query);
    $labData = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $labData[$row['name']] = $row['value'];
    }

    $labData = array_merge($defaultLabData, $labData);

    $updateMessage = "Lab information updated successfully.";
}

// Include the header file
require 'header.php';
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <!-- Meta tags for character encoding and responsive design -->
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Manage Lab</title>

    <!-- Inline CSS for styling -->
    <style>
        .lab-container {
            max-width: 900px;
            margin: 30px auto 50px;
            padding: 0 15px;
        }

        .section-card {
            background-color: var(--bs-tertiary-bg);
            border: 1px solid var(--bs-border-color);
            border-radius: 10px;
            padding: 24px;
            margin-bottom: 20px;
        }

        .section-header {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 18px;
            padding-bottom: 12px;
            border-bottom: 1px solid var(--bs-border-color);
        }

        .section-header i {
            font-size: 1.15rem;
            color: var(--bs-primary);
            width: 24px;
            text-align: center;
        }

        .section-header h5 {
            margin: 0;
            font-weight: 600;
            font-size: 1.05rem;
        }

        .form-group {
            margin-bottom: 16px;
        }

        .form-group label {
            font-weight: 500;
            font-size: 0.9rem;
            margin-bottom: 4px;
            color: var(--bs-body-color);
        }

        .form-group .form-text {
            font-size: 0.78rem;
        }

        .sensor-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
        }

        @media (max-width: 576px) {
            .sensor-grid {
                grid-template-columns: 1fr;
            }
        }

        .sensor-grid .form-group {
            margin-bottom: 0;
        }

        .sensor-grid textarea {
            font-size: 0.82rem;
            min-height: 60px;
        }

        .btn-submit {
            display: block;
            width: 100%;
            padding: 12px;
            border-radius: 8px;
            font-size: 1rem;
            font-weight: 600;
        }

        .collapse-toggle {
            cursor: pointer;
            user-select: none;
        }

        .collapse-toggle .chevron {
            transition: transform 0.2s ease;
            margin-left: auto;
            font-size: 0.85rem;
            color: var(--bs-secondary-color);
        }

        .collapse-toggle[aria-expanded="false"] .chevron {
            transform: rotate(-90deg);
        }
    </style>
</head>

<body>
    <div class="lab-container content">
        <h1 class="text-center mb-4">Manage Lab</h1>

        <?php if ($updateMessage) {
            echo "<div class='alert alert-success text-center'>" . htmlspecialchars($updateMessage) . "</div>";
        } ?>

        <form method="POST" action="">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">

            <!-- General Settings -->
            <div class="section-card">
                <div class="section-header">
                    <i class="fas fa-flask"></i>
                    <h5>General Settings</h5>
                </div>
                <div class="form-group">
                    <label for="lab_name">Lab Name</label>
                    <input type="text" class="form-control" id="lab_name" name="lab_name" value="<?php echo htmlspecialchars($labData['lab_name']); ?>">
                </div>
                <div class="form-group">
                    <label for="url">URL</label>
                    <input type="text" class="form-control" id="url" name="url" value="<?php echo htmlspecialchars($labData['url']); ?>" placeholder="domain.com">
                    <small class="form-text text-muted">Domain name only (e.g. domain.com)</small>
                </div>
                <div class="form-group mb-0">
                    <label for="timezone">Timezone</label>
                    <input type="text" class="form-control" id="timezone" name="timezone" value="<?php echo htmlspecialchars($labData['timezone']); ?>" placeholder="America/New_York">
                    <small class="form-text text-muted"><a href="https://www.php.net/manual/en/timezones.php" target="_blank">List of Supported Timezones</a></small>
                </div>
            </div>

            <!-- Room 1 Sensors -->
            <div class="section-card">
                <div class="section-header collapse-toggle" data-bs-toggle="collapse" data-bs-target="#room1Sensors" aria-expanded="true">
                    <i class="fas fa-thermometer-half"></i>
                    <h5>Room 1 Sensors</h5>
                    <i class="fas fa-chevron-down chevron"></i>
                </div>
                <div class="collapse show" id="room1Sensors">
                    <div class="sensor-grid">
                        <div class="form-group">
                            <label for="r1_temp"><i class="fas fa-temperature-high text-danger"></i> Temperature</label>
                            <textarea class="form-control" id="r1_temp" name="r1_temp" rows="2" oninput="adjustTextareaHeight(this)"><?php echo htmlspecialchars($labData['r1_temp']); ?></textarea>
                        </div>
                        <div class="form-group">
                            <label for="r1_humi"><i class="fas fa-tint text-info"></i> Humidity</label>
                            <textarea class="form-control" id="r1_humi" name="r1_humi" rows="2" oninput="adjustTextareaHeight(this)"><?php echo htmlspecialchars($labData['r1_humi']); ?></textarea>
                        </div>
                        <div class="form-group">
                            <label for="r1_illu"><i class="fas fa-sun text-warning"></i> Illumination</label>
                            <textarea class="form-control" id="r1_illu" name="r1_illu" rows="2" oninput="adjustTextareaHeight(this)"><?php echo htmlspecialchars($labData['r1_illu']); ?></textarea>
                        </div>
                        <div class="form-group">
                            <label for="r1_pres"><i class="fas fa-tachometer-alt text-success"></i> Pressure</label>
                            <textarea class="form-control" id="r1_pres" name="r1_pres" rows="2" oninput="adjustTextareaHeight(this)"><?php echo htmlspecialchars($labData['r1_pres']); ?></textarea>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Room 2 Sensors -->
            <div class="section-card">
                <div class="section-header collapse-toggle" data-bs-toggle="collapse" data-bs-target="#room2Sensors" aria-expanded="true">
                    <i class="fas fa-thermometer-half"></i>
                    <h5>Room 2 Sensors</h5>
                    <i class="fas fa-chevron-down chevron"></i>
                </div>
                <div class="collapse show" id="room2Sensors">
                    <div class="sensor-grid">
                        <div class="form-group">
                            <label for="r2_temp"><i class="fas fa-temperature-high text-danger"></i> Temperature</label>
                            <textarea class="form-control" id="r2_temp" name="r2_temp" rows="2" oninput="adjustTextareaHeight(this)"><?php echo htmlspecialchars($labData['r2_temp']); ?></textarea>
                        </div>
                        <div class="form-group">
                            <label for="r2_humi"><i class="fas fa-tint text-info"></i> Humidity</label>
                            <textarea class="form-control" id="r2_humi" name="r2_humi" rows="2" oninput="adjustTextareaHeight(this)"><?php echo htmlspecialchars($labData['r2_humi']); ?></textarea>
                        </div>
                        <div class="form-group">
                            <label for="r2_illu"><i class="fas fa-sun text-warning"></i> Illumination</label>
                            <textarea class="form-control" id="r2_illu" name="r2_illu" rows="2" oninput="adjustTextareaHeight(this)"><?php echo htmlspecialchars($labData['r2_illu']); ?></textarea>
                        </div>
                        <div class="form-group">
                            <label for="r2_pres"><i class="fas fa-tachometer-alt text-success"></i> Pressure</label>
                            <textarea class="form-control" id="r2_pres" name="r2_pres" rows="2" oninput="adjustTextareaHeight(this)"><?php echo htmlspecialchars($labData['r2_pres']); ?></textarea>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Security Settings -->
            <div class="section-card">
                <div class="section-header">
                    <i class="fas fa-shield-alt"></i>
                    <h5>Security</h5>
                </div>
                <div class="form-group">
                    <label for="cf-turnstile-sitekey">Cloudflare Turnstile Site Key</label>
                    <input type="text" class="form-control" id="cf-turnstile-sitekey" name="cf-turnstile-sitekey" value="<?php echo htmlspecialchars($labData['cf-turnstile-sitekey']); ?>">
                </div>
                <div class="form-group mb-0">
                    <label for="cf-turnstile-secretKey">Cloudflare Turnstile Secret Key</label>
                    <input type="password" class="form-control" id="cf-turnstile-secretKey" name="cf-turnstile-secretKey" value="<?php echo htmlspecialchars($labData['cf-turnstile-secretKey']); ?>">
                </div>
            </div>

            <button type="submit" class="btn btn-primary btn-submit" name="update_lab">
                <i class="fas fa-save"></i> Update Lab Information
            </button>
        </form>
    </div>

    <!-- Include the footer -->
    <?php include 'footer.php'; ?>

    <!-- JavaScript for adjusting textarea height dynamically -->
    <script>
        function adjustTextareaHeight(textarea) {
            textarea.style.height = 'auto';
            textarea.style.height = (textarea.scrollHeight) + 'px';
        }
        document.addEventListener('DOMContentLoaded', function() {
            document.querySelectorAll('textarea').forEach(function(textarea) {
                adjustTextareaHeight(textarea);
            });
        });
    </script>
</body>

</html>

<?php mysqli_close($con); ?>
