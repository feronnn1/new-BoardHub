<?php
session_start();
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) { header('Location: index.php'); exit; }
if (($_SESSION['user_role'] ?? '') !== 'admin') { header('Location: dashboard.php'); exit; }
if (isset($_GET['logout'])) { session_destroy(); header('Location: index.php'); exit; }

$host   = 'localhost'; $dbname = 'login_system'; $dbuser = 'root'; $dbpass = '';

$all_apps      = [];
$analytics     = ['events'=>[],'product_mix'=>[]];
$fee_ledger    = [];
$total_fees    = 0; $total_donated = 0; $total_pending = 0; $donated_count = 0;
$admin_name    = $_SESSION['user_name'] ?? 'Admin';
$admin_avatar  = ''; $admin_initials = strtoupper(substr($admin_name,0,1));

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4",$dbuser,$dbpass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);

    // Auto-migrate
    $pdo->exec("CREATE TABLE IF NOT EXISTS `stall_applications` (`id` int(11) NOT NULL AUTO_INCREMENT,`user_id` int(11) NOT NULL,`event_id` int(11) DEFAULT NULL,`business_name` varchar(255) DEFAULT NULL,`stall_type` varchar(100) DEFAULT NULL,`stall_size` varchar(100) DEFAULT NULL,`status` varchar(50) NOT NULL DEFAULT 'Submitted',`notes` text DEFAULT NULL,`reviewed_at` timestamp NULL DEFAULT NULL,`created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,`updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,PRIMARY KEY (`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $chk = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND COLUMN_NAME=?");
    foreach([["stall_applications","notes","ALTER TABLE `stall_applications` ADD COLUMN `notes` text DEFAULT NULL"],["stall_applications","reviewed_at","ALTER TABLE `stall_applications` ADD COLUMN `reviewed_at` timestamp NULL DEFAULT NULL"],["form_templates","event_target","ALTER TABLE `form_templates` ADD COLUMN `event_target` varchar(255) DEFAULT NULL"],["users","role","ALTER TABLE `users` ADD COLUMN `role` varchar(20) NOT NULL DEFAULT 'user'"],["users","stall_name","ALTER TABLE `users` ADD COLUMN `stall_name` varchar(255) DEFAULT NULL"],["users","avatar_path","ALTER TABLE `users` ADD COLUMN `avatar_path` varchar(500) DEFAULT NULL"]] as [$t,$c,$s]){$chk->execute([$dbname,$t,$c]);if(!(int)$chk->fetchColumn())$pdo->exec($s);}

    // ALL applications for vetting (all statuses, grouped by event later in PHP)
    $stmt = $pdo->query("
        SELECT sa.id,
               COALESCE(u.full_name,'Unknown Vendor') AS vendor,
               COALESCE(sa.business_name,'Unnamed Stall') AS stall,
               COALESCE(sa.stall_type,'General') AS type,
               COALESCE(sa.stall_size,'Standard') AS size,
               COALESCE(sa.event_id, 0) AS event_id,
               COALESCE(e.title,'No Event') AS event,
               COALESCE(e.start_date, sa.created_at) AS event_date,
               DATE_FORMAT(sa.created_at,'%b %d, %Y') AS submitted,
               sa.status,
               sa.notes,
               CASE WHEN fs.id IS NOT NULL THEN 1 ELSE 0 END AS docs
        FROM stall_applications sa
        LEFT JOIN users u  ON u.id  = sa.user_id
        LEFT JOIN events e ON e.id  = sa.event_id
        LEFT JOIN form_submissions fs ON fs.user_id = sa.user_id
        ORDER BY event_date DESC, FIELD(sa.status,'Submitted','Info Requested','Waitlisted','Approved','Rejected'), sa.created_at DESC
        LIMIT 500");
    $all_apps = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Group by event for organized display
    $apps_by_event = [];
    foreach ($all_apps as $app) {
        $key = $app['event_id'] ?: 0;
        if (!isset($apps_by_event[$key])) {
            $apps_by_event[$key] = [
                'event'    => $app['event'],
                'event_id' => $key,
                'apps'     => [],
            ];
        }
        $apps_by_event[$key]['apps'][] = $app;
    }

    // Analytics
    $stmt = $pdo->query("SELECT e.id,e.title AS name,e.capacity,COUNT(sa.id) AS applications FROM events e LEFT JOIN stall_applications sa ON sa.event_id=e.id WHERE e.is_active=1 GROUP BY e.id ORDER BY e.start_date ASC LIMIT 10");
    foreach($stmt->fetchAll(PDO::FETCH_ASSOC) as &$ev){
        $s2=$pdo->prepare("SELECT stall_type,COUNT(*) AS cnt FROM stall_applications WHERE event_id=? AND stall_type IS NOT NULL GROUP BY stall_type");
        $s2->execute([$ev['id']]); $types=[]; $tot=max($ev['applications'],1);
        foreach($s2->fetchAll(PDO::FETCH_ASSOC) as $t) $types[$t['stall_type']]=round($t['cnt']/$tot*100);
        $ev['types']=$types; $analytics['events'][]=$ev;
    } unset($ev);
    $stmt=$pdo->query("SELECT stall_type,COUNT(*) AS cnt FROM stall_applications WHERE stall_type IS NOT NULL GROUP BY stall_type ORDER BY cnt DESC LIMIT 10");
    $tot2=0; $mx=$stmt->fetchAll(PDO::FETCH_ASSOC); foreach($mx as $r)$tot2+=$r['cnt'];
    foreach($mx as $r) $analytics['product_mix'][$r['stall_type']]=$tot2>0?round($r['cnt']/$tot2*100):0;

    // Fee ledger — only Approved / Donated / Stall Setup apps have donation obligations
    $sizeAmt=['Small'=>1000,'Medium'=>1300,'Large'=>1500,'Standard'=>1000];
    $stmt=$pdo->query("
        SELECT sa.id, u.full_name AS vendor, sa.business_name AS stall,
               COALESCE(e.title,'No Event') AS event,
               sa.stall_size AS size, sa.status,
               sa.donation_type, sa.donation_amount, sa.donation_item_desc,
               DATE_FORMAT(COALESCE(e.start_date,sa.created_at),'%b %d') AS due,
               sa.reviewed_at
        FROM stall_applications sa
        JOIN users u ON u.id = sa.user_id
        LEFT JOIN events e ON e.id = sa.event_id
        WHERE sa.status IN ('Approved','Donated','Stall Setup')
        ORDER BY FIELD(sa.status,'Approved','Donated','Stall Setup'), sa.reviewed_at DESC
        LIMIT 100
    ");
    foreach($stmt->fetchAll(PDO::FETCH_ASSOC) as $app){
        $amt    = $sizeAmt[$app['size']] ?? 1000;
        $dtype  = $app['donation_type'] ?? 'cash';
        $damt   = ($dtype === 'item') ? $amt : (float)($app['donation_amount'] ?? 0);
        $donated= in_array($app['status'],['Donated','Stall Setup']) ? $damt : 0;
        // Ledger status reflects real DB status, not a recalculated one
        $lstatus = strtolower(str_replace(' ','-',$app['status'])); // approved | donated | stall-setup
        $fee_ledger[]=[
            'id'      => $app['id'],
            'vendor'  => $app['vendor'],
            'stall'   => $app['stall'],
            'event'   => $app['event'],
            'amount'  => $amt,
            'donated' => $donated,
            'dtype'   => $dtype,
            'ddesc'   => $app['donation_item_desc'] ?? '',
            'status'  => $lstatus,
            'db_status'=> $app['status'],
            'due'     => $app['due'],
        ];
    }
    $total_fees=$total_donated=$total_pending=0; $donated_count=0;
    foreach($fee_ledger as $r){
        $total_fees   += $r['amount'];
        $total_donated+= $r['donated'];
        if($r['status']==='donated'||$r['status']==='stall-setup') $donated_count++;
    }
    $total_pending=$total_fees-$total_donated;

    // Admin profile
    $stmt=$pdo->prepare("SELECT full_name,avatar_path FROM users WHERE id=?");
    $stmt->execute([$_SESSION['user_id']]??[0]);
    if($row=$stmt->fetch(PDO::FETCH_ASSOC)){$admin_name=$row['full_name']??$admin_name;$admin_avatar=$row['avatar_path']??'';}
    $raw=explode(' ',$admin_name);
    $admin_initials=strtoupper(substr($raw[0],0,1).(isset($raw[1])?substr($raw[1],0,1):''));

} catch(PDOException $e){ error_log("Admin dashboard: ".$e->getMessage()); }

$events_list=[];
try{$stmt=$pdo->query("SELECT id,title FROM events WHERE is_active=1 ORDER BY start_date ASC");$events_list=$stmt->fetchAll(PDO::FETCH_ASSOC);}catch(Exception $e){}

// Count per status for tab badges
$status_counts=['All'=>count($all_apps),'Submitted'=>0,'Info Requested'=>0,'Waitlisted'=>0,'Approved'=>0,'Rejected'=>0];
foreach($all_apps as $a){ $s=$a['status']; if(isset($status_counts[$s])) $status_counts[$s]++; }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>SmartPOP — Admin</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@300;400;500;600&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="admin.css">
<script>
(function(){
  var saved = localStorage.getItem('sp_admin_theme');
  var dark = saved ? saved==='dark' : window.matchMedia('(prefers-color-scheme:dark)').matches;
  if(dark) document.documentElement.classList.add('dark');
})();
</script>
</head>
<body>

<!-- Ambient bg (matches user dashboard) -->
<div class="ambient-bg">
    <div class="orb orb-1"></div>
    <div class="orb orb-2"></div>
    <div class="orb orb-3"></div>
    <div class="grain"></div>
</div>

<!-- ════════ SIDEBAR ════════ -->
<aside class="admin-sidebar" id="adminSidebar">
    <div class="sidebar-brand">
        <div class="brand-mark">⬡</div>
        <div>
            <span class="brand-name">SmartPOP</span>
            <span class="brand-role">Admin Panel</span>
        </div>
    </div>
    <nav class="admin-nav">
        <div class="nav-section-label">Modules</div>
        <a href="#" class="anav-item active" onclick="switchSection('vetting',this);return false;">
            <span class="anav-icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg></span>
            <span>Vetting Queue</span>
            <span class="anav-count" id="pendingCount"><?= $status_counts['Submitted'] ?></span>
        </a>
        <a href="#" class="anav-item" onclick="switchSection('analytics',this);return false;">
            <span class="anav-icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg></span>
            <span>Event Analytics</span>
        </a>
        <a href="#" class="anav-item" onclick="switchSection('comms',this);return false;">
            <span class="anav-icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/></svg></span>
            <span>Comm Hub</span>
        </a>
        <a href="#" class="anav-item" onclick="switchSection('revenue',this);return false;">
            <span class="anav-icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6"/></svg></span>
            <span>Donations & Setup</span>
        </a>
        <a href="#" class="anav-item" onclick="switchSection('formbuilder',this);return false;">
            <span class="anav-icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18M9 21V9"/></svg></span>
            <span>Form Builder</span>
        </a>
        <a href="#" class="anav-item" onclick="switchSection('events',this);return false;">
            <span class="anav-icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg></span>
            <span>Events</span>
        </a>
        <a href="#" class="anav-item" onclick="switchSection('announcements',this);return false;">
            <span class="anav-icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 17H2a3 3 0 0 0 3-3V9a7 7 0 0 1 14 0v5a3 3 0 0 0 3 3zm-8.27 4a2 2 0 0 1-3.46 0"/></svg></span>
            <span>Announcements</span>
        </a>
        <a href="#" class="anav-item" onclick="switchSection('users',this);return false;">
            <span class="anav-icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87M16 3.13a4 4 0 010 7.75"/></svg></span>
            <span>Users</span>
        </a>
    </nav>
    <div class="sidebar-footer">
        <div class="admin-user">
            <div class="admin-avatar">
                <?php if($admin_avatar && file_exists(__DIR__.'/'.$admin_avatar)): ?>
                    <img src="<?= htmlspecialchars($admin_avatar) ?>" alt="">
                <?php else: ?><?= htmlspecialchars($admin_initials) ?><?php endif; ?>
            </div>
            <div>
                <span class="admin-name"><?= htmlspecialchars($admin_name) ?></span>
                <span class="admin-badge">⬡ ADMIN</span>
            </div>
        </div>
        <a href="?logout=1" class="sidebar-logout" title="Logout">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
        </a>
    </div>
</aside>

<!-- ════════ MAIN ════════ -->
<div class="admin-main" id="adminMain">

    <header class="admin-topbar">
        <button class="topbar-toggle" onclick="toggleSidebar()" id="sidebarToggle"><span></span><span></span><span></span></button>
        <div class="topbar-title" id="topbarTitle">Vetting Queue</div>
        <div class="topbar-right">
            <button class="admin-theme-toggle" id="adminThemeToggle" onclick="toggleAdminDark()" title="Toggle dark mode" aria-label="Toggle dark mode">
                <span class="t-sun"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/><line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/></svg></span>
                <span class="t-moon"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg></span>
            </button>
            <div class="topbar-clock" id="adminClock"></div>
            <div class="topbar-status"><span class="status-led"></span><span>ONLINE</span></div>
        </div>
    </header>

    <!-- ══ VETTING QUEUE ══ -->
    <section class="admin-section active" id="sec-vetting">
        <div class="section-header">
            <div>
                <h1 class="section-title">Vetting Queue</h1>
                <p class="section-sub" id="vettingSubtitle"><?= $status_counts['Submitted'] ?> pending review</p>
            </div>
        </div>

        <!-- Tab bar -->
        <div class="vet-tabs" id="vetTabs">
            <div class="vet-tab active" data-tab="All"      onclick="switchVetTab(this)">All <span class="vet-tab-count"><?= $status_counts['All'] ?></span></div>
            <div class="vet-tab"        data-tab="Submitted" onclick="switchVetTab(this)">Pending <span class="vet-tab-count"><?= $status_counts['Submitted'] ?></span></div>
            <div class="vet-tab"        data-tab="Info Requested" onclick="switchVetTab(this)">Info Requested <span class="vet-tab-count"><?= $status_counts['Info Requested'] ?></span></div>
            <div class="vet-tab"        data-tab="Waitlisted" onclick="switchVetTab(this)">Waitlisted <span class="vet-tab-count"><?= $status_counts['Waitlisted'] ?></span></div>
            <div class="vet-tab"        data-tab="Approved"  onclick="switchVetTab(this)">Approved <span class="vet-tab-count"><?= $status_counts['Approved'] ?></span></div>
            <div class="vet-tab"        data-tab="Rejected"  onclick="switchVetTab(this)">Rejected <span class="vet-tab-count"><?= $status_counts['Rejected'] ?></span></div>
        </div>

        <!-- Controls -->
        <div class="vet-controls">
            <input type="text" class="search-box" id="vettingSearch" placeholder="Search vendor, stall, event…" oninput="filterVetting()">
            <select class="filter-select" id="typeFilter" onchange="filterVetting()">
                <option value="">All Types</option>
                <option>Food</option><option>Crafts</option><option>Produce</option>
                <option>Clothing</option><option>Arts</option><option>Services</option><option>General</option>
            </select>
            <select class="filter-select" id="eventFilter" onchange="filterVetting()">
                <option value="">All Events</option>
                <?php foreach($apps_by_event as $eg): ?>
                <option value="<?= $eg['event_id'] ?>"><?= htmlspecialchars($eg['event']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <!-- Event-grouped application list -->
        <div class="vetting-list" id="vettingGrid">
            <?php if(empty($all_apps)): ?>
            <div class="vet-empty">
                <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.2" style="margin-bottom:12px;opacity:.3"><path d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                <p>No applications yet</p>
            </div>
            <?php else: foreach($apps_by_event as $evGroup): ?>
            <!-- Event group header -->
            <div class="event-group" data-event-id="<?= $evGroup['event_id'] ?>">
                <div class="event-group-header" onclick="toggleEventGroup(this)">
                    <div class="event-group-title">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                        <?= htmlspecialchars($evGroup['event']) ?>
                    </div>
                    <div class="event-group-meta">
                        <span><?= count($evGroup['apps']) ?> application<?= count($evGroup['apps'])!==1?'s':'' ?></span>
                        <?php
                        $gCounts = array_count_values(array_column($evGroup['apps'],'status'));
                        foreach(['Submitted'=>'submitted','Approved'=>'approved','Donated'=>'donated','Stall Setup'=>'stall-setup','Rejected'=>'rejected','Waitlisted'=>'waitlisted'] as $st=>$cls):
                            if(!empty($gCounts[$st])): ?>
                        <span class="eg-pill pill-<?= $cls ?>"><?= $gCounts[$st] ?> <?= $st ?></span>
                        <?php endif; endforeach; ?>
                    </div>
                    <div class="event-group-chevron"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="6 9 12 15 18 9"/></svg></div>
                </div>
                <div class="event-group-body">
            <?php foreach($evGroup['apps'] as $i=>$app): ?>
            <div class="app-card"
                 data-id="<?= $app['id'] ?>"
                 data-status="<?= htmlspecialchars($app['status']) ?>"
                 data-type="<?= strtolower($app['type']??'') ?>"
                 data-vendor="<?= strtolower($app['vendor']) ?>"
                 data-stall="<?= strtolower($app['stall']) ?>"
                 data-event="<?= strtolower($app['event']??'') ?>"
                 style="animation-delay:<?= $i*0.05 ?>s">
                <div class="app-card-left">
                    <div class="app-type-badge badge-<?= strtolower($app['type']??'general') ?>"><?= htmlspecialchars($app['type']??'General') ?></div>
                    <div class="app-id">#<?= str_pad($app['id'],4,'0',STR_PAD_LEFT) ?></div>
                </div>
                <div class="app-card-body">
                    <div class="app-vendor"><?= htmlspecialchars($app['vendor']) ?></div>
                    <div class="app-stall">🏪 <?= htmlspecialchars($app['stall']) ?></div>
                    <div class="app-meta">
                        <span>📅 <?= htmlspecialchars($app['submitted']) ?></span>
                        <span>📐 <?= htmlspecialchars($app['size']??'—') ?></span>
                        <span>🎪 <?= htmlspecialchars($app['event']??'N/A') ?></span>
                    </div>
                </div>
                <div class="app-card-docs <?= $app['docs']?'docs-ok':'docs-missing' ?>">
                    <?php if($app['docs']): ?>
                        <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"/></svg> Docs
                    <?php else: ?>
                        <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg> Missing
                    <?php endif; ?>
                </div>
                <div class="app-actions">
                    <?php $s = $app['status']; ?>
                    <?php if($s === 'Submitted' || $s === 'Info Requested' || $s === 'Waitlisted'): ?>
                        <!-- Pre-approval: full vetting buttons -->
                        <button class="act-btn act-approve" onclick="vetAction(<?= $app['id'] ?>,'approve',this)">
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg> Approve
                        </button>
                        <button class="act-btn act-waitlist" onclick="vetAction(<?= $app['id'] ?>,'waitlist',this)">
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg> Waitlist
                        </button>
                        <button class="act-btn act-info" onclick="vetAction(<?= $app['id'] ?>,'info',this)">
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg> Info
                        </button>
                        <button class="act-btn act-reject" onclick="vetAction(<?= $app['id'] ?>,'reject',this)">
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg> Reject
                        </button>
                    <?php elseif($s === 'Approved'): ?>
                        <!-- Approved: awaiting donation -->
                        <span class="app-status-badge status-approved">✓ Approved</span>
                        <button class="act-btn act-approve" style="background:rgba(150,112,56,0.1);color:var(--gold);border-color:rgba(150,112,56,0.25)"
                                onclick="openDonationModal(<?= $app['id'] ?>,'<?= addslashes($app['vendor']) ?>','cash',0,'')">
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6"/></svg> Record Donation
                        </button>
                        <button class="act-btn act-reject" onclick="vetAction(<?= $app['id'] ?>,'reject',this)">
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg> Reject
                        </button>
                    <?php elseif($s === 'Donated'): ?>
                        <!-- Donated: awaiting stall setup -->
                        <span class="app-status-badge status-approved">✓ Approved</span>
                        <span class="app-status-badge" style="background:rgba(150,112,56,0.1);color:var(--gold)">💰 Donated</span>
                        <button class="act-btn" style="background:rgba(53,120,112,0.1);color:var(--teal);border-color:rgba(53,120,112,0.25)"
                                onclick="confirmStallSetupVet(<?= $app['id'] ?>,this)">
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 9l9-7 9 7v11a2 2 0 01-2 2H5a2 2 0 01-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg> Stall Setup
                        </button>
                        <button class="act-btn act-info" onclick="openDonationModal(<?= $app['id'] ?>,'<?= addslashes($app['vendor']) ?>','<?= $app['donation_type']??'cash' ?>',<?= (float)($app['donation_amount']??0) ?>,'<?= addslashes($app['donation_item_desc']??'') ?>')">
                            ✎ Edit Donation
                        </button>
                    <?php elseif($s === 'Stall Setup'): ?>
                        <!-- Fully complete -->
                        <span class="app-status-badge status-approved">✓ Approved</span>
                        <span class="app-status-badge" style="background:rgba(150,112,56,0.1);color:var(--gold)">💰 Donated</span>
                        <span class="app-status-badge" style="background:rgba(53,120,112,0.1);color:var(--teal)">🏪 Stall Set Up</span>
                    <?php elseif($s === 'Rejected'): ?>
                        <span class="app-status-badge status-rejected">✕ Rejected</span>
                        <!-- Allow re-evaluation -->
                        <button class="act-btn act-approve" onclick="vetAction(<?= $app['id'] ?>,'approve',this)">
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg> Re-approve
                        </button>
                    <?php else: ?>
                        <span class="app-status-badge status-<?= strtolower(str_replace(' ','-',$s)) ?>"><?= htmlspecialchars($s) ?></span>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
                </div><!-- /event-group-body -->
            </div><!-- /event-group -->
            <?php endforeach; endif; ?>
        </div>
    </section>

    <!-- ══ ANALYTICS ══ -->
    <section class="admin-section" id="sec-analytics">
        <div class="section-header"><div><h1 class="section-title">Event Analytics</h1><p class="section-sub">Stall interest and product diversity across all events</p></div></div>
        <div class="analytics-grid">
            <div class="analytics-card span-1">
                <div class="ac-header"><h3 class="ac-title">Product Mix</h3><span class="ac-sub">Overall diversity</span></div>
                <?php if(empty($analytics['product_mix'])): ?><p style="color:var(--text-3);text-align:center;padding:40px 0">No application data yet</p>
                <?php else: ?><div class="donut-wrap"><canvas id="donutChart" width="200" height="200"></canvas><div class="donut-center"><div class="donut-total" id="donutTotal">0</div><div class="donut-label">Total Apps</div></div></div><div class="donut-legend" id="donutLegend"></div><?php endif; ?>
            </div>
            <div class="analytics-card span-2">
                <div class="ac-header"><h3 class="ac-title">Event Capacity vs Applications</h3><span class="ac-sub">Fill rate per event</span></div>
                <?php if(empty($analytics['events'])): ?><p style="color:var(--text-3);text-align:center;padding:40px 0">No event data yet</p>
                <?php else: ?><div class="bar-chart-wrap" id="barChartWrap"></div><?php endif; ?>
            </div>
            <?php foreach($analytics['events'] as $ev): ?>
            <div class="analytics-card event-breakdown">
                <div class="ac-header"><h3 class="ac-title"><?= htmlspecialchars($ev['name']) ?></h3><span class="fill-rate"><?= $ev['capacity']>0?round($ev['applications']/$ev['capacity']*100):0 ?>% Full</span></div>
                <?php if(empty($ev['types'])): ?><p style="color:var(--text-3);font-size:12px">No applications yet</p>
                <?php else: ?><div class="mini-bars"><?php foreach($ev['types'] as $type=>$pct): if(!$pct)continue; ?><div class="mbar-row"><span class="mbar-label"><?= htmlspecialchars($type) ?></span><div class="mbar-track"><div class="mbar-fill badge-bg-<?= strtolower($type) ?>" data-val="<?= $pct ?>" style="width:0"></div></div><span class="mbar-val"><?= $pct ?>%</span></div><?php endforeach; ?></div><?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
    </section>

    <!-- ══ COMM HUB ══ -->
    <section class="admin-section" id="sec-comms">
        <div class="section-header"><div><h1 class="section-title">Communication Hub</h1><p class="section-sub">Broadcast alerts to vendors via SMS or Email</p></div></div>
        <div class="comms-layout">
            <div class="comms-compose">
                <div class="compose-card">
                    <h3 class="compose-title">New Broadcast</h3>
                    <div class="compose-form">
                        <div class="cf-group"><label class="cf-label">Channel</label>
                            <div class="channel-toggle">
                                <button class="ch-btn active" onclick="setChannel('email',this)"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg> Email</button>
                                <button class="ch-btn" onclick="setChannel('sms',this)"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/></svg> SMS</button>
                                <button class="ch-btn" onclick="setChannel('both',this)">Both</button>
                            </div>
                        </div>
                        <div class="cf-group"><label class="cf-label">Recipients</label>
                            <div class="recipient-chips" id="recipientChips">
                                <div class="chip chip-active" data-group="all" onclick="toggleChip(this)">All Vendors</div>
                                <div class="chip" data-group="approved" onclick="toggleChip(this)">Approved Only</div>
                                <div class="chip" data-group="pending" onclick="toggleChip(this)">Pending Only</div>
                                <?php foreach($events_list as $ev): ?><div class="chip" data-group="event_<?= $ev['id'] ?>" onclick="toggleChip(this)"><?= htmlspecialchars($ev['title']) ?></div><?php endforeach; ?>
                            </div>
                        </div>
                        <div class="cf-group" id="subjectGroup"><label class="cf-label">Subject <span class="cf-required">*</span></label><input type="text" class="cf-input" id="msgSubject" placeholder="e.g. Event starts in 1 hour"></div>
                        <div class="cf-group"><label class="cf-label">Message <span class="cf-required">*</span><span class="char-counter" id="charCounter">0 / 160</span></label><textarea class="cf-textarea" id="msgBody" rows="5" placeholder="Type your broadcast message…" oninput="updateCharCount(this)"></textarea></div>
                        <div class="cf-group"><label class="cf-label">Quick Templates</label>
                            <div class="templates-grid">
                                <button class="tpl-btn" onclick="loadTemplate('event_start')">🚀 Event Start</button>
                                <button class="tpl-btn" onclick="loadTemplate('weather')">⛈ Weather Alert</button>
                                <button class="tpl-btn" onclick="loadTemplate('payment')">💸 Payment Due</button>
                                <button class="tpl-btn" onclick="loadTemplate('reminder')">⏰ Reminder</button>
                            </div>
                        </div>
                        <button class="broadcast-btn" onclick="sendBroadcast()"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg> Send Broadcast</button>
                    </div>
                </div>
            </div>
            <div class="comms-log"><div class="log-card"><h3 class="compose-title">Broadcast Log</h3><div class="log-list" id="broadcastLog"><div class="log-empty">No broadcasts sent yet.</div></div></div></div>
        </div>
    </section>

    <!-- ══ REVENUE / DONATIONS ══ -->
    <section class="admin-section" id="sec-revenue">
        <div class="section-header">
            <div><h1 class="section-title">Donations & Stall Setup</h1><p class="section-sub" id="donSubtitle">Vendor donation tracking and stall setup confirmation</p></div>
            <div style="display:flex;gap:8px">
                <button class="fb-btn-secondary" onclick="loadDonationSummary()" title="Transparency report">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                    Summary
                </button>
                <button class="export-btn" onclick="exportCSV()">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                    Export CSV
                </button>
            </div>
        </div>

        <!-- Transparency summary panel (hidden by default) -->
        <div id="donSummaryPanel" style="display:none;margin-bottom:16px">
            <div class="rev-summary" style="margin-bottom:12px">
                <div class="rev-card success"><div class="rev-icon">₱</div><div><div class="rev-val" id="dsCash">₱0</div><div class="rev-lbl">Cash Donations</div></div></div>
                <div class="rev-card neutral"><div class="rev-icon">📦</div><div><div class="rev-val" id="dsItems">0</div><div class="rev-lbl">Item Donations</div></div></div>
                <div class="rev-card"><div class="rev-icon">👥</div><div><div class="rev-val" id="dsDonors">0</div><div class="rev-lbl">Total Donors</div></div></div>
            </div>
            <div id="donSummaryTable" class="ledger-wrap"></div>
        </div>

        <div class="rev-summary">
            <div class="rev-card success"><div class="rev-icon">✓</div><div><div class="rev-val" data-target="<?= $total_donated ?>">₱0</div><div class="rev-lbl">Total Donated</div></div></div>
            <div class="rev-card danger"><div class="rev-icon">!</div><div><div class="rev-val" data-target="<?= $total_pending ?>">₱0</div><div class="rev-lbl">Pending Donation</div></div></div>
        </div>

        <div class="ledger-wrap">
            <?php if(empty($fee_ledger)): ?>
            <p style="text-align:center;padding:40px;color:var(--text-3);">No applications yet.</p>
            <?php else: ?>
            <table class="ledger-table" id="ledgerTable">
                <thead><tr>
                    <th onclick="sortLedger('vendor')">Vendor <span class="sort-icon">↕</span></th>
                    <th>Stall</th>
                    <th onclick="sortLedger('event')">Event <span class="sort-icon">↕</span></th>
                    <th>Donation</th><th>Due</th>
                    <th onclick="sortLedger('status')">Status <span class="sort-icon">↕</span></th>
                    <th>Actions</th>
                </tr></thead>
                <tbody id="ledgerBody">
                <?php foreach($fee_ledger as $row):
                    $dtype = $row['dtype'];
                    $damt  = (float)$row['donated'];
                    $ddesc = $row['ddesc'];
                    $dbst  = $row['db_status']; // Approved | Donated | Stall Setup
                    $dst   = $row['status'];     // approved | donated | stall-setup
                    if ($dbst === 'Approved') {
                        $don_display = '<span style="color:var(--text-3);font-size:12px">⏳ Awaiting donation</span>';
                    } elseif ($dtype === 'item') {
                        $don_display = '📦 '.htmlspecialchars($ddesc ?: 'Item donation');
                    } else {
                        $don_display = '<span class="td-paid">₱'.number_format($damt,2).'</span>';
                    }
                    $pill_class = match($dbst) {
                        'Approved'    => 'pill-info-requested',
                        'Donated'     => 'pill-donated',
                        'Stall Setup' => 'pill-stall-setup',
                        default       => 'pill-submitted',
                    };
                ?>
                <tr class="ledger-row" data-id="<?= $row['id'] ?>" data-status="<?= $dst ?>">
                    <td class="td-vendor"><?= htmlspecialchars($row['vendor']) ?></td>
                    <td><?= htmlspecialchars($row['stall']) ?></td>
                    <td class="td-event" id="ev-<?= $row['id'] ?>"><?= htmlspecialchars($row['event']) ?></td>
                    <td id="don-<?= $row['id'] ?>"><?= $don_display ?></td>
                    <td><?= htmlspecialchars($row['due']) ?></td>
                    <td><span class="status-pill <?= $pill_class ?>" id="pill-<?= $row['id'] ?>"><?= htmlspecialchars($dbst) ?></span></td>
                    <td style="display:flex;gap:5px;flex-wrap:wrap;align-items:center">
                        <button class="ledger-btn" onclick="openAssignEventModal(<?= $row['id'] ?>,'<?= addslashes($row['event']) ?>')" title="Assign event">📅 Event</button>
                        <?php if ($dbst === 'Approved'): ?>
                            <button class="ledger-btn" style="color:var(--gold);border-color:rgba(150,112,56,0.28)"
                                    onclick="openDonationModal(<?= $row['id'] ?>,'<?= addslashes($row['vendor']) ?>','cash',0,'')">
                                💰 Record Donation
                            </button>
                        <?php elseif ($dbst === 'Donated'): ?>
                            <button class="ledger-btn" style="color:var(--teal);border-color:rgba(53,120,112,0.28)"
                                    onclick="confirmStallSetup(<?= $row['id'] ?>,this)">
                                🏪 Confirm Setup
                            </button>
                            <button class="ledger-btn" onclick="openDonationModal(<?= $row['id'] ?>,'<?= addslashes($row['vendor']) ?>','<?= $dtype ?>',<?= $damt ?>,'<?= addslashes($ddesc) ?>')">✎ Edit</button>
                        <?php elseif ($dbst === 'Stall Setup'): ?>
                            <span class="paid-check" style="color:var(--teal)">✓ Complete</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
    </section>

    <!-- ══ EVENTS ══ -->
    <section class="admin-section" id="sec-events">
        <div class="section-header">
            <div><h1 class="section-title">Events</h1><p class="section-sub" id="evSubtitle">Manage pop-up market events</p></div>
            <button class="fb-btn-primary" onclick="openEventModal()">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                New Event
            </button>
        </div>
        <div id="eventsList" style="display:flex;flex-direction:column;gap:10px">
            <div class="vet-empty"><div style="font-size:32px;opacity:.4">📅</div><p>Loading events…</p></div>
        </div>
    </section>

    <!-- ══ FORM BUILDER ══ -->
    <section class="admin-section" id="sec-formbuilder">
        <div class="section-header">
            <div>
                <h1 class="section-title">Form Builder</h1>
                <p class="section-sub" id="fbModeLabel">Drag fields to build application forms for events</p>
            </div>
            <div class="fb-header-actions">
                <span id="fbEditBadge" style="display:none;font-size:11px;color:var(--gold);background:rgba(150,112,56,0.12);border:1px solid rgba(150,112,56,0.25);border-radius:20px;padding:3px 10px;font-weight:600;">✎ Editing Form</span>
                <button class="fb-btn-secondary" onclick="cancelEditForm()" id="fbCancelEditBtn" style="display:none">✕ Cancel Edit</button>
                <button class="fb-btn-secondary" onclick="clearForm()">Clear</button>
                <button class="fb-btn-primary" id="fbSaveBtn" onclick="saveForm()"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 21H5a2 2 0 01-2-2V5a2 2 0 012-2h11l5 5v11a2 2 0 01-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg> <span id="fbSaveBtnTxt">Save Form</span></button>
            </div>
        </div>
        <input type="hidden" id="fbEditingId" value="">
        <div class="fb-layout">
            <div class="fb-palette">
                <h3 class="fb-palette-title">Field Types</h3>
                <div class="palette-fields">
                    <div class="palette-item" draggable="true" data-type="text"     ondragstart="paletteDrag(event)"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="4 7 4 4 20 4 20 7"/><line x1="9" y1="20" x2="15" y2="20"/><line x1="12" y1="4" x2="12" y2="20"/></svg> Short Text</div>
                    <div class="palette-item" draggable="true" data-type="textarea" ondragstart="paletteDrag(event)"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><line x1="7" y1="8" x2="17" y2="8"/><line x1="7" y1="12" x2="17" y2="12"/><line x1="7" y1="16" x2="13" y2="16"/></svg> Long Text</div>
                    <div class="palette-item" draggable="true" data-type="email"    ondragstart="paletteDrag(event)"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg> Email</div>
                    <div class="palette-item" draggable="true" data-type="phone"    ondragstart="paletteDrag(event)"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 16.92v3a2 2 0 01-2.18 2 19.79 19.79 0 01-8.63-3.07"/></svg> Phone</div>
                    <div class="palette-item" draggable="true" data-type="select"   ondragstart="paletteDrag(event)"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"/></svg> Dropdown</div>
                    <div class="palette-item" draggable="true" data-type="radio"    ondragstart="paletteDrag(event)"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="3"/></svg> Radio Group</div>
                    <div class="palette-item" draggable="true" data-type="checkbox" ondragstart="paletteDrag(event)"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 11 12 14 22 4"/><path d="M21 12v7a2 2 0 01-2 2H5a2 2 0 01-2-2V5a2 2 0 012-2h11"/></svg> Checkbox</div>
                    <div class="palette-item" draggable="true" data-type="date"     ondragstart="paletteDrag(event)"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg> Date</div>
                    <div class="palette-item" draggable="true" data-type="file"     ondragstart="paletteDrag(event)"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg> File Upload</div>
                    <div class="palette-item" draggable="true" data-type="heading"  ondragstart="paletteDrag(event)"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 6h16M4 12h16M4 18h7"/></svg> Section Heading</div>
                </div>
                <div class="fb-form-meta">
                    <h3 class="fb-palette-title" style="margin-top:20px">Form Settings</h3>
                    <div class="meta-field"><label>Form Title</label><input type="text" id="fbFormTitle" value="Stall Application Form" class="cf-input"></div>
                    <div class="meta-field"><label>Target Event</label>
                        <select id="fbEventTarget" class="cf-input">
                            <option value="">— Select Event —</option>
                            <?php foreach($events_list as $ev): ?><option value="<?= htmlspecialchars($ev['title']) ?>"><?= htmlspecialchars($ev['title']) ?></option><?php endforeach; ?>
                            <option value="__new__">+ New Event</option>
                        </select>
                    </div>
                    <div class="meta-field"><label>Deadline</label><input type="date" id="fbDeadline" class="cf-input"></div>
                </div>
            </div>
            <div class="fb-canvas-wrap">
                <div class="fb-canvas-title"><span id="fbCanvasTitle">Stall Application Form</span><span class="fb-field-count" id="fbFieldCount">0 fields</span></div>
                <div class="fb-canvas" id="fbCanvas" ondragover="canvasDragOver(event)" ondrop="canvasDrop(event)" ondragleave="canvasDragLeave(event)">
                    <div class="fb-empty" id="fbEmpty"><svg width="38" height="38" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.3"><path d="M12 5v14M5 12h14"/></svg><p>Drag fields here to build your form</p></div>
                </div>
                <div class="fb-canvas-footer">
                    <button class="fb-btn-secondary" onclick="previewForm()"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg> Preview</button>
                    <button class="fb-btn-secondary" onclick="exportFormJSON()"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg> Export</button>
                </div>
            </div>
            <div class="fb-editor" id="fbEditor">
                <h3 class="fb-palette-title">Field Properties</h3>
                <div class="editor-empty" id="editorEmpty"><p>Select a field to edit its properties</p></div>
                <div class="editor-form" id="editorForm" style="display:none">
                    <div class="meta-field"><label>Label</label><input type="text" id="ef_label" class="cf-input" oninput="updateSelectedField()"></div>
                    <div class="meta-field"><label>Placeholder</label><input type="text" id="ef_placeholder" class="cf-input" oninput="updateSelectedField()"></div>
                    <div class="meta-field" id="ef_options_group" style="display:none"><label>Options (one per line)</label><textarea class="cf-textarea" id="ef_options" rows="4" oninput="updateSelectedField()" placeholder="Option 1&#10;Option 2&#10;Option 3"></textarea></div>
                    <div class="meta-field"><label class="cf-check-label"><input type="checkbox" id="ef_required" onchange="updateSelectedField()"> Required field</label></div>
                    <button class="delete-field-btn" onclick="deleteSelectedField()"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 01-2 2H7a2 2 0 01-2-2V6m3 0V4a1 1 0 011-1h4a1 1 0 011 1v2"/></svg> Remove Field</button>
                </div>
            </div>
        </div>
        <div class="pf-section">
            <div class="pf-section-header"><h3>Published Forms</h3><span class="pf-section-sub">Forms available for vendors to apply</span></div>
            <div id="publishedFormsList" class="pf-list"><div class="pf-empty">Loading…</div></div>
        </div>
        <div id="submissionsPanel" class="sub-panel" style="display:none">
            <div class="pf-section-header"><h3>Submissions</h3><button class="pf-close-btn" onclick="document.getElementById('submissionsPanel').style.display='none'">✕ Close</button></div>
            <div id="submissionsList" class="sub-list"></div>
        </div>
    </section>

    <!-- ══ ANNOUNCEMENTS ══ -->
    <section class="admin-section" id="sec-announcements">
        <div class="section-header">
            <div>
                <h1 class="section-title">Announcements</h1>
                <p class="section-sub" id="annSubtitle">Manage what vendors see on their dashboard</p>
            </div>
            <button class="fb-btn-primary" onclick="openAnnModal()">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                New Announcement
            </button>
        </div>

        <!-- Emoji quick-pick bar -->
        <div class="ann-emoji-bar" id="annEmojiBar" style="display:none">
            <span class="ann-emoji-label">Quick emoji:</span>
            <?php foreach(['📢','🎉','⚠️','✅','📋','🔔','🚀','📅','💡','🛒','🏪','⭐'] as $e): ?>
            <button class="ann-emoji-pick" onclick="pickEmoji('<?= $e ?>')"><?= $e ?></button>
            <?php endforeach; ?>
        </div>

        <div id="annList" style="display:flex;flex-direction:column;gap:10px;">
            <div class="vet-empty">
                <div style="font-size:36px;margin-bottom:10px;opacity:.4">🔔</div>
                <p>Loading announcements…</p>
            </div>
        </div>
    </section>

    <!-- ══ USERS ══ -->
    <section class="admin-section" id="sec-users">
        <div class="section-header">
            <div><h1 class="section-title">User Management</h1><p class="section-sub" id="userSubtitle">Loading users…</p></div>
            <div class="section-controls"><input type="text" class="search-box" id="userSearch" placeholder="Search name or email…" oninput="filterUsers(this.value)"></div>
        </div>
        <div class="ledger-wrap">
            <table class="ledger-table" id="usersTable">
                <thead><tr><th>#</th><th>Name</th><th>Email</th><th>Stall</th><th>Role</th><th>Status</th><th>Joined</th><th>Actions</th></tr></thead>
                <tbody id="usersBody"><tr><td colspan="8" style="text-align:center;padding:32px;color:var(--text-3);">Loading…</td></tr></tbody>
            </table>
        </div>
    </section>

</div><!-- /admin-main -->

<!-- ════════ VET MODAL ════════ -->
<div class="modal-overlay" id="vetModal" onclick="closeVetModal(event)">
    <div class="vet-modal">
        <div class="vm-header"><h3 id="vmTitle">Action</h3><button onclick="closeVetModal()" class="vm-close">✕</button></div>
        <div class="vm-body">
            <p id="vmDesc"></p>
            <textarea class="cf-textarea" id="vmNote" rows="3" placeholder="Add a note (optional)…"></textarea>
            <div class="vm-actions"><button class="vm-cancel" onclick="closeVetModal()">Cancel</button><button class="vm-confirm" id="vmConfirm">Confirm</button></div>
        </div>
    </div>
</div>

<!-- ════════ PREVIEW MODAL ════════ -->
<div class="modal-overlay" id="previewModal" onclick="closePreview(event)">
    <div class="preview-modal">
        <div class="vm-header"><h3 id="previewTitle">Form Preview</h3><button onclick="closePreview()" class="vm-close">✕</button></div>
        <div class="preview-body" id="previewBody"></div>
    </div>
</div>

<!-- ════════ DONATION EDIT MODAL ════════ -->
<div class="modal-overlay" id="donModal" onclick="closeDonModal(event)">
    <div class="vet-modal" style="width:min(480px,calc(100vw - 32px))">
        <div class="vm-header">
            <h3 id="donModalTitle">Edit Donation</h3>
            <button onclick="closeDonModal()" class="vm-close">✕</button>
        </div>
        <div class="vm-body" style="display:flex;flex-direction:column;gap:14px">
            <input type="hidden" id="donAppId">
            <p id="donVendorName" style="color:var(--text-2);font-size:13px"></p>
            <div class="cf-group">
                <label class="cf-label">Donation Type</label>
                <div style="display:flex;gap:10px">
                    <label style="display:flex;align-items:center;gap:6px;cursor:pointer;font-size:13px;color:var(--text-2)">
                        <input type="radio" name="donType" value="cash" id="donTypeCash" onchange="toggleDonType()" style="accent-color:var(--rose)"> Cash
                    </label>
                    <label style="display:flex;align-items:center;gap:6px;cursor:pointer;font-size:13px;color:var(--text-2)">
                        <input type="radio" name="donType" value="item" id="donTypeItem" onchange="toggleDonType()" style="accent-color:var(--rose)"> Item Donation
                    </label>
                </div>
            </div>
            <div class="cf-group" id="donCashGroup">
                <label class="cf-label">Amount (₱)</label>
                <input type="number" id="donAmount" class="cf-input" min="0" step="0.01" placeholder="0.00">
            </div>
            <div class="cf-group" id="donItemGroup" style="display:none">
                <label class="cf-label">Item Description</label>
                <input type="text" id="donItemDesc" class="cf-input" placeholder="e.g. 2 boxes of bottled water, printed tarpaulin">
            </div>
            <div class="vm-actions" style="padding-top:4px">
                <button class="vm-cancel" onclick="closeDonModal()">Cancel</button>
                <button class="vm-confirm" onclick="saveDonation()">Save Donation</button>
            </div>
        </div>
    </div>
</div>

<!-- ════════ ASSIGN EVENT MODAL ════════ -->
<div class="modal-overlay" id="assignEvModal" onclick="closeAssignEvModal(event)">
    <div class="vet-modal" style="width:min(420px,calc(100vw - 32px))">
        <div class="vm-header">
            <h3>Assign Event</h3>
            <button onclick="closeAssignEvModal()" class="vm-close">✕</button>
        </div>
        <div class="vm-body" style="display:flex;flex-direction:column;gap:14px">
            <input type="hidden" id="assignAppId">
            <p id="assignCurrentEv" style="color:var(--text-3);font-size:12px"></p>
            <div class="cf-group">
                <label class="cf-label">Select Event</label>
                <select id="assignEventSelect" class="cf-input">
                    <option value="">— No event —</option>
                </select>
            </div>
            <div class="vm-actions">
                <button class="vm-cancel" onclick="closeAssignEvModal()">Cancel</button>
                <button class="vm-confirm" onclick="saveAssignEvent()">Assign</button>
            </div>
        </div>
    </div>
</div>

<!-- ════════ EVENT CRUD MODAL ════════ -->
<div class="modal-overlay" id="eventModal" onclick="closeEventModal(event)">
    <div class="vet-modal" style="width:min(540px,calc(100vw - 32px))">
        <div class="vm-header">
            <h3 id="eventModalTitle">New Event</h3>
            <button onclick="closeEventModal()" class="vm-close">✕</button>
        </div>
        <div class="vm-body" style="display:flex;flex-direction:column;gap:12px">
            <input type="hidden" id="evEditId">
            <div class="cf-group">
                <label class="cf-label">Event Title <span class="cf-required">*</span></label>
                <input type="text" id="evTitle" class="cf-input" placeholder="e.g. Summer Pop-Up Market">
            </div>
            <div class="cf-group">
                <label class="cf-label">Description</label>
                <textarea id="evDesc" class="cf-textarea" rows="2" placeholder="Brief description of the event"></textarea>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
                <div class="cf-group">
                    <label class="cf-label">Start Date</label>
                    <input type="date" id="evStart" class="cf-input">
                </div>
                <div class="cf-group">
                    <label class="cf-label">End Date</label>
                    <input type="date" id="evEnd" class="cf-input">
                </div>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
                <div class="cf-group">
                    <label class="cf-label">Location</label>
                    <input type="text" id="evLocation" class="cf-input" placeholder="e.g. Main Square">
                </div>
                <div class="cf-group">
                    <label class="cf-label">Vendor Capacity</label>
                    <input type="number" id="evCapacity" class="cf-input" min="1" placeholder="30">
                </div>
            </div>
            <div class="cf-group">
                <label class="cf-check-label" style="font-size:13px;color:var(--text-2)">
                    <input type="checkbox" id="evActive" checked style="accent-color:var(--rose);width:14px;height:14px">
                    Active (visible to vendors for applications)
                </label>
            </div>
            <div class="vm-actions">
                <button class="vm-cancel" onclick="closeEventModal()">Cancel</button>
                <button class="vm-confirm" id="evSaveBtn" onclick="saveEvent()">Create Event</button>
            </div>
        </div>
    </div>
</div>

<!-- ════════ ANNOUNCEMENT MODAL ════════ -->
<div class="modal-overlay" id="annModal" onclick="closeAnnModal(event)">
    <div class="vet-modal" style="width:min(540px,calc(100vw - 32px))">
        <div class="vm-header">
            <h3 id="annModalTitle">New Announcement</h3>
            <button onclick="closeAnnModal()" class="vm-close">✕</button>
        </div>
        <div class="vm-body" style="display:flex;flex-direction:column;gap:14px;">
            <input type="hidden" id="annEditId" value="">

            <div class="cf-group">
                <label class="cf-label">Icon <span style="color:var(--text-3);font-size:10px">— emoji or short symbol</span></label>
                <div style="display:flex;gap:8px;align-items:center">
                    <input type="text" id="annIcon" class="cf-input" value="📢" maxlength="4"
                           style="width:64px;text-align:center;font-size:22px;padding:6px 8px">
                    <button class="fb-btn-secondary" style="padding:8px 12px;font-size:12px"
                            onclick="toggleEmojiBar()">Pick emoji</button>
                </div>
                <div id="annModalEmojiRow" style="display:none;flex-wrap:wrap;gap:6px;margin-top:6px">
                    <?php foreach(['📢','🎉','⚠️','✅','📋','🔔','🚀','📅','💡','🛒','🏪','⭐','🆕','❗','📣','🗓️','🎪','💬'] as $e): ?>
                    <button class="ann-emoji-pick" onclick="document.getElementById('annIcon').value='<?= $e ?>';this.closest('#annModalEmojiRow').style.display='none'"><?= $e ?></button>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="cf-group">
                <label class="cf-label">Title <span class="cf-required">*</span></label>
                <input type="text" id="annTitle" class="cf-input" placeholder="e.g. Applications now open for Summer Market">
            </div>

            <div class="cf-group">
                <label class="cf-label">Body <span class="cf-required">*</span>
                    <span class="char-counter" id="annBodyCounter">0 / 400</span>
                </label>
                <textarea id="annBody" class="cf-textarea" rows="4"
                          placeholder="Write the full announcement message here…"
                          oninput="document.getElementById('annBodyCounter').textContent=this.value.length+' / 400'"
                          maxlength="400"></textarea>
            </div>

            <div class="cf-group">
                <label class="cf-check-label" style="font-size:13px;color:var(--text-2)">
                    <input type="checkbox" id="annPinned" style="accent-color:var(--rose);width:14px;height:14px">
                    📌 Pin this announcement (shows at top of vendor dashboard)
                </label>
            </div>

            <div class="vm-actions" style="padding-top:4px">
                <button class="vm-cancel" onclick="closeAnnModal()">Cancel</button>
                <button class="vm-confirm" id="annSaveBtn" onclick="saveAnnouncement()">Publish</button>
            </div>
        </div>
    </div>
</div>

<div class="admin-toast" id="adminToast"></div>

<script>
const ANALYTICS_DATA = <?= json_encode($analytics) ?>;
const LEDGER_DATA    = <?= json_encode($fee_ledger) ?>;

// ── Announcement Manager ──────────────────────────────
function loadAnnouncements() {
    const list = document.getElementById('annList');
    fetch('process_admin.php?action=get_announcements', {headers:{'X-Requested-With':'XMLHttpRequest'}})
    .then(r => r.json())
    .then(data => {
        if (!data.success) { list.innerHTML = '<div class="vet-empty"><p>Could not load announcements.</p></div>'; return; }
        const sub = document.getElementById('annSubtitle');
        if (sub) sub.textContent = data.data.length + ' announcement' + (data.data.length !== 1 ? 's' : '') + ' · visible on vendor dashboard';
        if (!data.data.length) {
            list.innerHTML = '<div class="vet-empty"><div style="font-size:36px;margin-bottom:10px;opacity:.4">🔔</div><p>No announcements yet. Create one to notify your vendors.</p></div>';
            return;
        }
        list.innerHTML = data.data.map((a, i) => `
            <div class="ann-admin-card${a.is_pinned=='1'?' ann-admin-pinned':''}" data-id="${a.id}" style="animation-delay:${i*0.05}s">
                <div class="ann-admin-icon">${escHtml(a.icon)}</div>
                <div class="ann-admin-body">
                    <div class="ann-admin-title">
                        ${a.is_pinned=='1' ? '<span class="ann-pin-chip">📌 Pinned</span>' : ''}
                        ${escHtml(a.title)}
                    </div>
                    <div class="ann-admin-text">${escHtml(a.body)}</div>
                    <div class="ann-admin-meta">${a.time_ago} · Posted ${a.created_date}</div>
                </div>
                <div class="ann-admin-actions">
                    <button class="act-btn ${a.is_pinned=='1'?'act-info':'act-waitlist'}"
                            onclick="togglePin(${a.id}, ${a.is_pinned=='1'?0:1}, this)"
                            title="${a.is_pinned=='1'?'Unpin':'Pin'}">
                        ${a.is_pinned=='1'
                          ? '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="5" y1="12" x2="19" y2="12"/></svg> Unpin'
                          : '📌 Pin'}
                    </button>
                    <button class="act-btn act-info" onclick="editAnnouncement(${a.id},'${escAttr(a.icon)}','${escAttr(a.title)}','${escAttr(a.body)}',${a.is_pinned})"
                            title="Edit">
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                        Edit
                    </button>
                    <button class="act-btn act-reject" onclick="deleteAnnouncement(${a.id}, this)" title="Delete">
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/></svg>
                        Delete
                    </button>
                </div>
            </div>`).join('');
    })
    .catch(() => {
        document.getElementById('annList').innerHTML = '<div class="vet-empty"><p>Network error loading announcements.</p></div>';
    });
}

function openAnnModal(id, icon, title, body, pinned) {
    document.getElementById('annEditId').value    = id  || '';
    document.getElementById('annIcon').value      = icon  || '📢';
    document.getElementById('annTitle').value     = title || '';
    document.getElementById('annBody').value      = body  || '';
    document.getElementById('annPinned').checked  = !!pinned;
    document.getElementById('annBodyCounter').textContent = (body||'').length + ' / 400';
    document.getElementById('annModalTitle').textContent  = id ? 'Edit Announcement' : 'New Announcement';
    document.getElementById('annSaveBtn').textContent     = id ? 'Save Changes' : 'Publish';
    document.getElementById('annModalEmojiRow').style.display = 'none';
    document.getElementById('annModal').classList.add('open');
    setTimeout(() => document.getElementById('annTitle').focus(), 100);
}

function editAnnouncement(id, icon, title, body, pinned) {
    openAnnModal(id, icon, title, body, pinned);
}

function closeAnnModal(e) {
    if (e && e.target !== document.getElementById('annModal')) return;
    document.getElementById('annModal').classList.remove('open');
}

function toggleEmojiBar() {
    const row = document.getElementById('annModalEmojiRow');
    row.style.display = row.style.display === 'none' ? 'flex' : 'none';
}

function saveAnnouncement() {
    const id     = document.getElementById('annEditId').value;
    const icon   = document.getElementById('annIcon').value.trim()  || '📢';
    const title  = document.getElementById('annTitle').value.trim();
    const body   = document.getElementById('annBody').value.trim();
    const pinned = document.getElementById('annPinned').checked ? 1 : 0;

    if (!title) { document.getElementById('annTitle').focus(); toast('Title is required.', '⚠', 'warn'); return; }
    if (!body)  { document.getElementById('annBody').focus();  toast('Body is required.',  '⚠', 'warn'); return; }

    const btn = document.getElementById('annSaveBtn');
    btn.disabled = true; btn.textContent = 'Saving…';

    const fd = new FormData();
    fd.append('action',     'save_announcement');
    fd.append('ann_id',     id);
    fd.append('icon',       icon);
    fd.append('title',      title);
    fd.append('body',       body);
    fd.append('is_pinned',  pinned);

    fetch('process_admin.php', {method:'POST', headers:{'X-Requested-With':'XMLHttpRequest'}, body:fd})
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            document.getElementById('annModal').classList.remove('open');
            loadAnnouncements();
            toast(data.message, '✓', 'success');
        } else {
            toast(data.message || 'Save failed.', '✕', 'error');
        }
    })
    .catch(() => toast('Network error.', '✕', 'error'))
    .finally(() => {
        btn.disabled = false;
        btn.textContent = id ? 'Save Changes' : 'Publish';
    });
}

