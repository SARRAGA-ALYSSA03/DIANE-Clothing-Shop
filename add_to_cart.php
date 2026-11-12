
<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/includes/supabase.php';

/**
 * Return customer to the product page with a status.
 */
function returnToProduct(string $productId, string $status): void
{
    header(
        'Location: product.php?id=' . rawurlencode($productId) .
        '&status=' . rawurlencode($status)
    );
    exit;
}

/**
 * Clear customer session if the refresh token is invalid.
 */
function expireCustomerSession(): void
{
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

// 1. Verify customer login.
if (
    empty($_SESSION['customer_id']) ||
    empty($_SESSION['customer_access_token'])
) {
    header('Location: login.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

$userId = (string) $_SESSION['customer_id'];
$productId = trim((string)($_POST['product_id'] ?? ''));
$size = trim((string)($_POST['size'] ?? ''));

$quantity = filter_var(
    $_POST['quantity'] ?? null,
    FILTER_VALIDATE_INT
);

// 2. Validate CSRF and submitted values.
$submittedToken = $_POST['csrf_token'] ?? '';
$sessionToken = $_SESSION['customer_csrf'] ?? '';

if (
    $productId === '' ||
    $size === '' ||
    $quantity === false ||
    $quantity < 1 ||
    $quantity > 99
) {
    returnToProduct($productId, 'invalid');
}

if (
    !is_string($submittedToken) ||
    !is_string($sessionToken) ||
    $sessionToken === '' ||
    !hash_equals($sessionToken, $submittedToken)
) {
    returnToProduct($productId, 'invalid');
}

// 3. Refresh the Supabase customer access token.
$refreshToken = trim(
    (string)($_SESSION['customer_refresh_token'] ?? '')
);

if ($refreshToken === '') {
    error_log('DIANE CART: Customer refresh token is missing.');
    expireCustomerSession();
}

$refreshResult = supabaseRequest(
    '/auth/v1/token?grant_type=refresh_token',
    'POST',
    [
        'refresh_token' => $refreshToken
    ]
);

$refreshData = $refreshResult['data'] ?? null;

if (
    empty($refreshResult['success']) ||
    !is_array($refreshData) ||
    empty($refreshData['access_token']) ||
    empty($refreshData['refresh_token'])
) {
    error_log(
        'DIANE CART TOKEN REFRESH ERROR: ' .
        json_encode([
            'status' => $refreshResult['status'] ?? 0,
            'error' => $refreshResult['error'] ?? 'Unknown refresh error',
            'data' => $refreshData
        ])
    );

    expireCustomerSession();
}

// Confirm the refreshed token belongs to the same customer.
$refreshedUserId = $refreshData['user']['id'] ?? '';

if (
    $refreshedUserId !== '' &&
    $refreshedUserId !== $userId
) {
    error_log('DIANE CART: Refreshed customer ID did not match session.');
    expireCustomerSession();
}

// Save both rotated tokens.
$_SESSION['customer_access_token'] = $refreshData['access_token'];
$_SESSION['customer_refresh_token'] = $refreshData['refresh_token'];

$accessToken = (string)$_SESSION['customer_access_token'];

// 4. Retrieve the product from Supabase.
$productResult = supabaseRequest(
    '/rest/v1/products?select=id,name,stock,sizes&id=eq.' .
    rawurlencode($productId) .
    '&limit=1',
    'GET',
    null,
    $accessToken
);

if (
    empty($productResult['success']) ||
    !is_array($productResult['data'] ?? null)
) {
    error_log(
        'DIANE CART PRODUCT LOOKUP ERROR: ' .
        json_encode([
            'status' => $productResult['status'] ?? 0,
            'error' => $productResult['error'] ?? 'Unknown error',
            'data' => $productResult['data'] ?? null
        ])
    );

    returnToProduct($productId, 'error');
}

$product = $productResult['data'][0] ?? null;

if (!is_array($product)) {
    error_log('DIANE CART: Product not found. ID: ' . $productId);
    returnToProduct($productId, 'error');
}

// 5. Validate selected size.
$availableSizes = is_array($product['sizes'] ?? null)
    ? $product['sizes']
    : [];

if (!in_array($size, $availableSizes, true)) {
    returnToProduct($productId, 'invalid');
}

// 6. Validate stock.
$stock = (int)($product['stock'] ?? 0);

if ($stock < 1 || $quantity > $stock) {
    returnToProduct($productId, 'stock');
}

// 7. Check whether the product and size already exist in the cart.
$existingResult = supabaseRequest(
    '/rest/v1/cart_items?select=id,quantity' .
    '&user_id=eq.' . rawurlencode($userId) .
    '&product_id=eq.' . rawurlencode($productId) .
    '&size=eq.' . rawurlencode($size) .
    '&limit=1',
    'GET',
    null,
    $accessToken
);

if (
    empty($existingResult['success']) ||
    !is_array($existingResult['data'] ?? null)
) {
    error_log(
        'DIANE CART EXISTING ITEM LOOKUP ERROR: ' .
        json_encode([
            'status' => $existingResult['status'] ?? 0,
            'error' => $existingResult['error'] ?? 'Unknown error',
            'data' => $existingResult['data'] ?? null
        ])
    );

    returnToProduct($productId, 'error');
}

$existingItem = $existingResult['data'][0] ?? null;

// 8. Update existing cart item or insert a new one.
if (is_array($existingItem)) {

    $newQuantity = (int)$existingItem['quantity'] + $quantity;

    if ($newQuantity > $stock || $newQuantity > 99) {
        returnToProduct($productId, 'stock');
    }

    $updateResult = supabaseRequest(
        '/rest/v1/cart_items?id=eq.' .
        rawurlencode((string)$existingItem['id']) .
        '&user_id=eq.' . rawurlencode($userId),
        'PATCH',
        [
            'quantity' => $newQuantity
        ],
        $accessToken
    );

    if (empty($updateResult['success'])) {
        error_log(
            'DIANE CART UPDATE ERROR: ' .
            json_encode([
                'status' => $updateResult['status'] ?? 0,
                'error' => $updateResult['error'] ?? 'Unknown error',
                'data' => $updateResult['data'] ?? null
            ])
        );

        returnToProduct($productId, 'error');
    }

} else {

    $insertResult = supabaseRequest(
        '/rest/v1/cart_items',
        'POST',
        [
            'user_id' => $userId,
            'product_id' => $productId,
            'size' => $size,
            'quantity' => $quantity
        ],
        $accessToken
    );

    if (empty($insertResult['success'])) {
        error_log(
            'DIANE CART INSERT ERROR: ' .
            json_encode([
                'status' => $insertResult['status'] ?? 0,
                'error' => $insertResult['error'] ?? 'Unknown error',
                'data' => $insertResult['data'] ?? null
            ])
        );

        returnToProduct($productId, 'error');
    }
}

// 9. Successfully saved.
header('Location: cart.php');
exit;
