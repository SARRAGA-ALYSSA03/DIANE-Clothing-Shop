
<?php
date_default_timezone_set('Asia/Manila');

session_start();

require_once __DIR__ . '/../includes/supabase.php';
require_once __DIR__ . '/../includes/admin_navigation.php';

// Verify admin session
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
    $_SESSION = [];
    session_destroy();
    header('Location: login.php');
    exit;
}

// Create CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Retrieve products from Supabase
$result = supabaseRequest(
    '/rest/v1/products?select=id,name,category,price,stock,image,created_at&order=created_at.desc',
    'GET',
    null,
    $_SESSION['access_token']
);

$products = $result['data'] ?? [];

$error = !$result['success']
    ? 'Unable to load products. Please check your Supabase connection and permissions.'
    : '';

$notice = '';
$deleteError = '';

if (($_GET['deleted'] ?? '') === '1') {
    $notice = 'Product deleted successfully.';
}

if (($_GET['updated'] ?? '') === '1') {
    $notice = 'Product updated successfully.';
}

$deleteErrors = [
    'not_found' => 'Product was not found.',
    'check_failed' => 'Unable to check product history.',
    'has_orders' => 'This product has existing orders and cannot be deleted.',
    'has_inventory_logs' => 'This product has inventory history and cannot be deleted.',
    'delete_failed' => 'Unable to delete the product.'
];

$deleteError = $deleteErrors[$_GET['delete_error'] ?? ''] ?? '';
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Products | Diane Clothing Shop</title>

    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            background: #f4f4f2;
            color: #171717;
            font-family: Arial, Helvetica, sans-serif;
        }

        .page-container {
            max-width: 1450px;
            margin: auto;
            padding: 38px 42px 55px;
        }

        .page-heading {
            display: flex;
            justify-content: space-between;
            align-items: flex-end;
            gap: 20px;
            margin-bottom: 30px;
        }

        .eyebrow {
            color: #777;
            font-size: 10px;
            letter-spacing: 2.5px;
            margin-bottom: 12px;
            font-weight: bold;
        }

        h1 {
            margin: 0 0 9px;
            font-size: 34px;
            font-weight: 500;
            letter-spacing: -1px;
        }

        .page-heading p {
            color: #777;
            font-size: 13px;
            margin: 0;
        }

        .add-button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 9px;
            padding: 14px 21px;
            background: #111;
            color: white;
            text-decoration: none;
            font-size: 11px;
            letter-spacing: 1.5px;
            transition: background .2s;
        }

        .add-button:hover {
            background: #444;
        }

        .add-button svg {
            width: 15px;
            height: 15px;
        }

        .notification {
            padding: 15px 18px;
            margin-bottom: 18px;
            font-size: 13px;
            display: flex;
            align-items: center;
            gap: 10px;
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

        .notification svg {
            width: 18px;
            height: 18px;
            flex-shrink: 0;
        }

        .table-panel {
            background: white;
            border: 1px solid #e6e6e6;
            overflow: hidden;
        }

        .table-heading {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 23px 25px;
            border-bottom: 1px solid #eee;
        }

        .table-title {
            display: flex;
            align-items: center;
            gap: 11px;
        }

        .table-title svg {
            width: 20px;
            height: 20px;
            color: #555;
        }

        .table-heading h2 {
            margin: 0;
            font-size: 16px;
            font-weight: 500;
        }

        .table-heading span {
            color: #888;
            font-size: 11px;
        }

        .table-scroll {
            width: 100%;
            overflow-x: auto;
        }

        table {
            width: 100%;
            min-width: 850px;
            border-collapse: collapse;
        }

        th {
            padding: 17px 16px;
            background: #f8f8f7;
            color: #666;
            text-align: left;
            font-size: 10px;
            font-weight: bold;
            letter-spacing: 1.2px;
            text-transform: uppercase;
        }

        td {
            padding: 16px;
            border-top: 1px solid #eee;
            font-size: 12px;
            vertical-align: middle;
        }

        tbody tr:hover {
            background: #fafafa;
        }

        .product-image {
            width: 55px;
            height: 67px;
            object-fit: cover;
            background: #f1f1f1;
            display: block;
        }

        .product-name {
            font-weight: bold;
            color: #222;
        }

        .product-id {
            font-family: monospace;
            color: #777;
            font-size: 11px;
        }

        .price {
            font-weight: bold;
            white-space: nowrap;
        }

        .stock {
            display: inline-block;
            padding: 6px 9px;
            background: #eee;
            font-size: 11px;
        }

        .stock.low {
            background: #f9e8e8;
            color: #982b2b;
        }

        .actions {
            white-space: nowrap;
        }

        .edit-button,
        .delete-button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            padding: 8px 10px;
            font-size: 10px;
            text-decoration: none;
            cursor: pointer;
        }

        .edit-button svg,
        .delete-button svg {
            width: 13px;
            height: 13px;
        }

        .edit-button {
            background: #111;
            color: white;
            margin-right: 4px;
            border: 1px solid #111;
        }

        .delete-button {
            background: white;
            color: #a12d2d;
            border: 1px solid #d9b5b5;
        }

        .delete-button:hover {
            background: #a12d2d;
            color: white;
        }

        .actions form {
            display: inline;
        }

        .empty {
            background: white;
            text-align: center;
            padding: 65px 20px;
        }

        .empty-icon {
            width: 66px;
            height: 66px;
            margin: 0 auto 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #f3f3f3;
            border-radius: 50%;
        }

        .empty-icon svg {
            width: 31px;
            height: 31px;
            stroke: #555;
        }

        .empty h3 {
            font-size: 19px;
            font-weight: 500;
            margin: 0 0 10px;
        }

        .empty p {
            color: #777;
            font-size: 13px;
            margin-bottom: 23px;
        }

        .back-link {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            margin-top: 25px;
            color: #555;
            text-decoration: none;
            font-size: 11px;
            letter-spacing: 1px;
        }

        .back-link:hover {
            color: #111;
        }

        @media (max-width: 700px) {
            .page-container {
                padding: 27px 16px 40px;
            }

            .page-heading {
                align-items: flex-start;
                flex-direction: column;
            }

            h1 {
                font-size: 28px;
            }

            .add-button {
                width: 100%;
            }

            .table-heading {
                align-items: flex-start;
                flex-direction: column;
                gap: 8px;
            }
        }
    </style>
