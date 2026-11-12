<?php

date_default_timezone_set('Asia/Manila');

session_start();



require_once __DIR__ . '/../includes/supabase.php';

require_once __DIR__ . '/../includes/admin_navigation.php';



// Check admin session

if (

    empty($_SESSION['admin_id']) ||

    empty($_SESSION['access_token'])

) {

    header('Location: login.php');

    exit;

}



// Admin access was verified during login. Keep the active session here.

$adminName = $_SESSION['admin_name'] ?? 'Admin';



// Dashboard navigation cards

$sections = [

    [

        'number' => '01',

        'title' => 'Products',

        'description' => 'Add, update, and organize your clothing collection.',

        'link' => 'products.php',

        'action' => 'MANAGE PRODUCTS'

    ],

    [

        'number' => '02',

        'title' => 'Orders',

        'description' => 'Review customer purchases and update order status.',

        'link' => 'orders.php',

        'action' => 'MANAGE ORDERS'

    ],

    [

        'number' => '03',

        'title' => 'Customers',

        'description' => 'View customer profiles and registration information.',

        'link' => 'customers.php',

        'action' => 'VIEW CUSTOMERS'

    ],

    [

        'number' => '04',

        'title' => 'Users',

        'description' => 'View registered accounts and their assigned roles.',

        'link' => 'users.php',

        'action' => 'MANAGE USERS'

    ],

    [

        'number' => '05',

        'title' => 'Reports',

        'description' => 'Review order summaries and monthly performance.',

        'link' => 'reports.php',

        'action' => 'VIEW REPORTS'

    ]

];

?>



<!DOCTYPE html>

<html lang="en">



<head>

    <meta charset="UTF-8">



    <meta

        name="viewport"

        content="width=device-width, initial-scale=1.0"

    >



    <title>Admin Dashboard | Diane Clothing Shop</title>



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



        a {

            color: inherit;

        }



        .dashboard-container {

            width: 100%;

            max-width: 1450px;

            margin: 0 auto;

            padding: 38px 42px 55px;

        }



        .dashboard-heading {

            display: flex;

            justify-content: space-between;

            align-items: flex-end;

            gap: 20px;

            margin-bottom: 25px;

        }



        .eyebrow {

            color: #777;

            font-size: 10px;

            font-weight: bold;

            letter-spacing: 2.5px;

            text-transform: uppercase;

            margin-bottom: 12px;

        }



        .dashboard-heading h1 {

            margin: 0;

            font-size: clamp(28px, 4vw, 38px);

            font-weight: 500;

            letter-spacing: -1.3px;

        }



        .dashboard-date {

            color: #777;

            font-size: 12px;

            text-align: right;

        }



        /* Welcome area */



        .welcome-panel {

            min-height: 245px;

            background: #fff;

            border: 1px solid #e6e6e6;

            display: grid;

            grid-template-columns: 1fr 280px;

            align-items: stretch;

            margin-bottom: 48px;

        }



        .welcome-content {

            padding: 38px 42px;

            display: flex;

            flex-direction: column;

            justify-content: center;

        }



        .welcome-label {

            color: #777;

            font-size: 10px;

            letter-spacing: 2.5px;

            margin-bottom: 18px;

        }



        .welcome-content h2 {

            margin: 0 0 13px;

            font-size: clamp(27px, 3vw, 36px);

            font-weight: 500;

            letter-spacing: -1px;

        }



        .welcome-content p {

            margin: 0;

            color: #777;

            font-size: 14px;

            line-height: 1.8;

        }



        .welcome-decoration {

            background: #111;

            color: white;

            padding: 30px;

            display: flex;

            flex-direction: column;

            justify-content: space-between;

            align-items: flex-start;

        }



        .welcome-decoration span {

            color: #aaa;

            font-size: 10px;

            letter-spacing: 2px;

        }



        .welcome-decoration strong {

            font-size: 37px;

            font-weight: normal;

            letter-spacing: 4px;

        }



        .welcome-decoration small {

            color: #aaa;

            font-size: 10px;

            letter-spacing: 1.5px;

        }



        /* Section heading */



        .section-heading {

            display: flex;

            justify-content: space-between;

            align-items: center;

            margin-bottom: 20px;

        }



        .section-heading h2 {

            margin: 0;

            font-size: 19px;

            font-weight: 500;

        }



        .section-heading span {

            color: #888;

            font-size: 11px;

            letter-spacing: 1px;

        }



        /* Management cards */



        .management-grid {

            display: grid;

            grid-template-columns: repeat(3, minmax(0, 1fr));

            gap: 18px;

        }



        .management-card {

            min-height: 205px;

            padding: 24px;

            background: #fff;

            border: 1px solid #e5e5e5;

            text-decoration: none;

            display: flex;

            flex-direction: column;

            justify-content: space-between;

            transition: transform .2s, background .2s, border-color .2s;

        }



        .management-card:hover {

            transform: translateY(-4px);

            border-color: #999;

            background: #fff;

        }



        .card-top {

            display: flex;

            justify-content: space-between;

            align-items: center;

        }



        .card-number {

            color: #999;

            font-size: 11px;

            letter-spacing: 1px;

        }



        .card-arrow {

            width: 33px;

            height: 33px;

            border: 1px solid #ddd;

            display: flex;

            justify-content: center;

            align-items: center;

            font-size: 16px;

            transition: background .2s, color .2s;

        }



        .management-card:hover .card-arrow {

            background: #111;

            color: white;

            border-color: #111;

        }



        .card-content {

            margin-top: 25px;

        }



        .card-content h3 {

            font-size: 20px;

            font-weight: 500;

            margin: 0 0 10px;

        }



        .card-content p {

            color: #777;

            font-size: 12px;

            line-height: 1.7;

            margin: 0;

            max-width: 290px;

        }



        .card-action {

            border-top: 1px solid #eee;

            margin-top: 25px;

            padding-top: 16px;

            font-size: 10px;

            letter-spacing: 1.5px;

            font-weight: bold;

        }



        /* Footer */



        .dashboard-footer {

            display: flex;

            justify-content: space-between;

            gap: 15px;

            margin-top: 45px;

            padding-top: 20px;

            border-top: 1px solid #ddd;

            color: #888;

            font-size: 11px;

        }



        /* Responsive */



        @media (max-width: 950px) {

            .management-grid {

                grid-template-columns: repeat(2, minmax(0, 1fr));

            }



            .welcome-panel {

                grid-template-columns: 1fr 220px;

            }



            .dashboard-container {

                padding: 30px 25px 45px;

            }

        }



        @media (max-width: 600px) {

            .dashboard-container {

                padding: 25px 16px 40px;

            }



            .dashboard-heading {

                align-items: flex-start;

                flex-direction: column;

            }



            .dashboard-date {

                text-align: left;

            }



            .welcome-panel {

                grid-template-columns: 1fr;

            }



            .welcome-content {

                padding: 28px 24px;

            }



            .welcome-decoration {

                min-height: 140px;

                padding: 22px;

            }



            .welcome-decoration strong {

                font-size: 28px;

            }



            .management-grid {

                grid-template-columns: 1fr;

                gap: 13px;

            }



            .management-card {

                min-height: 180px;

            }



            .section-heading {

                align-items: flex-start;

                flex-direction: column;

                gap: 8px;

            }



            .dashboard-footer {

                flex-direction: column;

            }

        }

    </style>

