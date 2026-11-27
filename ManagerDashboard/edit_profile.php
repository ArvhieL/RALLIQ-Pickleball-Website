<?php
session_start();

// Enforce Role-Based Access Control (RBAC) - Restrict to Managers only
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Manager') {
    header("Location: ../login.php");
    exit();
}

$user_id = $_SESSION['user_id'];

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

// Retrieve PRG flash messages if available
$success_message = $_SESSION['flash_success'] ?? "";
$error_message   = $_SESSION['flash_error'] ?? "";
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

// Fetch current manager profile
$sql_user = "SELECT email, first_name, last_name, gender, city, mobile_number, avatar_url FROM users WHERE user_id = ?";
$stmt_user = sqlsrv_query($conn, $sql_user, [$user_id]);

if ($stmt_user && sqlsrv_has_rows($stmt_user)) {
    $row             = sqlsrv_fetch_array($stmt_user, SQLSRV_FETCH_ASSOC);
    $email           = $row['email'];
    $first_name      = $row['first_name'];
    $last_name       = $row['last_name'];
    $gender          = $row['gender'];
    $city            = $row['city'];
    $mobile_number   = $row['mobile_number'];
    $avatar_filename = !empty($row['avatar_url']) ? $row['avatar_url'] : 'default_avatar.png';
} else {
    die("Error retrieving manager profile.");
}

// Handle profile update submission
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $new_email         = trim($_POST['email'] ?? '');
    $new_password      = $_POST['new_password'] ?? '';
    $confirm_password  = $_POST['confirm_password'] ?? '';
    $new_first_name    = trim($_POST['first_name'] ?? '');
    $new_last_name     = trim($_POST['last_name'] ?? '');
    $new_gender        = $_POST['gender'] ?? '';
    $new_city          = trim($_POST['city'] ?? '');
    $new_mobile_number = trim($_POST['mobile_number'] ?? '');

    // Preserve submitted inputs on validation error
    $email         = $new_email;
    $first_name    = $new_first_name;
    $last_name     = $new_last_name;
    $gender        = $new_gender;
    $city          = $new_city;
    $mobile_number = $new_mobile_number;

    $updated_avatar = $avatar_filename;

    // 1. Validate required NOT NULL fields
    if ($new_first_name === '' || $new_last_name === '' || $new_gender === '' || $new_city === '' || $new_mobile_number === '') {
        $error_message = "Please fill in all required administrator details.";
    } elseif (!in_array($new_gender, ['Male', 'Female', 'Other'], true)) {
        $error_message = "Please select a valid gender option.";
    }

    // 2. Validate email format and check if already used by another account
    if ($error_message === '') {
        if ($new_email === '' || !filter_var($new_email, FILTER_VALIDATE_EMAIL)) {
            $error_message = "Please enter a valid email address.";
        } else {
            $sql_check_email = "SELECT user_id FROM users WHERE email = ? AND user_id != ?";
            $stmt_check_email = sqlsrv_query($conn, $sql_check_email, [$new_email, $user_id]);
            if ($stmt_check_email && sqlsrv_has_rows($stmt_check_email)) {
                $error_message = "That email address is already registered to another account.";
            }
        }
    }

    // 3. Validate password change if a new password was entered
    if ($error_message === '' && $new_password !== '') {
        if ($new_password !== $confirm_password) {
            $error_message = "New password and confirmation do not match.";
        }
    }

    // 4. Handle avatar upload (short unique filename fits safely in nvarchar(50))
    if ($error_message === '' && isset($_FILES['avatar_url']) && $_FILES['avatar_url']['error'] === UPLOAD_ERR_OK) {
        $destination = "../avatars/";
        if (!is_dir($destination)) {
            mkdir($destination, 0777, true);
        }

        $allowed_extensions = ['jpg', 'jpeg', 'png', 'webp'];
        $filetype = strtolower(pathinfo($_FILES['avatar_url']['name'], PATHINFO_EXTENSION));

        if (in_array($filetype, $allowed_extensions, true)) {
            $new_filename = uniqid() . "." . $filetype;
            $target_filepath = $destination . $new_filename;

            if (move_uploaded_file($_FILES['avatar_url']['tmp_name'], $target_filepath)) {
                // Remove previous custom avatar file if one existed
                if ($avatar_filename !== 'default_avatar.png' && file_exists($destination . $avatar_filename)) {
                    @unlink($destination . $avatar_filename);
                }
                $updated_avatar = $new_filename;
            } else {
                $error_message = "Failed to upload the new profile photo.";
            }
        } else {
            $error_message = "Invalid file format. Please upload a JPG, JPEG, PNG, or WEBP image.";
        }
    }

    // 5. Execute parameterized database update (managers do not use skill_level)
    if ($error_message === '') {
        if ($new_password !== '') {
            $sql_update = "
                UPDATE users
                SET email = ?,
                    password = ?,
                    first_name = ?,
                    last_name = ?,
                    gender = ?,
                    city = ?,
                    mobile_number = ?,
                    avatar_url = ?
                WHERE user_id = ?
            ";
            $params = [
                $new_email,
                $new_password,
                $new_first_name,
                $new_last_name,
                $new_gender,
                $new_city,
                $new_mobile_number,
                $updated_avatar,
                $user_id
            ];
        } else {
            $sql_update = "
                UPDATE users
                SET email = ?,
                    first_name = ?,
                    last_name = ?,
                    gender = ?,
                    city = ?,
                    mobile_number = ?,
                    avatar_url = ?
                WHERE user_id = ?
            ";
            $params = [
                $new_email,
                $new_first_name,
                $new_last_name,
                $new_gender,
                $new_city,
                $new_mobile_number,
                $updated_avatar,
                $user_id
            ];
        }

        $result = sqlsrv_query($conn, $sql_update, $params);

        if ($result) {
            $_SESSION['first_name'] = $new_first_name;
            $_SESSION['flash_success'] = "Your manager profile and headquarters settings have been updated successfully.";
            header("Location: edit_profile.php");
            exit();
        } else {
            $error_message = "Error updating profile. Please try again.";
        }
    }
}

