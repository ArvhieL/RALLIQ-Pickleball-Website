<?php
session_start();

// 1. Enforce Role-Based Access Control (RBAC) - Restrict to Managers only
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Manager') {
    header("Location: ../login.php");
    exit();
}

date_default_timezone_set('Asia/Manila');
$today = date("Y-m-d");
$now_time = date("H:i:s");

// 2. Connect to SQL Server
$serverName = "LAPTOP-QIJJ9RAT\\SQLEXPRESS";
$connectionOptions = [
    "Database" => "FinalProject",
    "Uid" => "",
    "PWD" => ""
];

$conn = sqlsrv_connect($serverName, $connectionOptions);
if ($conn === false) {
    die(print_r(sqlsrv_errors(), true));
}

$user_id = (int)$_SESSION['user_id'];
$first_name = $_SESSION['first_name'] ?? 'Manager';

// 3. Fetch Manager Profile (Avatar & City)
$sql_profile = "SELECT avatar_url, city FROM users WHERE user_id = ?";
$res_profile = sqlsrv_query($conn, $sql_profile, [$user_id]);
$avatar = "";
$manager_city = "Facility HQ";

if ($res_profile && sqlsrv_has_rows($res_profile)) {
    $profile = sqlsrv_fetch_array($res_profile, SQLSRV_FETCH_ASSOC);
    $avatar = $profile['avatar_url'];
    if (!empty($profile['city'])) {
        $manager_city = $profile['city'];
    }
}
$display_avatar = !empty($avatar) ? $avatar : "default_avatar.png";

// Active UI tabs (supports GET query params or POST hidden inputs)
$active_form_tab = (isset($_REQUEST['form_tab']) && $_REQUEST['form_tab'] === 'venue') ? 'venue' : 'court';
$active_dir_tab  = (isset($_REQUEST['dir_tab']) && $_REQUEST['dir_tab'] === 'venues') ? 'venues' : 'courts';

$success_msg = "";
$error_msg = "";

if (isset($_GET['status'])) {
    $status_messages = [
        'venue_added'   => "New venue registered successfully! You can now add courts under it.",
        'court_added'   => "New court added and set to Available.",
        'court_updated' => "Court details updated successfully.",
        'venue_updated' => "Venue details updated successfully.",
        'status_toggled'=> "Court operational status updated."
    ];
    if (isset($status_messages[$_GET['status']])) {
        $success_msg = $status_messages[$_GET['status']];
    }
}

// Helper function for safe image uploads
function uploadFacilityImage(array $file, string $targetDir, string $prefix, ?string &$errorOut): ?string
{
    if (!isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $errorOut = "Image upload failed. Please try a smaller file.";
        return null;
    }

    $allowed_ext = ['jpg', 'jpeg', 'png', 'webp'];
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowed_ext, true)) {
        $errorOut = "Invalid image format. Only JPG, JPEG, PNG, and WEBP are allowed.";
        return null;
    }

    if (!is_dir($targetDir)) {
        mkdir($targetDir, 0777, true);
    }

    $filename = $prefix . "_" . date("Ymd") . "_" . uniqid() . "." . $ext;
    if (!move_uploaded_file($file['tmp_name'], rtrim($targetDir, '/') . '/' . $filename)) {
        $errorOut = "Unable to save the uploaded image file.";
        return null;
    }

    return $filename;
}

