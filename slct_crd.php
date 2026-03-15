<?php

/**
 * Select Cages for Printing (Unified)
 *
 * This script allows the user to select any number of cages (holding and/or breeding)
 * for printing their cage cards. It uses the Select2 library for an enhanced
 * multi-select dropdown and opens the selected cage IDs in a new tab for printing.
 * Cards are printed 4 per page (2x2 grid), with automatic page breaks.
 */

// Start a new session or resume the existing session
require 'session_config.php';

// Include the database connection file
require 'dbcon.php';

// Check if the user is not logged in, redirect them to index.php
if (!isset($_SESSION['username'])) {
    $currentUrl = urlencode($_SERVER['REQUEST_URI']);
    header("Location: index.php?redirect=$currentUrl");
    exit;
}

// Fetch all distinct holding cage IDs
$hcQuery = "SELECT DISTINCT h.`cage_id` FROM holding h INNER JOIN cages c ON h.cage_id = c.cage_id WHERE c.status = 'active'";
$hcResult = mysqli_query($con, $hcQuery);
$holdingCageIds = [];
while ($row = mysqli_fetch_assoc($hcResult)) {
    $holdingCageIds[] = $row['cage_id'];
}

// Fetch all distinct breeding cage IDs
$bcQuery = "SELECT DISTINCT b.`cage_id` FROM breeding b INNER JOIN cages c ON b.cage_id = c.cage_id WHERE c.status = 'active'";
$bcResult = mysqli_query($con, $bcQuery);
$breedingCageIds = [];
while ($row = mysqli_fetch_assoc($bcResult)) {
    $breedingCageIds[] = $row['cage_id'];
}

// Include the header file
require 'header.php';
?>

<!doctype html>
<html lang="en">

<head>
    <title>Select Cages for Printing</title>

    <!-- Select2 JS -->
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

    <style>
        body {
            margin: 0;
            padding: 0;
        }

        .container {
            max-width: 900px;
            background-color: var(--bs-tertiary-bg);
            padding: 20px;
            border-radius: 8px;
            margin: 20px auto;
            box-shadow: 0 0 10px rgba(0, 0, 0, 0.1);
        }

        .btn-container {
            margin-top: 20px;
        }

        .btn-container button {
            margin-right: 10px;
        }
    </style>

    <script>
        // Validate the selection of cage IDs
        function validateSelection() {
            var selectedIds = document.getElementById("cageIds").selectedOptions;
            if (selectedIds.length === 0) {
                alert("Please select at least one cage ID.");
                return false;
            }
            return true;
        }

        // Handle form submission to open a new tab with the selected cage IDs
        function handleSubmit(event) {
            event.preventDefault();
            if (validateSelection()) {
                var selectedIds = document.getElementById("cageIds").selectedOptions;
                var ids = Array.from(selectedIds).map(option => option.value);
                var queryString = "prnt_crd.php?id=" + ids.join(",");
                window.open(queryString, '_blank');
            }
        }

        // Initialize Select2 for the cage IDs dropdown
        $(document).ready(function() {
            $('#cageIds').select2({
                placeholder: "Select Cage IDs",
                allowClear: true,
                width: '100%'
            });
        });

        // Function to go back to the previous page
        function goBack() {
            window.history.back();
        }
    </script>
</head>

<body>
    <div class="content">
        <div class="container">
            <h4>Select Cages for Printing</h4>
            <br>
            <form>
                <div class="mb-3">
                    <label for="cageIds" class="form-label">Select Cage IDs:</label>
                    <br>
                    <select id="cageIds" name="id[]" class="form-select" multiple size="10">
                        <?php if (!empty($holdingCageIds)) : ?>
                            <optgroup label="Holding Cages">
                                <?php foreach ($holdingCageIds as $cageId) : ?>
                                    <option value="<?= htmlspecialchars($cageId) ?>"><?= htmlspecialchars($cageId) ?></option>
                                <?php endforeach; ?>
                            </optgroup>
                        <?php endif; ?>
                        <?php if (!empty($breedingCageIds)) : ?>
                            <optgroup label="Breeding Cages">
                                <?php foreach ($breedingCageIds as $cageId) : ?>
                                    <option value="<?= htmlspecialchars($cageId) ?>"><?= htmlspecialchars($cageId) ?></option>
                                <?php endforeach; ?>
                            </optgroup>
                        <?php endif; ?>
                    </select>
                </div>
                <br>
                <div class="btn-container">
                    <button type="submit" class="btn btn-primary btn-print" onclick="handleSubmit(event)">Print Cage Card</button>
                    <button type="button" class="btn btn-secondary" onclick="goBack()">Go Back</button>
                </div>
            </form>
        </div>
    </div>
    <br>
    <?php include 'footer.php'; ?>
</body>

</html>
