<?php
/**
 * DIANE CLOTHING SHOP
 * Customer Order Received Confirmation
 */

date_default_timezone_set('Asia/Manila');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/includes/supabase.php';

// Only signed-in customers may confirm their own orders.
if (
    empty($_SESSION['customer_id']) ||
    empty($_SESSION['customer_access_token'])
) {
    header('Location: login.php');
    exit;
}

$userId = (string) $_SESSION['customer_id'];

// Accept only POST requests.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: my_orders.php');
    exit;
}

// Verify CSRF token.
$submittedToken = $_POST['csrf_token'] ?? '';
if (
    !is_string($submittedToken) ||
    empty($_SESSION['customer_csrf']) ||
    !hash_equals($_SESSION['customer_csrf'], $submittedToken)
) {
    http_response_code(403);
    exit('Invalid request. Please refresh the page and try again.');
}

// Validate order ID.
$orderId = (isset($_POST['order_id']) && is_string($_POST['order_id']))
    ? trim($_POST['order_id'])
    : '';

if ($orderId === '' || strlen($orderId) > 100) {
    header('Location: my_orders.php?receipt_error=1');
    exit;
}

// Refresh the Supabase access token.
$refreshToken = trim(
    (string) ($_SESSION['customer_refresh_token'] ?? '')
);

if ($refreshToken === '') {
    unset(
        $_SESSION['customer_id'],
        $_SESSION['customer_access_token'],
        $_SESSION['customer_refresh_token'],
        $_SESSION['customer_name'],
        $_SESSION['customer_email'],
        $_SESSION['customer_csrf']
    );
    header('Location: login.php?expired=1');
    exit;
}

$refreshResult = supabaseRequest(
    '/auth/v1/token?grant_type=refresh_token',
    'POST',
    ['refresh_token' => $refreshToken]
);

$refreshData = $refreshResult['data'] ?? null;
if (
    empty($refreshResult['success']) ||
    !is_array($refreshData) ||
    empty($refreshData['access_token']) ||
    empty($refreshData['refresh_token'])
) {
    unset(
        $_SESSION['customer_id'],
        $_SESSION['customer_access_token'],
        $_SESSION['customer_refresh_token'],
        $_SESSION['customer_name'],
        $_SESSION['customer_email'],
        $_SESSION['customer_csrf']
    );
    header('Location: login.php?expired=1');
    exit;
}

$refreshedUserId = $refreshData['user']['id'] ?? '';
if ($refreshedUserId !== '' && $refreshedUserId !== $userId) {
    http_response_code(403);
    exit('Customer session mismatch.');
}

$_SESSION['customer_access_token'] = $refreshData['access_token'];
$_SESSION['customer_refresh_token'] = $refreshData['refresh_token'];
$accessToken = (string) $_SESSION['customer_access_token'];

// The database function verifies order ownership and Delivered status.
$result = supabaseRequest(
    '/rest/v1/rpc/confirm_order_received',
    'POST',
    ['p_order_id' => $orderId],
    $accessToken
);

$rpcData = $result['data'] ?? null;
if (
    !empty($result['success']) &&
    is_array($rpcData) &&
    !empty($rpcData['success'])
) {
    header(
        'Location: my_orders.php?received=1&order=' . rawurlencode($orderId)
    );
    exit;
}

error_log(
    'DIANE receipt confirmation failed: ' .
    (is_array($rpcData) ? ($rpcData['message'] ?? '') : '') . ' ' .
    ($result['error'] ?? 'RPC confirmation rejected')
);

header(
    'Location: my_orders.php?receipt_error=1&order=' . rawurlencode($orderId)
);
exit;
