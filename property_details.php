<?php
session_start();
include 'db.php';

// 1. GET PROPERTY ID
if (!isset($_GET['id'])) { header("Location: listings.php"); exit(); }
$prop_id = intval($_GET['id']);

// 2. FETCH PROPERTY & LANDLORD
$sql = "SELECT p.*, u.first_name, u.last_name, u.profile_pic 
        FROM properties p 
        JOIN users u ON p.landlord_id = u.id 
        WHERE p.id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $prop_id);
$stmt->execute();
$prop = $stmt->get_result()->fetch_assoc();

if (!$prop) die("Property not found.");

// 3. FETCH ROOMS
$rooms_q = $conn->query("SELECT * FROM room_units WHERE property_id = $prop_id");
$rooms = [];
while ($r = $rooms_q->fetch_assoc()) { $rooms[] = $r; }

// 4. PARSE DATA
$images = !empty($prop['images']) ? json_decode($prop['images'], true) : [];
$main_image = !empty($images[0]) ? "assets/uploads/rooms/".$images[0] : "assets/house.jpg";
$js_prop_imgs = json_encode($images);
$amenities = !empty($prop['inclusions']) ? explode(',', $prop['inclusions']) : [];
$addons = !empty($prop['paid_addons']) ? explode(',', $prop['paid_addons']) : [];
$landlord_img = !empty($prop['profile_pic']) ? "assets/uploads/".$prop['profile_pic'] : "assets/default.jpg";

// LOGIC FOR STATUS BADGE
$p_stat = !empty($prop['status']) ? $prop['status'] : 'Accepting';
$badge_bg = '#198754'; $badge_color = '#fff';
if ($p_stat == 'Full') { $badge_bg = '#dc3545'; }
elseif ($p_stat == 'Renovating') { $badge_bg = '#ffc107'; $badge_color = '#000'; }
elseif ($p_stat == 'Closed') { $badge_bg = '#6c757d'; }

