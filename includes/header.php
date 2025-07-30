<?php
require_once __DIR__ . '/../config/config.php';
$user = getCurrentUser();
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= isset($pageTitle) ? e($pageTitle) . ' - ' : '' ?><?= APP_NAME ?></title>
    
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <!-- Custom CSS -->
    <link href="/assets/css/style.css" rel="stylesheet">
    
    <style>
        :root {
            --primary-color: <?= COLOR_PRIMARY ?>;
            --secondary-color: <?= COLOR_SECONDARY ?>;
            --danger-color: <?= COLOR_DANGER ?>;
            --background-color: <?= COLOR_BACKGROUND ?>;
            --text-color: <?= COLOR_TEXT ?>;
        }
    </style>
</head>
<body class="bg-light">
    <?php if (isLoggedIn()): ?>
    <!-- Навигация -->
    <nav class="navbar navbar-expand-lg navbar-dark" style="background-color: var(--primary-color);">
        <div class="container-fluid">
            <a class="navbar-brand" href="/pages/dashboard.php">
                <i class="bi bi-boxes"></i> <?= APP_NAME ?>
            </a>
            
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav me-auto">
                    <?php if (hasAccessToSection('dashboard')): ?>
                    <li class="nav-item">
                        <a class="nav-link" href="/pages/dashboard.php">
                            <i class="bi bi-speedometer2"></i> Дашборд
                        </a>
                    </li>
                    <?php endif; ?>
                    
                    <?php if (hasAccessToSection('companies')): ?>
                    <li class="nav-item">
                        <a class="nav-link" href="/pages/companies/index.php">
                            <i class="bi bi-building"></i> Компании
                        </a>
                    </li>
                    <?php endif; ?>
                    
                    <?php if (hasAccessToSection('employees')): ?>
                    <li class="nav-item">
                        <a class="nav-link" href="/pages/employees/index.php">
                            <i class="bi bi-people"></i> Сотрудники
                        </a>
                    </li>
                    <?php endif; ?>
                    
                    <?php if (hasAccessToSection('products')): ?>
                    <li class="nav-item">
                        <a class="nav-link" href="/pages/products/index.php">
                            <i class="bi bi-box"></i> Товары
                        </a>
                    </li>
                    <?php endif; ?>
                    
                    <?php if (hasAccessToSection('requests')): ?>
                    <li class="nav-item">
                        <a class="nav-link" href="/pages/requests/index.php">
                            <i class="bi bi-chat-left-text"></i> Запросы
                            <?php
                            // Показываем количество необработанных запросов
                            $pdo = getDBConnection();
                            $stmt = $pdo->prepare("SELECT COUNT(*) FROM requests WHERE status = 'pending'");
                            $stmt->execute();
                            $pendingCount = $stmt->fetchColumn();
                            if ($pendingCount > 0):
                            ?>
                            <span class="badge bg-danger"><?= $pendingCount ?></span>
                            <?php endif; ?>
                        </a>
                    </li>
                    <?php endif; ?>
                    
                    <?php if (hasAccessToSection('inventory')): ?>
                    <li class="nav-item">
                        <a class="nav-link" href="/pages/inventory/index.php">
                            <i class="bi bi-archive"></i> Остатки
                        </a>
                    </li>
                    <?php endif; ?>
                    
                    <?php if (hasAccessToSection('goods_in_transit')): ?>
                    <li class="nav-item">
                        <a class="nav-link" href="/pages/transit/index.php">
                            <i class="bi bi-truck"></i> Товар в пути
                        </a>
                    </li>
                    <?php endif; ?>
                    
                    <?php if (hasAccessToSection('sales')): ?>
                    <li class="nav-item">
                        <a class="nav-link" href="/pages/sales/index.php">
                            <i class="bi bi-currency-dollar"></i> Реализация
                        </a>
                    </li>
                    <?php endif; ?>
                    
                    <?php if (hasAccessToSection('receiving')): ?>
                    <li class="nav-item">
                        <a class="nav-link" href="/pages/receiving/index.php">
                            <i class="bi bi-check-circle"></i> Приёмка
                        </a>
                    </li>
                    <?php endif; ?>
                    
                    <?php if (hasAccessToSection('product_templates')): ?>
                    <li class="nav-item">
                        <a class="nav-link" href="/pages/templates/index.php">
                            <i class="bi bi-gear"></i> Характеристики
                        </a>
                    </li>
                    <?php endif; ?>
                </ul>
                
                <!-- Пользователь -->
                <ul class="navbar-nav">
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown">
                            <i class="bi bi-person-circle"></i> <?= e($user['name']) ?>
                        </a>
                        <ul class="dropdown-menu">
                            <li><a class="dropdown-item" href="/pages/profile.php">
                                <i class="bi bi-person"></i> Профиль
                            </a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item" href="/pages/auth/logout.php">
                                <i class="bi bi-box-arrow-right"></i> Выйти
                            </a></li>
                        </ul>
                    </li>
                </ul>
            </div>
        </div>
    </nav>
    <?php endif; ?>
    
    <main class="container-fluid py-4">
        <?php if (isset($_SESSION['success_message'])): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="bi bi-check-circle"></i> <?= e($_SESSION['success_message']) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php unset($_SESSION['success_message']); endif; ?>
        
        <?php if (isset($_SESSION['error_message'])): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="bi bi-exclamation-triangle"></i> <?= e($_SESSION['error_message']) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php unset($_SESSION['error_message']); endif; ?>