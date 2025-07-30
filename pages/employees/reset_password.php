<?php
/**
 * Сброс пароля сотрудника (заглушка)
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

try {
    $pdo = getDBConnection();
    
    // Получаем информацию о сотруднике
    $stmt = $pdo->prepare("SELECT login, first_name, last_name FROM users WHERE id = ?");
    $stmt->execute([$employeeId]);
    $employee = $stmt->fetch();
    
    if (!$employee) {
        $_SESSION['error_message'] = 'Сотрудник не найден';
        header('Location: /pages/employees/index.php');
        exit;
    }
    
} catch (Exception $e) {
    logError('Reset password error: ' . $e->getMessage(), ['employee_id' => $employeeId]);
    $_SESSION['error_message'] = 'Произошла ошибка при загрузке данных';
    header('Location: /pages/employees/index.php');
    exit;
}

$pageTitle = 'Сброс пароля';

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
                        <?= e(trim($employee['first_name'] . ' ' . $employee['last_name'])) ?>
                    </a>
                </li>
                <li class="breadcrumb-item active"><?= e($pageTitle) ?></li>
            </ol>
        </nav>
        <h1 class="h3 mb-0"><?= e($pageTitle) ?></h1>
        <p class="text-muted mb-0">Сотрудник: <?= e(trim($employee['first_name'] . ' ' . $employee['last_name'])) ?></p>
    </div>
    <div>
        <a href="/pages/employees/view.php?id=<?= $employeeId ?>" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left"></i>
            Назад
        </a>
    </div>
</div>

<div class="card">
    <div class="card-body text-center py-5">
        <i class="bi bi-key text-muted" style="font-size: 4rem;"></i>
        <h4 class="mt-3">Функция в разработке</h4>
        <p class="text-muted">
            Сброс паролей будет доступен в следующих версиях системы.<br>
            Пока что можно создать нового пользователя с новым паролем.
        </p>
        <div class="d-flex gap-2 justify-content-center">
            <a href="/pages/employees/view.php?id=<?= $employeeId ?>" class="btn btn-primary">
                <i class="bi bi-arrow-left"></i>
                Вернуться к сотруднику
            </a>
            <a href="/pages/employees/create.php" class="btn btn-outline-primary">
                <i class="bi bi-person-plus"></i>
                Создать нового пользователя
            </a>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>