// IF NOT ACCEPTING OR ACTIVE, DISABLE THE BOOKING BUTTONS (FIX APPLIED HERE)
$can_book = ($p_stat == 'Accepting' || $p_stat == 'Active');
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <title><?php echo htmlspecialchars($prop['title']); ?> - Details</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        :root { --bg-dark: #0f0f0f; --bg-card: #1a1a1a; --accent-orange: #ff9000; }
        body { background: var(--bg-dark); color: white; font-family: sans-serif; padding-bottom: 80px; }

        /* GALLERY & CARDS */
        .gallery-header { position: relative; height: 400px; margin-bottom: 30px; }
        .main-img-display { width: 100%; height: 100%; object-fit: cover; border-radius: 16px; cursor: pointer; filter: brightness(0.9); transition: 0.3s; }
        .main-img-display:hover { filter: brightness(1); }
        .view-all-btn { position: absolute; bottom: 20px; right: 20px; background: white; color: black; padding: 8px 16px; border-radius: 8px; font-weight: 700; cursor: pointer; border: none; font-size: 14px; box-shadow: 0 4px 10px rgba(0,0,0,0.5); }
        .detail-card { background: var(--bg-card); border: 1px solid #333; border-radius: 16px; padding: 30px; margin-bottom: 20px; }
        
        /* PILLS & CONTACTS */
        .amenity-pill { background: #222; border: 1px solid #333; padding: 8px 16px; border-radius: 30px; font-size: 13px; color: #ccc; display: inline-flex; align-items: center; gap: 8px; margin: 0 5px 10px 0; }
        .landlord-card { background: #161616; border: 1px solid #333; border-radius: 16px; padding: 25px; position: sticky; top: 20px; }
        .contact-item { display: flex; align-items: center; gap: 15px; margin-bottom: 12px; padding: 12px; background: #222; border-radius: 8px; border: 1px solid #333; transition: 0.2s; }
        .contact-item:hover { border-color: #555; background: #2a2a2a; }
        .contact-label { display: block; font-size: 10px; text-transform: uppercase; color: #888; font-weight: 700; }
        .contact-value { font-size: 13px; font-weight: 600; color: white; text-decoration: none; word-break: break-all; }

        /* ROOM LIST */
        .room-item { background: #222; border: 1px solid #333; border-radius: 12px; padding: 15px; margin-bottom: 15px; display: flex; align-items: center; gap: 20px; }
        .room-thumb { width: 120px; height: 90px; border-radius: 8px; object-fit: cover; cursor: pointer; background: #000; transition: 0.2s; }
        .room-thumb:hover { opacity: 0.8; }
        .btn-book { background: var(--accent-orange); color: black; font-weight: 800; border: none; padding: 8px 20px; border-radius: 8px; text-decoration: none; font-size: 14px; cursor: pointer; transition: 0.2s; }
        .btn-book:hover { background: #e08e00; }
        .btn-photos { background: #333; color: white; font-size: 11px; border: 1px solid #444; padding: 4px 10px; border-radius: 4px; cursor: pointer; margin-top: 5px; display: inline-block; }
        .price-tag { text-align: right; }
        .price-main { font-size: 24px; font-weight: 800; color: #fff; margin: 0; }
        .price-sub { font-size: 13px; color: #888; display: block; }

        /* MODAL STYLES */
        .modal-content { background-color: #1a1a1a; border: 1px solid #333; color: white; }
        .form-control { background: #222; border: 1px solid #333; color: white; }
        .form-control:focus { background: #222; color: white; border-color: var(--accent-orange); box-shadow: none; }
    </style>
</head>
<body>

<div class="container mt-4">
    <a href="listings.php" class="text-secondary text-decoration-none fw-bold mb-3 d-inline-block"><i class="bi bi-arrow-left"></i> Back to Listings</a>

    <div class="gallery-header">
        <img src="<?php echo $main_image; ?>" class="main-img-display" onclick='openGallery(<?php echo $js_prop_imgs; ?>)'>
        <button class="view-all-btn" onclick='openGallery(<?php echo $js_prop_imgs; ?>)'><i class="bi bi-grid-3x3-gap-fill me-2"></i> View All Photos</button>
    </div>

    <div class="row">
        <div class="col-lg-8">
            <div class="detail-card">
                <div class="d-flex justify-content-between align-items-start mb-3">
                    <div>
                        <h2 class="fw-bold mb-1">
                            <?php echo htmlspecialchars($prop['title']); ?>
                            <span class="badge ms-2 align-middle" style="background-color: <?php echo $badge_bg; ?>; color: <?php echo $badge_color; ?>; font-size: 12px; transform: translateY(-4px);">
                                <?php echo htmlspecialchars($p_stat); ?>
                            </span>
                        </h2>
                        <p class="text-secondary mb-0"><i class="bi bi-geo-alt text-warning"></i> <?php echo htmlspecialchars($prop['location']); ?></p>
                    </div>
                    <div class="price-tag">
                        <?php if($prop['price'] > 0): ?>
                        <div class="mb-2"><h3 class="price-main text-success">₱<?php echo number_format($prop['price']); ?></h3><span class="price-sub">Whole Room / Month</span></div>
                        <?php endif; ?>
                        <?php if($prop['price_shared'] > 0): ?>
                        <div><h3 class="price-main text-info">₱<?php echo number_format($prop['price_shared']); ?></h3><span class="price-sub">Per Head / Month</span></div>
                        <?php endif; ?>
                    </div>
                </div>
                <hr class="border-secondary">
                <h5 class="fw-bold mt-4">About this place</h5>
                <p class="text-light opacity-75"><?php echo nl2br(htmlspecialchars($prop['description'])); ?></p>
            </div>

            <div class="detail-card">
                <h5 class="fw-bold mb-4">Amenities & Inclusions</h5>
                <div class="mb-3">
                    <span class="d-block text-uppercase text-secondary fw-bold small mb-2 text-success">Free Inclusions</span>
                    <?php if(!empty($amenities)): foreach($amenities as $am) echo "<div class='amenity-pill'><i class='bi bi-check-circle-fill text-success'></i> ".trim($am)."</div>"; else: echo "<span class='text-secondary small fst-italic'>None listed.</span>"; endif; ?>
                </div>
                <div>
                    <span class="d-block text-uppercase text-secondary fw-bold small mb-2 text-info">Paid Add-ons</span>
                    <?php if(!empty($addons)): foreach($addons as $ad) echo "<div class='amenity-pill' style='border-color:#444;'><i class='bi bi-plus-circle-fill text-info'></i> ".trim($ad)."</div>"; else: echo "<span class='text-secondary small fst-italic'>None listed.</span>"; endif; ?>
                </div>
            </div>

            <div class="detail-card">
                <h5 class="fw-bold mb-4">Available Rooms</h5>
                
                <?php if(!$can_book): ?>
                    <div class="alert alert-danger border-0 rounded-3 mb-4 small fw-bold">
                        <i class="bi bi-info-circle-fill me-2"></i> This property is currently marked as <?php echo htmlspecialchars($p_stat); ?>. You cannot book rooms at this time.
                    </div>
                <?php endif; ?>

                <?php if (count($rooms) > 0): ?>
                    <?php foreach ($rooms as $room): 
                        $r_imgs = (strpos($room['room_image'], '[') === 0) ? json_decode($room['room_image'], true) : [$room['room_image']];
                        $r_thumb = !empty($r_imgs[0]) ? "assets/uploads/rooms/".$r_imgs[0] : "assets/default_room.jpg";
                        $js_room_imgs = json_encode($r_imgs);
                        $is_full = $room['occupied_beds'] >= $room['total_beds'];
                        $slots = $room['total_beds'] - $room['occupied_beds'];
                    ?>
                    <div class="room-item">
                        <div class="text-center">
                            <img src="<?php echo $r_thumb; ?>" class="room-thumb" onclick='openGallery(<?php echo $js_room_imgs; ?>)'>
                            <div class="btn-photos" onclick='openGallery(<?php echo $js_room_imgs; ?>)'><i class="bi bi-images"></i> <?php echo count($r_imgs); ?> Photos</div>
                        </div>
                        <div style="flex-grow: 1;">
                            <h5 class="fw-bold m-0"><?php echo htmlspecialchars($room['room_name']); ?></h5>
                            <div class="small text-secondary mt-1">
                                <?php echo $is_full ? '<span class="text-danger">Full</span>' : '<span class="text-success">'.$slots.' beds available</span>'; ?>
                                &bull; Cap: <?php echo $room['total_beds']; ?>
                            </div>
                        </div>
                        
                        <?php if(!$can_book): ?>
                             <button class="btn btn-book" style="background:#333; color:#666;" disabled>Unavailable</button>
                        <?php elseif(!$is_full): ?>
                            <button class="btn btn-book" onclick="openBookingModal(<?php echo $room['id']; ?>, '<?php echo htmlspecialchars($room['room_name']); ?>')">Book Now</button>
                        <?php else: ?>
                            <button class="btn btn-book" style="background:#333; color:#666;" disabled>Full</button>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <p class="text-secondary">No rooms listed.</p>
                <?php endif; ?>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="landlord-card">
                <div class="d-flex align-items-center mb-4">
                    <img src="<?php echo $landlord_img; ?>" style="width:60px; height:60px; border-radius:50%; object-fit:cover;">
                    <div class="ms-3">
                        <div class="small text-secondary fw-bold">HOSTED BY</div>
                        <h5 class="fw-bold m-0"><?php echo htmlspecialchars($prop['first_name'] . ' ' . $prop['last_name']); ?></h5>
                    </div>
                </div>
                <div class="contact-item">
                    <div class="contact-icon"><i class="bi bi-facebook text-primary"></i></div>
                    <div><span class="contact-label">Facebook</span><a href="#" class="contact-value" onclick="return false;"><?php echo !empty($prop['contact_facebook']) ? htmlspecialchars($prop['contact_facebook']) : 'N/A'; ?></a></div>
                </div>
                <div class="contact-item">
                    <div class="contact-icon"><i class="bi bi-telephone-fill text-success"></i></div>
                    <div><span class="contact-label">Phone</span><span class="contact-value"><?php echo !empty($prop['contact_phone']) ? htmlspecialchars($prop['contact_phone']) : 'N/A'; ?></span></div>
                </div>
                <div class="contact-item">
                    <div class="contact-icon"><i class="bi bi-envelope-fill text-danger"></i></div>
                    <div><span class="contact-label">Email</span><span class="contact-value"><?php echo !empty($prop['contact_email']) ? htmlspecialchars($prop['contact_email']) : 'N/A'; ?></span></div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="galleryModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content bg-transparent border-0 position-relative">
            <button type="button" class="btn-close btn-close-white position-absolute top-0 end-0 m-3 z-3" data-bs-dismiss="modal"></button>
            <div id="carouselGallery" class="carousel slide">
                <div class="carousel-inner" id="galleryInner"></div>
                <button class="carousel-control-prev" type="button" data-bs-target="#carouselGallery" data-bs-slide="prev"><span class="carousel-control-prev-icon"></span></button>
                <button class="carousel-control-next" type="button" data-bs-target="#carouselGallery" data-bs-slide="next"><span class="carousel-control-next-icon"></span></button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="bookingModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header border-secondary">
                <h5 class="modal-title fw-bold">Confirm Booking</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form action="apply_action.php" method="POST">
                <div class="modal-body">
                    <input type="hidden" name="property_id" value="<?php echo $prop_id; ?>">
                    <input type="hidden" name="room_id" id="modal_room_id">
                    
                    <p class="text-secondary">You are booking: <strong id="modal_room_name" class="text-white"></strong></p>
                    
                    <div class="mb-3">
                        <label class="small text-secondary mb-1">Move-in Date</label>
                        <input type="date" name="move_in_date" class="form-control" required min="<?php echo date('Y-m-d'); ?>">
                    </div>
                    
                    <div class="mb-3">
                        <label class="small text-secondary mb-1">Message to Landlord (Optional)</label>
                        <textarea name="message" class="form-control" rows="3" placeholder="Hi, I'm interested in this room..."></textarea>
                    </div>
                    
                    <div class="small text-warning fst-italic">
                        <i class="bi bi-info-circle"></i> This booking will be "Pending" until the landlord approves it. You can cancel it anytime before approval.
                    </div>
                </div>
                <div class="modal-footer border-secondary">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="submit_application" class="btn btn-warning fw-bold">Confirm Booking</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Open Gallery
function openGallery(images) {
    const inner = document.getElementById('galleryInner');
    inner.innerHTML = ''; 
    if(!images || images.length === 0) return;
    images.forEach((img, index) => {
        const active = index === 0 ? 'active' : '';
        inner.insertAdjacentHTML('beforeend', `<div class="carousel-item ${active}"><img src="assets/uploads/rooms/${img}" class="d-block w-100" style="border-radius:12px; max-height:80vh; object-fit:contain; background:black;"></div>`);
    });
    new bootstrap.Modal(document.getElementById('galleryModal')).show();
}

// Open Booking Modal
function openBookingModal(roomId, roomName) {
    document.getElementById('modal_room_id').value = roomId;
    document.getElementById('modal_room_name').innerText = roomName;
    new bootstrap.Modal(document.getElementById('bookingModal')).show();
}
</script>
</body>
</html>