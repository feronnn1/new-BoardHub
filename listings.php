<?php
session_start();
include 'db.php';

// 1. SEARCH LOGIC
// We removed "WHERE status = 'Active'" so ALL properties show, but we will label them with badges
$where = "WHERE 1=1"; 
if (isset($_GET['search']) && !empty($_GET['search'])) {
    $s = $conn->real_escape_string($_GET['search']);
    $where .= " AND (title LIKE '%$s%' OR location LIKE '%$s%' OR description LIKE '%$s%')";
}
if (isset($_GET['max_price']) && !empty($_GET['max_price'])) {
    $p = intval($_GET['max_price']);
    $where .= " AND (price <= $p OR price_shared <= $p)";
}

// 2. FETCH PROPERTIES
$query = "SELECT * FROM properties $where ORDER BY id DESC";
$result = $conn->query($query);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <title>Find a Boarding House</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        :root { --bg-dark: #0f0f0f; --bg-card: #1a1a1a; --accent-orange: #ff9000; }
        body { background: var(--bg-dark); color: white; font-family: 'Plus Jakarta Sans', sans-serif; }
        .navbar { background: rgba(15, 15, 15, 0.85); backdrop-filter: blur(12px); border-bottom: 1px solid rgba(255,255,255,0.1); padding: 15px 0; }
        .navbar-brand { font-weight: 800; font-size: 22px; color: white; }
        .text-orange { color: var(--accent-orange); }
        .btn-login { background: white; color: black; font-weight: 700; border: none; padding: 8px 24px; border-radius: 50px; text-decoration: none; }
        .btn-login:hover { background: var(--accent-orange); }
        .nav-link-custom { color: #ccc; text-decoration: none; font-weight: 500; font-size: 14px; margin-left: 20px; }
        .nav-link-custom:hover { color: white; }
        .hero-section { padding: 80px 20px 40px; text-align: center; }
        .search-box { max-width: 600px; margin: 0 auto; background: rgba(255,255,255,0.1); border-radius: 50px; padding: 5px; display: flex; align-items: center; border: 1px solid rgba(255,255,255,0.1); }
        .search-input { background: transparent; border: none; color: white; flex-grow: 1; padding: 15px 20px; outline: none; }
        .btn-search { background: var(--accent-orange); color: black; border: none; width: 45px; height: 45px; border-radius: 50%; }
        .filter-pill { border: 1px solid rgba(255,255,255,0.2); color: #ccc; padding: 6px 16px; border-radius: 20px; font-size: 13px; text-decoration: none; display: inline-block; margin-top: 10px; }
        .prop-card { background: var(--bg-card); border-radius: 20px; overflow: hidden; border: 1px solid rgba(255,255,255,0.05); transition: 0.3s; height: 100%; display: flex; flex-direction: column; position: relative; }
        .prop-card:hover { transform: translateY(-5px); border-color: rgba(255, 144, 0, 0.3); }
        .prop-img { width: 100%; height: 240px; object-fit: cover; }
        .prop-body { padding: 20px; flex-grow: 1; display: flex; flex-direction: column; }
        .btn-details { margin-top: auto; width: 100%; padding: 12px; border-radius: 12px; background: rgba(255,255,255,0.05); color: white; text-decoration: none; text-align: center; }
        .btn-details:hover { background: var(--accent-orange); color: black; }
        
        /* NEW STATUS BADGE STYLING */
        .listing-status-badge { position: absolute; top: 15px; left: 15px; padding: 6px 14px; border-radius: 20px; font-size: 11px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.5px; z-index: 2; box-shadow: 0 4px 10px rgba(0,0,0,0.5); }
    </style>
</head>
<body>

<nav class="navbar navbar-expand-lg fixed-top">
    <div class="container">
        <a class="navbar-brand" href="listings.php">Board<span class="text-orange">Hub</span></a>
        <div class="ms-auto d-flex align-items-center">
            <?php if(isset($_SESSION['user'])): ?>
                <?php 
                    $role = $_SESSION['role'] ?? ''; 
                    $dash = "dashboard_tenant.php"; 
                    if ($role == 'Landlord') $dash = "dashboard_landlord.php";
                    if ($role == 'Admin') $dash = "dashboard_admin.php";
                ?>
                <a href="<?php echo $dash; ?>" class="nav-link-custom">My Dashboard</a>
                <a href="logout.php" class="nav-link-custom text-danger">Logout</a>
            <?php else: ?>
                <a href="login.php" class="btn-login">Sign In</a>
            <?php endif; ?>
        </div>
    </div>
</nav>

<div class="hero-section mt-5">
    <h1 class="fw-bold mb-3">Find Your Next Home</h1>
    <form class="search-box" method="GET">
        <input type="text" name="search" class="search-input" placeholder="Search location..." value="<?php echo isset($_GET['search']) ? htmlspecialchars($_GET['search']) : ''; ?>">
        <button type="submit" class="btn-search"><i class="bi bi-search"></i></button>
    </form>
    <div class="d-flex justify-content-center gap-2">
        <a href="listings.php?max_price=1500" class="filter-pill">Under ₱1,500</a>
        <a href="listings.php?max_price=3000" class="filter-pill">Under ₱3,000</a>
        <a href="listings.php" class="text-orange small text-decoration-none mt-3 ms-2">Clear All</a>
    </div>
</div>

<div class="container pb-5 mt-4">
    <h5 class="mb-4">Available Properties</h5>
    <div class="row g-4">
        <?php if($result->num_rows > 0): ?>
            <?php while($row = $result->fetch_assoc()): 
                $images = json_decode($row['images'], true);
                $thumb = !empty($images) ? "assets/uploads/rooms/" . $images[0] : "assets/default_room.jpg";
                
                // LOGIC FOR BADGE COLORS
                $p_stat = !empty($row['status']) ? $row['status'] : 'Accepting';
                $badge_bg = '#198754'; $badge_color = '#fff';
                if ($p_stat == 'Full') { $badge_bg = '#dc3545'; }
                elseif ($p_stat == 'Renovating') { $badge_bg = '#ffc107'; $badge_color = '#000'; }
                elseif ($p_stat == 'Closed') { $badge_bg = '#6c757d'; }
            ?>
            <div class="col-md-6 col-lg-4">
                <div class="prop-card">
                    <div class="listing-status-badge" style="background-color: <?php echo $badge_bg; ?>; color: <?php echo $badge_color; ?>;">
                        <?php echo htmlspecialchars($p_stat); ?>
                    </div>
                    
                    <img src="<?php echo $thumb; ?>" class="prop-img">
                    <div class="prop-body">
                        <h5 class="fw-bold mb-1"><?php echo htmlspecialchars($row['title']); ?></h5>
                        <p class="text-secondary small mb-2"><i class="bi bi-geo-alt-fill text-orange"></i> <?php echo htmlspecialchars($row['location']); ?></p>
                        <h6 class="text-orange fw-bold mb-3">₱<?php echo number_format($row['price']); ?> <span class="text-secondary small fw-normal">/ mo</span></h6>
                        
                        <a href="property_details.php?id=<?php echo $row['id']; ?>" class="btn-details">View Details</a>
                    </div>
                </div>
            </div>
            <?php endwhile; ?>
        <?php else: ?>
            <div class="col-12 text-center text-secondary py-5">No properties found.</div>
        <?php endif; ?>
    </div>
</div>

</body>
</html>