<?php
/**
 * Страница просмотра деталей товара для приемки
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

$transitId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$showSuccess = isset($_GET['success']) && $_GET['success'] == '1';

if (!$transitId) {
    header('Location: /pages/errors/404.php');
    exit();
}

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
            CONCAT(uc.first_name, ' ', uc.last_name) as confirmed_by_name,
            uc.login as confirmed_by_login
        FROM goods_in_transit gt
        LEFT JOIN warehouses w ON gt.warehouse_id = w.id
        LEFT JOIN users u ON gt.created_by = u.id
        LEFT JOIN users uc ON gt.confirmed_by = uc.id
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
    if ($_SESSION['user_role'] === 'warehouse_worker' && 
        !empty($_SESSION['warehouse_id']) && 
        $transit['warehouse_id'] != $_SESSION['warehouse_id']) {
        header('Location: /pages/errors/403.php');
        exit();
    }
    
    // Декодирование информации о товарах
    $goodsInfo = json_decode($transit['goods_info'], true) ?: [];
    
    // Декодирование информации о файлах
    $files = json_decode($transit['files'], true) ?: [];
    
} catch (Exception $e) {
    logError('Ошибка при загрузке деталей товара в пути: ' . $e->getMessage());
    $error = 'Ошибка при загрузке данных. Попробуйте позже.';
}

// Функция для получения атрибутов шаблона товара
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

// Функция для получения названия шаблона
function getTemplateName($pdo, $templateId) {
    try {
        $stmt = $pdo->prepare("SELECT name FROM product_templates WHERE id = ?");
        $stmt->execute([$templateId]);
        $result = $stmt->fetch();
        return $result ? $result['name'] : 'Неизвестный шаблон';
    } catch (Exception $e) {
        return 'Неизвестный шаблон';
    }
}

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
                            <li class="breadcrumb-item active">Товар #<?= $transit['id'] ?></li>
                        </ol>
                    </nav>
                </div>
                <div>
                    <?php 
                    $statusClass = '';
                    $statusText = '';
                    switch ($transit['status']) {
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
                    <span class="badge <?= $statusClass ?> fs-6"><?= $statusText ?></span>
                </div>
            </div>

            <?php if (isset($error)): ?>
                <div class="alert alert-danger" role="alert">
                    <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>

            <?php if ($showSuccess): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <h4 class="alert-heading">
                        <i class="fas fa-check-circle me-2"></i>Приемка успешно подтверждена!
                    </h4>
                    <p class="mb-0">
                        Товары добавлены в остатки склада. Статус изменен на "Принято".
                    </p>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <div class="row">
                <!-- Основная информация -->
                <div class="col-lg-8">
                    <!-- Информация о перевозке -->
                    <div class="card mb-4">
                        <div class="card-header">
                            <h5 class="card-title mb-0">
                                <i class="fas fa-route me-2"></i>Информация о перевозке
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
                                    <p class="mb-2">
                                        <i class="fas fa-map-marker-alt me-2"></i>
                                        <?= htmlspecialchars($transit['arrival_location']) ?>
                                    </p>
                                    <p class="mb-0">
                                        <i class="fas fa-calendar me-2"></i>
                                        <?= date('d.m.Y H:i', strtotime($transit['arrival_date'])) ?>
                                    </p>
                                </div>
                            </div>
                            
                            <hr>
                            
                            <div class="row">
                                <div class="col-md-12">
                                    <h6 class="text-muted">Склад назначения</h6>
                                    <p class="mb-0">
                                        <i class="fas fa-warehouse me-2"></i>
                                        <?= htmlspecialchars($transit['warehouse_name']) ?>
                                        <br>
                                        <small class="text-muted"><?= htmlspecialchars($transit['warehouse_address']) ?></small>
                                    </p>
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
                                <i class="fas fa-boxes me-2"></i>Товары в грузе
                            </h5>
                        </div>
                        <div class="card-body">
                            <?php if (empty($goodsInfo)): ?>
                                <p class="text-muted">Информация о товарах не найдена</p>
                            <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table table-sm">
                                        <thead>
                                            <tr>
                                                <th>Тип товара</th>
                                                <th>Количество</th>
                                                <th>Единица</th>
                                                <th>Характеристики</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($goodsInfo as $index => $item): ?>
                                                <tr>
                                                    <td>
                                                        <strong><?= htmlspecialchars(getTemplateName($pdo, $item['template_id'] ?? 0)) ?></strong>
                                                    </td>
                                                    <td>
                                                        <span class="badge bg-primary">
                                                            <?= number_format($item['quantity'] ?? 0, 2) ?>
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <?= htmlspecialchars($item['unit'] ?? '') ?>
                                                    </td>
                                                    <td>
                                                        <?php if (!empty($item['attributes'])): ?>
                                                            <?php 
                                                            $templateAttributes = getTemplateAttributes($pdo, $item['template_id'] ?? 0);
                                                            $attributeMap = [];
                                                            foreach ($templateAttributes as $attr) {
                                                                $attributeMap[$attr['variable']] = $attr['name'];
                                                            }
                                                            ?>
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

                    <!-- Файлы -->
                    <?php if (!empty($files)): ?>
                        <div class="card mb-4">
                            <div class="card-header">
                                <h5 class="card-title mb-0">
                                    <i class="fas fa-paperclip me-2"></i>Прикрепленные файлы
                                </h5>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <?php foreach ($files as $file): ?>
                                        <div class="col-md-4 mb-3">
                                            <div class="card border">
                                                <div class="card-body text-center p-3">
                                                    <?php
                                                    $extension = strtolower(pathinfo($file['original_name'], PATHINFO_EXTENSION));
                                                    $iconClass = 'fas fa-file';
                                                    
                                                    switch ($extension) {
                                                        case 'pdf':
                                                            $iconClass = 'fas fa-file-pdf text-danger';
                                                            break;
                                                        case 'doc':
                                                        case 'docx':
                                                            $iconClass = 'fas fa-file-word text-primary';
                                                            break;
                                                        case 'xls':
                                                        case 'xlsx':
                                                            $iconClass = 'fas fa-file-excel text-success';
                                                            break;
                                                        case 'jpg':
                                                        case 'jpeg':
                                                        case 'png':
                                                        case 'gif':
                                                            $iconClass = 'fas fa-file-image text-info';
                                                            break;
                                                    }
                                                    ?>
                                                    <i class="<?= $iconClass ?> fa-2x mb-2"></i>
                                                    <h6 class="card-title"><?= htmlspecialchars($file['original_name']) ?></h6>
                                                    <p class="card-text small text-muted">
                                                        <?= number_format($file['size'] / 1024, 1) ?> KB
                                                    </p>
                                                    <a href="/uploads/transit/<?= htmlspecialchars($file['saved_name']) ?>" 
                                                       class="btn btn-sm btn-outline-primary" target="_blank">
                                                        <i class="fas fa-download me-1"></i>Скачать
                                                    </a>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Боковая панель -->
                <div class="col-lg-4">
                    <!-- Действия -->
                    <div class="card mb-4">
                        <div class="card-header">
                            <h5 class="card-title mb-0">Действия</h5>
                        </div>
                        <div class="card-body">
                            <?php if ($transit['status'] === 'confirmed'): ?>
                                <a href="confirm.php?id=<?= $transit['id'] ?>" class="btn btn-success w-100 mb-2">
                                    <i class="fas fa-check me-2"></i>Подтвердить приемку
                                </a>
                                <div class="alert alert-info small">
                                    <i class="fas fa-info-circle me-1"></i>
                                    Товар готов к приемке на склад. После подтверждения товары будут добавлены в остатки.
                                </div>
                            <?php elseif ($transit['status'] === 'received'): ?>
                                <div class="alert alert-success small">
                                    <i class="fas fa-check-circle me-1"></i>
                                    Товар принят на склад. Товары добавлены в остатки.
                                </div>
                            <?php elseif ($transit['status'] === 'arrived'): ?>
                                <div class="alert alert-warning small">
                                    <i class="fas fa-truck me-1"></i>
                                    Товар прибыл на склад, но еще не подтвержден.
                                </div>
                            <?php else: ?>
                                <div class="alert alert-info small">
                                    <i class="fas fa-shipping-fast me-1"></i>
                                    Товар находится в пути.
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Информация о создании -->
                    <div class="card">
                        <div class="card-header">
                            <h5 class="card-title mb-0">Информация</h5>
                        </div>
                        <div class="card-body">
                            <h6 class="text-muted">Создано</h6>
                            <p class="mb-2">
                                <?= date('d.m.Y H:i', strtotime($transit['created_at'])) ?>
                                <br>
                                <small>
                                    <?= htmlspecialchars($transit['created_by_name']) ?>
                                    <span class="text-muted">(<?= htmlspecialchars($transit['created_by_login']) ?>)</span>
                                </small>
                            </p>

                            <?php if ($transit['confirmed_at']): ?>
                                <h6 class="text-muted mt-3">Подтверждено</h6>
                                <p class="mb-0">
                                    <?= date('d.m.Y H:i', strtotime($transit['confirmed_at'])) ?>
                                    <br>
                                    <small>
                                        <?= htmlspecialchars($transit['confirmed_by_name']) ?>
                                        <span class="text-muted">(<?= htmlspecialchars($transit['confirmed_by_login']) ?>)</span>
                                    </small>
                                </p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>