<?php

/**
 * Login Page
 * 
 * This script handles user login, displays login errors, and redirects authenticated users to their intended destination or home page. 
 * It also displays a carousel of images and highlights the features of the web application.
 * 
 */

// Disable error display in production (errors logged to server logs)
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);

// Start a new session or resume the existing session
require 'session_config.php';

// Include the database connection file
require 'dbcon.php';
require 'config.php'; // Include configuration file for SMTP details
require 'vendor/autoload.php'; // Include PHPMailer autoload file

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Query to fetch the lab name, URL, and Turnstile keys from the settings table
$labQuery = "SELECT name, value FROM settings WHERE name IN ('lab_name', 'url', 'cf-turnstile-secretKey', 'cf-turnstile-sitekey')";
$labResult = mysqli_query($con, $labQuery);

// Default values if the query fails or returns no result
$labName = "My Vivarium";
$url = "";
$turnstileSecretKey = "";
$turnstileSiteKey = "";

while ($row = mysqli_fetch_assoc($labResult)) {
    if ($row['name'] === 'lab_name') {
        $labName = $row['value'];
    } elseif ($row['name'] === 'url') {
        $url = $row['value'];
    } elseif ($row['name'] === 'cf-turnstile-secretKey') {
        $turnstileSecretKey = $row['value'];
    } elseif ($row['name'] === 'cf-turnstile-sitekey') {
        $turnstileSiteKey = $row['value'];
    }
}

// Function to send confirmation email
function sendConfirmationEmail($to, $token)
{
    global $url;
    $confirmLink = "https://" . $url . "/confirm_email.php?token=$token";
    $subject = 'Email Confirmation';
    $message = "Please click the link below to confirm your email address:\n$confirmLink";

    $mail = new PHPMailer(true);
    try {
        //Server settings
        $mail->isSMTP();
        $mail->Host = SMTP_HOST;
        $mail->Port = SMTP_PORT;
        $mail->SMTPAuth = true;
        $mail->Username = SMTP_USERNAME;
        $mail->Password = SMTP_PASSWORD;
        $mail->SMTPSecure = SMTP_ENCRYPTION;

        //Recipients
        $mail->setFrom(SENDER_EMAIL, SENDER_NAME);
        $mail->addAddress($to);

        // Content
        $mail->isHTML(false);
        $mail->Subject = $subject;
        $mail->Body = $message;

        $mail->send();
    } catch (Exception $e) {
        error_log("Message could not be sent. Mailer Error: {$mail->ErrorInfo}");
    }
}

// Check if the user is already logged in
if (isset($_SESSION['name'])) {
    // Redirect to the specified URL or default to home.php
    if (isset($_GET['redirect'])) {
        $rurl = urldecode($_GET['redirect']);
        // Validate redirect URL to prevent open redirects
        // Only allow relative URLs starting with /  or page names
        if (preg_match('/^[a-zA-Z0-9_\-\.\/\?=&]+\.php/', $rurl) && !preg_match('/^(https?:)?\/\//', $rurl)) {
            header("Location: $rurl");
            exit;
        }
    }
    // Default redirect if validation fails or no redirect specified
    header("Location: home.php");
    exit;
}

