<?php
/**
 * Просмотр запроса
 * Система складского учета (SUT)
 */

require_once __DIR__ . '/../../config/config.php';

requireAccess('requests');

$user = getCurrentUser();
$requestId = (int)($_GET['id'] ?? 0);

if ($requestId <= 0) {
    header('Location: /pages/requests/index.php');
    exit;
}

try {
    $pdo = getDBConnection();
    
    // Получаем данные запроса
    $stmt = $pdo->prepare("
        SELECT 
            r.*,
            pt.name as template_name,
            pt.description as template_description,
            pt.formula as template_formula,
            u.first_name, u.last_name, u.role as creator_role,
            w.name as warehouse_name,
            c.name as company_name,
            processor.first_name as processor_first_name,
            processor.last_name as processor_last_name
        FROM requests r
        LEFT JOIN product_templates pt ON r.template_id = pt.id
        LEFT JOIN users u ON r.created_by = u.id
        LEFT JOIN warehouses w ON r.warehouse_id = w.id
        LEFT JOIN companies c ON w.company_id = c.id
        LEFT JOIN users processor ON r.processed_by = processor.id
        WHERE r.id = ?
    ");
    $stmt->execute([$requestId]);
    $request = $stmt->fetch();
    
    if (!$request) {
        $_SESSION['error_message'] = 'Запрос не найден';
        header('Location: /pages/requests/index.php');
        exit;
    }
    
    // Проверяем права доступа
    $canView = false;
    if ($user['role'] === ROLE_ADMIN) {
        $canView = true;
    } elseif ($user['role'] === ROLE_SALES_MANAGER) {
        $canView = true; // Менеджер по продажам видит все запросы
    } elseif ($user['role'] === ROLE_WAREHOUSE_WORKER) {
        $canView = ($request['created_by'] == $user['id']); // Работник склада видит только свои запросы
    }
    
    if (!$canView) {
        header('Location: /pages/errors/403.php');
        exit;
    }
    
    // Получаем атрибуты шаблона для отображения
    $templateAttributes = [];
    if ($request['template_id']) {
        $stmt = $pdo->prepare("
            SELECT * FROM template_attributes 
            WHERE template_id = ? 
            ORDER BY sort_order, id
        ");
        $stmt->execute([$request['template_id']]);
        $templateAttributes = $stmt->fetchAll();
    }
    
    // Декодируем запрашиваемые атрибуты
    $requestedAttributes = [];
    if ($request['requested_attributes']) {
        $requestedAttributes = json_decode($request['requested_attributes'], true) ?: [];
    }
    
    $pageTitle = 'Запрос #' . $request['id'];
    
} catch (Exception $e) {
    logError('Request view error: ' . $e->getMessage());
    $_SESSION['error_message'] = 'Произошла ошибка при загрузке запроса';
    header('Location: /pages/requests/index.php');
    exit;
}

// Обработка успешного сообщения
$showSuccess = isset($_GET['success']) && $_GET['success'] == '1';

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item">
                    <a href="/pages/requests/index.php">Запросы</a>
                </li>
                <li class="breadcrumb-item active"><?= e($pageTitle) ?></li>
            </ol>
        </nav>
        <h1 class="h3 mb-0"><?= e($pageTitle) ?></h1>
    </div>
    <div>
        <a href="/pages/requests/index.php" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left"></i>
            Назад к списку
        </a>
    </div>
</div>

<?php if ($showSuccess): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <h4 class="alert-heading">
            <i class="fas fa-check-circle me-2"></i>Статус запроса изменен!
        </h4>
        <p class="mb-0">
            Запрос был успешно <?= $request['status'] === 'processed' ? 'обработан' : 'возвращен в обработку' ?>.
        </p>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<div class="row">
    <div class="col-lg-8">
        <!-- Основная информация -->
        <div class="card mb-4">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="card-title mb-0">
                    <i class="bi bi-info-circle"></i>
                    Информация о запросе
                </h5>
                <div>
                    <?php if ($request['status'] === 'processed'): ?>
                        <span class="badge bg-success fs-6">Обработан</span>
                    <?php else: ?>
                        <span class="badge bg-danger fs-6">Не обработан</span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-6">
                        <h6 class="text-muted">Дата запроса</h6>
                        <p><?= date('d.m.Y H:i', strtotime($request['created_at'])) ?></p>
                    </div>
                    <div class="col-md-6">
                        <h6 class="text-muted">Сотрудник</h6>
                        <p>
                            <?= e($request['first_name'] . ' ' . $request['last_name']) ?>
                            <br><small class="text-muted">
                                <?php
                                $roleNames = [
                                    ROLE_WAREHOUSE_WORKER => 'Работник склада',
                                    ROLE_SALES_MANAGER => 'Менеджер по продажам'
                                ];
                                echo $roleNames[$request['creator_role']] ?? $request['creator_role'];
                                ?>
                            </small>
                        </p>
                    </div>
                </div>
                
                <div class="row">
                    <div class="col-md-6">
                        <h6 class="text-muted">Тип товара</h6>
                        <p>
                            <strong><?= e($request['template_name']) ?></strong>
                            <?php if ($request['template_description']): ?>
                                <br><small class="text-muted"><?= e($request['template_description']) ?></small>
                            <?php endif; ?>
                        </p>
                    </div>
                    <div class="col-md-6">
                        <h6 class="text-muted">Склад</h6>
                        <p>
                            <?= e($request['warehouse_name']) ?>
                            <?php if ($request['company_name']): ?>
                                <br><small class="text-muted"><?= e($request['company_name']) ?></small>
                            <?php endif; ?>
                        </p>
                    </div>
                </div>
                
                <div class="row">
                    <div class="col-md-6">
                        <h6 class="text-muted">Количество</h6>
                        <p>
                            <span class="badge bg-info fs-6">
                                <?= number_format($request['quantity'], 3) ?>
                            </span>
                        </p>
                    </div>
                    <div class="col-md-6">
                        <h6 class="text-muted">Желаемая дата поставки</h6>
                        <p>
                            <?= $request['delivery_date'] ? date('d.m.Y', strtotime($request['delivery_date'])) : 'Не указана' ?>
                        </p>
                    </div>
                </div>
                
                <?php if ($request['description']): ?>
                    <div class="row">
                        <div class="col-12">
                            <h6 class="text-muted">Описание/комментарий</h6>
                            <p><?= nl2br(e($request['description'])) ?></p>
                        </div>
                    </div>
                <?php endif; ?>
                
                <?php if ($request['status'] === 'processed'): ?>
                    <div class="row">
                        <div class="col-md-6">
                            <h6 class="text-muted">Обработал</h6>
                            <p>
                                <?= e($request['processor_first_name'] . ' ' . $request['processor_last_name']) ?>
                            </p>
                        </div>
                        <div class="col-md-6">
                            <h6 class="text-muted">Дата обработки</h6>
                            <p><?= date('d.m.Y H:i', strtotime($request['processed_at'])) ?></p>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Характеристики товара -->
        <?php if (!empty($templateAttributes)): ?>
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="card-title mb-0">
                        <i class="bi bi-list-ul"></i>
                        Характеристики товара
                    </h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <?php foreach ($templateAttributes as $attr): ?>
                            <div class="col-md-6 mb-3">
                                <h6 class="text-muted">
                                    <?= e($attr['name']) ?>
                                    <?php if ($attr['unit']): ?>
                                        <small class="text-muted">(<?= e($attr['unit']) ?>)</small>
                                    <?php endif; ?>
                                </h6>
                                <p>
                                    <?php
                                    $value = $requestedAttributes[$attr['variable']] ?? '';
                                    if ($value !== '') {
                                        echo e($value);
                                    } else {
                                        echo '<span class="text-muted">Не указано</span>';
                                    }
                                    ?>
                                </p>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>
    
    <div class="col-lg-4">
        <!-- Действия -->
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="card-title mb-0">
                    <i class="bi bi-gear"></i>
                    Действия
                </h5>
            </div>
            <div class="card-body">
                <div class="d-grid gap-2">
                    <?php if ($request['status'] === 'pending'): ?>
                        <?php if (($user['role'] === ROLE_WAREHOUSE_WORKER || $user['role'] === ROLE_SALES_MANAGER) && $request['created_by'] == $user['id']): ?>
                            <a href="/pages/requests/edit.php?id=<?= $request['id'] ?>" class="btn btn-outline-primary">
                                <i class="bi bi-pencil"></i>
                                Редактировать запрос
                            </a>
                        <?php endif; ?>
                        
                        <?php if ($user['role'] === ROLE_ADMIN): ?>
                            <form method="POST" action="/pages/requests/process.php">
                                <?= generateCSRFToken() ?>
                                <input type="hidden" name="request_id" value="<?= $request['id'] ?>">
                                <input type="hidden" name="action" value="process">
                                <button type="submit" class="btn btn-success">
                                    <i class="bi bi-check-circle"></i>
                                    Запрос обработан
                                </button>
                            </form>
                        <?php endif; ?>
                    <?php else: ?>
                        <?php if ($user['role'] === ROLE_ADMIN): ?>
                            <form method="POST" action="/pages/requests/process.php">
                                <?= generateCSRFToken() ?>
                                <input type="hidden" name="request_id" value="<?= $request['id'] ?>">
                                <input type="hidden" name="action" value="unprocess">
                                <button type="submit" class="btn btn-warning">
                                    <i class="bi bi-arrow-counterclockwise"></i>
                                    Запрос не обработан
                                </button>
                            </form>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <!-- Информация о шаблоне -->
        <?php if ($request['template_formula']): ?>
            <div class="card">
                <div class="card-header">
                    <h5 class="card-title mb-0">
                        <i class="bi bi-calculator"></i>
                        Формула расчета
                    </h5>
                </div>
                <div class="card-body">
                    <code><?= e($request['template_formula']) ?></code>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>