function deleteAnnouncement(id, btn) {
    if (!confirm('Delete this announcement? Vendors will no longer see it.')) return;
    const card = document.querySelector(`.ann-admin-card[data-id="${id}"]`);
    if (card) { card.style.transition = 'opacity .3s, transform .3s'; card.style.opacity = '0'; card.style.transform = 'translateX(12px)'; }

    const fd = new FormData(); fd.append('action','delete_announcement'); fd.append('ann_id',id);
    fetch('process_admin.php', {method:'POST', headers:{'X-Requested-With':'XMLHttpRequest'}, body:fd})
    .then(r => r.json())
    .then(data => {
        if (data.success) { setTimeout(() => loadAnnouncements(), 350); toast(data.message, '🗑', 'warn'); }
        else { if(card){card.style.opacity='1';card.style.transform='';} toast(data.message||'Delete failed.','✕','error'); }
    });
}

function togglePin(id, newPinState, btn) {
    const fd = new FormData(); fd.append('action','toggle_pin'); fd.append('ann_id',id); fd.append('is_pinned',newPinState);
    fetch('process_admin.php', {method:'POST', headers:{'X-Requested-With':'XMLHttpRequest'}, body:fd})
    .then(r => r.json())
    .then(data => { if(data.success){loadAnnouncements();toast(data.message,'📌','success');}else toast(data.message||'Error','✕','error'); });
}

