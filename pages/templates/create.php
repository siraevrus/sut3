<?php
/**
 * Создание шаблона товара
 * Система складского учета (SUT)
 */

require_once __DIR__ . '/../../config/config.php';

requireAccess('product_templates');

$pageTitle = 'Создание шаблона товара';

$errors = [];
$formData = [
    'name' => '',
    'description' => '',
    'formula' => '',
    'status' => 1
];

// Обработка формы
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf_token = $_POST['csrf_token'] ?? '';
    
    if (!verifyCSRFToken($csrf_token)) {
        $errors['csrf'] = 'Ошибка безопасности';
    } else {
        // Получаем данные формы
        foreach ($formData as $field => $default) {
            if ($field === 'status') {
                $formData[$field] = (int)($_POST[$field] ?? $default);
            } else {
                $formData[$field] = trim($_POST[$field] ?? $default);
            }
        }
        
        // Получаем характеристики
        $attributes = $_POST['attributes'] ?? [];
        
        // Валидация основных полей
        if (empty($formData['name'])) {
            $errors['name'] = 'Название шаблона обязательно для заполнения';
        } elseif (strlen($formData['name']) < 3) {
            $errors['name'] = 'Название должно содержать минимум 3 символа';
        }
        
        if (empty($formData['description'])) {
            $errors['description'] = 'Описание обязательно для заполнения';
        }
        
        // Валидация характеристик
        $validatedAttributes = [];
        if (!empty($attributes)) {
            foreach ($attributes as $index => $attr) {
                $attrErrors = [];
                
                // Название характеристики
                if (empty($attr['name'])) {
                    $attrErrors[] = 'Название характеристики обязательно';
                }
                
                // Переменная (обязательна, только если характеристика участвует в формуле)
                if (!empty($attr['use_in_formula'])) {
                    if (empty($attr['variable'])) {
                        $attrErrors[] = 'Переменная обязательна, если характеристика участвует в формуле';
                    }
                }
                if (!empty($attr['variable']) && !preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $attr['variable'])) {
                    $attrErrors[] = 'Переменная должна содержать только английские буквы, цифры и _';
                }
                
                // Тип данных
                if (!in_array($attr['data_type'], ['number', 'text', 'select'])) {
                    $attrErrors[] = 'Неверный тип данных';
                }
                
                // Для select - проверяем варианты (каждый вариант с новой строки)
                if ($attr['data_type'] === 'select') {
                    $rawOptions = trim($attr['options'] ?? '');
                    // Разбиваем по переносу строки
                    $optionsArr = array_filter(array_map('trim', preg_split('/\r?\n/', $rawOptions)));
                    if (empty($optionsArr)) {
                        $attrErrors[] = 'Для выпадающего списка нужно указать варианты (каждый с новой строки)';
                    }
                }
                
                if (!empty($attrErrors)) {
                    $errors['attributes'][$index] = $attrErrors;
                } else {
                    $validatedAttributes[] = [
                        'name' => trim($attr['name']),
                        'variable' => trim($attr['variable']),
                        'data_type' => $attr['data_type'],
                        'options' => $attr['data_type'] === 'select' ? $optionsArr : null,
                        'unit' => trim($attr['unit'] ?? ''),
                        'is_required' => !empty($attr['is_required']),
                        'use_in_formula' => !empty($attr['use_in_formula']),
                        'sort_order' => $index
                    ];
                }
            }
        }
        
        // Проверяем уникальность переменных
        $variables = array_filter(array_column($validatedAttributes, 'variable'));
        if (count($variables) !== count(array_unique($variables))) {
            $errors['attributes_general'] = 'Переменные должны быть уникальными';
        }
        
        // Валидация формулы (если указана)
        if (!empty($formData['formula'])) {
            $formulaValidation = validateFormula($formData['formula'], $variables);
            if (!$formulaValidation['valid']) {
                $errors['formula'] = $formulaValidation['message'];
            }
        }
        
        if (empty($errors)) {
            // Логируем успешную валидацию перед записью
            logError('Template validation passed', [
                'formData' => $formData,
                'attributes' => $validatedAttributes
            ]);
            try {
                $pdo = getDBConnection();
                $pdo->beginTransaction();
                
                // Проверяем уникальность названия
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM product_templates WHERE name = ?");
                $stmt->execute([$formData['name']]);
                if ($stmt->fetchColumn() > 0) {
                    $errors['name'] = 'Шаблон с таким названием уже существует';
                } else {
                    // Создаем шаблон
                    $stmt = $pdo->prepare("
                        INSERT INTO product_templates (name, description, formula, status, created_by) 
                        VALUES (?, ?, ?, ?, ?)
                    ");
                    
                    $stmt->execute([
                        $formData['name'],
                        $formData['description'],
                        $formData['formula'] ?: null,
                        $formData['status'],
                        getCurrentUser()['id']
                    ]);
                    
                    $templateId = $pdo->lastInsertId();
                    
                    // Добавляем характеристики
                    if (!empty($validatedAttributes)) {
                        $stmt = $pdo->prepare("
                            INSERT INTO template_attributes 
                            (template_id, name, variable, data_type, options, unit, is_required, use_in_formula, sort_order) 
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                        ");
                        
                        foreach ($validatedAttributes as $attr) {
                            $stmt->execute([
                                $templateId,
                                $attr['name'],
                                $attr['variable'],
                                $attr['data_type'],
                                $attr['options'] ? json_encode($attr['options']) : null,
                                $attr['unit'],
                                $attr['is_required'],
                                $attr['use_in_formula'],
                                $attr['sort_order']
                            ]);
                        }
                    }
                    
                    $pdo->commit();
                    
                    // Логируем создание
                    logError('Template created', [
                        'template_id' => $templateId,
                        'template_name' => $formData['name'],
                        'attributes_count' => count($validatedAttributes),
                        'created_by' => getCurrentUser()['id']
                    ]);
                    
                    $_SESSION['success_message'] = 'Шаблон "' . $formData['name'] . '" успешно создан с ' . count($validatedAttributes) . ' характеристиками';
                    header('Location: /pages/templates/view.php?id=' . $templateId);
                    exit;
                }
                
            } catch (Exception $e) {
                $pdo->rollBack();
                logError('Create template error: ' . $e->getMessage(), $formData);
                $errors['general'] = 'Произошла ошибка при создании шаблона';
            }
        }

        // Логируем ошибки валидации, если они есть
        if (!empty($errors)) {
            logError('Template create validation errors', [
                'post' => $_POST,
                'errors' => $errors
            ]);
        }
    }
}

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item">
                    <a href="/pages/templates/index.php">Характеристики товара</a>
                </li>
                <li class="breadcrumb-item active"><?= e($pageTitle) ?></li>
            </ol>
        </nav>
        <h1 class="h3 mb-0"><?= e($pageTitle) ?></h1>
    </div>
    <div>
        <a href="/pages/templates/index.php" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left"></i>
            Назад к списку
        </a>
    </div>
