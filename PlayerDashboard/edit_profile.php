<?php
session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Player') {
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

$success_message = "";
$error_message = "";

// Fetch current player profile
$sql_user = "SELECT email, first_name, last_name, gender, city, mobile_number, skill_level, avatar_url FROM users WHERE user_id = ?";
$stmt_user = sqlsrv_query($conn, $sql_user, [$user_id]);

if ($stmt_user && sqlsrv_has_rows($stmt_user)) {
    $row = sqlsrv_fetch_array($stmt_user, SQLSRV_FETCH_ASSOC);
    $email = $row['email'];
    $first_name = $row['first_name'];
    $last_name = $row['last_name'];
    $gender = $row['gender'];
    $city = $row['city'];
    $mobile_number = $row['mobile_number'];
    $skill_level = $row['skill_level'];
    $avatar_filename = !empty($row['avatar_url']) ? $row['avatar_url'] : 'default_avatar.png';
} else {
    die("Error retrieving user profile.");
}

// Handle profile update submission
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $new_email = trim($_POST['email']);
    $new_password = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    $new_first_name = trim($_POST['first_name']);
    $new_last_name = trim($_POST['last_name']);
    $new_gender = $_POST['gender'] ?? '';
    $new_city = trim($_POST['city']);
    $new_mobile_number = trim($_POST['mobile_number']);
    $new_skill_level = !empty($_POST['skill_level']) ? $_POST['skill_level'] : null;

    $updated_avatar = $avatar_filename;

    // Validate required NOT NULL fields
    if (empty($new_first_name) || empty($new_last_name) || empty($new_gender) || empty($new_city) || empty($new_mobile_number)) {
        $error_message = "Please fill in all required personal information fields.";
    }

    // Validate email format and check if taken by another user
    if (empty($error_message)) {
        if (empty($new_email) || !filter_var($new_email, FILTER_VALIDATE_EMAIL)) {
            $error_message = "Please enter a valid email address.";
        } else {
            $sql_check_email = "SELECT user_id FROM users WHERE email = ? AND user_id != ?";
            $stmt_check_email = sqlsrv_query($conn, $sql_check_email, [$new_email, $user_id]);
            if ($stmt_check_email && sqlsrv_has_rows($stmt_check_email)) {
                $error_message = "That email address is already registered to another account.";
            }
        }
    }

    // Validate password change if a new password was entered
    if (empty($error_message) && $new_password !== '') {
        if ($new_password !== $confirm_password) {
            $error_message = "New password and confirmation do not match.";
        }
    }

    // Handle avatar upload with standardized naming scheme: avatar_u{user_id}_{YYYYMMDD}_{uniqid}.{ext}
    if (empty($error_message) && isset($_FILES['avatar_url']) && $_FILES['avatar_url']['error'] === UPLOAD_ERR_OK) {
        $destination = "../avatars/";
        if (!is_dir($destination)) {
            mkdir($destination, 0777, true);
        }

        $allowed_extensions = ['jpg', 'jpeg', 'png'];
        $filetype = strtolower(pathinfo($_FILES['avatar_url']['name'], PATHINFO_EXTENSION));

        if (in_array($filetype, $allowed_extensions, true)) {
            $new_filename = uniqid() . "." . $filetype;
            $target_filepath = $destination . $new_filename;

            if (move_uploaded_file($_FILES['avatar_url']['tmp_name'], $target_filepath)) {
                $updated_avatar = $new_filename;
            } else {
                $error_message = "Failed to upload the new profile photo.";
            }
        } else {
            $error_message = "Invalid file format. Please upload a JPG, JPEG, or PNG image.";
        }
    }

    // Execute database update
    if (empty($error_message)) {
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
                    skill_level = ?, 
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
                $new_skill_level,
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
                    skill_level = ?, 
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
                $new_skill_level,
                $updated_avatar,
                $user_id
            ];
        }

        $result = sqlsrv_query($conn, $sql_update, $params);

        if ($result) {
            $_SESSION['first_name'] = $new_first_name;
            $email = $new_email;
            $first_name = $new_first_name;
            $last_name = $new_last_name;
            $gender = $new_gender;
            $city = $new_city;
            $mobile_number = $new_mobile_number;
            $skill_level = $new_skill_level;
            $avatar_filename = $updated_avatar;
            $success_message = "Your profile and account settings have been updated successfully.";
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
    <title>Edit Profile - RALLIQ</title>
    
    <!-- Bootstrap 5.2.3 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-rbsA2VBKQhggwzxH7pPCaAqO46MgnOM80zW1RWuH61DGLwZJEdK2Kadq2F9CUG65" crossorigin="anonymous">
    
    <!-- RalliQ Organic Sport Theme -->
    <link rel="stylesheet" href="../ralliq-theme.css?v=2">
</head>
<body>

    <!-- Top Navigation Bar -->
    <nav class="navbar navbar-expand-lg bg-white sticky-top shadow-sm py-3">
        <div class="container">
            <a class="navbar-brand" href="player_dashboard.php">
                <img src="../assets/RalliQ Main Logo.png" alt="RALLIQ" height="30">
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#playerNav" aria-controls="playerNav" aria-expanded="false" aria-label="Toggle navigation">
                <span class="navbar-toggler-icon"></span>
            </button>
            
            <div class="collapse navbar-collapse" id="playerNav">
                <ul class="navbar-nav me-auto mb-2 mb-lg-0 fw-semibold">
                    <li class="nav-item"><a class="nav-link text-stone" href="player_dashboard.php">Dashboard</a></li>
                    <li class="nav-item"><a class="nav-link text-stone" href="discover.php">Discover Courts</a></li>
                    <li class="nav-item"><a class="nav-link text-stone" href="open_plays.php">Open Play</a></li>
                    <li class="nav-item"><a class="nav-link text-stone" href="play_history.php">Play History</a></li>
                </ul>
                
                <div class="d-flex align-items-center gap-3">
                    <a href="edit_profile.php" class="text-decoration-none fw-semibold" style="color: var(--color-forest);">
                        <img src="../avatars/<?php echo htmlspecialchars($display_avatar); ?>" alt="Profile" class="nav-avatar me-2">
                        My Profile
                    </a>
                    <a href="../logout.php" class="btn btn-sm btn-outline-secondary" style="border-radius: var(--radius-button);">Logout</a>
                </div>
            </div>
        </div>
    </nav>

    <!-- Banner & Overlapping Avatar -->
    <div class="container-fluid p-0">
        <div class="profile-banner">
            <div class="avatar-wrapper">
                <img id="avatarPreview" src="../avatars/<?php echo htmlspecialchars($display_avatar); ?>" alt="Player Avatar" class="avatar-circle-large">
                <label for="avatar_url_input" class="edit-camera-trigger" title="Change Profile Photo">
                    <img src="../assets/Edit Camera Icon.png" alt="Edit Photo">
                </label>
            </div>
        </div>
    </div>

    <!-- Profile Form Container -->
    <div class="container mb-5">
        <div class="row justify-content-center">
            <div class="col-12 col-md-10 col-lg-8">

                <?php if (!empty($success_message)): ?>
                    <div class="alert alert-success alert-dismissible fade show rounded-3" role="alert">
                        <?php echo htmlspecialchars($success_message); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>

                <?php if (!empty($error_message)): ?>
                    <div class="alert alert-danger alert-dismissible fade show rounded-3" role="alert">
                        <?php echo htmlspecialchars($error_message); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>

                <div class="card card-custom p-4 p-md-5">
                    <div class="card-body p-0">
                        <div class="text-center mb-4">
                            <h1 class="h3 fw-bold mb-1">Edit Profile</h1>
                            <p class="text-stone mb-0">Click the camera icon above to update your photo, or modify your account and player details below.</p>
                        </div>

                        <form action="edit_profile.php" method="POST" enctype="multipart/form-data">
                            <!-- Hidden File Input Linked to Camera Icon -->
                            <input type="file" id="avatar_url_input" name="avatar_url" accept=".jpg, .jpeg, .png" onchange="previewAvatar(event)">

                            <!-- Section 1: Account Credentials -->
                            <h5 class="fw-bold mb-3">Account Credentials</h5>

                            <div class="mb-3">
                                <label for="email" class="form-label form-label-custom">Email Address *</label>
                                <input type="email" class="form-control form-control-custom" id="email" name="email" value="<?php echo htmlspecialchars($email ?? ''); ?>" required>
                            </div>

                            <div class="row g-3 mb-4">
                                <div class="col-md-6">
                                    <label for="new_password" class="form-label form-label-custom">New Password</label>
                                    <input type="password" class="form-control form-control-custom" id="new_password" name="new_password">
                                </div>
                                <div class="col-md-6">
                                    <label for="confirm_password" class="form-label form-label-custom">Confirm New Password</label>
                                    <input type="password" class="form-control form-control-custom" id="confirm_password" name="confirm_password">
                                </div>
                            </div>

                            <!-- Section 2: Personal Information -->
                            <h5 class="fw-bold mb-3 mt-4">Personal Information</h5>

                            <div class="row g-3 mb-4">
                                <div class="col-md-6">
                                    <label for="first_name" class="form-label form-label-custom">First Name *</label>
                                    <input type="text" class="form-control form-control-custom" id="first_name" name="first_name" value="<?php echo htmlspecialchars($first_name ?? ''); ?>" required>
                                </div>
                                <div class="col-md-6">
                                    <label for="last_name" class="form-label form-label-custom">Last Name *</label>
                                    <input type="text" class="form-control form-control-custom" id="last_name" name="last_name" value="<?php echo htmlspecialchars($last_name ?? ''); ?>" required>
                                </div>
                            </div>

                            <div class="row g-3 mb-4">
                                <div class="col-md-4">
                                    <label for="gender" class="form-label form-label-custom">Gender *</label>
                                    <select class="form-control form-control-custom" id="gender" name="gender" required>
                                        <option value="Male" <?php echo ($gender === 'Male') ? 'selected' : ''; ?>>Male</option>
                                        <option value="Female" <?php echo ($gender === 'Female') ? 'selected' : ''; ?>>Female</option>
                                        <option value="Other" <?php echo ($gender === 'Other') ? 'selected' : ''; ?>>Other</option>
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <label for="city" class="form-label form-label-custom">City *</label>
                                    <input type="text" class="form-control form-control-custom" id="city" name="city" value="<?php echo htmlspecialchars($city ?? ''); ?>" required>
                                </div>
                                <div class="col-md-4">
                                    <label for="mobile_number" class="form-label form-label-custom">Mobile Number *</label>
                                    <input type="tel" class="form-control form-control-custom" id="mobile_number" name="mobile_number" value="<?php echo htmlspecialchars($mobile_number ?? ''); ?>" required>
                                </div>
                            </div>

                            <!-- Section 3: Pickleball Profile -->
                            <h5 class="fw-bold mb-3 mt-4">Pickleball Profile</h5>
                            <div class="row g-3 mb-5">
                                <div class="col-12">
                                    <label for="skill_level" class="form-label form-label-custom">Skill Level</label>
                                    <select class="form-control form-control-custom" id="skill_level" name="skill_level">
                                        <option value="">Select Skill Level (Optional)</option>
                                        <option value="Beginner" <?php echo ($skill_level === 'Beginner') ? 'selected' : ''; ?>>Beginner</option>
                                        <option value="Intermediate" <?php echo ($skill_level === 'Intermediate') ? 'selected' : ''; ?>>Intermediate</option>
                                        <option value="Advanced" <?php echo ($skill_level === 'Advanced') ? 'selected' : ''; ?>>Advanced</option>
                                    </select>
                                </div>
                            </div>

                            <div class="d-flex justify-content-end gap-2 border-top pt-4">
                                <a href="player_dashboard.php" class="btn btn-outline-secondary px-4 py-2" style="border-radius: var(--radius-button);">Cancel</a>
                                <button type="submit" class="btn btn-primary-custom px-5 py-2">Save Changes</button>
                            </div>
                        </form>
                    </div>
                </div>

            </div>
        </div>
    </div>

    <!-- Bootstrap 5.2.3 JS Bundle -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-kenU1KFdBIe4zVF0s0G1M5b4hcpxyD9F7jL+jjXkk+Q2h455rYXK/7HAuoJl+0I4" crossorigin="anonymous"></script>

    <!-- Instant Avatar Preview -->
    <script>
        function previewAvatar(event) {
            const file = event.target.files[0];
            if (file) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    document.getElementById('avatarPreview').src = e.target.result;
                };
                reader.readAsDataURL(file);
            }
        }
    </script>
</body>
</html>