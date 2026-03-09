<?php
session_start();
include 'db.php';

// 1. SECURITY & DATA FETCH
if (!isset($_SESSION['user'])) { header("Location: login.php"); exit(); }
$username = $_SESSION['user'];
$user_q = $conn->query("SELECT * FROM users WHERE username='$username'");
$user = $user_q->fetch_assoc();

if ($user['role'] !== 'Landlord') { header("Location: login.php"); exit(); }
$landlord_id = $user['id'];
$pp = !empty($user['profile_pic']) ? "assets/uploads/" . $user['profile_pic'] : "assets/default.jpg";

// 2. CHECK IF LANDLORD ALREADY HAS A BOARDING HOUSE
$house_q = $conn->query("SELECT * FROM properties WHERE landlord_id = $landlord_id LIMIT 1");
$my_house = $house_q->fetch_assoc();
$has_house = ($my_house) ? true : false;

// 3. GET PENDING REQUESTS (Tenant Applications)
$req_query = "SELECT applications.id as app_id, users.first_name, users.last_name, users.phone, users.profile_pic,
                     room_units.room_name, applications.created_at 
              FROM applications 
              JOIN users ON applications.tenant_id = users.id 
              JOIN room_units ON applications.room_id = room_units.id 
              WHERE applications.property_id = " . ($has_house ? $my_house['id'] : 0) . " 
              AND applications.status = 'Pending'";
$requests = $conn->query($req_query);
$total_pending_apps = $requests->num_rows;

// 4. NEW: GET PAYMENTS (Pending & History)
$pending_payments = [];
$history_payments = [];
$total_pending_pay = 0;

if ($has_house) {
    $pay_sql = "
        SELECT 
            pay.id as pay_id, 
            pay.amount, 
            pay.payment_date, 
            pay.status, 
            u.first_name, 
            u.last_name, 
            u.profile_pic,
            r.room_name
        FROM payments pay
        JOIN users u ON pay.tenant_id = u.id
        JOIN applications app ON (app.tenant_id = u.id AND app.status = 'Approved')
        JOIN room_units r ON app.room_id = r.id
        WHERE app.property_id = " . $my_house['id'] . "
        ORDER BY pay.payment_date DESC
    ";
    
    $pay_res = $conn->query($pay_sql);
    if ($pay_res) {
        while($row = $pay_res->fetch_assoc()) {
            if ($row['status'] == 'Pending') {
                $pending_payments[] = $row;
            } else {
                $history_payments[] = $row;
            }
        }
    }
    $total_pending_pay = count($pending_payments);
}

// 5. GET TOTAL TENANTS COUNT
$total_tenants = 0;
if ($has_house) {
    $Approved_q = $conn->query("SELECT COUNT(*) as count FROM applications WHERE property_id = " . $my_house['id'] . " AND status = 'Approved'");
    $Approved_data = $Approved_q->fetch_assoc();
    $total_tenants = $Approved_data['count'];
}