// 4. Handle POST Form Submissions
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['action'])) {
    $action = $_POST['action'];

    // ACTION A: Add New Venue
    if ($action === 'add_venue') {
        $active_form_tab = 'venue';
        $venue_name = trim($_POST['venue_name'] ?? '');
        $address    = trim($_POST['address'] ?? '');
        $city       = trim($_POST['city'] ?? '');

        if ($venue_name === '' || $address === '') {
            $error_msg = "Venue name and street address are required.";
        } elseif (mb_strlen($venue_name) > 50 || mb_strlen($address) > 50 || mb_strlen($city) > 50) {
            $error_msg = "Venue fields must be 50 characters or fewer.";
        } else {
            $venue_photo = uploadFacilityImage($_FILES['venue_photo'] ?? [], "../venue_images", "venue_m{$user_id}", $error_msg);

            if ($error_msg === '') {
                $sql_ins_venue = "
                    INSERT INTO venues (manager_id, venue_name, address, city, venue_photo, date_created)
                    VALUES (?, ?, ?, ?, ?, GETDATE())
                ";
                $params = [$user_id, $venue_name, $address, ($city !== '' ? $city : null), $venue_photo];

                if (sqlsrv_query($conn, $sql_ins_venue, $params) !== false) {
                    header("Location: manage_courts.php?status=venue_added&form_tab=court&dir_tab=venues");
                    exit();
                } else {
                    $error_msg = "Database error while registering the venue.";
                }
            }
        }
    }

    // ACTION B: Add New Court
    elseif ($action === 'add_court') {
        $active_form_tab = 'court';
        $venue_id     = (int)($_POST['venue_id'] ?? 0);
        $court_name   = trim($_POST['court_name'] ?? '');
        $court_type   = trim($_POST['court_type'] ?? '');
        $surface_type = trim($_POST['surface_type'] ?? '');

        $allowed_types = ['Indoor', 'Outdoor'];
        $allowed_surfaces = ['Acrylic Hardcourt', 'Cushioned Acrylic', 'Asphalt', 'Concrete', 'Polypropylene Modular', 'Wood / Gymnasium'];

        if ($venue_id <= 0 || $court_name === '' || !in_array($court_type, $allowed_types, true) || !in_array($surface_type, $allowed_surfaces, true)) {
            $error_msg = "Please complete all required court details properly.";
        } elseif (mb_strlen($court_name) > 50) {
            $error_msg = "Court name must be 50 characters or fewer.";
        } else {
            // Verify venue belongs to logged-in manager
            $stmt_v = sqlsrv_query($conn, "SELECT venue_id FROM venues WHERE venue_id = ? AND manager_id = ?", [$venue_id, $user_id]);
            if (!$stmt_v || !sqlsrv_has_rows($stmt_v)) {
                $error_msg = "Invalid parent venue selected.";
            } else {
                $court_photo = uploadFacilityImage($_FILES['court_photo'] ?? [], "../court_images", "court_v{$venue_id}", $error_msg);

                if ($error_msg === '') {
                    $sql_ins_court = "
                        INSERT INTO courts (venue_id, court_name, surface_type, court_type, is_active)
                        OUTPUT INSERTED.court_id
                        VALUES (?, ?, ?, ?, 1)
                    ";
                    $stmt_c = sqlsrv_query($conn, $sql_ins_court, [$venue_id, $court_name, $surface_type, $court_type]);

                    if ($stmt_c && ($new_row = sqlsrv_fetch_array($stmt_c, SQLSRV_FETCH_ASSOC))) {
                        $new_court_id = (int)$new_row['court_id'];
                        if ($court_photo !== null) {
                            sqlsrv_query($conn, "INSERT INTO court_pictures (court_id, picture_url) VALUES (?, ?)", [$new_court_id, $court_photo]);
                        }
                        header("Location: manage_courts.php?status=court_added&form_tab=court&dir_tab=courts");
                        exit();
                    } else {
                        $error_msg = "Database error while saving the court.";
                    }
                }
            }
        }
    }

    // ACTION C: Toggle Court Active / Maintenance Status
    elseif ($action === 'toggle_court_status') {
        $court_id = (int)($_POST['court_id'] ?? 0);
        $current_status = (int)($_POST['current_status'] ?? 1);
        $new_status = ($current_status === 1) ? 0 : 1;

        $sql_verify_c = "
            SELECT c.court_id
            FROM courts AS c
            INNER JOIN venues AS v ON c.venue_id = v.venue_id
            WHERE c.court_id = ? AND v.manager_id = ?
        ";
        $stmt_vc = sqlsrv_query($conn, $sql_verify_c, [$court_id, $user_id]);

        if (!$stmt_vc || !sqlsrv_has_rows($stmt_vc)) {
            $error_msg = "Court not found or access denied.";
        } else {
            if (sqlsrv_query($conn, "UPDATE courts SET is_active = ? WHERE court_id = ?", [$new_status, $court_id]) !== false) {
                header("Location: manage_courts.php?status=status_toggled&dir_tab=courts");
                exit();
            } else {
                $error_msg = "Failed to update court status.";
            }
        }
    }

    // ACTION D: Edit Court Info & Photo
    elseif ($action === 'edit_court') {
        $court_id     = (int)($_POST['court_id'] ?? 0);
        $venue_id     = (int)($_POST['venue_id'] ?? 0);
        $court_name   = trim($_POST['court_name'] ?? '');
        $court_type   = trim($_POST['court_type'] ?? '');
        $surface_type = trim($_POST['surface_type'] ?? '');

        $sql_verify_ownership = "
            SELECT c.court_id
            FROM courts AS c
            INNER JOIN venues AS v ON c.venue_id = v.venue_id
            WHERE c.court_id = ? AND v.manager_id = ?
        ";
        $stmt_own_c = sqlsrv_query($conn, $sql_verify_ownership, [$court_id, $user_id]);
        $stmt_own_v = sqlsrv_query($conn, "SELECT venue_id FROM venues WHERE venue_id = ? AND manager_id = ?", [$venue_id, $user_id]);

        if (!$stmt_own_c || !sqlsrv_has_rows($stmt_own_c) || !$stmt_own_v || !sqlsrv_has_rows($stmt_own_v)) {
            $error_msg = "Unauthorized court or venue selection.";
        } elseif ($court_name === '' || mb_strlen($court_name) > 50) {
            $error_msg = "Court name is required and must be 50 characters or fewer.";
        } else {
            $new_photo = uploadFacilityImage($_FILES['court_photo'] ?? [], "../court_images", "court_c{$court_id}", $error_msg);

            if ($error_msg === '') {
                $sql_upd_c = "
                    UPDATE courts
                    SET venue_id = ?, court_name = ?, court_type = ?, surface_type = ?
                    WHERE court_id = ?
                ";
                if (sqlsrv_query($conn, $sql_upd_c, [$venue_id, $court_name, $court_type, $surface_type, $court_id]) !== false) {
                    if ($new_photo !== null) {
                        $stmt_pic = sqlsrv_query($conn, "SELECT TOP 1 picture_id FROM court_pictures WHERE court_id = ? ORDER BY picture_id DESC", [$court_id]);
                        if ($stmt_pic && ($pic_row = sqlsrv_fetch_array($stmt_pic, SQLSRV_FETCH_ASSOC))) {
                            sqlsrv_query($conn, "UPDATE court_pictures SET picture_url = ? WHERE picture_id = ?", [$new_photo, (int)$pic_row['picture_id']]);
                        } else {
                            sqlsrv_query($conn, "INSERT INTO court_pictures (court_id, picture_url) VALUES (?, ?)", [$court_id, $new_photo]);
                        }
                    }
                    header("Location: manage_courts.php?status=court_updated&dir_tab=courts");
                    exit();
                } else {
                    $error_msg = "Database error while updating court details.";
                }
            }
        }
    }

    // ACTION E: Edit Venue Info & Photo
    elseif ($action === 'edit_venue') {
        $active_dir_tab = 'venues';
        $venue_id   = (int)($_POST['venue_id'] ?? 0);
        $venue_name = trim($_POST['venue_name'] ?? '');
        $address    = trim($_POST['address'] ?? '');
        $city       = trim($_POST['city'] ?? '');

        $stmt_own_v = sqlsrv_query($conn, "SELECT venue_id FROM venues WHERE venue_id = ? AND manager_id = ?", [$venue_id, $user_id]);
        if (!$stmt_own_v || !sqlsrv_has_rows($stmt_own_v)) {
            $error_msg = "Unauthorized venue selection.";
        } elseif ($venue_name === '' || $address === '') {
            $error_msg = "Venue name and address are required.";
        } else {
            $new_v_photo = uploadFacilityImage($_FILES['venue_photo'] ?? [], "../venue_images", "venue_v{$venue_id}", $error_msg);

            if ($error_msg === '') {
                if ($new_v_photo !== null) {
                    $sql_upd_v = "UPDATE venues SET venue_name = ?, address = ?, city = ?, venue_photo = ? WHERE venue_id = ? AND manager_id = ?";
                    $v_params = [$venue_name, $address, ($city !== '' ? $city : null), $new_v_photo, $venue_id, $user_id];
                } else {
                    $sql_upd_v = "UPDATE venues SET venue_name = ?, address = ?, city = ? WHERE venue_id = ? AND manager_id = ?";
                    $v_params = [$venue_name, $address, ($city !== '' ? $city : null), $venue_id, $user_id];
                }

                if (sqlsrv_query($conn, $sql_upd_v, $v_params) !== false) {
                    header("Location: manage_courts.php?status=venue_updated&dir_tab=venues");
                    exit();
                } else {
                    $error_msg = "Database error while updating venue details.";
                }
            }
        }
    }
}

