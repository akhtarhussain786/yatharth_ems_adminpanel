<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Yatharth EMS - Employee Management System </title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <?php // ?v= is the file's own timestamp, so a changed stylesheet reaches
          // browsers that are still holding the previous one in cache. ?>
    <link href="<?php echo BASE_URL; ?>css/style.css?v=<?php echo @filemtime(__DIR__ . '/../css/style.css') ?: '1'; ?>" rel="stylesheet">
    
</head>
<body>
<?php if (isLoggedIn()): ?>

<div class="sidebar-overlay" id="sidebarOverlay" onclick="toggleSidebar()" style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.4);z-index:1045;"></div>

<aside class="sidebar" id="sidebar">
    <div class="sidebar-brand">
        <div class="brand-logo"><i class="fa-solid fa-y"></i></div>
        <div class="brand-text">
            <h6>Yatharth EMS</h6>
<small>Employee Management System</small>
        </div>
    </div>
    <div class="sidebar-menu">
        <div class="menu-label">Main Menu</div>
        <ul class="list-unstyled mb-0">
            <?php foreach (getMenuItems() as $item):
                if (!empty($item['module']) && !hasModuleAccess($item['module'])) continue;
                $mn = basename($item['link'], '.php');
                $active = $current_page == $mn || strpos($item['link'], 'modules/' . $current_page) !== false;
            ?>
            <li class="nav-item">
                <a class="nav-link <?php echo $active ? 'active' : ''; ?>" 
                   href="<?php echo BASE_URL . $item['link']; ?>">
                   <i class="fas fa-<?php echo $item['icon']; ?>"></i>
                   <span><?php echo $item['label']; ?></span>
                </a>
            </li>
            <?php endforeach; ?>
        </ul>
    </div>
</aside>

<div class="main-content" id="mainContent">

<header class="main-header">
    <div class="header-left">
        <button class="sidebar-toggle" onclick="toggleSidebar()">
            <i class="fas fa-bars"></i>
        </button>
        <div class="header-search">
            <i class="fas fa-search"></i>
            <input type="text" class="form-control" placeholder="Search menu, employees, reports..." id="globalSearch">
        </div>
    </div>
    <div class="header-right">
        <div class="dropdown">
            <button class="header-btn" data-bs-toggle="dropdown" aria-expanded="false" id="notifBtn">
                <i class="fas fa-bell"></i>
                <span class="notif-dot" id="notifDot"></span>
            </button>
            <div class="dropdown-menu dropdown-menu-end notif-dropdown" id="notifDropdown">
                <div class="notif-header">
                    <h6>Notifications</h6>
                    <button class="btn btn-xs btn-outline-primary" onclick="markAllRead()">Mark all read</button>
                </div>
                <div class="notif-body" id="notifBody">
                    <div class="text-center text-muted py-4" style="font-size:0.8rem;">
                        <i class="fas fa-circle-notch fa-spin me-1"></i> Loading...
                    </div>
                </div>
                <div class="notif-footer">
                    <a href="<?php echo BASE_URL; ?>modules/notifications.php">View all notifications</a>
                </div>
            </div>
        </div>
        <div class="dropdown">
            <button class="user-dropdown" data-bs-toggle="dropdown" aria-expanded="false">
                <div class="user-avatar">
                    <?php echo strtoupper(substr(sanitize($_SESSION['admin_name'] ?? 'A'), 0, 1)); ?>
                </div>
                <div class="user-info d-none d-md-block">
                    <div class="name"><?php echo sanitize($_SESSION['admin_name'] ?? 'Admin'); ?></div>
                    <div class="role"><?php echo sanitize($_SESSION['admin_role_display'] ?? getAdminRoleName()); ?></div>
                </div>
                <i class="fas fa-chevron-down" style="font-size:0.65rem;color:var(--gray-400);"></i>
            </button>
            <ul class="dropdown-menu dropdown-menu-end">
                <li><a class="dropdown-item" href="#"><i class="fas fa-user"></i> Profile</a></li>
                <li><a class="dropdown-item" href="<?php echo BASE_URL; ?>modules/attendance.php"><i class="fas fa-calendar-check"></i> My Attendance</a></li>
                <li><hr class="dropdown-divider"></li>
                <li><a class="dropdown-item" href="#"><i class="fas fa-cog"></i> Settings</a></li>
                <li><a class="dropdown-item" href="#"><i class="fas fa-key"></i> Change Password</a></li>
                <li><hr class="dropdown-divider"></li>
                <li>
                    <a class="dropdown-item text-danger" href="<?php echo BASE_URL; ?>logout.php">
                        <i class="fas fa-sign-out-alt"></i> Logout
                    </a>
                </li>
            </ul>
        </div>
    </div>
</header>

<div class="page-content">

<?php endif; ?>
