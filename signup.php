
<?php
session_start();

require_once __DIR__ . '/includes/supabase.php';

date_default_timezone_set('Asia/Manila');

if (empty($_SESSION['signup_csrf'])) {
    $_SESSION['signup_csrf'] = bin2hex(random_bytes(32));
}

$name = '';
$email = '';
$message = '';
$messageType = '';

if (($_GET['success'] ?? '') === '1') {
    $message = 'Registration successful! You may now sign in. Check your email first if confirmation is required.';
    $messageType = 'success';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $name = trim((string)($_POST['name'] ?? ''));
    $email = trim((string)($_POST['email'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $confirmPassword = (string)($_POST['confirm_password'] ?? '');

    $csrfSubmitted = $_POST['csrf_token'] ?? '';
    $csrfSession = $_SESSION['signup_csrf'] ?? '';

    if (
        $csrfSession === '' ||
        $csrfSubmitted === '' ||
        !hash_equals($csrfSession, $csrfSubmitted)
    ) {
        http_response_code(403);
        exit('Invalid request. Please refresh the page and try again.');

    } elseif (
        $name === '' ||
        $email === '' ||
        $password === '' ||
        $confirmPassword === ''
    ) {
        $message = 'Please complete all required fields.';
        $messageType = 'error';

    } elseif (strlen($name) > 80) {
        $message = 'Your name must not exceed 80 characters.';
        $messageType = 'error';

    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $message = 'Please enter a valid email address.';
        $messageType = 'error';

    } elseif (strlen($password) < 8) {
        $message = 'Your password must contain at least 8 characters.';
        $messageType = 'error';

    } elseif ($password !== $confirmPassword) {
        $message = 'Your passwords do not match.';
        $messageType = 'error';

    } else {

        $signupResult = supabaseRequest(
            '/auth/v1/signup',
            'POST',
            [
                'email' => $email,
                'password' => $password,
                'data' => [
                    'name' => $name
                ]
            ]
        );

        if (!empty($signupResult['success'])) {

            $_SESSION['signup_csrf'] = bin2hex(random_bytes(32));

            header('Location: signup.php?success=1');
            exit;

        } else {

            $message = $signupResult['error']
                ?? 'Registration failed. Please check your information and try again.';

            $messageType = 'error';
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Create Account | Diane Clothing Shop</title>

    <style>

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            min-height: 100vh;
            background: #f5f5f3;
            color: #171717;
            font-family: Arial, Helvetica, sans-serif;
        }

        header {
            height: 75px;
            background: #111;
            color: white;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 6%;
        }

        .logo {
            color: white;
            text-decoration: none;
            font-size: 23px;
            font-weight: bold;
            letter-spacing: 4px;
        }

        header a:last-child {
            color: white;
            text-decoration: none;
            font-size: 12px;
            letter-spacing: 1px;
        }

        .page-wrapper {
            min-height: calc(100vh - 75px);
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 35px 18px;
        }

        .signup-card {
            width: 100%;
            max-width: 460px;
            padding: 38px;
            background: white;
            border: 1px solid #e5e5e5;
        }

        .eyebrow {
            color: #777;
            font-size: 10px;
            letter-spacing: 2.5px;
            margin-bottom: 12px;
        }

        h1 {
            font-family: Georgia, serif;
            font-size: 31px;
            font-weight: normal;
            margin: 0 0 10px;
        }

        .subtitle {
            color: #777;
            font-size: 13px;
            line-height: 1.6;
            margin-bottom: 27px;
        }

        .form-group {
            margin-bottom: 18px;
        }

        label {
            display: block;
            font-size: 12px;
            font-weight: bold;
            margin-bottom: 8px;
        }

        input {
            width: 100%;
            padding: 13px;
            border: 1px solid #ddd;
            background: white;
            color: #171717;
            font-size: 13px;
            outline: none;
        }

        input:focus {
            border-color: #111;
        }

        /* Password field with eye icon */

        .password-input-wrap {
            position: relative;
            width: 100%;
        }

        .password-input-wrap input {
            padding-right: 48px;
        }

        .password-toggle {
            position: absolute;
            top: 50%;
            right: 12px;
            transform: translateY(-50%);
            display: flex;
            align-items: center;
            justify-content: center;
            width: 26px;
            height: 26px;
            padding: 0;
            margin: 0;
            border: none;
            background: transparent;
            color: #777;
            cursor: pointer;
        }

        .password-toggle:hover {
            background: transparent;
            color: #111;
        }

        .password-toggle svg {
            width: 19px;
            height: 19px;
            stroke: currentColor;
            fill: none;
            stroke-width: 1.7;
            stroke-linecap: round;
            stroke-linejoin: round;
        }

        .password-toggle .eye-closed {
            display: none;
        }

        .password-toggle[aria-pressed="true"] .eye-open {
            display: none;
        }

        .password-toggle[aria-pressed="true"] .eye-closed {
            display: block;
        }

        /* Submit button */

        .submit-button {
            width: 100%;
            padding: 15px;
            border: 1px solid #111;
            background: #111;
            color: white;
            font-size: 11px;
            letter-spacing: 1.5px;
            cursor: pointer;
            margin-top: 7px;
        }

        .submit-button:hover {
            background: #444;
        }

        .message {
            padding: 13px;
            margin-bottom: 20px;
            font-size: 12px;
            line-height: 1.6;
        }

        .success {
            background: #e5f1e8;
            color: #245c35;
            border: 1px solid #cce2d1;
        }

        .error {
            background: #f9e8e8;
            color: #982b2b;
            border: 1px solid #edcece;
        }

        .login-link {
            margin-top: 23px;
            text-align: center;
            font-size: 12px;
            color: #777;
        }

        .login-link a {
            color: #111;
            font-weight: bold;
            text-decoration: none;
        }

        @media (max-width: 480px) {
            .signup-card {
                padding: 25px 20px;
            }

            h1 {
                font-size: 27px;
            }
        }

    </style>

</head>

<body>

<header>

    <a href="index.php" class="logo">DIANE</a>

    <a href="index.php">BACK TO SHOP</a>

</header>

<main class="page-wrapper">

    <section class="signup-card">

        <p class="eyebrow">DIANE CLOTHING SHOP</p>

        <h1>Create Your Account</h1>

        <p class="subtitle">
            Join us and discover your next favorite style.
        </p>

        <?php if ($message !== ''): ?>

            <div class="message <?= htmlspecialchars($messageType) ?>">
                <?= htmlspecialchars($message) ?>
            </div>

        <?php endif; ?>

        <form method="POST" action="signup.php">

            <input
                type="hidden"
                name="csrf_token"
                value="<?= htmlspecialchars($_SESSION['signup_csrf']) ?>"
            >

            <div class="form-group">

                <label for="name">Full Name</label>

                <input
                    type="text"
                    id="name"
                    name="name"
                    maxlength="80"
                    value="<?= htmlspecialchars($name) ?>"
                    placeholder="Enter your full name"
                    autocomplete="name"
                    required
                >

            </div>

            <div class="form-group">

                <label for="email">Email Address</label>

                <input
                    type="email"
                    id="email"
                    name="email"
                    value="<?= htmlspecialchars($email) ?>"
                    placeholder="Enter your email"
                    autocomplete="email"
                    required
                >

            </div>

            <div class="form-group">

                <label for="password">Password</label>

                <div class="password-input-wrap">

                    <input
                        type="password"
                        id="password"
                        name="password"
                        minlength="8"
                        placeholder="At least 8 characters"
                        autocomplete="new-password"
                        required
                    >

                    <button
                        type="button"
                        class="password-toggle"
                        data-target="password"
                        aria-label="Show password"
                        aria-pressed="false"
                    >

                        <!-- Open eye -->
                        <svg class="eye-open" viewBox="0 0 24 24" aria-hidden="true">
                            <path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12z"/>
                            <circle cx="12" cy="12" r="3"/>
                        </svg>

                        <!-- Closed eye -->
                        <svg class="eye-closed" viewBox="0 0 24 24" aria-hidden="true">
                            <path d="M3 3l18 18"/>
                            <path d="M10.6 5.2A11 11 0 0 1 12 5c6.4 0 10 7 10 7a15 15 0 0 1-3.2 4.1"/>
                            <path d="M6.2 6.2C3.5 8 2 12 2 12s3.6 7 10 7a10 10 0 0 0 4.2-.9"/>
                            <path d="M9.9 9.9a3 3 0 0 0 4.2 4.2"/>
                        </svg>

                    </button>

                </div>

            </div>

            <div class="form-group">

                <label for="confirm_password">Confirm Password</label>

                <div class="password-input-wrap">

                    <input
                        type="password"
                        id="confirm_password"
                        name="confirm_password"
                        minlength="8"
                        placeholder="Enter your password again"
                        autocomplete="new-password"
                        required
                    >

                    <button
                        type="button"
                        class="password-toggle"
                        data-target="confirm_password"
                        aria-label="Show password"
                        aria-pressed="false"
                    >

                        <!-- Open eye -->
                        <svg class="eye-open" viewBox="0 0 24 24" aria-hidden="true">
                            <path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12z"/>
                            <circle cx="12" cy="12" r="3"/>
                        </svg>

                        <!-- Closed eye -->
                        <svg class="eye-closed" viewBox="0 0 24 24" aria-hidden="true">
                            <path d="M3 3l18 18"/>
                            <path d="M10.6 5.2A11 11 0 0 1 12 5c6.4 0 10 7 10 7a15 15 0 0 1-3.2 4.1"/>
                            <path d="M6.2 6.2C3.5 8 2 12 2 12s3.6 7 10 7a10 10 0 0 0 4.2-.9"/>
                            <path d="M9.9 9.9a3 3 0 0 0 4.2 4.2"/>
                        </svg>

                    </button>

                </div>

            </div>

            <button type="submit" class="submit-button">
                CREATE ACCOUNT
            </button>

        </form>

        <div class="login-link">

            Already have an account?

            <a href="login.php">Sign In</a>

        </div>

    </section>

</main>

<script>
document.querySelectorAll('.password-toggle').forEach(function (button) {

    button.addEventListener('click', function () {

        const input = document.getElementById(this.dataset.target);

        if (!input) {
            return;
        }

        const shouldShow = input.type === 'password';

        input.type = shouldShow ? 'text' : 'password';

        this.setAttribute(
            'aria-pressed',
            String(shouldShow)
        );

        this.setAttribute(
            'aria-label',
            shouldShow ? 'Hide password' : 'Show password'
        );

    });

});
</script>

</body>
</html>