</div>

<?php if (!empty($errors['general'])): ?>
<div class="alert alert-danger">
    <i class="bi bi-exclamation-triangle"></i>
    <?= e($errors['general']) ?>
</div>
<?php endif; ?>

<?php if (!empty($errors['attributes_general'])): ?>
<div class="alert alert-danger">
    <i class="bi bi-exclamation-triangle"></i>
    <?= e($errors['attributes_general']) ?>
</div>
<?php endif; ?>

<form method="POST" action="" novalidate id="templateForm">
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
                    <!-- Название -->
                    <div class="mb-3">
                        <label for="name" class="form-label">
                            Название шаблона <span class="text-danger">*</span>
                        </label>
                        <input type="text" 
                               class="form-control <?= isset($errors['name']) ? 'is-invalid' : '' ?>" 
                               id="name" 
                               name="name" 
                               value="<?= e($formData['name']) ?>"
                               required
                               maxlength="100"
                               placeholder="Например: Строительные блоки">
                        <?php if (isset($errors['name'])): ?>
                        <div class="invalid-feedback"><?= e($errors['name']) ?></div>
                        <?php endif; ?>
                        <div class="form-text">Краткое название для идентификации шаблона</div>
                    </div>
                    
                    <!-- Описание -->
                    <div class="mb-3">
                        <label for="description" class="form-label">
                            Описание <span class="text-danger">*</span>
                        </label>
                        <textarea class="form-control <?= isset($errors['description']) ? 'is-invalid' : '' ?>" 
                                  id="description" 
                                  name="description" 
                                  rows="3"
                                  required
                                  placeholder="Подробное описание шаблона и его назначения"><?= e($formData['description']) ?></textarea>
                        <?php if (isset($errors['description'])): ?>
                        <div class="invalid-feedback"><?= e($errors['description']) ?></div>
                        <?php endif; ?>
                        <div class="form-text">Подробное описание поможет пользователям понять назначение шаблона</div>
                    </div>
                    
                    <!-- Статус -->
                    <div class="mb-3">
                        <label for="status" class="form-label">Статус</label>
                        <select class="form-select" id="status" name="status">
                            <option value="1" <?= $formData['status'] == 1 ? 'selected' : '' ?>>Активен</option>
                            <option value="0" <?= $formData['status'] == 0 ? 'selected' : '' ?>>Неактивен</option>
                        </select>
                        <div class="form-text">Неактивные шаблоны не отображаются при создании товаров</div>
                    </div>
                </div>
            </div>
            
            <!-- Блок характеристик -->
            <div class="card mb-4">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="card-title mb-0">
                        <i class="bi bi-list-ul"></i>
                        Характеристики
                    </h5>
                    <button type="button" class="btn btn-success btn-sm" id="addAttributeBtn">
                        <i class="bi bi-plus-circle"></i>
                        Добавить характеристику
                    </button>
                </div>
                <div class="card-body">
                    <div id="attributesContainer">
                        <div class="empty-attributes text-center py-4" id="emptyAttributesMessage">
                            <i class="bi bi-list-ul text-muted" style="font-size: 2rem;"></i>
                            <p class="text-muted mb-0">Характеристики не добавлены</p>
                            <small class="text-muted">Нажмите "Добавить характеристику" для создания полей</small>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Формула -->
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="card-title mb-0">
                        <i class="bi bi-calculator"></i>
                        Формула расчета объема
                        <span class="text-muted">(необязательно)</span>
                    </h5>
                </div>
                <div class="card-body">
                    <div class="input-group">
                        <input type="text" 
                               class="form-control <?= isset($errors['formula']) ? 'is-invalid' : '' ?>" 
                               id="formula" 
                               name="formula" 
                               value="<?= e($formData['formula']) ?>"
                               placeholder="Например: length * width * height">
                        <button type="button" class="btn btn-outline-secondary" id="testFormulaBtn">
                            <i class="bi bi-play-circle"></i>
                            Тестировать
                        </button>
                    </div>
                    <?php if (isset($errors['formula'])): ?>
                    <div class="invalid-feedback d-block"><?= e($errors['formula']) ?></div>
                    <?php endif; ?>
                    <div class="form-text">
                        Используйте переменные из характеристик и операторы: +, -, *, /, (, )<br>
                        <span id="availableVariables" class="text-primary"></span>
                    </div>
                    
                    <!-- Результат тестирования формулы -->
                    <div id="formulaTestResult" class="mt-2" style="display: none;"></div>
                </div>
            </div>
            
            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-check-lg"></i>
                    Создать шаблон
                </button>
                <a href="/pages/templates/index.php" class="btn btn-outline-secondary">
                    <i class="bi bi-x-circle"></i>
                    Отменить
                </a>
            </div>
        </div>
    
    <div class="col-lg-4">
        <div class="card">
            <div class="card-header">
                <h6 class="card-title mb-0">
                    <i class="bi bi-info-circle"></i>
                    Справка по характеристикам
                </h6>
            </div>
            <div class="card-body">
                <h6>Типы данных</h6>
                <ul class="list-unstyled small">
                    <li><strong>Число</strong> - для численных значений (длина, вес, количество)</li>
                    <li><strong>Текст</strong> - для текстовых данных (цвет, материал)</li>
                    <li><strong>Выпадающий список</strong> - для выбора из предустановленных вариантов</li>
                </ul>
                
                <h6>Переменные</h6>
                <p class="small text-muted">
                    Используйте английские названия без пробелов (length, width, height).
                    Переменные нужны для формул расчета.
                </p>
                
                <h6>Единицы измерения</h6>
                <div class="d-flex flex-wrap gap-1">
                    <span class="badge bg-light text-dark">мм</span>
                    <span class="badge bg-light text-dark">см</span>
                    <span class="badge bg-light text-dark">м</span>
                    <span class="badge bg-light text-dark">м²</span>
                    <span class="badge bg-light text-dark">м³</span>
                    <span class="badge bg-light text-dark">кг</span>
                    <span class="badge bg-light text-dark">г</span>
                    <span class="badge bg-light text-dark">шт</span>
                </div>
            </div>
        </div>
        
        <div class="card mt-3">
            <div class="card-header">
                <h6 class="card-title mb-0">
                    <i class="bi bi-lightbulb"></i>
                    Пример характеристик
                </h6>
            </div>
            <div class="card-body">
                <div class="example-attribute mb-2">
                    <strong>Длина</strong> (length)<br>
                    <small class="text-muted">Тип: Число, Единица: см, Обязательно, В формуле</small>
                </div>
                <div class="example-attribute mb-2">
                    <strong>Ширина</strong> (width)<br>
                    <small class="text-muted">Тип: Число, Единица: см, Обязательно, В формуле</small>
                </div>
                <div class="example-attribute mb-2">
                    <strong>Материал</strong> (material)<br>
                    <small class="text-muted">Тип: Список, Варианты: Дерево,Металл,Пластик</small>
                </div>
            </div>
        </div>
    </div>
