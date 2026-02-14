<?php

/**
 * Add New Breeding Cage Script
 *
 * This script handles the creation of new breeding cages in a laboratory management system. It starts a session,
 * regenerates the session ID to prevent session fixation attacks, and checks if the user is logged in.
 * It generates a CSRF token for form submissions, retrieves a list of Principal Investigators (PIs),
 * and processes the form submission for adding a new breeding cage. The script also includes the functionality
 * to add litter data associated with the breeding cage.
 */

// Start a new session or resume the existing session
require 'session_config.php';

// Include the database connection file
require 'dbcon.php';

// Include the activity log helper
require_once 'log_activity.php';

// Check if the user is not logged in, redirect them to index.php with the current URL for redirection after login
if (!isset($_SESSION['username'])) {
    $currentUrl = urlencode($_SERVER['REQUEST_URI']);
    header("Location: index.php?redirect=$currentUrl");
    exit; // Exit to ensure no further code is executed
}

// Generate CSRF token if not already set
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Get current user's ID for auto-selection
$currentUserQuery = "SELECT id FROM users WHERE username = ?";
$currentUserStmt = $con->prepare($currentUserQuery);
$currentUserStmt->bind_param("s", $_SESSION['username']);
$currentUserStmt->execute();
$currentUserResult = $currentUserStmt->get_result();
$currentUser = $currentUserResult->fetch_assoc();
$currentUserId = $currentUser['id'] ?? null;
$currentUserStmt->close();

// Query to retrieve users with initials and names
$userQuery = "SELECT id, initials, name FROM users WHERE status = 'approved'";
$userResult = $con->query($userQuery);

// Query to retrieve options where position is 'Principal Investigator'
$piQuery = "SELECT id, initials, name FROM users WHERE position = 'Principal Investigator' AND status = 'approved'";
$piResult = $con->query($piQuery);

// Store PI results in array for auto-selection logic
$piOptions = [];
while ($row = $piResult->fetch_assoc()) {
    $piOptions[] = $row;
}
$piResult->data_seek(0); // Reset pointer for form display

// Query to retrieve IACUC values
$iacucQuery = "SELECT iacuc_id, iacuc_title FROM iacuc";
$iacucResult = $con->query($iacucQuery);

// Clone cage data if clone parameter is provided
$cloneData = null;
$cloneUsers = [];
$cloneIacuc = [];
if (isset($_GET['clone'])) {
    $cloneId = $_GET['clone'];

    $cloneQuery = "SELECT b.*, c.pi_name, c.remarks, c.room, c.rack
                   FROM breeding b
                   LEFT JOIN cages c ON b.cage_id = c.cage_id
                   WHERE b.cage_id = ?";
    $cloneStmt = $con->prepare($cloneQuery);
    $cloneStmt->bind_param("s", $cloneId);
    $cloneStmt->execute();
    $cloneResult = $cloneStmt->get_result();
    if ($cloneResult->num_rows === 1) {
        $cloneData = $cloneResult->fetch_assoc();
    }
    $cloneStmt->close();

    // Fetch associated users and IACUC
    if ($cloneData) {
        $cuQuery = "SELECT user_id FROM cage_users WHERE cage_id = ?";
        $cuStmt = $con->prepare($cuQuery);
        $cuStmt->bind_param("s", $cloneId);
        $cuStmt->execute();
        $cuResult = $cuStmt->get_result();
        while ($row = $cuResult->fetch_assoc()) {
            $cloneUsers[] = $row['user_id'];
        }
        $cuStmt->close();

        $ciQuery = "SELECT iacuc_id FROM cage_iacuc WHERE cage_id = ?";
        $ciStmt = $con->prepare($ciQuery);
        $ciStmt->bind_param("s", $cloneId);
        $ciStmt->execute();
        $ciResult = $ciStmt->get_result();
        while ($row = $ciResult->fetch_assoc()) {
            $cloneIacuc[] = $row['iacuc_id'];
        }
        $ciStmt->close();
    }
}

