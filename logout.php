
<?php

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/includes/supabase.php';

// Get the customer's current access token.
$customerAccessToken = $_SESSION['customer_access_token'] ?? '';

// Sign out the customer from Supabase.
if (
    is_string($customerAccessToken) &&
    $customerAccessToken !== ''
) {
    supabaseRequest(
        '/auth/v1/logout?scope=local',
        'POST',
        null,
        $customerAccessToken
    );
}

// Remove only customer session information.
// Keep admin session information separate.
unset(
    $_SESSION['customer_id'],
    $_SESSION['customer_access_token'],
    $_SESSION['customer_refresh_token'],
    $_SESSION['customer_name'],
    $_SESSION['customer_email'],
    $_SESSION['customer_csrf']
);

session_regenerate_id(true);

// Return to the customer homepage.
header('Location: index.php');
exit;