</div>
</form>

<!-- Шаблон характеристики -->
<template id="attributeTemplate">
    <div class="attribute-item border rounded p-3 mb-3" data-index="">
        <div class="d-flex justify-content-between align-items-start mb-3">
            <h6 class="mb-0">Характеристика <span class="attribute-number"></span></h6>
            <button type="button" class="btn btn-outline-danger btn-sm remove-attribute">
                <i class="bi bi-trash"></i>
            </button>
        </div>
        
        <div class="row">
            <!-- Название характеристики -->
            <div class="col-md-6 mb-3">
                <label class="form-label">Название характеристики <span class="text-danger">*</span></label>
                <input type="text" class="form-control attribute-name" name="attributes[][name]" 
                       placeholder="Например: Длина" required>
                <div class="invalid-feedback"></div>
            </div>
            
            <!-- Переменная -->
            <div class="col-md-6 mb-3">
                <label class="form-label">Переменная <span class="text-danger">*</span></label>
                <input type="text" class="form-control attribute-variable" name="attributes[][variable]" 
                       placeholder="Например: length" required>
                <div class="invalid-feedback"></div>
                <div class="form-text">Только английские буквы, цифры и _</div>
            </div>
            
            <!-- Тип данных -->
            <div class="col-md-4 mb-3">
                <label class="form-label">Тип данных <span class="text-danger">*</span></label>
                <select class="form-select attribute-type" name="attributes[][data_type]" required>
                    <option value="">Выберите тип</option>
                    <option value="number">Число</option>
                    <option value="text">Текст</option>
                    <option value="select">Выпадающий список</option>
                </select>
                <div class="invalid-feedback"></div>
            </div>
            
            <!-- Варианты для списка -->
            <div class="col-md-4 mb-3 options-field" style="display: none;">
                <label class="form-label">Варианты (каждый с новой строки)</label>
                <textarea class="form-control attribute-options" name="attributes[][options]" rows="3"
                          placeholder="Вариант 1\nВариант 2\nВариант 3"></textarea>
                <div class="invalid-feedback"></div>
                <div class="form-text">Введите каждый вариант с новой строки</div>
            </div>
            
            <!-- Единица измерения -->
            <div class="col-md-4 mb-3">
                <label class="form-label">Единица измерения</label>
                <select class="form-select attribute-unit" name="attributes[][unit]">
                    <option value="">Без единицы</option>
                    <option value="мм">мм</option>
                    <option value="см">см</option>
                    <option value="м">м</option>
                    <option value="м²">м²</option>
                    <option value="м³">м³</option>
                    <option value="кг">кг</option>
                    <option value="г">г</option>
                    <option value="шт">шт</option>
                    <option value="л">л</option>
                    <option value="мл">мл</option>
                </select>
            </div>
            
            <!-- Чекбоксы -->
            <div class="col-12">
                <div class="row">
                    <div class="col-md-6">
                        <div class="form-check">
                            <input class="form-check-input attribute-required" type="checkbox" 
                                   name="attributes[][is_required]" value="1">
                            <label class="form-check-label">Обязательно к заполнению</label>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-check">
                            <input class="form-check-input attribute-formula" type="checkbox" 
                                   name="attributes[][use_in_formula]" value="1">
                            <label class="form-check-label">Учитывать в формуле</label>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>

