<?php
/**
 * Добавление сотрудника
 * Система складского учета (SUT)
 */

require_once __DIR__ . '/../../config/config.php';

requireAccess('employees');

$pageTitle = 'Добавление сотрудника';

$errors = [];
$formData = [
    'login' => '',
    'first_name' => '',
    'last_name' => '',
    'middle_name' => '',
    'phone' => '',
    'role' => '',
    'company_id' => 0,
    'warehouse_id' => 0,
    'generate_password' => 1
];

try {
    $pdo = getDBConnection();
    
    // Получаем список компаний
    $stmt = $pdo->query("SELECT id, name FROM companies WHERE status = 1 ORDER BY name");
    $companies = $stmt->fetchAll();
    
    // Получаем список складов
    $stmt = $pdo->query("
        SELECT w.id, w.name, c.name as company_name, w.company_id
        FROM warehouses w 
        JOIN companies c ON w.company_id = c.id 
        WHERE w.status = 1 AND c.status = 1 
        ORDER BY c.name, w.name
    ");
    $warehouses = $stmt->fetchAll();
    
} catch (Exception $e) {
    logError('Employee create load error: ' . $e->getMessage());
    $error = 'Произошла ошибка при загрузке данных';
    $companies = [];
    $warehouses = [];
}

// Обработка формы
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf_token = $_POST['csrf_token'] ?? '';
    
    if (!verifyCSRFToken($csrf_token)) {
        $errors['csrf'] = 'Ошибка безопасности';
    } else {
        // Получаем данные формы
        foreach ($formData as $field => $default) {
            if (in_array($field, ['company_id', 'warehouse_id', 'generate_password'])) {
                $formData[$field] = (int)($_POST[$field] ?? $default);
            } else {
                $formData[$field] = trim($_POST[$field] ?? $default);
            }
        }
        
        // Валидация
        if (empty($formData['login'])) {
            $errors['login'] = 'Логин обязателен для заполнения';
        } elseif (strlen($formData['login']) < 3) {
            $errors['login'] = 'Логин должен содержать минимум 3 символа';
        } elseif (!preg_match('/^[a-zA-Z0-9_]+$/', $formData['login'])) {
            $errors['login'] = 'Логин может содержать только латинские буквы, цифры и символ подчеркивания';
        }
        
        if (empty($formData['first_name'])) {
            $errors['first_name'] = 'Имя обязательно для заполнения';
        }
        
        if (empty($formData['last_name'])) {
            $errors['last_name'] = 'Фамилия обязательна для заполнения';
        }
        
        if (!empty($formData['phone'])) {
            $phone = preg_replace('/\D/', '', $formData['phone']);
            if (strlen($phone) < 10) {
                $errors['phone'] = 'Некорректный номер телефона';
            }
        }
        
        if (empty($formData['role'])) {
            $errors['role'] = 'Необходимо выбрать роль';
        } elseif (!in_array($formData['role'], ['admin', 'pc_operator', 'warehouse_worker', 'sales_manager'])) {
            $errors['role'] = 'Некорректная роль';
        }
        
        // Для ролей, кроме администратора, компания обязательна
        if ($formData['role'] !== 'admin' && !$formData['company_id']) {
            $errors['company_id'] = 'Для данной роли необходимо выбрать компанию';
        }
        
        // Для работника склада склад обязателен
        if ($formData['role'] === 'warehouse_worker' && !$formData['warehouse_id']) {
            $errors['warehouse_id'] = 'Для работника склада необходимо выбрать склад';
        }
        
        if (empty($errors)) {
            try {
                // Проверяем уникальность логина
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE login = ?");
                $stmt->execute([$formData['login']]);
                if ($stmt->fetchColumn() > 0) {
                    $errors['login'] = 'Пользователь с таким логином уже существует';
                } else {
                    // Генерируем или получаем пароль
                    if ($formData['generate_password']) {
                        $password = generateRandomPassword();
                    } else {
                        $password = $_POST['password'] ?? '';
                        if (empty($password)) {
                            $errors['password'] = 'Необходимо указать пароль';
                        } elseif (strlen($password) < 6) {
                            $errors['password'] = 'Пароль должен содержать минимум 6 символов';
                        }
                    }
                    
                    if (empty($errors)) {
                        // Хешируем пароль
                        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
                        
                        // Создаем пользователя
                        $stmt = $pdo->prepare("
                            INSERT INTO users (
                                login, password, first_name, last_name, middle_name, phone, 
                                role, company_id, warehouse_id, status
                            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1)
                        ");
                        
                        $stmt->execute([
                            $formData['login'],
                            $hashedPassword,
                            $formData['first_name'],
                            $formData['last_name'],
                            $formData['middle_name'] ?: null,
                            $formData['phone'] ?: null,
                            $formData['role'],
                            $formData['company_id'] ?: null,
                            $formData['warehouse_id'] ?: null
                        ]);
                        
                        $userId = $pdo->lastInsertId();
                        
                        // Логируем создание
                        logError('Employee created', [
                            'user_id' => $userId,
                            'login' => $formData['login'],
                            'role' => $formData['role'],
                            'created_by' => getCurrentUser()['id']
                        ]);
                        
                        $fullName = trim($formData['first_name'] . ' ' . $formData['last_name']);
                        $_SESSION['success_message'] = 'Сотрудник "' . $fullName . '" успешно создан. Логин: ' . $formData['login'] . ', пароль: ' . $password;
                        header('Location: /pages/employees/view.php?id=' . $userId);
                        exit;
                    }
                }
                
            } catch (Exception $e) {
                logError('Create employee error: ' . $e->getMessage(), $formData);
                $errors['general'] = 'Произошла ошибка при создании сотрудника';
            }
        }
    }
}

/**
 * Генерация случайного пароля
 */
function generateRandomPassword($length = 8) {
    $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
    $password = '';
    for ($i = 0; $i < $length; $i++) {
        $password .= $characters[rand(0, strlen($characters) - 1)];
    }
    return $password;
}

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item">
                    <a href="/pages/employees/index.php">Сотрудники</a>
                </li>
                <li class="breadcrumb-item active"><?= e($pageTitle) ?></li>
            </ol>
        </nav>
        <h1 class="h3 mb-0"><?= e($pageTitle) ?></h1>
    </div>
    <div>
        <a href="/pages/employees/index.php" class="btn btn-outline-secondary">
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
                    <i class="bi bi-person-plus"></i>
                    Информация о сотруднике
                </h5>
            </div>
            <div class="card-body">
                <form method="POST" action="" novalidate>
                    <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                    
                    <div class="row">
                        <!-- Фамилия -->
                        <div class="col-md-4 mb-3">
                            <label for="last_name" class="form-label">
                                Фамилия <span class="text-danger">*</span>
                            </label>
                            <input type="text" 
                                   class="form-control <?= isset($errors['last_name']) ? 'is-invalid' : '' ?>" 
                                   id="last_name" 
                                   name="last_name" 
                                   value="<?= e($formData['last_name']) ?>"
                                   required>
                            <?php if (isset($errors['last_name'])): ?>
                            <div class="invalid-feedback"><?= e($errors['last_name']) ?></div>
                            <?php endif; ?>
                        </div>
                        
                        <!-- Имя -->
                        <div class="col-md-4 mb-3">
                            <label for="first_name" class="form-label">
                                Имя <span class="text-danger">*</span>
                            </label>
                            <input type="text" 
                                   class="form-control <?= isset($errors['first_name']) ? 'is-invalid' : '' ?>" 
                                   id="first_name" 
                                   name="first_name" 
                                   value="<?= e($formData['first_name']) ?>"
                                   required>
                            <?php if (isset($errors['first_name'])): ?>
                            <div class="invalid-feedback"><?= e($errors['first_name']) ?></div>
                            <?php endif; ?>
                        </div>
                        
                        <!-- Отчество -->
                        <div class="col-md-4 mb-3">
                            <label for="middle_name" class="form-label">Отчество</label>
                            <input type="text" 
                                   class="form-control" 
                                   id="middle_name" 
                                   name="middle_name" 
                                   value="<?= e($formData['middle_name']) ?>">
                        </div>
                    </div>
                    
                    <div class="row">
                        <!-- Логин -->
                        <div class="col-md-6 mb-3">
                            <label for="login" class="form-label">
                                Логин <span class="text-danger">*</span>
                            </label>
                            <input type="text" 
                                   class="form-control <?= isset($errors['login']) ? 'is-invalid' : '' ?>" 
                                   id="login" 
                                   name="login" 
                                   value="<?= e($formData['login']) ?>"
                                   required
                                   autocomplete="username">
                            <?php if (isset($errors['login'])): ?>
                            <div class="invalid-feedback"><?= e($errors['login']) ?></div>
                            <?php endif; ?>
                            <div class="form-text">Только латинские буквы, цифры и символ подчеркивания</div>
                        </div>
                        
                        <!-- Телефон -->
                        <div class="col-md-6 mb-3">
                            <label for="phone" class="form-label">Телефон</label>
                            <input type="tel" 
                                   class="form-control <?= isset($errors['phone']) ? 'is-invalid' : '' ?>" 
                                   id="phone" 
                                   name="phone" 
                                   value="<?= e($formData['phone']) ?>"
                                   placeholder="+7 (999) 999-99-99">
                            <?php if (isset($errors['phone'])): ?>
                            <div class="invalid-feedback"><?= e($errors['phone']) ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <!-- Роль -->
                    <div class="mb-3">
                        <label for="role" class="form-label">
                            Роль <span class="text-danger">*</span>
                        </label>
                        <select class="form-select <?= isset($errors['role']) ? 'is-invalid' : '' ?>" 
                                id="role" 
                                name="role" 
                                required>
                            <option value="">Выберите роль</option>
                            <option value="admin" <?= $formData['role'] === 'admin' ? 'selected' : '' ?>>
                                Администратор
                            </option>
                            <option value="pc_operator" <?= $formData['role'] === 'pc_operator' ? 'selected' : '' ?>>
                                Оператор ПК
                            </option>
                            <option value="warehouse_worker" <?= $formData['role'] === 'warehouse_worker' ? 'selected' : '' ?>>
                                Работник склада
                            </option>
                            <option value="sales_manager" <?= $formData['role'] === 'sales_manager' ? 'selected' : '' ?>>
                                Менеджер по продажам
                            </option>
                        </select>
                        <?php if (isset($errors['role'])): ?>
                        <div class="invalid-feedback"><?= e($errors['role']) ?></div>
                        <?php endif; ?>
                    </div>
                    
                    <div class="row" id="company-warehouse-fields">
                        <!-- Компания -->
                        <div class="col-md-6 mb-3">
                            <label for="company_id" class="form-label">
                                Компания <span class="text-danger company-required" style="display: none;">*</span>
                            </label>
                            <select class="form-select <?= isset($errors['company_id']) ? 'is-invalid' : '' ?>" 
                                    id="company_id" 
                                    name="company_id">
                                <option value="">Выберите компанию</option>
                                <?php foreach ($companies as $company): ?>
                                <option value="<?= $company['id'] ?>" <?= $formData['company_id'] == $company['id'] ? 'selected' : '' ?>>
                                    <?= e($company['name']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <?php if (isset($errors['company_id'])): ?>
                            <div class="invalid-feedback"><?= e($errors['company_id']) ?></div>
                            <?php endif; ?>
                        </div>
                        
                        <!-- Склад -->
                        <div class="col-md-6 mb-3">
                            <label for="warehouse_id" class="form-label">
                                Склад <span class="text-danger warehouse-required" style="display: none;">*</span>
                            </label>
                            <select class="form-select <?= isset($errors['warehouse_id']) ? 'is-invalid' : '' ?>" 
                                    id="warehouse_id" 
                                    name="warehouse_id">
                                <option value="">Выберите склад</option>
                                <?php foreach ($warehouses as $warehouse): ?>
                                <option value="<?= $warehouse['id'] ?>" 
                                        data-company="<?= $warehouse['company_id'] ?>"
                                        <?= $formData['warehouse_id'] == $warehouse['id'] ? 'selected' : '' ?>>
                                    <?= e($warehouse['name']) ?> (<?= e($warehouse['company_name']) ?>)
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <?php if (isset($errors['warehouse_id'])): ?>
                            <div class="invalid-feedback"><?= e($errors['warehouse_id']) ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <hr>
                    
                    <h6>Настройки пароля</h6>
                    
                    <!-- Генерация пароля -->
                    <div class="mb-3">
                        <div class="form-check">
                            <input class="form-check-input" 
                                   type="checkbox" 
                                   id="generate_password" 
                                   name="generate_password" 
                                   value="1"
                                   <?= $formData['generate_password'] ? 'checked' : '' ?>>
                            <label class="form-check-label" for="generate_password">
                                Сгенерировать пароль автоматически
                            </label>
                        </div>
                        <div class="form-text">
                            Если отмечено, система создаст случайный пароль из 8 символов
                        </div>
                    </div>
                    
                    <!-- Ручной ввод пароля -->
                    <div class="mb-3" id="manual-password" style="display: none;">
                        <label for="password" class="form-label">
                            Пароль <span class="text-danger">*</span>
                        </label>
                        <input type="password" 
                               class="form-control <?= isset($errors['password']) ? 'is-invalid' : '' ?>" 
                               id="password" 
                               name="password" 
                               autocomplete="new-password">
                        <?php if (isset($errors['password'])): ?>
                        <div class="invalid-feedback"><?= e($errors['password']) ?></div>
                        <?php endif; ?>
                        <div class="form-text">Минимум 6 символов</div>
                    </div>
                    
                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-person-plus"></i>
                            Создать сотрудника
                        </button>
                        <a href="/pages/employees/index.php" class="btn btn-outline-secondary">
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
                    Справка по ролям
                </h6>
            </div>
            <div class="card-body">
                <div class="role-info">
                    <div class="role-item">
                        <span class="badge bg-danger mb-1">Администратор</span>
                        <p class="small">Полный доступ ко всем разделам системы. Не привязывается к компании.</p>
                    </div>
                    
                    <div class="role-item">
                        <span class="badge bg-primary mb-1">Оператор ПК</span>
                        <p class="small">Работа с товарами, остатками, запросами. Обязательна привязка к компании.</p>
                    </div>
                    
                    <div class="role-item">
                        <span class="badge bg-success mb-1">Работник склада</span>
                        <p class="small">Приемка, отгрузка товаров. Обязательна привязка к компании и складу.</p>
                    </div>
                    
                    <div class="role-item">
                        <span class="badge bg-warning mb-1">Менеджер по продажам</span>
                        <p class="small">Реализация товаров, работа с запросами. Обязательна привязка к компании.</p>
                    </div>
                </div>
                
                <hr>
                
                <h6>Генерация логина</h6>
                <p class="small">
                    Рекомендуется использовать схему: 
                    <code>фамилия + цифра</code> (например: <code>ivanov1</code>)
                </p>
                
                <button type="button" class="btn btn-sm btn-outline-primary" onclick="generateLogin()">
                    Сгенерировать логин
                </button>
            </div>
        </div>
    </div>
</div>

<script>
// Управление отображением полей в зависимости от роли
document.getElementById('role').addEventListener('change', function() {
    const role = this.value;
    const companyField = document.getElementById('company_id');
    const warehouseField = document.getElementById('warehouse_id');
    const companyRequired = document.querySelector('.company-required');
    const warehouseRequired = document.querySelector('.warehouse-required');
    
    if (role === 'admin') {
        // Администратор - компания и склад не обязательны
        companyRequired.style.display = 'none';
        warehouseRequired.style.display = 'none';
        companyField.required = false;
        warehouseField.required = false;
    } else if (role === 'warehouse_worker') {
        // Работник склада - компания и склад обязательны
        companyRequired.style.display = 'inline';
        warehouseRequired.style.display = 'inline';
        companyField.required = true;
        warehouseField.required = true;
    } else if (role) {
        // Остальные роли - только компания обязательна
        companyRequired.style.display = 'inline';
        warehouseRequired.style.display = 'none';
        companyField.required = true;
        warehouseField.required = false;
    } else {
        // Роль не выбрана
        companyRequired.style.display = 'none';
        warehouseRequired.style.display = 'none';
        companyField.required = false;
        warehouseField.required = false;
    }
});

// Фильтрация складов по выбранной компании
document.getElementById('company_id').addEventListener('change', function() {
    const companyId = this.value;
    const warehouseSelect = document.getElementById('warehouse_id');
    
    // Сбрасываем выбор склада
    warehouseSelect.value = '';
    
    // Показываем только склады выбранной компании
    Array.from(warehouseSelect.options).forEach(option => {
        if (option.value === '') return; // Пропускаем "Выберите склад"
        
        if (!companyId) {
            option.style.display = 'block';
        } else {
            option.style.display = option.dataset.company === companyId ? 'block' : 'none';
        }
    });
});

// Управление отображением поля пароля
document.getElementById('generate_password').addEventListener('change', function() {
    const manualPassword = document.getElementById('manual-password');
    const passwordField = document.getElementById('password');
    
    if (this.checked) {
        manualPassword.style.display = 'none';
        passwordField.required = false;
    } else {
        manualPassword.style.display = 'block';
        passwordField.required = true;
    }
});

// Генерация логина на основе ФИО
function generateLogin() {
    const lastName = document.getElementById('last_name').value.trim();
    const firstName = document.getElementById('first_name').value.trim();
    
    if (!lastName) {
        alert('Сначала введите фамилию');
        return;
    }
    
    // Транслитерация
    const translitMap = {
        'а': 'a', 'б': 'b', 'в': 'v', 'г': 'g', 'д': 'd', 'е': 'e', 'ё': 'e',
        'ж': 'zh', 'з': 'z', 'и': 'i', 'й': 'y', 'к': 'k', 'л': 'l', 'м': 'm',
        'н': 'n', 'о': 'o', 'п': 'p', 'р': 'r', 'с': 's', 'т': 't', 'у': 'u',
        'ф': 'f', 'х': 'h', 'ц': 'c', 'ч': 'ch', 'ш': 'sh', 'щ': 'sch',
        'ъ': '', 'ы': 'y', 'ь': '', 'э': 'e', 'ю': 'yu', 'я': 'ya'
    };
    
    function translit(text) {
        return text.toLowerCase().split('').map(char => translitMap[char] || char).join('');
    }
    
    let login = translit(lastName);
    if (firstName) {
        login += translit(firstName.charAt(0));
    }
    
    // Добавляем цифру
    login += '1';
    
    document.getElementById('login').value = login;
}

// Форматирование телефона
document.getElementById('phone').addEventListener('input', function(e) {
    let value = e.target.value.replace(/\D/g, '');
    if (value.startsWith('7')) {
        value = value.slice(1);
    }
    if (value.length > 0) {
        if (value.length <= 3) {
            value = `+7 (${value}`;
        } else if (value.length <= 6) {
            value = `+7 (${value.slice(0, 3)}) ${value.slice(3)}`;
        } else if (value.length <= 8) {
            value = `+7 (${value.slice(0, 3)}) ${value.slice(3, 6)}-${value.slice(6)}`;
        } else {
            value = `+7 (${value.slice(0, 3)}) ${value.slice(3, 6)}-${value.slice(6, 8)}-${value.slice(8, 10)}`;
        }
    }
    e.target.value = value;
});

// Инициализация при загрузке
document.addEventListener('DOMContentLoaded', function() {
    // Проверяем начальное состояние роли
    document.getElementById('role').dispatchEvent(new Event('change'));
    
    // Проверяем начальное состояние генерации пароля
    document.getElementById('generate_password').dispatchEvent(new Event('change'));
    
    // Проверяем начальное состояние компании
    document.getElementById('company_id').dispatchEvent(new Event('change'));
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>