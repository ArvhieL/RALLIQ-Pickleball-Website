<?php
$active_page    = $active_page ?? '';
$display_avatar = !empty($display_avatar) ? $display_avatar : 'default_avatar.png';
?>
<nav class="navbar navbar-expand-lg navbar-ralliq sticky-top py-3">
    <div class="container">
        <a class="navbar-brand d-flex align-items-center gap-2" href="player_dashboard.php">
            <img src="../assets/RalliQ Main Logo.png" alt="RALLIQ Logo" height="30">
            <span class="badge rounded-pill badge-soft-orange px-2 py-1" style="font-size: 0.7rem; letter-spacing: 0.5px;">PLAYER</span>
        </a>

        <button class="navbar-toggler border-0" type="button" data-bs-toggle="collapse" data-bs-target="#playerNavbar" aria-controls="playerNavbar" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>

        <div class="collapse navbar-collapse" id="playerNavbar">
            <ul class="navbar-nav me-auto mb-2 mb-lg-0 ms-lg-4 gap-lg-2">
                <li class="nav-item">
                    <a class="nav-link <?php echo $active_page === 'dashboard' ? 'active' : ''; ?>" href="player_dashboard.php">Dashboard</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo $active_page === 'discover' ? 'active' : ''; ?>" href="discover.php">Discover Courts</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo $active_page === 'open_plays' ? 'active' : ''; ?>" href="open_plays.php">Open Plays</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo $active_page === 'history' ? 'active' : ''; ?>" href="play_history.php">Play History</a>
                </li>
            </ul>

            <div class="d-flex align-items-center gap-3">
                <a href="edit_profile.php" class="d-flex align-items-center text-decoration-none gap-2">
                    <img src="../avatars/<?php echo htmlspecialchars($display_avatar); ?>"
                         onerror="this.src='../avatars/default_avatar.png';"
                         alt="Player Avatar"
                         class="nav-avatar">
                    <span class="fw-semibold small <?php echo $active_page === 'profile' ? 'text-orange' : 'text-dark'; ?>">My Profile</span>
                </a>
                <a href="../logout.php" class="btn btn-sm btn-outline-danger rounded-pill px-3 fw-semibold">Logout</a>
            </div>
        </div>
    </div>
</nav>