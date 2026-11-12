
<?php
session_start();

require_once __DIR__ . '/includes/supabase.php';

if (empty($_SESSION['customer_csrf'])) {
    $_SESSION['customer_csrf'] = bin2hex(random_bytes(32));
}

$email = '';
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $email = trim((string)($_POST['email'] ?? ''));
    $password = (string)($_POST['password'] ?? '');

    $csrfSubmitted = $_POST['csrf_token'] ?? '';
    $csrfSession = $_SESSION['customer_csrf'] ?? '';

    if (
        $csrfSession === '' ||
        $csrfSubmitted === '' ||
        !hash_equals($csrfSession, $csrfSubmitted)
    ) {
        http_response_code(403);
        exit('Invalid request. Please refresh the page and try again.');

    } elseif ($email === '' || $password === '') {

        $message = 'Please enter your email and password.';
        $messageType = 'error';

    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {

        $message = 'Please enter a valid email address.';
        $messageType = 'error';

    } else {

        // Sign in through Supabase Auth.
        $loginResult = supabaseRequest(
            '/auth/v1/token?grant_type=password',
            'POST',
            [
                'email' => $email,
                'password' => $password
            ]
        );

        if (
            empty($loginResult['success']) ||
            empty($loginResult['data']['access_token']) ||
            empty($loginResult['data']['user']['id'])
        ) {

            $message = 'Unable to sign in. Check your email and password, and confirm your email if required.';
            $messageType = 'error';

        } else {

            $user = $loginResult['data']['user'];
            $accessToken = $loginResult['data']['access_token'];

            // Check that this account has a customer profile.
            $profileResult = supabaseRequest(
                '/rest/v1/profiles?select=id,name,email,role&id=eq.' .
                rawurlencode($user['id']),
                'GET',
                null,
                $accessToken
            );

            $profile = $profileResult['data'][0] ?? null;

            if (
                empty($profileResult['success']) ||
                !is_array($profile) ||
                ($profile['role'] ?? '') !== 'customer'
            ) {

                $message = 'This is not a customer account. Please use the correct login page.';
                $messageType = 'error';

            } else {

                // Create a separate customer session.
                session_regenerate_id(true);

                $_SESSION['customer_id'] = $user['id'];
                $_SESSION['customer_access_token'] = $accessToken;
                $_SESSION['customer_refresh_token'] =
                    $loginResult['data']['refresh_token'] ?? '';

                $_SESSION['customer_name'] =
                    $profile['name'] ?? '';

                $_SESSION['customer_email'] =
                    $profile['email'] ?? $email;

                // Generate a fresh CSRF token.
                unset($_SESSION['customer_csrf']);

                header('Location: index.php');
                exit;
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Customer Login | Diane Clothing Shop</title>

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

        .login-card {
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
            margin-bottom: 19px;
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
            font-size: 13px;
            outline: none;
        }

        input:focus {
            border-color: #111;
        }

        button {
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

        button:hover {
            background: #444;
        }

        .message {
            padding: 13px;
            margin-bottom: 20px;
            font-size: 12px;
            line-height: 1.6;
        }

        .error {
            background: #f9e8e8;
            color: #982b2b;
            border: 1px solid #edcece;
        }

        .signup-link {
            margin-top: 23px;
            text-align: center;
            font-size: 12px;
            color: #777;
        }

        .signup-link a {
            color: #111;
            font-weight: bold;
            text-decoration: none;
        }

        @media (max-width: 480px) {

            .login-card {
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

    <section class="login-card">

        <p class="eyebrow">DIANE CLOTHING SHOP</p>

        <h1>Welcome Back</h1>

        <p class="subtitle">
            Sign in to access your Diane Clothing Shop account.
        </p>

        <?php if ($message !== ''): ?>

            <div class="message <?= htmlspecialchars($messageType) ?>">

                <?= htmlspecialchars($message) ?>

            </div>

        <?php endif; ?>

        <form method="POST" action="login.php">

            <input
                type="hidden"
                name="csrf_token"
                value="<?= htmlspecialchars($_SESSION['customer_csrf']) ?>"
            >

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

                <input
                    type="password"
                    id="password"
                    name="password"
                    placeholder="Enter your password"
                    autocomplete="current-password"
                    required
                >

            </div>

            <button type="submit">
                SIGN IN
            </button>

        </form>

        <div class="signup-link">

            Don't have an account?

            <a href="signup.php">Create Account</a>

        </div>

    </section>

</main>

</body>
</html>
