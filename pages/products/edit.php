<?php
/**
 * Редактирование товара
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

$errors = [];
$formData = [];

// Обработка формы
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf_token = $_POST['csrf_token'] ?? '';
    
    if (!verifyCSRFToken($csrf_token)) {
        $errors['csrf'] = 'Ошибка безопасности';
    } else {
        // Получаем данные формы
        $formData['arrival_date'] = trim($_POST['arrival_date'] ?? '');
        $formData['transport_number'] = trim($_POST['transport_number'] ?? '');
        $formData['attributes'] = $_POST['attributes'] ?? [];
        
        // Валидация основных полей
        if (empty($formData['arrival_date'])) {
            $errors['arrival_date'] = 'Укажите дату поступления';
        } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $formData['arrival_date'])) {
            $errors['arrival_date'] = 'Неверный формат даты';
        }
        
        // Если основные поля валидны, обновляем товар
        if (empty($errors)) {
            try {
                $pdo = getDBConnection();
                $user = getCurrentUser();
                
                // Получаем информацию о товаре и проверяем права доступа
                $query = "
                    SELECT p.*, pt.formula, w.company_id
                    FROM products p
                    JOIN product_templates pt ON p.template_id = pt.id
                    JOIN warehouses w ON p.warehouse_id = w.id
                    WHERE p.id = ?
                ";
                
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
                    $errors['general'] = 'Товар не найден или недоступен';
                } else {
                    // Получаем атрибуты шаблона для валидации
                    $stmt = $pdo->prepare("
                        SELECT * FROM template_attributes 
                        WHERE template_id = ? 
                        ORDER BY sort_order, id
                    ");
                    $stmt->execute([$product['template_id']]);
                    $templateAttributes = $stmt->fetchAll();
                    
                    // Валидируем атрибуты
                    $validatedAttributes = [];
                    foreach ($templateAttributes as $attr) {
                        $value = $formData['attributes'][$attr['variable']] ?? '';
                        
                        // Проверяем обязательные поля
                        if ($attr['is_required'] && empty($value)) {
                            $errors['attributes'][$attr['variable']] = $attr['name'] . ' обязательно для заполнения';
                            continue;
                        }
                        
                        // Валидация по типу данных
                        if (!empty($value)) {
                            switch ($attr['data_type']) {
                                case 'number':
                                    if (!is_numeric($value)) {
                                        $errors['attributes'][$attr['variable']] = $attr['name'] . ' должно быть числом';
                                    } else {
                                        $validatedAttributes[$attr['variable']] = (float)$value;
                                    }
                                    break;
                                    
                                case 'select':
                                    $options = array_map('trim', explode(',', $attr['options']));
                                    if (!in_array($value, $options)) {
                                        $errors['attributes'][$attr['variable']] = 'Недопустимое значение для ' . $attr['name'];
                                    } else {
                                        $validatedAttributes[$attr['variable']] = $value;
                                    }
                                    break;
                                    
                                default: // text
                                    $validatedAttributes[$attr['variable']] = $value;
                                    break;
                            }
                        }
                    }
                    
                    // Если все валидно, рассчитываем объем и сохраняем
                    if (empty($errors)) {
                        $calculatedVolume = null;
                        
                        // Рассчитываем объем по формуле, если она есть
                        if (!empty($product['formula'])) {
                            try {
                                $calculatedVolume = calculateVolumeByFormula($product['formula'], $validatedAttributes);
                            } catch (Exception $e) {
                                logError('Volume calculation error during edit: ' . $e->getMessage(), [
                                    'product_id' => $productId,
                                    'formula' => $product['formula'],
                                    'attributes' => $validatedAttributes
                                ]);
                                // Не блокируем обновление товара, если формула не работает
                            }
                        }
                        
                        // Обновляем товар
                        $stmt = $pdo->prepare("
                            UPDATE products SET 
                                arrival_date = ?, 
                                transport_number = ?,
                                attributes = ?, 
                                calculated_volume = ?,
                                updated_at = CURRENT_TIMESTAMP
                            WHERE id = ?
                        ");
                        
                        $stmt->execute([
                            $formData['arrival_date'],
                            $formData['transport_number'] ?: null,
                            json_encode($validatedAttributes, JSON_UNESCAPED_UNICODE),
                            $calculatedVolume,
                            $productId
                        ]);
                        
                        $_SESSION['success_message'] = 'Товар успешно обновлен';
                        header('Location: /pages/products/view.php?id=' . $productId);
                        exit;
                    }
                }
                
            } catch (Exception $e) {
                logError('Product edit error: ' . $e->getMessage());
                $errors['general'] = 'Произошла ошибка при сохранении товара';
            }
        }
    }
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
            c.name as company_name
        FROM products p
        JOIN product_templates pt ON p.template_id = pt.id
        JOIN warehouses w ON p.warehouse_id = w.id
        JOIN companies c ON w.company_id = c.id
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
    
    // Получаем атрибуты шаблона
    $stmt = $pdo->prepare("
        SELECT * FROM template_attributes 
        WHERE template_id = ? 
        ORDER BY sort_order, id
    ");
    $stmt->execute([$product['template_id']]);
    $templateAttributes = $stmt->fetchAll();
    
    // Заполняем форму данными товара (если форма не была отправлена)
    if (empty($formData)) {
        $productAttributes = json_decode($product['attributes'], true) ?? [];
        $formData = [
            'arrival_date' => $product['arrival_date'],
            'transport_number' => $product['transport_number'] ?? '',
            'attributes' => $productAttributes
        ];
    }
    
    $pageTitle = 'Редактирование: ' . $product['template_name'];
    
} catch (Exception $e) {
    logError('Product edit page error: ' . $e->getMessage());
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
                <li class="breadcrumb-item">
                    <a href="/pages/products/view.php?id=<?= $productId ?>"><?= e($product['template_name']) ?></a>
                </li>
                <li class="breadcrumb-item active">Редактирование</li>
            </ol>
        </nav>
        <h1 class="h3 mb-0"><?= e($pageTitle) ?></h1>
    </div>
    <div>
        <a href="/pages/products/view.php?id=<?= $productId ?>" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left"></i>
            Назад к товару
        </a>
    </div>
</div>

<?php if (!empty($errors['general'])): ?>
<div class="alert alert-danger">
    <i class="bi bi-exclamation-triangle"></i> <?= e($errors['general']) ?>
</div>
<?php endif; ?>

<?php if (!empty($errors['csrf'])): ?>
<div class="alert alert-danger">
    <i class="bi bi-shield-exclamation"></i> <?= e($errors['csrf']) ?>
</div>
<?php endif; ?>

<form method="POST" id="productEditForm">
    <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
    
    <div class="row">
        <div class="col-lg-8">
            <!-- Информация о типе товара (только для чтения) -->
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="card-title mb-0">
                        <i class="bi bi-info-circle"></i>
                        Информация о товаре
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
                                <small class="text-muted"><?= e($product['company_name']) ?></small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Редактируемые поля -->
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="card-title mb-0">
                        <i class="bi bi-pencil"></i>
                        Основные данные
                    </h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="arrival_date" class="form-label">
                                    Дата поступления <span class="text-danger">*</span>
                                </label>
                                <input type="date" class="form-control <?= !empty($errors['arrival_date']) ? 'is-invalid' : '' ?>" 
                                       id="arrival_date" name="arrival_date" value="<?= e($formData['arrival_date']) ?>" required>
                                <?php if (!empty($errors['arrival_date'])): ?>
                                <div class="invalid-feedback"><?= e($errors['arrival_date']) ?></div>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="transport_number" class="form-label">Номер транспортного средства</label>
                                <input type="text" class="form-control" id="transport_number" name="transport_number" 
                                       value="<?= e($formData['transport_number']) ?>" placeholder="А123БВ77">
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
                    <?php foreach ($templateAttributes as $attr): ?>
                    <div class="mb-3">
                        <label for="attr_<?= e($attr['variable']) ?>" class="form-label">
                            <?= e($attr['name']) ?>
                            <?php if ($attr['is_required']): ?>
                            <span class="text-danger">*</span>
                            <?php endif; ?>
                            <?php if ($attr['unit']): ?>
                            <small class="text-muted">(<?= e($attr['unit']) ?>)</small>
                            <?php endif; ?>
                        </label>
                        
                        <?php if ($attr['data_type'] === 'select'): ?>
                        <select class="form-select <?= !empty($errors['attributes'][$attr['variable']]) ? 'is-invalid' : '' ?>" 
                                id="attr_<?= e($attr['variable']) ?>" name="attributes[<?= e($attr['variable']) ?>]"
                                <?= $attr['is_required'] ? 'required' : '' ?>
                                <?= $attr['in_formula'] ? 'onchange="calculateVolume()"' : '' ?>>
                            <option value="">Выберите значение</option>
                            <?php foreach (array_map('trim', explode(',', $attr['options'])) as $option): ?>
                            <option value="<?= e($option) ?>" 
                                    <?= ($formData['attributes'][$attr['variable']] ?? '') === $option ? 'selected' : '' ?>>
                                <?= e($option) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <?php else: ?>
                        <input type="<?= $attr['data_type'] === 'number' ? 'number' : 'text' ?>" 
                               class="form-control <?= !empty($errors['attributes'][$attr['variable']]) ? 'is-invalid' : '' ?>" 
                               id="attr_<?= e($attr['variable']) ?>" name="attributes[<?= e($attr['variable']) ?>]"
                               value="<?= e($formData['attributes'][$attr['variable']] ?? '') ?>"
                               <?= $attr['is_required'] ? 'required' : '' ?>
                               <?= $attr['data_type'] === 'number' ? 'step="0.001"' : '' ?>
                               <?= $attr['in_formula'] ? 'oninput="calculateVolume()"' : '' ?>
                               placeholder="<?= e($attr['description'] ?: 'Введите ' . mb_strtolower($attr['name'])) ?>">
                        <?php endif; ?>
                        
                        <?php if (!empty($errors['attributes'][$attr['variable']])): ?>
                        <div class="invalid-feedback"><?= e($errors['attributes'][$attr['variable']]) ?></div>
                        <?php endif; ?>
                        
                        <?php if ($attr['description']): ?>
                        <div class="form-text"><?= e($attr['description']) ?></div>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
        
        <div class="col-lg-4">
            <!-- Расчетный объем -->
            <?php if ($product['formula']): ?>
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="card-title mb-0">
                        <i class="bi bi-calculator"></i>
                        Расчетный объем
                    </h5>
                </div>
                <div class="card-body text-center">
                    <div id="volumeResult">
                        <?php if ($product['calculated_volume']): ?>
                        <div class="text-success">
                            <i class="bi bi-check-circle" style="font-size: 2rem;"></i>
                            <h4 class="mt-2"><?= number_format($product['calculated_volume'], 3) ?> м³</h4>
                            <p class="mb-0">Текущий объем</p>
                        </div>
                        <?php else: ?>
                        <div class="text-muted">
                            <i class="bi bi-calculator" style="font-size: 2rem;"></i>
                            <p class="mt-2">Объем будет пересчитан</p>
                        </div>
                        <?php endif; ?>
                    </div>
                    <div class="mt-3">
                        <small class="text-muted">
                            <strong>Формула:</strong> <code><?= e($product['formula']) ?></code>
                        </small>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Кнопки действий -->
            <div class="card">
                <div class="card-body">
                    <div class="d-grid gap-2">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-check-circle"></i>
                            Сохранить изменения
                        </button>
                        <a href="/pages/products/view.php?id=<?= $productId ?>" class="btn btn-outline-secondary">
                            <i class="bi bi-x-circle"></i>
                            Отменить
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</form>

<script>
// Расчет объема (аналогично странице создания)
function calculateVolume() {
    const templateId = <?= $product['template_id'] ?>;
    
    // Собираем значения атрибутов
    const attributes = {};
    document.querySelectorAll('[name^="attributes["]').forEach(input => {
        const match = input.name.match(/attributes\[(.+)\]/);
        if (match && input.value) {
            attributes[match[1]] = input.value;
        }
    });
    
    if (Object.keys(attributes).length === 0) {
        return;
    }
    
    // Отправляем запрос на расчет
    fetch('/ajax/templates.php?action=calculate_volume&template_id=' + templateId, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({ attributes: attributes })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success && data.data.volume !== null) {
            document.getElementById('volumeResult').innerHTML = `
                <div class="text-success">
                    <i class="bi bi-check-circle" style="font-size: 2rem;"></i>
                    <h4 class="mt-2">${data.data.volume} м³</h4>
                    <p class="mb-0">Новый расчетный объем</p>
                </div>
            `;
        } else {
            document.getElementById('volumeResult').innerHTML = `
                <div class="text-warning">
                    <i class="bi bi-exclamation-triangle" style="font-size: 2rem;"></i>
                    <p class="mt-2">Не удалось рассчитать объем</p>
                </div>
            `;
        }
    })
    .catch(error => {
        console.error('Error calculating volume:', error);
    });
}

// Инициализация расчета при загрузке страницы
document.addEventListener('DOMContentLoaded', function() {
    <?php if ($product['formula']): ?>
    calculateVolume();
    <?php endif; ?>
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>