// Check if the form is submitted
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Validate CSRF token
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        die('CSRF token validation failed');
    }

    // Retrieve and sanitize form data (only cage_id is required)
    $cage_id = trim($_POST['cage_id']);
    $pi_id = !empty($_POST['pi_name']) ? $_POST['pi_name'] : null;
    $room = !empty($_POST['room']) ? trim($_POST['room']) : null;
    $rack = !empty($_POST['rack']) ? trim($_POST['rack']) : null;
    $cross = !empty($_POST['cross']) ? $_POST['cross'] : null;
    $iacuc_ids = $_POST['iacuc'] ?? [];
    $user_ids = $_POST['user'] ?? [];
    $male_id = !empty($_POST['male_id']) ? $_POST['male_id'] : null;
    $female_id = !empty($_POST['female_id']) ? $_POST['female_id'] : null;
    $male_dob = !empty($_POST['male_dob']) ? $_POST['male_dob'] : null;
    $female_dob = !empty($_POST['female_dob']) ? $_POST['female_dob'] : null;
    $male_genotype = !empty($_POST['male_genotype']) ? trim($_POST['male_genotype']) : null;
    $male_parent_cage = !empty($_POST['male_parent_cage']) ? trim($_POST['male_parent_cage']) : null;
    $female_genotype = !empty($_POST['female_genotype']) ? trim($_POST['female_genotype']) : null;
    $female_parent_cage = !empty($_POST['female_parent_cage']) ? trim($_POST['female_parent_cage']) : null;
    $remarks = $_POST['remarks'];

    // Check if the cage_id already exists in the cages table
    $check_query = $con->prepare("SELECT * FROM cages WHERE cage_id = ?");
    $check_query->bind_param("s", $cage_id);
    $check_query->execute();
    $check_result = $check_query->get_result();

    if ($check_result->num_rows > 0) {
        // Set an error message if cage_id already exists
        $_SESSION['message'] = "Cage ID '$cage_id' already exists. Please use a different Cage ID.";
    } else {
        // Insert into the cages table
        $insert_cage_query = $con->prepare("INSERT INTO cages (`cage_id`, `pi_name`, `remarks`, `room`, `rack`) VALUES (?, ?, ?, ?, ?)");
        $insert_cage_query->bind_param("sssss", $cage_id, $pi_id, $remarks, $room, $rack);

        // Insert into the breeding table
        $insert_breeding_query = $con->prepare("INSERT INTO breeding (`cage_id`, `cross`, `male_id`, `female_id`, `male_dob`, `female_dob`, `male_genotype`, `male_parent_cage`, `female_genotype`, `female_parent_cage`) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $insert_breeding_query->bind_param("ssssssssss", $cage_id, $cross, $male_id, $female_id, $male_dob, $female_dob, $male_genotype, $male_parent_cage, $female_genotype, $female_parent_cage);

        // Execute the statements and check if they were successful
        if ($insert_cage_query->execute() && $insert_breeding_query->execute()) {
            // Set a success message
            $_SESSION['message'] = "New breeding cage added successfully.";

            // Log activity
            log_activity($con, 'create', 'cage', $cage_id, 'Created breeding cage');

            // Insert IACUC associations
            foreach ($iacuc_ids as $iacuc_id) {
                $insert_cage_iacuc_query = $con->prepare("INSERT INTO cage_iacuc (`cage_id`, `iacuc_id`) VALUES (?, ?)");
                $insert_cage_iacuc_query->bind_param("ss", $cage_id, $iacuc_id);
                $insert_cage_iacuc_query->execute();
                $insert_cage_iacuc_query->close();
            }

            // Insert user associations
            foreach ($user_ids as $user_id) {
                $insert_cage_user_query = $con->prepare("INSERT INTO cage_users (`cage_id`, `user_id`) VALUES (?, ?)");
                $insert_cage_user_query->bind_param("ss", $cage_id, $user_id);
                $insert_cage_user_query->execute();
                $insert_cage_user_query->close();
            }

            // Process litter data insertion if provided
            if (isset($_POST['dom'])) {
                $dom = $_POST['dom'];
                $litter_dob = $_POST['litter_dob'];
                $pups_alive = array_map(function ($value) {
                    return !empty($value) ? intval($value) : 0;
                }, $_POST['pups_alive']);
                $pups_dead = array_map(function ($value) {
                    return !empty($value) ? intval($value) : 0;
                }, $_POST['pups_dead']);
                $pups_male = array_map(function ($value) {
                    return !empty($value) ? intval($value) : 0;
                }, $_POST['pups_male']);
                $pups_female = array_map(function ($value) {
                    return !empty($value) ? intval($value) : 0;
                }, $_POST['pups_female']);
                $litter_remarks = $_POST['remarks_litter'];

                // Loop through each litter entry and insert into the database
                for ($i = 0; $i < count($dom); $i++) {
                    $insert_litter_query = $con->prepare("INSERT INTO litters (`cage_id`, `dom`, `litter_dob`, `pups_alive`, `pups_dead`, `pups_male`, `pups_female`, `remarks`) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                    $insert_litter_query->bind_param("ssssssss", $cage_id, $dom[$i], $litter_dob[$i], $pups_alive[$i], $pups_dead[$i], $pups_male[$i], $pups_female[$i], $litter_remarks[$i]);

                    // Execute the statement and check if it was successful
                    if ($insert_litter_query->execute()) {
                        // Append success message for litter data
                        $_SESSION['message'] .= " Litter data added successfully.";
                    } else {
                        // Append error message for litter data
                        $_SESSION['message'] .= " Failed to add litter data: " . $insert_litter_query->error;
                    }

                    // Close the prepared statement for litter data
                    $insert_litter_query->close();
                }
            }
        } else {
            // Set an error message if the cage insertion failed
            $_SESSION['message'] = "Failed to add new breeding cage.";
        }

        // Close the prepared statements for cage and breeding data
        $insert_cage_query->close();
        $insert_breeding_query->close();
    }

    // Close the check query prepared statement
    $check_query->close();

    // Redirect back to the main page
    header("Location: bc_dash.php");
    exit();
}

// Include the header file
require 'header.php';
?>

<!doctype html>
<html lang="en">

<head>
    <title>Add New Breeding Cage | <?php echo htmlspecialchars($labName); ?></title>

    <!-- Include Select2 CSS -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/select2/4.1.0-beta.1/css/select2.min.css" rel="stylesheet" />

    <!-- Include Select2 JavaScript -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/select2/4.1.0-beta.1/js/select2.min.js"></script>

    <style>
        .container {
            max-width: 900px;
            background-color: var(--bs-tertiary-bg);
            padding: 20px;
            border-radius: 8px;
            margin: auto;
        }

        .form-label {
            font-weight: bold;
        }

        .required-asterisk {
            color: red;
        }

        .warning-text {
            color: var(--bs-danger);
            font-size: 14px;
        }

        .select2-container .select2-selection--single {
            height: 35px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .select2-container--default .select2-selection--single .select2-selection__rendered {
            padding-right: 10px;
            padding-left: 10px;
        }

        .select2-container--default .select2-selection--single .select2-selection__arrow {
            height: 35px;
        }
    </style>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Function to get today's date in YYYY-MM-DD format
            function getCurrentDate() {
                const today = new Date();
                const yyyy = today.getFullYear();
                const mm = String(today.getMonth() + 1).padStart(2, '0');
                const dd = String(today.getDate()).padStart(2, '0');
                return `${yyyy}-${mm}-${dd}`;
            }

            // Function to set the max date to today for all date input fields
            function setMaxDate() {
                const currentDate = getCurrentDate();
                const dateFields = document.querySelectorAll('input[type="date"]');
                dateFields.forEach(field => {
                    field.setAttribute('max', currentDate);
                });
            }

            // Initial call to set max date on page load
            setMaxDate();

            // Function to dynamically add new litter entry
            function addLitter() {
                const litterDiv = document.createElement('div');
                litterDiv.className = 'litter-entry';

                litterDiv.innerHTML = `
            <hr>
            <div class="mb-3">
                <label for="dom[]" class="form-label">DOM <span class="required-asterisk">*</span></label>
                <input type="date" class="form-control" name="dom[]" required min="1900-01-01">
            </div>
            <div class="mb-3">
                <label for="litter_dob[]" class="form-label">Litter DOB <span class="required-asterisk">*</span></label>
                <input type="date" class="form-control" name="litter_dob[]" required min="1900-01-01">
            </div>
            <div class="mb-3">
                <label for="pups_alive[]" class="form-label">Pups Alive <span class="required-asterisk">*</span></label>
                <input type="number" class="form-control" name="pups_alive[]" required min="0" step="1">
            </div>
            <div class="mb-3">
                <label for="pups_dead[]" class="form-label">Pups Dead <span class="required-asterisk">*</span></label>
                <input type="number" class="form-control" name="pups_dead[]" required min="0" step="1">
            </div>
            <div class="mb-3">
                <label for="pups_male[]" class="form-label">Pups Male</label>
                <input type="number" class="form-control" name="pups_male[]" min="0" step="1">
            </div>
            <div class="mb-3">
                <label for="pups_female[]" class="form-label">Pups Female</label>
                <input type="number" class="form-control" name="pups_female[]" min="0" step="1">
            </div>
            <div class="mb-3">
                <label for="remarks_litter[]" class="form-label">Remarks</label>
                <textarea class="form-control" name="remarks_litter[]" oninput="adjustTextareaHeight(this)"></textarea>
            </div>
            <button type="button" class="btn btn-danger" onclick="removeLitter(this)">Remove</button>
        `;

                document.getElementById('litterEntries').appendChild(litterDiv);

                // Apply max date to new date fields
                setMaxDate();
            }

            // Function to adjust the height of the textarea dynamically
            function adjustTextareaHeight(element) {
                element.style.height = "auto";
                element.style.height = (element.scrollHeight) + "px";
            }

            // Function to remove a litter entry dynamically
            function removeLitter(element) {
                element.parentElement.remove();
            }

            // Ensure the functions are available globally
            window.addLitter = addLitter;
            window.removeLitter = removeLitter;
        });


        // Function to navigate back to the previous page
        function goBack() {
            window.history.back();
        }

        // Function to validate date format & provide feedback
        document.addEventListener('DOMContentLoaded', function() {
            // Function to validate date
            function validateDate(dateString) {
                // Allow empty dates since date fields are now optional
                if (!dateString || dateString.trim() === '') return true;

                const regex = /^\d{4}-\d{2}-\d{2}$/;
                if (!dateString.match(regex)) return false;

                const date = new Date(dateString);
                const now = new Date();
                const year = date.getFullYear();

                // Check if the date is valid and within the range 1900-2099 and not in the future
                return date && !isNaN(date) && year >= 1900 && date <= now;
            }

            // Function to attach event listeners to date fields
            function attachDateValidation() {
                const dateFields = document.querySelectorAll('input[type="date"]');
                dateFields.forEach(field => {
                    if (!field.dataset.validated) { // Check if already validated
                        const warningText = document.createElement('span');
                        warningText.style.color = 'red';
                        warningText.style.display = 'none';
                        field.parentNode.appendChild(warningText);

                        field.addEventListener('input', function() {
                            const dateValue = field.value;
                            const isValidDate = validateDate(dateValue);
                            if (!isValidDate) {
                                warningText.textContent = 'Invalid Date. Please enter a valid date.';
                                warningText.style.display = 'block';
                            } else {
                                warningText.textContent = '';
                                warningText.style.display = 'none';
                            }
                        });

                        // Mark the field as validated
                        field.dataset.validated = 'true';
                    }
                });
            }

            // Initial call to validate existing date fields
            attachDateValidation();

            // Observe the form for changes (e.g., new nodes added dynamically)
            const form = document.querySelector('form');
            const observer = new MutationObserver(() => {
                attachDateValidation(); // Reattach validation to new nodes
            });

            // Start observing the form
            observer.observe(form, {
                childList: true,
                subtree: true
            });

            // Prevent form submission if dates are invalid
            form.addEventListener('submit', function(event) {
                let isValid = true;
                const dateFields = document.querySelectorAll('input[type="date"]');
                dateFields.forEach(field => {
                    const dateValue = field.value;
                    const warningText = field.nextElementSibling;
                    if (!validateDate(dateValue)) {
                        warningText.textContent = 'Invalid Date. Please enter a valid date.';
                        warningText.style.display = 'block';
                        isValid = false;
                    }
                });
                if (!isValid) {
                    event.preventDefault(); // Prevent form submission if any date is invalid
                }
            });
        });

        // Initialize Select2 for the user dropdown
        $(document).ready(function() {
            $('#user').select2({
                placeholder: "Select User(s)",
                allowClear: true
            });

            $('#iacuc').select2({
                placeholder: "Select IACUC",
                allowClear: true,
                templateResult: function(data) {
                    if (!data.id) {
                        return data.text;
                    }
                    var $result = $('<span>' + data.text + '</span>');
                    $result.attr('title', data.element.title);
                    return $result;
                }
            });

            // Information Completeness Tracking
            function calculateCompleteness() {
                const fields = {
                    critical: ['male_id', 'female_id'],
                    important: ['pi_name', 'cross', 'iacuc', 'user'],
                    useful: ['male_dob', 'female_dob']
                };

                let totalFields = 0;
                let filledFields = 0;
                let missingCritical = [];
                let missingImportant = [];
                let missingUseful = [];

                // Count critical fields
                fields.critical.forEach(fieldId => {
                    totalFields++;
                    const field = document.getElementById(fieldId);
                    if (field && field.value && field.value.trim() !== '') {
                        filledFields++;
                    } else {
                        missingCritical.push(field ? field.previousElementSibling.textContent.split(' ')[0] + ' ' + field.previousElementSibling.textContent.split(' ')[1] : fieldId);
                    }
                });

                // Count important fields
                fields.important.forEach(fieldId => {
                    totalFields++;
                    const field = document.getElementById(fieldId);
                    if (field) {
                        if (field.multiple) {
                            // For Select2 multiselect
                            const selectedValues = $(field).val();
                            if (selectedValues && selectedValues.length > 0 && selectedValues[0] !== '') {
                                filledFields++;
                            } else {
                                missingImportant.push(field.previousElementSibling.textContent.split(' ')[0]);
                            }
                        } else if (field.value && field.value.trim() !== '') {
                            filledFields++;
                        } else {
                            missingImportant.push(field.previousElementSibling.textContent.split(' ')[0]);
                        }
                    }
                });

                // Count useful fields
                fields.useful.forEach(fieldId => {
                    totalFields++;
                    const field = document.getElementById(fieldId);
                    if (field && field.value && field.value.trim() !== '') {
                        filledFields++;
                    } else {
                        missingUseful.push(field ? field.previousElementSibling.textContent.split(' ')[0] + ' ' + field.previousElementSibling.textContent.split(' ')[1] : fieldId);
                    }
                });

                const percentage = Math.round((filledFields / totalFields) * 100);

                // Update completeness bar
                $('#completeness-bar').css('width', percentage + '%');
                $('#completeness-bar').attr('aria-valuenow', percentage);
                $('#completeness-bar').text(percentage + '%');
                $('#completeness-percentage').text(percentage + '%');

                // Change bar color based on completion
                $('#completeness-bar').removeClass('bg-danger bg-warning bg-success');
                if (percentage < 50) {
                    $('#completeness-bar').addClass('bg-danger');
                } else if (percentage < 80) {
                    $('#completeness-bar').addClass('bg-warning');
                } else {
                    $('#completeness-bar').addClass('bg-success');
                }

                // Show missing fields
                let missingText = '';
                if (missingCritical.length > 0) {
                    missingText += '<strong class="text-danger">Critical fields missing:</strong> ' + missingCritical.join(', ') + '<br>';
                }
                if (missingImportant.length > 0) {
                    missingText += '<strong class="text-warning">Important fields missing:</strong> ' + missingImportant.join(', ') + '<br>';
                }
                if (missingUseful.length > 0) {
                    missingText += '<strong class="text-muted">Useful fields missing:</strong> ' + missingUseful.join(', ');
                }

                if (percentage === 100) {
                    missingText = '<strong class="text-success">✓ All information complete!</strong>';
                }

                $('#missing-fields').html(missingText);
                $('#completeness-alert').show();
            }

            // Calculate on page load
            calculateCompleteness();

            // Recalculate when fields change
            $('form input, form select, form textarea').on('change keyup', calculateCompleteness);
            $('#user, #iacuc').on('select2:select select2:unselect', calculateCompleteness);
        });
    </script>