<!-- Модальное окно тестирования формулы -->
<div class="modal fade" id="formulaTestModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Тестирование формулы</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label">Формула:</label>
                    <code id="testFormulaDisplay" class="d-block p-2 bg-light rounded"></code>
                </div>
                
                <div id="testVariablesContainer">
                    <!-- Здесь будут поля для ввода значений переменных -->
                </div>
                
                <div class="mt-3">
                    <button type="button" class="btn btn-primary" id="calculateTestBtn">
                        <i class="bi bi-calculator"></i>
                        Вычислить
                    </button>
                </div>
                
                <div id="testResult" class="mt-3" style="display: none;"></div>
            </div>
        </div>
    </div>
</div>

<style>
.attribute-item {
    background: #f8f9fa;
    transition: all 0.3s ease;
}

.attribute-item:hover {
    background: #e9ecef;
}

.attribute-number {
    color: var(--primary-color);
    font-weight: 600;
}

.empty-attributes {
    border: 2px dashed #dee2e6;
    border-radius: 0.5rem;
}

.options-field.show {
    display: block !important;
}

.form-check-input:checked + .form-check-label {
    color: var(--primary-color);
    font-weight: 500;
}
</style>

<script>
let attributeIndex = 0;

// Добавление новой характеристики
document.getElementById('addAttributeBtn').addEventListener('click', function() {
    addAttribute();
});

// Функция добавления характеристики
function addAttribute(data = {}) {
    const template = document.getElementById('attributeTemplate');
    const clone = template.content.cloneNode(true);
    
    // Устанавливаем индекс
    const attributeItem = clone.querySelector('.attribute-item');
    attributeItem.setAttribute('data-index', attributeIndex);
    
    // Обновляем номер
    clone.querySelector('.attribute-number').textContent = attributeIndex + 1;
    
    // Устанавливаем имена полей с правильными индексами
    const inputs = clone.querySelectorAll('input, select');
    inputs.forEach(input => {
        if (input.name) {
            input.name = input.name.replace('[]', `[${attributeIndex}]`);
        }
    });
    
    // Заполняем данными если есть
    if (data.name) clone.querySelector('.attribute-name').value = data.name;
    if (data.variable) clone.querySelector('.attribute-variable').value = data.variable;
    if (data.data_type) clone.querySelector('.attribute-type').value = data.data_type;
    if (data.options) clone.querySelector('.attribute-options').value = data.options;
    if (data.unit) clone.querySelector('.attribute-unit').value = data.unit;
    if (data.is_required) clone.querySelector('.attribute-required').checked = true;
    if (data.use_in_formula) clone.querySelector('.attribute-formula').checked = true;
    
    // Добавляем обработчики событий
    const removeBtn = clone.querySelector('.remove-attribute');
    removeBtn.addEventListener('click', function() {
        removeAttribute(attributeIndex);
    });
    
    const typeSelect = clone.querySelector('.attribute-type');
    const optionsField = clone.querySelector('.options-field');
    
    typeSelect.addEventListener('change', function() {
        if (this.value === 'select') {
            optionsField.style.display = 'block';
            optionsField.classList.add('show');
            clone.querySelector('.attribute-options').required = true;
        } else {
            optionsField.style.display = 'none';
            optionsField.classList.remove('show');
            clone.querySelector('.attribute-options').required = false;
        }
    });
    
    // Показываем поле опций если тип уже select
    if (data.data_type === 'select') {
        optionsField.style.display = 'block';
        optionsField.classList.add('show');
        clone.querySelector('.attribute-options').required = true;
    }
    
    // Автогенерация переменной из названия
    const nameInput = clone.querySelector('.attribute-name');
    const variableInput = clone.querySelector('.attribute-variable');
    
    nameInput.addEventListener('input', function() {
        if (!variableInput.value || variableInput.dataset.autoGenerated === 'true') {
            const variable = this.value
                .toLowerCase()
                .replace(/[^a-zA-Z0-9]/g, '_')
                .replace(/_{2,}/g, '_')
                .replace(/^_|_$/g, '');
            variableInput.value = variable;
            variableInput.dataset.autoGenerated = 'true';
        }
    });
    
    variableInput.addEventListener('input', function() {
        this.dataset.autoGenerated = 'false';
        updateAvailableVariables();
    });
    
    // Обновление списка переменных при изменении checkbox формулы
    clone.querySelector('.attribute-formula').addEventListener('change', updateAvailableVariables);
    
    // Добавляем в контейнер
    document.getElementById('attributesContainer').appendChild(clone);
    
    // Скрываем сообщение о пустом списке
    document.getElementById('emptyAttributesMessage').style.display = 'none';
    
    attributeIndex++;
    updateAvailableVariables();
}

