<?php
/**
 * DIANE CLOTHING SHOP
 * Luxury Product Details Page
 * Connected to Supabase and Add to Bag.
 */

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/includes/supabase.php';

// ==========================================
// CUSTOMER SESSION
// ==========================================

$isCustomerLoggedIn = (
    !empty($_SESSION['customer_id']) &&
    !empty($_SESSION['customer_access_token'])
);

$fullCustomerName = trim(
    (string)($_SESSION['customer_name'] ?? '')
);

$nameParts = preg_split('/\s+/', $fullCustomerName);

$customerName = (
    is_array($nameParts) && !empty($nameParts[0])
)
    ? $nameParts[0]
    : 'Customer';

// CSRF token for cart form.
if ($isCustomerLoggedIn && empty($_SESSION['customer_csrf'])) {
    $_SESSION['customer_csrf'] = bin2hex(random_bytes(32));
}

$customerCsrf = $_SESSION['customer_csrf'] ?? '';

// ==========================================
// SHOPPING BAG COUNT
// ==========================================

$cartCount = 0;

if ($isCustomerLoggedIn) {

    $cartResult = supabaseRequest(
        '/rest/v1/cart_items?select=quantity&user_id=eq.' .
        rawurlencode((string)$_SESSION['customer_id']),
        'GET',
        null,
        (string)$_SESSION['customer_access_token']
    );

    if (
        !empty($cartResult['success']) &&
        is_array($cartResult['data'] ?? null)
    ) {
        foreach ($cartResult['data'] as $item) {
            $cartCount += (int)($item['quantity'] ?? 0);
        }
    }
}

// ==========================================
// LOAD PRODUCT FROM SUPABASE
// ==========================================

$productId = (
    isset($_GET['id']) && is_string($_GET['id'])
)
    ? trim($_GET['id'])
    : '';

$product = null;
$loadError = false;

if ($productId !== '') {

    $result = supabaseRequest(
        '/rest/v1/products?select=*&id=eq.' .
        rawurlencode($productId) .
        '&limit=1',
        'GET'
    );

    if (
        !empty($result['success']) &&
        is_array($result['data'])
    ) {
        $product = $result['data'][0] ?? null;
    } else {
        $loadError = true;
    }
}

// ==========================================
// PRODUCT INFORMATION
// ==========================================

$image = '';
$sizes = [];
$colors = [];
$stock = 0;

if (is_array($product)) {

    $image = trim((string)($product['image'] ?? ''));

    if (
        $image === '' &&
        !empty($product['images']) &&
        is_array($product['images'])
    ) {
        $image = trim((string)($product['images'][0] ?? ''));
    }

    // Product sizes.
    $sizes = is_array($product['sizes'] ?? null)
        ? $product['sizes']
        : [];

    // Product colors.
    $colors = is_array($product['colors'] ?? null)
        ? $product['colors']
        : (
            empty($product['colors'])
                ? []
                : [(string)$product['colors']]
        );

    $stock = max(
        0,
        (int)($product['stock'] ?? 0)
    );
}

// Product rating summary from the Supabase products table.
$productRating = is_array($product)
    ? max(0, min(5, (float)($product['rating'] ?? 0)))
    : 0.0;
$productReviewCount = is_array($product)
    ? max(0, (int)($product['review_count'] ?? 0))
    : 0;

// Safe output helper.
function productEscape($value): string
{
    return htmlspecialchars(
        (string)$value,
        ENT_QUOTES,
        'UTF-8'
    );
}

// ==========================================
// CART FEEDBACK MESSAGES
// ==========================================

$status = (
    isset($_GET['status']) && is_string($_GET['status'])
)
    ? $_GET['status']
    : '';

$statusMessage = '';

if ($status === 'stock') {
    $statusMessage = 'The requested quantity is not available in stock.';
} elseif ($status === 'invalid') {
    $statusMessage = 'Please select a valid size and quantity.';
} elseif ($status === 'error') {
    $statusMessage = 'Unable to add this product. Please try again.';
}

// ==========================================
// CUSTOMER REVIEWS
// ==========================================

$reviews = [];
$reviewEligibility = [
    'can_review' => false,
    'already_reviewed' => false,
    'message' => 'Only customers who have received this product can write a review.'
];
$reviewFeedback = '';
$reviewFeedbackType = '';
$reviewCommentInput = '';
$reviewRatingInput = 5;

// Show the success notice after a successful POST/redirect/GET cycle.
if (
    isset($_GET['review']) &&
    $_GET['review'] === 'success' &&
    !empty($_SESSION['review_flash'])
) {
    $reviewFeedback = (string) $_SESSION['review_flash'];
    $reviewFeedbackType = 'success';
    unset($_SESSION['review_flash']);
}

