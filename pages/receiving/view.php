<?php
/**
 * Страница просмотра товара в пути для приемки
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

$transitId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$success = isset($_GET['success']) && $_GET['success'] == '1';

if (!$transitId) {
    header('Location: /pages/errors/404.php');
    exit();
}

$error = null;
$transit = null;

try {
    $pdo = getDBConnection();
    
    // Получение информации о товаре в пути
    $query = "
        SELECT 
            gt.*,
            w.name as warehouse_name,
            w.address as warehouse_address,
            CONCAT(u.first_name, ' ', u.last_name) as created_by_name,
            u.login as created_by_login,
            CONCAT(cu.first_name, ' ', cu.last_name) as confirmed_by_name,
            cu.login as confirmed_by_login
        FROM goods_in_transit gt
        LEFT JOIN warehouses w ON gt.warehouse_id = w.id
        LEFT JOIN users u ON gt.created_by = u.id
        LEFT JOIN users cu ON gt.confirmed_by = cu.id
        WHERE gt.id = :id
    ";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute(['id' => $transitId]);
    $transit = $stmt->fetch();
    
    if (!$transit) {
        header('Location: /pages/errors/404.php');
        exit();
    }
    
    // Проверка прав доступа к конкретному складу
    if ($currentUser['role'] === 'warehouse_worker' && 
        !empty($currentUser['warehouse_id']) && 
        $transit['warehouse_id'] != $currentUser['warehouse_id']) {
        header('Location: /pages/errors/403.php');
        exit();
    }
    
    // Декодирование информации о товарах
    // === Обработка подтверждения приемки на этой же странице ===
    if (
        		empty(
        		    $error
        		) &&
        		$_SERVER['REQUEST_METHOD'] === 'POST' &&
        		isset($_POST['action']) && $_POST['action'] === 'receive'
        	) {
        try {
            $pdo->beginTransaction();
            
            $notes = trim($_POST['notes'] ?? '');
            $damagedGoods = trim($_POST['damaged_goods'] ?? '');
            $notesAppend = '';
            if ($notes !== '' || $damagedGoods !== '') {
                $notesAppend = "\n\n--- Приемка ---\n";
                if ($notes !== '') {
                    $notesAppend .= $notes;
                }
                if ($damagedGoods !== '') {
                    $notesAppend .= ($notes !== '' ? "\n" : '') . 'Поврежденные товары: ' . $damagedGoods;
                }
            }
            
            // Только если статус ещё 'confirmed'
            if ($transit['status'] === 'confirmed') {
                // Обновить запись goods_in_transit
                $update = $pdo->prepare("UPDATE goods_in_transit SET status = 'received', confirmed_by = :user, confirmed_at = NOW(), notes = CONCAT(COALESCE(notes,''), :notesAppend) WHERE id = :id");
                $update->execute([
                    'user' => $currentUser['id'],
                    'notesAppend' => $notesAppend,
                    'id' => $transitId
                ]);
                
                // Добавляем в inventory
                foreach ($goodsInfo as $item) {
                    if (empty($item['template_id']) || empty($item['quantity'])) continue;
                    $templateId = (int)$item['template_id'];
                    $quantity = (float)$item['quantity'];
                    $attributesHash = hash('sha256', json_encode($item['attributes'] ?? [], JSON_UNESCAPED_UNICODE|JSON_SORT_KEYS));
                    
                    // upsert
                    $pdo->prepare("INSERT INTO inventory (warehouse_id, template_id, quantity, product_attributes_hash, created_at, updated_at) VALUES (:w, :t, :q, :h, NOW(), NOW()) ON DUPLICATE KEY UPDATE quantity = quantity + VALUES(quantity), updated_at = NOW()")
                        ->execute([
                            'w' => $transit['warehouse_id'],
                            't' => $templateId,
                            'q' => $quantity,
                            'h' => $attributesHash
                        ]);
                }
            }
            
            $pdo->commit();
            // Перезагружаем данные
            $stmt = $pdo->prepare($query);
            $stmt->execute(['id' => $transitId]);
            $transit = $stmt->fetch();
            $statusInfo = getStatusInfo($transit['status']);
            $success = true;
            $error = null;
        } catch (Exception $e) {
            $pdo->rollBack();
            logError('Ошибка при подтверждении приемки(view): ' . $e->getMessage());
            $error = 'Ошибка при подтверждении приемки. Попробуйте ещё раз.';
        }
    }

    // === Конец обработки подтверждения ===

    // Декодирование информации о товарах
    $goodsInfo = json_decode($transit['goods_info'], true) ?: [];
    
} catch (Exception $e) {
    logError('Ошибка при загрузке страницы просмотра товара в пути: ' . $e->getMessage());
    $error = 'Ошибка при загрузке данных. Попробуйте позже.';
}

// Функция для получения названия шаблона
function getTemplateName($pdo, $templateId) {
    try {
        $stmt = $pdo->prepare("SELECT name, unit FROM product_templates WHERE id = ?");
        $stmt->execute([$templateId]);
        return $stmt->fetch();
    } catch (Exception $e) {
        return ['name' => 'Неизвестный шаблон', 'unit' => ''];
    }
}

// Функция для получения атрибутов шаблона
function getTemplateAttributes($pdo, $templateId) {
    try {
        $stmt = $pdo->prepare("
            SELECT * FROM template_attributes 
            WHERE template_id = ? 
            ORDER BY sort_order, id
        ");
        $stmt->execute([$templateId]);
        return $stmt->fetchAll();
    } catch (Exception $e) {
        return [];
    }
}

// Функция для получения статуса
function getStatusInfo($status) {
    switch ($status) {
        case 'in_transit':
            return ['class' => 'bg-info', 'text' => 'В пути', 'icon' => 'fas fa-truck'];
        case 'arrived':
            return ['class' => 'bg-warning', 'text' => 'Прибыло', 'icon' => 'fas fa-shipping-fast'];
        case 'confirmed':
            return ['class' => 'bg-primary', 'text' => 'Готово к приемке', 'icon' => 'fas fa-box'];
        case 'received':
            return ['class' => 'bg-success', 'text' => 'Принято', 'icon' => 'fas fa-check-circle'];
        default:
            return ['class' => 'bg-secondary', 'text' => 'Неизвестно', 'icon' => 'fas fa-question'];
    }
}

$statusInfo = getStatusInfo($transit['status']);

include '../../includes/header.php';
?>

<div class="container-fluid">
    <div class="row">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <h1 class="h3 mb-0">Товар в пути #<?= $transit['id'] ?></h1>
                    <nav aria-label="breadcrumb">
                        <ol class="breadcrumb">
                            <li class="breadcrumb-item"><a href="/pages/receiving/index.php">Приемка</a></li>
                            <li class="breadcrumb-item active">Просмотр</li>
                        </ol>
                    </nav>
                </div>
                <div>
                    <?php if ($transit['status'] === 'confirmed'): ?>
                        <form method="POST" class="d-inline">
                            <input type="hidden" name="action" value="receive">
                            <button type="submit" class="btn btn-success">
                                <i class="fas fa-check me-2"></i>Подтвердить приемку
                            </button>
                        </form>
                    <?php endif; ?>
                    <a href="/pages/receiving/index.php" class="btn btn-outline-secondary">
                        <i class="fas fa-arrow-left me-2"></i>Назад к списку
                    </a>
                </div>
            </div>

            <?php if ($success): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <h4 class="alert-heading">
                        <i class="fas fa-check-circle me-2"></i>Приемка успешно подтверждена!
                    </h4>
                    <p class="mb-0">
                        Товары добавлены в остатки склада. 
                        <a href="/pages/inventory/index.php" class="alert-link">Просмотреть остатки</a>
                    </p>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="alert alert-danger" role="alert">
                    <?= htmlspecialchars($error) ?>
                </div>
            <?php else: ?>
                
                <!-- Основная информация -->
                <div class="row">
                    <div class="col-lg-8">
                        <div class="card mb-4">
                            <div class="card-header">
                                <h5 class="card-title mb-0">
                                    <i class="fas fa-info-circle me-2"></i>Основная информация
                                </h5>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-6">
                                        <h6 class="text-muted">Отправление</h6>
                                        <p class="mb-2">
                                            <i class="fas fa-map-marker-alt me-2"></i>
                                            <?= htmlspecialchars($transit['departure_location']) ?>
                                        </p>
                                        <p class="mb-0">
                                            <i class="fas fa-calendar me-2"></i>
                                            <?= date('d.m.Y H:i', strtotime($transit['departure_date'])) ?>
                                        </p>
                                    </div>
                                    <div class="col-md-6">
                                        <h6 class="text-muted">Прибытие</h6>
                                        <p class="mb-0">
                                            <i class="fas fa-calendar me-2"></i>
                                            <?= date('d.m.Y H:i', strtotime($transit['arrival_date'])) ?>
                                        </p>
                                    </div>
                                </div>
                                
                                <hr>
                                
                                <div class="row">
                                    <div class="col-md-6">
                                        <h6 class="text-muted">Склад назначения</h6>
                                        <p class="mb-0">
                                            <i class="fas fa-warehouse me-2"></i>
                                            <?= htmlspecialchars($transit['warehouse_name']) ?>
                                            <br>
                                            <small class="text-muted"><?= htmlspecialchars($transit['warehouse_address']) ?></small>
                                        </p>
                                    </div>
                                    <div class="col-md-6">
                                        <h6 class="text-muted">Статус</h6>
                                        <span class="badge <?= $statusInfo['class'] ?> fs-6">
                                            <i class="<?= $statusInfo['icon'] ?> me-1"></i>
                                            <?= $statusInfo['text'] ?>
                                        </span>
                                    </div>
                                </div>
                                
                                <?php if ($transit['notes']): ?>
                                    <hr>
                                    <h6 class="text-muted">Примечания</h6>
                                    <p class="mb-0"><?= nl2br(htmlspecialchars($transit['notes'])) ?></p>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Товары -->
                        <div class="card mb-4">
                            <div class="card-header">
                                <h5 class="card-title mb-0">
                                    <i class="fas fa-boxes me-2"></i>Товары
                                    <span class="badge bg-secondary ms-2"><?= count($goodsInfo) ?></span>
                                </h5>
                            </div>
                            <div class="card-body">
                                <?php if (empty($goodsInfo)): ?>
                                    <p class="text-muted">Информация о товарах не найдена</p>
                                <?php else: ?>
                                    <div class="table-responsive">
                                        <table class="table table-hover">
                                            <thead>
                                                <tr>
                                                    <th>Товар</th>
                                                    <th>Количество</th>
                                                    <th>Единица</th>
                                                    <th>Характеристики</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($goodsInfo as $index => $item): ?>
                                                    <?php 
                                                    $template = getTemplateName($pdo, $item['template_id'] ?? 0);
                                                    $templateAttributes = getTemplateAttributes($pdo, $item['template_id'] ?? 0);
                                                    $attributeMap = [];
                                                    foreach ($templateAttributes as $attr) {
                                                        $attributeMap[$attr['variable']] = $attr['name'];
                                                    }
                                                    ?>
                                                    <tr>
                                                        <td>
                                                            <strong><?= htmlspecialchars($template['name']) ?></strong>
                                                        </td>
                                                        <td>
                                                            <span class="badge bg-primary fs-6">
                                                                <?= number_format($item['quantity'] ?? 0, 2) ?>
                                                            </span>
                                                        </td>
                                                        <td>
                                                            <?= htmlspecialchars($template['unit'] ?? $item['unit'] ?? '') ?>
                                                        </td>
                                                        <td>
                                                            <?php if (!empty($item['attributes'])): ?>
                                                                <div class="small">
                                                                    <?php foreach ($item['attributes'] as $key => $value): ?>
                                                                        <?php if ($value !== ''): ?>
                                                                            <div>
                                                                                <strong><?= htmlspecialchars($attributeMap[$key] ?? $key) ?>:</strong>
                                                                                <?= htmlspecialchars($value) ?>
                                                                            </div>
                                                                        <?php endif; ?>
                                                                    <?php endforeach; ?>
                                                                </div>
                                                            <?php else: ?>
                                                                <span class="text-muted">Нет характеристик</span>
                                                            <?php endif; ?>
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

                    <!-- Дополнительная информация -->
                    <div class="col-lg-4">
                        <div class="card mb-4">
                            <div class="card-header">
                                <h5 class="card-title mb-0">
                                    <i class="fas fa-history me-2"></i>История
                                </h5>
                            </div>
                            <div class="card-body">
                                <div class="timeline">
                                    <div class="timeline-item">
                                        <div class="timeline-marker bg-primary"></div>
                                        <div class="timeline-content">
                                            <h6 class="timeline-title">Создан</h6>
                                            <p class="timeline-text">
                                                <?= date('d.m.Y H:i', strtotime($transit['created_at'])) ?>
                                                <br>
                                                <small class="text-muted">
                                                    <?= htmlspecialchars($transit['created_by_name']) ?>
                                                </small>
                                            </p>
                                        </div>
                                    </div>
                                    
                                    <?php if ($transit['confirmed_by']): ?>
                                        <div class="timeline-item">
                                            <div class="timeline-marker bg-success"></div>
                                            <div class="timeline-content">
                                                <h6 class="timeline-title">Принят</h6>
                                                <p class="timeline-text">
                                                    <?= date('d.m.Y H:i', strtotime($transit['confirmed_at'])) ?>
                                                    <br>
                                                    <small class="text-muted">
                                                        <?= htmlspecialchars($transit['confirmed_by_name']) ?>
                                                    </small>
                                                </p>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <!-- Действия -->
                        <div class="card">
                            <div class="card-header">
                                <h5 class="card-title mb-0">
                                    <i class="fas fa-cogs me-2"></i>Действия
                                </h5>
                            </div>
                            <div class="card-body">
                                <div class="d-grid gap-2">
                                    <?php if ($transit['status'] === 'confirmed'): ?>
                                        <form method="POST" class="d-grid gap-2 mb-2">
                                            <input type="hidden" name="action" value="receive">
                                            <button type="submit" class="btn btn-success">
                                                <i class="fas fa-check me-2"></i>Подтвердить приемку
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                    
                                    <a href="/pages/transit/view.php?id=<?= $transit['id'] ?>" class="btn btn-outline-primary">
                                        <i class="fas fa-eye me-2"></i>Просмотр в разделе "Товар в пути"
                                    </a>
                                    
                                    <a href="/pages/inventory/index.php" class="btn btn-outline-info">
                                        <i class="fas fa-boxes me-2"></i>Остатки на складах
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
            <?php endif; ?>
        </div>
    </div>
</div>

<style>
.timeline {
    position: relative;
    padding-left: 30px;
}

.timeline-item {
    position: relative;
    margin-bottom: 20px;
}

.timeline-marker {
    position: absolute;
    left: -35px;
    top: 0;
    width: 12px;
    height: 12px;
    border-radius: 50%;
    border: 2px solid #fff;
    box-shadow: 0 0 0 2px #dee2e6;
}

.timeline-content {
    padding-left: 10px;
}

.timeline-title {
    margin-bottom: 5px;
    font-weight: 600;
}

.timeline-text {
    margin-bottom: 0;
    font-size: 0.9rem;
}
</style>

<?php include '../../includes/footer.php'; ?>