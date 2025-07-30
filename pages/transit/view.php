<?php
require_once '../../config/config.php';
require_once '../../includes/functions.php';

// Проверяем авторизацию и права доступа
if (!isLoggedIn()) {
    redirect('/pages/auth/login.php');
}

// Проверяем права доступа к разделу товаров в пути
if (!hasAccessToSection('goods_in_transit')) {
    redirect('/pages/errors/403.php');
}

$currentUser = getCurrentUser();
$transitId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$transitId) {
    redirect('index.php');
}

$pageTitle = 'Просмотр товара в пути';
$errors = [];
$success = false;

try {
    $pdo = getDBConnection();
    
    // Получаем информацию о товаре в пути
    $transitQuery = "
        SELECT 
            git.*,
            w.name as warehouse_name,
            w.address as warehouse_address,
            c.name as company_name,
            CONCAT(u.first_name, ' ', u.last_name) as created_by_name,
            u.login as created_by_login,
            CONCAT(uc.first_name, ' ', uc.last_name) as confirmed_by_name,
            uc.login as confirmed_by_login
        FROM goods_in_transit git
        JOIN warehouses w ON git.warehouse_id = w.id
        JOIN companies c ON w.company_id = c.id
        JOIN users u ON git.created_by = u.id
        LEFT JOIN users uc ON git.confirmed_by = uc.id
        WHERE git.id = ?
    ";
    
    // Ограичиваем доступ для работника склада
    if ($currentUser['role'] !== 'admin' && $currentUser['warehouse_id']) {
        $transitQuery .= " AND git.warehouse_id = " . $currentUser['warehouse_id'];
    }
    
    $stmt = $pdo->prepare($transitQuery);
    $stmt->execute([$transitId]);
    $transit = $stmt->fetch();
    
    if (!$transit) {
        redirect('index.php');
    }
    
    // Декодируем информацию о товарах
    $goodsInfo = json_decode($transit['goods_info'], true) ?: [];
    $files = json_decode($transit['files'], true) ?: [];
    
    // Получаем информацию о шаблонах товаров
    $templateIds = array_column($goodsInfo, 'template_id');
    $templates = [];
    $templateAttributes = [];
    
    if (!empty($templateIds)) {
        $placeholders = str_repeat('?,', count($templateIds) - 1) . '?';
        
        // Получаем шаблоны
        $templatesQuery = "SELECT id, name, unit FROM product_templates WHERE id IN ($placeholders)";
        $stmt = $pdo->prepare($templatesQuery);
        $stmt->execute($templateIds);
        $templatesData = $stmt->fetchAll();
        
        foreach ($templatesData as $template) {
            $templates[$template['id']] = $template;
        }
        
        // Получаем атрибуты шаблонов
        $attributesQuery = "
            SELECT template_id, name, variable, data_type, options, unit
            FROM template_attributes 
            WHERE template_id IN ($placeholders)
            ORDER BY template_id, sort_order
        ";
        $stmt = $pdo->prepare($attributesQuery);
        $stmt->execute($templateIds);
        $attributesData = $stmt->fetchAll();
        
        foreach ($attributesData as $attr) {
            $templateAttributes[$attr['template_id']][] = $attr;
        }
    }
    
} catch (Exception $e) {
    logError('Transit view error: ' . $e->getMessage());
    redirect('index.php');
}

// Обработка подтверждения прибытия
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'confirm_arrival' && $transit['status'] === 'in_transit') {
        try {
            $updateQuery = "
                UPDATE goods_in_transit 
                SET status = 'arrived', updated_at = CURRENT_TIMESTAMP 
                WHERE id = ?
            ";
            $stmt = $pdo->prepare($updateQuery);
            $stmt->execute([$transitId]);
            
            $success = true;
            $transit['status'] = 'arrived';
            
        } catch (Exception $e) {
            logError('Transit arrival confirmation error: ' . $e->getMessage());
            $errors[] = 'Ошибка при подтверждении прибытия товара';
        }
    }
    
    if ($_POST['action'] === 'confirm_receipt' && $transit['status'] === 'arrived') {
        try {
            $pdo->beginTransaction();
            
            $updateQuery = "
                UPDATE goods_in_transit 
                SET status = 'confirmed', confirmed_by = ?, confirmed_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP 
                WHERE id = ?
            ";
            $stmt = $pdo->prepare($updateQuery);
            $stmt->execute([$currentUser['id'], $transitId]);
            
            $pdo->commit();
            $success = true;
            $transit['status'] = 'confirmed';
            $transit['confirmed_by'] = $currentUser['id'];
            $transit['confirmed_at'] = date('Y-m-d H:i:s');
            $transit['confirmed_by_name'] = $currentUser['first_name'] . ' ' . $currentUser['last_name'];
            
        } catch (Exception $e) {
            $pdo->rollBack();
            logError('Transit receipt confirmation error: ' . $e->getMessage());
            $errors[] = 'Ошибка при подтверждении получения товара';
        }
    }
}

