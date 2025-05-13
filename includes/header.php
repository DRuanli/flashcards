<?php
require_once dirname(__DIR__) . '/config.php';
// includes/header.php - Common header for all pages

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo SITE_NAME; ?> - 暗記カード</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+JP:wght@300;400;500;700&family=Shippori+Mincho:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css">
    <style>
        :root {
            --indigo: #3E4A89;
            --indigo-dark: #2A325F;
            --sakura: #FFB7C5;
            --sakura-light: #FFD8E0;
            --matcha: #8AA367;
            --matcha-light: #C5D5A9;
            --asagi: #7DB9DE;
            --kinari: #F9F6EE;
            --kuro: #333333;
            --gofun: #FFFFFB;
            --shadow: rgba(0, 0, 0, 0.1);
        }
        
        body {
            font-family: 'Noto Sans JP', sans-serif;
            background-color: var(--kinari);
            color: var(--kuro);
            line-height: 1.7;
        }
        
        h1, h2, h3, h4, h5, h6, .navbar-brand {
            font-family: 'Shippori Mincho', serif;
            font-weight: 600;
        }
        
        .bg-gradient-primary {
            background: linear-gradient(135deg, var(--indigo) 0%, var(--indigo-dark) 100%);
        }
        
        .navbar {
            background-color: var(--indigo) !important;
            box-shadow: 0 2px 4px var(--shadow);
        }
        
        .navbar-brand {
            font-size: 1.5rem;
            letter-spacing: 0.5px;
        }
        
        .navbar-brand .logo-accent {
            color: var(--sakura-light);
        }
        
        .nav-item {
            margin: 0 0.25rem;
        }
        
        .nav-link {
            position: relative;
            padding: 0.5rem 0.75rem !important;
            transition: all 0.3s ease;
        }
        
        .nav-link:hover {
            color: var(--sakura-light) !important;
        }
        
        .nav-link::after {
            content: '';
            position: absolute;
            width: 0;
            height: 2px;
            bottom: 0;
            left: 50%;
            background-color: var(--sakura-light);
            transition: all 0.3s ease;
        }
        
        .nav-link:hover::after {
            width: 80%;
            left: 10%;
        }
        
        .btn-primary {
            background-color: var(--indigo);
            border-color: var(--indigo);
        }
        
        .btn-primary:hover, .btn-primary:focus {
            background-color: var(--indigo-dark);
            border-color: var(--indigo-dark);
        }
        
        .btn-success {
            background-color: var(--matcha);
            border-color: var(--matcha);
        }
        
        .btn-success:hover, .btn-success:focus {
            background-color: #718B4B;
            border-color: #718B4B;
        }
        
        .btn-outline-primary {
            color: var(--indigo);
            border-color: var(--indigo);
        }
        
        .btn-outline-primary:hover, .btn-outline-primary:focus {
            background-color: var(--indigo);
            border-color: var(--indigo);
        }
        
        .card {
            background-color: var(--gofun);
            border: none;
            border-radius: 8px;
            box-shadow: 0 4px 6px var(--shadow);
            transition: transform 0.3s ease, box-shadow 0.3s ease;
            overflow: hidden;
        }
        
        .card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 15px var(--shadow);
        }
        
        .card-header {
            background-color: var(--indigo);
            color: white;
            font-family: 'Shippori Mincho', serif;
            font-weight: 500;
            border-bottom: none;
        }
        
        .card-footer {
            background-color: rgba(242, 242, 242, 0.7);
            border-top: none;
        }
        
        .progress-card {
            height: 150px;
        }
        
        .badge.bg-primary {
            background-color: var(--indigo) !important;
        }
        
        .badge.bg-success {
            background-color: var(--matcha) !important;
        }
        
        .badge.bg-danger {
            background-color: #E83015 !important;
        }
        
        .badge.bg-secondary {
            background-color: #8A9BA8 !important;
        }
        
        .badge.bg-info {
            background-color: var(--asagi) !important;
        }
        
        .badge.bg-warning {
            background-color: #D3A625 !important;
        }
        
        .alert-success {
            background-color: var(--matcha-light);
            border-color: var(--matcha);
            color: #3C4D22;
        }
        
        .alert-danger {
            background-color: #FFE5E5;
            border-color: #FFCCCC;
            color: #A94442;
        }
        
        /* Flashcard styling */
        .card-container {
            perspective: 1000px;
            height: 300px;
            margin-bottom: 20px;
        }
        
        .flashcard {
            position: relative;
            width: 100%;
            height: 100%;
            transition: transform 0.8s cubic-bezier(0.175, 0.885, 0.32, 1.275);
            transform-style: preserve-3d;
            cursor: pointer;
        }
        
        .flashcard.flipped {
            transform: rotateY(180deg);
        }
        
        .flashcard-front, .flashcard-back {
            position: absolute;
            width: 100%;
            height: 100%;
            backface-visibility: hidden;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 30px;
            border-radius: 8px;
            background-image: url("data:image/svg+xml,%3Csvg width='100' height='100' viewBox='0 0 100 100' xmlns='http://www.w3.org/2000/svg'%3E%3Cpath d='M0 0h100v100H0z' fill='%23FFFFFF'/%3E%3Cpath fill='%23F5F5F5' d='M0 0h50v50H0zm50 50h50v50H50z'/%3E%3C/svg%3E");
            background-size: 20px 20px;
            box-shadow: 0 4px 8px var(--shadow);
        }
        
        .flashcard-front {
            background-color: var(--gofun);
            border-left: 4px solid var(--indigo);
            z-index: 2;
        }
        
        .flashcard-back {
            background-color: var(--gofun);
            border-left: 4px solid var(--matcha);
            transform: rotateY(180deg);
        }
        
        .flashcard-front::before, .flashcard-back::before {
            content: "";
            position: absolute;
            top: 10px;
            right: 10px;
            width: 20px;
            height: 20px;
            background-size: contain;
            opacity: 0.2;
        }
        
        .flashcard-front::before {
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' width='24' height='24'%3E%3Cpath fill='none' d='M0 0h24v24H0z'/%3E%3Cpath d='M12 22C6.477 22 2 17.523 2 12S6.477 2 12 2s10 4.477 10 10-4.477 10-10 10zm0-2a8 8 0 1 0 0-16 8 8 0 0 0 0 16zm-1-5h2v2h-2v-2zm0-8h2v6h-2V7z' fill='%233E4A89'/%3E%3C/svg%3E");
        }
        
        .flashcard-back::before {
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' width='24' height='24'%3E%3Cpath fill='none' d='M0 0h24v24H0z'/%3E%3Cpath d='M12 22C6.477 22 2 17.523 2 12S6.477 2 12 2s10 4.477 10 10-4.477 10-10 10zm0-2a8 8 0 1 0 0-16 8 8 0 0 0 0 16zm-1-5h2v2h-2v-2zm2-1.645V14h-2v-1.5a1 1 0 0 1 1-1 1.5 1.5 0 1 0-1.471-1.794l-1.962-.393A3.5 3.5 0 1 1 13 13.355z' fill='%238AA367'/%3E%3C/svg%3E");
        }
        
        /* Deck card styling */
        .deck-card {
            position: relative;
            overflow: hidden;
            border-radius: 8px;
            transition: all 0.3s ease;
        }
        
        .deck-card::before {
            content: "";
            position: absolute;
            top: 0;
            right: 0;
            width: 0;
            height: 0;
            border-style: solid;
            border-width: 0 50px 50px 0;
            border-color: transparent var(--sakura-light) transparent transparent;
            transition: all 0.3s ease;
            opacity: 0.7;
            z-index: 1;
        }
        
        .deck-card:hover::before {
            border-width: 0 70px 70px 0;
        }
        
        .deck-pattern {
            position: absolute;
            bottom: 0;
            left: 0;
            width: 100%;
            height: 30%;
            background-image: url("data:image/svg+xml,%3Csvg width='40' height='40' viewBox='0 0 40 40' xmlns='http://www.w3.org/2000/svg'%3E%3Cg fill='%23F0F0F0' fill-opacity='0.4' fill-rule='evenodd'%3E%3Cpath d='M0 40L40 0H20L0 20M40 40V20L20 40'/%3E%3C/g%3E%3C/svg%3E");
            opacity: 0.3;
            z-index: 0;
        }
        
        .due-badge {
            position: absolute;
            top: 10px;
            right: 10px;
            z-index: 2;
        }
        
        /* Study page styles */
        .btn[data-rating="1"] {
            background-color: #E83015;
            border-color: #E83015;
        }
        
        .btn[data-rating="2"] {
            background-color: #D3A625;
            border-color: #D3A625;
        }
        
        .btn[data-rating="3"] {
            background-color: var(--asagi);
            border-color: var(--asagi);
        }
        
        .btn[data-rating="4"] {
            background-color: var(--matcha);
            border-color: var(--matcha);
        }
        
        /* Dashboard styling */
        .stat-card {
            position: relative;
            overflow: hidden;
            border-radius: 8px;
            background-color: white;
        }
        
        .stat-card .stat-icon {
            position: absolute;
            bottom: -15px;
            right: -15px;
            font-size: 5rem;
            opacity: 0.1;
            color: var(--indigo);
        }
        
        /* Table styling */
        .table {
            border-collapse: separate;
            border-spacing: 0;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 2px 4px var(--shadow);
        }
        
        .table thead th {
            background-color: var(--indigo);
            color: white;
            font-weight: 500;
            border: none;
        }
        
        .table tbody tr:nth-child(odd) {
            background-color: rgba(245, 245, 245, 0.5);
        }
        
        .table tbody tr:hover {
            background-color: rgba(222, 226, 237, 0.5);
        }
        
        /* Breadcrumb styling */
        .breadcrumb {
            background: rgba(255, 255, 255, 0.7);
            border-radius: 30px;
            padding: 0.75rem 1.5rem;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
        }
        
        .breadcrumb-item + .breadcrumb-item::before {
            content: "〉";
        }
        
        /* Cherry blossom spinner */
        .spinner-container {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(255, 255, 255, 0.9);
            display: flex;
            justify-content: center;
            align-items: center;
            z-index: 9999;
            opacity: 0;
            visibility: hidden;
            transition: all 0.3s ease;
        }
        
        .spinner-container.show {
            opacity: 1;
            visibility: visible;
        }
        
        .sakura-spinner {
            width: 80px;
            height: 80px;
            animation: sakura-spin 1.5s linear infinite;
        }
        
        @keyframes sakura-spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        .sakura-petal {
            position: absolute;
            background-color: var(--sakura);
            width: 15px;
            height: 15px;
            border-radius: 15px 0px 15px 0px;
        }
        
        .sakura-petal:nth-child(1) {
            transform: rotate(0deg) translateY(-30px);
            animation: sakura-fall 1.5s ease-in-out infinite;
        }
        
        .sakura-petal:nth-child(2) {
            transform: rotate(90deg) translateY(-30px);
            animation: sakura-fall 1.5s ease-in-out infinite 0.3s;
        }
        
        .sakura-petal:nth-child(3) {
            transform: rotate(180deg) translateY(-30px);
            animation: sakura-fall 1.5s ease-in-out infinite 0.6s;
        }
        
        .sakura-petal:nth-child(4) {
            transform: rotate(270deg) translateY(-30px);
            animation: sakura-fall 1.5s ease-in-out infinite 0.9s;
        }
        
        .sakura-petal:nth-child(5) {
            width: 10px;
            height: 10px;
            background-color: var(--sakura-light);
            border-radius: 10px;
            top: 35px;
            left: 35px;
        }
        
        @keyframes sakura-fall {
            0% { opacity: 0; }
            50% { opacity: 1; }
            100% { opacity: 0; }
        }
        
        /* Progress bar styling */
        .progress {
            height: 10px;
            border-radius: 10px;
            background-color: #F0F0F0;
        }
        
        .progress-bar {
            border-radius: 10px;
            background-color: var(--indigo);
        }
        
        /* User Profile Dropdown */
        .user-dropdown .dropdown-toggle::after {
            display: none;
        }
        
        .user-dropdown .dropdown-toggle {
            display: flex;
            align-items: center;
        }
        
        .user-avatar {
            width: 32px;
            height: 32px;
            background-color: rgba(255, 255, 255, 0.2);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 8px;
            font-weight: 600;
        }
        
        .user-dropdown .dropdown-menu {
            border: none;
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
            padding: 0.5rem 0;
        }
        
        .user-dropdown .dropdown-item {
            padding: 0.6rem 1.2rem;
            transition: all 0.2s ease;
        }
        
        .user-dropdown .dropdown-item:hover {
            background-color: rgba(62, 74, 137, 0.1);
        }
        
        .user-dropdown .dropdown-item i {
            width: 20px;
            margin-right: 8px;
            color: var(--indigo);
        }
    </style>