// Удаление характеристики
function removeAttribute(index) {
    const item = document.querySelector(`[data-index="${index}"]`);
    if (item) {
        item.remove();
        updateAttributeNumbers();
        updateAvailableVariables();
        
        // Показываем сообщение если характеристик нет
        const container = document.getElementById('attributesContainer');
        if (container.children.length === 1) { // только пустое сообщение
            document.getElementById('emptyAttributesMessage').style.display = 'block';
        }
    }
}

// Обновление номеров характеристик
function updateAttributeNumbers() {
    const items = document.querySelectorAll('.attribute-item');
    items.forEach((item, index) => {
        item.querySelector('.attribute-number').textContent = index + 1;
    });
}

// Обновление списка доступных переменных
function updateAvailableVariables() {
    const variables = [];
    const formulaCheckboxes = document.querySelectorAll('.attribute-formula:checked');
    
    formulaCheckboxes.forEach(checkbox => {
        const attributeItem = checkbox.closest('.attribute-item');
        const variableInput = attributeItem.querySelector('.attribute-variable');
        const variable = variableInput.value.trim();
        if (variable) {
            variables.push(variable);
        }
    });
    
    const availableVarsElement = document.getElementById('availableVariables');
    if (variables.length > 0) {
        availableVarsElement.textContent = 'Доступные переменные: ' + variables.join(', ');
    } else {
        availableVarsElement.textContent = 'Отметьте характеристики для использования в формуле';
    }
}

// Валидация формулы в реальном времени
document.getElementById('formula').addEventListener('input', function() {
    const formula = this.value.trim();
    const resultDiv = document.getElementById('formulaTestResult');
    
    if (!formula) {
        resultDiv.style.display = 'none';
        this.classList.remove('is-invalid', 'is-valid');
        return;
    }
    
    // Получаем доступные переменные
    const variables = [];
    const formulaCheckboxes = document.querySelectorAll('.attribute-formula:checked');
    formulaCheckboxes.forEach(checkbox => {
        const attributeItem = checkbox.closest('.attribute-item');
        const variableInput = attributeItem.querySelector('.attribute-variable');
        const variable = variableInput.value.trim();
        if (variable) variables.push(variable);
    });
    
    // Простая валидация на клиенте
    const validation = validateFormulaClient(formula, variables);
    
    resultDiv.style.display = 'block';
    if (validation.valid) {
        resultDiv.innerHTML = '<div class="alert alert-success alert-sm"><i class="bi bi-check-circle"></i> Формула корректна</div>';
        this.classList.remove('is-invalid');
        this.classList.add('is-valid');
    } else {
        resultDiv.innerHTML = '<div class="alert alert-danger alert-sm"><i class="bi bi-exclamation-triangle"></i> ' + validation.error + '</div>';
        this.classList.remove('is-valid');
        this.classList.add('is-invalid');
    }
});

// Клиентская валидация формулы
function validateFormulaClient(formula, availableVariables = []) {
    // Проверяем на разрешенные символы
    if (!/^[a-zA-Z0-9_+\-*\/().\s]+$/.test(formula)) {
        return {
            valid: false,
            error: 'Недопустимые символы в формуле'
        };
    }
    
    // Проверяем баланс скобок
    const openBrackets = (formula.match(/\(/g) || []).length;
    const closeBrackets = (formula.match(/\)/g) || []).length;
    if (openBrackets !== closeBrackets) {
        return {
            valid: false,
            error: 'Несбалансированные скобки'
        };
    }
    
    // Проверяем на пустые скобки
    if (formula.includes('()')) {
        return {
            valid: false,
            error: 'Пустые скобки не разрешены'
        };
    }
    
    // Проверяем переменные
    if (availableVariables.length > 0) {
        const formulaVariables = [];
        const matches = formula.match(/[a-zA-Z_][a-zA-Z0-9_]*/g);
        if (matches) {
            const uniqueVars = [...new Set(matches)];
            const undefinedVars = uniqueVars.filter(v => !availableVariables.includes(v));
            if (undefinedVars.length > 0) {
                return {
                    valid: false,
                    error: 'Неопределенные переменные: ' + undefinedVars.join(', ')
                };
            }
        }
    }
    
    return { valid: true };
}

// Тестирование формулы
document.getElementById('testFormulaBtn').addEventListener('click', function() {
    const formula = document.getElementById('formula').value.trim();
    
    if (!formula) {
        alert('Введите формулу для тестирования');
        return;
    }
    
    // Получаем переменные из характеристик
    const variables = [];
    const formulaCheckboxes = document.querySelectorAll('.attribute-formula:checked');
    formulaCheckboxes.forEach(checkbox => {
        const attributeItem = checkbox.closest('.attribute-item');
        const variableInput = attributeItem.querySelector('.attribute-variable');
        const nameInput = attributeItem.querySelector('.attribute-name');
        const variable = variableInput.value.trim();
        const name = nameInput.value.trim();
        if (variable && name) {
            variables.push({ variable, name });
        }
    });
    
    if (variables.length === 0) {
        alert('Добавьте характеристики и отметьте их для использования в формуле');
        return;
    }
    
    const validation = validateFormulaClient(formula, variables.map(v => v.variable));
    if (!validation.valid) {
        alert('Формула содержит ошибки: ' + validation.error);
        return;
    }
    
    // Показываем модальное окно
    showFormulaTestModal(formula, variables);
});