</head>



<body>



<?php renderAdminNavigation(); ?>



<main class="dashboard-container">



    <!-- Page heading -->



    <section class="dashboard-heading">



        <div>

            <div class="eyebrow">

                DIANE CLOTHING SHOP / ADMINISTRATION

            </div>



            <h1>Dashboard</h1>

        </div>



        <div class="dashboard-date">

            <?= date('l, F j, Y') ?>

        </div>



    </section>



    <!-- Welcome panel -->



    <section class="welcome-panel">



        <div class="welcome-content">



            <div class="welcome-label">

                ADMINISTRATOR ACCOUNT

            </div>



            <h2>

                Welcome back,

                <?= htmlspecialchars($adminName, ENT_QUOTES, 'UTF-8') ?>.

            </h2>



            <p>

                Manage your store, products, customer orders, and

                account information from one place.

            </p>



        </div>



        <div class="welcome-decoration">



            <span>YOUR STORE</span>



            <strong>DIANE</strong>



            <small>MODERN CLOTHING COLLECTION</small>



        </div>



    </section>



    <!-- Management sections -->



    <section>



        <div class="section-heading">



            <h2>Management</h2>



            <span>SELECT A SECTION TO CONTINUE</span>



        </div>



        <div class="management-grid">



            <?php foreach ($sections as $section): ?>



                <a

                    href="<?= htmlspecialchars($section['link']) ?>"

                    class="management-card"

                >



                    <div class="card-top">



                        <span class="card-number">

                            <?= htmlspecialchars($section['number']) ?>

                        </span>



                        <span class="card-arrow">

                            ↗

                        </span>



                    </div>



                    <div class="card-content">



                        <h3>

                            <?= htmlspecialchars($section['title']) ?>

                        </h3>



                        <p>

                            <?= htmlspecialchars($section['description']) ?>

                        </p>



                    </div>



                    <div class="card-action">

                        <?= htmlspecialchars($section['action']) ?>

                    </div>



                </a>



            <?php endforeach; ?>



        </div>



    </section>



    <!-- Footer -->



    <footer class="dashboard-footer">



        <span>DIANE CLOTHING SHOP</span>



        <span>ADMIN MANAGEMENT SYSTEM</span>



    </footer>



</main>



</body>

</html>
