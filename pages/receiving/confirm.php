<?php
/**
 * Страница подтверждения приемки товара
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

if (!$transitId) {
    header('Location: /pages/errors/404.php');
    exit();
}

$success = false;
$error = null;

try {
    $pdo = getDBConnection();
    
    // Получение информации о товаре в пути
    $query = "
        SELECT 
            gt.*,
            w.name as warehouse_name,
            w.address as warehouse_address
        FROM goods_in_transit gt
        LEFT JOIN warehouses w ON gt.warehouse_id = w.id
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
    
    // Проверка статуса - можно подтверждать только товары со статусом 'confirmed'
    if ($transit['status'] !== 'confirmed') {
        $error = 'Данный товар нельзя принять. Статус: ' . $transit['status'];
    }
    
    // Декодирование информации о товарах
    $goodsInfo = json_decode($transit['goods_info'], true) ?: [];
    
    // Обработка формы подтверждения приемки
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$error) {
        $notes = trim($_POST['notes'] ?? '');
        $damagedGoods = $_POST['damaged_goods'] ?? '';
        
        try {
            $pdo->beginTransaction();
            
            // Обновляем статус товара в пути и добавляем информацию о подтверждении
            $updateQuery = "
                UPDATE goods_in_transit 
                SET 
                    status = 'received',
                    confirmed_by = :confirmed_by,
                    confirmed_at = NOW(),
                    notes = CONCAT(COALESCE(notes, ''), :notes_separator, :notes)
                WHERE id = :id
            ";
            
            $stmt = $pdo->prepare($updateQuery);
            $stmt->execute([
                'id' => $transitId,
                'confirmed_by' => $_SESSION['user_id'],
                'notes_separator' => $transit['notes'] ? "\n\n--- Приемка ---\n" : "--- Приемка ---\n",
                'notes' => $notes . ($damagedGoods ? "\nПоврежденные товары: " . $damagedGoods : '')
            ]);
            
            // Добавляем товары в остатки склада
            foreach ($goodsInfo as $item) {
                if (empty($item['template_id']) || empty($item['quantity'])) {
                    continue;
                }
                
                $templateId = (int)$item['template_id'];
                $quantity = (float)$item['quantity'];
                $attributes = $item['attributes'] ?? [];
                
                // Вычисляем хэш атрибутов
                $attributesHash = hash('sha256', json_encode($attributes, JSON_UNESCAPED_UNICODE | JSON_SORT_KEYS));
                
                // Проверяем, есть ли уже такая запись в остатках
                $checkQuery = "
                    SELECT id, quantity 
                    FROM inventory 
                    WHERE warehouse_id = :warehouse_id 
                      AND template_id = :template_id 
                      AND product_attributes_hash = :attributes_hash
                ";
                
                $checkStmt = $pdo->prepare($checkQuery);
                $checkStmt->execute([
                    'warehouse_id' => $transit['warehouse_id'],
                    'template_id' => $templateId,
                    'attributes_hash' => $attributesHash
                ]);
                
                $existingInventory = $checkStmt->fetch();
                
                if ($existingInventory) {
                    // Обновляем существующую запись
                    $updateInventoryQuery = "
                        UPDATE inventory 
                        SET quantity = quantity + :quantity,
                            updated_at = NOW()
                        WHERE id = :id
                    ";
                    
                    $updateInventoryStmt = $pdo->prepare($updateInventoryQuery);
                    $updateInventoryStmt->execute([
                        'quantity' => $quantity,
                        'id' => $existingInventory['id']
                    ]);
                } else {
                    // Создаем новую запись
                    $insertInventoryQuery = "
                        INSERT INTO inventory 
                        (warehouse_id, template_id, quantity, product_attributes_hash, created_at, updated_at)
                        VALUES (:warehouse_id, :template_id, :quantity, :attributes_hash, NOW(), NOW())
                    ";
                    
                    $insertInventoryStmt = $pdo->prepare($insertInventoryQuery);
                    $insertInventoryStmt->execute([
                        'warehouse_id' => $transit['warehouse_id'],
                        'template_id' => $templateId,
                        'quantity' => $quantity,
                        'attributes_hash' => $attributesHash
                    ]);
                }
            }
            
            $pdo->commit();
            
            // Редирект на страницу просмотра с сообщением об успехе
            header('Location: view.php?id=' . $transitId . '&success=1');
            exit();
            
        } catch (Exception $e) {
            $pdo->rollback();
            logError('Ошибка при подтверждении приемки: ' . $e->getMessage());
            $error = 'Ошибка при подтверждении приемки. Попробуйте еще раз.';
        }
    }
    
} catch (Exception $e) {
    logError('Ошибка при загрузке страницы подтверждения приемки: ' . $e->getMessage());
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

include '../../includes/header.php';
?>

<div class="container-fluid">
    <div class="row">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <h1 class="h3 mb-0">Подтверждение приемки #<?= $transit['id'] ?></h1>
                    <nav aria-label="breadcrumb">
                        <ol class="breadcrumb">
                            <li class="breadcrumb-item"><a href="/pages/receiving/index.php">Приемка</a></li>
                            <li class="breadcrumb-item"><a href="view.php?id=<?= $transit['id'] ?>">Товар #<?= $transit['id'] ?></a></li>
                            <li class="breadcrumb-item active">Подтверждение</li>
                        </ol>
                    </nav>
                </div>
            </div>

            <?php if ($success): ?>
                <div class="alert alert-success" role="alert">
                    <h4 class="alert-heading">Приемка подтверждена!</h4>
                    <p>Товары успешно добавлены в остатки склада.</p>
                    <hr>
                    <div class="d-flex">
                        <a href="/pages/receiving/index.php" class="btn btn-success me-2">К списку приемки</a>
                        <a href="/pages/inventory/index.php" class="btn btn-outline-success">К остаткам</a>
                    </div>
                </div>
            <?php elseif ($error): ?>
                <div class="alert alert-danger" role="alert">
                    <?= htmlspecialchars($error) ?>
                    <hr>
                    <a href="/pages/receiving/index.php" class="btn btn-outline-danger">Назад к списку</a>
                </div>
            <?php else: ?>
                
                <!-- Информация о товаре -->
                <div class="row">
                    <div class="col-lg-8">
                        <div class="card mb-4">
                            <div class="card-header">
                                <h5 class="card-title mb-0">
                                    <i class="fas fa-info-circle me-2"></i>Информация о грузе
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
                                
                                <h6 class="text-muted">Склад назначения</h6>
                                <p class="mb-0">
                                    <i class="fas fa-warehouse me-2"></i>
                                    <?= htmlspecialchars($transit['warehouse_name']) ?>
                                    <br>
                                    <small class="text-muted"><?= htmlspecialchars($transit['warehouse_address']) ?></small>
                                </p>
                            </div>
                        </div>

                        <!-- Товары для приемки -->
                        <div class="card mb-4">
                            <div class="card-header">
                                <h5 class="card-title mb-0">
                                    <i class="fas fa-boxes me-2"></i>Товары для приемки
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

                    <!-- Форма подтверждения -->
                    <div class="col-lg-4">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="card-title mb-0">
                                    <i class="fas fa-check-circle me-2"></i>Подтверждение приемки
                                </h5>
                            </div>
                            <div class="card-body">
                                <form method="POST">
                                    <div class="mb-3">
                                        <label for="notes" class="form-label">Примечания к приемке</label>
                                        <textarea class="form-control" id="notes" name="notes" rows="4" 
                                                  placeholder="Укажите дополнительную информацию о приемке товара..."></textarea>
                                        <div class="form-text">
                                            Например: состояние упаковки, время разгрузки, особенности товара
                                        </div>
                                    </div>

                                    <div class="mb-3">
                                        <label for="damaged_goods" class="form-label">Поврежденные товары</label>
                                        <textarea class="form-control" id="damaged_goods" name="damaged_goods" rows="3" 
                                                  placeholder="Опишите поврежденные товары, если есть..."></textarea>
                                        <div class="form-text">
                                            Укажите какие товары повреждены и характер повреждений
                                        </div>
                                    </div>

                                    <div class="alert alert-warning">
                                        <h6 class="alert-heading">
                                            <i class="fas fa-exclamation-triangle me-2"></i>Внимание!
                                        </h6>
                                        <p class="mb-0">
                                            После подтверждения приемки товары будут автоматически добавлены в остатки склада. 
                                            Отменить данное действие будет невозможно.
                                        </p>
                                    </div>

                                    <div class="d-grid gap-2">
                                        <button type="submit" class="btn btn-success">
                                            <i class="fas fa-check me-2"></i>Подтвердить приемку
                                        </button>
                                        <a href="view.php?id=<?= $transit['id'] ?>" class="btn btn-outline-secondary">
                                            <i class="fas fa-arrow-left me-2"></i>Назад к просмотру
                                        </a>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
                
            <?php endif; ?>
        </div>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>