</head>

<body>

<?php renderAdminNavigation(); ?>

<main class="page-container">

    <section class="page-heading">

        <div>
            <div class="eyebrow">
                DIANE CLOTHING SHOP / INVENTORY
            </div>

            <h1>Products</h1>

            <p>Manage and organize your clothing collection.</p>
        </div>

        <a href="add_product.php" class="add-button">
            <svg viewBox="0 0 24 24" fill="none"
                stroke="currentColor" stroke-width="2"
                stroke-linecap="round">
                <path d="M12 5v14M5 12h14"/>
            </svg>
            ADD NEW PRODUCT
        </a>

    </section>

    <?php if ($notice !== ''): ?>
        <div class="notification success">
            <?= htmlspecialchars($notice) ?>
        </div>
    <?php endif; ?>

    <?php if ($deleteError !== ''): ?>
        <div class="notification error">
            <?= htmlspecialchars($deleteError) ?>
        </div>
    <?php endif; ?>

    <?php if ($error !== ''): ?>

        <div class="notification error">
            <?= htmlspecialchars($error) ?>
        </div>

    <?php elseif (empty($products)): ?>

        <div class="empty">

            <div class="empty-icon">
                <svg viewBox="0 0 24 24" fill="none"
                    stroke="currentColor" stroke-width="1.5"
                    stroke-linecap="round" stroke-linejoin="round">
                    <path d="M5 8h14l1 13H4L5 8Z"/>
                    <path d="M9 8V6a3 3 0 0 1 6 0v2"/>
                    <path d="M9 13h6"/>
                </svg>
            </div>

            <h3>No Products Found</h3>

            <p>
                Your clothing products will appear here once they are
                available in your Supabase database.
            </p>

            <a href="add_product.php" class="add-button">
                <svg viewBox="0 0 24 24" fill="none"
                    stroke="currentColor" stroke-width="2"
                    stroke-linecap="round">
                    <path d="M12 5v14M5 12h14"/>
                </svg>
                ADD YOUR FIRST PRODUCT
            </a>

        </div>

    <?php else: ?>

        <section class="table-panel">

            <div class="table-heading">

                <div class="table-title">
                    <svg viewBox="0 0 24 24" fill="none"
                        stroke="currentColor" stroke-width="1.7"
                        stroke-linecap="round" stroke-linejoin="round">
                        <path d="m12 3 9 5-9 5-9-5 9-5Z"/>
                        <path d="m3 12 9 5 9-5"/>
                        <path d="m3 16 9 5 9-5"/>
                    </svg>

                    <h2>Product Inventory</h2>
                </div>

                <span><?= count($products) ?> products displayed</span>

            </div>

            <div class="table-scroll">

                <table>

                    <thead>
                        <tr>
                            <th>Image</th>
                            <th>Product ID</th>
                            <th>Product Name</th>
                            <th>Category</th>
                            <th>Price</th>
                            <th>Stock</th>
                            <th>Actions</th>
                        </tr>
                    </thead>

                    <tbody>

                        <?php foreach ($products as $product): ?>

                            <tr>

                                <td>
                                    <?php if (!empty($product['image'])): ?>
                                        <img
                                            class="product-image"
                                            src="<?= htmlspecialchars($product['image']) ?>"
                                            alt="Product image"
                                        >
                                    <?php else: ?>
                                        <div class="product-image"></div>
                                    <?php endif; ?>
                                </td>

                                <td class="product-id">
                                    <?= htmlspecialchars($product['id']) ?>
                                </td>

                                <td class="product-name">
                                    <?= htmlspecialchars($product['name']) ?>
                                </td>

                                <td>
                                    <?= htmlspecialchars($product['category']) ?>
                                </td>

                                <td class="price">
                                    ₱<?= number_format((float)$product['price'], 2) ?>
                                </td>

                                <td>
                                    <span class="stock <?= (int)$product['stock'] <= 5 ? 'low' : '' ?>">
                                        <?= (int)$product['stock'] ?> units
                                    </span>
                                </td>

                                <td class="actions">

                                    <a
                                        class="edit-button"
                                        href="edit_product.php?id=<?= urlencode($product['id']) ?>"
                                    >
                                        <svg viewBox="0 0 24 24" fill="none"
                                            stroke="currentColor" stroke-width="2"
                                            stroke-linecap="round">
                                            <path d="m15 5 4 4M4 20l4-.8L19 8a2.8 2.8 0 0 0-4-4L4 15v5Z"/>
                                        </svg>
                                        EDIT
                                    </a>

                                    <form
                                        action="delete_product.php"
                                        method="POST"
                                        style="display:inline;"
                                        onsubmit="return confirm('Are you sure you want to delete this product?');"
                                    >

                                        <input
                                            type="hidden"
                                            name="id"
                                            value="<?= htmlspecialchars($product['id']) ?>"
                                        >

                                        <input
                                            type="hidden"
                                            name="csrf_token"
                                            value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>"
                                        >

                                        <button
                                            type="submit"
                                            class="delete-button"
                                        >
                                            <svg viewBox="0 0 24 24" fill="none"
                                                stroke="currentColor" stroke-width="2"
                                                stroke-linecap="round" stroke-linejoin="round">
                                                <path d="M3 6h18"/>
                                                <path d="M8 6V4h8v2"/>
                                                <path d="m19 6-1 14H6L5 6"/>
                                                <path d="M10 11v5M14 11v5"/>
                                            </svg>
                                            DELETE
                                        </button>

                                    </form>

                                </td>

                            </tr>

                        <?php endforeach; ?>

                    </tbody>

                </table>

            </div>

        </section>

    <?php endif; ?>

    <a href="dashboard.php" class="back-link">
        ← BACK TO DASHBOARD
    </a>

</main>

</body>
</html>