</head>

<body>

    <div class="container content mt-4">

        <h4>Add New Breeding Cage<?php if ($cloneData): ?> <small class="text-muted">(Cloning from <?= htmlspecialchars($_GET['clone']); ?>)</small><?php endif; ?></h4>

        <?php include('message.php'); ?>

        <p class="warning-text">Only <span class="required-asterisk">Cage ID</span> is required. Other fields can be added later.</p>

        <div id="completeness-alert" class="alert alert-warning" style="display: none;">
            <strong>Information Completeness:</strong> <span id="completeness-percentage">0%</span>
            <div class="progress mt-2" style="height: 20px;">
                <div id="completeness-bar" class="progress-bar" role="progressbar" style="width: 0%;" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100">0%</div>
            </div>
            <div id="missing-fields" class="mt-2"></div>
        </div>

        <form method="POST" id="cage-form">

            <!-- CSRF token field -->
            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">

            <div class="mb-3">
                <label for="cage_id" class="form-label">Cage ID <span class="required-asterisk">*</span></label>
                <input type="text" class="form-control" id="cage_id" name="cage_id" required>
            </div>

            <div class="mb-3">
                <label for="pi_name" class="form-label">PI Name</label>
                <select class="form-control" id="pi_name" name="pi_name" data-field-type="important">
                    <option value="">Select PI</option>
                    <?php
                    // Populate dropdown with options from the database
                    // Auto-select if only one PI exists, or use cloneData if cloning
                    $isFirst = true;
                    $shouldAutoSelect = (count($piOptions) === 1);
                    foreach ($piOptions as $row) {
                        $pi_id = htmlspecialchars($row['id']);
                        $pi_initials = htmlspecialchars($row['initials']);
                        $pi_name = htmlspecialchars($row['name']);
                        if ($cloneData) {
                            $selected = ($cloneData['pi_name'] == $row['id']) ? 'selected' : '';
                        } else {
                            $selected = ($shouldAutoSelect || ($isFirst && count($piOptions) > 0)) ? 'selected' : '';
                        }
                        echo "<option value='$pi_id' $selected>$pi_initials [$pi_name]</option>";
                        $isFirst = false;
                    }
                    ?>
                </select>
            </div>

            <div class="mb-3">
                <label for="room" class="form-label">Room</label>
                <input type="text" class="form-control" id="room" name="room" value="<?= htmlspecialchars($cloneData['room'] ?? ''); ?>">
            </div>

            <div class="mb-3">
                <label for="rack" class="form-label">Rack</label>
                <input type="text" class="form-control" id="rack" name="rack" value="<?= htmlspecialchars($cloneData['rack'] ?? ''); ?>">
            </div>

            <div class="mb-3">
                <label for="cross" class="form-label">Cross <span class="badge bg-info">Important</span></label>
                <input type="text" class="form-control" id="cross" name="cross" data-field-type="important" value="<?= htmlspecialchars($cloneData['cross'] ?? ''); ?>">
            </div>

            <div class="mb-3">
                <label for="iacuc" class="form-label">IACUC <span class="badge bg-info">Important</span></label>
                <select class="form-control" id="iacuc" name="iacuc[]" multiple data-field-type="important">
                    <option value="" disabled>Select IACUC</option>
                    <?php
                    // Populate the dropdown with IACUC values from the database
                    while ($iacucRow = $iacucResult->fetch_assoc()) {
                        $iacuc_id = htmlspecialchars($iacucRow['iacuc_id']);
                        $iacuc_title = htmlspecialchars($iacucRow['iacuc_title']);
                        $truncated_title = strlen($iacuc_title) > 40 ? substr($iacuc_title, 0, 40) . '...' : $iacuc_title;
                        $iacucSelected = in_array($iacucRow['iacuc_id'], $cloneIacuc) ? 'selected' : '';
                        echo "<option value='$iacuc_id' title='$iacuc_title' $iacucSelected>$iacuc_id | $truncated_title</option>";
                    }
                    ?>
                </select>
            </div>

            <div class="mb-3">
                <label for="user" class="form-label">User <span class="badge bg-info">Important</span></label>
                <select class="form-control" id="user" name="user[]" multiple data-field-type="important">
                    <?php
                    // Populate the dropdown with options from the database
                    // Auto-select current user, or use cloneUsers if cloning
                    while ($userRow = $userResult->fetch_assoc()) {
                        $user_id = htmlspecialchars($userRow['id']);
                        $initials = htmlspecialchars($userRow['initials']);
                        $name = htmlspecialchars($userRow['name']);
                        if ($cloneData) {
                            $selected = in_array($userRow['id'], $cloneUsers) ? 'selected' : '';
                        } else {
                            $selected = ($currentUserId && $user_id == $currentUserId) ? 'selected' : '';
                        }
                        echo "<option value='$user_id' $selected>$initials [$name]</option>";
                    }
                    ?>
                </select>
            </div>

            <div class="mb-3">
                <label for="male_id" class="form-label">Male ID <span class="badge bg-warning">Critical</span></label>
                <input type="text" class="form-control" id="male_id" name="male_id" data-field-type="critical" value="<?= htmlspecialchars($cloneData['male_id'] ?? ''); ?>">
            </div>

            <div class="mb-3">
                <label for="female_id" class="form-label">Female ID <span class="badge bg-warning">Critical</span></label>
                <input type="text" class="form-control" id="female_id" name="female_id" data-field-type="critical" value="<?= htmlspecialchars($cloneData['female_id'] ?? ''); ?>">
            </div>

            <div class="mb-3">
                <label for="male_genotype" class="form-label">Male Genotype</label>
                <input type="text" class="form-control" id="male_genotype" name="male_genotype" value="<?= htmlspecialchars($cloneData['male_genotype'] ?? ''); ?>">
            </div>

            <div class="mb-3">
                <label for="male_dob" class="form-label">Male DOB <span class="badge bg-secondary">Useful</span></label>
                <input type="date" class="form-control" id="male_dob" name="male_dob" min="1900-01-01" data-field-type="useful" value="<?= htmlspecialchars($cloneData['male_dob'] ?? ''); ?>">
            </div>

            <div class="mb-3">
                <label for="male_parent_cage" class="form-label">Male Source / Parent Cage</label>
                <input type="text" class="form-control" id="male_parent_cage" name="male_parent_cage" placeholder="e.g. Jax Lab, cage ID, or other source" value="<?= htmlspecialchars($cloneData['male_parent_cage'] ?? ''); ?>">
            </div>

            <div class="mb-3">
                <label for="female_genotype" class="form-label">Female Genotype</label>
                <input type="text" class="form-control" id="female_genotype" name="female_genotype" value="<?= htmlspecialchars($cloneData['female_genotype'] ?? ''); ?>">
            </div>

            <div class="mb-3">
                <label for="female_dob" class="form-label">Female DOB <span class="badge bg-secondary">Useful</span></label>
                <input type="date" class="form-control" id="female_dob" name="female_dob" min="1900-01-01" data-field-type="useful" value="<?= htmlspecialchars($cloneData['female_dob'] ?? ''); ?>">
            </div>

            <div class="mb-3">
                <label for="female_parent_cage" class="form-label">Female Source / Parent Cage</label>
                <input type="text" class="form-control" id="female_parent_cage" name="female_parent_cage" placeholder="e.g. Jax Lab, cage ID, or other source" value="<?= htmlspecialchars($cloneData['female_parent_cage'] ?? ''); ?>">
            </div>

            <div class="mb-3">
                <label for="remarks" class="form-label">Remarks</label>
                <textarea class="form-control" id="remarks" name="remarks" oninput="adjustTextareaHeight(this)"><?= htmlspecialchars($cloneData['remarks'] ?? ''); ?></textarea>
            </div>

            <!-- Litter Data Section -->
            <div class="mt-4">
                <h5>Litter Data</h5>
                <div id="litterEntries">
                    <!-- Litter entries will be added here dynamically -->
                </div>
                <button type="button" class="btn btn-success mt-3" onclick="addLitter()">Add Litter Entry</button>
            </div>

            <br>

            <button type="submit" class="btn btn-primary">Add Cage</button>
            <button type="button" class="btn btn-secondary" onclick="goBack()">Go Back</button>

        </form>
    </div>

    <br>
    <?php include 'footer.php'; ?>
</body>

</html>