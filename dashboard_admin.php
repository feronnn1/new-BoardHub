<?php
session_start();
include 'db.php';

// 1. SECURITY
if (!isset($_SESSION['user']) || $_SESSION['role'] !== 'Admin') { header("Location: login.php"); exit(); }

$user_id = $_SESSION['user_id'];
$user = $conn->query("SELECT * FROM users WHERE id=$user_id")->fetch_assoc();
$pp = !empty($user['profile_pic']) ? "assets/uploads/" . $user['profile_pic'] : "assets/default.jpg";

// 2. FETCH STATS
$total_landlords = $conn->query("SELECT COUNT(*) as c FROM users WHERE role='Landlord'")->fetch_assoc()['c'];
$total_tenants = $conn->query("SELECT COUNT(*) as c FROM users WHERE role='Tenant'")->fetch_assoc()['c'];
$total_properties = $conn->query("SELECT COUNT(*) as c FROM properties")->fetch_assoc()['c'];
$total_rooms = $conn->query("SELECT COUNT(*) as c FROM room_units")->fetch_assoc()['c'];

// 3. RECENT ACTIVITY
$recent_users = $conn->query("SELECT * FROM users ORDER BY created_at DESC LIMIT 5");
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <title>Admin Dashboard</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        :root { --bg-dark: #0f0f0f; --bg-card: #1a1a1a; --accent-orange: #ff9000; }
        body { background: var(--bg-dark); color: white; font-family: 'Plus Jakarta Sans', sans-serif; overflow-x: hidden; }
        
        /* SIDEBAR */
        .sidebar { width: 260px; height: 100vh; background: #050505; position: fixed; top: 0; left: 0; border-right: 1px solid #222; padding: 20px; display: flex; flex-direction: column; }
        .logo { font-size: 24px; font-weight: 800; color: white; margin-bottom: 40px; display: flex; align-items: center; gap: 12px; text-decoration: none; padding-left: 10px; }
        .nav-label { font-size: 11px; text-transform: uppercase; color: #666; font-weight: 700; margin-bottom: 10px; padding-left: 15px; letter-spacing: 1px; }
        .nav-link { color: #888; padding: 12px 18px; border-radius: 12px; margin-bottom: 5px; display: flex; align-items: center; gap: 14px; font-weight: 500; transition: all 0.2s ease; text-decoration: none; }
        .nav-link:hover, .nav-link.active { background: rgba(255, 144, 0, 0.15); color: var(--accent-orange); }
        .nav-link.logout { color: #ff5252; margin-top: auto; }
        
        .main-content { margin-left: 260px; padding: 40px 50px; }
        
        /* STAT CARDS */
        .stat-card { background: var(--bg-card); border: 1px solid #333; border-radius: 16px; padding: 25px; display: flex; align-items: center; gap: 20px; transition: 0.2s; }
        .stat-card:hover { border-color: #555; transform: translateY(-3px); }
        .stat-icon { width: 60px; height: 60px; border-radius: 12px; background: rgba(255, 144, 0, 0.1); color: var(--accent-orange); display: flex; align-items: center; justify-content: center; font-size: 28px; }
        .stat-info h2 { margin: 0; font-weight: 800; }
        .stat-info span { color: #888; font-size: 13px; text-transform: uppercase; font-weight: 700; }

        /* TABLE */
        .table-card { background: var(--bg-card); border: 1px solid #333; border-radius: 16px; padding: 25px; }
        .custom-table { width: 100%; border-collapse: separate; border-spacing: 0 10px; }
        .custom-table th { text-align: left; color: #888; font-size: 12px; text-transform: uppercase; padding: 0 15px; }
        .custom-table td { background: #222; padding: 15px; color: #eee; font-size: 14px; vertical-align: middle; }
        .custom-table tr td:first-child { border-top-left-radius: 10px; border-bottom-left-radius: 10px; }
        .custom-table tr td:last-child { border-top-right-radius: 10px; border-bottom-right-radius: 10px; }
        
        .user-img { width: 35px; height: 35px; border-radius: 50%; object-fit: cover; margin-right: 10px; }
        .badge-role { font-size: 11px; padding: 4px 10px; border-radius: 20px; font-weight: 700; text-transform: uppercase; }
        
        /* Modal */
        .modal-content { background-color: #1a1a1a; border: 1px solid #333; color: white; }
        .modal-header, .modal-footer { border-color: #333; }
        .btn-close-white { filter: invert(1); }
    </style>
</head>
<body>

<div class="sidebar">
    <a href="#" class="logo"><i class="bi bi-shield-lock-fill text-primary-orange" style="color: var(--accent-orange);"></i> Admin</a>
    
    <div class="nav-label">Main</div>
    <a href="dashboard_admin.php" class="nav-link active"><i class="bi bi-speedometer2"></i> Overview</a>
    
    <div class="nav-label mt-4">Management</div>
    <a href="admin_users.php?role=Landlord" class="nav-link"><i class="bi bi-person-square"></i> Landlords</a>
    <a href="admin_users.php?role=Tenant" class="nav-link"><i class="bi bi-people"></i> Tenants</a>
    <a href="admin_properties.php" class="nav-link"><i class="bi bi-houses"></i> Properties</a>
    
    <a href="#" data-bs-toggle="modal" data-bs-target="#logoutModal" class="nav-link logout"><i class="bi bi-box-arrow-right"></i> Sign Out</a>
</div>

<div class="main-content">
    <div class="d-flex justify-content-between align-items-center mb-5">
        <div>
            <h2 class="fw-bold m-0">Dashboard Overview</h2>
            <p class="text-secondary m-0">System statistics and recent activity.</p>
        </div>
        <img src="<?php echo $pp; ?>" style="width: 45px; height: 45px; border-radius: 50%; border: 2px solid var(--accent-orange);">
    </div>

    <div class="row g-4 mb-5">
        <div class="col-md-3">
            <div class="stat-card">
                <div class="stat-icon"><i class="bi bi-person-square"></i></div>
                <div class="stat-info"><span>Landlords</span><h2><?php echo $total_landlords; ?></h2></div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card">
                <div class="stat-icon" style="color: #0dcaf0; background: rgba(13, 202, 240, 0.1);"><i class="bi bi-people"></i></div>
                <div class="stat-info"><span>Tenants</span><h2><?php echo $total_tenants; ?></h2></div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card">
                <div class="stat-icon" style="color: #198754; background: rgba(25, 135, 84, 0.1);"><i class="bi bi-houses"></i></div>
                <div class="stat-info"><span>Properties</span><h2><?php echo $total_properties; ?></h2></div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card">
                <div class="stat-icon" style="color: #dc3545; background: rgba(220, 53, 69, 0.1);"><i class="bi bi-door-open"></i></div>
                <div class="stat-info"><span>Total Rooms</span><h2><?php echo $total_rooms; ?></h2></div>
            </div>
        </div>
    </div>

    <div class="table-card">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h5 class="fw-bold m-0">Newest Users</h5>
            <a href="admin_users.php?role=All" class="btn btn-sm btn-outline-secondary border-0 text-white">View All</a>
        </div>
        <table class="custom-table">
            <thead><tr><th>User</th><th>Role</th><th>Joined</th></tr></thead>
            <tbody>
                <?php while($u = $recent_users->fetch_assoc()):
                    $u_pic = !empty($u['profile_pic']) ? "assets/uploads/".$u['profile_pic'] : "assets/default.jpg";
                    $badge = ($u['role'] == 'Landlord') ? 'background: rgba(13, 202, 240, 0.15); color: #0dcaf0;' : 'background: rgba(255, 193, 7, 0.15); color: #ffc107;';
                ?>
                <tr>
                    <td>
                        <div class="d-flex align-items-center">
                            <img src="<?php echo $u_pic; ?>" class="user-img">
                            <div>
                                <div class="fw-bold"><?php echo htmlspecialchars($u['first_name'] . ' ' . $u['last_name']); ?></div>
                                <div class="small text-secondary" style="font-size: 11px;"><?php echo htmlspecialchars($u['email']); ?></div>
                            </div>
                        </div>
                    </td>
                    <td><span class="badge-role" style="<?php echo $badge; ?>"><?php echo $u['role']; ?></span></td>
                    <td class="text-secondary"><?php echo date("M d, Y", strtotime($u['created_at'])); ?></td>
                </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
    </div>

</div>

<div class="modal fade" id="logoutModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header"><h5 class="modal-title fw-bold">Sign Out</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
      <div class="modal-body text-secondary">Are you sure you want to log out?</div>
      <div class="modal-footer"><button type="button" class="btn btn-outline-secondary text-light" data-bs-dismiss="modal">Cancel</button><a href="logout.php" class="btn btn-danger">Log Out</a></div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>