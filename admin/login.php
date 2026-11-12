
<?php
session_start();

require_once __DIR__ . '/../includes/supabase.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($email === '' || $password === '') {
        $error = 'Please enter your email and password.';
    } else {

        // Authenticate with Supabase
        $login = supabaseRequest(
            '/auth/v1/token?grant_type=password',
            'POST',
            [
                'email' => $email,
                'password' => $password
            ]
        );

        if (!$login['success'] || empty($login['data']['access_token'])) {
            $error = 'Invalid email or password.';
        } else {

            $user = $login['data']['user'];
            $accessToken = $login['data']['access_token'];

            // Check the user's role in the profiles table
            $profile = supabaseRequest(
                '/rest/v1/profiles?select=id,name,email,role&id=eq.'
                . urlencode($user['id']),
                'GET',
                null,
                $accessToken
            );

            $profileData = $profile['data'][0] ?? null;

            if (
                !$profile['success'] ||
                !$profileData ||
                $profileData['role'] !== 'admin'
            ) {
                $error = 'Access denied. Admin account required.';
            } else {

                session_regenerate_id(true);

                $_SESSION['admin_id'] = $user['id'];
                $_SESSION['admin_name'] = $profileData['name'];
                $_SESSION['admin_email'] = $profileData['email'];
                $_SESSION['access_token'] = $accessToken;
                $_SESSION['refresh_token'] =
                    $login['data']['refresh_token'] ?? '';

                header('Location: dashboard.php');
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
    <title>Admin Login | Diane Clothing Shop</title>

    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            background: #f5f5f5;
            font-family: Arial, sans-serif;
        }

        .login-container {
            width: 100%;
            max-width: 400px;
            padding: 40px;
            background: white;
            border: 1px solid #e5e5e5;
        }

        h1 {
            margin: 0 0 8px;
            text-align: center;
            font-size: 25px;
            letter-spacing: 2px;
        }

        .subtitle {
            margin-bottom: 30px;
            text-align: center;
            color: #777;
            font-size: 13px;
        }

        label {
            display: block;
            margin-bottom: 8px;
            font-size: 13px;
        }

        input {
            width: 100%;
            padding: 13px;
            margin-bottom: 20px;
            border: 1px solid #ccc;
            font-size: 14px;
        }

        button {
            width: 100%;
            padding: 14px;
            border: none;
            background: #111;
            color: white;
            cursor: pointer;
            font-size: 13px;
            letter-spacing: 1px;
        }

        button:hover {
            background: #444;
        }

        .error {
            padding: 12px;
            margin-bottom: 18px;
            background: #fff0f0;
            color: #b00020;
            font-size: 13px;
        }
    </style>
</head>

<body>

<div class="login-container">

    <h1>DIANE</h1>
    <div class="subtitle">CLOTHING SHOP — ADMIN PANEL</div>

    <?php if ($error): ?>
        <div class="error">
            <?= htmlspecialchars($error) ?>
        </div>
    <?php endif; ?>

    <form method="POST">

        <label for="email">Email Address</label>
        <input
            type="email"
            id="email"
            name="email"
            placeholder="Enter admin email"
            required
        >

        <label for="password">Password</label>
        <input
            type="password"
            id="password"
            name="password"
            placeholder="Enter password"
            required
        >

        <button type="submit">ADMIN LOGIN</button>

    </form>

</div>

</body>
</html>