// 5. Fetch Manager's Venues with Court Counts
$sql_venues = "
    SELECT v.venue_id, v.venue_name, v.address, v.city, v.venue_photo,
           (SELECT COUNT(*) FROM courts AS c WHERE c.venue_id = v.venue_id) AS court_count
    FROM venues AS v
    WHERE v.manager_id = ?
    ORDER BY v.date_created DESC, v.venue_name ASC
";
$res_venues = sqlsrv_query($conn, $sql_venues, [$user_id]);
$venues_list = [];
if ($res_venues && sqlsrv_has_rows($res_venues)) {
    while ($v = sqlsrv_fetch_array($res_venues, SQLSRV_FETCH_ASSOC)) {
        $venues_list[] = $v;
    }
}

// 6. Fetch Manager's Courts with Latest Photo & Live Block Status
$sql_courts = "
    SELECT c.court_id, c.venue_id, c.court_name, c.surface_type, c.court_type, c.is_active,
           v.venue_name,
           cp.picture_url,
           blk_now.booking_id AS active_block_id,
           blk_later.start_time AS next_block_start
    FROM courts AS c
    INNER JOIN venues AS v ON c.venue_id = v.venue_id
    OUTER APPLY (
        SELECT TOP 1 picture_url
        FROM court_pictures
        WHERE court_id = c.court_id
        ORDER BY picture_id DESC
    ) AS cp
    OUTER APPLY (
        SELECT TOP 1 booking_id
        FROM bookings
        WHERE court_id = c.court_id
          AND booking_date = ?
          AND status = 'Cancelled'
          AND total_amount = 0.00
          AND start_time <= ?
          AND end_time > ?
    ) AS blk_now
    OUTER APPLY (
        SELECT TOP 1 start_time
        FROM bookings
        WHERE court_id = c.court_id
          AND booking_date = ?
          AND status = 'Cancelled'
          AND total_amount = 0.00
          AND start_time > ?
        ORDER BY start_time ASC
    ) AS blk_later
    WHERE v.manager_id = ?
    ORDER BY v.venue_name ASC, c.court_name ASC
";
$res_courts = sqlsrv_query($conn, $sql_courts, [$today, $now_time, $now_time, $today, $now_time, $user_id]);
$courts_list = [];
$available_courts_count = 0;

