<!-- Footer Section -->

<!--
    This code defines the styling and content for the footer section of a webpage.
    The footer is styled with a dark background and centered white text. It dynamically displays
    the current year and lab name, ensuring special characters in the lab name are properly escaped for security.
-->

<style>
    html {
        margin: 0;
        padding: 0;
    }

    body {
        margin: 0;
        padding: 0;
        min-height: 100vh;
        display: flex;
        flex-direction: column;
    }

    .content {
        flex: 1;
    }

    footer {
        background-color: #343a40;
        padding: 20px 0;
        text-align: center;
        color: white;
        box-sizing: border-box;
        height: 60px;
        width: 100%;
        margin-top: auto;
    }

    .footer-text {
        margin: 0;
    }
</style>

<!-- Footer content -->
<footer>
    <!-- Dynamic footer text displaying the current year and lab name, with HTML special characters escaped -->
    <p class="footer-text">&copy; <?php echo date("Y"); ?>
        <?php
        if (isset($labName)) {
            echo htmlspecialchars($labName);
        } else {
            echo "My Vivarium";
        }
        ?>. All rights reserved.</p>
</footer>

<?php
// Inject the chatbot widget on every authenticated page that includes this
// footer. The widget hides itself when chatbot_enabled is off, no Groq key
// is configured, or the user is unauthenticated.
if (file_exists(__DIR__ . '/includes/chatbot_widget.php')) {
    include __DIR__ . '/includes/chatbot_widget.php';
}
?>