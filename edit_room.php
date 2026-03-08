<?php
session_start();
include 'db.php';

// 1. SECURITY
if (!isset($_SESSION['user'])) { header("Location: login.php"); exit(); }
$username = $_SESSION['user'];
$user = $conn->query("SELECT * FROM users WHERE username='$username'")->fetch_assoc();
$role = $user['role'];
$user_id = $user['id'];

if (!isset($_GET['id'])) { 
    if($role == 'Admin') header("Location: admin_properties.php");
    else header("Location: dashboard_landlord.php");
    exit(); 
}
$prop_id = intval($_GET['id']);

// 2. FETCH DATA
$stmt = $conn->prepare("SELECT * FROM properties WHERE id = ?");
$stmt->bind_param("i", $prop_id);
$stmt->execute();
$prop = $stmt->get_result()->fetch_assoc();

if (!$prop) die("Property not found.");
if ($role == 'Landlord' && $prop['landlord_id'] != $user_id) die("Unauthorized.");

$rooms_q = $conn->query("SELECT * FROM room_units WHERE property_id = $prop_id");
$existing_rooms = [];
while ($r = $rooms_q->fetch_assoc()) { $existing_rooms[] = $r; }

// 3. HANDLE UPDATE
if (isset($_POST['update_room'])) {
    // A. Images
    $images_json = $prop['images'];
    if (!empty($_FILES['room_images']['name'][0])) {
        $image_paths = [];
        $target_dir = "assets/uploads/rooms/";
        if (!file_exists($target_dir)) { mkdir($target_dir, 0777, true); }
        foreach ($_FILES['room_images']['name'] as $key => $val) {
            $filename = time() . "_house_" . basename($_FILES['room_images']['name'][$key]);
            move_uploaded_file($_FILES['room_images']['tmp_name'][$key], $target_dir . $filename);
            $image_paths[] = $filename;
        }
        $images_json = json_encode($image_paths);
    }

    // B. Data
    $inclusions = isset($_POST['inclusions']) ? implode(",", $_POST['inclusions']) : "";
    $paid_addons = isset($_POST['paid_addons']) ? implode(",", $_POST['paid_addons']) : "";
    $price = !empty($_POST['price']) ? $_POST['price'] : 0;
    $price_shared = !empty($_POST['price_shared']) ? $_POST['price_shared'] : 0;
    
    // Prevent undefined index warnings if wifi/water aren't in the form
    $wifi_type = isset($_POST['wifi_type']) ? $_POST['wifi_type'] : '';
    $water_type = isset($_POST['water_type']) ? $_POST['water_type'] : '';

    $stmt = $conn->prepare("UPDATE properties SET title=?, location=?, description=?, contact_phone=?, contact_facebook=?, contact_email=?, wifi_type=?, water_type=?, price=?, price_shared=?, inclusions=?, paid_addons=?, images=?, status=? WHERE id=?");
    $stmt->bind_param("ssssssssddssssi", $_POST['title'], $_POST['location'], $_POST['description'], $_POST['contact_phone'], $_POST['contact_facebook'], $_POST['contact_email'], $wifi_type, $water_type, $price, $price_shared, $inclusions, $paid_addons, $images_json, $_POST['status'], $prop_id);
    $stmt->execute();

    // C. Rooms
    $db_room_ids = [];
    $get_ids = $conn->query("SELECT id FROM room_units WHERE property_id = $prop_id");
    while($row = $get_ids->fetch_assoc()) { $db_room_ids[] = $row['id']; }
    $submitted_ids = [];

    if (isset($_POST['room_names'])) {
        $names = $_POST['room_names']; $ids = $_POST['room_ids'];
        $beds = $_POST['room_beds']; $occ = $_POST['room_occupied'];
        $old_imgs = $_POST['existing_room_imgs'];
        $target_dir = "assets/uploads/rooms/";

        for ($i = 0; $i < count($names); $i++) {
            $r_id = intval($ids[$i]);
            $final_img = $old_imgs[$i];
            if (!empty($_FILES['room_specific_img']['name'][$i])) {
                $new_fn = time() . "_room_" . $i . "_" . basename($_FILES['room_specific_img']['name'][$i]);
                if (move_uploaded_file($_FILES['room_specific_img']['tmp_name'][$i], $target_dir . $new_fn)) { $final_img = $new_fn; }
            }
            $safe_occ = ($occ[$i] > $beds[$i]) ? $beds[$i] : $occ[$i];

            if ($r_id > 0 && in_array($r_id, $db_room_ids)) {
                $up = $conn->prepare("UPDATE room_units SET room_name=?, total_beds=?, occupied_beds=?, room_image=? WHERE id=?");
                $up->bind_param("siisi", $names[$i], $beds[$i], $safe_occ, $final_img, $r_id);
                $up->execute(); $submitted_ids[] = $r_id;
            } else {
                $in = $conn->prepare("INSERT INTO room_units (property_id, room_name, total_beds, occupied_beds, room_image) VALUES (?, ?, ?, ?, ?)");
                $in->bind_param("isiis", $prop_id, $names[$i], $beds[$i], $safe_occ, $final_img);
                $in->execute();
            }
        }
    }
    // Delete removed rooms
    $ids_to_delete = array_diff($db_room_ids, $submitted_ids);
    if (!empty($ids_to_delete)) {
        $del_str = implode(",", $ids_to_delete);
        $conn->query("DELETE FROM room_units WHERE id IN ($del_str)");
    }
    
    // Redirect based on role
    if($role == 'Admin') {
        header("Location: admin_properties.php?msg=Property Updated"); 
    } else {
        header("Location: dashboard_landlord.php?msg=Updated"); 
    }
    exit();
}

