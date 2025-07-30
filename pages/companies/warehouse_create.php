<?php
/**
 * Добавление склада
 * Система складского учета (SUT)
 */

require_once __DIR__ . '/../../config/config.php';

requireAccess('companies');

$companyId = (int)($_GET['company_id'] ?? 0);

if (!$companyId) {
    $_SESSION['error_message'] = 'Компания не найдена';
    header('Location: /pages/companies/index.php');
    exit;
}

$errors = [];
$formData = [
    'name' => '',
    'address' => ''
];

try {
    $pdo = getDBConnection();
    
    // Проверяем существование компании
    $stmt = $pdo->prepare("SELECT * FROM companies WHERE id = ? AND status != -1");
    $stmt->execute([$companyId]);
    $company = $stmt->fetch();
    
    if (!$company) {
        $_SESSION['error_message'] = 'Компания не найдена';
        header('Location: /pages/companies/index.php');
        exit;
    }
    
} catch (Exception $e) {
    logError('Warehouse create load error: ' . $e->getMessage(), ['company_id' => $companyId]);
    $_SESSION['error_message'] = 'Произошла ошибка при загрузке данных';
    header('Location: /pages/companies/index.php');
    exit;
}

$pageTitle = 'Добавление склада';

// Обработка формы
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf_token = $_POST['csrf_token'] ?? '';
    
    if (!verifyCSRFToken($csrf_token)) {
        $errors['csrf'] = 'Ошибка безопасности';
    } else {
        // Получаем данные формы
        $formData['name'] = trim($_POST['name'] ?? '');
        $formData['address'] = trim($_POST['address'] ?? '');
        
        // Валидация
        if (empty($formData['name'])) {
            $errors['name'] = 'Название склада обязательно для заполнения';
        } elseif (strlen($formData['name']) > 100) {
            $errors['name'] = 'Название склада не должно превышать 100 символов';
        }
        
        if (empty($errors)) {
            try {
                // Проверяем уникальность названия в рамках компании
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM warehouses WHERE company_id = ? AND name = ? AND status >= 0");
                $stmt->execute([$companyId, $formData['name']]);
                if ($stmt->fetchColumn() > 0) {
                    $errors['name'] = 'Склад с таким названием уже существует в данной компании';
                } else {
                    // Создаем склад
                    $stmt = $pdo->prepare("
                        INSERT INTO warehouses (company_id, name, address, status)
                        VALUES (?, ?, ?, 1)
                    ");
                    
                    $stmt->execute([
                        $companyId,
                        $formData['name'],
                        $formData['address'] ?: null
                    ]);
                    
                    $warehouseId = $pdo->lastInsertId();
                    
                    // Логируем создание
                    logError('Warehouse created', [
                        'warehouse_id' => $warehouseId,
                        'warehouse_name' => $formData['name'],
                        'company_id' => $companyId,
                        'company_name' => $company['name'],
                        'created_by' => getCurrentUser()['id']
                    ]);
                    
                    $_SESSION['success_message'] = 'Склад "' . $formData['name'] . '" успешно создан';
                    header('Location: /pages/companies/view.php?id=' . $companyId);
                    exit;
                }
                
            } catch (Exception $e) {
                logError('Create warehouse error: ' . $e->getMessage(), array_merge($formData, ['company_id' => $companyId]));
                $errors['general'] = 'Произошла ошибка при создании склада';
            }
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
                    <a href="/pages/companies/index.php">Компании</a>
                </li>
                <li class="breadcrumb-item">
                    <a href="/pages/companies/view.php?id=<?= $companyId ?>"><?= e($company['name']) ?></a>
                </li>
                <li class="breadcrumb-item active"><?= e($pageTitle) ?></li>
            </ol>
        </nav>
        <h1 class="h3 mb-0"><?= e($pageTitle) ?></h1>
        <p class="text-muted mb-0">Компания: <?= e($company['name']) ?></p>
    </div>
    <div>
        <a href="/pages/companies/view.php?id=<?= $companyId ?>" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left"></i>
            Назад к компании
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
                    <i class="bi bi-warehouse"></i>
                    Информация о складе
                </h5>
            </div>
            <div class="card-body">
                <form method="POST" action="" novalidate>
                    <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                    
                    <!-- Название склада -->
                    <div class="mb-3">
                        <label for="name" class="form-label">
                            Название склада <span class="text-danger">*</span>
                        </label>
                        <input type="text" 
                               class="form-control <?= isset($errors['name']) ? 'is-invalid' : '' ?>" 
                               id="name" 
                               name="name" 
                               value="<?= e($formData['name']) ?>"
                               maxlength="100"
                               required
                               placeholder="Например: Центральный склад">
                        <?php if (isset($errors['name'])): ?>
                        <div class="invalid-feedback"><?= e($errors['name']) ?></div>
                        <?php endif; ?>
                        <div class="form-text">Название должно быть уникальным в рамках компании</div>
                    </div>
                    
                    <!-- Адрес склада -->
                    <div class="mb-3">
                        <label for="address" class="form-label">Адрес склада</label>
                        <textarea class="form-control" 
                                  id="address" 
                                  name="address" 
                                  rows="3"
                                  placeholder="Полный адрес склада"><?= e($formData['address']) ?></textarea>
                        <div class="form-text">Укажите полный адрес для удобства навигации</div>
                    </div>
                    
                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-check-circle"></i>
                            Создать склад
                        </button>
                        <a href="/pages/companies/view.php?id=<?= $companyId ?>" class="btn btn-outline-secondary">
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
                    Справка
                </h6>
            </div>
            <div class="card-body">
                <h6>О складах</h6>
                <p class="small">
                    Склад - это физическое место хранения товаров. К одной компании можно привязать несколько складов.
                </p>
                
                <h6>После создания</h6>
                <ul class="small">
                    <li>Можно назначить сотрудников для работы со складом</li>
                    <li>Добавлять товары на склад</li>
                    <li>Отслеживать остатки товаров</li>
                    <li>Проводить операции реализации</li>
                </ul>
                
                <h6>Обязательные поля</h6>
                <ul class="small">
                    <li><strong>Название склада</strong> - для идентификации в системе</li>
                </ul>
            </div>
        </div>
        
        <!-- Информация о компании -->
        <div class="card">
            <div class="card-header">
                <h6 class="card-title mb-0">
                    <i class="bi bi-building"></i>
                    Компания
                </h6>
            </div>
            <div class="card-body">
                <div class="detail-row">
                    <div class="detail-label">Название:</div>
                    <div class="detail-value"><?= e($company['name']) ?></div>
                </div>
                
                <?php if ($company['director']): ?>
                <div class="detail-row">
                    <div class="detail-label">Директор:</div>
                    <div class="detail-value"><?= e($company['director']) ?></div>
                </div>
                <?php endif; ?>
                
                <?php if ($company['phone']): ?>
                <div class="detail-row">
                    <div class="detail-label">Телефон:</div>
                    <div class="detail-value"><?= e($company['phone']) ?></div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Автофокус на поле названия
    document.getElementById('name').focus();
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>