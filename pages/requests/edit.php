<?php
/**
 * Редактирование запроса
 * Система складского учета (SUT)
 */

require_once __DIR__ . '/../../config/config.php';

requireAccess('requests');

$user = getCurrentUser();
$requestId = (int)($_GET['id'] ?? 0);

if ($requestId <= 0) {
    header('Location: /pages/requests/index.php');
    exit;
}

try {
    $pdo = getDBConnection();
    
    // Получаем данные запроса
    $stmt = $pdo->prepare("
        SELECT r.*, pt.name as template_name, pt.description as template_description
        FROM requests r
        LEFT JOIN product_templates pt ON r.template_id = pt.id
        WHERE r.id = ?
    ");
    $stmt->execute([$requestId]);
    $request = $stmt->fetch();
    
    if (!$request) {
        $_SESSION['error_message'] = 'Запрос не найден';
        header('Location: /pages/requests/index.php');
        exit;
    }
    
    // Проверяем права доступа
    if ($request['status'] === 'processed') {
        $_SESSION['error_message'] = 'Нельзя редактировать обработанный запрос';
        header('Location: /pages/requests/view.php?id=' . $requestId);
        exit;
    }
    
    if (!in_array($user['role'], [ROLE_WAREHOUSE_WORKER, ROLE_SALES_MANAGER]) || $request['created_by'] != $user['id']) {
        header('Location: /pages/errors/403.php');
        exit;
    }
    
} catch (Exception $e) {
    logError('Request edit access error: ' . $e->getMessage());
    $_SESSION['error_message'] = 'Произошла ошибка при загрузке запроса';
    header('Location: /pages/requests/index.php');
    exit;
}

$pageTitle = 'Редактировать запрос #' . $request['id'];

$errors = [];
$formData = [
    'template_id' => $request['template_id'],
    'warehouse_id' => $request['warehouse_id'],
    'quantity' => $request['quantity'],
    'delivery_date' => $request['delivery_date'],
    'description' => $request['description'],
    'attributes' => json_decode($request['requested_attributes'], true) ?: []
];

// Обработка формы
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf_token = $_POST['csrf_token'] ?? '';
    
    if (!verifyCSRFToken($csrf_token)) {
        $errors['csrf'] = 'Ошибка безопасности';
    } else {
        // Получаем данные формы
        $formData['template_id'] = (int)($_POST['template_id'] ?? 0);
        $formData['warehouse_id'] = (int)($_POST['warehouse_id'] ?? 0);
        $formData['quantity'] = (float)($_POST['quantity'] ?? 1);
        $formData['delivery_date'] = trim($_POST['delivery_date'] ?? '');
        $formData['description'] = trim($_POST['description'] ?? '');
        $formData['attributes'] = $_POST['attributes'] ?? [];
        
        // Валидация основных полей
        if ($formData['template_id'] <= 0) {
            $errors['template_id'] = 'Выберите тип товара';
        }
        
        if ($formData['warehouse_id'] <= 0) {
            $errors['warehouse_id'] = 'Выберите склад';
        }
        
        if ($formData['quantity'] <= 0) {
            $errors['quantity'] = 'Количество должно быть больше нуля';
        }
        
        if (!empty($formData['delivery_date']) && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $formData['delivery_date'])) {
            $errors['delivery_date'] = 'Неверный формат даты';
        }
        
        // Если основные поля валидны, проверяем доступ к складу и атрибуты
        if (empty($errors)) {
            try {
                // Проверяем доступ к шаблону
                $stmt = $pdo->prepare("SELECT * FROM product_templates WHERE id = ? AND status = 1");
                $stmt->execute([$formData['template_id']]);
                $template = $stmt->fetch();
                
                if (!$template) {
                    $errors['template_id'] = 'Шаблон товара не найден или неактивен';
                } else {
                    // Проверяем доступ к складу
                    if ($user['role'] === ROLE_WAREHOUSE_WORKER) {
                        // Работник склада может создавать запросы только для своего склада
                        $stmt = $pdo->prepare("
                            SELECT w.*, c.name as company_name 
                            FROM warehouses w 
                            JOIN companies c ON w.company_id = c.id 
                            WHERE w.id = ? AND w.status = 1 AND c.status = 1 AND w.id IN (
                                SELECT warehouse_id FROM users WHERE id = ?
                            )
                        ");
                        $stmt->execute([$formData['warehouse_id'], $user['id']]);
                    } else {
                        // Менеджер по продажам может создавать запросы для любого склада
                        $stmt = $pdo->prepare("
                            SELECT w.*, c.name as company_name 
                            FROM warehouses w 
                            JOIN companies c ON w.company_id = c.id 
                            WHERE w.id = ? AND w.status = 1 AND c.status = 1
                        ");
                        $stmt->execute([$formData['warehouse_id']]);
                    }
                    $warehouse = $stmt->fetch();
                    
                    if (!$warehouse) {
                        $errors['warehouse_id'] = 'Склад не найден или недоступен';
                    } else {
                        // Получаем атрибуты шаблона
                        $stmt = $pdo->prepare("
                            SELECT * FROM template_attributes 
                            WHERE template_id = ? 
                            ORDER BY sort_order, id
                        ");
                        $stmt->execute([$formData['template_id']]);
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
                                        $options = [];
                                        try {
                                            $options = json_decode($attr['options'], true) ?: [];
                                        } catch (Exception $e) {
                                            $options = array_map('trim', explode(',', $attr['options']));
                                        }
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
                        
                        // Если все валидно, обновляем запрос
                        if (empty($errors)) {
                            $stmt = $pdo->prepare("
                                UPDATE requests SET
                                    template_id = ?, warehouse_id = ?, quantity = ?, delivery_date = ?, 
                                    description = ?, requested_attributes = ?
                                WHERE id = ? AND created_by = ?
                            ");
                            
                            $stmt->execute([
                                $formData['template_id'],
                                $formData['warehouse_id'],
                                $formData['quantity'],
                                $formData['delivery_date'] ?: null,
                                $formData['description'] ?: null,
                                json_encode($validatedAttributes, JSON_UNESCAPED_UNICODE),
                                $requestId,
                                $user['id']
                            ]);
                            
                            logInfo('Request updated', [
                                'request_id' => $requestId,
                                'template_id' => $formData['template_id'],
                                'warehouse_id' => $formData['warehouse_id'],
                                'quantity' => $formData['quantity'],
                                'updated_by' => $user['id']
                            ]);
                            
                            $_SESSION['success_message'] = 'Запрос успешно обновлен';
                            header('Location: /pages/requests/view.php?id=' . $requestId);
                            exit;
                        }
                    }
                }
                
            } catch (Exception $e) {
                logError('Request update error: ' . $e->getMessage());
                $errors['general'] = 'Произошла ошибка при сохранении запроса';
            }
        }
    }
}

try {
    // Получаем список шаблонов
    $stmt = $pdo->query("
        SELECT id, name, description, formula
        FROM product_templates 
        WHERE status = 1 
        ORDER BY name
    ");
    $templates = $stmt->fetchAll();
    
    // Получаем список складов (в зависимости от роли)
    if ($user['role'] === ROLE_WAREHOUSE_WORKER) {
        // Работник склада видит только свой склад
        $stmt = $pdo->prepare("
            SELECT w.id, w.name, c.name as company_name
            FROM warehouses w
            JOIN companies c ON w.company_id = c.id
            WHERE w.status = 1 AND c.status = 1 AND w.id IN (
                SELECT warehouse_id FROM users WHERE id = ?
            )
            ORDER BY w.name
        ");
        $stmt->execute([$user['id']]);
    } else {
        // Менеджер по продажам видит все склады
        $stmt = $pdo->query("
            SELECT w.id, w.name, c.name as company_name
            FROM warehouses w
            JOIN companies c ON w.company_id = c.id
            WHERE w.status = 1 AND c.status = 1
            ORDER BY c.name, w.name
        ");
    }
    $warehouses = $stmt->fetchAll();
    
    // Получаем атрибуты текущего шаблона
    $templateAttributes = [];
    if ($formData['template_id']) {
        $stmt = $pdo->prepare("
            SELECT * FROM template_attributes 
            WHERE template_id = ? 
            ORDER BY sort_order, id
        ");
        $stmt->execute([$formData['template_id']]);
        $templateAttributes = $stmt->fetchAll();
    }
    
} catch (Exception $e) {
    logError('Request edit page error: ' . $e->getMessage());
    $templates = [];
    $warehouses = [];
    $templateAttributes = [];
}

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item">
                    <a href="/pages/requests/index.php">Запросы</a>
                </li>
                <li class="breadcrumb-item">
                    <a href="/pages/requests/view.php?id=<?= $request['id'] ?>">Запрос #<?= $request['id'] ?></a>
                </li>
                <li class="breadcrumb-item active">Редактирование</li>
            </ol>
        </nav>
        <h1 class="h3 mb-0"><?= e($pageTitle) ?></h1>
    </div>
    <div>
        <a href="/pages/requests/view.php?id=<?= $request['id'] ?>" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left"></i>
            Назад к запросу
        </a>
    </div>
</div>

<?php if (!empty($errors['general'])): ?>
    <div class="alert alert-danger" role="alert">
        <?= e($errors['general']) ?>
    </div>
<?php endif; ?>

<?php if (!empty($errors['csrf'])): ?>
    <div class="alert alert-danger" role="alert">
        <?= e($errors['csrf']) ?>
    </div>
<?php endif; ?>

<form method="POST" id="requestForm">
    <?= generateCSRFToken() ?>
    
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
                                <label for="template_id" class="form-label">
                                    Тип товара <span class="text-danger">*</span>
                                </label>
                                <select class="form-select <?= !empty($errors['template_id']) ? 'is-invalid' : '' ?>"
                                        id="template_id" name="template_id" required onchange="loadTemplateAttributes()">
                                    <option value="">Выберите тип товара</option>
                                    <?php foreach ($templates as $template): ?>
                                        <option value="<?= $template['id'] ?>" 
                                                <?= $formData['template_id'] == $template['id'] ? 'selected' : '' ?>
                                                data-description="<?= e($template['description']) ?>"
                                                data-formula="<?= e($template['formula']) ?>">
                                            <?= e($template['name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <?php if (!empty($errors['template_id'])): ?>
                                    <div class="invalid-feedback"><?= e($errors['template_id']) ?></div>
                                <?php endif; ?>
                                <div id="templateInfo" class="form-text" style="display: none;"></div>
                            </div>
                        </div>
                        
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="warehouse_id" class="form-label">
                                    Склад <span class="text-danger">*</span>
                                </label>
                                <select class="form-select <?= !empty($errors['warehouse_id']) ? 'is-invalid' : '' ?>"
                                        id="warehouse_id" name="warehouse_id" required>
                                    <option value="">Выберите склад</option>
                                    <?php foreach ($warehouses as $warehouse): ?>
                                        <option value="<?= $warehouse['id'] ?>" 
                                                <?= $formData['warehouse_id'] == $warehouse['id'] ? 'selected' : '' ?>>
                                            <?= e($warehouse['name']) ?> (<?= e($warehouse['company_name']) ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <?php if (!empty($errors['warehouse_id'])): ?>
                                    <div class="invalid-feedback"><?= e($errors['warehouse_id']) ?></div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="quantity" class="form-label">
                                    Количество <span class="text-danger">*</span>
                                </label>
                                <input type="number" class="form-control <?= !empty($errors['quantity']) ? 'is-invalid' : '' ?>"
                                       id="quantity" name="quantity" value="<?= e($formData['quantity']) ?>" 
                                       min="0.001" step="0.001" required>
                                <?php if (!empty($errors['quantity'])): ?>
                                    <div class="invalid-feedback"><?= e($errors['quantity']) ?></div>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="delivery_date" class="form-label">Желаемая дата поставки</label>
                                <input type="date" class="form-control <?= !empty($errors['delivery_date']) ? 'is-invalid' : '' ?>"
                                       id="delivery_date" name="delivery_date" value="<?= e($formData['delivery_date']) ?>">
                                <?php if (!empty($errors['delivery_date'])): ?>
                                    <div class="invalid-feedback"><?= e($errors['delivery_date']) ?></div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label for="description" class="form-label">Описание/комментарий</label>
                        <textarea class="form-control" id="description" name="description" rows="3"
                                  placeholder="Дополнительная информация о запросе"><?= e($formData['description']) ?></textarea>
                    </div>
                </div>
            </div>
            
            <!-- Характеристики товара -->
            <div class="card mb-4" id="attributesCard" <?= empty($templateAttributes) ? 'style="display: none;"' : '' ?>>
                <div class="card-header">
                    <h5 class="card-title mb-0">
                        <i class="bi bi-list-ul"></i>
                        Характеристики товара
                    </h5>
                </div>
                <div class="card-body" id="attributesContainer">
                    <?php foreach ($templateAttributes as $attr): ?>
                        <div class="mb-3">
                            <label for="attr_<?= $attr['variable'] ?>" class="form-label">
                                <?= e($attr['name']) ?>
                                <?php if ($attr['is_required']): ?>
                                    <span class="text-danger">*</span>
                                <?php endif; ?>
                                <?php if ($attr['unit']): ?>
                                    <small class="text-muted">(<?= e($attr['unit']) ?>)</small>
                                <?php endif; ?>
                            </label>
                            
                            <?php if ($attr['data_type'] === 'select' && $attr['options']): ?>
                                <?php
                                $options = [];
                                try {
                                    $options = json_decode($attr['options'], true) ?: [];
                                } catch (Exception $e) {
                                    $options = array_map('trim', explode(',', $attr['options']));
                                }
                                ?>
                                <select class="form-select <?= !empty($errors['attributes'][$attr['variable']]) ? 'is-invalid' : '' ?>"
                                        id="attr_<?= $attr['variable'] ?>" name="attributes[<?= $attr['variable'] ?>]"
                                        <?= $attr['is_required'] ? 'required' : '' ?>>
                                    <option value="">Выберите значение</option>
                                    <?php foreach ($options as $option): ?>
                                        <option value="<?= e($option) ?>" 
                                                <?= ($formData['attributes'][$attr['variable']] ?? '') === $option ? 'selected' : '' ?>>
                                            <?= e($option) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            <?php else: ?>
                                <input type="<?= $attr['data_type'] === 'number' ? 'number' : 'text' ?>"
                                       class="form-control <?= !empty($errors['attributes'][$attr['variable']]) ? 'is-invalid' : '' ?>"
                                       id="attr_<?= $attr['variable'] ?>" name="attributes[<?= $attr['variable'] ?>]"
                                       value="<?= e($formData['attributes'][$attr['variable']] ?? '') ?>"
                                       <?= $attr['is_required'] ? 'required' : '' ?>
                                       <?= $attr['data_type'] === 'number' ? 'step="0.001"' : '' ?>
                                       placeholder="<?= e($attr['description'] ?: 'Введите ' . $attr['name']) ?>">
                            <?php endif; ?>
                            
                            <?php if (!empty($errors['attributes'][$attr['variable']])): ?>
                                <div class="invalid-feedback"><?= e($errors['attributes'][$attr['variable']]) ?></div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        
        <div class="col-lg-4">
            <!-- Кнопки действий -->
            <div class="card">
                <div class="card-body">
                    <div class="d-grid gap-2">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-check-circle"></i>
                            Сохранить изменения
                        </button>
                        <a href="/pages/requests/view.php?id=<?= $request['id'] ?>" class="btn btn-outline-secondary">
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
// Загрузка атрибутов шаблона через AJAX
function loadTemplateAttributes() {
    const templateId = document.getElementById('template_id').value;
    const attributesCard = document.getElementById('attributesCard');
    const container = document.getElementById('attributesContainer');
    
    if (!templateId) {
        attributesCard.style.display = 'none';
        return;
    }
    
    // Показываем информацию о шаблоне
    const selectedOption = document.querySelector('#template_id option:checked');
    const templateInfo = document.getElementById('templateInfo');
    const description = selectedOption.getAttribute('data-description');
    
    if (description) {
        templateInfo.innerHTML = '<strong>Описание:</strong> ' + description;
        templateInfo.style.display = 'block';
    } else {
        templateInfo.style.display = 'none';
    }
    
    // Загружаем атрибуты через AJAX
    fetch('/ajax/templates.php?action=get_attributes&template_id=' + templateId)
        .then(response => response.json())
        .then(data => {
            if (data.success && data.attributes && data.attributes.length > 0) {
                container.innerHTML = '';
                
                data.attributes.forEach(attr => {
                    const div = document.createElement('div');
                    div.className = 'mb-3';
                    
                    let inputHtml = '';
                    if (attr.data_type === 'select' && attr.options) {
                        let options = [];
                        try {
                            options = typeof attr.options === 'string' ? JSON.parse(attr.options) : attr.options;
                        } catch (e) {
                            options = attr.options.split(',').map(opt => opt.trim());
                        }
                        
                        inputHtml = `
                            <select class="form-select" id="attr_${attr.variable}" name="attributes[${attr.variable}]"
                                    ${attr.is_required ? 'required' : ''}>
                                <option value="">Выберите значение</option>
                                ${options.map(opt => `<option value="${opt}">${opt}</option>`).join('')}
                            </select>
                        `;
                    } else {
                        inputHtml = `
                            <input type="${attr.data_type === 'number' ? 'number' : 'text'}"
                                   class="form-control" id="attr_${attr.variable}" name="attributes[${attr.variable}]"
                                   ${attr.is_required ? 'required' : ''}
                                   ${attr.data_type === 'number' ? 'step="0.001"' : ''}
                                   placeholder="${attr.description || 'Введите ' + attr.name.toLowerCase()}">
                        `;
                    }
                    
                    div.innerHTML = `
                        <label for="attr_${attr.variable}" class="form-label">
                            ${attr.name}
                            ${attr.is_required ? '<span class="text-danger">*</span>' : ''}
                            ${attr.unit ? '<small class="text-muted">(' + attr.unit + ')</small>' : ''}
                        </label>
                        ${inputHtml}
                    `;
                    
                    container.appendChild(div);
                });
                
                attributesCard.style.display = 'block';
            } else {
                attributesCard.style.display = 'none';
            }
        })
        .catch(error => {
            console.error('Error loading attributes:', error);
            attributesCard.style.display = 'none';
        });
}

// Инициализация при загрузке страницы
document.addEventListener('DOMContentLoaded', function() {
    if (document.getElementById('template_id').value) {
        // Показываем информацию о текущем шаблоне
        const selectedOption = document.querySelector('#template_id option:checked');
        const templateInfo = document.getElementById('templateInfo');
        const description = selectedOption.getAttribute('data-description');
        
        if (description) {
            templateInfo.innerHTML = '<strong>Описание:</strong> ' + description;
            templateInfo.style.display = 'block';
        }
    }
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>