function escAttr(s) {
    return String(s||'').replace(/\\/g,'\\\\').replace(/'/g,"\\'").replace(/"/g,'&quot;').replace(/\n/g,' ');
}

// ── Vetting tab filter ────────────────────────────────
let currentVetTab = 'All';

function switchVetTab(el) {
    document.querySelectorAll('.vet-tab').forEach(t => t.classList.remove('active'));
    el.classList.add('active');
    currentVetTab = el.dataset.tab;
    filterVetting();
    const visible = [...document.querySelectorAll('.app-card:not([style*="display: none"])')].length;
    const sub = document.getElementById('vettingSubtitle');
    if(sub) sub.textContent = visible + ' application' + (visible!==1?'s':'') + (currentVetTab==='All'?'':' · '+currentVetTab);
}

function filterVetting() {
    const q       = (document.getElementById('vettingSearch')?.value||'').toLowerCase();
    const type    = (document.getElementById('typeFilter')?.value||'').toLowerCase();
    const evFilt  = (document.getElementById('eventFilter')?.value||'');

    document.querySelectorAll('.event-group').forEach(group => {
        const groupEvId = group.dataset.eventId || '';
        const evMatch   = !evFilt || groupEvId === evFilt;

        let visibleInGroup = 0;
        group.querySelectorAll('.app-card').forEach(card => {
            const tabMatch = currentVetTab==='All' || card.dataset.status===currentVetTab;
            const qMatch   = !q || card.dataset.vendor?.includes(q) || card.dataset.stall?.includes(q) || card.dataset.event?.includes(q);
            const tMatch   = !type || card.dataset.type===type;
            const show     = evMatch && tabMatch && qMatch && tMatch;
            card.style.display = show ? '' : 'none';
            if (show) visibleInGroup++;
        });

        // Hide entire group if no cards visible or event filter excludes it
        group.style.display = (evMatch && visibleInGroup > 0) ? '' : 'none';
    });

    // Update subtitle count
    const total = [...document.querySelectorAll('.app-card')].filter(c => c.style.display !== 'none').length;
    const sub = document.getElementById('vettingSubtitle');
    if(sub) sub.textContent = total + ' application' + (total!==1?'s':'') + (currentVetTab==='All'?'':' · '+currentVetTab);
}