// Submit a review through a database RPC. The database independently verifies
// the signed-in user, delivered order, receipt confirmation, and product line.
if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    ($_POST['form_action'] ?? '') === 'submit_review' &&
    is_array($product)
) {
    $reviewCommentInput = trim((string) ($_POST['comment'] ?? ''));
    $reviewRatingInput = (int) ($_POST['rating'] ?? 0);
    $submittedCsrf = (string) ($_POST['csrf_token'] ?? '');
    $commentLength = function_exists('mb_strlen')
        ? mb_strlen($reviewCommentInput, 'UTF-8')
        : strlen($reviewCommentInput);

    if (!$isCustomerLoggedIn) {
        $reviewFeedback = 'Please sign in to write a review.';
        $reviewFeedbackType = 'error';
    } elseif (
        $customerCsrf === '' ||
        !hash_equals($customerCsrf, $submittedCsrf)
    ) {
        $reviewFeedback = 'Your session form has expired. Please refresh the page and try again.';
        $reviewFeedbackType = 'error';
    } elseif ($reviewRatingInput < 1 || $reviewRatingInput > 5) {
        $reviewFeedback = 'Please select a rating from 1 to 5 stars.';
        $reviewFeedbackType = 'error';
    } elseif ($commentLength < 5 || $commentLength > 1000) {
        $reviewFeedback = 'Your review must be between 5 and 1,000 characters.';
        $reviewFeedbackType = 'error';
    } else {
        $submitReviewResult = supabaseRequest(
            '/rest/v1/rpc/submit_product_review',
            'POST',
            [
                'p_product_id' => (string) $product['id'],
                'p_rating' => $reviewRatingInput,
                'p_comment' => $reviewCommentInput
            ],
            (string) $_SESSION['customer_access_token']
        );

        $submitReviewData = $submitReviewResult['data'] ?? null;

        if (
            !empty($submitReviewResult['success']) &&
            is_array($submitReviewData) &&
            !empty($submitReviewData['success'])
        ) {
            $_SESSION['review_flash'] = 'Thank you! Your review has been submitted.';
            header(
                'Location: product.php?id=' . rawurlencode((string) $product['id']) .
                '&review=success#customer-reviews'
            );
            exit;
        }

        $reviewFeedback = (
            is_array($submitReviewData) &&
            !empty($submitReviewData['message'])
        )
            ? (string) $submitReviewData['message']
            : (string) ($submitReviewResult['error'] ?? 'Unable to submit your review. Please try again.');
        $reviewFeedbackType = 'error';
    }
}