</head>
<body>
    <div class="spinner-container" id="page-spinner">
        <div class="sakura-spinner">
            <div class="sakura-petal"></div>
            <div class="sakura-petal"></div>
            <div class="sakura-petal"></div>
            <div class="sakura-petal"></div>
            <div class="sakura-petal"></div>
        </div>
    </div>

    <nav class="navbar navbar-expand-lg navbar-dark mb-4">
        <div class="container">
            <a class="navbar-brand" href="<?php echo SITE_URL; ?>">
                <span class="logo-accent">暗記</span>FlashLearn
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav">
                    <li class="nav-item">
                        <a class="nav-link" href="<?php echo SITE_URL; ?>">
                            <i class="fas fa-home"></i> Dashboard
                        </a>
                    </li>
                    <?php if (isLoggedIn()): ?>
                        <li class="nav-item">
                            <a class="nav-link" href="<?php echo SITE_URL; ?>/decks/list.php">
                                <i class="fas fa-layer-group"></i> My Decks
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="<?php echo SITE_URL; ?>/study/index.php">
                                <i class="fas fa-graduation-cap"></i> Study
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="<?php echo SITE_URL; ?>/stats.php">
                                <i class="fas fa-chart-line"></i> Progress
                            </a>
                        </li>
                    <?php endif; ?>
                </ul>
                <ul class="navbar-nav ms-auto">
                    <?php if (isLoggedIn()): ?>
                        <li class="nav-item dropdown user-dropdown">
                            <a class="nav-link dropdown-toggle" href="#" id="userDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                                <div class="user-avatar">
                                    <?php echo strtoupper(substr($_SESSION['username'], 0, 1)); ?>
                                </div>
                                <span class="d-none d-md-inline"><?php echo htmlspecialchars($_SESSION['username']); ?></span>
                            </a>
                            <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="userDropdown">
                                <li>
                                    <a class="dropdown-item" href="<?php echo SITE_URL; ?>/profile.php">
                                        <i class="fas fa-user"></i> Profile
                                    </a>
                                </li>
                                <li>
                                    <a class="dropdown-item" href="<?php echo SITE_URL; ?>/stats.php">
                                        <i class="fas fa-chart-line"></i> My Stats
                                    </a>
                                </li>
                                <li><hr class="dropdown-divider"></li>
                                <li>
                                    <a class="dropdown-item" href="<?php echo SITE_URL; ?>/auth/logout.php">
                                        <i class="fas fa-sign-out-alt"></i> Logout
                                    </a>
                                </li>
                            </ul>
                        </li>
                    <?php else: ?>
                        <li class="nav-item">
                            <a class="nav-link" href="<?php echo SITE_URL; ?>/auth/login.php">
                                <i class="fas fa-sign-in-alt"></i> Login
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="<?php echo SITE_URL; ?>/auth/register.php">
                                <i class="fas fa-user-plus"></i> Register
                            </a>
                        </li>
                    <?php endif; ?>
                </ul>
            </div>
        </div>
    </nav>
    <div class="container">