function toggleEventGroup(headerEl) {
    const body = headerEl.nextElementSibling;
    const chevron = headerEl.querySelector('.event-group-chevron');
    const isOpen = body.style.display !== 'none';
    body.style.display = isOpen ? 'none' : '';
    headerEl.classList.toggle('collapsed', isOpen);
}

// ── User Management ───────────────────────────────────
let allUsers = [];

function loadUsers() {
    fetch('process_admin.php?action=get_users',{headers:{'X-Requested-With':'XMLHttpRequest'}})
    .then(r=>r.json()).then(data=>{
        if(!data.success)return;
        allUsers=data.data;
        document.getElementById('userSubtitle').textContent=allUsers.length+' registered users';
        renderUsers(allUsers);
    }).catch(e=>console.error(e));
}

function renderUsers(users){
    const tbody=document.getElementById('usersBody');
    if(!users.length){tbody.innerHTML='<tr><td colspan="8" style="text-align:center;padding:32px;color:var(--text-3);">No users found</td></tr>';return;}
    tbody.innerHTML=users.map(u=>`
        <tr class="ledger-row" id="user-row-${u.id}">
            <td class="td-mono">${u.id}</td>
            <td class="td-vendor">${escHtml(u.full_name)}</td>
            <td>${escHtml(u.email)}</td>
            <td>${escHtml(u.stall_name||'—')}</td>
            <td><span class="status-pill pill-${u.role}">${u.role}</span></td>
            <td><span class="status-pill pill-${u.is_active=='1'?'paid':'overdue'}">${u.is_active=='1'?'Active':'Inactive'}</span></td>
            <td>${u.created_at}</td>
            <td style="display:flex;gap:6px;flex-wrap:wrap">
                <button class="ledger-btn" onclick="toggleUserStatus(${u.id},${u.is_active},this)">${u.is_active=='1'?'Deactivate':'Activate'}</button>
                <button class="ledger-btn" onclick="toggleUserRole(${u.id},'${u.role}',this)">${u.role==='admin'?'Demote':'Make Admin'}</button>
                <button class="ledger-btn danger" onclick="deleteUser(${u.id},this)" style="color:var(--red)">Delete</button>
            </td>
        </tr>`).join('');
}

