<?php
/**
 * Редактирование шаблона товара (заглушка)
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
    $stmt = $pdo->prepare("SELECT * FROM product_templates WHERE id = ?");
    $stmt->execute([$templateId]);
    $template = $stmt->fetch();
    
    if (!$template) {
        $_SESSION['error_message'] = 'Шаблон не найден';
        header('Location: /pages/templates/index.php');
        exit;
    }
    
} catch (Exception $e) {
    logError('Template edit error: ' . $e->getMessage(), ['template_id' => $templateId]);
    $_SESSION['error_message'] = 'Произошла ошибка при загрузке данных';
    header('Location: /pages/templates/index.php');
    exit;
}

$pageTitle = 'Редактирование шаблона';

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item">
                    <a href="/pages/templates/index.php">Характеристики товара</a>
                </li>
                <li class="breadcrumb-item">
                    <a href="/pages/templates/view.php?id=<?= $templateId ?>">
                        <?= e($template['name']) ?>
                    </a>
                </li>
                <li class="breadcrumb-item active"><?= e($pageTitle) ?></li>
            </ol>
        </nav>
        <h1 class="h3 mb-0"><?= e($pageTitle) ?></h1>
        <p class="text-muted mb-0">Шаблон: <?= e($template['name']) ?></p>
    </div>
    <div>
        <a href="/pages/templates/view.php?id=<?= $templateId ?>" class="btn btn-outline-secondary">
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
            Редактирование шаблонов с характеристиками будет доступно в следующих версиях системы.
        </p>
        <a href="/pages/templates/view.php?id=<?= $templateId ?>" class="btn btn-primary">
            <i class="bi bi-arrow-left"></i>
            Вернуться к шаблону
        </a>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>