// Public-safe review list RPC: returns only review text, rating, display name,
// date, and verified-purchase status. It does not expose customer/order IDs.
if (is_array($product)) {
    $reviewListResult = supabaseRequest(
        '/rest/v1/rpc/get_product_reviews',
        'POST',
        ['p_product_id' => (string) $product['id']]
    );

    if (
        !empty($reviewListResult['success']) &&
        is_array($reviewListResult['data'] ?? null)
    ) {
        $reviews = $reviewListResult['data'];
    }

    // Eligibility is checked with the customer's own access token.
    if ($isCustomerLoggedIn) {
        $eligibilityResult = supabaseRequest(
            '/rest/v1/rpc/get_product_review_status',
            'POST',
            ['p_product_id' => (string) $product['id']],
            (string) $_SESSION['customer_access_token']
        );

        if (
            !empty($eligibilityResult['success']) &&
            is_array($eligibilityResult['data'] ?? null)
        ) {
            $reviewEligibility = array_merge(
                $reviewEligibility,
                $eligibilityResult['data']
            );
        }
    }
}

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>
        <?= is_array($product)
            ? productEscape($product['name'] ?? 'Product')
            : 'Product Not Found'
        ?> | DIANE Clothing Shop
    </title>

    <link rel="stylesheet" href="assets/css/style.css">

    <style>

        /* =========================================
           01. GLOBAL
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
            position: relative;
            z-index: 100;

            width: 100%;
            min-height: 86px;
            padding: 12px 5%;

            background: #ffffff;
            border-bottom: 1px solid var(--diane-border);

            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 25px;
        }

        .diane-brand {
            flex: 0 0 auto;

            display: flex;
            flex-direction: column;
            align-items: center;

            text-decoration: none;
            color: #111111;
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
            gap: 32px;
            margin-left: auto;
        }

        .diane-navigation {
            display: flex;
            align-items: center;
            gap: 25px;
        }

        .diane-navigation a,
        .diane-account a {
            color: #111111;
            text-decoration: none;

            font-size: 10px;
            font-weight: 500;
            letter-spacing: 1px;
            white-space: nowrap;
        }

        .diane-navigation a:hover,
        .diane-account a:hover {
            opacity: .55;
        }

        .diane-account {
            display: flex;
            align-items: center;
            gap: 16px;
            white-space: nowrap;
        }

        .diane-greeting {
            color: #666666;
            font-size: 11px;
        }

        .diane-bag-link {
            font-weight: 600 !important;
        }

        /* =========================================
           03. PRODUCT PAGE
        ========================================= */

        .product-page {
            max-width: 1440px;
            margin: 0 auto;
            padding: 38px 5% 100px;
        }

        .product-back-link {
            display: inline-block;
            margin-bottom: 30px;

            color: #777777;
            font-size: 10px;
            font-weight: 600;
            letter-spacing: 1px;
            text-decoration: none;
        }

        .product-back-link:hover {
            color: #111111;
        }

        .product-detail-layout {
            display: grid;
            grid-template-columns: minmax(0, 1.15fr) minmax(320px, .85fr);
            gap: clamp(35px, 7vw, 100px);
            align-items: start;
        }

        /* =========================================
           04. PRODUCT IMAGE
        ========================================= */

        .product-image-column {
            width: 100%;
        }

        .product-image-frame {
            position: relative;

            width: 100%;
            aspect-ratio: 4 / 5;

            overflow: hidden;
            background: #f4f4f2;

            display: flex;
            align-items: center;
            justify-content: center;
        }

        .product-main-image {
            display: block;
            width: 100%;
            height: 100%;

            object-fit: contain;
        }

        .product-image-placeholder {
            color: #999999;
            font-size: 11px;
            letter-spacing: 2px;
        }

        .product-image-caption {
            margin-top: 12px;

            color: #888888;
            font-size: 9px;
            letter-spacing: 1px;
            text-align: center;
        }

        /* =========================================
           05. PRODUCT INFORMATION
        ========================================= */

        .product-information {
            position: sticky;
            top: 30px;

            padding: 10px 0;
        }

        .product-eyebrow {
            margin: 0 0 18px;

            color: #777777;
            font-size: 10px;
            font-weight: 600;
            letter-spacing: 2px;
            text-transform: uppercase;
        }

        .product-name {
            margin: 0;

            font-family: var(--diane-serif);
            font-size: clamp(31px, 3.4vw, 44px);
            font-weight: normal;
            line-height: 1.18;
            letter-spacing: -.8px;
        }

        .product-price-row {
            display: flex;
            align-items: center;
            gap: 12px;
            margin: 20px 0 24px;
        }

        .product-price {
            font-size: 18px;
            font-weight: 600;
        }

        .product-old-price {
            color: #999999;
            font-size: 14px;
            text-decoration: line-through;
        }

        .product-rating {
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 8px;
            margin: -10px 0 24px;
            font-size: 11px;
        }
        .product-rating-stars {
            color: #9a7838;
            letter-spacing: 1px;
            font-size: 13px;
        }
        .product-rating-value {
            color: #333333;
            font-weight: 600;
        }
        .product-rating-count,
        .product-rating-empty {
            color: #888888;
        }

        .product-description {
            margin: 0 0 28px;

            color: #666666;
            font-size: 13px;
            line-height: 1.9;
            white-space: pre-line;
        }

        /* =========================================
           06. PRODUCT DETAILS
        ========================================= */

        .product-specifications {
            border-top: 1px solid var(--diane-border);
            margin-bottom: 25px;
        }

        .product-specification {
            display: grid;
            grid-template-columns: 125px 1fr;
            gap: 15px;

            padding: 15px 0;
            border-bottom: 1px solid var(--diane-border);

            font-size: 11px;
            line-height: 1.6;
        }

        .specification-label {
            color: #888888;
        }

        .specification-value {
            color: #222222;
        }

        .product-size-chips,
        .product-color-chips {
            display: flex;
            flex-wrap: wrap;
            gap: 7px;
        }

        .product-size-chip,
        .product-color-chip {
            padding: 6px 9px;
            border: 1px solid #e0e0e0;
            font-size: 10px;
        }

        .product-stock {
            margin: 20px 0 24px;

            color: #666666;
            font-size: 11px;
        }

        /* =========================================
           07. ADD TO BAG FORM
        ========================================= */

        .product-cart-form {
            padding-top: 22px;
            border-top: 1px solid var(--diane-border);
        }

        .product-form-group {
            margin-bottom: 20px;
        }

        .product-form-label {
            display: block;
            margin-bottom: 10px;

            color: #333333;
            font-size: 10px;
            font-weight: 700;
            letter-spacing: 1px;
        }

        .product-form-select,
        .product-form-quantity {
            width: 100%;
            min-height: 47px;

            padding: 12px;

            background: #ffffff;
            color: #111111;

            border: 1px solid #dddddd;
            border-radius: 0;

            font-size: 12px;
        }

        .product-form-select:focus,
        .product-form-quantity:focus {
            outline: none;
            border-color: #111111;
        }

        .product-add-button {
            display: flex;
            align-items: center;
            justify-content: center;

            width: 100%;
            min-height: 54px;

            background: #111111;
            color: #ffffff;

            border: 1px solid #111111;
            border-radius: 0;

            font-size: 10px;
            font-weight: 700;
            letter-spacing: 1.5px;

            cursor: pointer;
            transition: .2s ease;
        }

        .product-add-button:hover {
            background: #ffffff;
            color: #111111;
        }

        .product-add-button:disabled {
            opacity: .45;
            cursor: not-allowed;
        }

        .product-signin-box {
            margin-top: 25px;
            padding: 19px;

            background: #f5f5f3;
            color: #555555;

            font-size: 12px;
            line-height: 1.8;
        }

        .product-signin-box a {
            display: inline-block;
            margin-top: 10px;

            color: #111111;
            font-size: 10px;
            font-weight: 700;
            letter-spacing: 1px;
        }

        .product-alert {
            margin: 20px 0;
            padding: 14px;

            background: #f5f5f3;
            color: #a21d1d;

            font-size: 12px;
            line-height: 1.6;
        }

        /* =========================================
           08. ADDITIONAL PRODUCT INFORMATION
        ========================================= */

        .product-extra-info {
            margin-top: 35px;
            border-top: 1px solid var(--diane-border);
        }

        .product-extra-row {
            padding: 17px 0;
            border-bottom: 1px solid var(--diane-border);

            display: flex;
            align-items: center;
            justify-content: space-between;

            color: #444444;
            font-size: 11px;
        }

        /* =========================================
           09. ERROR AND EMPTY STATES
        ========================================= */

        .product-message {
            max-width: 650px;
            margin: 90px auto;
            padding: 35px 20px;
            text-align: center;
        }

        .product-message h1 {
            font-family: var(--diane-serif);
            font-size: 34px;
            font-weight: normal;
        }

        .product-message p {
            color: #777777;
            font-size: 13px;
            line-height: 1.8;
        }

        .product-message a {
            display: inline-flex;
            align-items: center;
            justify-content: center;

            min-height: 47px;
            margin-top: 20px;
            padding: 0 25px;

            background: #111111;
            color: #ffffff;

            font-size: 10px;
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
            padding-bottom: 35px;
            border-bottom: 1px solid #3a3a3a;

            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 30px;
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
            padding-top: 22px;

            display: flex;
            justify-content: space-between;
            gap: 15px;

            color: #aaaaaa;
            font-size: 10px;
        }

        /* =========================================
           11. RESPONSIVE DESIGN
        ========================================= */

        @media (max-width: 1050px) {

            .diane-header {
                padding: 15px 3%;
            }

            .diane-header-right {
                gap: 16px;
            }

            .diane-navigation {
                gap: 13px;
            }

            .diane-account {
                gap: 10px;
            }

            .product-detail-layout {
                gap: 35px;
            }

        }

        @media (max-width: 800px) {

            .diane-header {
                flex-wrap: wrap;
                justify-content: center;
            }

            .diane-header-right {
                width: 100%;
                justify-content: center;
                flex-wrap: wrap;
            }

            .product-detail-layout {
                grid-template-columns: 1fr;
                gap: 28px;
            }

            .product-information {
                position: static;
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

            .product-page {
                padding: 25px 5% 65px;
            }

            .product-image-frame {
                aspect-ratio: 4 / 5;
            }

            .product-name {
                font-size: 34px;
            }

            .product-specification {
                grid-template-columns: 105px 1fr;
            }

            .diane-footer-top,
            .diane-footer-bottom {
                flex-direction: column;
            }

        }

    

        /* =========================================
           12. CUSTOMER REVIEWS
        ========================================= */

        .product-reviews-section {
            margin-top: 70px;
            padding-top: 42px;
            border-top: 1px solid var(--diane-border);
        }

        .reviews-section-heading {
            display: flex;
            align-items: flex-end;
            justify-content: space-between;
            gap: 20px;
            margin-bottom: 28px;
        }

        .reviews-section-heading h2 {
            margin: 0;
            font-family: var(--diane-serif);
            font-size: clamp(27px, 3vw, 36px);
            font-weight: normal;
        }

        .reviews-section-heading p {
            margin: 8px 0 0;
            color: #777;
            font-size: 12px;
            line-height: 1.7;
        }

        .reviews-summary {
            flex: 0 0 auto;
            text-align: right;
            color: #777;
            font-size: 11px;
        }

        .reviews-summary strong {
            display: block;
            color: #111;
            font-size: 22px;
            font-weight: 600;
        }

        .reviews-summary-stars,
        .review-card-stars {
            color: #9a7838;
            letter-spacing: 1px;
        }

        .reviews-layout {
            display: grid;
            grid-template-columns: minmax(0, 1.3fr) minmax(280px, .7fr);
            gap: 45px;
            align-items: start;
        }

        .reviews-list {
            border-top: 1px solid var(--diane-border);
        }

        .review-card {
            padding: 22px 0;
            border-bottom: 1px solid var(--diane-border);
        }

        .review-card-header {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 15px;
        }

        .reviewer-name {
            color: #222;
            font-size: 12px;
            font-weight: 700;
        }

        .review-date {
            margin-top: 5px;
            color: #888;
            font-size: 10px;
        }

        .review-verified {
            display: inline-block;
            margin-top: 8px;
            color: #55745e;
            font-size: 9px;
            letter-spacing: .5px;
            text-transform: uppercase;
        }

        .review-card-stars {
            flex: 0 0 auto;
            font-size: 13px;
            white-space: nowrap;
        }

        .review-comment {
            margin: 13px 0 0;
            color: #555;
            font-size: 12px;
            line-height: 1.85;
            white-space: normal;
            overflow-wrap: anywhere;
        }

        .reviews-empty {
            padding: 22px 0;
            color: #777;
            font-size: 12px;
            line-height: 1.8;
        }

        .review-form-panel {
            padding: 25px;
            background: #f6f6f4;
            border: 1px solid #e8e8e5;
        }

        .review-form-panel h3 {
            margin: 0 0 8px;
            font-family: var(--diane-serif);
            font-size: 23px;
            font-weight: normal;
        }

        .review-form-intro,
        .review-status-message {
            margin: 0 0 18px;
            color: #777;
            font-size: 11px;
            line-height: 1.8;
        }

        .review-feedback {
            margin: 0 0 16px;
            padding: 12px 13px;
            font-size: 11px;
            line-height: 1.6;
        }

        .review-feedback.success {
            background: #eaf2eb;
            color: #315c3b;
        }

        .review-feedback.error {
            background: #f8eaea;
            color: #982b2b;
        }

        .review-form-group {
            margin-bottom: 17px;
        }

        .review-form-label {
            display: block;
            margin-bottom: 9px;
            color: #333;
            font-size: 10px;
            font-weight: 700;
            letter-spacing: 1px;
        }

        .review-star-picker {
            display: flex;
            align-items: center;
            gap: 7px;
        }

        .review-star-button {
            padding: 0;
            border: 0;
            background: transparent;
            color: #c9c9c9;
            font-size: 27px;
            line-height: 1;
            cursor: pointer;
            transition: color .15s ease;
        }

        .review-star-button.is-active {
            color: #9a7838;
        }

        .review-star-button:focus-visible {
            outline: 2px solid #777;
            outline-offset: 3px;
        }

        .review-form-textarea {
            display: block;
            width: 100%;
            min-height: 130px;
            padding: 12px;
            resize: vertical;
            border: 1px solid #ddd;
            border-radius: 0;
            background: #fff;
            color: #222;
            font: inherit;
            font-size: 12px;
            line-height: 1.7;
        }

        .review-form-textarea:focus {
            outline: none;
            border-color: #111;
        }

        .review-submit-button {
            width: 100%;
            min-height: 48px;
            padding: 12px 16px;
            border: 1px solid #111;
            border-radius: 0;
            background: #111;
            color: #fff;
            font-size: 10px;
            font-weight: 700;
            letter-spacing: 1.2px;
            cursor: pointer;
            transition: .2s ease;
        }

        .review-submit-button:hover {
            background: #fff;
            color: #111;
        }

        .review-signin-link {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 43px;
            padding: 0 17px;
            border: 1px solid #111;
            color: #111;
            font-size: 10px;
            font-weight: 700;
            letter-spacing: 1px;
            text-decoration: none;
        }

        .review-signin-link:hover {
            background: #111;
            color: #fff;
        }

        @media (max-width: 800px) {
            .reviews-layout {
                grid-template-columns: 1fr;
                gap: 25px;
            }
        }

        @media (max-width: 600px) {
            .product-reviews-section {
                margin-top: 48px;
                padding-top: 30px;
            }

            .reviews-section-heading {
                align-items: flex-start;
                flex-direction: column;
            }

            .reviews-summary {
                text-align: left;
            }

            .review-form-panel {
                padding: 20px;
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

            <a href="index.php">
                HOME
            </a>

            <a href="index.php#collection">
                COLLECTION
            </a>

            <a href="index.php#try-on">
                VIRTUAL TRY-ON
            </a>

        </nav>

        <div class="diane-account">

            <?php if ($isCustomerLoggedIn): ?>

                <a
                    class="diane-bag-link"
                    href="cart.php"
                >
                    MY BAG (<?= (int)$cartCount ?>)
                </a>

                <a href="logout.php">
                    LOG OUT
                </a>

                <span class="diane-greeting">
                    Hi, <?= productEscape($customerName) ?>
                </span>

            <?php else: ?>

                <a href="login.php">
                    SIGN IN
                </a>

                <a href="signup.php">
                    CREATE ACCOUNT
                </a>

            <?php endif; ?>

        </div>

    </div>

</header>

<!-- =========================================
     PRODUCT CONTENT
========================================= -->

<?php if ($loadError): ?>

    <main class="product-message">

        <h1>Unable to load this product</h1>

        <p>
            Please refresh the page or return to the collection.
        </p>

        <a href="index.php#collection">
            BACK TO COLLECTION
        </a>

    </main>

<?php elseif (!is_array($product)): ?>

    <main class="product-message">

        <h1>Product not found</h1>

        <p>
            This product may have been removed or the link may be incorrect.
        </p>

        <a href="index.php#collection">
            BACK TO COLLECTION
        </a>

    </main>

<?php else: ?>

    <main class="product-page">

        <a
            class="product-back-link"
            href="index.php#collection"
        >
            ← BACK TO COLLECTION
        </a>

        <section class="product-detail-layout">

            <!-- PRODUCT IMAGE -->

            <div class="product-image-column">

                <div class="product-image-frame">

                    <?php if ($image !== ''): ?>

                        <img
                            class="product-main-image"
                            src="<?= productEscape($image) ?>"
                            alt="<?= productEscape(
                                $product['name'] ?? 'Product image'
                            ) ?>"
                        >

                    <?php else: ?>

                        <span class="product-image-placeholder">
                            NO PRODUCT IMAGE
                        </span>

                    <?php endif; ?>

                </div>

                <p class="product-image-caption">
                    DIANE CLOTHING SHOP / COLLECTION
                </p>

            </div>

            <!-- PRODUCT INFORMATION -->

            <div class="product-information">

                <p class="product-eyebrow">

                    <?= productEscape(
                        $product['category'] ?? 'Collection'
                    ) ?>

                    <?php if (!empty($product['is_new'])): ?>
                        / NEW ARRIVAL
                    <?php endif; ?>

                </p>

                <h1 class="product-name">

                    <?= productEscape(
                        $product['name'] ?? 'Unnamed Product'
                    ) ?>

                </h1>

                <!-- PRODUCT PRICE -->

                <div class="product-price-row">

                    <span class="product-price">

                        ₱<?= number_format(
                            (float)($product['price'] ?? 0),
                            2
                        ) ?>

                    </span>

                    <?php if (
                        isset($product['original_price']) &&
                        (float)$product['original_price'] >
                        (float)($product['price'] ?? 0)
                    ): ?>

                        <span class="product-old-price">

                            ₱<?= number_format(
                                (float)$product['original_price'],
                                2
                            ) ?>

                        </span>

                    <?php endif; ?>

                </div>

                <!-- CUSTOMER RATING -->
                <div
                    class="product-rating"
                    aria-label="<?= $productReviewCount > 0
                        ? number_format($productRating, 1) . ' out of 5 stars, ' . $productReviewCount . ' reviews'
                        : 'No customer reviews yet'
                    ?>"
                >
                    <?php if ($productReviewCount > 0): ?>
                        <?php $productFilledStars = (int)round($productRating); ?>
                        <span class="product-rating-stars" aria-hidden="true"><?= str_repeat('★', $productFilledStars) . str_repeat('☆', 5 - $productFilledStars) ?></span>
                        <span class="product-rating-value"><?= number_format($productRating, 1) ?></span>
                        <span class="product-rating-count">(<?= $productReviewCount ?> <?= $productReviewCount === 1 ? 'review' : 'reviews' ?>)</span>
                    <?php else: ?>
                        <span class="product-rating-empty">No reviews yet</span>
                    <?php endif; ?>
                </div>

                <!-- DESCRIPTION -->

                <p class="product-description">

                    <?= productEscape(
                        $product['description'] ??
                        'No description available.'
                    ) ?>

                </p>

                <!-- PRODUCT SPECIFICATIONS -->

                <div class="product-specifications">

                    <div class="product-specification">

                        <span class="specification-label">
                            Available sizes
                        </span>

                        <div class="product-size-chips">

                            <?php if (!empty($sizes)): ?>

                                <?php foreach ($sizes as $size): ?>

                                    <span class="product-size-chip">

                                        <?= productEscape($size) ?>

                                    </span>

                                <?php endforeach; ?>

                            <?php else: ?>

                                Not specified

                            <?php endif; ?>

                        </div>

                    </div>

                    <div class="product-specification">

                        <span class="specification-label">
                            Color
                        </span>

                        <div class="product-color-chips">

                            <?php if (!empty($colors)): ?>

                                <?php foreach ($colors as $color): ?>

                                    <span class="product-color-chip">

                                        <?= productEscape($color) ?>

                                    </span>

                                <?php endforeach; ?>

                            <?php else: ?>

                                Not specified

                            <?php endif; ?>

                        </div>

                    </div>

                    <?php if (!empty($product['material'])): ?>

                        <div class="product-specification">

                            <span class="specification-label">
                                Material
                            </span>

                            <span class="specification-value">

                                <?= productEscape(
                                    $product['material']
                                ) ?>

                            </span>

                        </div>

                    <?php endif; ?>

                    <div class="product-specification">

                        <span class="specification-label">
                            Category
                        </span>

                        <span class="specification-value">

                            <?= productEscape(
                                $product['category'] ?? ''
                            ) ?>

                        </span>

                    </div>

                </div>

                <!-- STOCK -->

                <p class="product-stock">

                    <?= $stock > 0
                        ? 'IN STOCK — ' . $stock . ' AVAILABLE'
                        : 'CURRENTLY OUT OF STOCK'
                    ?>

                </p>

                <!-- FEEDBACK -->

                <?php if ($statusMessage !== ''): ?>

                    <div class="product-alert" role="alert">

                        <?= productEscape($statusMessage) ?>

                    </div>

                <?php endif; ?>

                <!-- ADD TO BAG -->

                <?php if ($isCustomerLoggedIn): ?>

                    <?php if ($stock > 0 && !empty($sizes)): ?>

                        <form
                            class="product-cart-form"
                            method="POST"
                            action="add_to_cart.php"
                        >

                            <input
                                type="hidden"
                                name="csrf_token"
                                value="<?= productEscape(
                                    $customerCsrf
                                ) ?>"
                            >

                            <input
                                type="hidden"
                                name="product_id"
                                value="<?= productEscape(
                                    $product['id']
                                ) ?>"
                            >

                            <div class="product-form-group">

                                <label
                                    class="product-form-label"
                                    for="product-size"
                                >
                                    SELECT YOUR SIZE
                                </label>

                                <select
                                    class="product-form-select"
                                    name="size"
                                    id="product-size"
                                    required
                                >

                                    <option value="">
                                        CHOOSE A SIZE
                                    </option>

                                    <?php foreach ($sizes as $size): ?>

                                        <option
                                            value="<?= productEscape($size) ?>"
                                        >
                                            <?= productEscape($size) ?>
                                        </option>

                                    <?php endforeach; ?>

                                </select>

                            </div>

                            <div class="product-form-group">

                                <label
                                    class="product-form-label"
                                    for="product-quantity"
                                >
                                    QUANTITY
                                </label>

                                <input
                                    class="product-form-quantity"
                                    type="number"
                                    id="product-quantity"
                                    name="quantity"
                                    min="1"
                                    max="<?= min($stock, 99) ?>"
                                    value="1"
                                    required
                                >

                            </div>

                            <button
                                type="submit"
                                class="product-add-button"
                            >
                                ADD TO BAG
                            </button>

                        </form>

                    <?php else: ?>

                        <button
                            class="product-add-button"
                            type="button"
                            disabled
                        >
                            OUT OF STOCK
                        </button>

                    <?php endif; ?>

                <?php else: ?>

                    <div class="product-signin-box">

                        Please sign in to add this product to your shopping bag.

                        <br>

                        <a href="login.php">
                            SIGN IN TO CONTINUE
                        </a>

                    </div>

                <?php endif; ?>

                <!-- ADDITIONAL INFORMATION -->

                <div class="product-extra-info">

                    <div class="product-extra-row">

                        <span>
                            Product availability
                        </span>

                        <span>
                            <?= $stock > 0 ? 'Available' : 'Unavailable' ?>
                        </span>

                    </div>

                    <div class="product-extra-row">

                        <span>
                            Shopping and delivery
                        </span>

                        <span>
                            Available at checkout
                        </span>

                    </div>

                </div>

            </div>

        </section>

        <!-- =========================================
             CUSTOMER REVIEWS
        ========================================= -->
        <section class="product-reviews-section" id="customer-reviews" aria-labelledby="customer-reviews-title">
            <div class="reviews-section-heading">
                <div>
                    <p class="product-eyebrow">DIANE COMMUNITY</p>
                    <h2 id="customer-reviews-title">Customer Reviews</h2>
                    <p>Feedback from customers who have received this product.</p>
                </div>

                <div class="reviews-summary">
                    <?php if ($productReviewCount > 0): ?>
                        <strong><?= number_format($productRating, 1) ?> / 5</strong>
                        <span class="reviews-summary-stars" aria-hidden="true"><?= str_repeat('★', (int) round($productRating)) . str_repeat('☆', 5 - (int) round($productRating)) ?></span>
                        <div><?= $productReviewCount ?> <?= $productReviewCount === 1 ? 'review' : 'reviews' ?></div>
                    <?php else: ?>
                        <strong>—</strong>
                        <div>No reviews yet</div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="reviews-layout">
                <div class="reviews-list" aria-live="polite">
                    <?php if (!empty($reviews)): ?>
                        <?php foreach ($reviews as $review): ?>
                            <?php
                                $reviewStars = max(1, min(5, (int) ($review['rating'] ?? 0)));
                                $reviewTimestamp = !empty($review['created_at'])
                                    ? strtotime((string) $review['created_at'])
                                    : false;
                                $reviewDateLabel = $reviewTimestamp
                                    ? date('F j, Y', $reviewTimestamp)
                                    : '';
                            ?>
                            <article class="review-card">
                                <div class="review-card-header">
                                    <div>
                                        <div class="reviewer-name"><?= productEscape($review['reviewer_name'] ?? 'DIANE Customer') ?></div>
                                        <?php if ($reviewDateLabel !== ''): ?>
                                            <div class="review-date"><?= productEscape($reviewDateLabel) ?></div>
                                        <?php endif; ?>
                                        <?php if (!empty($review['verified_purchase'])): ?>
                                            <span class="review-verified">✓ Verified purchase</span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="review-card-stars" aria-label="<?= $reviewStars ?> out of 5 stars">
                                        <?= str_repeat('★', $reviewStars) . str_repeat('☆', 5 - $reviewStars) ?>
                                    </div>
                                </div>
                                <p class="review-comment"><?= nl2br(productEscape($review['comment'] ?? '')) ?></p>
                            </article>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <p class="reviews-empty">
                            No customer reviews yet. Once a customer receives this product and shares feedback, their review will appear here.
                        </p>
                    <?php endif; ?>
                </div>

                <aside class="review-form-panel">
                    <h3>Write a Review</h3>
                    <p class="review-form-intro">Your feedback helps other customers make informed choices.</p>

                    <?php if ($reviewFeedback !== ''): ?>
                        <div class="review-feedback <?= $reviewFeedbackType === 'success' ? 'success' : 'error' ?>" role="status">
                            <?= productEscape($reviewFeedback) ?>
                        </div>
                    <?php endif; ?>

                    <?php if (!$isCustomerLoggedIn): ?>
                        <p class="review-status-message">Please sign in with your customer account to check review eligibility.</p>
                        <a class="review-signin-link" href="login.php">SIGN IN TO REVIEW</a>
                    <?php elseif (!empty($reviewEligibility['already_reviewed'])): ?>
                        <p class="review-status-message">You have already reviewed this product. Thank you for sharing your feedback.</p>
                    <?php elseif (!empty($reviewEligibility['can_review'])): ?>
                        <form method="POST" action="product.php?id=<?= rawurlencode((string) $product['id']) ?>#customer-reviews">
                            <input type="hidden" name="form_action" value="submit_review">
                            <input type="hidden" name="csrf_token" value="<?= productEscape($customerCsrf) ?>">
                            <input type="hidden" name="rating" value="<?= (int) $reviewRatingInput ?>" data-review-rating>

                            <div class="review-form-group">
                                <span class="review-form-label">YOUR RATING</span>
                                <div class="review-star-picker" data-review-stars role="group" aria-label="Choose a rating from one to five stars">
                                    <?php for ($starOption = 1; $starOption <= 5; $starOption++): ?>
                                        <button
                                            class="review-star-button <?= $starOption <= $reviewRatingInput ? 'is-active' : '' ?>"
                                            type="button"
                                            data-rating="<?= $starOption ?>"
                                            aria-label="<?= $starOption ?> <?= $starOption === 1 ? 'star' : 'stars' ?>"
                                            aria-pressed="<?= $starOption <= $reviewRatingInput ? 'true' : 'false' ?>"
                                        >★</button>
                                    <?php endfor; ?>
                                </div>
                            </div>

                            <div class="review-form-group">
                                <label class="review-form-label" for="review-comment">YOUR REVIEW</label>
                                <textarea
                                    class="review-form-textarea"
                                    id="review-comment"
                                    name="comment"
                                    minlength="5"
                                    maxlength="1000"
                                    placeholder="Share your experience with this product..."
                                    required
                                ><?= productEscape($reviewCommentInput) ?></textarea>
                            </div>

                            <button class="review-submit-button" type="submit">SUBMIT REVIEW</button>
                        </form>
                    <?php else: ?>
                        <p class="review-status-message">
                            <?= productEscape($reviewEligibility['message'] ?? 'You can write a review after your order is delivered and you confirm receipt.') ?>
                        </p>
                    <?php endif; ?>
                </aside>
            </div>
        </section>

    </main>

