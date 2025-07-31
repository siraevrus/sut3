<?php
/**
 * Создание товара
 * Система складского учета (SUT)
 */



require_once __DIR__ . '/../../config/config.php';

requireAccess('products');

$pageTitle = 'Добавление товара';

$errors = [];
$formData = [
    'template_id' => 0,
    'warehouse_id' => 0,
    'arrival_date' => date('Y-m-d'),
    'transport_number' => '',
    'attributes' => []
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
        $formData['arrival_date'] = trim($_POST['arrival_date'] ?? '');
        $formData['transport_number'] = trim($_POST['transport_number'] ?? '');
        $formData['attributes'] = $_POST['attributes'] ?? [];
        
        // Валидация основных полей
        if ($formData['template_id'] <= 0) {
            $errors['template_id'] = 'Выберите тип товара';
        }
        
        if ($formData['warehouse_id'] <= 0) {
            $errors['warehouse_id'] = 'Выберите склад';
        }
        
        if (empty($formData['arrival_date'])) {
            $errors['arrival_date'] = 'Укажите дату поступления';
        } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $formData['arrival_date'])) {
            $errors['arrival_date'] = 'Неверный формат даты';
        }
        
        // Если основные поля валидны, проверяем доступ к складу и атрибуты
        if (empty($errors)) {
            try {
                $pdo = getDBConnection();
                $user = getCurrentUser();
                
                // Проверяем доступ к шаблону
                $stmt = $pdo->prepare("SELECT * FROM product_templates WHERE id = ? AND status = 1");
                $stmt->execute([$formData['template_id']]);
                $template = $stmt->fetch();
                
                if (!$template) {
                    $errors['template_id'] = 'Шаблон товара не найден или неактивен';
                } else {
                    // Проверяем доступ к складу
                    if ($user['role'] === ROLE_ADMIN) {
                        $stmt = $pdo->prepare("
                            SELECT w.*, c.name as company_name 
                            FROM warehouses w 
                            JOIN companies c ON w.company_id = c.id 
                            WHERE w.id = ? AND w.status = 1 AND c.status = 1
                        ");
                        $stmt->execute([$formData['warehouse_id']]);
                    } else {
                        $stmt = $pdo->prepare("
                            SELECT w.*, c.name as company_name 
                            FROM warehouses w 
                            JOIN companies c ON w.company_id = c.id 
                            WHERE w.id = ? AND w.status = 1 AND c.status = 1 AND w.company_id = ?
                        ");
                        $stmt->execute([$formData['warehouse_id'], $user['company_id']]);
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
                            if (!empty($template['formula'])) {
                                try {
                                    $calculatedVolume = calculateVolumeByFormula($template['formula'], $validatedAttributes);
                                } catch (Exception $e) {
                                    logError('Volume calculation error: ' . $e->getMessage(), [
                                        'template_id' => $template['id'],
                                        'formula' => $template['formula'],
                                        'attributes' => $validatedAttributes
                                    ]);
                                    // Не блокируем создание товара, если формула не работает
                                }
                            }
                            
                            // Сохраняем товар
                            $pdo->beginTransaction();
                            
                            $stmt = $pdo->prepare("
                                INSERT INTO products (
                                    template_id, warehouse_id, arrival_date, transport_number,
                                    attributes, calculated_volume, created_by
                                ) VALUES (?, ?, ?, ?, ?, ?, ?)
                            ");
                            
                            $stmt->execute([
                                $formData['template_id'],
                                $formData['warehouse_id'],
                                $formData['arrival_date'],
                                $formData['transport_number'] ?: null,
                                json_encode($validatedAttributes, JSON_UNESCAPED_UNICODE),
                                $calculatedVolume,
                                $user['id']
                            ]);
                            
                            // Обновляем агрегированные остатки в inventory
                            $quantityValue = isset($validatedAttributes['quantity']) ? (float)$validatedAttributes['quantity'] : 1;
                            
                            $stmtInv = $pdo->prepare("
                                INSERT INTO inventory (warehouse_id, template_id, quantity)
                                VALUES (?, ?, ?)
                                ON DUPLICATE KEY UPDATE quantity = quantity + VALUES(quantity)
                            ");
                            $stmtInv->execute([
                                $formData['warehouse_id'],
                                $formData['template_id'],
                                $quantityValue
                            ]);
                            
                            $pdo->commit();
                            
                            $_SESSION['success_message'] = 'Товар успешно добавлен и остатки обновлены';
                            header('Location: /pages/products/index.php');
                            exit;
                        }
                    }
                }
                
            } catch (Exception $e) {
                logError('Product creation error: ' . $e->getMessage());
                $errors['general'] = 'Произошла ошибка при сохранении товара';
            }
        }
    }
}

try {
    $pdo = getDBConnection();
    $user = getCurrentUser();
    
    // Получаем список шаблонов
    $stmt = $pdo->query("
        SELECT id, name, description, formula
        FROM product_templates 
        WHERE status = 1 
        ORDER BY name
    ");
    $templates = $stmt->fetchAll();
    
    // Получаем список складов (в зависимости от роли)
    if ($user['role'] === ROLE_ADMIN) {
        $stmt = $pdo->query("
            SELECT w.id, w.name, c.name as company_name
            FROM warehouses w
            JOIN companies c ON w.company_id = c.id
            WHERE w.status = 1 AND c.status = 1
            ORDER BY c.name, w.name
        ");
    } else {
        $stmt = $pdo->prepare("
            SELECT w.id, w.name, c.name as company_name
            FROM warehouses w
            JOIN companies c ON w.company_id = c.id
            WHERE w.status = 1 AND c.status = 1 AND w.company_id = ?
            ORDER BY w.name
        ");
        $stmt->execute([$user['company_id']]);
    }
    $warehouses = $stmt->fetchAll();
    
    // Если выбран шаблон, получаем его атрибуты
    $templateAttributes = [];
    if ($formData['template_id'] > 0) {
        $stmt = $pdo->prepare("
            SELECT * FROM template_attributes 
            WHERE template_id = ? 
            ORDER BY sort_order, id
        ");
        $stmt->execute([$formData['template_id']]);
        $templateAttributes = $stmt->fetchAll();
    }
    
} catch (Exception $e) {
    logError('Product create page error: ' . $e->getMessage());
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
                    <a href="/pages/products/index.php">Товары</a>
                </li>
                <li class="breadcrumb-item active"><?= e($pageTitle) ?></li>
            </ol>
        </nav>
        <h1 class="h3 mb-0"><?= e($pageTitle) ?></h1>
    </div>
    <div>
        <a href="/pages/products/index.php" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left"></i>
            Назад к списку
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

<form method="POST" id="productForm">
    <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
    
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
                                    <?php if (empty($templates)): ?>
                                    <option value="" disabled>Нет доступных шаблонов</option>
                                    <?php else: ?>
                                    <?php foreach ($templates as $template): ?>
                                    <option value="<?= $template['id'] ?>" 
                                            <?= $formData['template_id'] == $template['id'] ? 'selected' : '' ?>
                                            data-description="<?= e($template['description']) ?>"
                                            data-formula="<?= e($template['formula']) ?>">
                                        <?= e($template['name']) ?>
                                    </option>
                                    <?php endforeach; ?>
                                    <?php endif; ?>
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
                                        <?= e($warehouse['name']) ?>
                                        <?php if ($user['role'] === ROLE_ADMIN): ?>
                                        (<?= e($warehouse['company_name']) ?>)
                                        <?php endif; ?>
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
            <div class="card mb-4" id="attributesCard" style="<?= empty($templateAttributes) ? 'display: none;' : '' ?>">
                <div class="card-header">
                    <h5 class="card-title mb-0">
                        <i class="bi bi-list-ul"></i>
                        Характеристики товара
                    </h5>
                </div>
                <div class="card-body" id="attributesContainer">
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
        </div>
        
        <div class="col-lg-4">
            <!-- Расчетный объем -->
            <div class="card mb-4" id="volumeCard" style="<?= empty($templateAttributes) ? 'display: none;' : '' ?>">
                <div class="card-header">
                    <h5 class="card-title mb-0">
                        <i class="bi bi-calculator"></i>
                        Расчетный объем
                    </h5>
                </div>
                <div class="card-body text-center">
                    <div id="volumeResult">
                        <div class="text-muted">
                            <i class="bi bi-calculator" style="font-size: 2rem;"></i>
                            <p class="mt-2">Заполните характеристики для расчета</p>
                        </div>
                    </div>
                    <div id="formulaDisplay" class="mt-3" style="display: none;">
                        <small class="text-muted">
                            <strong>Формула:</strong> <code id="formulaText"></code>
                        </small>
                    </div>
                </div>
            </div>
            
            <!-- Кнопки действий -->
            <div class="card">
                <div class="card-body">
                    <div class="d-grid gap-2">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-check-circle"></i>
                            Добавить товар
                        </button>
                        <a href="/pages/products/index.php" class="btn btn-outline-secondary">
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
    const volumeCard = document.getElementById('volumeCard');
    const container = document.getElementById('attributesContainer');
    
    if (!templateId) {
        attributesCard.style.display = 'none';
        volumeCard.style.display = 'none';
        return;
    }
    
    // Показываем информацию о шаблоне
    const selectedOption = document.querySelector('#template_id option:checked');
    const templateInfo = document.getElementById('templateInfo');
    const description = selectedOption.getAttribute('data-description');
    const formula = selectedOption.getAttribute('data-formula');
    
    if (description) {
        templateInfo.innerHTML = '<strong>Описание:</strong> ' + description;
        templateInfo.style.display = 'block';
    } else {
        templateInfo.style.display = 'none';
    }
    
    // Показываем формулу
    const formulaDisplay = document.getElementById('formulaDisplay');
    const formulaText = document.getElementById('formulaText');
    if (formula) {
        formulaText.textContent = formula;
        formulaDisplay.style.display = 'block';
    } else {
        formulaDisplay.style.display = 'none';
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
                            // Если options это JSON строка, парсим её
                            options = typeof attr.options === 'string' ? JSON.parse(attr.options) : attr.options;
                        } catch (e) {
                            // Если не JSON, разбиваем по запятым
                            options = attr.options.split(',').map(opt => opt.trim());
                        }
                        
                        inputHtml = `
                            <select class="form-select" id="attr_${attr.variable}" name="attributes[${attr.variable}]"
                                    ${attr.is_required ? 'required' : ''}
                                    ${attr.in_formula ? 'onchange="calculateVolume()"' : ''}>
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
                                   ${attr.in_formula ? 'oninput="calculateVolume()"' : ''}
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
                        ${attr.description ? '<div class="form-text">' + attr.description + '</div>' : ''}
                    `;
                    
                    container.appendChild(div);
                });
                
                attributesCard.style.display = 'block';
                volumeCard.style.display = 'block';
            } else {
                attributesCard.style.display = 'none';
                volumeCard.style.display = 'none';
            }
        })
        .catch(error => {
            console.error('Error loading attributes:', error);
            attributesCard.style.display = 'none';
            volumeCard.style.display = 'none';
        });
}

