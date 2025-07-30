<?php
/**
 * Редактирование сотрудника
 * Система складского учета (SUT)
 */

require_once __DIR__ . '/../../config/config.php';

requireAccess('employees');

$employeeId = (int)($_GET['id'] ?? 0);

if (!$employeeId) {
    $_SESSION['error_message'] = 'Сотрудник не найден';
    header('Location: /pages/employees/index.php');
    exit;
}

$errors = [];
$formData = [];

try {
    $pdo = getDBConnection();
    
    // Получаем информацию о сотруднике
    $stmt = $pdo->prepare("
        SELECT u.*, c.name as company_name, w.name as warehouse_name
        FROM users u
        LEFT JOIN companies c ON u.company_id = c.id
        LEFT JOIN warehouses w ON u.warehouse_id = w.id
        WHERE u.id = ?
    ");
    $stmt->execute([$employeeId]);
    $employee = $stmt->fetch();
    
    if (!$employee) {
        $_SESSION['error_message'] = 'Сотрудник не найден';
        header('Location: /pages/employees/index.php');
        exit;
    }
    
    // Заполняем форму данными сотрудника
    $formData = [
        'login' => $employee['login'],
        'first_name' => $employee['first_name'],
        'last_name' => $employee['last_name'],
        'middle_name' => $employee['middle_name'] ?? '',
        'phone' => $employee['phone'] ?? '',
        'role' => $employee['role'],
        'company_id' => $employee['company_id'] ?? 0,
        'warehouse_id' => $employee['warehouse_id'] ?? 0,
        'status' => $employee['status']
    ];
    
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
    logError('Employee edit load error: ' . $e->getMessage(), ['employee_id' => $employeeId]);
    $_SESSION['error_message'] = 'Произошла ошибка при загрузке данных';
    header('Location: /pages/employees/index.php');
    exit;
}

// Обработка формы
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf_token = $_POST['csrf_token'] ?? '';
    
    if (!verifyCSRFToken($csrf_token)) {
        $errors['csrf'] = 'Ошибка безопасности';
    } else {
        // Получаем данные формы
        foreach ($formData as $field => $default) {
            if (in_array($field, ['company_id', 'warehouse_id', 'status'])) {
                $formData[$field] = (int)($_POST[$field] ?? $default);
            } else {
                $formData[$field] = trim($_POST[$field] ?? $default);
            }
        }
        
        // Валидация (исключаем логин, так как он не изменяется)
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
                // Обновляем данные сотрудника
                $stmt = $pdo->prepare("
                    UPDATE users SET 
                        first_name = ?, 
                        last_name = ?, 
                        middle_name = ?, 
                        phone = ?, 
                        role = ?, 
                        company_id = ?, 
                        warehouse_id = ?,
                        status = ?,
                        updated_at = NOW()
                    WHERE id = ?
                ");
                
                $stmt->execute([
                    $formData['first_name'],
                    $formData['last_name'],
                    $formData['middle_name'] ?: null,
                    $formData['phone'] ?: null,
                    $formData['role'],
                    $formData['company_id'] ?: null,
                    $formData['warehouse_id'] ?: null,
                    $formData['status'],
                    $employeeId
                ]);
                
                // Если сотрудник заблокирован, завершаем все его сессии
                if ($formData['status'] == 0 && $employee['status'] == 1) {
                    $stmt = $pdo->prepare("DELETE FROM user_sessions WHERE user_id = ?");
                    $stmt->execute([$employeeId]);
                }
                
                // Логируем изменение
                logError('Employee updated', [
                    'employee_id' => $employeeId,
                    'employee_login' => $employee['login'],
                    'updated_by' => getCurrentUser()['id'],
                    'changes' => array_diff_assoc($formData, [
                        'login' => $employee['login'],
                        'first_name' => $employee['first_name'],
                        'last_name' => $employee['last_name'],
                        'middle_name' => $employee['middle_name'] ?? '',
                        'phone' => $employee['phone'] ?? '',
                        'role' => $employee['role'],
                        'company_id' => $employee['company_id'] ?? 0,
                        'warehouse_id' => $employee['warehouse_id'] ?? 0,
                        'status' => $employee['status']
                    ])
                ]);
                
                $fullName = trim($formData['first_name'] . ' ' . $formData['last_name']);
                $_SESSION['success_message'] = 'Данные сотрудника "' . $fullName . '" успешно обновлены';
                header('Location: /pages/employees/view.php?id=' . $employeeId);
                exit;
                
            } catch (Exception $e) {
                logError('Update employee error: ' . $e->getMessage(), $formData);
                $errors['general'] = 'Произошла ошибка при обновлении данных сотрудника';
            }
        }
    }
}

