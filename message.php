<?php

/**
 * Display Session Message
 *
 * Renders a flash message as a Bootstrap alert, then clears it.
 *
 * Two flavors:
 *   $_SESSION['message']      — plain text. HTML-escaped on output (safe
 *                               default; use for anything containing user input).
 *   $_SESSION['message_html'] — trusted HTML (bold, links, <br>). Rendered raw,
 *                               so producers MUST escape any dynamic/user values
 *                               with htmlspecialchars() before embedding them.
 */

if (isset($_SESSION['message']) || isset($_SESSION['message_html'])) :
    $messageBody = isset($_SESSION['message_html'])
        ? $_SESSION['message_html']
        : htmlspecialchars($_SESSION['message']);
?>

    <!-- Bootstrap alert message -->
    <div class="alert alert-warning alert-dismissible fade show" role="alert">
        <strong>Hey! <?= htmlspecialchars($_SESSION['name'] ?? ''); ?>,</strong> <?= $messageBody; ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>

<?php
    // Unset after displaying so it shows once.
    unset($_SESSION['message'], $_SESSION['message_html']);
endif;
?>