function filterUsers(q){
    const lq=q.toLowerCase();
    renderUsers(allUsers.filter(u=>u.full_name.toLowerCase().includes(lq)||u.email.toLowerCase().includes(lq)||(u.stall_name||'').toLowerCase().includes(lq)));
}

function toggleUserStatus(userId,cur,btn){
    const fd=new FormData();fd.append('action','toggle_user');fd.append('user_id',userId);fd.append('is_active',cur==1?0:1);
    fetch('process_admin.php',{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd})
    .then(r=>r.json()).then(data=>{if(data.success){showToast(data.message,'success');loadUsers();}else showToast(data.message||'Error','error');});
}

function toggleUserRole(userId,cur,btn){
    const fd=new FormData();fd.append('action','set_role');fd.append('user_id',userId);fd.append('role',cur==='admin'?'user':'admin');
    fetch('process_admin.php',{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd})
    .then(r=>r.json()).then(data=>{if(data.success){showToast(data.message,'success');loadUsers();}else showToast(data.message||'Error','error');});
}

function deleteUser(userId,btn){
    if(!confirm('Delete this user? Their applications will also be removed.'))return;
    const row=document.getElementById('user-row-'+userId);
    if(row){row.style.transition='opacity .3s';row.style.opacity='0';setTimeout(()=>row.remove(),300);}
    const fd=new FormData();fd.append('action','delete_user');fd.append('user_id',userId);
    fetch('process_admin.php',{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd})
    .then(r=>r.json()).then(data=>{showToast(data.success?'User deleted':data.message||'Error',data.success?'success':'error');if(data.success)loadUsers();});
}