include '../../includes/header.php';
?>

<div class="container-fluid">
    <div class="row">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <h1><?= htmlspecialchars($pageTitle) ?> #<?= $transit['id'] ?></h1>
                    <nav aria-label="breadcrumb">
                        <ol class="breadcrumb">
                            <li class="breadcrumb-item"><a href="index.php">Товар в пути</a></li>
                            <li class="breadcrumb-item active">Просмотр #<?= $transit['id'] ?></li>
                        </ol>
                    </nav>
                </div>
                <div>
                    <?php if (hasAccessToSection('goods_in_transit', 'edit') && $transit['status'] !== 'confirmed'): ?>
                        <a href="edit.php?id=<?= $transit['id'] ?>" class="btn btn-outline-primary">
                            <i class="bi bi-pencil"></i> Редактировать
                        </a>
                    <?php endif; ?>
                    <a href="index.php" class="btn btn-outline-secondary">
                        <i class="bi bi-arrow-left"></i> Назад к списку
                    </a>
                </div>
            </div>

            <?php if (!empty($errors)): ?>
                <div class="alert alert-danger">
                    <ul class="mb-0">
                        <?php foreach ($errors as $error): ?>
                            <li><?= htmlspecialchars($error) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <?php if ($success): ?>
                <div class="alert alert-success">
                    <i class="bi bi-check-circle"></i> Операция выполнена успешно
                </div>
            <?php endif; ?>

            <div class="row">
                <div class="col-lg-8">
                    <!-- Основная информация -->
                    <div class="card mb-4">
                        <div class="card-header">
                            <h5 class="card-title mb-0">
                                <i class="bi bi-info-circle"></i> Информация о доставке
                            </h5>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-6">
                                    <h6>Отправка</h6>
                                    <table class="table table-borderless">
                                        <tr>
                                            <td><strong>Место отгрузки:</strong></td>
                                            <td><?= htmlspecialchars($transit['departure_location']) ?></td>
                                        </tr>
                                        <tr>
                                            <td><strong>Дата отгрузки:</strong></td>
                                            <td><?= date('d.m.Y', strtotime($transit['departure_date'])) ?></td>
                                        </tr>
                                        <tr>
                                            <td><strong>Создал:</strong></td>
                                            <td>
                                                <?= htmlspecialchars($transit['created_by_name']) ?>
                                                <small class="text-muted">(<?= htmlspecialchars($transit['created_by_login']) ?>)</small>
                                            </td>
                                        </tr>
                                        <tr>
                                            <td><strong>Дата создания:</strong></td>
                                            <td><?= date('d.m.Y H:i', strtotime($transit['created_at'])) ?></td>
                                        </tr>
                                    </table>
                                </div>
                                <div class="col-md-6">
                                    <h6>Назначение</h6>
                                    <table class="table table-borderless">
                                        <tr>
                                            <td><strong>Склад:</strong></td>
                                            <td>
                                                <?= htmlspecialchars($transit['warehouse_name']) ?><br>
                                                <small class="text-muted"><?= htmlspecialchars($transit['company_name']) ?></small>
                                            </td>
                                        </tr>
                                        <tr>
                                            <td><strong>Планируемая дата:</strong></td>
                                            <td><?= date('d.m.Y', strtotime($transit['arrival_date'])) ?></td>
                                        </tr>
                                        <?php if ($transit['confirmed_by']): ?>
                                        <tr>
                                            <td><strong>Подтвердил:</strong></td>
                                            <td>
                                                <?= htmlspecialchars($transit['confirmed_by_name']) ?>
                                                <small class="text-muted">(<?= htmlspecialchars($transit['confirmed_by_login']) ?>)</small>
                                            </td>
                                        </tr>
                                        <tr>
                                            <td><strong>Дата подтверждения:</strong></td>
                                            <td><?= date('d.m.Y H:i', strtotime($transit['confirmed_at'])) ?></td>
                                        </tr>
                                        <?php endif; ?>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Информация о грузе -->
                    <div class="card mb-4">
                        <div class="card-header">
                            <h5 class="card-title mb-0">
                                <i class="bi bi-box-seam"></i> Информация о грузе
                            </h5>
                        </div>
                        <div class="card-body">
                            <?php if (empty($goodsInfo)): ?>
                                <p class="text-muted">Информация о грузе отсутствует</p>
                            <?php else: ?>
                                <?php foreach ($goodsInfo as $index => $good): ?>
                                    <?php $template = $templates[$good['template_id']] ?? null; ?>
                                    <div class="border rounded p-3 mb-3">
                                        <div class="d-flex justify-content-between align-items-start mb-3">
                                            <h6 class="mb-0">Товар #<?= $index + 1 ?></h6>
                                            <span class="badge bg-secondary"><?= number_format($good['quantity'], 3) ?> <?= htmlspecialchars($template['unit'] ?? 'шт') ?></span>
                                        </div>
                                        
                                        <div class="row">
                                            <div class="col-md-6">
                                                <strong>Тип товара:</strong><br>
                                                <?= htmlspecialchars($template['name'] ?? 'Неизвестный товар') ?>
                                            </div>
                                            <div class="col-md-6">
                                                <strong>Количество:</strong><br>
                                                <?= number_format($good['quantity'], 3) ?> <?= htmlspecialchars($template['unit'] ?? 'шт') ?>
                                            </div>
                                        </div>
                                        
                                        <?php if (!empty($good['attributes']) && isset($templateAttributes[$good['template_id']])): ?>
                                            <hr>
                                            <h6>Характеристики:</h6>
                                            <div class="row">
                                                <?php foreach ($templateAttributes[$good['template_id']] as $attr): ?>
                                                    <?php $value = $good['attributes'][$attr['variable']] ?? 'Не указано'; ?>
                                                    <div class="col-md-6 mb-2">
                                                        <strong><?= htmlspecialchars($attr['name']) ?>:</strong>
                                                        <?= htmlspecialchars($value) ?>
                                                        <?php if (!empty($attr['unit'])): ?>
                                                            <?= htmlspecialchars($attr['unit']) ?>
                                                        <?php endif; ?>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Прикрепленные файлы -->
                    <?php if (!empty($files)): ?>
                    <div class="card mb-4">
                        <div class="card-header">
                            <h5 class="card-title mb-0">
                                <i class="bi bi-paperclip"></i> Прикрепленные файлы
                            </h5>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <?php foreach ($files as $file): ?>
                                    <div class="col-md-6 mb-3">
                                        <div class="card">
                                            <div class="card-body">
                                                <h6 class="card-title">
                                                    <i class="bi bi-file-earmark"></i>
                                                    <?= htmlspecialchars($file['original_name']) ?>
                                                </h6>
                                                <p class="card-text">
                                                    <small class="text-muted">
                                                        Размер: <?= formatFileSize($file['file_size']) ?><br>
                                                        Загружен: <?= date('d.m.Y H:i', strtotime($file['uploaded_at'])) ?>
                                                    </small>
                                                </p>
                                                <a href="/<?= $file['file_path'] ?>" class="btn btn-outline-primary btn-sm" target="_blank">
                                                    <i class="bi bi-download"></i> Скачать
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

                <div class="col-lg-4">
                    <!-- Статус и действия -->
                    <div class="card mb-4">
                        <div class="card-header">
                            <h5 class="card-title mb-0">
                                <i class="bi bi-flag"></i> Статус доставки
                            </h5>
                        </div>
                        <div class="card-body text-center">
                            <?php
                            $statusClass = match($transit['status']) {
                                'in_transit' => 'bg-warning',
                                'arrived' => 'bg-info',
                                'confirmed' => 'bg-success',
                                default => 'bg-secondary'
                            };
                            $statusText = match($transit['status']) {
                                'in_transit' => 'В пути',
                                'arrived' => 'Прибыл',
                                'confirmed' => 'Подтвержден',
                                default => $transit['status']
                            };
                            ?>
                            <span class="badge <?= $statusClass ?> fs-5 mb-3"><?= $statusText ?></span>
                            
                            <?php if ($transit['status'] === 'in_transit' && hasAccessToSection('goods_in_transit', 'edit')): ?>
                                <form method="POST" class="d-grid">
                                    <input type="hidden" name="action" value="confirm_arrival">
                                    <button type="submit" class="btn btn-info" onclick="return confirm('Подтвердить прибытие товара на склад?')">
                                        <i class="bi bi-geo-alt"></i> Товар прибыл
                                    </button>
                                </form>
                            <?php elseif ($transit['status'] === 'arrived' && hasAccessToSection('goods_in_transit', 'edit')): ?>
                                <form method="POST" class="d-grid">
                                    <input type="hidden" name="action" value="confirm_receipt">
                                    <button type="submit" class="btn btn-success" onclick="return confirm('Подтвердить получение товара? После подтверждения изменения будут невозможны.')">
                                        <i class="bi bi-check-circle"></i> Подтвердить получение
                                    </button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Временная шкала -->
                    <div class="card">
                        <div class="card-header">
                            <h5 class="card-title mb-0">
                                <i class="bi bi-clock-history"></i> История
                            </h5>
                        </div>
                        <div class="card-body">
                            <div class="timeline">
                                <div class="timeline-item">
                                    <div class="timeline-marker bg-primary"></div>
                                    <div class="timeline-content">
                                        <h6>Отправка создана</h6>
                                        <p class="mb-1"><?= date('d.m.Y H:i', strtotime($transit['created_at'])) ?></p>
                                        <small class="text-muted"><?= htmlspecialchars($transit['created_by_name']) ?></small>
                                    </div>
                                </div>
                                
                                <div class="timeline-item">
                                    <div class="timeline-marker <?= $transit['status'] !== 'in_transit' ? 'bg-warning' : 'bg-light' ?>"></div>
                                    <div class="timeline-content">
                                        <h6>Товар отправлен</h6>
                                        <p class="mb-1"><?= date('d.m.Y', strtotime($transit['departure_date'])) ?></p>
                                        <small class="text-muted"><?= htmlspecialchars($transit['departure_location']) ?></small>
                                    </div>
                                </div>
                                
                                <?php if ($transit['status'] === 'arrived' || $transit['status'] === 'confirmed'): ?>
                                <div class="timeline-item">
                                    <div class="timeline-marker bg-info"></div>
                                    <div class="timeline-content">
                                        <h6>Товар прибыл</h6>
                                        <p class="mb-1"><?= date('d.m.Y H:i', strtotime($transit['updated_at'])) ?></p>
                                        <small class="text-muted">На склад <?= htmlspecialchars($transit['warehouse_name']) ?></small>
                                    </div>
                                </div>
                                <?php endif; ?>
                                
                                <?php if ($transit['status'] === 'confirmed'): ?>
                                <div class="timeline-item">
                                    <div class="timeline-marker bg-success"></div>
                                    <div class="timeline-content">
                                        <h6>Получение подтверждено</h6>
                                        <p class="mb-1"><?= date('d.m.Y H:i', strtotime($transit['confirmed_at'])) ?></p>
                                        <small class="text-muted"><?= htmlspecialchars($transit['confirmed_by_name']) ?></small>
                                    </div>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
.timeline {
    position: relative;
    padding-left: 30px;
}

.timeline::before {
    content: '';
    position: absolute;
    left: 10px;
    top: 0;
    bottom: 0;
    width: 2px;
    background: #dee2e6;
}

.timeline-item {
    position: relative;
    margin-bottom: 20px;
}

.timeline-marker {
    position: absolute;
    left: -25px;
    top: 5px;
    width: 20px;
    height: 20px;
    border-radius: 50%;
    border: 2px solid #fff;
    box-shadow: 0 0 0 2px #dee2e6;
}

.timeline-content h6 {
    margin-bottom: 5px;
    font-size: 0.9rem;
}

.timeline-content p {
    margin-bottom: 2px;
    font-size: 0.85rem;
}

.timeline-content small {
    font-size: 0.75rem;
}
</style>

<?php
function formatFileSize($bytes) {
    if ($bytes >= 1073741824) {
        return number_format($bytes / 1073741824, 2) . ' GB';
    } elseif ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 2) . ' MB';
    } elseif ($bytes >= 1024) {
        return number_format($bytes / 1024, 2) . ' KB';
    } else {
        return $bytes . ' bytes';
    }
}
?>

<?php include '../../includes/footer.php'; ?>