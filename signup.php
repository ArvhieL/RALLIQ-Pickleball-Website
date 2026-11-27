<?php
session_start();

// 1. Redirect already-authenticated users to their respective dashboards
if (isset($_SESSION['user_id']) && isset($_SESSION['role'])) {
    if ($_SESSION['role'] === 'Manager') {
        header("Location: ManagerDashboard/manager_dashboard.php");
        exit();
    } elseif ($_SESSION['role'] === 'Player') {
        header("Location: PlayerDashboard/player_dashboard.php");
        exit();
    }
}

// 2. SQL Server Connection
$serverName = "LAPTOP-QIJJ9RAT\\SQLEXPRESS";
$connectionOptions = [
    "Database" => "FinalProject",
    "Uid"      => "",
    "PWD"      => ""
];

$conn = sqlsrv_connect($serverName, $connectionOptions);
if ($conn === false) {
    die(print_r(sqlsrv_errors(), true));
}

// 3. Handle Registration Form Submission
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $email         = isset($_POST['email']) ? trim($_POST['email']) : '';
    $password      = isset($_POST['password']) ? trim($_POST['password']) : '';
    $role          = isset($_POST['role']) ? trim($_POST['role']) : '';
    $first_name    = isset($_POST['first_name']) ? trim($_POST['first_name']) : '';
    $last_name     = isset($_POST['last_name']) ? trim($_POST['last_name']) : '';
    $gender        = isset($_POST['gender']) ? trim($_POST['gender']) : '';
    $city          = isset($_POST['city']) ? trim($_POST['city']) : '';
    $mobile_number = isset($_POST['mobile_number']) ? trim($_POST['mobile_number']) : '';
    $raw_skill     = isset($_POST['skill_level']) ? trim($_POST['skill_level']) : '';

    // Validate required NOT NULL fields & allowed roles
    if (
        $email === '' || $password === '' || $role === '' ||
        $first_name === '' || $last_name === '' ||
        $gender === '' || $city === '' || $mobile_number === ''
    ) {
        header("Location: signup.php?error=missing_fields");
        exit();
    }

    if (!in_array($role, ['Player', 'Manager'], true)) {
        header("Location: signup.php?error=invalid_role");
        exit();
    }

    // Store NULL for skill_level when Manager or left unselected
    $skill_level = ($role === 'Player' && $raw_skill !== '') ? $raw_skill : null;

    // Check if email already exists
    $sql_check  = "SELECT user_id FROM users WHERE email = ?";
    $stmt_check = sqlsrv_query($conn, $sql_check, [$email]);
    if ($stmt_check !== false && sqlsrv_has_rows($stmt_check)) {
        header("Location: signup.php?error=email_exists");
        exit();
    }

    // Handle Avatar Upload (short unique filename fits safely in nvarchar(50))
    $avatar_filename = "default_avatar.png";

    if (isset($_FILES['avatar_url']) && $_FILES['avatar_url']['error'] === UPLOAD_ERR_OK) {
        $destination = "avatars/";
        if (!is_dir($destination)) {
            mkdir($destination, 0777, true);
        }

        $ext         = strtolower(pathinfo($_FILES['avatar_url']['name'], PATHINFO_EXTENSION));
        $allowed_ext = ['jpg', 'jpeg', 'png', 'webp'];

        if (in_array($ext, $allowed_ext, true)) {
            $new_filename = uniqid() . "." . $ext;
            $target_path  = $destination . $new_filename;

            if (move_uploaded_file($_FILES['avatar_url']['tmp_name'], $target_path)) {
                $avatar_filename = $new_filename;
            }
        } else {
            header("Location: signup.php?error=invalid_avatar");
            exit();
        }
    }

    // Parameterized INSERT into dbo.users
    $sql_insert = "
        INSERT INTO users
            (email, password, role, first_name, last_name, gender, city, mobile_number, skill_level, avatar_url)
        VALUES
            (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ";
    $params = [
        $email,
        $password,
        $role,
        $first_name,
        $last_name,
        $gender,
        $city,
        $mobile_number,
        $skill_level,
        $avatar_filename
    ];

    $stmt_insert = sqlsrv_query($conn, $sql_insert, $params);

    if ($stmt_insert !== false) {
        header("Location: signup_success.php");
        exit();
    } else {
        header("Location: signup.php?error=db_error");
        exit();
    }
}