$saved_inc = !empty($prop['inclusions']) ? explode(',', $prop['inclusions']) : [];
$saved_paid = !empty($prop['paid_addons']) ? explode(',', $prop['paid_addons']) : [];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <title>Manage Listing</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        :root { --bg-dark: #0f0f0f; --bg-card: #1a1a1a; --accent-orange: #ff9000; --input-bg: #222; }
        body { background: var(--bg-dark); color: white; font-family: sans-serif; padding: 20px; padding-bottom: 100px; }
        
        .page-header { margin-bottom: 30px; display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #333; padding-bottom: 20px; }
        .back-link { color: #888; text-decoration: none; display: flex; align-items: center; gap: 8px; font-weight: 600; }
        .back-link:hover { color: var(--accent-orange); }

        /* CARDS */
        .edit-card { background: var(--bg-card); border: 1px solid #333; border-radius: 12px; padding: 25px; margin-bottom: 20px; }
        .card-title { font-size: 14px; font-weight: 700; color: var(--accent-orange); margin-bottom: 20px; text-transform: uppercase; display: flex; align-items: center; gap: 8px; }

        /* INPUTS */
        label { font-size: 11px; font-weight: 700; color: #888; margin-bottom: 5px; display: block; text-transform: uppercase; }
        .form-control, .form-select { background: var(--input-bg); border: 1px solid #333; color: white; border-radius: 6px; padding: 10px; font-size: 14px; }
        .form-control:focus { border-color: var(--accent-orange); box-shadow: none; background: #2a2a2a; color: white; }

        /* RIGHT PANEL STICKY */
        .room-panel { background: #161616; border: 1px solid #333; border-radius: 12px; padding: 20px; position: sticky; top: 20px; height: fit-content; min-height: 600px; }
        
        /* ROOM ITEM */
        .room-item { background: #222; border: 1px solid #333; border-radius: 8px; padding: 15px; margin-bottom: 15px; position: relative; }
        .btn-del { position: absolute; top: -10px; right: -10px; background: #dc3545; color: white; border-radius: 50%; width: 24px; height: 24px; display: flex; align-items: center; justify-content: center; cursor: pointer; }
        
        .room-thumb-wrapper {
            width: 200px;
            height: 140px;
            background: #000;
            border-radius: 6px;
            overflow: hidden;
            position: relative;
            flex-shrink: 0;
        }
        
        .room-thumb { width: 100%; height: 100%; object-fit: cover; }
        .upload-overlay { position: absolute; bottom: 0; left: 0; width: 100%; background: rgba(0,0,0,0.7); color: white; font-size: 11px; font-weight: bold; text-align: center; cursor: pointer; padding: 4px 0; letter-spacing: 1px; }
        .upload-overlay:hover { background: var(--accent-orange); color: black; }

        .occ-bar { height: 4px; background: #333; margin-top: 10px; border-radius: 2px; }
        .occ-fill { height: 100%; background: var(--accent-orange); width: 0%; transition: 0.3s; }

        /* FOOTER */
        .sticky-footer { position: fixed; bottom: 0; left: 0; width: 100%; background: rgba(15,15,15,0.95); padding: 15px 30px; border-top: 1px solid #333; z-index: 999; display: flex; justify-content: flex-end; gap: 15px; backdrop-filter: blur(5px); }
        .btn-save-all { background: white; color: black; font-weight: 800; padding: 10px 30px; border-radius: 6px; border: none; font-size: 13px; text-transform: uppercase; }
        .btn-save-all:hover { background: var(--accent-orange); }

        /* CHECKBOX PILLS */
        .pill-chk { display: none; }
        .pill-lbl { display: inline-block; padding: 5px 12px; background: #222; border: 1px solid #333; border-radius: 20px; margin: 0 5px 8px 0; color: #ccc; font-size: 11px; cursor: pointer; font-weight: 600; }
        .pill-chk:checked + .pill-lbl { background: var(--accent-orange); color: black; border-color: var(--accent-orange); }
        .paid:checked + .pill-lbl { background: #0dcaf0; border-color: #0dcaf0; }
    </style>
</head>
<body>

<form method="POST" enctype="multipart/form-data">

    <div class="page-header">
        <div>
            <h3 class="fw-bold m-0">Manage Listing</h3>
            <span class="text-secondary small">Edit property details and unit availability</span>
        </div>
        <a href="<?php echo ($role == 'Admin') ? 'admin_properties.php' : 'dashboard_landlord.php'; ?>" class="back-link">
            <i class="bi bi-arrow-left"></i> Back to Dashboard
        </a>
    </div>

    <div class="row">
        
        <div class="col-lg-7">
            
            <div class="edit-card">
                <div class="card-title"><i class="bi bi-house-door-fill"></i> Property Details</div>
                <div class="mb-3">
                    <label>Boarding House Name</label>
                    <input type="text" name="title" class="form-control" value="<?php echo htmlspecialchars($prop['title']); ?>" required>
                </div>
                <div class="row g-3 mb-3">
                    <div class="col-md-8">
                        <label>Location</label>
                        <input type="text" name="location" class="form-control" value="<?php echo htmlspecialchars($prop['location']); ?>" required>
                    </div>
                    <div class="col-md-4">
                        <label>Status</label>
                        <select name="status" class="form-select">
                            <option value="Accepting" <?php if($prop['status']=='Accepting') echo 'selected'; ?>>🟢 Accepting</option>
                            <option value="Full" <?php if($prop['status']=='Full') echo 'selected'; ?>>🔴 Full</option>
                            <option value="Renovating" <?php if($prop['status']=='Renovating') echo 'selected'; ?>>🟡 Renovating</option>
                            <option value="Closed" <?php if($prop['status']=='Closed') echo 'selected'; ?>>⚫ Closed</option>
                        </select>
                    </div>
                </div>
                <div class="mb-3">
                    <label>Description</label>
                    <textarea name="description" class="form-control" rows="3"><?php echo htmlspecialchars($prop['description']); ?></textarea>
                </div>
                 <div class="row g-3">
                    <div class="col-md-6">
                        <label>Room Price (Range)</label>
                        <input type="number" name="price" class="form-control" value="<?php echo $prop['price']; ?>">
                    </div>
                    <div class="col-md-6">
                        <label>Shared Price (Optional)</label>
                        <input type="number" name="price_shared" class="form-control" value="<?php echo $prop['price_shared']; ?>">
                    </div>
                </div>
            </div>

            <div class="edit-card">
                <div class="card-title"><i class="bi bi-telephone-fill"></i> Contact Information</div>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label>Phone Number</label>
                        <input type="text" name="contact_phone" class="form-control" value="<?php echo htmlspecialchars($prop['contact_phone'] ?? ''); ?>">
                    </div>
                    <div class="col-md-6">
                        <label>Facebook Link</label>
                        <input type="text" name="contact_facebook" class="form-control" value="<?php echo htmlspecialchars($prop['contact_facebook'] ?? ''); ?>">
                    </div>
                    <div class="col-12">
                        <label>Email Address</label>
                        <input type="email" name="contact_email" class="form-control" value="<?php echo htmlspecialchars($prop['contact_email'] ?? ''); ?>">
                    </div>
                </div>
            </div>

            <div class="edit-card">
                <div class="card-title"><i class="bi bi-stars"></i> Amenities & Inclusions</div>
                <div class="mb-3">
                    <label class="mb-2 text-white">Free Inclusions</label>
                    <div>
                    <?php
                    $free = ["Water", "Electricity", "WiFi", "Gas", "Beddings", "Cabinet", "Study Table", "Private CR"];
                    foreach($free as $opt) {
                        $chk = in_array(trim($opt), array_map('trim', $saved_inc)) ? "checked" : "";
                        $id = "inc_".str_replace(' ','',$opt);
                        echo "<input type='checkbox' name='inclusions[]' value='$opt' id='$id' class='pill-chk' $chk><label for='$id' class='pill-lbl'>$opt</label>";
                    }
                    ?>
                    </div>
                </div>
                <div>
                    <label class="mb-2 text-info">Paid Add-ons</label>
                    <div>
                    <?php
                    $paid = ["Drinking Water", "Ref Use", "Rice Cooker", "Heater", "Laptop", "Fan"];
                    foreach($paid as $opt) {
                        $chk = in_array(trim($opt), array_map('trim', $saved_paid)) ? "checked" : "";
                        $id = "pd_".str_replace(' ','',$opt);
                        echo "<input type='checkbox' name='paid_addons[]' value='$opt' id='$id' class='pill-chk paid' $chk><label for='$id' class='pill-lbl'>+ $opt</label>";
                    }
                    ?>
                    </div>
                </div>
            </div>

            <div class="edit-card">
                <div class="card-title"><i class="bi bi-images"></i> House Gallery</div>
                <input type="file" name="room_images[]" class="form-control" multiple accept="image/*">
                <div class="small text-secondary mt-2">Main photos of the house exterior/interior.</div>
            </div>
        </div>

        <div class="col-lg-5">
            <div class="room-panel">
                <div class="d-flex justify-content-between align-items-center mb-4 border-bottom border-secondary pb-3">
                    <h5 class="fw-bold m-0">Room Availability</h5>
                    <button type="button" class="btn btn-warning btn-sm fw-bold" onclick="addRoom()">+ Add Room</button>
                </div>
                
                <div id="room-list-container">
                    <?php foreach ($existing_rooms as $i => $room): 
                        $img = !empty($room['room_image']) ? "assets/uploads/rooms/" . $room['room_image'] : "assets/default_room.jpg";
                        $perc = ($room['total_beds'] > 0) ? ($room['occupied_beds'] / $room['total_beds']) * 100 : 0;
                    ?>
                    <div class="room-item room-wrapper">
                        <div class="btn-del" onclick="this.closest('.room-wrapper').remove()"><i class="bi bi-x"></i></div>
                        <input type="hidden" name="room_ids[]" value="<?php echo $room['id']; ?>">
                        <input type="hidden" name="existing_room_imgs[]" value="<?php echo $room['room_image']; ?>">
                        
                        <div class="d-flex gap-3">
                            <div class="room-thumb-wrapper">
                                <img src="<?php echo $img; ?>" class="room-thumb" id="prev_<?php echo $i; ?>">
                                <label class="upload-overlay">
                                    <input type="file" name="room_specific_img[]" class="d-none" accept="image/*" onchange="upPreview(this, 'prev_<?php echo $i; ?>')">
                                    CHANGE
                                </label>
                            </div>
                            <div style="flex-grow: 1;">
                                <label>Room Name</label>
                                <input type="text" name="room_names[]" class="form-control py-1 mb-2" value="<?php echo htmlspecialchars($room['room_name']); ?>" required>
                                <div class="row g-2">
                                    <div class="col-6"><label>Beds</label><input type="number" name="room_beds[]" class="form-control py-1 bd-tot" value="<?php echo $room['total_beds']; ?>" oninput="upBar(this)"></div>
                                    <div class="col-6"><label>Occ</label><input type="number" name="room_occupied[]" class="form-control py-1 bd-occ" value="<?php echo $room['occupied_beds']; ?>" oninput="upBar(this)"></div>
                                </div>
                            </div>
                        </div>
                        <div class="occ-bar"><div class="occ-fill" style="width: <?php echo $perc; ?>%;"></div></div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

    </div>

    <div class="sticky-footer">
        <a href="<?php echo ($role == 'Admin') ? 'admin_properties.php' : 'dashboard_landlord.php'; ?>" class="btn btn-outline-secondary border-0 text-white d-flex align-items-center">Cancel</a>
        <button type="submit" name="update_room" class="btn-save-all">Save All Changes</button>
    </div>

</form>

<script>
function upPreview(input, id) {
    if (input.files && input.files[0]) {
        var r = new FileReader();
        r.onload = function(e) { document.getElementById(id).src = e.target.result; }
        r.readAsDataURL(input.files[0]);
    }
}
function upBar(input) {
    const p = input.closest('.room-item');
    const t = parseFloat(p.querySelector('.bd-tot').value)||1;
    const o = parseFloat(p.querySelector('.bd-occ').value)||0;
    p.querySelector('.occ-fill').style.width = Math.min((o/t)*100, 100) + "%";
}
function addRoom() {
    const u = Date.now();
    const h = `
    <div class="room-item room-wrapper">
        <div class="btn-del" onclick="this.closest('.room-wrapper').remove()"><i class="bi bi-x"></i></div>
        <input type="hidden" name="room_ids[]" value="0">
        <input type="hidden" name="existing_room_imgs[]" value="default_room.jpg">
        <div class="d-flex gap-3">
            <div class="room-thumb-wrapper">
                <img src="assets/default_room.jpg" class="room-thumb" id="n_${u}">
                <label class="upload-overlay"><input type="file" name="room_specific_img[]" class="d-none" accept="image/*" onchange="upPreview(this, 'n_${u}')">UPLOAD</label>
            </div>
            <div style="flex-grow: 1;">
                <label>Room Name</label>
                <input type="text" name="room_names[]" class="form-control py-1 mb-2" placeholder="Room #">
                <div class="row g-2">
                    <div class="col-6"><label>Beds</label><input type="number" name="room_beds[]" class="form-control py-1 bd-tot" value="4" oninput="upBar(this)"></div>
                    <div class="col-6"><label>Occ</label><input type="number" name="room_occupied[]" class="form-control py-1 bd-occ" value="0" oninput="upBar(this)"></div>
                </div>
            </div>
        </div>
        <div class="occ-bar"><div class="occ-fill" style="width: 0%;"></div></div>
    </div>`;
    document.getElementById('room-list-container').insertAdjacentHTML('beforeend', h);
}
</script>
</body>
</html>