// Расчет объема
function calculateVolume() {
    const templateId = document.getElementById('template_id').value;
    if (!templateId) return;
    
    // Собираем значения атрибутов
    const attributes = {};
    document.querySelectorAll('[name^="attributes["]').forEach(input => {
        const match = input.name.match(/attributes\[(.+)\]/);
        if (match && input.value) {
            attributes[match[1]] = input.value;
        }
    });
    
    if (Object.keys(attributes).length === 0) {
        document.getElementById('volumeResult').innerHTML = `
            <div class="text-muted">
                <i class="bi bi-calculator" style="font-size: 2rem;"></i>
                <p class="mt-2">Заполните характеристики для расчета</p>
            </div>
        `;
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
                    <p class="mb-0">Расчетный объем</p>
                </div>
            `;
        } else {
            document.getElementById('volumeResult').innerHTML = `
                <div class="text-warning">
                    <i class="bi bi-exclamation-triangle" style="font-size: 2rem;"></i>
                    <p class="mt-2">Не удалось рассчитать объем</p>
                    ${data.message ? '<small class="text-muted">' + data.message + '</small>' : ''}
                </div>
            `;
        }
    })
    .catch(error => {
        console.error('Error calculating volume:', error);
        document.getElementById('volumeResult').innerHTML = `
            <div class="text-danger">
                <i class="bi bi-x-circle" style="font-size: 2rem;"></i>
                <p class="mt-2">Ошибка расчета</p>
            </div>
        `;
    });
}

// Инициализация при загрузке страницы
document.addEventListener('DOMContentLoaded', function() {
    if (document.getElementById('template_id').value) {
        loadTemplateAttributes();
    }
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>