<?php
/**
 * Просмотр товара
 * Система складского учета (SUT)
 */

require_once __DIR__ . '/../../config/config.php';

requireAccess('products');

$productId = (int)($_GET['id'] ?? 0);

if (!$productId) {
    $_SESSION['error_message'] = 'Товар не найден';
    header('Location: /pages/products/index.php');
    exit;
}

try {
    $pdo = getDBConnection();
    $user = getCurrentUser();
    
    // Получаем информацию о товаре
    $query = "
        SELECT 
            p.*,
            pt.name as template_name,
            pt.description as template_description,
            pt.formula,
            w.name as warehouse_name,
            w.address as warehouse_address,
            c.name as company_name,
            u.first_name as creator_name,
            u.last_name as creator_lastname
        FROM products p
        JOIN product_templates pt ON p.template_id = pt.id
        JOIN warehouses w ON p.warehouse_id = w.id
        JOIN companies c ON w.company_id = c.id
        LEFT JOIN users u ON p.created_by = u.id
        WHERE p.id = ?
    ";
    
    // Ограничения по роли пользователя
    if ($user['role'] !== ROLE_ADMIN) {
        $query .= " AND w.company_id = ?";
        $stmt = $pdo->prepare($query);
        $stmt->execute([$productId, $user['company_id']]);
    } else {
        $stmt = $pdo->prepare($query);
        $stmt->execute([$productId]);
    }
    
    $product = $stmt->fetch();
    
    if (!$product) {
        $_SESSION['error_message'] = 'Товар не найден или недоступен';
        header('Location: /pages/products/index.php');
        exit;
    }
    
    // Получаем атрибуты шаблона для правильного отображения
    $stmt = $pdo->prepare("
        SELECT * FROM template_attributes 
        WHERE template_id = ? 
        ORDER BY sort_order, id
    ");
    $stmt->execute([$product['template_id']]);
    $templateAttributes = $stmt->fetchAll();
    
    // Декодируем атрибуты товара
    $productAttributes = json_decode($product['attributes'], true) ?? [];
    
    $pageTitle = $product['template_name'];
    
} catch (Exception $e) {
    logError('Product view error: ' . $e->getMessage(), ['product_id' => $productId]);
    $_SESSION['error_message'] = 'Произошла ошибка при загрузке товара';
    header('Location: /pages/products/index.php');
    exit;
}

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item">
                    <a href="/pages/products/index.php">Товары</a>
                </li>
                <li class="breadcrumb-item active"><?= e($pageTitle) ?></li>
            </ol>
        </nav>
        <h1 class="h3 mb-0"><?= e($pageTitle) ?></h1>
        <p class="text-muted mb-0">Детальная информация о товаре</p>
    </div>
    <div>
        <a href="/pages/products/edit.php?id=<?= $productId ?>" class="btn btn-primary">
            <i class="bi bi-pencil"></i>
            Редактировать
        </a>
        <a href="/pages/products/index.php" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left"></i>
            Назад к списку
        </a>
    </div>
</div>

<div class="row">
    <div class="col-lg-8">
        <!-- Основная информация -->
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="card-title mb-0">
                    <i class="bi bi-info-circle"></i>
                    Основная информация
                </h5>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label class="form-label text-muted">Тип товара</label>
                            <div class="fw-semibold"><?= e($product['template_name']) ?></div>
                            <?php if ($product['template_description']): ?>
                            <small class="text-muted"><?= e($product['template_description']) ?></small>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label class="form-label text-muted">Склад</label>
                            <div class="fw-semibold"><?= e($product['warehouse_name']) ?></div>
                            <small class="text-muted">
                                <?= e($product['company_name']) ?>
                                <?php if ($product['warehouse_address']): ?>
                                <br><?= e($product['warehouse_address']) ?>
                                <?php endif; ?>
                            </small>
                        </div>
                    </div>
                </div>
                
                <div class="row">
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label class="form-label text-muted">Дата поступления</label>
                            <div class="fw-semibold">
                                <?= date('d.m.Y', strtotime($product['arrival_date'])) ?>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label class="form-label text-muted">Номер транспортного средства</label>
                            <div class="fw-semibold">
                                <?php if ($product['transport_number']): ?>
                                <code><?= e($product['transport_number']) ?></code>
                                <?php else: ?>
                                <span class="text-muted">Не указан</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="row">
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label class="form-label text-muted">Добавил</label>
                            <div class="fw-semibold">
                                <?= e(trim($product['creator_lastname'] . ' ' . $product['creator_name'])) ?>
                            </div>
                            <small class="text-muted">
                                <?= date('d.m.Y H:i', strtotime($product['created_at'])) ?>
                            </small>
                        </div>
                    </div>
                    
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label class="form-label text-muted">Последнее изменение</label>
                            <div class="fw-semibold">
                                <?= date('d.m.Y H:i', strtotime($product['updated_at'])) ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Характеристики товара -->
        <?php if (!empty($templateAttributes)): ?>
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="card-title mb-0">
                    <i class="bi bi-list-ul"></i>
                    Характеристики товара
                </h5>
            </div>
            <div class="card-body">
                <div class="row">
                    <?php foreach ($templateAttributes as $attr): ?>
                    <div class="col-md-6 mb-3">
                        <label class="form-label text-muted">
                            <?= e($attr['name']) ?>
                            <?php if ($attr['unit']): ?>
                            <small>(<?= e($attr['unit']) ?>)</small>
                            <?php endif; ?>
                        </label>
                        <div class="fw-semibold">
                            <?php 
                            $value = $productAttributes[$attr['variable']] ?? '';
                            if ($value !== '') {
                                if ($attr['data_type'] === 'number') {
                                    echo number_format($value, 3);
                                } else {
                                    echo e($value);
                                }
                            } else {
                                echo '<span class="text-muted">Не указано</span>';
                            }
                            ?>
                        </div>
                        <?php if ($attr['description']): ?>
                        <small class="text-muted"><?= e($attr['description']) ?></small>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
    
    <div class="col-lg-4">
        <!-- Расчетный объем -->
        <?php if ($product['calculated_volume'] || $product['formula']): ?>
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="card-title mb-0">
                    <i class="bi bi-calculator"></i>
                    Расчетный объем
                </h5>
            </div>
            <div class="card-body text-center">
                <?php if ($product['calculated_volume']): ?>
                <div class="text-success">
                    <i class="bi bi-check-circle" style="font-size: 3rem;"></i>
                    <h3 class="mt-2 text-primary"><?= number_format($product['calculated_volume'], 3) ?> м³</h3>
                    <p class="text-muted mb-0">Объем по формуле</p>
                </div>
                <?php else: ?>
                <div class="text-warning">
                    <i class="bi bi-exclamation-triangle" style="font-size: 3rem;"></i>
                    <h5 class="mt-2">Не рассчитан</h5>
                    <p class="text-muted mb-0">Объем не был рассчитан</p>
                </div>
                <?php endif; ?>
                
                <?php if ($product['formula']): ?>
                <div class="mt-3 pt-3 border-top">
                    <small class="text-muted">
                        <strong>Формула:</strong><br>
                        <code><?= e($product['formula']) ?></code>
                    </small>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Действия -->
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="card-title mb-0">
                    <i class="bi bi-gear"></i>
                    Действия
                </h5>
            </div>
            <div class="card-body">
                <div class="d-grid gap-2">
                    <a href="/pages/products/edit.php?id=<?= $productId ?>" class="btn btn-primary">
                        <i class="bi bi-pencil"></i>
                        Редактировать товар
                    </a>
                    
                    <?php if ($product['calculated_volume'] && $product['formula']): ?>
                    <button type="button" class="btn btn-outline-info" onclick="recalculateVolume()">
                        <i class="bi bi-calculator"></i>
                        Пересчитать объем
                    </button>
                    <?php endif; ?>
                    
                    <button type="button" class="btn btn-outline-danger" onclick="confirmDelete()">
                        <i class="bi bi-trash"></i>
                        Удалить товар
                    </button>
                </div>
            </div>
        </div>
        
        <!-- История изменений -->
        <div class="card">
            <div class="card-header">
                <h5 class="card-title mb-0">
                    <i class="bi bi-clock-history"></i>
                    История
                </h5>
            </div>
            <div class="card-body">
                <div class="timeline">
                    <div class="timeline-item">
                        <div class="timeline-marker bg-success"></div>
                        <div class="timeline-content">
                            <h6 class="mb-1">Товар создан</h6>
                            <small class="text-muted">
                                <?= date('d.m.Y H:i', strtotime($product['created_at'])) ?>
                                <br>
                                <?= e(trim($product['creator_lastname'] . ' ' . $product['creator_name'])) ?>
                            </small>
                        </div>
                    </div>
                    
                    <?php if ($product['updated_at'] !== $product['created_at']): ?>
                    <div class="timeline-item">
                        <div class="timeline-marker bg-info"></div>
                        <div class="timeline-content">
                            <h6 class="mb-1">Последнее изменение</h6>
                            <small class="text-muted">
                                <?= date('d.m.Y H:i', strtotime($product['updated_at'])) ?>
                            </small>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function recalculateVolume() {
    if (!confirm('Пересчитать объем товара по формуле?')) {
        return;
    }
    
    // Отправляем запрос на пересчет
    fetch('/api/products/<?= $productId ?>/recalculate', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        }
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            location.reload();
        } else {
            alert('Ошибка пересчета: ' + (data.message || 'Неизвестная ошибка'));
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('Произошла ошибка при пересчете объема');
    });
}

function confirmDelete() {
    if (confirm('Вы уверены, что хотите удалить этот товар? Это действие необратимо.')) {
        window.location.href = '/pages/products/delete.php?id=<?= $productId ?>';
    }
}
</script>

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
    width: 12px;
    height: 12px;
    border-radius: 50%;
    border: 2px solid #fff;
}

.timeline-content {
    padding-left: 15px;
}
</style>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>