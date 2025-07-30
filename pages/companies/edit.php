<?php
/**
 * Редактирование компании
 * Система складского учета (SUT)
 */

require_once __DIR__ . '/../../config/config.php';

requireAccess('companies');

$companyId = (int)($_GET['id'] ?? 0);

if (!$companyId) {
    $_SESSION['error_message'] = 'Компания не найдена';
    header('Location: /pages/companies/index.php');
    exit;
}

$errors = [];
$formData = [];

try {
    $pdo = getDBConnection();
    
    // Получаем данные компании
    $stmt = $pdo->prepare("SELECT * FROM companies WHERE id = ?");
    $stmt->execute([$companyId]);
    $company = $stmt->fetch();
    
    if (!$company) {
        $_SESSION['error_message'] = 'Компания не найдена';
        header('Location: /pages/companies/index.php');
        exit;
    }
    
    // Заполняем форму данными из БД
    $formData = [
        'name' => $company['name'],
        'legal_address' => $company['legal_address'],
        'postal_address' => $company['postal_address'],
        'phone' => $company['phone'],
        'director' => $company['director'],
        'email' => $company['email'],
        'inn' => $company['inn'],
        'kpp' => $company['kpp'],
        'ogrn' => $company['ogrn'],
        'bank' => $company['bank'],
        'account' => $company['account'],
        'correspondent_account' => $company['correspondent_account'],
        'bik' => $company['bik'],
        'status' => $company['status']
    ];
    
} catch (Exception $e) {
    logError('Company edit load error: ' . $e->getMessage(), ['company_id' => $companyId]);
    $_SESSION['error_message'] = 'Произошла ошибка при загрузке данных';
    header('Location: /pages/companies/index.php');
    exit;
}