function showToast(msg,type='success'){toast(msg,type==='success'?'✓':'✕',type);}
function escHtml(s){const d=document.createElement('div');d.textContent=s;return d.innerHTML;}

// ── Donation Edit ──────────────────────────────────────
function openDonationModal(id, vendor, type, amount, desc) {
    document.getElementById('donAppId').value = id;
    document.getElementById('donVendorName').textContent = 'Vendor: ' + vendor;
    document.getElementById('donTypeCash').checked = type !== 'item';
    document.getElementById('donTypeItem').checked = type === 'item';
    document.getElementById('donAmount').value = amount || '';
    document.getElementById('donItemDesc').value = desc || '';
    toggleDonType();
    document.getElementById('donModal').classList.add('open');
}
function closeDonModal(e) {
    if (e && e.target !== document.getElementById('donModal')) return;
    document.getElementById('donModal').classList.remove('open');
}
function toggleDonType() {
    const isItem = document.getElementById('donTypeItem').checked;
    document.getElementById('donCashGroup').style.display = isItem ? 'none' : '';
    document.getElementById('donItemGroup').style.display = isItem ? '' : 'none';
}
function saveDonation() {
    const id   = document.getElementById('donAppId').value;
    const type = document.querySelector('input[name="donType"]:checked').value;
    const amt  = document.getElementById('donAmount').value;
    const desc = document.getElementById('donItemDesc').value;
    const fd = new FormData();
    // Use record_donation — enforces Approved-first rule on server
    fd.append('action','record_donation'); fd.append('app_id',id);
    fd.append('donation_type',type); fd.append('donation_amount',amt);
    fd.append('donation_item_desc',desc);
    fetch('process_admin.php',{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd})
    .then(r=>r.json()).then(d=>{
        if (d.success) {
            document.getElementById('donModal').classList.remove('open');
            // Update ledger cells
            const donEl  = document.getElementById('don-'+id);
            const pillEl = document.getElementById('pill-'+id);
            const row    = document.querySelector(`.ledger-row[data-id="${id}"]`);
            if (donEl) donEl.innerHTML = type==='item'
                ? '📦 '+(desc||'Item donation')
                : '<span class="td-paid">₱'+parseFloat(amt||0).toLocaleString('en-PH',{minimumFractionDigits:2})+'</span>';
            if (pillEl) { pillEl.textContent = 'Donated'; pillEl.className = 'status-pill pill-donated'; }
            if (row) row.dataset.status = 'donated';
            // Update vetting card if it exists (in-page, same tab session)
            _refreshVetCard(id, 'Donated');
            toast(d.message,'💰','success');
        } else toast(d.message||'Error saving donation','✕','error');
    })
    .catch(()=>toast('Network error','✕','error'));
}

