<?php
/**
 * Список товаров для приемки
 * Показывает только товары со статусом 'confirmed'
 */

require_once '../../config/config.php';
require_once '../../includes/functions.php';

// Проверка авторизации
if (!isLoggedIn()) {
    header('Location: /pages/auth/login.php');
    exit();
}

$currentUser = getCurrentUser();

// Проверка прав доступа
if (!hasAccessToSection('receiving')) {
    header('Location: /pages/errors/403.php');
    exit();
}

$pageTitle = 'Приемка товаров';
$error = null;
$transitItems = [];
$stats = [];

try {
    $pdo = getDBConnection();
    
    // Строим запрос для получения товаров со статусом 'confirmed'
    $whereConditions = ["gt.status = 'confirmed'"];
    $params = [];
    
    // Ограничения по складу для работника склада
    if ($currentUser['role'] === 'warehouse_worker' && !empty($currentUser['warehouse_id'])) {
        $whereConditions[] = "gt.warehouse_id = ?";
        $params[] = $currentUser['warehouse_id'];
    }
    
    $whereClause = implode(' AND ', $whereConditions);
    
    // Основной запрос для получения товаров
    $query = "
        SELECT 
            gt.id,
            gt.departure_date,
            gt.arrival_date,
            gt.departure_location,
            gt.goods_info,
            gt.status,
            gt.notes,
            gt.created_at,
            w.name as warehouse_name,
            w.address as warehouse_address,
            CONCAT(u.first_name, ' ', u.last_name) as created_by_name,
            u.login as created_by_login
        FROM goods_in_transit gt
        LEFT JOIN warehouses w ON gt.warehouse_id = w.id
        LEFT JOIN users u ON gt.created_by = u.id
        WHERE $whereClause
        ORDER BY gt.arrival_date ASC, gt.created_at DESC
    ";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $transitItems = $stmt->fetchAll();
    
    // Статистика
    $statsQuery = "
        SELECT 
            COUNT(CASE WHEN status = 'confirmed' THEN 1 END) as confirmed_count,
            COUNT(CASE WHEN status = 'received' THEN 1 END) as received_count,
            COUNT(CASE WHEN status = 'arrived' THEN 1 END) as arrived_count,
            COUNT(CASE WHEN status = 'in_transit' THEN 1 END) as in_transit_count
        FROM goods_in_transit gt
        WHERE 1=1 " . 
        (($currentUser['role'] === 'warehouse_worker' && !empty($currentUser['warehouse_id'])) 
            ? " AND gt.warehouse_id = " . (int)$currentUser['warehouse_id'] 
            : "");
    
    $statsStmt = $pdo->prepare($statsQuery);
    $statsStmt->execute();
    $stats = $statsStmt->fetch();
    
} catch (Exception $e) {
    logError('Ошибка при загрузке списка приемки: ' . $e->getMessage());
    $error = 'Ошибка при загрузке данных. Попробуйте позже.';
}

// Функция для декодирования JSON информации о товарах
function decodeGoodsInfo($goodsInfoJson) {
    $goodsInfo = json_decode($goodsInfoJson, true);
    if (!$goodsInfo) return [];
    return $goodsInfo;
}

// Функция для получения общего количества товаров
function getTotalQuantity($goodsInfo) {
    $total = 0;
    foreach ($goodsInfo as $item) {
        $total += (float)($item['quantity'] ?? 0);
    }
    return $total;
}

include '../../includes/header.php';
?>

