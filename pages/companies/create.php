<?php
/**
 * Добавление компании
 * Система складского учета (SUT)
 */

require_once __DIR__ . '/../../config/config.php';

requireAccess('companies');

$pageTitle = 'Добавление компании';

$errors = [];
$formData = [
    'name' => '',
    'legal_address' => '',
    'postal_address' => '',
    'phone' => '',
    'director' => '',
    'email' => '',
    'inn' => '',
    'kpp' => '',
    'ogrn' => '',
    'bank' => '',
    'account' => '',
    'correspondent_account' => '',
    'bik' => ''
];

// Обработка формы
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf_token = $_POST['csrf_token'] ?? '';
    
    if (!verifyCSRFToken($csrf_token)) {
        $errors['csrf'] = 'Ошибка безопасности';
    } else {
        // Получаем данные формы
        foreach ($formData as $field => $default) {
            $formData[$field] = trim($_POST[$field] ?? $default);
        }
        
        // Валидация
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
                $pdo = getDBConnection();
                
                // Проверяем уникальность названия
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM companies WHERE name = ? AND status != -1");
                $stmt->execute([$formData['name']]);
                if ($stmt->fetchColumn() > 0) {
                    $errors['name'] = 'Компания с таким названием уже существует';
                } else {
                    // Создаем компанию
                    $stmt = $pdo->prepare("
                        INSERT INTO companies (
                            name, legal_address, postal_address, phone, director, email,
                            inn, kpp, ogrn, bank, account, correspondent_account, bik, status
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)
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
                        $formData['bik'] ?: null
                    ]);
                    
                    $companyId = $pdo->lastInsertId();
                    
                    // Логируем создание
                    logError('Company created', [
                        'company_id' => $companyId,
                        'company_name' => $formData['name'],
                        'created_by' => getCurrentUser()['id']
                    ]);
                    
                    $_SESSION['success_message'] = 'Компания "' . $formData['name'] . '" успешно создана';
                    header('Location: /pages/companies/view.php?id=' . $companyId);
                    exit;
                }
                
            } catch (Exception $e) {
                logError('Create company error: ' . $e->getMessage(), $formData);
                $errors['general'] = 'Произошла ошибка при создании компании';
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
                <li class="breadcrumb-item active"><?= e($pageTitle) ?></li>
            </ol>
        </nav>
        <h1 class="h3 mb-0"><?= e($pageTitle) ?></h1>
    </div>
    <div>
        <a href="/pages/companies/index.php" class="btn btn-outline-secondary">
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
                    <i class="bi bi-building"></i>
                    Основная информация
                </h5>
            </div>
            <div class="card-body">
                <form method="POST" action="" novalidate>
                    <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                    
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
                        <div class="form-text">Не более 100 символов</div>
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
                                   value="<?= e($formData['phone']) ?>"
                                   placeholder="+7 (999) 999-99-99">
                        </div>
                        
                        <!-- Email -->
                        <div class="col-md-6 mb-3">
                            <label for="email" class="form-label">Email</label>
                            <input type="email" 
                                   class="form-control <?= isset($errors['email']) ? 'is-invalid' : '' ?>" 
                                   id="email" 
                                   name="email" 
                                   value="<?= e($formData['email']) ?>"
                                   placeholder="example@company.ru">
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
                               value="<?= e($formData['director']) ?>"
                               placeholder="Иванов Иван Иванович">
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
                                   placeholder="1234567890"
                                   maxlength="12">
                            <?php if (isset($errors['inn'])): ?>
                            <div class="invalid-feedback"><?= e($errors['inn']) ?></div>
                            <?php endif; ?>
                            <div class="form-text">10 или 12 цифр</div>
                        </div>
                        
                        <!-- КПП -->
                        <div class="col-md-4 mb-3">
                            <label for="kpp" class="form-label">КПП</label>
                            <input type="text" 
                                   class="form-control <?= isset($errors['kpp']) ? 'is-invalid' : '' ?>" 
                                   id="kpp" 
                                   name="kpp" 
                                   value="<?= e($formData['kpp']) ?>"
                                   placeholder="123456789"
                                   maxlength="9">
                            <?php if (isset($errors['kpp'])): ?>
                            <div class="invalid-feedback"><?= e($errors['kpp']) ?></div>
                            <?php endif; ?>
                            <div class="form-text">9 цифр</div>
                        </div>
                        
                        <!-- ОГРН -->
                        <div class="col-md-4 mb-3">
                            <label for="ogrn" class="form-label">ОГРН</label>
                            <input type="text" 
                                   class="form-control <?= isset($errors['ogrn']) ? 'is-invalid' : '' ?>" 
                                   id="ogrn" 
                                   name="ogrn" 
                                   value="<?= e($formData['ogrn']) ?>"
                                   placeholder="1234567890123"
                                   maxlength="15">
                            <?php if (isset($errors['ogrn'])): ?>
                            <div class="invalid-feedback"><?= e($errors['ogrn']) ?></div>
                            <?php endif; ?>
                            <div class="form-text">13 или 15 цифр</div>
                        </div>
                    </div>
                    
                    <!-- Банк -->
                    <div class="mb-3">
                        <label for="bank" class="form-label">Банк</label>
                        <input type="text" 
                               class="form-control" 
                               id="bank" 
                               name="bank" 
                               value="<?= e($formData['bank']) ?>"
                               placeholder="ПАО Сбербанк России">
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
                                   placeholder="40702810123456789012"
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
                                   placeholder="30101810400000000225"
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
                                   placeholder="044525225"
                                   maxlength="9">
                            <?php if (isset($errors['bik'])): ?>
                            <div class="invalid-feedback"><?= e($errors['bik']) ?></div>
                            <?php endif; ?>
                            <div class="form-text">9 цифр</div>
                        </div>
                    </div>
                    
                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-check-circle"></i>
                            Создать компанию
                        </button>
                        <a href="/pages/companies/index.php" class="btn btn-outline-secondary">
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
                <h6>Обязательные поля</h6>
                <ul class="small">
                    <li><strong>Название компании</strong> - основное наименование организации</li>
                </ul>
                
                <h6 class="mt-3">Реквизиты</h6>
                <ul class="small">
                    <li><strong>ИНН</strong> - 10 цифр для организаций, 12 для ИП</li>
                    <li><strong>КПП</strong> - 9 цифр, код причины постановки на учет</li>
                    <li><strong>ОГРН</strong> - 13 цифр для организаций, 15 для ИП</li>
                    <li><strong>БИК</strong> - 9 цифр, банковский идентификационный код</li>
                </ul>
                
                <h6 class="mt-3">После создания</h6>
                <p class="small">
                    После создания компании вы сможете добавить склады и назначить сотрудников для работы с ними.
                </p>
            </div>
        </div>
    </div>
</div>

<script>
// Копирование юридического адреса в почтовый
function copyLegalAddress() {
    event.preventDefault();
    const legalAddress = document.getElementById('legal_address').value;
    document.getElementById('postal_address').value = legalAddress;
}

// Форматирование полей ввода
document.addEventListener('DOMContentLoaded', function() {
    // Форматирование телефона
    const phoneInput = document.getElementById('phone');
    phoneInput.addEventListener('input', function(e) {
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
    
    // Только цифры для реквизитов
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