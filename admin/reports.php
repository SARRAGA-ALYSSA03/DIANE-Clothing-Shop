
<?php
date_default_timezone_set('Asia/Manila');

session_start();

require_once __DIR__ . '/../includes/supabase.php';
require_once __DIR__ . '/../includes/admin_navigation.php';

// 1. Verify admin session
if (
    empty($_SESSION['admin_id']) ||
    empty($_SESSION['access_token'])
) {
    header('Location: login.php');
    exit;
}

// Admin access was verified during login. Keep the existing session active.

// 3. Retrieve orders from Supabase
$result = supabaseRequest(
    '/rest/v1/orders?select=id,total,status,payment_status,created_at&order=created_at.desc&limit=1000',
    'GET',
    null,
    $_SESSION['access_token']
);

$orders = (
    !empty($result['success']) &&
    isset($result['data']) &&
    is_array($result['data'])
)
    ? $result['data']
    : [];

$error = empty($result['success'])
    ? 'Unable to generate reports. Please check your Supabase connection and permissions.'
    : '';

// 4. Initialize report information
$totalOrders = count($orders);
$totalOrderValue = 0;

$statusCounts = [
    'Processing' => 0,
    'Shipped' => 0,
    'Delivered' => 0,
    'Cancelled' => 0
];

$monthlyOrders = [];

$currentMonth = date('Y-m');

// 5. Calculate order totals and monthly information
foreach ($orders as $order) {

    $total = (float)($order['total'] ?? 0);

    $totalOrderValue += $total;

    $status = $order['status'] ?? 'Unknown';

    if (isset($statusCounts[$status])) {
        $statusCounts[$status]++;
    }

    if (!empty($order['created_at'])) {

        $timestamp = strtotime($order['created_at']);

        if ($timestamp !== false) {

            $monthKey = date('Y-m', $timestamp);
            $monthLabel = date('F Y', $timestamp);

            if (!isset($monthlyOrders[$monthKey])) {

                $monthlyOrders[$monthKey] = [
                    'month' => $monthLabel,
                    'orders' => 0,
                    'value' => 0
                ];

            }

            $monthlyOrders[$monthKey]['orders']++;
            $monthlyOrders[$monthKey]['value'] += $total;
        }
    }
}

// 6. Sort monthly report newest first
krsort($monthlyOrders);

// 7. Current month totals
$currentMonthOrders = $monthlyOrders[$currentMonth]['orders'] ?? 0;
$currentMonthValue = $monthlyOrders[$currentMonth]['value'] ?? 0;

// 8. Find highest monthly order value
$maxMonthlyValue = 0;