// ── Stall Setup (from ledger) ──────────────────────────
function confirmStallSetup(id, btn) {
    if (!confirm('Confirm this vendor\'s stall is physically set up and ready?')) return;
    _doStallSetup(id, btn, 'ledger');
}
// ── Stall Setup (from vetting card) ───────────────────
function confirmStallSetupVet(id, btn) {
    if (!confirm('Confirm this vendor\'s stall is physically set up and ready?')) return;
    _doStallSetup(id, btn, 'vet');
}
function _doStallSetup(id, btn, source) {
    const fd = new FormData(); fd.append('action','mark_stall_setup'); fd.append('app_id',id);
    fetch('process_admin.php',{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd})
    .then(r=>r.json()).then(d=>{
        if (d.success) {
            // Update ledger pill
            const pillEl = document.getElementById('pill-'+id);
            if (pillEl) { pillEl.textContent = 'Stall Setup'; pillEl.className = 'status-pill pill-stall-setup'; }
            // Swap button → label
            if (source === 'ledger') {
                const actCell = btn.closest('td');
                if (actCell) {
                    // Remove setup + edit buttons, leave Event button
                    actCell.querySelectorAll('.ledger-btn:not([title])').forEach(b=>b.remove());
                    const check = document.createElement('span');
                    check.className='paid-check'; check.style.color='var(--teal)'; check.textContent='✓ Complete';
                    actCell.appendChild(check);
                }
            }
            const row = document.querySelector(`.ledger-row[data-id="${id}"]`);
            if (row) row.dataset.status = 'stall-setup';
            _refreshVetCard(id, 'Stall Setup');
            toast(d.message,'🏪','success');
        } else toast(d.message||'Error','✕','error');
    })
    .catch(()=>toast('Network error','✕','error'));
}

// Refresh vetting card badge after donation/stall-setup changes
function _refreshVetCard(id, newStatus) {
    const card = document.querySelector(`.app-card[data-id="${id}"]`);
    if (!card) return;
    card.dataset.status = newStatus;
    const actDiv = card.querySelector('.app-actions');
    if (!actDiv) return;
    // Remove action buttons; insert updated badges
    const badges = {
        'Donated':    `<span class="app-status-badge status-approved">✓ Approved</span><span class="app-status-badge" style="background:rgba(150,112,56,0.1);color:var(--gold)">💰 Donated</span>`,
        'Stall Setup':`<span class="app-status-badge status-approved">✓ Approved</span><span class="app-status-badge" style="background:rgba(150,112,56,0.1);color:var(--gold)">💰 Donated</span><span class="app-status-badge" style="background:rgba(53,120,112,0.1);color:var(--teal)">🏪 Stall Set Up</span>`,
    };
    if (badges[newStatus]) actDiv.innerHTML = badges[newStatus];
}

// ── Assign Event ───────────────────────────────────────
let _evList = [];
function openAssignEventModal(appId, currentEvent) {
    document.getElementById('assignAppId').value = appId;
    document.getElementById('assignCurrentEv').textContent = 'Current: ' + currentEvent;
    document.getElementById('assignEvModal').classList.add('open');
    loadEventsForSelect();
}
function closeAssignEvModal(e) {
    if (e && e.target !== document.getElementById('assignEvModal')) return;
    document.getElementById('assignEvModal').classList.remove('open');
}
function loadEventsForSelect() {
    fetch('process_admin.php?action=get_events',{headers:{'X-Requested-With':'XMLHttpRequest'}})
    .then(r=>r.json()).then(d=>{
        if (!d.success) return;
        _evList = d.data;
        const sel = document.getElementById('assignEventSelect');
        sel.innerHTML = '<option value="">— No event —</option>' +
            d.data.map(e=>`<option value="${e.id}">${escHtml(e.title)}${e.start_date?' ('+e.start_date+')':''}</option>`).join('');
    });
}
function saveAssignEvent() {
    const appId   = document.getElementById('assignAppId').value;
    const eventId = document.getElementById('assignEventSelect').value;
    const fd = new FormData(); fd.append('action','assign_event'); fd.append('app_id',appId); fd.append('event_id',eventId||0);
    fetch('process_admin.php',{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd})
    .then(r=>r.json()).then(d=>{
        if (d.success) {
            document.getElementById('assignEvModal').classList.remove('open');
            const evEl = document.getElementById('ev-'+appId);
            if (evEl) evEl.textContent = d.event_title||'—';
            toast(d.message,'📅','success');
        } else toast(d.message||'Error','✕','error');
    });
}

