<?php
/**
 * Дашборд системы (временная версия для тестирования авторизации)
 * Система складского учета (SUT)
 */

require_once __DIR__ . '/../config/config.php';

requireAccess('dashboard');

$user = getCurrentUser();
$pageTitle = 'Дашборд';

// Получаем базовую статистику
try {
    $pdo = getDBConnection();
    
    // Статистика из представления
    $stmt = $pdo->query("SELECT * FROM v_dashboard_stats");
    $stats = $stmt->fetch();
    
    // Статистика сессий
    $sessionStats = SessionManager::getSessionStats();
    
} catch (Exception $e) {
    logError('Dashboard stats error: ' . $e->getMessage());
    $stats = [
        'active_companies' => 0,
        'active_users' => 0,
        'pending_requests' => 0,
        'goods_in_transit' => 0,
        'today_sales_total' => 0
    ];
    $sessionStats = [
        'active_sessions' => 0,
        'daily_sessions' => 0,
        'online_users' => 0
    ];
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <div class="d-flex justify-content-between align-items-center">
        <div>
            <h1 class="h3 mb-1">Добро пожаловать, <?= e($user['name']) ?>!</h1>
            <p class="text-muted mb-0">Общая сводка по системе складского учета</p>
        </div>
        <div class="text-end">
            <div class="small text-muted">
                Роль: <strong><?= e($user['role']) ?></strong><br>
                Последнее обновление: <?= date('d.m.Y H:i') ?>
            </div>
        </div>
    </div>
</div>

<!-- Статистические карточки -->
<div class="row mb-4">
    <div class="col-md-3">
        <div class="stats-card">
            <div class="stats-number"><?= number_format($stats['active_companies']) ?></div>
            <div class="stats-label">
                <i class="bi bi-building"></i>
                Активные компании
            </div>
        </div>
    </div>
    
    <div class="col-md-3">
        <div class="stats-card" style="background: linear-gradient(135deg, <?= COLOR_SECONDARY ?>, #2f855a);">
            <div class="stats-number"><?= number_format($stats['active_users']) ?></div>
            <div class="stats-label">
                <i class="bi bi-people"></i>
                Активные пользователи
            </div>
        </div>
    </div>
    
    <div class="col-md-3">
        <div class="stats-card" style="background: linear-gradient(135deg, #ffa500, #ff8c00);">
            <div class="stats-number"><?= number_format($stats['pending_requests']) ?></div>
            <div class="stats-label">
                <i class="bi bi-chat-left-text"></i>
                Необработанные запросы
            </div>
        </div>
    </div>
    
    <div class="col-md-3">
        <div class="stats-card" style="background: linear-gradient(135deg, #6f42c1, #5a2d91);">
            <div class="stats-number"><?= number_format($stats['goods_in_transit']) ?></div>
            <div class="stats-label">
                <i class="bi bi-truck"></i>
                Товаров в пути
            </div>
        </div>
    </div>
</div>

<div class="row mb-4">
    <div class="col-md-6">
        <div class="card">
            <div class="card-header">
                <h5 class="card-title mb-0">
                    <i class="bi bi-currency-dollar"></i>
                    Продажи за сегодня
                </h5>
            </div>
            <div class="card-body">
                <div class="d-flex align-items-center">
                    <div class="flex-grow-1">
                        <h3 class="mb-0" style="color: <?= COLOR_SECONDARY ?>;">
                            $<?= formatNumber($stats['today_sales_total']) ?>
                        </h3>
                        <p class="text-muted mb-0">Общая сумма реализации</p>
                    </div>
                    <div class="text-end">
                        <i class="bi bi-graph-up" style="font-size: 2rem; color: <?= COLOR_SECONDARY ?>;"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-md-6">
        <div class="card">
            <div class="card-header">
                <h5 class="card-title mb-0">
                    <i class="bi bi-shield-check"></i>
                    Активность пользователей
                </h5>
            </div>
            <div class="card-body">
                <div class="row text-center">
                    <div class="col-4">
                        <div class="h4 mb-0" style="color: <?= COLOR_PRIMARY ?>;">
                            <?= $sessionStats['online_users'] ?>
                        </div>
                        <small class="text-muted">Онлайн</small>
                    </div>
                    <div class="col-4">
                        <div class="h4 mb-0" style="color: <?= COLOR_SECONDARY ?>;">
                            <?= $sessionStats['active_sessions'] ?>
                        </div>
                        <small class="text-muted">Активные сессии</small>
                    </div>
                    <div class="col-4">
                        <div class="h4 mb-0" style="color: #ffa500;">
                            <?= $sessionStats['daily_sessions'] ?>
                        </div>
                        <small class="text-muted">За 24 часа</small>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Быстрые действия -->
<div class="row">
    <div class="col-md-12">
        <div class="card">
            <div class="card-header">
                <h5 class="card-title mb-0">
                    <i class="bi bi-lightning"></i>
                    Быстрые действия
                </h5>
            </div>
            <div class="card-body">
                <div class="row">
                    <?php if (hasAccessToSection('companies')): ?>
                    <div class="col-md-3 mb-3">
                        <a href="/pages/companies/index.php" class="btn btn-outline-primary w-100 h-100 d-flex flex-column justify-content-center">
                            <i class="bi bi-building" style="font-size: 2rem; margin-bottom: 0.5rem;"></i>
                            <span>Управление компаниями</span>
                        </a>
                    </div>
                    <?php endif; ?>
                    
                    <?php if (hasAccessToSection('employees')): ?>
                    <div class="col-md-3 mb-3">
                        <a href="/pages/employees/index.php" class="btn btn-outline-success w-100 h-100 d-flex flex-column justify-content-center">
                            <i class="bi bi-people" style="font-size: 2rem; margin-bottom: 0.5rem;"></i>
                            <span>Управление сотрудниками</span>
                        </a>
                    </div>
                    <?php endif; ?>
                    
                    <?php if (hasAccessToSection('product_templates')): ?>
                    <div class="col-md-3 mb-3">
                        <a href="/pages/templates/index.php" class="btn btn-outline-info w-100 h-100 d-flex flex-column justify-content-center">
                            <i class="bi bi-gear" style="font-size: 2rem; margin-bottom: 0.5rem;"></i>
                            <span>Шаблоны товаров</span>
                        </a>
                    </div>
                    <?php endif; ?>
                    
                    <?php if (hasAccessToSection('inventory')): ?>
                    <div class="col-md-3 mb-3">
                        <a href="/pages/inventory/index.php" class="btn btn-outline-warning w-100 h-100 d-flex flex-column justify-content-center">
                            <i class="bi bi-archive" style="font-size: 2rem; margin-bottom: 0.5rem;"></i>
                            <span>Остатки на складах</span>
                        </a>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Уведомление о демо-режиме -->
<div class="alert alert-info mt-4" role="alert">
    <i class="bi bi-info-circle"></i>
    <strong>Демонстрационный режим:</strong> 
    Система находится в режиме разработки. Все данные являются тестовыми.
    Полный функционал будет доступен после завершения разработки всех модулей.
</div>

<script>
// Обновление времени последнего обновления каждую минуту
setInterval(function() {
    const timeElement = document.querySelector('.page-header .text-end .small');
    if (timeElement) {
        const now = new Date();
        const timeString = now.toLocaleDateString('ru-RU') + ' ' + 
                          now.toLocaleTimeString('ru-RU', {hour: '2-digit', minute: '2-digit'});
        timeElement.innerHTML = timeElement.innerHTML.replace(/\d{2}\.\d{2}\.\d{4} \d{2}:\d{2}/, timeString);
    }
}, 60000);

// Анимация счетчиков при загрузке страницы
document.addEventListener('DOMContentLoaded', function() {
    const numbers = document.querySelectorAll('.stats-number');
    numbers.forEach(function(element) {
        const finalNumber = parseInt(element.textContent.replace(/,/g, ''));
        let currentNumber = 0;
        const increment = finalNumber / 50;
        
        const timer = setInterval(function() {
            currentNumber += increment;
            if (currentNumber >= finalNumber) {
                element.textContent = finalNumber.toLocaleString();
                clearInterval(timer);
            } else {
                element.textContent = Math.floor(currentNumber).toLocaleString();
            }
        }, 30);
    });
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>