// 4. Resolve Error Alert Message
$error_message = '';
if (isset($_GET['error'])) {
    switch ($_GET['error']) {
        case 'missing_fields':
            $error_message = 'Please fill out all required fields marked with *.';
            break;
        case 'email_exists':
            $error_message = 'That email address is already registered. Please log in or use another email.';
            break;
        case 'invalid_avatar':
            $error_message = 'Profile photo must be a JPG, JPEG, PNG, or WEBP file.';
            break;
        case 'invalid_role':
            $error_message = 'Please select a valid account type.';
            break;
        default:
            $error_message = 'Unable to create your account right now. Please check your inputs and try again.';
            break;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign Up - RALLIQ</title>

    <!-- Bootstrap 5.2.3 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-rbsA2VBKQhggwzxH7pPCaAqO46MgnOM80zW1RWuH61DGLwZJEdK2Kadq2F9CUG65" crossorigin="anonymous">

    <!-- RALLIQ Orange & Slate Theme -->
    <link rel="stylesheet" href="ralliq-theme.css?v=4">

    <style>
        .signup-hero-panel {
            background-image:
                linear-gradient(135deg, rgba(30, 36, 43, 0.84) 0%, rgba(30, 36, 43, 0.58) 60%, rgba(255, 107, 43, 0.32) 100%),
                url('assets/RalliQ Background Splash 2.png');
            background-size: cover;
            background-position: center;
            min-height: 100vh;
            border-right: 4px solid var(--color-orange);
        }
    </style>
</head>
<body class="min-vh-100 p-0">

    <div class="container-fluid p-0">
        <div class="row g-0 min-vh-100">

            <!-- Left Column: Full-Height Splash Hero (Desktop) -->
            <div class="d-none d-lg-flex col-lg-5 signup-hero-panel align-items-center p-5">
                <div class="w-100 px-xl-4">
                    <h1 class="display-3 fw-bold text-white mb-3">JOIN<br>THE<br>CLUB.</h1>
                    <p class="fs-5 text-white opacity-75 mb-0" style="max-width: 400px;">
                        Create your RALLIQ profile to reserve courts, join open plays, or manage your pickleball facility.
                    </p>
                </div>
            </div>

            <!-- Right Column: Registration Form Card -->
            <div class="col-12 col-lg-7 d-flex flex-column justify-content-center p-4 p-md-5">
                <div class="w-100 mx-auto" style="max-width: 620px;">

                    <div class="card card-custom p-4 p-md-5">
                        <div class="card-body p-0">

                            <!-- Brand Logo & Subheading -->
                            <div class="text-center border-bottom pb-4 mb-4">
                                <img src="assets/RalliQ Main Logo.png" alt="RALLIQ Logo" class="img-fluid mb-3" style="max-width: 220px;">
                                <h2 class="h5 fw-bold mb-1">Create Your Account</h2>
                                <p class="small text-stone mb-0">Fill in your details below to get started on the court</p>
                            </div>

                            <!-- Validation & Error Alert -->
                            <?php if ($error_message !== ''): ?>
                                <div class="alert alert-danger text-center small fw-medium rounded-3 py-2 mb-4" role="alert">
                                    <?php echo htmlspecialchars($error_message); ?>
                                </div>
                            <?php endif; ?>

                            <!-- Sign Up Form -->
                            <form action="signup.php" method="POST" enctype="multipart/form-data">

                                <!-- Section 1: Account Credentials & Role -->
                                <h6 class="fw-bold mb-3">Account Details</h6>
                                <div class="row g-3 mb-4">
                                    <div class="col-md-6">
                                        <label for="email" class="form-label form-label-custom">Email Address *</label>
                                        <input type="email" class="form-control form-control-custom" id="email" name="email" placeholder="player@example.com" maxlength="50" required>
                                    </div>
                                    <div class="col-md-6">
                                        <label for="password" class="form-label form-label-custom">Password *</label>
                                        <input type="password" class="form-control form-control-custom" id="password" name="password" placeholder="Create a password" maxlength="50" required>
                                    </div>
                                    <div class="col-12">
                                        <label for="role" class="form-label form-label-custom">Account Type *</label>
                                        <select class="form-select form-control-custom" id="role" name="role" required>
                                            <option value="Player">Player</option>
                                            <option value="Manager">Facility Manager</option>
                                        </select>
                                    </div>
                                </div>

                                <!-- Section 2: Personal Information -->
                                <h6 class="fw-bold mb-3">Personal Information</h6>
                                <div class="row g-3 mb-4">
                                    <div class="col-md-6">
                                        <label for="first_name" class="form-label form-label-custom">First Name *</label>
                                        <input type="text" class="form-control form-control-custom" id="first_name" name="first_name" placeholder="Juan" maxlength="50" required>
                                    </div>
                                    <div class="col-md-6">
                                        <label for="last_name" class="form-label form-label-custom">Last Name *</label>
                                        <input type="text" class="form-control form-control-custom" id="last_name" name="last_name" placeholder="Dela Cruz" maxlength="50" required>
                                    </div>
                                    <div class="col-md-4">
                                        <label for="gender" class="form-label form-label-custom">Gender *</label>
                                        <select class="form-select form-control-custom" id="gender" name="gender" required>
                                            <option value="">Select Gender</option>
                                            <option value="Male">Male</option>
                                            <option value="Female">Female</option>
                                            <option value="Other">Other</option>
                                        </select>
                                    </div>
                                    <div class="col-md-4">
                                        <label for="city" class="form-label form-label-custom">City *</label>
                                        <input type="text" class="form-control form-control-custom" id="city" name="city" placeholder="Imus" maxlength="50" required>
                                    </div>
                                    <div class="col-md-4">
                                        <label for="mobile_number" class="form-label form-label-custom">Mobile Number *</label>
                                        <input type="tel" class="form-control form-control-custom" id="mobile_number" name="mobile_number" placeholder="09171234567" maxlength="50" required>
                                    </div>
                                </div>

                                <!-- Section 3: Profile Setup -->
                                <h6 class="fw-bold mb-3">Profile Setup</h6>
                                <div class="row g-3 mb-4">
                                    <div class="col-md-6" id="skill_level_group">
                                        <label for="skill_level" class="form-label form-label-custom">Skill Level</label>
                                        <select class="form-select form-control-custom" id="skill_level" name="skill_level">
                                            <option value="">Select Skill Level (Optional)</option>
                                            <option value="Beginner">Beginner</option>
                                            <option value="Intermediate">Intermediate</option>
                                            <option value="Advanced">Advanced</option>
                                        </select>
                                    </div>
                                    <div class="col-md-6" id="avatar_upload_group">
                                        <label for="avatar_url" class="form-label form-label-custom">Profile Photo</label>
                                        <input type="file" class="form-control form-control-custom" id="avatar_url" name="avatar_url" accept=".jpg, .jpeg, .png, .webp">
                                    </div>
                                </div>

                                <button type="submit" class="btn btn-primary w-100 py-2 fs-6 mt-2">
                                    Create Account
                                </button>
                            </form>

                            <!-- Login Footer Link -->
                            <div class="text-center border-top pt-4 mt-4">
                                <p class="small text-stone mb-1">Already have an account?</p>
                                <a href="login.php" class="text-orange fw-semibold text-decoration-none">
                                    Log In Here
                                </a>
                            </div>

                        </div>
                    </div>

                </div>
            </div>

        </div>
    </div>

    <!-- Bootstrap 5.2.3 JS Bundle -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-kenU1KFdBIe4zVF0s0G1M5b4hcpxyD9F7jL+jjXkk+Q2h455rYXK/7HAuoJl+0I4" crossorigin="anonymous"></script>
</body>
</html>