// ── Events CRUD ────────────────────────────────────────
function loadEvents() {
    fetch('process_admin.php?action=get_events',{headers:{'X-Requested-With':'XMLHttpRequest'}})
    .then(r=>r.json()).then(d=>{
        const list = document.getElementById('eventsList');
        if (!d.success || !d.data.length) {
            list.innerHTML = '<div class="vet-empty"><div style="font-size:32px;opacity:.4">📅</div><p>No events yet. Create one!</p></div>'; return;
        }
        document.getElementById('evSubtitle').textContent = d.data.length+' event'+(d.data.length!==1?'s':'');
        list.innerHTML = d.data.map((e,i) => `
            <div class="ann-admin-card" style="animation-delay:${i*0.04}s">
                <div class="ann-admin-icon" style="font-size:24px">📅</div>
                <div class="ann-admin-body">
                    <div class="ann-admin-title">
                        ${e.is_active=='1'?'<span class="ann-pin-chip" style="background:var(--teal-dim);color:var(--teal);border-color:var(--teal)">Active</span>':'<span class="ann-pin-chip" style="background:var(--red-dim);color:var(--red);border-color:var(--red)">Inactive</span>'}
                        ${escHtml(e.title)}
                    </div>
                    <div class="ann-admin-text">
                        ${e.start_date?'📆 '+e.start_date+(e.end_date?' – '+e.end_date:''):'No dates set'}
                        ${e.location?' &nbsp;·&nbsp; 📍 '+escHtml(e.location):''}
                        ${e.capacity?' &nbsp;·&nbsp; 👥 '+e.capacity+' vendors':''}
                    </div>
                    <div class="ann-admin-meta">${e.description?escHtml(e.description.substring(0,100)):''}</div>
                </div>
                <div class="ann-admin-actions">
                    <button class="act-btn act-info" onclick="openEventModal(${e.id},'${escAttr(e.title)}','${escAttr(e.description||'')}','${e.start_date||''}','${e.end_date||''}','${escAttr(e.location||'')}',${e.capacity||0},${e.is_active})">✎ Edit</button>
                    <button class="act-btn act-reject" onclick="deleteEvent(${e.id},this)">🗑 Delete</button>
                </div>
            </div>`).join('');
    });
}
function openEventModal(id,title,desc,start,end,loc,cap,active) {
    document.getElementById('evEditId').value    = id    || '';
    document.getElementById('evTitle').value     = title || '';
    document.getElementById('evDesc').value      = desc  || '';
    document.getElementById('evStart').value     = start || '';
    document.getElementById('evEnd').value       = end   || '';
    document.getElementById('evLocation').value  = loc   || '';
    document.getElementById('evCapacity').value  = cap   || '';
    document.getElementById('evActive').checked  = active===undefined ? true : (active==1||active===true);
    document.getElementById('eventModalTitle').textContent = id ? 'Edit Event' : 'New Event';
    document.getElementById('evSaveBtn').textContent = id ? 'Save Changes' : 'Create Event';
    document.getElementById('eventModal').classList.add('open');
    setTimeout(()=>document.getElementById('evTitle').focus(),100);
}
function closeEventModal(e) {
    if (e && e.target !== document.getElementById('eventModal')) return;
    document.getElementById('eventModal').classList.remove('open');
}
function saveEvent() {
    const id = document.getElementById('evEditId').value;
    const fd = new FormData();
    fd.append('action','save_event');
    fd.append('event_id',   id);
    fd.append('title',      document.getElementById('evTitle').value.trim());
    fd.append('description',document.getElementById('evDesc').value.trim());
    fd.append('start_date', document.getElementById('evStart').value);
    fd.append('end_date',   document.getElementById('evEnd').value);
    fd.append('location',   document.getElementById('evLocation').value.trim());
    fd.append('capacity',   document.getElementById('evCapacity').value||0);
    fd.append('is_active',  document.getElementById('evActive').checked?1:0);
    if (!fd.get('title')) { toast('Event title is required.','⚠','warn'); return; }
    document.getElementById('evSaveBtn').disabled = true;
    fetch('process_admin.php',{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd})
    .then(r=>r.json()).then(d=>{
        if (d.success) { closeEventModal(); loadEvents(); toast(d.message,'✓','success'); }
        else toast(d.message||'Error','✕','error');
    }).finally(()=>document.getElementById('evSaveBtn').disabled=false);
}
function deleteEvent(id, btn) {
    if (!confirm('Delete this event? This will not remove existing applications.')) return;
    const card = btn.closest('.ann-admin-card');
    if (card) { card.style.transition='opacity .3s'; card.style.opacity='0'; }
    const fd = new FormData(); fd.append('action','delete_event'); fd.append('event_id',id);
    fetch('process_admin.php',{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd})
    .then(r=>r.json()).then(d=>{
        if (d.success) { setTimeout(()=>loadEvents(),300); toast(d.message,'🗑','warn'); }
        else { if(card) card.style.opacity='1'; toast(d.message||'Error','✕','error'); }
    });
}

// ── Donation Summary (Transparency) ───────────────────
function loadDonationSummary() {
    const panel = document.getElementById('donSummaryPanel');
    panel.style.display = panel.style.display==='none' ? '' : 'none';
    if (panel.style.display === 'none') return;
    document.getElementById('donSummaryTable').innerHTML = '<p style="color:var(--text-3);padding:12px">Loading…</p>';
    fetch('process_admin.php?action=get_donation_summary',{headers:{'X-Requested-With':'XMLHttpRequest'}})
    .then(r=>r.json()).then(d=>{
        if (!d.success) return;
        document.getElementById('dsCash').textContent   = '₱'+parseFloat(d.total_cash).toLocaleString('en-PH');
        document.getElementById('dsItems').textContent  = d.total_items;
        document.getElementById('dsDonors').textContent = d.total_donors;
        if (!d.data.length) { document.getElementById('donSummaryTable').innerHTML='<p style="color:var(--text-3);padding:12px">No completed donations yet.</p>'; return; }
        document.getElementById('donSummaryTable').innerHTML = `
        <table class="ledger-table">
            <thead><tr><th>Vendor</th><th>Stall</th><th>Event</th><th>Type</th><th>Value</th><th>Status</th><th>Date</th></tr></thead>
            <tbody>${d.data.map(r=>`<tr class="ledger-row">
                <td class="td-vendor">${escHtml(r.vendor)}</td>
                <td>${escHtml(r.stall||'—')}</td>
                <td>${escHtml(r.event)}</td>
                <td><span class="status-pill ${r.donation_type==='item'?'pill-stall-setup':'pill-donated'}">${r.donation_type==='item'?'📦 Item':'💰 Cash'}</span></td>
                <td class="td-mono td-paid">${r.donation_type==='item'?escHtml(r.donation_item_desc||'Item'):'₱'+parseFloat(r.donation_amount||0).toLocaleString('en-PH')}</td>
                <td><span class="status-pill pill-donated">${escHtml(r.status)}</span></td>
                <td style="font-size:11px;color:var(--text-3)">${r.updated}</td>
            </tr>`).join('')}</tbody>
        </table>`;
    });
}

// Hook section switch to load events
document.querySelectorAll('.anav-item').forEach(a=>{
    a.addEventListener('click',()=>{
        const sec=a.getAttribute('onclick')?.match(/'(\w+)'/)?.[1];
        if(sec==='users')setTimeout(loadUsers,100);
        if(sec==='announcements')setTimeout(loadAnnouncements,100);
        if(sec==='events')setTimeout(loadEvents,100);
    });
});



// ── Form Edit ──────────────────────────────────────────
let editingFormId = null;

function loadFormForEdit(formId) {
    fetch('process_admin.php?action=get_form_fields&form_id=' + formId, {
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(r => r.json())
    .then(data => {
        if (!data.success) { toast(data.message || 'Could not load form', '✕', 'error'); return; }
        const f = data.data;
        editingFormId = formId;
        document.getElementById('fbEditingId').value = formId;

        // Populate meta fields
        const titleEl = document.getElementById('fbFormTitle');
        const evEl    = document.getElementById('fbEventTarget');
        const dlEl    = document.getElementById('fbDeadline');
        if (titleEl) titleEl.value = f.title || '';
        if (evEl)    evEl.value    = f.event_target || '';
        if (dlEl)    dlEl.value    = f.deadline ? f.deadline.substring(0,10) : '';

        // Load fields into builder
        fbFields = Array.isArray(f.fields) ? f.fields : [];
        selectedField = null;
        renderCanvas();

        // Show edit mode UI
        document.getElementById('fbEditBadge').style.display = '';
        document.getElementById('fbCancelEditBtn').style.display = '';
        document.getElementById('fbSaveBtnTxt').textContent = 'Update Form';
        document.getElementById('fbModeLabel').textContent = 'Editing: ' + f.title;

        // Switch to form builder section
        document.querySelectorAll('.admin-section').forEach(s => s.classList.remove('active'));
        document.getElementById('sec-formbuilder').classList.add('active');
        document.querySelectorAll('.anav-item').forEach(a => {
            a.classList.toggle('active', a.getAttribute('onclick')?.includes("'formbuilder'"));
        });
        document.getElementById('topbarTitle').textContent = 'Form Builder';

        toast('Form loaded for editing', '✎', 'warn');
    })
    .catch(() => toast('Network error loading form', '✕', 'error'));
}

function cancelEditForm() {
    editingFormId = null;
    document.getElementById('fbEditingId').value = '';
    document.getElementById('fbEditBadge').style.display = 'none';
    document.getElementById('fbCancelEditBtn').style.display = 'none';
    document.getElementById('fbSaveBtnTxt').textContent = 'Save Form';
    document.getElementById('fbModeLabel').textContent = 'Drag fields to build application forms for events';
    fbFields = [];
    selectedField = null;
    renderCanvas();
    const titleEl = document.getElementById('fbFormTitle');
    if (titleEl) titleEl.value = 'Stall Application Form';
}

// ── Dark Mode ────────────────────────────────────────────
function toggleAdminDark() {
    const isDark = document.documentElement.classList.toggle('dark');
    localStorage.setItem('sp_admin_theme', isDark ? 'dark' : 'light');
}
// Sync toggle button state on load
(function syncThemeBtn(){
    // Button visual is handled purely by html.dark CSS class — no JS state needed
})();
// ESC closes all modals
document.addEventListener('keydown', e => {
    if (e.key === 'Escape') {
        ['annModal','vetModal','previewModal','donModal','assignEvModal','eventModal'].forEach(id=>{
            document.getElementById(id)?.classList.remove('open');
        });
    }
});
</script>
<style>
/* ── Event Groups ── */
.event-group { margin-bottom: 10px; }
.event-group-header {
    display: flex; align-items: center; gap: 10px;
    padding: 10px 14px;
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    cursor: pointer;
    user-select: none;
    transition: background var(--t);
}
.event-group-header:hover { background: var(--rose-dim); }
.event-group-header.collapsed + .event-group-body { display: none; }
.event-group-title {
    display: flex; align-items: center; gap: 6px;
    font-family: 'Cormorant Garamond', serif;
    font-size: 16px; font-weight: 600;
    color: var(--rose-deep); flex: 1;
}
html.dark .event-group-title { color: var(--rose); }
.event-group-meta { display: flex; align-items: center; gap: 6px; flex-wrap: wrap; font-size: 11px; color: var(--text-3); }
.event-group-chevron { transition: transform 0.25s ease; line-height: 0; color: var(--text-3); }
.event-group-header.collapsed .event-group-chevron { transform: rotate(-90deg); }
.event-group-body { padding: 8px 0 4px 0; display: flex; flex-direction: column; gap: 8px; }
.eg-pill { padding: 2px 8px; border-radius: 20px; font-size: 10px; font-weight: 600; }
/* Form builder published list edit button */
.pf-edit-btn {
    padding: 4px 10px; border-radius: 6px; font-size: 12px; cursor: pointer;
    background: rgba(150,112,56,0.1); color: var(--gold);
    border: 1px solid rgba(150,112,56,0.25);
    transition: background var(--t);
}
.pf-edit-btn:hover { background: rgba(150,112,56,0.2); }
</style>
<script src="admin.js"></script>
</body>
</html>
