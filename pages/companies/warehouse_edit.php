<?php
/**
 * Редактирование склада (заглушка)
 * Система складского учета (SUT)
 */

require_once __DIR__ . '/../../config/config.php';

requireAccess('companies');

$warehouseId = (int)($_GET['id'] ?? 0);

if (!$warehouseId) {
    $_SESSION['error_message'] = 'Склад не найден';
    header('Location: /pages/companies/index.php');
    exit;
}

try {
    $pdo = getDBConnection();
    
    // Получаем информацию о складе и компании
    $stmt = $pdo->prepare("
        SELECT w.*, c.name as company_name, c.id as company_id
        FROM warehouses w
        JOIN companies c ON w.company_id = c.id
        WHERE w.id = ?
    ");
    $stmt->execute([$warehouseId]);
    $warehouse = $stmt->fetch();
    
    if (!$warehouse) {
        $_SESSION['error_message'] = 'Склад не найден';
        header('Location: /pages/companies/index.php');
        exit;
    }
    
} catch (Exception $e) {
    logError('Warehouse edit error: ' . $e->getMessage(), ['warehouse_id' => $warehouseId]);
    $_SESSION['error_message'] = 'Произошла ошибка при загрузке данных';
    header('Location: /pages/companies/index.php');
    exit;
}

$pageTitle = 'Редактирование склада';

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item">
                    <a href="/pages/companies/index.php">Компании</a>
                </li>
                <li class="breadcrumb-item">
                    <a href="/pages/companies/view.php?id=<?= $warehouse['company_id'] ?>"><?= e($warehouse['company_name']) ?></a>
                </li>
                <li class="breadcrumb-item active"><?= e($pageTitle) ?></li>
            </ol>
        </nav>
        <h1 class="h3 mb-0"><?= e($pageTitle) ?></h1>
        <p class="text-muted mb-0">Склад: <?= e($warehouse['name']) ?></p>
    </div>
    <div>
        <a href="/pages/companies/view.php?id=<?= $warehouse['company_id'] ?>" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left"></i>
            Назад к компании
        </a>
    </div>
</div>

<div class="card">
    <div class="card-body text-center py-5">
        <i class="bi bi-tools text-muted" style="font-size: 4rem;"></i>
        <h4 class="mt-3">Функция в разработке</h4>
        <p class="text-muted">
            Редактирование складов будет доступно в следующих версиях системы.
        </p>
        <a href="/pages/companies/view.php?id=<?= $warehouse['company_id'] ?>" class="btn btn-primary">
            <i class="bi bi-arrow-left"></i>
            Вернуться к компании
        </a>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>