// Показ модального окна тестирования
function showFormulaTestModal(formula, variables) {
    document.getElementById('testFormulaDisplay').textContent = formula;
    
    const container = document.getElementById('testVariablesContainer');
    container.innerHTML = '';
    
    variables.forEach(({ variable, name }) => {
        const div = document.createElement('div');
        div.className = 'mb-3';
        div.innerHTML = `
            <label class="form-label">${name} (${variable}):</label>
            <input type="number" class="form-control test-variable" 
                   data-variable="${variable}" 
                   step="0.01" 
                   placeholder="Введите значение">
        `;
        container.appendChild(div);
    });
    
    const modalElement = document.getElementById('formulaTestModal');
    const modal = new bootstrap.Modal(modalElement);
    
    // Очищаем результат при открытии
    document.getElementById('testResult').style.display = 'none';
    
    // Добавляем обработчик закрытия модального окна
    modalElement.addEventListener('hidden.bs.modal', function () {
        // Принудительно удаляем backdrop если он остался
        const backdrop = document.querySelector('.modal-backdrop');
        if (backdrop) {
            backdrop.remove();
        }
        
        // Убираем класс modal-open с body
        document.body.classList.remove('modal-open');
        document.body.style.removeProperty('padding-right');
        
        // Очищаем поля ввода
        const inputs = modalElement.querySelectorAll('.test-variable');
        inputs.forEach(input => {
            input.value = '';
            input.classList.remove('is-invalid');
        });
        
        // Скрываем результат
        document.getElementById('testResult').style.display = 'none';
    }, { once: true }); // once: true означает что обработчик сработает только один раз
    
    modal.show();
}

// Вычисление тестовой формулы
document.getElementById('calculateTestBtn').addEventListener('click', function() {
    const formula = document.getElementById('testFormulaDisplay').textContent;
    const variableInputs = document.querySelectorAll('.test-variable');
    const variables = {};
    
    // Собираем значения переменных
    let hasEmptyValues = false;
    variableInputs.forEach(input => {
        const value = parseFloat(input.value);
        if (isNaN(value)) {
            hasEmptyValues = true;
            input.classList.add('is-invalid');
        } else {
            input.classList.remove('is-invalid');
            variables[input.dataset.variable] = value;
        }
    });
    
    if (hasEmptyValues) {
        document.getElementById('testResult').innerHTML = 
            '<div class="alert alert-warning"><i class="bi bi-exclamation-triangle"></i> Заполните все поля</div>';
        document.getElementById('testResult').style.display = 'block';
        return;
    }
    
    // Вычисляем формулу
    try {
        const result = calculateFormula(formula, variables);
        document.getElementById('testResult').innerHTML = 
            '<div class="alert alert-success"><i class="bi bi-check-circle"></i> Результат: <strong>' + result + '</strong></div>';
    } catch (error) {
        document.getElementById('testResult').innerHTML = 
            '<div class="alert alert-danger"><i class="bi bi-x-circle"></i> Ошибка: ' + error.message + '</div>';
    }
    
    document.getElementById('testResult').style.display = 'block';
});

// Вычисление формулы (простая реализация)
function calculateFormula(formula, variables) {
    let expression = formula;
    
    // Заменяем переменные на значения
    for (const [variable, value] of Object.entries(variables)) {
        const regex = new RegExp('\\b' + variable + '\\b', 'g');
        expression = expression.replace(regex, value);
    }
    
    // Проверяем безопасность
    if (!/^[0-9+\-*/.() ]+$/.test(expression)) {
        throw new Error('Недопустимые символы после подстановки');
    }
    
    // Вычисляем
    const result = Function('"use strict"; return (' + expression + ')')();
    
    if (!isFinite(result)) {
        throw new Error('Результат не является конечным числом');
    }
    
    return Math.round(result * 100) / 100; // Округляем до 2 знаков
}

// Валидация формы перед отправкой
document.getElementById('templateForm').addEventListener('submit', function(e) {
    let hasErrors = false;
    
    // Проверяем характеристики
    const attributeItems = document.querySelectorAll('.attribute-item');
    attributeItems.forEach((item, index) => {
        const nameInput = item.querySelector('.attribute-name');
        const variableInput = item.querySelector('.attribute-variable');
        const typeSelect = item.querySelector('.attribute-type');
        const optionsInput = item.querySelector('.attribute-options');
        
        // Сбрасываем предыдущие ошибки
        [nameInput, variableInput, typeSelect, optionsInput].forEach(el => {
            if (el) {
                el.classList.remove('is-invalid');
                const feedback = el.parentNode.querySelector('.invalid-feedback');
                if (feedback) feedback.textContent = '';
            }
        });
        
        // Проверяем название
        if (!nameInput.value.trim()) {
            nameInput.classList.add('is-invalid');
            nameInput.parentNode.querySelector('.invalid-feedback').textContent = 'Укажите название характеристики';
            hasErrors = true;
        }
        
        // Проверяем переменную
        if (!variableInput.value.trim()) {
            variableInput.classList.add('is-invalid');
            variableInput.parentNode.querySelector('.invalid-feedback').textContent = 'Укажите переменную';
            hasErrors = true;
        } else if (!/^[a-zA-Z_][a-zA-Z0-9_]*$/.test(variableInput.value.trim())) {
            variableInput.classList.add('is-invalid');
            variableInput.parentNode.querySelector('.invalid-feedback').textContent = 'Неверный формат переменной';
            hasErrors = true;
        }
        
        // Проверяем тип
        if (!typeSelect.value) {
            typeSelect.classList.add('is-invalid');
            typeSelect.parentNode.querySelector('.invalid-feedback').textContent = 'Выберите тип данных';
            hasErrors = true;
        }
        
        // Проверяем варианты для списка
        if (typeSelect.value === 'select' && !optionsInput.value.trim()) {
            optionsInput.classList.add('is-invalid');
            optionsInput.parentNode.querySelector('.invalid-feedback').textContent = 'Укажите варианты для списка';
            hasErrors = true;
        }
    });
    
    if (hasErrors) {
        e.preventDefault();
        alert('Исправьте ошибки в характеристиках перед сохранением');
    }
});

