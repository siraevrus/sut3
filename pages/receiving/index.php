<?php
/**
 * Страница списка товаров для приемки
 */

require_once '../../config/config.php';

// Проверка авторизации
if (!isset($_SESSION['user_id'])) {
    header('Location: /pages/auth/login.php');
    exit();
}

// Проверка прав доступа
if (!hasAccessToSection('receiving')) {
    header('Location: /pages/errors/403.php');
    exit();
}

try {
    $pdo = getDBConnection();
    $currentUser = $_SESSION;
    
    // Параметры фильтрации
    $warehouseId = isset($_GET['warehouse_id']) ? (int)$_GET['warehouse_id'] : null;
    $status = isset($_GET['status']) ? $_GET['status'] : 'confirmed';
    
    // Построение SQL запроса с учетом прав доступа
    $whereConditions = ["gt.status = :status"];
    $params = ['status' => $status];
    
    // Ограничение по складу для работников склада
    if ($_SESSION['user_role'] === 'warehouse_worker' && !empty($_SESSION['warehouse_id'])) {
        $whereConditions[] = "gt.warehouse_id = :user_warehouse_id";
        $params['user_warehouse_id'] = $_SESSION['warehouse_id'];
    } elseif ($warehouseId) {
        $whereConditions[] = "gt.warehouse_id = :warehouse_id";
        $params['warehouse_id'] = $warehouseId;
    }
    
    $whereClause = implode(' AND ', $whereConditions);
    
    // Основной запрос для получения товаров
    $query = "
        SELECT 
            gt.id,
            gt.departure_date,
            gt.arrival_date,
            gt.departure_location,
            gt.arrival_location,
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
    
    // Получение списка складов для фильтра (только для админа)
    $warehouses = [];
    if ($_SESSION['user_role'] === 'admin') {
        $warehousesQuery = "SELECT id, name FROM warehouses WHERE status = 1 ORDER BY name";
        $warehousesStmt = $pdo->prepare($warehousesQuery);
        $warehousesStmt->execute();
        $warehouses = $warehousesStmt->fetchAll();
    }
    
    // Статистика
    $statsQuery = "
        SELECT 
            COUNT(CASE WHEN status = 'confirmed' THEN 1 END) as confirmed_count,
            COUNT(CASE WHEN status = 'received' THEN 1 END) as received_count,
            COUNT(CASE WHEN status = 'arrived' THEN 1 END) as arrived_count,
            COUNT(CASE WHEN status = 'in_transit' THEN 1 END) as in_transit_count
        FROM goods_in_transit gt
        WHERE 1=1 " . 
        (($_SESSION['user_role'] === 'warehouse_worker' && !empty($_SESSION['warehouse_id'])) 
            ? " AND gt.warehouse_id = " . (int)$_SESSION['warehouse_id'] 
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
                                    <i class="fas fa-clipboard-check fa-2x"></i>
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
                                    <i class="fas fa-shipping-fast fa-2x"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Фильтры -->
            <div class="card mb-4">
                <div class="card-body">
                    <form method="GET" class="row g-3">
                        <div class="col-md-3">
                            <label for="status" class="form-label">Статус</label>
                            <select class="form-select" id="status" name="status">
                                <option value="confirmed" <?= $status === 'confirmed' ? 'selected' : '' ?>>Готово к приемке</option>
                                <option value="received" <?= $status === 'received' ? 'selected' : '' ?>>Принято</option>
                                <option value="arrived" <?= $status === 'arrived' ? 'selected' : '' ?>>Прибыло</option>
                                <option value="in_transit" <?= $status === 'in_transit' ? 'selected' : '' ?>>В пути</option>
                                <option value="" <?= $status === '' ? 'selected' : '' ?>>Все статусы</option>
                            </select>
                        </div>
                        
                        <?php if ($_SESSION['user_role'] === 'admin' && !empty($warehouses)): ?>
                        <div class="col-md-3">
                            <label for="warehouse_id" class="form-label">Склад</label>
                            <select class="form-select" id="warehouse_id" name="warehouse_id">
                                <option value="">Все склады</option>
                                <?php foreach ($warehouses as $warehouse): ?>
                                    <option value="<?= $warehouse['id'] ?>" <?= $warehouseId == $warehouse['id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($warehouse['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php endif; ?>
                        
                        <div class="col-md-3 d-flex align-items-end">
                            <button type="submit" class="btn btn-primary me-2">Фильтровать</button>
                            <a href="?" class="btn btn-outline-secondary">Сбросить</a>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Таблица товаров -->
            <div class="card">
                <div class="card-header">
                    <h5 class="card-title mb-0">
                        Товары для приемки 
                        <span class="badge bg-secondary ms-2"><?= count($transitItems ?? []) ?></span>
                    </h5>
                </div>
                <div class="card-body">
                    <?php if (empty($transitItems)): ?>
                        <div class="text-center py-4">
                            <i class="fas fa-inbox fa-3x text-muted mb-3"></i>
                            <p class="text-muted">Нет товаров с выбранными параметрами</p>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Откуда</th>
                                        <th>Куда</th>
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
                                        
                                        $statusClass = '';
                                        $statusText = '';
                                        switch ($item['status']) {
                                            case 'in_transit':
                                                $statusClass = 'bg-info';
                                                $statusText = 'В пути';
                                                break;
                                            case 'arrived':
                                                $statusClass = 'bg-warning';
                                                $statusText = 'Прибыло';
                                                break;
                                            case 'confirmed':
                                                $statusClass = 'bg-primary';
                                                $statusText = 'Готово к приемке';
                                                break;
                                            case 'received':
                                                $statusClass = 'bg-success';
                                                $statusText = 'Принято';
                                                break;
                                        }
                                        ?>
                                        <tr>
                                            <td><strong>#<?= $item['id'] ?></strong></td>
                                            <td><?= htmlspecialchars($item['departure_location']) ?></td>
                                            <td><?= htmlspecialchars($item['arrival_location']) ?></td>
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
                                                <span class="badge <?= $statusClass ?>"><?= $statusText ?></span>
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
                                                    
                                                    <?php if ($item['status'] === 'confirmed'): ?>
                                                        <a href="confirm.php?id=<?= $item['id'] ?>" 
                                                           class="btn btn-success" title="Подтвердить приемку">
                                                            <i class="fas fa-check"></i> Принять
                                                        </a>
                                                    <?php endif; ?>
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