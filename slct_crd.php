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

        // Handle PDF download via hidden iframe + html2pdf
        function handleDownloadPDF(event) {
            event.preventDefault();
            if (!validateSelection()) return;

            var selectedIds = document.getElementById("cageIds").selectedOptions;
            var ids = Array.from(selectedIds).map(option => option.value);
            var url = "prnt_crd.php?id=" + ids.join(",");

            // Show loading state
            var btn = document.getElementById('pdfBtn');
            var originalText = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Generating...';

            // Load print page in hidden iframe
            var iframe = document.createElement('iframe');
            iframe.style.position = 'fixed';
            iframe.style.left = '-9999px';
            iframe.style.width = '11in';
            iframe.style.height = '8.5in';
            document.body.appendChild(iframe);

            iframe.onload = function() {
                var iframeDoc = iframe.contentDocument || iframe.contentWindow.document;
                var printArea = iframeDoc.getElementById('printArea');

                // Load html2pdf in the iframe context
                var script = iframeDoc.createElement('script');
                script.src = 'https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js';
                script.onload = function() {
                    var h2p = iframe.contentWindow.html2pdf;
                    var opt = {
                        margin: 0,
                        filename: 'cage_cards.pdf',
                        image: { type: 'jpeg', quality: 0.98 },
                        html2canvas: { scale: 2, useCORS: true },
                        jsPDF: { unit: 'in', format: 'letter', orientation: 'landscape' },
                        pagebreak: { mode: ['css'] }
                    };
                    h2p().set(opt).from(printArea).save().then(function() {
                        document.body.removeChild(iframe);
                        btn.disabled = false;
                        btn.innerHTML = originalText;
                    }).catch(function() {
                        document.body.removeChild(iframe);
                        btn.disabled = false;
                        btn.innerHTML = originalText;
                        alert('Error generating PDF. Please try again.');
                    });
                };
                iframeDoc.head.appendChild(script);
            };

            iframe.src = url;
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
                    <button type="submit" class="btn btn-primary" onclick="handleSubmit(event)"><i class="fas fa-print me-1"></i> Print Cage Card</button>
                    <button type="button" id="pdfBtn" class="btn btn-danger" onclick="handleDownloadPDF(event)"><i class="fas fa-file-pdf me-1"></i> Download PDF</button>
                    <button type="button" class="btn btn-secondary" onclick="goBack()">Go Back</button>
                </div>
            </form>
        </div>
    </div>
    <br>
    <?php include 'footer.php'; ?>
</body>

</html>
