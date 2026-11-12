
<?php
/**
 * DIANE CLOTHING SHOP
 * Luxury Shopping Bag
 * Supabase cart with automatic customer token refresh.
 */

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/includes/supabase.php';

// ==========================================
// 1. CUSTOMER SESSION
// ==========================================

if (
    empty($_SESSION['customer_id']) ||
    empty($_SESSION['customer_access_token'])
) {
    header('Location: login.php');
    exit;
}

$userId = (string) $_SESSION['customer_id'];

// ==========================================
// 2. REFRESH SUPABASE ACCESS TOKEN
// ==========================================

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

if (
    $refreshedUserId !== '' &&
    $refreshedUserId !== $userId
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

$_SESSION['customer_access_token'] = $refreshData['access_token'];
$_SESSION['customer_refresh_token'] = $refreshData['refresh_token'];

$accessToken = (string) $_SESSION['customer_access_token'];

// ==========================================
// 3. CUSTOMER INFORMATION
// ==========================================

$fullCustomerName = trim(
    (string) ($_SESSION['customer_name'] ?? '')
);

$nameParts = preg_split('/\s+/', $fullCustomerName);

$customerName = (
    is_array($nameParts) && !empty($nameParts[0])
)
    ? $nameParts[0]
    : 'Customer';

// ==========================================
// 4. SECURITY TOKEN
// ==========================================

if (empty($_SESSION['customer_csrf'])) {
    $_SESSION['customer_csrf'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['customer_csrf'];

function cartEscape($value): string
{
    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES,
        'UTF-8'
    );
}

// ==========================================
// 5. CART FEEDBACK
// ==========================================

$message = '';
$error = '';

if (($_GET['status'] ?? '') === 'updated') {
    $message = 'Your shopping bag has been updated.';
} elseif (($_GET['status'] ?? '') === 'removed') {
    $message = 'The item was removed from your shopping bag.';
}

// ==========================================
// 6. HANDLE CART ACTIONS
// ==========================================

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $submittedToken = $_POST['csrf_token'] ?? '';

    if (
        !is_string($submittedToken) ||
        !hash_equals($csrfToken, $submittedToken)
    ) {

        $error = 'Invalid request. Please refresh the page.';

    } else {

        $action = $_POST['action'] ?? '';
        $itemId = $_POST['item_id'] ?? '';

        if (
            !is_string($itemId) ||
            !ctype_digit($itemId)
        ) {

            $error = 'Invalid shopping bag item.';

        } elseif ($action === 'update') {

            $quantity = filter_var(
                $_POST['quantity'] ?? null,
                FILTER_VALIDATE_INT
            );

            if (
                $quantity === false ||
                $quantity < 1 ||
                $quantity > 99
            ) {

                $error = 'Please enter a quantity from 1 to 99.';

            } else {

                // Find this cart item belonging to this customer.
                $itemResult = supabaseRequest(
                    '/rest/v1/cart_items?select=id,product_id' .
                    '&id=eq.' . rawurlencode($itemId) .
                    '&user_id=eq.' . rawurlencode($userId) .
                    '&limit=1',
                    'GET',
                    null,
                    $accessToken
                );

                $currentItem = (
                    !empty($itemResult['success']) &&
                    is_array($itemResult['data'] ?? null)
                )
                    ? ($itemResult['data'][0] ?? null)
                    : null;

                if (!is_array($currentItem)) {

                    $error = $itemResult['error']
                        ?? 'This shopping bag item could not be found.';

                } else {

                    // Retrieve the latest stock.
                    $stockResult = supabaseRequest(
                        '/rest/v1/products?select=id,stock&id=eq.' .
                        rawurlencode((string) $currentItem['product_id']) .
                        '&limit=1',
                        'GET',
                        null,
                        $accessToken
                    );

                    $stockProduct = (
                        !empty($stockResult['success']) &&
                        is_array($stockResult['data'] ?? null)
                    )
                        ? ($stockResult['data'][0] ?? null)
                        : null;

                    if (!is_array($stockProduct)) {

                        $error = $stockResult['error']
                            ?? 'Unable to check product availability.';

                    } else {

                        $productStock = max(
                            0,
                            (int) ($stockProduct['stock'] ?? 0)
                        );

                        // Count the customer's other cart lines
                        // for the same product, regardless of size.
                        $otherCartResult = supabaseRequest(
                            '/rest/v1/cart_items?select=quantity' .
                            '&user_id=eq.' . rawurlencode($userId) .
                            '&product_id=eq.' . rawurlencode(
                                (string) $currentItem['product_id']
                            ) .
                            '&id=neq.' . rawurlencode($itemId),
                            'GET',
                            null,
                            $accessToken
                        );

                        if (
                            empty($otherCartResult['success']) ||
                            !is_array($otherCartResult['data'] ?? null)
                        ) {

                            $error = $otherCartResult['error']
                                ?? 'Unable to verify the other quantities in your bag.';

                        } else {

                            $otherProductQuantity = 0;

                            foreach ($otherCartResult['data'] as $otherRow) {
                                $otherProductQuantity += max(
                                    0,
                                    (int) ($otherRow['quantity'] ?? 0)
                                );
                            }

                            $maximumForThisLine = max(
                                0,
                                $productStock - $otherProductQuantity
                            );

                            if (
                                $maximumForThisLine < 1 ||
                                $quantity > $maximumForThisLine
                            ) {

                                $error = $maximumForThisLine < 1
                                    ? 'No stock is available for this cart line after counting the same product in your other sizes. Remove this line or adjust the other quantity.'
                                    : 'You can set this line to a maximum of ' .
                                        $maximumForThisLine .
                                        ' because the product has only ' .
                                        $productStock .
                                        ' unit(s) available across all sizes.';

                            } else {

                                $updateResult = supabaseRequest(
                                    '/rest/v1/cart_items?id=eq.' .
                                    rawurlencode($itemId) .
                                    '&user_id=eq.' . rawurlencode($userId),
                                    'PATCH',
                                    [
                                        'quantity' => $quantity
                                    ],
                                    $accessToken
                                );

                                if (!empty($updateResult['success'])) {
                                    header('Location: cart.php?status=updated');
                                    exit;
                                }

                                $error = $updateResult['error']
                                    ?? 'Unable to update this item. Please try again.';
                            }
                        }
                    }
                }
            }

        } elseif ($action === 'remove') {

            // Delete only this customer's selected cart item.
            $deleteResult = supabaseRequest(
                '/rest/v1/cart_items?id=eq.' .
                rawurlencode($itemId) .
                '&user_id=eq.' . rawurlencode($userId),
                'DELETE',
                null,
                $accessToken
            );

            if (!empty($deleteResult['success'])) {
                header('Location: cart.php?status=removed');
                exit;
            }

            $error = $deleteResult['error']
                ?? 'Unable to remove this item. Please try again.';

        } else {

            $error = 'Invalid shopping bag action.';

        }
    }
}

// ==========================================
// 7. LOAD CUSTOMER CART ITEMS
// ==========================================

// Get cart rows first, without an embedded product relationship.
$cartResult = supabaseRequest(
    '/rest/v1/cart_items?select=id,product_id,size,quantity' .
    '&user_id=eq.' . rawurlencode($userId) .
    '&order=id.desc',
    'GET',
    null,
    $accessToken
);

$cartItems = [];

$loadError = empty($cartResult['success']);
$loadErrorMessage = $cartResult['error'] ?? '';
$loadErrorStatus = (int) ($cartResult['status'] ?? 0);

if (
    !$loadError &&
    is_array($cartResult['data'] ?? null)
) {

    $cartRows = $cartResult['data'];

    // Retrieve product details separately for every cart row.
    foreach ($cartRows as $cartRow) {

        $productId = (string) (
            $cartRow['product_id'] ?? ''
        );

        if ($productId === '') {

            $loadError = true;
            $loadErrorMessage = 'A cart item has no product ID.';
            break;
        }

        $productResult = supabaseRequest(
            '/rest/v1/products?select=id,name,price,image,images,stock,category' .
            '&id=eq.' . rawurlencode($productId) .
            '&limit=1',
            'GET',
            null,
            $accessToken
        );

        if (empty($productResult['success'])) {

            $loadError = true;
            $loadErrorMessage = $productResult['error']
                ?? 'Unable to retrieve product information.';

            $loadErrorStatus = (int) (
                $productResult['status'] ?? 0
            );

            break;
        }

        $product = $productResult['data'][0] ?? null;

        if (!is_array($product)) {

            $loadError = true;
            $loadErrorMessage = 'A product in your shopping bag could not be found.';
            break;
        }

        // Use the first gallery image if the main image is empty.
        if (
            empty($product['image']) &&
            !empty($product['images']) &&
            is_array($product['images'])
        ) {
            $product['image'] = $product['images'][0] ?? '';
        }

        // Combine the cart row and product details.
        $cartRow['products'] = $product;

        $cartItems[] = $cartRow;
    }

} elseif (!$loadError) {

    $loadError = true;
    $loadErrorMessage = 'Unexpected response received from Supabase.';

}

// Do not show partial totals if any cart request failed.
if ($loadError) {
    $cartItems = [];
}

// ==========================================
// 8. VALIDATE CURRENT CART STOCK AND CALCULATE TOTALS
// ==========================================

$subtotal = 0;
$totalItems = 0;

$canProceedToCheckout = !$loadError && !empty($cartItems);

$cartStockIssues = [];
$productQuantitiesInCart = [];

// Sum every cart line for each product, regardless of selected size.
foreach ($cartItems as $item) {

    $productId = (string) ($item['product_id'] ?? '');

    if ($productId !== '') {

        $productQuantitiesInCart[$productId] =
            ($productQuantitiesInCart[$productId] ?? 0) +
            max(0, (int) ($item['quantity'] ?? 0));
    }
}

// Add stock information and warnings to each product line.
foreach ($cartItems as &$cartItem) {

    $product = $cartItem['products'] ?? [];
    $productId = (string) ($cartItem['product_id'] ?? '');

    $lineQuantity = max(
        0,
        (int) ($cartItem['quantity'] ?? 0)
    );

    $productStock = max(
        0,
        (int) ($product['stock'] ?? 0)
    );

    $totalProductQuantity = (int) (
        $productQuantitiesInCart[$productId] ?? $lineQuantity
    );

    $otherProductQuantity = max(
        0,
        $totalProductQuantity - $lineQuantity
    );

    $maximumForThisLine = max(
        0,
        $productStock - $otherProductQuantity
    );

    $cartItem['product_stock'] = $productStock;

    $cartItem['product_cart_quantity'] = $totalProductQuantity;

    $cartItem['maximum_line_quantity'] = min(
        99,
        $maximumForThisLine
    );

    $cartItem['stock_issue'] = '';

    if ($productStock < 1) {

        $cartItem['stock_issue'] =
            'This product is currently out of stock. Remove it from your bag to continue.';

    } elseif ($totalProductQuantity > $productStock) {

        $cartItem['stock_issue'] =
            'Your bag contains ' . $totalProductQuantity .
            ' unit(s) across all sizes, but only ' . $productStock .
            ' remain. Reduce the quantity or remove an item.';
    }

    if ($cartItem['stock_issue'] !== '') {

        $canProceedToCheckout = false;

        if (!in_array($productId, $cartStockIssues, true)) {
            $cartStockIssues[] = $productId;
        }
    }
}

unset($cartItem);

// Calculate the current shopping bag total.
foreach ($cartItems as $item) {

    $product = $item['products'];

    $quantity = (int) ($item['quantity'] ?? 0);
    $price = (float) ($product['price'] ?? 0);

    $subtotal += $price * $quantity;
    $totalItems += $quantity;
}

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>My Shopping Bag | DIANE Clothing Shop</title>

    <style>

        /* =========================================
           01. GLOBAL DESIGN
        ========================================= */

        :root {

            --diane-black: #111111;
            --diane-white: #ffffff;
            --diane-gray: #777777;
            --diane-light: #f5f5f3;
            --diane-border: #e5e5e5;

            --diane-serif: Georgia, "Times New Roman", serif;
            --diane-sans: "Helvetica Neue", Arial, sans-serif;

        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            background: #ffffff;
            color: #111111;
            font-family: var(--diane-sans);
        }

        /* =========================================
           02. HEADER
        ========================================= */

        .diane-header {

            width: 100%;
            min-height: 86px;
            padding: 12px 5%;

            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 25px;

            background: #ffffff;
            border-bottom: 1px solid var(--diane-border);

        }

        .diane-brand {

            flex: 0 0 auto;

            display: flex;
            flex-direction: column;
            align-items: center;

            color: #111111;
            text-decoration: none;

        }

        .diane-brand-name {

            font-family: var(--diane-serif);
            font-size: 34px;
            font-weight: normal;
            letter-spacing: 2px;
            line-height: 1;

        }

        .diane-brand-subtitle {

            margin-top: 6px;
            font-size: 8px;
            font-weight: 600;
            letter-spacing: 4px;

        }

        .diane-header-right {

            display: flex;
            align-items: center;
            justify-content: flex-end;
            gap: 28px;
            margin-left: auto;

        }

        .diane-navigation {

            display: flex;
            align-items: center;
            gap: 22px;

        }

        .diane-navigation a,
        .diane-account a {

            color: #111111;
            font-size: 10px;
            font-weight: 500;
            letter-spacing: 1px;
            text-decoration: none;
            white-space: nowrap;

        }

        .diane-navigation a:hover,
        .diane-account a:hover {
            opacity: .55;
        }

        .diane-account {

            display: flex;
            align-items: center;
            gap: 15px;
            white-space: nowrap;

        }

        .diane-account .bag-link {
            font-weight: 600;
        }

        .diane-greeting {

            color: #666666;
            font-size: 11px;

        }

        /* CUSTOMER PROFILE ICON */

        .diane-profile-icon {

            width: 32px;
            height: 32px;

            display: inline-flex;
            flex: 0 0 32px;

            align-items: center;
            justify-content: center;

            color: #111111;
            border: 1px solid #dddddd;
            border-radius: 50%;

            text-decoration: none;

            transition:
                background .2s ease,
                color .2s ease,
                border-color .2s ease;

        }

        .diane-account .diane-profile-icon {

            font-size: initial;
            letter-spacing: normal;

        }

        .diane-profile-icon svg {

            width: 16px;
            height: 16px;
            display: block;

        }

        .diane-profile-icon:hover,
        .diane-account .diane-profile-icon:hover {

            background: #111111;
            color: #ffffff;
            border-color: #111111;
            opacity: 1;

        }

        .diane-profile-icon:focus-visible {

            outline: 2px solid #111111;
            outline-offset: 3px;

        }

        /* =========================================
           03. MAIN CART PAGE
        ========================================= */

        .cart-page {

            max-width: 1350px;
            margin: auto;
            padding: 65px 5% 100px;

        }

        .cart-breadcrumb {

            margin-bottom: 22px;
            color: #777777;
            font-size: 9px;
            letter-spacing: 1px;

        }

        .cart-heading {

            padding-bottom: 25px;
            border-bottom: 1px solid var(--diane-border);

            display: flex;
            align-items: flex-end;
            justify-content: space-between;
            gap: 20px;

        }

        .cart-heading h1 {

            margin: 0;

            font-family: var(--diane-serif);
            font-size: clamp(34px, 4vw, 48px);
            font-weight: normal;
            letter-spacing: -1px;

        }

        .cart-heading p {

            margin: 10px 0 0;
            color: #777777;
            font-size: 12px;

        }

        .cart-item-count {

            color: #777777;
            font-size: 10px;
            letter-spacing: 1px;
            white-space: nowrap;

        }

        /* =========================================
           04. NOTIFICATIONS AND ERRORS
        ========================================= */

        .cart-notice {

            padding: 15px 18px;
            margin: 22px 0;

            background: #f5f5f3;
            color: #333333;

            font-size: 12px;
            line-height: 1.6;

        }

        .cart-notice.error {
            color: #a21d1d;
        }

        .cart-stock-warning {

            margin: 10px 0 0;
            padding: 10px 12px;

            background: #fff5f3;
            border-left: 2px solid #a21d1d;

            color: #9b2525;
            font-size: 10px;
            line-height: 1.7;

        }

        .cart-stock-block-message {

            margin: 0 0 14px;
            color: #9b2525;
            font-size: 10px;
            line-height: 1.7;

        }

        .cart-technical-details {

            margin-top: 12px;
            padding-top: 10px;
            border-top: 1px solid #dddddd;

            color: #555555;
            overflow-wrap: anywhere;

        }

        /* =========================================
           05. CART LAYOUT
        ========================================= */

        .cart-layout {

            display: grid;
            grid-template-columns: minmax(0, 1fr) 340px;
            gap: 65px;
            align-items: start;

            margin-top: 35px;

        }

        .cart-list-heading {

            margin-bottom: 20px;
            padding-bottom: 13px;

            border-bottom: 1px solid var(--diane-border);

            color: #777777;
            font-size: 9px;
            font-weight: 600;
            letter-spacing: 1.5px;

        }

        /* =========================================
           06. CART ITEM
        ========================================= */

        .cart-item {

            display: grid;
            grid-template-columns: 155px minmax(0, 1fr) auto;
            gap: 25px;
            align-items: start;

            padding: 25px 0;
            border-bottom: 1px solid var(--diane-border);

        }

        .cart-product-image {

            display: block;
            width: 155px;
            aspect-ratio: 3 / 4;

            background: #f4f4f2;
            object-fit: contain;

        }

        .cart-product-name {

            display: inline-block;
            margin: 3px 0 10px;

            color: #111111;
            font-family: var(--diane-serif);
            font-size: 20px;
            font-weight: normal;
            line-height: 1.3;
            text-decoration: none;

        }

        .cart-product-name:hover {
            text-decoration: underline;
        }

        .cart-product-category {

            margin: 0 0 15px;
            color: #888888;
            font-size: 9px;
            letter-spacing: 1px;
            text-transform: uppercase;

        }

        .cart-product-detail {

            margin: 8px 0;
            color: #666666;
            font-size: 11px;

        }

        .cart-product-price {

            padding-top: 4px;
            color: #111111;
            font-size: 13px;
            font-weight: 600;
            white-space: nowrap;

        }

        /* =========================================
           07. QUANTITY AND REMOVE
        ========================================= */

        .cart-item-actions {

            margin-top: 19px;

            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 14px;

        }

        .cart-quantity-form {

            display: flex;
            align-items: center;
            gap: 7px;

        }

        .cart-quantity-form label {

            color: #777777;
            font-size: 10px;

        }

        .cart-quantity-form input {

            width: 58px;
            height: 36px;
            padding: 6px;

            background: #ffffff;
            border: 1px solid #dddddd;

            font-size: 11px;

        }

        .cart-small-button {

            min-height: 36px;
            padding: 0 13px;

            background: #ffffff;
            color: #111111;
            border: 1px solid #cccccc;

            font-size: 9px;
            font-weight: 600;
            letter-spacing: .8px;

            cursor: pointer;

        }

        .cart-small-button:hover {
            border-color: #111111;
        }

        .cart-small-button:disabled {

            opacity: .45;
            cursor: not-allowed;

        }

        .cart-quantity-form input:disabled {

            background: #eeeeec;
            color: #777777;
            cursor: not-allowed;

        }

        .cart-remove-button {

            padding: 8px 0;

            background: none;
            border: none;
            color: #777777;

            font-size: 9px;
            font-weight: 600;
            letter-spacing: .8px;

            text-decoration: underline;
            text-underline-offset: 3px;

            cursor: pointer;

        }

        .cart-remove-button:hover {
            color: #111111;
        }

        /* =========================================
           08. ORDER SUMMARY
        ========================================= */

        .cart-summary {

            padding: 28px;
            background: #f5f5f3;

        }

        .cart-summary h2 {

            margin: 0 0 23px;

            font-family: var(--diane-serif);
            font-size: 25px;
            font-weight: normal;

        }

        .cart-summary-row {

            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 15px;

            padding: 13px 0;

            color: #666666;
            font-size: 11px;

        }

        .cart-summary-row strong {

            color: #111111;
            font-weight: 600;

        }

        .cart-summary-total {

            margin-top: 12px;
            padding-top: 18px;

            border-top: 1px solid #dddddd;

            color: #111111;
            font-size: 13px;
            font-weight: 700;

        }

        .cart-summary-total strong {
            font-size: 19px;
        }

        .cart-shipping-note {

            margin: 18px 0 22px;
            color: #888888;
            font-size: 10px;
            line-height: 1.8;

        }

        .cart-checkout-button {

            display: flex;
            align-items: center;
            justify-content: center;

            width: 100%;
            min-height: 51px;

            background: #111111;
            color: #ffffff;

            border: 1px solid #111111;

            font-size: 10px;
            font-weight: 700;
            letter-spacing: 1.3px;
            text-decoration: none;

            transition: .2s ease;

        }

        .cart-checkout-button:hover {

            background: #ffffff;
            color: #111111;

        }

        .cart-checkout-button.is-disabled,
        .cart-checkout-button.is-disabled:hover {

            background: #d8d8d6;
            color: #666666;
            border-color: #d8d8d6;
            cursor: not-allowed;

        }

        .cart-continue-link {

            display: block;
            margin-top: 22px;

            color: #555555;
            font-size: 10px;
            letter-spacing: .8px;
            text-align: center;
            text-decoration: underline;
            text-underline-offset: 4px;

        }

        /* =========================================
           09. EMPTY CART
        ========================================= */

        .cart-empty {

            padding: 95px 20px;
            text-align: center;

        }

        .cart-empty-symbol {

            margin-bottom: 20px;
            font-family: var(--diane-serif);
            font-size: 45px;

        }

        .cart-empty h2 {

            margin: 0;

            font-family: var(--diane-serif);
            font-size: 32px;
            font-weight: normal;

        }

        .cart-empty p {

            margin: 14px auto 25px;
            color: #777777;
            font-size: 12px;
            line-height: 1.8;

        }

        .cart-empty-button {

            display: inline-flex;
            justify-content: center;
            align-items: center;

            min-height: 48px;
            padding: 0 25px;

            background: #111111;
            color: #ffffff;

            font-size: 10px;
            font-weight: 600;
            letter-spacing: 1px;
            text-decoration: none;

        }

        /* =========================================
           10. FOOTER
        ========================================= */

        .diane-footer {

            padding: 55px 5% 25px;
            background: #111111;
            color: #ffffff;

        }

        .diane-footer-top {

            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 30px;

            padding-bottom: 35px;
            border-bottom: 1px solid #3a3a3a;

        }

        .diane-footer-brand {

            color: #ffffff;
            text-decoration: none;

        }

        .diane-footer-brand strong {

            display: block;

            font-family: var(--diane-serif);
            font-size: 31px;
            font-weight: normal;
            letter-spacing: 2px;

        }

        .diane-footer-brand span {

            display: block;
            margin-top: 7px;

            font-size: 8px;
            letter-spacing: 4px;

        }

        .diane-footer-links {

            display: flex;
            flex-wrap: wrap;
            gap: 22px;

        }

        .diane-footer-links a {

            color: #dddddd;
            font-size: 10px;
            letter-spacing: .8px;
            text-decoration: none;

        }

        .diane-footer-bottom {

            display: flex;
            justify-content: space-between;
            gap: 15px;

            padding-top: 22px;

            color: #aaaaaa;
            font-size: 10px;

        }

        /* =========================================
           11. RESPONSIVE DESIGN
        ========================================= */

        @media (max-width: 1050px) {

            .cart-layout {

                grid-template-columns: minmax(0, 1fr) 300px;
                gap: 30px;

            }

        }

        @media (max-width: 800px) {

            .cart-layout {

                grid-template-columns: 1fr;
                gap: 35px;

            }

            .cart-summary {
                max-width: 100%;
            }

        }

        @media (max-width: 600px) {

            .diane-header {

                padding: 18px 4%;
                flex-direction: column;
                gap: 16px;

            }

            .diane-header-right {

                flex-direction: column;
                gap: 14px;

            }

            .diane-navigation,
            .diane-account {

                justify-content: center;
                flex-wrap: wrap;
                gap: 12px;

            }

            .cart-page {
                padding: 38px 5% 65px;
            }

            .cart-heading {

                align-items: flex-start;
                flex-direction: column;
                gap: 12px;

            }

            .cart-item {

                grid-template-columns: 105px minmax(0, 1fr);
                gap: 15px;

            }

            .cart-product-image {
                width: 105px;
            }

            .cart-product-name {
                font-size: 17px;
            }

            .cart-product-price {

                grid-column: 2;
                margin-top: -7px;

            }

            .cart-item-actions {
                grid-column: 1 / -1;
            }

            .cart-summary {
                padding: 22px;
            }

            .diane-footer-top,
            .diane-footer-bottom {

                flex-direction: column;

            }

        }

    </style>

</head>

<body>

<!-- =========================================
     HEADER
========================================= -->

<header class="diane-header">

    <a class="diane-brand" href="index.php">

        <span class="diane-brand-name">
            DIANE
        </span>

        <span class="diane-brand-subtitle">
            CLOTHING SHOP
        </span>

    </a>

    <div class="diane-header-right">

        <nav class="diane-navigation" aria-label="Main navigation">

            <a href="index.php">HOME</a>

            <a href="index.php#collection">COLLECTION</a>

            <a href="index.php#try-on">VIRTUAL TRY-ON</a>

        </nav>

        <div class="diane-account">

            <a class="bag-link" href="cart.php">
                MY BAG (<?= (int) $totalItems ?>)
            </a>

            <a href="logout.php">
                LOG OUT
            </a>

            <span class="diane-greeting">
                Hi, <?= cartEscape($customerName) ?>
            </span>

            <!-- PROFILE ICON -->
            <a
                class="diane-profile-icon"
                href="profile.php"
                aria-label="Open my profile"
                title="My profile"
            >
                <svg
                    xmlns="http://www.w3.org/2000/svg"
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="1.6"
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    aria-hidden="true"
                >
                    <circle cx="12" cy="8" r="3.5"></circle>
                    <path d="M5 20c.7-3.3 3.2-5 7-5s6.3 1.7 7 5"></path>
                </svg>
            </a>

        </div>

    </div>

</header>

<!-- =========================================
     SHOPPING BAG
========================================= -->

<main class="cart-page">

    <p class="cart-breadcrumb">
        DIANE / SHOPPING BAG
    </p>

    <div class="cart-heading">

        <div>

            <h1>
                Your Shopping Bag
            </h1>

            <p>
                Review your selected pieces before checkout.
            </p>

        </div>

        <span class="cart-item-count">

            <?= (int) $totalItems ?>

            <?= $totalItems === 1 ? 'ITEM' : 'ITEMS' ?>

        </span>

    </div>

    <!-- SUCCESS MESSAGE -->

    <?php if ($message !== ''): ?>

        <div class="cart-notice">
            <?= cartEscape($message) ?>
        </div>

    <?php endif; ?>

    <!-- ACTION ERROR -->

    <?php if ($error !== ''): ?>

        <div class="cart-notice error">
            <?= cartEscape($error) ?>
        </div>

    <?php endif; ?>

    <!-- SUPABASE LOADING ERROR -->

    <?php if ($loadError): ?>

        <div class="cart-notice error">

            <strong>
                Unable to load your shopping bag.
            </strong>

            <p>
                Please check the technical details below.
            </p>

            <div class="cart-technical-details">

                HTTP Status:
                <?= (int) $loadErrorStatus ?>

                <p>
                    <?= cartEscape(
                        $loadErrorMessage !== ''
                            ? $loadErrorMessage
                            : 'Unknown Supabase error.'
                    ) ?>
                </p>

            </div>

        </div>

    <?php elseif (empty($cartItems)): ?>

        <!-- EMPTY SHOPPING BAG -->

        <section class="cart-empty">

            <div class="cart-empty-symbol">
                DIANE
            </div>

            <h2>
                Your bag is waiting.
            </h2>

            <p>
                You haven't added anything to your bag yet.
                <br>
                Discover timeless pieces from our collection.
            </p>

            <a
                class="cart-empty-button"
                href="index.php#collection"
            >
                EXPLORE COLLECTION
            </a>

        </section>

    <?php else: ?>

        <!-- CART ITEMS AND SUMMARY -->

        <div class="cart-layout">

            <!-- CART ITEM LIST -->

            <section class="cart-items-section">

                <p class="cart-list-heading">
                    YOUR SELECTED ITEMS
                </p>

                <?php foreach ($cartItems as $item): ?>

                    <?php

                        $product = $item['products'];

                        $itemId = (string) ($item['id'] ?? '');

                        $productId = (string) (
                            $product['id'] ?? $item['product_id'] ?? ''
                        );

                        $productName = (string) (
                            $product['name'] ?? 'Product'
                        );

                        $productImage = (string) (
                            $product['image'] ?? ''
                        );

                        $productPrice = (float) (
                            $product['price'] ?? 0
                        );

                        $itemSize = (string) (
                            $item['size'] ?? ''
                        );

                        $quantity = (int) (
                            $item['quantity'] ?? 1
                        );

                        $productStock = max(
                            0,
                            (int) ($product['stock'] ?? 0)
                        );

                        $itemTotal = $productPrice * $quantity;

                    ?>

                    <article class="cart-item">

                        <!-- PRODUCT IMAGE -->

                        <a href="product.php?id=<?= rawurlencode(
                            $productId
                        ) ?>">

                            <?php if ($productImage !== ''): ?>

                                <img
                                    class="cart-product-image"
                                    src="<?= cartEscape($productImage) ?>"
                                    alt="<?= cartEscape($productName) ?>"
                                >

                            <?php else: ?>

                                <div class="cart-product-image"></div>

                            <?php endif; ?>

                        </a>

                        <!-- PRODUCT INFORMATION -->

                        <div class="cart-item-information">

                            <p class="cart-product-category">

                                <?= cartEscape(
                                    $product['category'] ?? 'DIANE COLLECTION'
                                ) ?>

                            </p>

                            <a
                                class="cart-product-name"
                                href="product.php?id=<?= rawurlencode(
                                    $productId
                                ) ?>"
                            >

                                <?= cartEscape($productName) ?>

                            </a>

                            <p class="cart-product-detail">
                                Size: <?= cartEscape($itemSize) ?>
                            </p>

                            <p class="cart-product-detail">
                                Available stock: <?= $productStock ?>
                            </p>

                            <p class="cart-product-detail">
                                In your bag across all sizes:
                                <?= (int) ($item['product_cart_quantity'] ?? $quantity) ?>
                            </p>

                            <?php if (!empty($item['stock_issue'])): ?>

                                <p class="cart-stock-warning" role="alert">

                                    <?= cartEscape($item['stock_issue']) ?>

                                </p>

                            <?php endif; ?>

                            <!-- UPDATE QUANTITY AND REMOVE -->

                            <div class="cart-item-actions">

                                <form
                                    method="POST"
                                    class="cart-quantity-form"
                                >

                                    <input
                                        type="hidden"
                                        name="csrf_token"
                                        value="<?= cartEscape($csrfToken) ?>"
                                    >

                                    <input
                                        type="hidden"
                                        name="item_id"
                                        value="<?= cartEscape($itemId) ?>"
                                    >

                                    <input
                                        type="hidden"
                                        name="action"
                                        value="update"
                                    >

                                    <label for="quantity-<?= cartEscape(
                                        $itemId
                                    ) ?>">
                                        QTY
                                    </label>

                                    <input
                                        type="number"
                                        id="quantity-<?= cartEscape($itemId) ?>"
                                        name="quantity"
                                        min="1"
                                        max="<?= max(
                                            1,
                                            (int) (
                                                $item['maximum_line_quantity']
                                                ?? min($productStock, 99)
                                            )
                                        ) ?>"
                                        value="<?= $quantity ?>"
                                        <?= (
                                            (int) (
                                                $item['maximum_line_quantity']
                                                ?? min($productStock, 99)
                                            ) < 1
                                        ) ? 'disabled' : '' ?>
                                        required
                                    >

                                    <button
                                        class="cart-small-button"
                                        type="submit"
                                        <?= (
                                            (int) (
                                                $item['maximum_line_quantity']
                                                ?? min($productStock, 99)
                                            ) < 1
                                        ) ? 'disabled' : '' ?>
                                    >
                                        UPDATE
                                    </button>

                                </form>

                                <!-- REMOVE ITEM -->

                                <form method="POST">

                                    <input
                                        type="hidden"
                                        name="csrf_token"
                                        value="<?= cartEscape($csrfToken) ?>"
                                    >

                                    <input
                                        type="hidden"
                                        name="item_id"
                                        value="<?= cartEscape($itemId) ?>"
                                    >

                                    <input
                                        type="hidden"
                                        name="action"
                                        value="remove"
                                    >

                                    <button
                                        type="submit"
                                        class="cart-remove-button"
                                    >
                                        REMOVE
                                    </button>

                                </form>

                            </div>

                        </div>

                        <!-- ITEM TOTAL -->

                        <div class="cart-product-price">

                            ₱<?= number_format($itemTotal, 2) ?>

                        </div>

                    </article>

                <?php endforeach; ?>

            </section>

            <!-- ORDER SUMMARY -->

            <aside class="cart-summary">

                <h2>
                    Order Summary
                </h2>

                <div class="cart-summary-row">

                    <span>
                        Items
                    </span>

                    <strong>
                        <?= (int) $totalItems ?>
                    </strong>

                </div>

                <div class="cart-summary-row">

                    <span>
                        Subtotal
                    </span>

                    <strong>
                        ₱<?= number_format($subtotal, 2) ?>
                    </strong>

                </div>

                <div class="cart-summary-row">

                    <span>
                        Shipping
                    </span>

                    <strong>
                        Calculated at checkout
                    </strong>

                </div>

                <div class="cart-summary-row cart-summary-total">

                    <span>
                        TOTAL
                    </span>

                    <strong>
                        ₱<?= number_format($subtotal, 2) ?>
                    </strong>

                </div>

                <p class="cart-shipping-note">

                    Your shipping information and final delivery
                    charges will be collected at checkout.

                </p>

                <?php if ($canProceedToCheckout): ?>

                    <a
                        class="cart-checkout-button"
                        href="checkout.php"
                    >
                        PROCEED TO CHECKOUT
                    </a>

                <?php else: ?>

                    <p class="cart-stock-block-message" role="alert">

                        Please update or remove the items with stock
                        warnings before proceeding to checkout.

                    </p>

                    <span
                        class="cart-checkout-button is-disabled"
                        aria-disabled="true"
                    >
                        UPDATE YOUR BAG TO CONTINUE
                    </span>

                <?php endif; ?>

                <a
                    class="cart-continue-link"
                    href="index.php#collection"
                >
                    CONTINUE SHOPPING
                </a>

            </aside>

        </div>

    <?php endif; ?>

</main>

<!-- =========================================
     FOOTER
========================================= -->

<footer class="diane-footer">

    <div class="diane-footer-top">

        <a
            href="index.php"
            class="diane-footer-brand"
        >

            <strong>DIANE</strong>

            <span>CLOTHING SHOP</span>

        </a>

        <div class="diane-footer-links">

            <a href="index.php">HOME</a>

            <a href="index.php#collection">COLLECTION</a>

            <a href="index.php#try-on">VIRTUAL TRY-ON</a>

            <a href="cart.php">MY BAG</a>

        </div>

    </div>

    <div class="diane-footer-bottom">

        <span>
            DIANE CLOTHING SHOP &copy; <?= date('Y') ?>
        </span>

        <span>
            SIMPLE. TIMELESS. YOU.
        </span>

    </div>

</footer>

</body>
</html>