// Инициализация при загрузке страницы
document.addEventListener('DOMContentLoaded', function() {
    updateAvailableVariables();
    
    // Дополнительная защита от зависших backdrop'ов
    document.addEventListener('click', function(e) {
        if (e.target.classList.contains('modal-backdrop')) {
            // Если кликнули на backdrop, принудительно закрываем все модальные окна
            const modals = document.querySelectorAll('.modal.show');
            modals.forEach(modal => {
                const bsModal = bootstrap.Modal.getInstance(modal);
                if (bsModal) {
                    bsModal.hide();
                }
            });
            
            // Удаляем все backdrop'ы
            const backdrops = document.querySelectorAll('.modal-backdrop');
            backdrops.forEach(backdrop => backdrop.remove());
            
            // Очищаем body
            document.body.classList.remove('modal-open');
            document.body.style.removeProperty('padding-right');
        }
    });
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
                    <i class="bi bi-info-circle"></i>
                    Справка по формулам
                </h6>
            </div>
            <div class="card-body">
                <h6>Переменные</h6>
                <p class="small text-muted">
                    Используйте английские названия для переменных в формуле. 
                    Они должны точно соответствовать названиям характеристик, которые вы добавите позже.
                </p>
                
                <h6>Операторы</h6>
                <ul class="list-unstyled small">
                    <li><code>+</code> - сложение</li>
                    <li><code>-</code> - вычитание</li>
                    <li><code>*</code> - умножение</li>
                    <li><code>/</code> - деление</li>
                    <li><code>( )</code> - скобки для группировки</li>
                </ul>
                
                <h6>Примеры формул</h6>
                <div class="examples">
                    <div class="example-item mb-2">
                        <code>length * width * height</code>
                        <small class="text-muted d-block">Объем прямоугольного блока</small>
                    </div>
                    <div class="example-item mb-2">
                        <code>3.14159 * radius * radius * height</code>
                        <small class="text-muted d-block">Объем цилиндра</small>
                    </div>
                    <div class="example-item mb-2">
                        <code>(length + width) * 2 * thickness</code>
                        <small class="text-muted d-block">Сложная формула</small>
                    </div>
                </div>
                
                <hr>
                
                <h6>Тестирование</h6>
                <p class="small text-muted">
                    Используйте кнопку "Тестировать" для проверки корректности формулы. 
                    Вы сможете ввести тестовые значения для переменных.
                </p>
            </div>
        </div>
        
        <div class="card mt-3">
            <div class="card-header">
                <h6 class="card-title mb-0">
                    <i class="bi bi-lightbulb"></i>
                    Следующие шаги
                </h6>
            </div>
            <div class="card-body">
                <p class="small text-muted">
                    После создания шаблона вы сможете:
                </p>
                <ul class="list-unstyled small">
                    <li class="mb-1">
                        <i class="bi bi-1-circle text-primary"></i>
                        Добавить характеристики (поля)
                    </li>
                    <li class="mb-1">
                        <i class="bi bi-2-circle text-primary"></i>
                        Настроить типы данных
                    </li>
                    <li class="mb-1">
                        <i class="bi bi-3-circle text-primary"></i>
                        Протестировать формулу с реальными данными
                    </li>
                    <li class="mb-1">
                        <i class="bi bi-4-circle text-primary"></i>
                        Создавать товары по шаблону
                    </li>
                </ul>
            </div>
        </div>
    </div>
</div>

<!-- Модальное окно тестирования формулы -->
<div class="modal fade" id="formulaTestModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Тестирование формулы</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label">Формула:</label>
                    <code id="testFormulaDisplay" class="d-block p-2 bg-light rounded"></code>
                </div>
                
                <div id="testVariablesContainer">
                    <!-- Здесь будут поля для ввода значений переменных -->
                </div>
                
                <div class="mt-3">
                    <button type="button" class="btn btn-primary" id="calculateTestBtn">
                        <i class="bi bi-calculator"></i>
                        Вычислить
                    </button>
                </div>
                
                <div id="testResult" class="mt-3" style="display: none;"></div>
            </div>
        </div>
    </div>
</div>

<script>
// Валидация формулы в реальном времени
document.getElementById('formula').addEventListener('input', function() {
    const formula = this.value.trim();
    const resultDiv = document.getElementById('formulaTestResult');
    
    if (!formula) {
        resultDiv.style.display = 'none';
        this.classList.remove('is-invalid', 'is-valid');
        return;
    }
    
    // Простая валидация на клиенте
    const validation = validateFormulaClient(formula);
    
    resultDiv.style.display = 'block';
    if (validation.valid) {
        resultDiv.innerHTML = '<div class="alert alert-success alert-sm"><i class="bi bi-check-circle"></i> Формула корректна</div>';
        this.classList.remove('is-invalid');
        this.classList.add('is-valid');
    } else {
        resultDiv.innerHTML = '<div class="alert alert-danger alert-sm"><i class="bi bi-exclamation-triangle"></i> ' + validation.error + '</div>';
        this.classList.remove('is-valid');
        this.classList.add('is-invalid');
    }
});

// Клиентская валидация формулы
function validateFormulaClient(formula) {
    // Проверяем на разрешенные символы
    if (!/^[a-zA-Z0-9_+\-*\/().\s]+$/.test(formula)) {
        return {
            valid: false,
            error: 'Недопустимые символы в формуле'
        };
    }
    
    // Проверяем баланс скобок
    const openBrackets = (formula.match(/\(/g) || []).length;
    const closeBrackets = (formula.match(/\)/g) || []).length;
    if (openBrackets !== closeBrackets) {
        return {
            valid: false,
            error: 'Несбалансированные скобки'
        };
    }
    
    // Проверяем на пустые скобки
    if (formula.includes('()')) {
        return {
            valid: false,
            error: 'Пустые скобки не разрешены'
        };
    }
    
    return { valid: true };
}

// Тестирование формулы
document.getElementById('testFormulaBtn').addEventListener('click', function() {
    const formula = document.getElementById('formula').value.trim();
    
    if (!formula) {
        alert('Введите формулу для тестирования');
        return;
    }
    
    const validation = validateFormulaClient(formula);
    if (!validation.valid) {
        alert('Формула содержит ошибки: ' + validation.error);
        return;
    }
    
    // Извлекаем переменные из формулы
    const variables = extractVariables(formula);
    
    if (variables.length === 0) {
        alert('В формуле не найдены переменные для тестирования');
        return;
    }
    
    // Показываем модальное окно
    showFormulaTestModal(formula, variables);
});

// Извлечение переменных из формулы
function extractVariables(formula) {
    const matches = formula.match(/[a-zA-Z_][a-zA-Z0-9_]*/g) || [];
    return [...new Set(matches)]; // убираем дубликаты
}

// Показ модального окна тестирования
function showFormulaTestModal(formula, variables) {
    document.getElementById('testFormulaDisplay').textContent = formula;
    
    const container = document.getElementById('testVariablesContainer');
    container.innerHTML = '';
    
    variables.forEach(variable => {
        const div = document.createElement('div');
        div.className = 'mb-3';
        div.innerHTML = `
            <label class="form-label">${variable}:</label>
            <input type="number" class="form-control test-variable" 
                   data-variable="${variable}" 
                   step="0.01" 
                   placeholder="Введите значение">
        `;
        container.appendChild(div);
    });
    
    const modal = new bootstrap.Modal(document.getElementById('formulaTestModal'));
    modal.show();
}

// Вычисление тестовой формулы
document.getElementById('calculateTestBtn').addEventListener('click', function() {
    const formula = document.getElementById('testFormulaDisplay').textContent;
    const variableInputs = document.querySelectorAll('.test-variable');
    const variables = {};
    
    // Собираем значения переменных
    let hasEmptyValues = false;
    variableInputs.forEach(input => {
        const value = parseFloat(input.value);
        if (isNaN(value)) {
            hasEmptyValues = true;
            input.classList.add('is-invalid');
        } else {
            input.classList.remove('is-invalid');
            variables[input.dataset.variable] = value;
        }
    });
    
    if (hasEmptyValues) {
        document.getElementById('testResult').innerHTML = 
            '<div class="alert alert-warning"><i class="bi bi-exclamation-triangle"></i> Заполните все поля</div>';
        document.getElementById('testResult').style.display = 'block';
        return;
    }
    
    // Вычисляем формулу
    try {
        const result = calculateFormula(formula, variables);
        document.getElementById('testResult').innerHTML = 
            '<div class="alert alert-success"><i class="bi bi-check-circle"></i> Результат: <strong>' + result + '</strong></div>';
    } catch (error) {
        document.getElementById('testResult').innerHTML = 
            '<div class="alert alert-danger"><i class="bi bi-x-circle"></i> Ошибка: ' + error.message + '</div>';
    }
    
    document.getElementById('testResult').style.display = 'block';
});

// Вычисление формулы (простая реализация)
function calculateFormula(formula, variables) {
    let expression = formula;
    
    // Заменяем переменные на значения
    for (const [variable, value] of Object.entries(variables)) {
        const regex = new RegExp('\\b' + variable + '\\b', 'g');
        expression = expression.replace(regex, value);
    }
    
    // Проверяем безопасность
    if (!/^[0-9+\-*/.() ]+$/.test(expression)) {
        throw new Error('Недопустимые символы после подстановки');
    }
    
    // Вычисляем
    const result = Function('"use strict"; return (' + expression + ')')();
    
    if (!isFinite(result)) {
        throw new Error('Результат не является конечным числом');
    }
    
    return Math.round(result * 100) / 100; // Округляем до 2 знаков
}
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>