$pageTitle = 'Редактирование сотрудника';

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item">
                    <a href="/pages/employees/index.php">Сотрудники</a>
                </li>
                <li class="breadcrumb-item">
                    <a href="/pages/employees/view.php?id=<?= $employeeId ?>">
                        <?= e(trim($employee['last_name'] . ' ' . $employee['first_name'])) ?>
                    </a>
                </li>
                <li class="breadcrumb-item active"><?= e($pageTitle) ?></li>
            </ol>
        </nav>
        <h1 class="h3 mb-0"><?= e($pageTitle) ?></h1>
        <p class="text-muted mb-0">Логин: <code><?= e($employee['login']) ?></code></p>
    </div>
    <div>
        <a href="/pages/employees/view.php?id=<?= $employeeId ?>" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left"></i>
            Назад
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
                    <i class="bi bi-pencil"></i>
                    Редактирование информации
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
                        <!-- Логин (только для отображения) -->
                        <div class="col-md-6 mb-3">
                            <label for="login_display" class="form-label">Логин</label>
                            <input type="text" 
                                   class="form-control" 
                                   id="login_display" 
                                   value="<?= e($formData['login']) ?>"
                                   disabled>
                            <div class="form-text">Логин нельзя изменить после создания</div>
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

                    <!-- Статус -->
                    <div class="mb-3">
                        <label for="status" class="form-label">Статус</label>
                        <select class="form-select" id="status" name="status">
                            <option value="1" <?= $formData['status'] == 1 ? 'selected' : '' ?>>Активен</option>
                            <option value="0" <?= $formData['status'] == 0 ? 'selected' : '' ?>>Заблокирован</option>
                        </select>
                        <div class="form-text">При блокировке все активные сессии пользователя будут завершены</div>
                    </div>
                    
                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-check-lg"></i>
                            Сохранить изменения
                        </button>
                        <a href="/pages/employees/view.php?id=<?= $employeeId ?>" class="btn btn-outline-secondary">
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
                    Информация о сотруднике
                </h6>
            </div>
            <div class="card-body">
                <div class="detail-row">
                    <div class="detail-label">Создан:</div>
                    <div class="detail-value"><?= formatDate($employee['created_at']) ?></div>
                </div>
                
                <div class="detail-row">
                    <div class="detail-label">Обновлен:</div>
                    <div class="detail-value"><?= formatDate($employee['updated_at']) ?></div>
                </div>
                
                <?php if ($employee['last_login']): ?>
                <div class="detail-row">
                    <div class="detail-label">Последний вход:</div>
                    <div class="detail-value"><?= formatDate($employee['last_login']) ?></div>
                </div>
                <?php endif; ?>
                
                <hr>
                
                <h6>Быстрые действия</h6>
                <div class="d-grid gap-2">
                    <a href="/pages/employees/reset_password.php?id=<?= $employeeId ?>" 
                       class="btn btn-outline-warning btn-sm">
                        <i class="bi bi-key"></i>
                        Сбросить пароль
                    </a>
                    
                    <?php if ($formData['status'] == 1): ?>
                    <a href="/pages/employees/block.php?id=<?= $employeeId ?>" 
                       class="btn btn-outline-danger btn-sm"
                       data-confirm-action="Заблокировать сотрудника?">
                        <i class="bi bi-lock"></i>
                        Заблокировать
                    </a>
                    <?php else: ?>
                    <a href="/pages/employees/unblock.php?id=<?= $employeeId ?>" 
                       class="btn btn-outline-success btn-sm">
                        <i class="bi bi-unlock"></i>
                        Разблокировать
                    </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
.detail-row {
    display: flex;
    margin-bottom: 0.75rem;
}

.detail-label {
    font-weight: 600;
    min-width: 120px;
    color: #6c757d;
}

.detail-value {
    flex: 1;
}
</style>

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
    const currentWarehouseId = warehouseSelect.value;
    
    // Показываем только склады выбранной компании
    Array.from(warehouseSelect.options).forEach(option => {
        if (option.value === '') return; // Пропускаем "Выберите склад"
        
        if (!companyId) {
            option.style.display = 'block';
        } else {
            const shouldShow = option.dataset.company === companyId;
            option.style.display = shouldShow ? 'block' : 'none';
            
            // Если текущий выбранный склад не принадлежит новой компании, сбрасываем выбор
            if (!shouldShow && option.value === currentWarehouseId) {
                warehouseSelect.value = '';
            }
        }
    });
});

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

// Подтверждение действий
document.addEventListener('DOMContentLoaded', function() {
    const actionLinks = document.querySelectorAll('[data-confirm-action]');
    actionLinks.forEach(link => {
        link.addEventListener('click', function(e) {
            e.preventDefault();
            const message = this.dataset.confirmAction;
            
            if (confirm(message)) {
                window.location.href = this.href;
            }
        });
    });
    
    // Проверяем начальное состояние роли
    document.getElementById('role').dispatchEvent(new Event('change'));
    
    // Проверяем начальное состояние компании
    document.getElementById('company_id').dispatchEvent(new Event('change'));
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>