// 6. TIME & GREETING LOGIC
date_default_timezone_set('Asia/Manila'); 
$hour = date('H');
if ($hour < 12) { $greeting = "Good Morning"; }
elseif ($hour < 18) { $greeting = "Good Afternoon"; }
else { $greeting = "Good Evening"; }
$today_date = date("l, F j, Y");
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <title>Landlord Dashboard</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        :root {
            --bg-dark: #121212;
            --bg-card: #1e1e1e;
            --bg-sidebar: #0a0a0a;
            --text-primary: #ffffff;
            --text-secondary: #a0a0a0;
            --accent-orange: #ff9000;
            --accent-orange-hover: #e08000;
            --danger: #dc3545;
            --success: #198754;
        }
        body { background: var(--bg-dark); color: var(--text-primary); font-family: 'Inter', sans-serif; overflow-x: hidden; }
        h1, h2, h3, h4, h5, h6 { font-weight: 700; letter-spacing: -0.5px; }
        .text-orange { color: var(--accent-orange) !important; }
        .text-muted { color: var(--text-secondary) !important; }

        /* SIDEBAR */
        .sidebar { width: 280px; height: 100vh; background: var(--bg-sidebar); position: fixed; top: 0; left: 0; padding: 30px; display: flex; flex-direction: column; border-right: 1px solid #222; z-index: 1000; }
        .sidebar-brand { font-size: 24px; font-weight: 800; margin-bottom: 50px; display: flex; align-items: center; }
        .sidebar-menu { flex-grow: 1; }
        .nav-item { list-style: none; margin-bottom: 10px; }
        .nav-link { color: var(--text-secondary); padding: 14px 20px; border-radius: 12px; font-weight: 600; text-decoration: none; display: flex; align-items: center; transition: all 0.3s ease; cursor: pointer; }
        .nav-link i { font-size: 20px; margin-right: 15px; }
        .nav-link:hover, .nav-link.active { background: rgba(255, 144, 0, 0.15); color: var(--accent-orange); }

        /* MAIN CONTENT */
        .main-content { margin-left: 280px; padding: 30px 50px; }
        
        /* TOP HEADER */
        .top-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 40px; padding-bottom: 20px; border-bottom: 1px solid #222; }
        .profile-widget { display: flex; align-items: center; gap: 15px; }
        
        .role-badge {
            background: rgba(255, 144, 0, 0.1);
            color: var(--accent-orange);
            font-size: 11px;
            font-weight: 700;
            padding: 6px 12px;
            border-radius: 20px;
            border: 1px solid var(--accent-orange);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .profile-img-top { 
            width: 50px; height: 50px; 
            border-radius: 50%; 
            object-fit: cover; 
            border: 2px solid var(--accent-orange); 
            padding: 2px;
            background: var(--bg-card);
        }
        
        .logout-btn { 
            width: 45px; height: 45px; 
            border-radius: 12px; 
            background: rgba(30, 30, 30, 1); 
            border: 1px solid #333;
            color: var(--danger); 
            display: flex; align-items: center; justify-content: center; 
            text-decoration: none; 
            transition: all 0.2s ease;
            font-size: 18px;
            cursor: pointer;
        }
        .logout-btn:hover { 
            background: var(--danger); 
            color: white; 
            border-color: var(--danger); 
            transform: translateY(-2px);
            box-shadow: 0 4px 10px rgba(220, 53, 69, 0.3);
        }

        /* LOGOUT MODAL STYLING */
        .modal-content { background: var(--bg-card); border: 1px solid #333; border-radius: 16px; color: white; }
        .modal-header { border-bottom: 1px solid #333; }
        .modal-footer { border-top: 1px solid #333; }
        .btn-modal-cancel { color: #aaa; font-weight: 600; }
        .btn-modal-cancel:hover { color: white; }
        .btn-modal-logout { background: var(--danger); border: none; font-weight: 600; padding: 8px 20px; border-radius: 8px; }
        .btn-modal-logout:hover { background: #b02a37; }

        /* CARDS */
        .stat-card { background: var(--bg-card); border-radius: 16px; padding: 25px; display: flex; align-items: center; text-decoration: none; color: var(--text-primary); transition: transform 0.3s ease, box-shadow 0.3s ease; border: 1px solid transparent; }
        .stat-card:hover { transform: translateY(-5px); box-shadow: 0 10px 20px rgba(0,0,0,0.2); border-color: var(--accent-orange); }
        .stat-icon { width: 60px; height: 60px; border-radius: 12px; background: rgba(255, 144, 0, 0.1); display: flex; align-items: center; justify-content: center; margin-right: 20px; }
        .stat-icon i { font-size: 28px; color: var(--accent-orange); }
        .stat-label { font-size: 14px; font-weight: 600; color: var(--text-secondary); display: block; margin-bottom: 5px; }
        .stat-value { font-size: 32px; font-weight: 800; line-height: 1; }

        /* HOUSE CARD */
        .house-card { background: var(--bg-card); border-radius: 20px; overflow: hidden; border: 1px solid #333; box-shadow: 0 4px 15px rgba(0,0,0,0.1); }
        .house-hero { height: 220px; position: relative; }
        .house-img { width: 100%; height: 100%; object-fit: cover; }
        .house-overlay { position: absolute; top: 0; left: 0; width: 100%; height: 100%; background: linear-gradient(to top, var(--bg-card) 10%, transparent); }
        .house-body { padding: 30px; position: relative; }
        .house-badge { background: var(--accent-orange); color: #000; padding: 6px 14px; border-radius: 20px; font-weight: 700; font-size: 12px; position: absolute; top: -15px; left: 30px; }
        .btn-manage { background: var(--accent-orange); color: #000; padding: 12px 30px; border-radius: 12px; font-weight: 700; text-decoration: none; display: inline-flex; align-items: center; transition: background 0.3s ease; }
        .btn-manage:hover { background: var(--accent-orange-hover); }

        /* LIST ITEMS (Reused for both Requests and Payments) */
        .req-section { margin-top: 50px; scroll-margin-top: 40px; }
        .req-list { display: flex; flex-direction: column; gap: 15px; }
        .req-item { background: var(--bg-card); border-radius: 16px; padding: 20px; display: flex; align-items: center; justify-content: space-between; border: 1px solid #333; transition: 0.2s; }
        .req-item:hover { border-color: #555; }
        .tenant-info { display: flex; align-items: center; }
        .tenant-img { width: 50px; height: 50px; border-radius: 50%; object-fit: cover; margin-right: 15px; border: 2px solid #333; }
        
        .action-btn { min-width: 65px; padding: 6px 0; border-radius: 10px; display: flex; flex-direction: column; align-items: center; justify-content: center; text-decoration: none; transition: 0.2s; line-height: 1; }
        .action-btn i { font-size: 16px; margin-bottom: 4px; }
        .action-btn span { font-size: 10px; font-weight: 700; text-transform: uppercase; }
        .btn-accept { background: rgba(25, 135, 84, 0.15); color: var(--success); }
        .btn-accept:hover { background: var(--success); color: white; }
        .btn-reject { background: rgba(220, 53, 69, 0.15); color: var(--danger); margin-left: 10px; }
        .btn-reject:hover { background: var(--danger); color: white; }
        
        /* TABLE STYLES FOR HISTORY */
        .history-table { width: 100%; border-collapse: separate; border-spacing: 0 10px; }
        .history-table th { text-align: left; color: var(--text-secondary); font-size: 12px; text-transform: uppercase; font-weight: 600; padding: 0 20px; }
        .history-table td { background: var(--bg-card); padding: 20px; font-size: 14px; vertical-align: middle; color: #fff; border-top: 1px solid #333; border-bottom: 1px solid #333; }
        .history-table tr td:first-child { border-left: 1px solid #333; border-top-left-radius: 12px; border-bottom-left-radius: 12px; }
        .history-table tr td:last-child { border-right: 1px solid #333; border-top-right-radius: 12px; border-bottom-right-radius: 12px; }
        
        .empty-state { background: var(--bg-card); border-radius: 20px; padding: 50px; text-align: center; border: 2px dashed #333; }
        .empty-icon { font-size: 60px; color: #333; margin-bottom: 20px; }
    </style>
</head>
<body>

<div class="sidebar">
    <div class="sidebar-brand">
        Board<span class="text-orange">Hub</span>
    </div>
    <ul class="sidebar-menu p-0">
        <li class="nav-item">
            <a href="dashboard_landlord.php" class="nav-link active">
                <i class="bi bi-grid-fill"></i> Dashboard
            </a>
        </li>
        <li class="nav-item">
            <?php if(!$has_house): ?>
                <a href="post_room.php" class="nav-link"><i class="bi bi-plus-circle-fill"></i> Post Property</a>
            <?php else: ?>
                <a href="edit_room.php?id=<?php echo $my_house['id']; ?>" class="nav-link"><i class="bi bi-house-gear-fill"></i> Manage House</a>
            <?php endif; ?>
        </li>
        <li class="nav-item">
            <a href="manage_rooms.php" class="nav-link">
                <i class="bi bi-door-open-fill"></i> Manage Rooms
            </a>
        </li>
        <li class="nav-item">
            <a href="profile_setup.php" class="nav-link">
                <i class="bi bi-person-circle"></i> Profile
            </a>
        </li>
    </ul>
</div>

<div class="main-content">
    
    <div class="top-header">
        <div>
            <small class="text-muted fw-bold text-uppercase" style="font-size: 11px; letter-spacing: 1px;"><?php echo $today_date; ?></small>
            <h2 class="m-0 mt-1"><?php echo $greeting; ?>, <?php echo htmlspecialchars($user['first_name']); ?></h2>
        </div>
        
        <div class="profile-widget">
            <span class="role-badge">Landlord</span>
            <img src="<?php echo $pp; ?>" class="profile-img-top">
            
            <button class="logout-btn" data-bs-toggle="modal" data-bs-target="#logoutModal">
                <i class="bi bi-box-arrow-right"></i>
            </button>
        </div>
    </div>

    <?php if(isset($_GET['msg'])): ?><div class="alert alert-success rounded-4 border-0 mb-4"><?php echo htmlspecialchars($_GET['msg']); ?></div><?php endif; ?>
    <?php if(isset($_GET['error'])): ?><div class="alert alert-danger rounded-4 border-0 mb-4"><?php echo htmlspecialchars($_GET['error']); ?></div><?php endif; ?>

    <div class="row g-4 mb-5">
        <div class="col-md-4">
            <a href="#payment-section" class="stat-card">
                <div class="stat-icon"><i class="bi bi-cash-stack"></i></div>
                <div>
                    <span class="stat-label">Pending Payments</span>
                    <span class="stat-value text-orange"><?php echo $total_pending_pay; ?></span>
                </div>
            </a>
        </div>
        <div class="col-md-4">
            <a href="#requests-section" class="stat-card">
                <div class="stat-icon"><i class="bi bi-hourglass-split"></i></div>
                <div>
                    <span class="stat-label">Pending Apps</span>
                    <span class="stat-value text-white"><?php echo $total_pending_apps; ?></span>
                </div>
            </a>
        </div>
        <div class="col-md-4">
            <div class="stat-card" style="cursor: default; transform: none; box-shadow: none; border-color: transparent;">
                <div class="stat-icon" style="background: rgba(255,255,255,0.05);"><i class="bi bi-people-fill" style="color: white;"></i></div>
                <div>
                    <span class="stat-label">Total Tenants</span>
                    <span class="stat-value"><?php echo $total_tenants; ?></span>
                </div>
            </div>
        </div>
    </div>

    <div id="payment-section" class="req-section">
        <h3 class="mb-4">Payment Verification</h3>
        <?php if (count($pending_payments) > 0): ?>
            <div class="req-list">
                <?php foreach($pending_payments as $pay): 
                    $t_pic = !empty($pay['profile_pic']) ? "assets/uploads/" . $pay['profile_pic'] : "assets/default.jpg";
                ?>
                <div class="req-item" style="border-left: 4px solid var(--accent-orange);">
                    <div class="tenant-info col-md-4">
                        <img src="<?php echo $t_pic; ?>" class="tenant-img">
                        <div>
                            <h6 class="mb-1"><?php echo htmlspecialchars($pay['first_name'] . " " . $pay['last_name']); ?></h6>
                            <small class="text-orange fw-bold">Room: <?php echo htmlspecialchars($pay['room_name']); ?></small>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <small class="text-muted d-block mb-1">Amount Paid</small>
                        <span class="text-white fw-bold fs-5">₱<?php echo number_format($pay['amount']); ?></span>
                    </div>
                    <div class="col-md-2 text-end">
                        <small class="text-muted"><?php echo date("M d, Y", strtotime($pay['payment_date'])); ?></small>
                    </div>
                    <div class="col-md-2 d-flex justify-content-end">
                        <a href="verify_payment.php?id=<?php echo $pay['pay_id']; ?>&action=confirm" class="action-btn btn-accept" title="Confirm Payment" onclick="return confirm('Confirm this payment?');"><i class="bi bi-check-lg"></i><span>Confirm</span></a>
                        <a href="verify_payment.php?id=<?php echo $pay['pay_id']; ?>&action=reject" class="action-btn btn-reject" title="Reject Payment" onclick="return confirm('Reject this payment?');"><i class="bi bi-x-lg"></i><span>Reject</span></a>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="empty-state py-4 mb-4">
                <i class="bi bi-wallet2 empty-icon" style="font-size: 40px;"></i>
                <h6 class="text-muted m-0">No pending payments to verify.</h6>
            </div>
        <?php endif; ?>
    </div>

    <div id="requests-section" class="req-section mt-5">
        <h3 class="mb-4">Tenant Applications</h3>
        
        <?php if ($total_pending_apps > 0): ?>
            <div class="req-list">
                <?php while($req = $requests->fetch_assoc()): 
                    $t_pic = !empty($req['profile_pic']) ? "assets/uploads/" . $req['profile_pic'] : "assets/default.jpg";
                ?>
                <div class="req-item">
                    <div class="tenant-info col-md-4">
                        <img src="<?php echo $t_pic; ?>" class="tenant-img">
                        <div>
                            <h6 class="mb-1"><?php echo $req['first_name'] . " " . $req['last_name']; ?></h6>
                            <small class="text-muted"><i class="bi bi-telephone me-1"></i><?php echo $req['phone']; ?></small>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <small class="text-muted d-block mb-1">Applying for</small>
                        <span class="text-white fw-bold"><i class="bi bi-door-closed me-1"></i><?php echo $req['room_name']; ?></span>
                    </div>
                    <div class="col-md-2 text-end">
                        <small class="text-muted"><?php echo date("M d, Y", strtotime($req['created_at'])); ?></small>
                    </div>
                    <div class="col-md-2 d-flex justify-content-end">
                        <a href="handle_application.php?id=<?php echo $req['app_id']; ?>&action=approve" class="action-btn btn-accept" title="Approve"><i class="bi bi-check-lg"></i><span>Accept</span></a>
                        <a href="handle_application.php?id=<?php echo $req['app_id']; ?>&action=reject" class="action-btn btn-reject" title="Reject" onclick="return confirm('Reject this application?')"><i class="bi bi-x-lg"></i><span>Reject</span></a>
                    </div>
                </div>
                <?php endwhile; ?>
            </div>
        <?php else: ?>
            <div class="empty-state py-4">
                <i class="bi bi-inbox empty-icon" style="font-size: 40px;"></i>
                <h6 class="text-muted m-0">No new tenant applications.</h6>
            </div>
        <?php endif; ?>
    </div>

    <div class="mt-5">
        <h3 class="mb-4">Payment History</h3>
        <?php if (count($history_payments) > 0): ?>
            <table class="history-table">
                <thead>
                    <tr>
                        <th>Tenant</th>
                        <th>Room</th>
                        <th>Date Paid</th>
                        <th>Amount</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($history_payments as $hist): ?>
                    <tr>
                        <td class="fw-bold"><?php echo htmlspecialchars($hist['first_name'] . ' ' . $hist['last_name']); ?></td>
                        <td class="text-muted"><?php echo htmlspecialchars($hist['room_name']); ?></td>
                        <td class="text-muted"><?php echo date("M d, Y", strtotime($hist['payment_date'])); ?></td>
                        <td class="text-success fw-bold">₱<?php echo number_format($hist['amount']); ?></td>
                        <td>
                            <?php if($hist['status'] == 'Confirmed'): ?>
                                <span class="badge bg-success bg-opacity-25 text-success">Confirmed</span>
                            <?php else: ?>
                                <span class="badge bg-danger bg-opacity-25 text-danger">Rejected</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <p class="text-muted fst-italic">No payment history available.</p>
        <?php endif; ?>
    </div>

    <h3 class="mb-4 mt-5">My Property</h3>
    <?php if ($has_house): ?>
        <?php 
            $images = json_decode($my_house['images'], true);
            $thumb = !empty($images) ? "assets/uploads/rooms/" . $images[0] : "assets/default_room.jpg";
            $r_count = $conn->query("SELECT COUNT(*) as c FROM room_units WHERE property_id = " . $my_house['id'])->fetch_assoc()['c'];
            
            // NEW: Status Logic
            $p_stat = !empty($my_house['status']) ? $my_house['status'] : 'Accepting';
            $bg_color = '#198754'; // default green
            $text_color = '#ffffff';
            
            if ($p_stat == 'Full') { $bg_color = '#dc3545'; }
            elseif ($p_stat == 'Renovating') { $bg_color = '#ffc107'; $text_color = '#000000'; }
            elseif ($p_stat == 'Closed') { $bg_color = '#6c757d'; }
        ?>
        <div class="house-card">
            <div class="house-hero">
                <img src="<?php echo $thumb; ?>" class="house-img">
                <div class="house-overlay"></div>
            </div>
            <div class="house-body d-flex justify-content-between align-items-end">
                <span class="house-badge"><?php echo $r_count; ?> Rooms</span>
                
                <span style="background-color: <?php echo $bg_color; ?>; color: <?php echo $text_color; ?>; padding: 6px 14px; border-radius: 20px; font-weight: 700; font-size: 12px; position: absolute; top: -15px; left: 110px;">
                    <?php echo htmlspecialchars($p_stat); ?>
                </span>

                <div>
                    <h2 class="mb-2"><?php echo htmlspecialchars($my_house['title']); ?></h2>
                    <p class="text-muted mb-0"><i class="bi bi-geo-alt-fill me-2"></i><?php echo htmlspecialchars($my_house['location']); ?></p>
                </div>
                <a href="edit_room.php?id=<?php echo $my_house['id']; ?>" class="btn-manage">
                    <i class="bi bi-pencil-square me-2"></i> Manage Property
                </a>
            </div>
        </div>
    <?php else: ?>
        <div class="empty-state">
            <i class="bi bi-house-add empty-icon"></i>
            <h3>You haven't posted a property yet.</h3>
            <p class="text-secondary mb-4">List your boarding house and rooms to start accepting tenants.</p>
            <a href="post_room.php" class="btn-manage px-4 py-3 fs-5">+ Post Your Property Now</a>
        </div>
    <?php endif; ?>

</div>

<div class="modal fade" id="logoutModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title fw-bold">Sign Out?</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                Are you sure you want to end your session?
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-modal-cancel" data-bs-dismiss="modal">Cancel</button>
                <a href="logout.php" class="btn btn-modal-logout">Log Out</a>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

</body>
</html>