$display_avatar = file_exists("../avatars/" . $avatar_filename) ? $avatar_filename : "default_avatar.png";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manager Settings - RALLIQ</title>
    <!-- Bootstrap 5.2.3 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-rbsA2VBKQhggwzxH7pPCaAqO46MgnOM80zW1RWuH61DGLwZJEdK2Kadq2F9CUG65" crossorigin="anonymous">
    <!-- RALLIQ Court Orange & Deep Slate Theme -->
    <link rel="stylesheet" href="../ralliq-theme.css?v=4">
</head>
<body>

    <!-- Sticky Top Navigation Bar -->
    <nav class="navbar navbar-expand-lg navbar-ralliq sticky-top py-3">
        <div class="container">
            <a class="navbar-brand d-flex align-items-center gap-2" href="manager_dashboard.php">
                <img src="../assets/RalliQ Main Logo.png?=v2" alt="RALLIQ Logo" height="30">
                <span class="badge rounded-pill badge-slate px-2 py-1">ADMIN</span>
            </a>

            <button class="navbar-toggler border-0" type="button" data-bs-toggle="collapse" data-bs-target="#managerNavbar" aria-controls="managerNavbar" aria-expanded="false" aria-label="Toggle navigation">
                <span class="navbar-toggler-icon"></span>
            </button>

            <div class="collapse navbar-collapse" id="managerNavbar">
                <ul class="navbar-nav me-auto mb-2 mb-lg-0 ms-lg-4 gap-lg-2">
                    <li class="nav-item">
                        <a class="nav-link" href="manager_dashboard.php">Dashboard</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="manage_courts.php">Venue &amp; Courts</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="facility_scheduling.php">Facility Scheduling</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="manage_open_plays.php">Open Plays</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="manage_rentals.php">Court Rentals</a>
                    </li>
                </ul>

                <div class="d-flex align-items-center gap-3">
                    <a href="edit_profile.php" class="d-flex align-items-center text-decoration-none gap-2">
                        <img src="../avatars/<?php echo htmlspecialchars($display_avatar); ?>" onerror="this.src='../avatars/default_avatar.png';" alt="Manager Avatar" class="nav-avatar">
                        <span class="fw-semibold small text-orange">My Profile</span>
                    </a>
                    <a href="../logout.php" class="btn btn-sm btn-outline-danger rounded-pill px-3 fw-semibold">Logout</a>
                </div>
            </div>
        </div>
    </nav>

    <!-- Clean Page Header Strip -->
    <header class="page-header-strip">
        <div class="container">
            <div class="row align-items-center g-3">
                <div class="col-md-8">
                    <span class="badge rounded-pill badge-soft-orange px-3 py-1 mb-2">Facility Manager HQ</span>
                    <h1 class="h3 fw-bold mb-1">Manager Settings</h1>
                    <p class="text-stone mb-0">Update your HQ profile photo, login credentials, and administrator contact details.</p>
                </div>
                <div class="col-md-4 text-md-end">
                    <a href="manager_dashboard.php" class="btn btn-outline-secondary rounded-pill px-4 fw-semibold">Back to Dashboard</a>
                </div>
            </div>
        </div>
    </header>

    <!-- Main Profile Settings Container -->
    <main class="container mb-5">
        <div class="row justify-content-center">
            <div class="col-12 col-md-10 col-lg-8">

                <?php if (!empty($success_message)): ?>
                    <div class="alert alert-success alert-dismissible fade show rounded-3 border-0 shadow-sm mb-4" role="alert">
                        <?php echo htmlspecialchars($success_message); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>

                <?php if (!empty($error_message)): ?>
                    <div class="alert alert-danger alert-dismissible fade show rounded-3 border-0 shadow-sm mb-4" role="alert">
                        <?php echo htmlspecialchars($error_message); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>

                <div class="card card-custom p-4 p-md-5">
                    <div class="card-body p-0">

                        <!-- Current Avatar & Account Summary -->
                        <div class="d-flex align-items-center gap-3 pb-4 mb-4 border-bottom">
                            <img src="../avatars/<?php echo htmlspecialchars($display_avatar); ?>" onerror="this.src='../avatars/default_avatar.png';" alt="Manager Avatar" class="avatar-circle">
                            <div>
                                <h2 class="h4 fw-bold mb-1"><?php echo htmlspecialchars($first_name . " " . $last_name); ?></h2>
                                <p class="small text-stone mb-0"><?php echo htmlspecialchars($email ?? ''); ?></p>
                            </div>
                        </div>

                        <form action="edit_profile.php" method="POST" enctype="multipart/form-data">

                            <!-- Profile Photo Upload -->
                            <div class="mb-4">
                                <label for="avatar_url" class="form-label form-label-custom">Profile Photo <span class="text-stone small">(Optional — JPG, PNG, or WEBP)</span></label>
                                <input type="file" class="form-control form-control-custom" id="avatar_url" name="avatar_url" accept=".jpg, .jpeg, .png, .webp">
                            </div>

                            <hr class="my-4">

                            <!-- Section 1: Account Credentials -->
                            <h5 class="fw-bold mb-3">Account Credentials</h5>
                            <div class="mb-3">
                                <label for="email" class="form-label form-label-custom">Email Address <span class="text-danger">*</span></label>
                                <input type="email" class="form-control form-control-custom" id="email" name="email" maxlength="50" value="<?php echo htmlspecialchars($email ?? ''); ?>" required>
                            </div>

                            <div class="row g-3 mb-4">
                                <div class="col-md-6">
                                    <label for="new_password" class="form-label form-label-custom">New Password</label>
                                    <input type="password" class="form-control form-control-custom" id="new_password" name="new_password" maxlength="50" placeholder="Leave blank to keep current">
                                </div>
                                <div class="col-md-6">
                                    <label for="confirm_password" class="form-label form-label-custom">Confirm New Password</label>
                                    <input type="password" class="form-control form-control-custom" id="confirm_password" name="confirm_password" maxlength="50" placeholder="Re-enter new password">
                                </div>
                            </div>

                            <hr class="my-4">

                            <!-- Section 2: Administrator Details -->
                            <h5 class="fw-bold mb-3">Administrator Details</h5>
                            <div class="row g-3 mb-3">
                                <div class="col-md-6">
                                    <label for="first_name" class="form-label form-label-custom">First Name <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control form-control-custom" id="first_name" name="first_name" maxlength="50" value="<?php echo htmlspecialchars($first_name ?? ''); ?>" required>
                                </div>
                                <div class="col-md-6">
                                    <label for="last_name" class="form-label form-label-custom">Last Name <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control form-control-custom" id="last_name" name="last_name" maxlength="50" value="<?php echo htmlspecialchars($last_name ?? ''); ?>" required>
                                </div>
                            </div>

                            <div class="row g-3 mb-4">
                                <div class="col-md-4">
                                    <label for="gender" class="form-label form-label-custom">Gender <span class="text-danger">*</span></label>
                                    <select class="form-select form-control-custom" id="gender" name="gender" required>
                                        <option value="Male" <?php echo ($gender === 'Male') ? 'selected' : ''; ?>>Male</option>
                                        <option value="Female" <?php echo ($gender === 'Female') ? 'selected' : ''; ?>>Female</option>
                                        <option value="Other" <?php echo ($gender === 'Other') ? 'selected' : ''; ?>>Other</option>
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <label for="city" class="form-label form-label-custom">City <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control form-control-custom" id="city" name="city" maxlength="50" value="<?php echo htmlspecialchars($city ?? ''); ?>" required>
                                </div>
                                <div class="col-md-4">
                                    <label for="mobile_number" class="form-label form-label-custom">Mobile Number <span class="text-danger">*</span></label>
                                    <input type="tel" class="form-control form-control-custom" id="mobile_number" name="mobile_number" maxlength="50" value="<?php echo htmlspecialchars($mobile_number ?? ''); ?>" required>
                                </div>
                            </div>

                            <div class="d-flex justify-content-end gap-2 border-top pt-4">
                                <a href="manager_dashboard.php" class="btn btn-outline-secondary rounded-pill px-4 fw-semibold">Cancel</a>
                                <button type="submit" class="btn btn-primary px-5">Save Changes</button>
                            </div>
                        </form>
                    </div>
                </div>

            </div>
        </div>
    </main>

    <!-- Bootstrap 5.2.3 JS Bundle -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-kenU1KFdBIe4zVF0s0G1M5b4hcpxyD9F7jL+jjXkk+Q2h455rYXK/7HAuoJl+0I4" crossorigin="anonymous"></script>
</body>
</html>