foreach ($monthlyOrders as $month) {
    $maxMonthlyValue = max(
        $maxMonthlyValue,
        $month['value']
    );
}

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Reports | Diane Clothing Shop</title>

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

        .eyebrow {
            color: #777;
            font-size: 10px;
            letter-spacing: 2.5px;
            font-weight: bold;
            margin-bottom: 12px;
        }

        .page-heading {
            display: flex;
            justify-content: space-between;
            align-items: flex-end;
            gap: 20px;
            margin-bottom: 30px;
        }

        h1 {
            margin: 0 0 9px;
            font-size: 34px;
            font-weight: 500;
            letter-spacing: -1px;
        }

        .page-heading p {
            margin: 0;
            color: #777;
            font-size: 14px;
            line-height: 1.6;
        }

        .report-date {
            color: #777;
            font-size: 12px;
            text-align: right;
        }

        /* Main summaries */

        .summary-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 18px;
            margin-bottom: 28px;
        }

        .summary-card {
            background: white;
            border: 1px solid #e6e6e6;
            padding: 24px;
        }

        .summary-label {
            margin-bottom: 14px;
            color: #777;
            font-size: 11px;
            letter-spacing: 1.4px;
        }

        .summary-value {
            font-size: 29px;
            font-weight: 500;
        }

        .summary-description {
            margin-top: 9px;
            color: #999;
            font-size: 11px;
        }

        /* Status cards */

        .section-title {
            margin: 0 0 18px;
            font-size: 18px;
            font-weight: 500;
        }

        .status-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 13px;
            margin-bottom: 28px;
        }

        .status-card {
            padding: 21px;
            background: white;
            border: 1px solid #e6e6e6;
        }

        .status-card span {
            display: block;
            margin-bottom: 11px;
            color: #777;
            font-size: 12px;
        }

        .status-card strong {
            font-size: 25px;
            font-weight: 500;
        }

        /* Report panels */

        .report-panel {
            background: white;
            border: 1px solid #e6e6e6;
            padding: 25px;
            margin-bottom: 22px;
        }

        .report-panel h2 {
            margin: 0 0 8px;
            font-size: 17px;
            font-weight: 500;
        }

        .panel-description {
            margin: 0 0 25px;
            color: #777;
            font-size: 12px;
            line-height: 1.6;
        }

        /* Monthly bars */

        .bar-row {
            margin: 22px 0;
        }

        .bar-label {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 15px;
            margin-bottom: 10px;
            font-size: 13px;
        }

        .bar-label strong {
            font-weight: 500;
        }

        .bar-label span {
            font-weight: bold;
        }

        .bar-background {
            width: 100%;
            height: 13px;
            background: #eee;
        }

        .bar-fill {
            height: 100%;
            min-width: 0;
            background: #222;
            transition: width .3s;
        }

        .bar-caption {
            display: block;
            margin-top: 8px;
            color: #888;
            font-size: 11px;
        }

        /* Monthly table */

        .table-panel {
            background: white;
            border: 1px solid #e6e6e6;
            overflow: hidden;
        }

        .table-heading {
            padding: 23px 25px;
            border-bottom: 1px solid #eee;
        }

        .table-heading h2 {
            margin: 0;
            font-size: 17px;
            font-weight: 500;
        }

        .table-scroll {
            width: 100%;
            overflow-x: auto;
        }

        table {
            width: 100%;
            min-width: 550px;
            border-collapse: collapse;
        }

        th {
            padding: 17px;
            background: #f8f8f7;
            color: #666;
            text-align: left;
            font-size: 11px;
            letter-spacing: 1px;
            text-transform: uppercase;
        }

        td {
            padding: 17px;
            border-top: 1px solid #eee;
            font-size: 13px;
        }

        tbody tr:hover {
            background: #fafafa;
        }

        .money {
            font-weight: bold;
            white-space: nowrap;
        }

        /* Empty and error states */

        .empty {
            padding: 45px 20px;
            color: #777;
            text-align: center;
            font-size: 13px;
            line-height: 1.7;
        }

        .notification {
            padding: 16px 18px;
            background: #f9e8e8;
            color: #982b2b;
            border: 1px solid #edcece;
            font-size: 14px;
            line-height: 1.6;
        }

        .report-note {
            margin-top: 18px;
            padding: 16px 18px;
            background: white;
            border: 1px solid #e6e6e6;
            color: #777;
            font-size: 12px;
            line-height: 1.7;
        }

        /* Responsive */

        @media (max-width: 850px) {

            .summary-grid {
                grid-template-columns: 1fr;
            }

            .status-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media (max-width: 600px) {

            .page-container {
                padding: 27px 16px 40px;
            }

            .page-heading {
                align-items: flex-start;
                flex-direction: column;
            }

            .report-date {
                text-align: left;
            }

            h1 {
                font-size: 28px;
            }

            .report-panel {
                padding: 20px;
            }

            .bar-label {
                align-items: flex-start;
                flex-direction: column;
                gap: 5px;
            }
        }
    </style>

</head>

<body>

<?php renderAdminNavigation(); ?>

<main class="page-container">

    <!-- Page heading -->

    <section class="page-heading">

        <div>

            <div class="eyebrow">
                DIANE CLOTHING SHOP / ANALYTICS
            </div>

            <h1>Reports & Analytics</h1>

            <p>
                Monitor your store's order activity and monthly performance.
            </p>

        </div>

        <div class="report-date">
            REPORT DATE<br>
            <?= date('F d, Y') ?>
        </div>

    </section>

    <?php if ($error !== ''): ?>

        <div class="notification">
            <?= htmlspecialchars($error) ?>
        </div>

    <?php else: ?>

        <!-- Main summaries -->

        <section class="summary-grid">

            <div class="summary-card">

                <div class="summary-label">
                    TOTAL ORDERS
                </div>

                <div class="summary-value">
                    <?= number_format($totalOrders) ?>
                </div>

                <div class="summary-description">
                    All recorded customer orders
                </div>

            </div>

            <div class="summary-card">

                <div class="summary-label">
                    TOTAL ORDER VALUE
                </div>

                <div class="summary-value">
                    ₱<?= number_format($totalOrderValue, 2) ?>
                </div>

                <div class="summary-description">
                    Combined value of recorded orders
                </div>

            </div>

            <div class="summary-card">

                <div class="summary-label">
                    THIS MONTH'S ORDERS
                </div>

                <div class="summary-value">
                    <?= number_format($currentMonthOrders) ?>
                </div>

                <div class="summary-description">
                    <?= date('F Y') ?>
                </div>

            </div>

        </section>

        <!-- Order status summary -->

        <h2 class="section-title">
            Order Status Summary
        </h2>

        <section class="status-grid">

            <div class="status-card">
                <span>Processing</span>
                <strong><?= $statusCounts['Processing'] ?></strong>
            </div>

            <div class="status-card">
                <span>Shipped</span>
                <strong><?= $statusCounts['Shipped'] ?></strong>
            </div>

            <div class="status-card">
                <span>Delivered</span>
                <strong><?= $statusCounts['Delivered'] ?></strong>
            </div>

            <div class="status-card">
                <span>Cancelled</span>
                <strong><?= $statusCounts['Cancelled'] ?></strong>
            </div>

        </section>

        <!-- Current month order value -->

        <section class="report-panel">

            <h2>Current Month Overview</h2>

            <p class="panel-description">
                Order activity recorded during <?= date('F Y') ?>.
            </p>

            <div class="bar-row">

                <div class="bar-label">

                    <strong>Current Month Order Value</strong>

                    <span>
                        ₱<?= number_format($currentMonthValue, 2) ?>
                    </span>

                </div>

                <div class="bar-background">

                    <div
                        class="bar-fill"
                        style="width: <?= $currentMonthValue > 0 ? '100' : '0' ?>%;"
                    ></div>

                </div>

                <span class="bar-caption">
                    <?= number_format($currentMonthOrders) ?> orders recorded this month.
                </span>

            </div>

        </section>

        <!-- Monthly order value -->

        <section class="report-panel">

            <h2>Monthly Order Value</h2>

            <p class="panel-description">
                Comparison of recorded order values by month.
            </p>

            <?php if (empty($monthlyOrders)): ?>

                <div class="empty">
                    No monthly order information available yet.
                </div>

            <?php else: ?>

                <?php foreach ($monthlyOrders as $month): ?>

                    <?php
                    $percentage = $maxMonthlyValue > 0
                        ? ($month['value'] / $maxMonthlyValue) * 100
                        : 0;
                    ?>

                    <div class="bar-row">

                        <div class="bar-label">

                            <strong>
                                <?= htmlspecialchars($month['month']) ?>
                            </strong>

                            <span>
                                ₱<?= number_format($month['value'], 2) ?>
                            </span>

                        </div>

                        <div class="bar-background">

                            <div
                                class="bar-fill"
                                style="width: <?= min(100, max(0, $percentage)) ?>%;"
                            ></div>

                        </div>

                        <span class="bar-caption">
                            <?= number_format($month['orders']) ?> orders
                        </span>

                    </div>

                <?php endforeach; ?>

            <?php endif; ?>

        </section>

        <!-- Monthly report table -->

        <section class="table-panel">

            <div class="table-heading">
                <h2>Monthly Report Records</h2>
            </div>

            <?php if (empty($monthlyOrders)): ?>

                <div class="empty">
                    No monthly records available yet.
                </div>

            <?php else: ?>

                <div class="table-scroll">

                    <table>

                        <thead>

                            <tr>
                                <th>Month</th>
                                <th>Number of Orders</th>
                                <th>Total Order Value</th>
                            </tr>

                        </thead>

                        <tbody>

                            <?php foreach ($monthlyOrders as $month): ?>

                                <tr>

                                    <td>
                                        <?= htmlspecialchars($month['month']) ?>
                                    </td>

                                    <td>
                                        <?= number_format($month['orders']) ?>
                                    </td>

                                    <td class="money">
                                        ₱<?= number_format($month['value'], 2) ?>
                                    </td>

                                </tr>

                            <?php endforeach; ?>

                        </tbody>

                    </table>

                </div>

            <?php endif; ?>

        </section>

        <div class="report-note">
            <strong>Report information:</strong>
            Figures are based on the latest 1,000 orders retrieved from Supabase.
            Total order value includes all order statuses and should not be
            interpreted as confirmed paid revenue.
        </div>

    <?php endif; ?>

</main>

</body>
</html>