<?php endif; ?>

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

            <a href="index.php">
                HOME
            </a>

            <a href="index.php#collection">
                COLLECTION
            </a>

            <a href="index.php#try-on">
                VIRTUAL TRY-ON
            </a>

            <?php if ($isCustomerLoggedIn): ?>

                <a href="cart.php">
                    MY BAG
                </a>

            <?php else: ?>

                <a href="login.php">
                    SIGN IN
                </a>

            <?php endif; ?>

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


<script>
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('[data-review-stars]').forEach(function (picker) {
        const form = picker.closest('form');
        const ratingInput = form ? form.querySelector('[data-review-rating]') : null;
        const buttons = Array.from(picker.querySelectorAll('[data-rating]'));

        if (!ratingInput || buttons.length === 0) {
            return;
        }

        function setRating(value) {
            const rating = Math.max(1, Math.min(5, Number(value) || 5));
            ratingInput.value = String(rating);

            buttons.forEach(function (button) {
                const active = Number(button.dataset.rating) <= rating;
                button.classList.toggle('is-active', active);
                button.setAttribute('aria-pressed', active ? 'true' : 'false');
            });
        }

        buttons.forEach(function (button) {
            button.addEventListener('click', function () {
                setRating(button.dataset.rating);
            });
        });

        setRating(ratingInput.value);
    });
});
</script>

</body>
</html>