// Handle login form submission
if (isset($_POST['login'])) {
    $username = $_POST['username'];
    $password = $_POST['password'];
    
    // Proceed with Turnstile verification only if Turnstile keys are set
    if (!empty($turnstileSiteKey) && !empty($turnstileSecretKey)) {
        $turnstileResponse = $_POST['cf-turnstile-response'];
        
        // Verify Turnstile token
        $verifyUrl = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';
        $data = [
            'secret' => $turnstileSecretKey,
            'response' => $turnstileResponse,
            'remoteip' => $_SERVER['REMOTE_ADDR']
        ];

        // Send request to verify Turnstile response
        $ch = curl_init($verifyUrl);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        $response = curl_exec($ch);
        curl_close($ch);
        $result = json_decode($response, true);

        // Check Turnstile response success
        if (!$result['success']) {
            // Store error message in the session to display to the user
            $_SESSION['error_message'] = "Cloudflare Turnstile verification failed. Please try again.";
            header("Location: " . htmlspecialchars($_SERVER["PHP_SELF"]));
            exit;
        }
    }

    // Proceed with login validation if Turnstile passed or not required
    $query = "SELECT * FROM users WHERE username=?";
    $statement = mysqli_prepare($con, $query);
    mysqli_stmt_bind_param($statement, "s", $username);
    mysqli_stmt_execute($statement);
    $result = mysqli_stmt_get_result($statement);

    if ($row = mysqli_fetch_assoc($result)) {
        // Check if the email is verified
        if ($row['email_verified'] == 0) {
            // Check if email_token is empty
            if (empty($row['email_token'])) {
                // Generate a new token
                $new_token = bin2hex(random_bytes(16));

                // Update the database with the new token
                $update_token_query = "UPDATE users SET email_token = ? WHERE username = ?";
                $update_token_stmt = mysqli_prepare($con, $update_token_query);
                mysqli_stmt_bind_param($update_token_stmt, "ss", $new_token, $username);
                mysqli_stmt_execute($update_token_stmt);
                mysqli_stmt_close($update_token_stmt);

                // Use the new token for sending the confirmation email
                $token = $new_token;
            } else {
                // Use the existing token
                $token = $row['email_token'];
            }

            // Send the confirmation email
            sendConfirmationEmail($username, $token);

            // Set error message for the user
            $error_message = "Your email is not verified. A new verification email has been sent. Please check your email to verify your account.";
        } else {
            // Check if the account status is approved
            if ($row['status'] != 'approved') {
                $error_message = "Your account is pending admin approval.";
            } else {
                // Check if the account is locked
                if (!is_null($row['account_locked']) && new DateTime() < new DateTime($row['account_locked'])) {
                    $error_message = "Account is temporarily locked. Please try again later.";
                } else {
                    // Verify password
                    if (password_verify($password, $row['password'])) {
                        // Set session variables
                        $_SESSION['name'] = $row['name'];
                        $_SESSION['username'] = $row['username'];
                        $_SESSION['role'] = $row['role'];
                        $_SESSION['position'] = $row['position'];
                        $_SESSION['user_id'] = $row['id'];

                        // Regenerate session ID to prevent session fixation    
                        session_regenerate_id(true);

                        // Reset login attempts and unlock the account
                        $reset_attempts = "UPDATE users SET login_attempts = 0, account_locked = NULL WHERE username=?";
                        $reset_stmt = mysqli_prepare($con, $reset_attempts);
                        mysqli_stmt_bind_param($reset_stmt, "s", $username);
                        mysqli_stmt_execute($reset_stmt);

                        // Redirect to the specified URL or default to home.php
                        if (isset($_GET['redirect'])) {
                            $rurl = urldecode($_GET['redirect']);
                            // SECURITY: Validate redirect URL to prevent open redirect attacks
                            // Open redirects can be exploited for phishing by redirecting users to malicious sites
                            // Only allow relative URLs to .php pages within this application
                            // Reject any URLs containing http:// or https:// (external sites)
                            if (preg_match('/^[a-zA-Z0-9_\-\.\/\?=&]+\.php/', $rurl) && !preg_match('/^(https?:)?\/\//', $rurl)) {
                                header("Location: $rurl");
                                exit;
                            }
                        }
                        // Default redirect if validation fails or no redirect specified
                        header("Location: home.php");
                        exit;
                    } else {
                        // Handle failed login attempts
                        $new_attempts = $row['login_attempts'] + 1;
                        if ($new_attempts >= 3) {
                            $lock_time = "UPDATE users SET account_locked = DATE_ADD(NOW(), INTERVAL 15 MINUTE), login_attempts = 3 WHERE username=?";
                            $lock_stmt = mysqli_prepare($con, $lock_time);
                            mysqli_stmt_bind_param($lock_stmt, "s", $username);
                            mysqli_stmt_execute($lock_stmt);
                            $error_message = "Account is temporarily locked for 15 minutes due to too many failed login attempts.";
                        } else {
                            $update_attempts = "UPDATE users SET login_attempts = ? WHERE username=?";
                            $update_stmt = mysqli_prepare($con, $update_attempts);
                            mysqli_stmt_bind_param($update_stmt, "is", $new_attempts, $username);
                            mysqli_stmt_execute($update_stmt);
                            $error_message = "Invalid credentials. Please try again.";
                        }
                    }
                }
            }
        }
    } else {
        $error_message = "Invalid credentials. Please try again.";
    }
    mysqli_stmt_close($statement);
}
mysqli_close($con);
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($labName); ?></title>

    <!-- Favicon and Icons -->
    <link rel="icon" href="icons/favicon.ico" type="image/x-icon">
    <link rel="apple-touch-icon" sizes="180x180" href="icons/apple-touch-icon.png">
    <link rel="icon" type="image/png" sizes="32x32" href="icons/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="icons/favicon-16x16.png">
    <link rel="icon" sizes="192x192" href="icons/android-chrome-192x192.png">
    <link rel="icon" sizes="512x512" href="icons/android-chrome-512x512.png">
    <link rel="manifest" href="manifest.json" crossorigin="use-credentials">

    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">

    <!-- Font Awesome for icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.3/css/all.min.css">

    <!-- Google Font: Poppins -->
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">

    <!-- Bootstrap and jQuery JS -->
    <script src="https://ajax.googleapis.com/ajax/libs/jquery/3.5.1/jquery.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>

    <!-- Custom CSS -->
    <style>
        body,
        html {
            margin: 0;
            padding: 0;
            height: 100%;
            width: 100%;
            font-family: 'Poppins', sans-serif;
        }

        /* Header */
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

        /* Hero Section */
        .hero-section {
            background: linear-gradient(135deg, #343a40 0%, #1a1d21 100%);
            color: #ffffff;
            padding: 60px 0;
        }

        .hero-section h1 {
            font-family: 'Poppins', sans-serif;
            font-weight: 600;
            font-size: 2rem;
            margin-bottom: 8px;
        }

        .hero-section .lead {
            opacity: 0.75;
            font-size: 1rem;
            margin-bottom: 35px;
        }

        .feature-item {
            display: flex;
            align-items: flex-start;
            gap: 15px;
            margin-bottom: 22px;
        }

        .feature-item:last-child {
            margin-bottom: 0;
        }

        .feature-item .feature-icon {
            width: 44px;
            height: 44px;
            min-width: 44px;
            border-radius: 10px;
            background: rgba(13, 110, 253, 0.15);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
            color: #4da3ff;
        }

        .feature-item strong {
            display: block;
            font-size: 0.95rem;
            margin-bottom: 2px;
        }

        .feature-item p {
            margin: 0;
            font-size: 0.85rem;
            opacity: 0.75;
            line-height: 1.4;
        }

        /* Login Card */
        .login-card {
            background: #ffffff;
            color: #212529;
            border-radius: 12px;
            padding: 32px;
            box-shadow: 0 8px 30px rgba(0, 0, 0, 0.3);
        }

        .login-card h3 {
            font-family: 'Poppins', sans-serif;
            font-weight: 600;
            margin-bottom: 20px;
            text-align: center;
        }

        .login-card .form-label {
            font-weight: 500;
            font-size: 0.9rem;
        }

        .login-card .form-control {
            border-radius: 8px;
            padding: 10px 14px;
        }

        .login-card .btn-primary {
            border-radius: 8px;
            padding: 12px 24px;
            font-weight: 500;
            font-size: 1rem;
        }

        .login-card .login-divider {
            border-top: 1px solid var(--bs-border-color);
            margin-top: 16px;
            padding-top: 14px;
            text-align: center;
            font-size: 0.85rem;
            color: var(--bs-secondary-color);
        }

        .login-card .login-divider a {
            color: var(--bs-primary);
            font-weight: 500;
            text-decoration: none;
        }

        .login-card .login-divider a:hover {
            text-decoration: underline;
        }

        /* Carousel Section */
        .carousel-section {
            padding: 30px 0 40px;
            background-color: #f8f9fa;
        }

        .carousel-section .carousel {
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
        }

        .carousel-section .carousel img {
            height: 380px;
            object-fit: cover;
            width: 100%;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .header h2 {
                font-size: 1.8rem;
                margin-bottom: 5px;
            }

            .header img.header-logo {
                width: 150px;
            }

            .hero-section {
                padding: 30px 0;
            }

            .hero-section h1 {
                font-size: 1.5rem;
            }

            .carousel-section .carousel img {
                height: 250px;
            }
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

    <!-- Hero Section: Features + Login -->
    <div class="hero-section">
        <div class="container" style="max-width: 900px;">
            <div class="row align-items-center justify-content-between">
                <!-- Left Column: Welcome + Features -->
                <div class="col-lg-6 mb-4 mb-lg-0">
                    <h1>Welcome to the <?php echo htmlspecialchars($labName); ?></h1>
                    <p class="lead">Elevate Your Research with IoT-Enhanced Colony Management</p>

                    <div class="feature-item">
                        <div class="feature-icon">
                            <i class="fas fa-thermometer-half"></i>
                        </div>
                        <div>
                            <strong>Real-Time Environmental Monitoring</strong>
                            <p>IoT sensors continuously track temperature and humidity, ensuring a stable environment for your research animals.</p>
                        </div>
                    </div>

                    <div class="feature-item">
                        <div class="feature-icon">
                            <i class="fas fa-paw"></i>
                        </div>
                        <div>
                            <strong>Effortless Cage and Mouse Tracking</strong>
                            <p>Seamlessly monitor every cage and mouse in your facility. No more manual record-keeping.</p>
                        </div>
                    </div>

                    <div class="feature-item">
                        <div class="feature-icon">
                            <i class="fas fa-shield-alt"></i>
                        </div>
                        <div>
                            <strong>Security and Compliance</strong>
                            <p>Data integrity and confidentiality prioritized, compliant with industry regulations.</p>
                        </div>
                    </div>
                </div>

                <!-- Right Column: Login Card -->
                <div class="col-lg-5 col-md-8 col-sm-10 mx-lg-0 mx-auto">
                    <div class="login-card">
                        <h3>Login</h3>
                        <?php if (isset($_SESSION['error_message'])) { ?>
                            <div class="alert alert-danger">
                                <?php
                                    echo htmlspecialchars($_SESSION['error_message']);
                                    unset($_SESSION['error_message']);
                                ?>
                            </div>
                        <?php } ?>
                        <?php if (isset($error_message)) { ?>
                            <div class="alert alert-danger">
                                <?php echo htmlspecialchars($error_message); ?>
                            </div>
                        <?php } ?>
                        <form method="POST" action="">
                            <div class="mb-3">
                                <label for="username" class="form-label">Email Address</label>
                                <input type="text" class="form-control" id="username" name="username" required>
                            </div>
                            <div class="mb-3">
                                <label for="password" class="form-label">Password</label>
                                <input type="password" class="form-control" id="password" name="password" required>
                            </div>

                            <!-- Conditionally include Cloudflare Turnstile Widget -->
                            <?php if (!empty($turnstileSiteKey)) { ?>
                                <div class="cf-turnstile mb-3" data-sitekey="<?php echo htmlspecialchars($turnstileSiteKey); ?>" data-size="flexible"></div>
                                <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
                            <?php } ?>

                            <div class="d-grid gap-2">
                                <button type="submit" class="btn btn-primary" name="login">Login</button>
                            </div>
                            <div class="text-center mt-2">
                                <a href="forgot_password.php" class="text-muted small">Forgot Password?</a>
                            </div>
                        </form>
                        <div class="login-divider">
                            Don't have an account? <a href="register.php">Register</a>
                        </div>
                    </div>
                    <?php if ($demo === "yes") include('demo/demo-credentials.php'); ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Image Carousel Section -->
    <div class="carousel-section">
        <div class="container" style="max-width: 900px;">
            <div id="labCarousel" class="carousel slide" data-bs-ride="carousel">
                <div class="carousel-inner">
                    <div class="carousel-item active"> <img class="d-block w-100" src="images/DSC_0536.webp" alt="Image 1"> </div>
                    <div class="carousel-item"> <img class="d-block w-100" src="images/DSC_0537.webp" alt="Image 2"> </div>
                    <div class="carousel-item"> <img class="d-block w-100" src="images/DSC_0539.webp" alt="Image 3"> </div>
                    <div class="carousel-item"> <img class="d-block w-100" src="images/DSC_0540.webp" alt="Image 4"> </div>
                    <div class="carousel-item"> <img class="d-block w-100" src="images/DSC_0560.webp" alt="Image 7"> </div>
                    <div class="carousel-item"> <img class="d-block w-100" src="images/DSC_0562.webp" alt="Image 8"> </div>
                    <div class="carousel-item"> <img class="d-block w-100" src="images/DSC_0586.webp" alt="Image 11"> </div>
                    <div class="carousel-item"> <img class="d-block w-100" src="images/DSC_0593.webp" alt="Image 12"> </div>
                    <div class="carousel-item"> <img class="d-block w-100" src="images/DSC_0607.webp" alt="Image 13"> </div>
                    <div class="carousel-item"> <img class="d-block w-100" src="images/DSC_0623.webp" alt="Image 14"> </div>
                    <div class="carousel-item"> <img class="d-block w-100" src="images/DSC_0658.webp" alt="Image 15"> </div>
                    <div class="carousel-item"> <img class="d-block w-100" src="images/DSC_0665.webp" alt="Image 16"> </div>
                </div>
                <button class="carousel-control-prev" type="button" data-bs-target="#labCarousel" data-bs-slide="prev">
                    <span class="carousel-control-prev-icon" aria-hidden="true"></span>
                    <span class="visually-hidden">Previous</span>
                </button>
                <button class="carousel-control-next" type="button" data-bs-target="#labCarousel" data-bs-slide="next">
                    <span class="carousel-control-next-icon" aria-hidden="true"></span>
                    <span class="visually-hidden">Next</span>
                </button>
            </div>
            <?php if ($demo === "yes") include('demo/demo-disclaimer.php'); ?>
        </div>
    </div>

    <!-- Include the footer -->
    <?php include 'footer.php'; ?>
</body>

</html>
