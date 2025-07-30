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
        
        // Валидация
        if (empty($formData['name'])) {
            $errors['name'] = 'Название шаблона обязательно для заполнения';
        } elseif (strlen($formData['name']) < 3) {
            $errors['name'] = 'Название должно содержать минимум 3 символа';
        }
        
        if (empty($formData['description'])) {
            $errors['description'] = 'Описание обязательно для заполнения';
        }
        
        // Валидация формулы (если указана)
        if (!empty($formData['formula'])) {
            $formulaValidation = validateFormula($formData['formula']);
            if (!$formulaValidation['valid']) {
                $errors['formula'] = $formulaValidation['error'];
            }
        }
        
        if (empty($errors)) {
            try {
                $pdo = getDBConnection();
                
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
                    
                    // Логируем создание
                    logError('Template created', [
                        'template_id' => $templateId,
                        'template_name' => $formData['name'],
                        'created_by' => getCurrentUser()['id']
                    ]);
                    
                    $_SESSION['success_message'] = 'Шаблон "' . $formData['name'] . '" успешно создан';
                    header('Location: /pages/templates/view.php?id=' . $templateId);
                    exit;
                }
                
            } catch (Exception $e) {
                logError('Create template error: ' . $e->getMessage(), $formData);
                $errors['general'] = 'Произошла ошибка при создании шаблона';
            }
        }
    }
}

/**
 * Валидация формулы
 */
function validateFormula($formula) {
    if (empty($formula)) {
        return ['valid' => true];
    }
    
    // Проверяем на разрешенные символы (переменные, числа, операторы, скобки, точки)
    if (!preg_match('/^[a-zA-Z0-9_+\-*\/().\s]+$/', $formula)) {
        return [
            'valid' => false,
            'error' => 'Формула содержит недопустимые символы. Разрешены: буквы, цифры, +, -, *, /, (, )'
        ];
    }
    
    // Проверяем баланс скобок
    $openBrackets = substr_count($formula, '(');
    $closeBrackets = substr_count($formula, ')');
    if ($openBrackets !== $closeBrackets) {
        return [
            'valid' => false,
            'error' => 'Несбалансированные скобки в формуле'
        ];
    }
    
    // Проверяем на пустые скобки
    if (strpos($formula, '()') !== false) {
        return [
            'valid' => false,
            'error' => 'Обнаружены пустые скобки в формуле'
        ];
    }
    
    return ['valid' => true];
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

<div class="row">
    <div class="col-lg-8">
        <div class="card">
            <div class="card-header">
                <h5 class="card-title mb-0">
                    <i class="bi bi-plus-circle"></i>
                    Основная информация
                </h5>
            </div>
            <div class="card-body">
                <form method="POST" action="" novalidate>
                    <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                    
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
                    
                    <!-- Формула -->
                    <div class="mb-3">
                        <label for="formula" class="form-label">
                            Формула расчета объема
                            <span class="text-muted">(необязательно)</span>
                        </label>
                        <div class="input-group">
                            <input type="text" 
                                   class="form-control <?= isset($errors['formula']) ? 'is-invalid' : '' ?>" 
                                   id="formula" 
                                   name="formula" 
                                   value="<?= e($formData['formula']) ?>"
                                   placeholder="Например: length * width * height">
                            <button type="button" class="btn btn-outline-secondary" id="testFormulaBtn">
                                <i class="bi bi-calculator"></i>
                                Тестировать
                            </button>
                        </div>
                        <?php if (isset($errors['formula'])): ?>
                        <div class="invalid-feedback"><?= e($errors['formula']) ?></div>
                        <?php endif; ?>
                        <div class="form-text">
                            Используйте переменные (английские буквы) и операторы: +, -, *, /, (, )<br>
                            Переменные должны соответствовать названиям характеристик
                        </div>
                        
                        <!-- Результат тестирования формулы -->
                        <div id="formulaTestResult" class="mt-2" style="display: none;"></div>
                    </div>
                    
                    <!-- Статус -->
                    <div class="mb-4">
                        <label for="status" class="form-label">Статус</label>
                        <select class="form-select" id="status" name="status">
                            <option value="1" <?= $formData['status'] == 1 ? 'selected' : '' ?>>Активен</option>
                            <option value="0" <?= $formData['status'] == 0 ? 'selected' : '' ?>>Неактивен</option>
                        </select>
                        <div class="form-text">Неактивные шаблоны не отображаются при создании товаров</div>
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
                </form>
            </div>
        </div>
    </div>
    
    <div class="col-lg-4">
        <div class="card">
            <div class="card-header">
                <h6 class="card-title mb-0">
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