
<?php
session_start();

require_once __DIR__ . '/../includes/supabase.php';

// Check admin session
if (
    empty($_SESSION['admin_id']) ||
    empty($_SESSION['access_token'])
) {
    header('Location: login.php');
    exit;
}

// Verify admin role
$profile = supabaseRequest(
    '/rest/v1/profiles?select=id,role&id=eq.' .
    urlencode($_SESSION['admin_id']),
    'GET',
    null,
    $_SESSION['access_token']
);

$admin = $profile['data'][0] ?? null;

if (
    !$profile['success'] ||
    !$admin ||
    $admin['role'] !== 'admin'
) {
    session_unset();
    session_destroy();
    header('Location: login.php');
    exit;
}

// Only accept POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: products.php');
    exit;
}

// Verify CSRF token
$submittedToken = $_POST['csrf_token'] ?? '';
$sessionToken = $_SESSION['csrf_token'] ?? '';

if (
    $sessionToken === '' ||
    $submittedToken === '' ||
    !hash_equals($sessionToken, $submittedToken)
) {
    http_response_code(403);
    exit('Invalid request. Please return to the products page and try again.');
}

// Get product ID
$id = trim($_POST['id'] ?? '');

if ($id === '') {
    header('Location: products.php?delete_error=not_found');
    exit;
}

// Check if product exists
$productResult = supabaseRequest(
    '/rest/v1/products?select=id&id=eq.' . urlencode($id),
    'GET',
    null,
    $_SESSION['access_token']
);

$product = $productResult['data'][0] ?? null;

if (!$productResult['success'] || !$product) {
    header('Location: products.php?delete_error=not_found');
    exit;
}

// Check existing order history
$orderCheck = supabaseRequest(
    '/rest/v1/order_items?select=id&product_id=eq.' .
    urlencode($id) . '&limit=1',
    'GET',
    null,
    $_SESSION['access_token']
);

if (!$orderCheck['success']) {
    header('Location: products.php?delete_error=check_failed');
    exit;
}

if (!empty($orderCheck['data'])) {
    header('Location: products.php?delete_error=has_orders');
    exit;
}

// Check inventory history
$inventoryCheck = supabaseRequest(
    '/rest/v1/inventory_logs?select=id&product_id=eq.' .
    urlencode($id) . '&limit=1',
    'GET',
    null,
    $_SESSION['access_token']
);

if (!$inventoryCheck['success']) {
    header('Location: products.php?delete_error=check_failed');
    exit;
}

if (!empty($inventoryCheck['data'])) {
    header('Location: products.php?delete_error=has_inventory_logs');
    exit;
}

// Delete product from Supabase
$deleteResult = supabaseRequest(
    '/rest/v1/products?id=eq.' . urlencode($id),
    'DELETE',
    null,
    $_SESSION['access_token']
);

if ($deleteResult['success']) {
    header('Location: products.php?deleted=1');
    exit;
}

header('Location: products.php?delete_error=delete_failed');
exit;
