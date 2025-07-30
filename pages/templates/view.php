<?php
/**
 * Просмотр шаблона товара (заглушка)
 * Система складского учета (SUT)
 */

require_once __DIR__ . '/../../config/config.php';

requireAccess('product_templates');

$templateId = (int)($_GET['id'] ?? 0);

if (!$templateId) {
    $_SESSION['error_message'] = 'Шаблон не найден';
    header('Location: /pages/templates/index.php');
    exit;
}

try {
    $pdo = getDBConnection();
    
    // Получаем информацию о шаблоне
    $stmt = $pdo->prepare("
        SELECT pt.*, u.first_name, u.last_name
        FROM product_templates pt
        LEFT JOIN users u ON pt.created_by = u.id
        WHERE pt.id = ?
    ");
    $stmt->execute([$templateId]);
    $template = $stmt->fetch();
    
    if (!$template) {
        $_SESSION['error_message'] = 'Шаблон не найден';
        header('Location: /pages/templates/index.php');
        exit;
    }
    
} catch (Exception $e) {
    logError('Template view error: ' . $e->getMessage(), ['template_id' => $templateId]);
    $_SESSION['error_message'] = 'Произошла ошибка при загрузке данных';
    header('Location: /pages/templates/index.php');
    exit;
}

$pageTitle = $template['name'];

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
        <a href="/pages/templates/edit.php?id=<?= $templateId ?>" class="btn btn-primary">
            <i class="bi bi-pencil"></i>
            Редактировать
        </a>
        <a href="/pages/templates/index.php" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left"></i>
            Назад
        </a>
    </div>
</div>

<div class="card">
    <div class="card-body text-center py-5">
        <i class="bi bi-tools text-muted" style="font-size: 4rem;"></i>
        <h4 class="mt-3">Функция в разработке</h4>
        <p class="text-muted">
            Детальный просмотр шаблонов будет доступен в следующих версиях системы.
        </p>
        <a href="/pages/templates/edit.php?id=<?= $templateId ?>" class="btn btn-primary">
            <i class="bi bi-pencil"></i>
            Перейти к редактированию
        </a>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>