<div class="container-fluid">
    <div class="row">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h1 class="h3 mb-0">Приемка товаров</h1>
            </div>

            <?php if (isset($error)): ?>
                <div class="alert alert-danger" role="alert">
                    <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>

            <!-- Статистика -->
            <div class="row mb-4">
                <div class="col-md-3">
                    <div class="card text-white bg-success">
                        <div class="card-body">
                            <div class="d-flex justify-content-between">
                                <div>
                                    <h4 class="card-title"><?= $stats['received_count'] ?? 0 ?></h4>
                                    <p class="card-text">Принято</p>
                                </div>
                                <div class="align-self-center">
                                    <i class="fas fa-check-circle fa-2x"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card text-white bg-primary">
                        <div class="card-body">
                            <div class="d-flex justify-content-between">
                                <div>
                                    <h4 class="card-title"><?= $stats['confirmed_count'] ?? 0 ?></h4>
                                    <p class="card-text">Готово к приемке</p>
                                </div>
                                <div class="align-self-center">
                                    <i class="fas fa-box fa-2x"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card text-white bg-warning">
                        <div class="card-body">
                            <div class="d-flex justify-content-between">
                                <div>
                                    <h4 class="card-title"><?= $stats['arrived_count'] ?? 0 ?></h4>
                                    <p class="card-text">Прибыло</p>
                                </div>
                                <div class="align-self-center">
                                    <i class="fas fa-truck fa-2x"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card text-white bg-info">
                        <div class="card-body">
                            <div class="d-flex justify-content-between">
                                <div>
                                    <h4 class="card-title"><?= $stats['in_transit_count'] ?? 0 ?></h4>
                                    <p class="card-text">В пути</p>
                                </div>
                                <div class="align-self-center">
                                    <i class="fas fa-route fa-2x"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Список товаров для приемки -->
            <div class="card">
                <div class="card-header">
                    <h5 class="card-title mb-0">
                        <i class="fas fa-boxes me-2"></i>Товары готовые к приемке
                    </h5>
                </div>
                <div class="card-body">
                    <?php if (empty($transitItems)): ?>
                        <div class="text-center py-5">
                            <i class="fas fa-box-open fa-3x text-muted mb-3"></i>
                            <h5 class="text-muted">Нет товаров для приемки</h5>
                            <p class="text-muted">Товары со статусом "Готово к приемке" появятся здесь</p>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Откуда</th>
                                        <th>Склад</th>
                                        <th>Дата прибытия</th>
                                        <th>Товары</th>
                                        <th>Статус</th>
                                        <th>Создан</th>
                                        <th>Действия</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($transitItems as $item): ?>
                                        <?php 
                                        $goodsInfo = decodeGoodsInfo($item['goods_info']);
                                        $totalQuantity = getTotalQuantity($goodsInfo);
                                        ?>
                                        <tr>
                                            <td><strong>#<?= $item['id'] ?></strong></td>
                                            <td><?= htmlspecialchars($item['departure_location']) ?></td>
                                            <td>
                                                <small class="text-muted"><?= htmlspecialchars($item['warehouse_name']) ?></small>
                                            </td>
                                            <td>
                                                <?= date('d.m.Y', strtotime($item['arrival_date'])) ?>
                                                <br>
                                                <small class="text-muted"><?= date('H:i', strtotime($item['arrival_date'])) ?></small>
                                            </td>
                                            <td>
                                                <span class="badge bg-light text-dark">
                                                    <?= count($goodsInfo) ?> поз. / <?= number_format($totalQuantity, 2) ?> ед.
                                                </span>
                                            </td>
                                            <td>
                                                <span class="badge bg-primary">Готово к приемке</span>
                                            </td>
                                            <td>
                                                <?= date('d.m.Y H:i', strtotime($item['created_at'])) ?>
                                                <br>
                                                <small class="text-muted">
                                                    <?= htmlspecialchars($item['created_by_name']) ?>
                                                </small>
                                            </td>
                                            <td>
                                                <div class="btn-group btn-group-sm" role="group">
                                                    <a href="view.php?id=<?= $item['id'] ?>" 
                                                       class="btn btn-outline-primary" title="Просмотр">
                                                        <i class="fas fa-eye"></i>
                                                    </a>
                                                    
                                                    <a href="confirm.php?id=<?= $item['id'] ?>" 
                                                       class="btn btn-success" title="Подтвердить приемку">
                                                        <i class="fas fa-check"></i> Принять
                                                    </a>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>