if ($res_courts && sqlsrv_has_rows($res_courts)) {
    while ($c = sqlsrv_fetch_array($res_courts, SQLSRV_FETCH_ASSOC)) {
        $is_op = ((int)$c['is_active'] === 1);
        $is_blocked_now = !empty($c['active_block_id']);

        if (!$is_op) {
            $c['avail_state'] = 'inactive';
            $c['avail_label'] = 'Maintenance';
            $c['upcoming_block_label'] = '';
        } elseif ($is_blocked_now) {
            $c['avail_state'] = 'blocked_now';
            $c['avail_label'] = 'Blocked Now';
            $c['upcoming_block_label'] = '';
        } else {
            $c['avail_state'] = 'available';
            $c['avail_label'] = 'Available';
            $available_courts_count++;
            $c['upcoming_block_label'] = !empty($c['next_block_start'])
                ? 'Blocked later at ' . $c['next_block_start']->format('g:i A')
                : '';
        }

        $courts_list[] = $c;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Court Management - RALLIQ</title>

    <!-- Bootstrap 5.2.3 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-rbsA2VBKQhggwzxH7pPCaAqO46MgnOM80zW1RWuH61DGLwZJEdK2Kadq2F9CUG65" crossorigin="anonymous">

    <!-- RALLIQ Orange & Slate Theme -->
    <link rel="stylesheet" href="../ralliq-theme.css?v=4">
</head>
<body>

    <!-- Top Navigation Bar -->
    <nav class="navbar navbar-expand-lg navbar-ralliq sticky-top py-3">
        <div class="container">
            <a class="navbar-brand d-flex align-items-center gap-2" href="manager_dashboard.php">
                <img src="../assets/RalliQ Main Logo.png?v=2" alt="RALLIQ Logo" height="30">
                <span class="badge rounded-pill badge-slate px-2 py-1">ADMIN</span>
            </a>

            <button class="navbar-toggler border-0" type="button" data-bs-toggle="collapse" data-bs-target="#managerNavbar" aria-controls="managerNavbar" aria-expanded="false" aria-label="Toggle navigation">
                <span class="navbar-toggler-icon"></span>
            </button>

            <div class="collapse navbar-collapse" id="managerNavbar">
                <ul class="navbar-nav me-auto mb-2 mb-lg-0 ms-lg-4 gap-lg-3">
                    <li class="nav-item">
                        <a class="nav-link" href="manager_dashboard.php">Dashboard</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link active" aria-current="page" href="manage_courts.php">Court Management</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="facility_scheduling.php">Facility Scheduling</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="manage_rentals.php">Private Rentals</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="manage_open_plays.php">Open Plays</a>
                    </li>
                </ul>

                <div class="d-flex align-items-center gap-3">
                    <a href="edit_profile.php" class="d-flex align-items-center text-decoration-none gap-2">
                        <img src="../avatars/<?php echo htmlspecialchars($display_avatar); ?>" onerror="this.src='../avatars/default_avatar.png';" alt="Manager Avatar" class="nav-avatar">
                        <span class="fw-semibold small text-dark">My Profile</span>
                    </a>
                    <a href="../logout.php" class="btn btn-sm btn-outline-danger rounded-pill px-3 fw-semibold">Logout</a>
                </div>
            </div>
        </div>
    </nav>

    <!-- Clean White Page Header Strip -->
    <header class="page-header-strip">
        <div class="container">
            <div class="row align-items-center gy-3">
                <div class="col-12 col-lg-7">
                    <h1 class="h2 fw-bold mb-1">Court Management</h1>
                    <p class="text-stone mb-0">
                        Register facilities, configure playing surfaces, and monitor live court status &bull; <?php echo htmlspecialchars($manager_city); ?>
                    </p>
                </div>
                <div class="col-12 col-lg-5 d-flex flex-wrap align-items-center gap-2 justify-content-lg-end">
                    <span class="badge rounded-pill badge-soft-orange px-3 py-2">
                        <?php echo count($venues_list); ?> Venues
                    </span>
                    <span class="badge rounded-pill bg-success bg-opacity-10 text-success border border-success border-opacity-25 px-3 py-2">
                        <?php echo $available_courts_count; ?> / <?php echo count($courts_list); ?> Available
                    </span>
                    <a href="facility_scheduling.php" class="btn btn-sm btn-outline-primary ms-1">Block Hours</a>
                </div>
            </div>
        </div>
    </header>

    <!-- Main Workspace -->
    <main class="container pb-5">

        <!-- Feedback Alerts -->
        <?php if (!empty($error_msg)): ?>
            <div class="alert alert-danger rounded-4 border-0 shadow-sm alert-dismissible fade show mb-4" role="alert">
                <?php echo htmlspecialchars($error_msg); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <?php if (!empty($success_msg)): ?>
            <div class="alert alert-success rounded-4 border-0 shadow-sm alert-dismissible fade show mb-4" role="alert">
                <?php echo htmlspecialchars($success_msg); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <div class="row g-4">
            <!-- LEFT COLUMN: Register New Court / Venue -->
            <div class="col-12 col-lg-5">
                <div class="card card-custom p-4 h-100">
                    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 pb-3 mb-4 border-bottom">
                        <h2 class="h5 fw-bold mb-0">Register New</h2>
                        <ul class="nav nav-pills ralliq-switcher gap-2" id="addFormTabs" role="tablist">
                            <li class="nav-item" role="presentation">
                                <button class="nav-link <?php echo ($active_form_tab === 'court') ? 'active' : ''; ?>" id="add-court-tab" data-bs-toggle="pill" data-bs-target="#add-court-pane" type="button" role="tab">
                                    + Court
                                </button>
                            </li>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link <?php echo ($active_form_tab === 'venue') ? 'active' : ''; ?>" id="add-venue-tab" data-bs-toggle="pill" data-bs-target="#add-venue-pane" type="button" role="tab">
                                    + Venue
                                </button>
                            </li>
                        </ul>
                    </div>

                    <div class="tab-content" id="addFormTabsContent">
                        <!-- TAB 1: Add Court Form -->
                        <div class="tab-pane fade <?php echo ($active_form_tab === 'court') ? 'show active' : ''; ?>" id="add-court-pane" role="tabpanel">
                            <?php if (count($venues_list) > 0): ?>
                                <form action="manage_courts.php" method="POST" enctype="multipart/form-data">
                                    <input type="hidden" name="action" value="add_court">
                                    <input type="hidden" name="form_tab" value="court">

                                    <div class="mb-3">
                                        <label for="venue_id" class="form-label form-label-custom">Parent Venue <span class="text-danger">*</span></label>
                                        <select class="form-select form-control-custom" id="venue_id" name="venue_id" required>
                                            <?php foreach ($venues_list as $v): ?>
                                                <option value="<?php echo (int)$v['venue_id']; ?>">
                                                    <?php echo htmlspecialchars($v['venue_name']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>

                                    <div class="mb-3">
                                        <label for="court_name" class="form-label form-label-custom">Court Name <span class="text-danger">*</span></label>
                                        <input type="text" class="form-control form-control-custom" id="court_name" name="court_name" placeholder="e.g., Court 1 - Championship" maxlength="50" required>
                                    </div>

                                    <div class="row g-3 mb-3">
                                        <div class="col-12 col-sm-6">
                                            <label for="court_type" class="form-label form-label-custom">Court Type <span class="text-danger">*</span></label>
                                            <select class="form-select form-control-custom" id="court_type" name="court_type" required>
                                                <option value="Indoor">Indoor</option>
                                                <option value="Outdoor">Outdoor</option>
                                            </select>
                                        </div>

                                        <div class="col-12 col-sm-6">
                                            <label for="surface_type" class="form-label form-label-custom">Surface <span class="text-danger">*</span></label>
                                            <select class="form-select form-control-custom" id="surface_type" name="surface_type" required>
                                                <option value="Acrylic Hardcourt">Acrylic Hardcourt</option>
                                                <option value="Cushioned Acrylic">Cushioned Acrylic</option>
                                                <option value="Asphalt">Asphalt</option>
                                                <option value="Concrete">Concrete</option>
                                                <option value="Polypropylene Modular">Polypropylene Modular</option>
                                                <option value="Wood / Gymnasium">Wood / Gymnasium</option>
                                            </select>
                                        </div>
                                    </div>

                                    <div class="mb-4">
                                        <label for="court_photo" class="form-label form-label-custom">Court Photo <span class="text-stone small">(Optional)</span></label>
                                        <input type="file" class="form-control form-control-custom" id="court_photo" name="court_photo" accept=".jpg,.jpeg,.png,.webp">
                                        <div class="form-text text-stone">Defaults to standard court thumbnail if left blank.</div>
                                    </div>

                                    <button type="submit" class="btn btn-primary w-100">Save Court</button>
                                </form>
                            <?php else: ?>
                                <div class="text-center py-4 px-3 rounded-4 bg-light">
                                    <img src="../assets/Venue Icon.png" alt="Venue Required" width="48" height="48" class="mb-2">
                                    <h3 class="h6 fw-bold">Register a Venue First</h3>
                                    <p class="small text-stone mb-3">Every court must belong to a registered sports facility.</p>
                                    <button type="button" class="btn btn-primary btn-sm px-4" onclick="document.getElementById('add-venue-tab').click();">
                                        Switch to + Venue
                                    </button>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- TAB 2: Add Venue Form -->
                        <div class="tab-pane fade <?php echo ($active_form_tab === 'venue') ? 'show active' : ''; ?>" id="add-venue-pane" role="tabpanel">
                            <form action="manage_courts.php" method="POST" enctype="multipart/form-data">
                                <input type="hidden" name="action" value="add_venue">
                                <input type="hidden" name="form_tab" value="venue">

                                <div class="mb-3">
                                    <label for="venue_name" class="form-label form-label-custom">Venue Name <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control form-control-custom" id="venue_name" name="venue_name" placeholder="e.g., Imus Smash Pickleball Club" maxlength="50" required>
                                </div>

                                <div class="mb-3">
                                    <label for="address" class="form-label form-label-custom">Street Address <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control form-control-custom" id="address" name="address" placeholder="e.g., Aguinaldo Highway, Brgy. Anabu" maxlength="50" required>
                                </div>

                                <div class="mb-3">
                                    <label for="city" class="form-label form-label-custom">City / Municipality</label>
                                    <input type="text" class="form-control form-control-custom" id="city" name="city" placeholder="e.g., Imus" maxlength="50" value="<?php echo htmlspecialchars($manager_city !== 'Facility HQ' ? $manager_city : ''); ?>">
                                </div>

                                <div class="mb-4">
                                    <label for="venue_photo" class="form-label form-label-custom">Venue Cover Photo <span class="text-stone small">(Optional)</span></label>
                                    <input type="file" class="form-control form-control-custom" id="venue_photo" name="venue_photo" accept=".jpg,.jpeg,.png,.webp">
                                </div>

                                <button type="submit" class="btn btn-primary w-100">Register Venue</button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>

            <!-- RIGHT COLUMN: Facility Directory (Courts & Venues) -->
            <div class="col-12 col-lg-7">
                <div class="card card-custom p-4 h-100">
                    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 pb-3 mb-4 border-bottom">
                        <div>
                            <h2 class="h5 fw-bold mb-0">Facility Directory</h2>
                            <p class="small text-stone mb-0">Edit details, inspect availability, or toggle maintenance.</p>
                        </div>
                        <ul class="nav nav-pills ralliq-switcher gap-2" id="dirTabs" role="tablist">
                            <li class="nav-item" role="presentation">
                                <button class="nav-link <?php echo ($active_dir_tab === 'courts') ? 'active' : ''; ?>" id="view-courts-tab" data-bs-toggle="pill" data-bs-target="#view-courts-pane" type="button" role="tab">
                                    Courts (<?php echo count($courts_list); ?>)
                                </button>
                            </li>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link <?php echo ($active_dir_tab === 'venues') ? 'active' : ''; ?>" id="view-venues-tab" data-bs-toggle="pill" data-bs-target="#view-venues-pane" type="button" role="tab">
                                    Venues (<?php echo count($venues_list); ?>)
                                </button>
                            </li>
                        </ul>
                    </div>

                    <div class="tab-content" id="dirTabsContent">
                        <!-- DIRECTORY PANE 1: Courts -->
                        <div class="tab-pane fade <?php echo ($active_dir_tab === 'courts') ? 'show active' : ''; ?>" id="view-courts-pane" role="tabpanel">
                            <?php if (count($courts_list) > 0): ?>
                                <div class="d-flex flex-column gap-3">
                                    <?php foreach ($courts_list as $court): ?>
                                        <?php
                                            $c_id = (int)$court['court_id'];
                                            $v_id = (int)$court['venue_id'];
                                            $is_active = ((int)$court['is_active'] === 1);
                                            $availState = $court['avail_state'];
                                            $thumbSrc = !empty($court['picture_url'])
                                                ? "../court_images/" . $court['picture_url']
                                                : "../assets/Court Icon.png";

                                            if ($availState === 'available') {
                                                $badgeClass = "bg-success bg-opacity-10 text-success border border-success border-opacity-25";
                                            } elseif ($availState === 'blocked_now') {
                                                $badgeClass = "badge-soft-orange";
                                            } else {
                                                $badgeClass = "bg-danger bg-opacity-10 text-danger border border-danger border-opacity-25";
                                            }
                                        ?>
                                        <div class="roster-item-card d-flex flex-column flex-sm-row align-items-sm-center justify-content-between gap-3">
                                            <div class="d-flex align-items-center gap-3">
                                                <img
                                                    src="<?php echo htmlspecialchars($thumbSrc); ?>"
                                                    onerror="this.src='../assets/default_court.png';"
                                                    alt="<?php echo htmlspecialchars($court['court_name']); ?>"
                                                    class="roster-thumb"
                                                    style="cursor: pointer;"
                                                    title="Click to edit court"
                                                    data-bs-toggle="modal"
                                                    data-bs-target="#editCourtModal"
                                                    data-court-id="<?php echo $c_id; ?>"
                                                    data-venue-id="<?php echo $v_id; ?>"
                                                    data-court-name="<?php echo htmlspecialchars($court['court_name']); ?>"
                                                    data-court-type="<?php echo htmlspecialchars($court['court_type']); ?>"
                                                    data-surface-type="<?php echo htmlspecialchars($court['surface_type']); ?>"
                                                    data-photo-src="<?php echo htmlspecialchars($thumbSrc); ?>"
                                                >
                                                <div>
                                                    <div class="d-flex flex-wrap align-items-center gap-2 mb-1">
                                                        <h3 class="h6 fw-bold mb-0"><?php echo htmlspecialchars($court['court_name']); ?></h3>
                                                        <span class="badge rounded-pill px-2 py-1 <?php echo $badgeClass; ?>">
                                                            <?php echo htmlspecialchars($court['avail_label']); ?>
                                                        </span>
                                                    </div>
                                                    <div class="small text-stone">
                                                        <?php echo htmlspecialchars($court['venue_name'] . " • " . $court['court_type'] . " • " . $court['surface_type']); ?>
                                                    </div>
                                                    <?php if (!empty($court['upcoming_block_label'])): ?>
                                                        <div class="small fw-medium text-orange mt-1">
                                                            <?php echo htmlspecialchars($court['upcoming_block_label']); ?>
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                            </div>

                                            <div class="d-flex flex-wrap align-items-center gap-2 align-self-end align-self-sm-center">
                                                <button
                                                    type="button"
                                                    class="btn btn-sm btn-soft-orange px-3"
                                                    data-bs-toggle="modal"
                                                    data-bs-target="#editCourtModal"
                                                    data-court-id="<?php echo $c_id; ?>"
                                                    data-venue-id="<?php echo $v_id; ?>"
                                                    data-court-name="<?php echo htmlspecialchars($court['court_name']); ?>"
                                                    data-court-type="<?php echo htmlspecialchars($court['court_type']); ?>"
                                                    data-surface-type="<?php echo htmlspecialchars($court['surface_type']); ?>"
                                                    data-photo-src="<?php echo htmlspecialchars($thumbSrc); ?>"
                                                >
                                                    Edit
                                                </button>

                                                <a href="facility_scheduling.php" class="btn btn-sm btn-outline-secondary rounded-pill px-3 fw-semibold">
                                                    Schedule
                                                </a>

                                                <form action="manage_courts.php" method="POST" class="m-0">
                                                    <input type="hidden" name="action" value="toggle_court_status">
                                                    <input type="hidden" name="dir_tab" value="courts">
                                                    <input type="hidden" name="court_id" value="<?php echo $c_id; ?>">
                                                    <input type="hidden" name="current_status" value="<?php echo $is_active ? '1' : '0'; ?>">
                                                    <button type="submit" class="btn btn-sm rounded-pill px-3 fw-semibold <?php echo $is_active ? 'btn-outline-danger' : 'btn-success'; ?>">
                                                        <?php echo $is_active ? 'Set Inactive' : 'Activate'; ?>
                                                    </button>
                                                </form>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <div class="text-center py-5 px-3 rounded-4 bg-light">
                                    <img src="../assets/default_court.png" alt="No Courts" class="mb-3 rounded-3" width="96" height="72" style="object-fit: cover;">
                                    <h3 class="h6 fw-bold">No Courts Registered Yet</h3>
                                    <p class="small text-stone mb-0">Use the <strong>+ Court</strong> form on the left to add your first court.</p>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- DIRECTORY PANE 2: Venues -->
                        <div class="tab-pane fade <?php echo ($active_dir_tab === 'venues') ? 'show active' : ''; ?>" id="view-venues-pane" role="tabpanel">
                            <?php if (count($venues_list) > 0): ?>
                                <div class="d-flex flex-column gap-3">
                                    <?php foreach ($venues_list as $venue): ?>
                                        <?php
                                            $v_id = (int)$venue['venue_id'];
                                            $venueThumb = !empty($venue['venue_photo'])
                                                ? "../venue_images/" . $venue['venue_photo']
                                                : "../assets/Venue Icon.png";
                                            $c_count = (int)$venue['court_count'];
                                        ?>
                                        <div class="roster-item-card d-flex flex-column flex-sm-row align-items-sm-center justify-content-between gap-3">
                                            <div class="d-flex align-items-center gap-3">
                                                <img
                                                    src="<?php echo htmlspecialchars($venueThumb); ?>"
                                                    onerror="this.src='../assets/Venue Icon.png';"
                                                    alt="<?php echo htmlspecialchars($venue['venue_name']); ?>"
                                                    class="roster-thumb"
                                                    style="cursor: pointer;"
                                                    title="Click to edit venue"
                                                    data-bs-toggle="modal"
                                                    data-bs-target="#editVenueModal"
                                                    data-venue-id="<?php echo $v_id; ?>"
                                                    data-venue-name="<?php echo htmlspecialchars($venue['venue_name']); ?>"
                                                    data-venue-address="<?php echo htmlspecialchars($venue['address']); ?>"
                                                    data-venue-city="<?php echo htmlspecialchars($venue['city'] ?? ''); ?>"
                                                    data-photo-src="<?php echo htmlspecialchars($venueThumb); ?>"
                                                >
                                                <div>
                                                    <div class="d-flex flex-wrap align-items-center gap-2 mb-1">
                                                        <h3 class="h6 fw-bold mb-0"><?php echo htmlspecialchars($venue['venue_name']); ?></h3>
                                                        <span class="badge rounded-pill badge-soft-orange px-2 py-1">
                                                            <?php echo $c_count . ($c_count === 1 ? ' Court' : ' Courts'); ?>
                                                        </span>
                                                    </div>
                                                    <div class="small text-stone">
                                                        <?php echo htmlspecialchars($venue['address']); ?><?php echo !empty($venue['city']) ? ', ' . htmlspecialchars($venue['city']) : ''; ?>
                                                    </div>
                                                </div>
                                            </div>

                                            <div class="d-flex align-items-center gap-2 align-self-end align-self-sm-center">
                                                <button
                                                    type="button"
                                                    class="btn btn-sm btn-soft-orange px-3"
                                                    data-bs-toggle="modal"
                                                    data-bs-target="#editVenueModal"
                                                    data-venue-id="<?php echo $v_id; ?>"
                                                    data-venue-name="<?php echo htmlspecialchars($venue['venue_name']); ?>"
                                                    data-venue-address="<?php echo htmlspecialchars($venue['address']); ?>"
                                                    data-venue-city="<?php echo htmlspecialchars($venue['city'] ?? ''); ?>"
                                                    data-photo-src="<?php echo htmlspecialchars($venueThumb); ?>"
                                                >
                                                    Edit
                                                </button>
                                                <a href="manage_courts.php?form_tab=court&amp;dir_tab=venues" class="btn btn-sm btn-primary px-3">
                                                    + Court
                                                </a>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <div class="text-center py-5 px-3 rounded-4 bg-light">
                                    <img src="../assets/Venue Icon.png" alt="No Venues" width="48" height="48" class="mb-2">
                                    <h3 class="h6 fw-bold">No Venues Registered Yet</h3>
                                    <p class="small text-stone mb-3">Switch to the <strong>+ Venue</strong> tab on the left to register your sports facility.</p>
                                    <button type="button" class="btn btn-primary btn-sm px-4" onclick="document.getElementById('add-venue-tab').click();">
                                        + Register Venue
                                    </button>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

    </main>

    <!-- MODAL 1: Edit Court Details -->
    <div class="modal fade" id="editCourtModal" tabindex="-1" aria-labelledby="editCourtModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content rounded-4 border-0 shadow">
                <form action="manage_courts.php" method="POST" enctype="multipart/form-data">
                    <div class="modal-header border-bottom px-4 py-3">
                        <h3 class="modal-title h5 fw-bold" id="editCourtModalLabel">Edit Court Details</h3>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body p-4">
                        <input type="hidden" name="action" value="edit_court">
                        <input type="hidden" name="dir_tab" value="courts">
                        <input type="hidden" name="court_id" id="edit_court_id">

                        <div class="text-center mb-3">
                            <img id="edit_court_preview" src="../assets/default_court.png" alt="Court Preview" class="rounded-4 border" width="140" height="96" style="object-fit: cover;">
                        </div>

                        <div class="mb-3">
                            <label for="edit_court_venue_id" class="form-label form-label-custom">Parent Venue <span class="text-danger">*</span></label>
                            <select class="form-select form-control-custom" id="edit_court_venue_id" name="venue_id" required>
                                <?php foreach ($venues_list as $v): ?>
                                    <option value="<?php echo (int)$v['venue_id']; ?>">
                                        <?php echo htmlspecialchars($v['venue_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label for="edit_court_name" class="form-label form-label-custom">Court Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control form-control-custom" id="edit_court_name" name="court_name" maxlength="50" required>
                        </div>

                        <div class="row g-3 mb-3">
                            <div class="col-12 col-sm-6">
                                <label for="edit_court_type" class="form-label form-label-custom">Court Type</label>
                                <select class="form-select form-control-custom" id="edit_court_type" name="court_type" required>
                                    <option value="Indoor">Indoor</option>
                                    <option value="Outdoor">Outdoor</option>
                                </select>
                            </div>
                            <div class="col-12 col-sm-6">
                                <label for="edit_surface_type" class="form-label form-label-custom">Surface</label>
                                <select class="form-select form-control-custom" id="edit_surface_type" name="surface_type" required>
                                    <option value="Acrylic Hardcourt">Acrylic Hardcourt</option>
                                    <option value="Cushioned Acrylic">Cushioned Acrylic</option>
                                    <option value="Asphalt">Asphalt</option>
                                    <option value="Concrete">Concrete</option>
                                    <option value="Polypropylene Modular">Polypropylene Modular</option>
                                    <option value="Wood / Gymnasium">Wood / Gymnasium</option>
                                </select>
                            </div>
                        </div>

                        <div class="mb-0">
                            <label for="edit_court_photo" class="form-label form-label-custom">Replace Court Photo <span class="text-stone small">(Optional)</span></label>
                            <input type="file" class="form-control form-control-custom" id="edit_court_photo" name="court_photo" accept=".jpg,.jpeg,.png,.webp">
                        </div>
                    </div>
                    <div class="modal-footer border-top px-4 py-3">
                        <button type="button" class="btn btn-light rounded-pill px-4" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary px-4">Save Changes</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- MODAL 2: Edit Venue Details -->
    <div class="modal fade" id="editVenueModal" tabindex="-1" aria-labelledby="editVenueModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content rounded-4 border-0 shadow">
                <form action="manage_courts.php" method="POST" enctype="multipart/form-data">
                    <div class="modal-header border-bottom px-4 py-3">
                        <h3 class="modal-title h5 fw-bold" id="editVenueModalLabel">Edit Venue Details</h3>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body p-4">
                        <input type="hidden" name="action" value="edit_venue">
                        <input type="hidden" name="dir_tab" value="venues">
                        <input type="hidden" name="venue_id" id="edit_venue_id">

                        <div class="text-center mb-3">
                            <img id="edit_venue_preview" src="../assets/Venue Icon.png" alt="Venue Preview" class="rounded-4 border" width="140" height="96" style="object-fit: cover;">
                        </div>

                        <div class="mb-3">
                            <label for="edit_venue_name" class="form-label form-label-custom">Venue Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control form-control-custom" id="edit_venue_name" name="venue_name" maxlength="50" required>
                        </div>

                        <div class="mb-3">
                            <label for="edit_venue_address" class="form-label form-label-custom">Street Address <span class="text-danger">*</span></label>
                            <input type="text" class="form-control form-control-custom" id="edit_venue_address" name="address" maxlength="50" required>
                        </div>

                        <div class="mb-3">
                            <label for="edit_venue_city" class="form-label form-label-custom">City / Municipality</label>
                            <input type="text" class="form-control form-control-custom" id="edit_venue_city" name="city" maxlength="50">
                        </div>

                        <div class="mb-0">
                            <label for="edit_venue_photo" class="form-label form-label-custom">Replace Cover Photo <span class="text-stone small">(Optional)</span></label>
                            <input type="file" class="form-control form-control-custom" id="edit_venue_photo" name="venue_photo" accept=".jpg,.jpeg,.png,.webp">
                        </div>
                    </div>
                    <div class="modal-footer border-top px-4 py-3">
                        <button type="button" class="btn btn-light rounded-pill px-4" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary px-4">Save Changes</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Bootstrap 5.2.3 JS Bundle -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-kenU1KFdBIe4zVF0s0G1M5b4hcpxyD9F7jL+jjXkk+Q2h455rYXK/7HAuoJl+0I4" crossorigin="anonymous"></script>

    <!-- Populate Edit Modals & Live Image Preview -->
    <script>
        const editCourtModal = document.getElementById('editCourtModal');
        if (editCourtModal) {
            editCourtModal.addEventListener('show.bs.modal', function (event) {
                const trigger = event.relatedTarget;
                document.getElementById('edit_court_id').value = trigger.getAttribute('data-court-id');
                document.getElementById('edit_court_venue_id').value = trigger.getAttribute('data-venue-id');
                document.getElementById('edit_court_name').value = trigger.getAttribute('data-court-name');
                document.getElementById('edit_court_type').value = trigger.getAttribute('data-court-type');
                document.getElementById('edit_surface_type').value = trigger.getAttribute('data-surface-type');
                document.getElementById('edit_court_preview').src = trigger.getAttribute('data-photo-src');
                document.getElementById('edit_court_photo').value = '';
            });
        }

        const editCourtPhotoInput = document.getElementById('edit_court_photo');
        if (editCourtPhotoInput) {
            editCourtPhotoInput.addEventListener('change', function (e) {
                if (e.target.files && e.target.files[0]) {
                    document.getElementById('edit_court_preview').src = URL.createObjectURL(e.target.files[0]);
                }
            });
        }

        const editVenueModal = document.getElementById('editVenueModal');
        if (editVenueModal) {
            editVenueModal.addEventListener('show.bs.modal', function (event) {
                const trigger = event.relatedTarget;
                document.getElementById('edit_venue_id').value = trigger.getAttribute('data-venue-id');
                document.getElementById('edit_venue_name').value = trigger.getAttribute('data-venue-name');
                document.getElementById('edit_venue_address').value = trigger.getAttribute('data-venue-address');
                document.getElementById('edit_venue_city').value = trigger.getAttribute('data-venue-city');
                document.getElementById('edit_venue_preview').src = trigger.getAttribute('data-photo-src');
                document.getElementById('edit_venue_photo').value = '';
            });
        }

        const editVenuePhotoInput = document.getElementById('edit_venue_photo');
        if (editVenuePhotoInput) {
            editVenuePhotoInput.addEventListener('change', function (e) {
                if (e.target.files && e.target.files[0]) {
                    document.getElementById('edit_venue_preview').src = URL.createObjectURL(e.target.files[0]);
                }
            });
        }
    </script>
</body>
</html>