$pageTitle = 'Редактирование: ' . $company['name'];

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
        
        // Валидация (аналогично create.php)
        if (empty($formData['name'])) {
            $errors['name'] = 'Название компании обязательно для заполнения';
        } elseif (strlen($formData['name']) > 100) {
            $errors['name'] = 'Название компании не должно превышать 100 символов';
        }
        
        if (!empty($formData['email']) && !filter_var($formData['email'], FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Некорректный email адрес';
        }
        
        if (!empty($formData['inn'])) {
            $inn = preg_replace('/\D/', '', $formData['inn']);
            if (strlen($inn) != 10 && strlen($inn) != 12) {
                $errors['inn'] = 'ИНН должен содержать 10 или 12 цифр';
            }
            $formData['inn'] = $inn;
        }
        
        if (!empty($formData['kpp'])) {
            $kpp = preg_replace('/\D/', '', $formData['kpp']);
            if (strlen($kpp) != 9) {
                $errors['kpp'] = 'КПП должен содержать 9 цифр';
            }
            $formData['kpp'] = $kpp;
        }
        
        if (!empty($formData['ogrn'])) {
            $ogrn = preg_replace('/\D/', '', $formData['ogrn']);
            if (strlen($ogrn) != 13 && strlen($ogrn) != 15) {
                $errors['ogrn'] = 'ОГРН должен содержать 13 или 15 цифр';
            }
            $formData['ogrn'] = $ogrn;
        }
        
        if (!empty($formData['bik'])) {
            $bik = preg_replace('/\D/', '', $formData['bik']);
            if (strlen($bik) != 9) {
                $errors['bik'] = 'БИК должен содержать 9 цифр';
            }
            $formData['bik'] = $bik;
        }
        
        if (empty($errors)) {
            try {
                // Проверяем уникальность названия (исключая текущую компанию)
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM companies WHERE name = ? AND id != ? AND status != -1");
                $stmt->execute([$formData['name'], $companyId]);
                if ($stmt->fetchColumn() > 0) {
                    $errors['name'] = 'Компания с таким названием уже существует';
                } else {
                    // Обновляем компанию
                    $stmt = $pdo->prepare("
                        UPDATE companies SET
                            name = ?, legal_address = ?, postal_address = ?, phone = ?, director = ?, email = ?,
                            inn = ?, kpp = ?, ogrn = ?, bank = ?, account = ?, correspondent_account = ?, bik = ?, status = ?
                        WHERE id = ?
                    ");
                    
                    $stmt->execute([
                        $formData['name'],
                        $formData['legal_address'] ?: null,
                        $formData['postal_address'] ?: null,
                        $formData['phone'] ?: null,
                        $formData['director'] ?: null,
                        $formData['email'] ?: null,
                        $formData['inn'] ?: null,
                        $formData['kpp'] ?: null,
                        $formData['ogrn'] ?: null,
                        $formData['bank'] ?: null,
                        $formData['account'] ?: null,
                        $formData['correspondent_account'] ?: null,
                        $formData['bik'] ?: null,
                        $formData['status'],
                        $companyId
                    ]);
                    
                    // Логируем изменение
                    logError('Company updated', [
                        'company_id' => $companyId,
                        'company_name' => $formData['name'],
                        'updated_by' => getCurrentUser()['id']
                    ]);
                    
                    $_SESSION['success_message'] = 'Компания "' . $formData['name'] . '" успешно обновлена';
                    header('Location: /pages/companies/view.php?id=' . $companyId);
                    exit;
                }
                
            } catch (Exception $e) {
                logError('Update company error: ' . $e->getMessage(), array_merge($formData, ['company_id' => $companyId]));
                $errors['general'] = 'Произошла ошибка при обновлении компании';
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
                <li class="breadcrumb-item active">Редактирование</li>
            </ol>
        </nav>
        <h1 class="h3 mb-0"><?= e($pageTitle) ?></h1>
    </div>
    <div>
        <a href="/pages/companies/view.php?id=<?= $companyId ?>" class="btn btn-outline-secondary">
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
                    <i class="bi bi-building"></i>
                    Основная информация
                </h5>
            </div>
            <div class="card-body">
                <form method="POST" action="" novalidate>
                    <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                    
                    <!-- Статус компании -->
                    <div class="mb-3">
                        <label for="status" class="form-label">Статус компании</label>
                        <select class="form-select" id="status" name="status">
                            <option value="1" <?= $formData['status'] == 1 ? 'selected' : '' ?>>Активна</option>
                            <option value="0" <?= $formData['status'] == 0 ? 'selected' : '' ?>>Заблокирована</option>
                            <option value="-1" <?= $formData['status'] == -1 ? 'selected' : '' ?>>Архивирована</option>
                        </select>
                        <div class="form-text">
                            Архивированные компании скрыты из основного списка
                        </div>
                    </div>
                    
                    <!-- Название компании -->
                    <div class="mb-3">
                        <label for="name" class="form-label">
                            Название компании <span class="text-danger">*</span>
                        </label>
                        <input type="text" 
                               class="form-control <?= isset($errors['name']) ? 'is-invalid' : '' ?>" 
                               id="name" 
                               name="name" 
                               value="<?= e($formData['name']) ?>"
                               maxlength="100"
                               required>
                        <?php if (isset($errors['name'])): ?>
                        <div class="invalid-feedback"><?= e($errors['name']) ?></div>
                        <?php endif; ?>
                    </div>
                    
                    <div class="row">
                        <!-- Юридический адрес -->
                        <div class="col-md-6 mb-3">
                            <label for="legal_address" class="form-label">Юридический адрес</label>
                            <textarea class="form-control" 
                                      id="legal_address" 
                                      name="legal_address" 
                                      rows="3"><?= e($formData['legal_address']) ?></textarea>
                        </div>
                        
                        <!-- Почтовый адрес -->
                        <div class="col-md-6 mb-3">
                            <label for="postal_address" class="form-label">Почтовый адрес</label>
                            <textarea class="form-control" 
                                      id="postal_address" 
                                      name="postal_address" 
                                      rows="3"><?= e($formData['postal_address']) ?></textarea>
                            <div class="form-text">
                                <a href="#" onclick="copyLegalAddress()">Скопировать из юридического адреса</a>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <!-- Телефон -->
                        <div class="col-md-6 mb-3">
                            <label for="phone" class="form-label">Телефон/Факс</label>
                            <input type="tel" 
                                   class="form-control" 
                                   id="phone" 
                                   name="phone" 
                                   value="<?= e($formData['phone']) ?>">
                        </div>
                        
                        <!-- Email -->
                        <div class="col-md-6 mb-3">
                            <label for="email" class="form-label">Email</label>
                            <input type="email" 
                                   class="form-control <?= isset($errors['email']) ? 'is-invalid' : '' ?>" 
                                   id="email" 
                                   name="email" 
                                   value="<?= e($formData['email']) ?>">
                            <?php if (isset($errors['email'])): ?>
                            <div class="invalid-feedback"><?= e($errors['email']) ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <!-- Генеральный директор -->
                    <div class="mb-3">
                        <label for="director" class="form-label">Генеральный директор</label>
                        <input type="text" 
                               class="form-control" 
                               id="director" 
                               name="director" 
                               value="<?= e($formData['director']) ?>">
                    </div>
                    
                    <hr>
                    
                    <h6 class="mb-3">Реквизиты</h6>
                    
                    <div class="row">
                        <!-- ИНН -->
                        <div class="col-md-4 mb-3">
                            <label for="inn" class="form-label">ИНН</label>
                            <input type="text" 
                                   class="form-control <?= isset($errors['inn']) ? 'is-invalid' : '' ?>" 
                                   id="inn" 
                                   name="inn" 
                                   value="<?= e($formData['inn']) ?>"
                                   maxlength="12">
                            <?php if (isset($errors['inn'])): ?>
                            <div class="invalid-feedback"><?= e($errors['inn']) ?></div>
                            <?php endif; ?>
                        </div>
                        
                        <!-- КПП -->
                        <div class="col-md-4 mb-3">
                            <label for="kpp" class="form-label">КПП</label>
                            <input type="text" 
                                   class="form-control <?= isset($errors['kpp']) ? 'is-invalid' : '' ?>" 
                                   id="kpp" 
                                   name="kpp" 
                                   value="<?= e($formData['kpp']) ?>"
                                   maxlength="9">
                            <?php if (isset($errors['kpp'])): ?>
                            <div class="invalid-feedback"><?= e($errors['kpp']) ?></div>
                            <?php endif; ?>
                        </div>
                        
                        <!-- ОГРН -->
                        <div class="col-md-4 mb-3">
                            <label for="ogrn" class="form-label">ОГРН</label>
                            <input type="text" 
                                   class="form-control <?= isset($errors['ogrn']) ? 'is-invalid' : '' ?>" 
                                   id="ogrn" 
                                   name="ogrn" 
                                   value="<?= e($formData['ogrn']) ?>"
                                   maxlength="15">
                            <?php if (isset($errors['ogrn'])): ?>
                            <div class="invalid-feedback"><?= e($errors['ogrn']) ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <!-- Банк -->
                    <div class="mb-3">
                        <label for="bank" class="form-label">Банк</label>
                        <input type="text" 
                               class="form-control" 
                               id="bank" 
                               name="bank" 
                               value="<?= e($formData['bank']) ?>">
                    </div>
                    
                    <div class="row">
                        <!-- Расчетный счет -->
                        <div class="col-md-4 mb-3">
                            <label for="account" class="form-label">Расчетный счет</label>
                            <input type="text" 
                                   class="form-control" 
                                   id="account" 
                                   name="account" 
                                   value="<?= e($formData['account']) ?>"
                                   maxlength="20">
                        </div>
                        
                        <!-- Корреспондентский счет -->
                        <div class="col-md-4 mb-3">
                            <label for="correspondent_account" class="form-label">Корреспондентский счет</label>
                            <input type="text" 
                                   class="form-control" 
                                   id="correspondent_account" 
                                   name="correspondent_account" 
                                   value="<?= e($formData['correspondent_account']) ?>"
                                   maxlength="20">
                        </div>
                        
                        <!-- БИК -->
                        <div class="col-md-4 mb-3">
                            <label for="bik" class="form-label">БИК</label>
                            <input type="text" 
                                   class="form-control <?= isset($errors['bik']) ? 'is-invalid' : '' ?>" 
                                   id="bik" 
                                   name="bik" 
                                   value="<?= e($formData['bik']) ?>"
                                   maxlength="9">
                            <?php if (isset($errors['bik'])): ?>
                            <div class="invalid-feedback"><?= e($errors['bik']) ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-check-circle"></i>
                            Сохранить изменения
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
                    Информация об изменениях
                </h6>
            </div>
            <div class="card-body">
                <div class="detail-row">
                    <div class="detail-label">Создана:</div>
                    <div class="detail-value"><?= formatDate($company['created_at']) ?></div>
                </div>
                
                <div class="detail-row">
                    <div class="detail-label">Последнее изменение:</div>
                    <div class="detail-value"><?= formatDate($company['updated_at']) ?></div>
                </div>
                
                <hr>
                
                <h6>Статусы компании</h6>
                <ul class="small">
                    <li><strong>Активна</strong> - компания доступна для работы</li>
                    <li><strong>Заблокирована</strong> - временно недоступна</li>
                    <li><strong>Архивирована</strong> - скрыта из основного списка</li>
                </ul>
                
                <div class="alert alert-warning small mt-3">
                    <i class="bi bi-exclamation-triangle"></i>
                    При архивировании компании будут скрыты все связанные данные: сотрудники, склады, товары.
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// Функции из create.php
function copyLegalAddress() {
    event.preventDefault();
    const legalAddress = document.getElementById('legal_address').value;
    document.getElementById('postal_address').value = legalAddress;
}

document.addEventListener('DOMContentLoaded', function() {
    // Форматирование полей (аналогично create.php)
    ['inn', 'kpp', 'ogrn', 'bik', 'account', 'correspondent_account'].forEach(fieldId => {
        const field = document.getElementById(fieldId);
        if (field) {
            field.addEventListener('input', function(e) {
                e.target.value = e.target.value.replace(/\D/g, '');
            });
        }
    });
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>