<?php
require_once '../../config/config.php';
require_once '../../includes/functions.php';

// Проверяем авторизацию и права доступа
if (!isLoggedIn()) {
    redirect('/pages/auth/login.php');
}

// Проверяем права доступа к разделу товаров в пути
if (!hasAccessToSection('goods_in_transit', 'edit')) {
    redirect('/pages/errors/403.php');
}

$currentUser = getCurrentUser();
$transitId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$transitId) {
    redirect('index.php');
}

$pageTitle = 'Редактировать товар в пути';
$errors = [];
$success = false;

try {
    $pdo = getDBConnection();
    
    // Получаем информацию о товаре в пути
    $transitQuery = "
        SELECT git.*
        FROM goods_in_transit git
        WHERE git.id = ? AND git.status != 'confirmed'
    ";
    
    // Ограичиваем доступ для работника склада
    if ($currentUser['role'] !== 'admin' && $currentUser['warehouse_id']) {
        $transitQuery .= " AND git.warehouse_id = " . $currentUser['warehouse_id'];
    }
    
    $stmt = $pdo->prepare($transitQuery);
    $stmt->execute([$transitId]);
    $transit = $stmt->fetch();
    
    if (!$transit) {
        redirect('index.php');
    }
    
    // Декодируем информацию о товарах и файлах
    $goodsInfo = json_decode($transit['goods_info'], true) ?: [];
    $existingFiles = json_decode($transit['files'], true) ?: [];
    
    // Получаем список складов
    $warehousesQuery = "
        SELECT w.id, w.name, c.name as company_name
        FROM warehouses w 
        JOIN companies c ON w.company_id = c.id 
        WHERE w.status = 1
    ";
    
    // Ограичиваем склады для работника склада
    if ($currentUser['role'] !== 'admin' && $currentUser['warehouse_id']) {
        $warehousesQuery .= " AND w.id = " . $currentUser['warehouse_id'];
    }
    
    $warehousesQuery .= " ORDER BY c.name, w.name";
    
    $stmt = $pdo->prepare($warehousesQuery);
    $stmt->execute();
    $warehouses = $stmt->fetchAll();
    
    // Получаем список шаблонов товаров
    $templatesQuery = "
        SELECT id, name, unit 
        FROM product_templates 
        WHERE status = 1 
        ORDER BY name
    ";
    $stmt = $pdo->prepare($templatesQuery);
    $stmt->execute();
    $templates = $stmt->fetchAll();
    
} catch (Exception $e) {
    logError('Transit edit page error: ' . $e->getMessage());
    redirect('index.php');
}

// Обработка формы
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $departureLocation = trim($_POST['departure_location'] ?? '');
    $departureDate = trim($_POST['departure_date'] ?? '');
    $arrivalDate = trim($_POST['arrival_date'] ?? '');
    $warehouseId = (int)($_POST['warehouse_id'] ?? 0);
    $goodsInfo = $_POST['goods_info'] ?? [];
    $filesToDelete = $_POST['delete_files'] ?? [];
    
    // Валидация основных полей
    if (empty($departureLocation)) {
        $errors[] = 'Место отгрузки обязательно для заполнения';
    }
    
    if (empty($departureDate)) {
        $errors[] = 'Дата отгрузки обязательна для заполнения';
    }
    
    if ($warehouseId <= 0) {
        $errors[] = 'Выберите склад назначения';
    }
    
    // Проверяем даты (только если обе даты заполнены)
    if (!empty($departureDate) && !empty($arrivalDate)) {
        if (strtotime($arrivalDate) < strtotime($departureDate)) {
            $errors[] = 'Дата поступления не может быть раньше даты отгрузки';
        }
    }
    
    // Валидация информации о грузе
    $validGoodsInfo = [];
    if (!empty($goodsInfo)) {
        foreach ($goodsInfo as $index => $goodItem) {
            if (empty($goodItem['template_id']) || empty($goodItem['quantity'])) {
                continue; // Пропускаем пустые строки
            }
            
            $templateId = (int)$goodItem['template_id'];
            $quantity = (float)$goodItem['quantity'];
            $attributes = $goodItem['attributes'] ?? [];
            
            if ($templateId <= 0) {
                $errors[] = "Товар #" . ($index + 1) . ": выберите тип товара";
                continue;
            }
            
            if ($quantity <= 0) {
                $errors[] = "Товар #" . ($index + 1) . ": количество должно быть больше 0";
                continue;
            }
            
            $validGoodsInfo[] = [
                'template_id' => $templateId,
                'quantity' => $quantity,
                'attributes' => $attributes
            ];
        }
    }
    
    if (empty($validGoodsInfo)) {
        $errors[] = 'Добавьте хотя бы один товар в груз';
    }
    
    // Проверяем доступ к складу
    if ($warehouseId > 0 && $currentUser['role'] !== 'admin' && $currentUser['warehouse_id']) {
        if ($warehouseId != $currentUser['warehouse_id']) {
            $errors[] = 'У вас нет доступа к выбранному складу';
        }
    }
    
    // Обработка новых файлов
    $newUploadedFiles = [];
    if (!empty($_FILES['files']['name'][0])) {
        $allowedExtensions = ['jpg', 'jpeg', 'png', 'pdf', 'doc', 'docx', 'xls', 'xlsx', 'txt', 'odt', 'rtf'];
        $maxFileSize = 10 * 1024 * 1024; // 10MB
        
        foreach ($_FILES['files']['name'] as $key => $fileName) {
            if ($_FILES['files']['error'][$key] !== UPLOAD_ERR_OK) {
                continue;
            }
            
            $fileSize = $_FILES['files']['size'][$key];
            $fileTmpName = $_FILES['files']['tmp_name'][$key];
            $fileExtension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
            
            // Проверяем расширение
            if (!in_array($fileExtension, $allowedExtensions)) {
                $errors[] = "Файл '$fileName': недопустимый формат. Разрешены: " . implode(', ', $allowedExtensions);
                continue;
            }
            
            // Проверяем размер
            if ($fileSize > $maxFileSize) {
                $errors[] = "Файл '$fileName': размер превышает 10MB";
                continue;
            }
            
            // Генерируем уникальное имя файла
            $newFileName = uniqid() . '_' . time() . '.' . $fileExtension;
            $uploadPath = 'uploads/transit/' . $newFileName;
            
            if (move_uploaded_file($fileTmpName, $uploadPath)) {
                $newUploadedFiles[] = [
                    'original_name' => $fileName,
                    'file_name' => $newFileName,
                    'file_path' => $uploadPath,
                    'file_size' => $fileSize,
                    'uploaded_at' => date('Y-m-d H:i:s')
                ];
            } else {
                $errors[] = "Ошибка загрузки файла '$fileName'";
            }
        }
    }
    
    // Если нет ошибок, сохраняем изменения
    if (empty($errors)) {
        try {
            $pdo->beginTransaction();
            
            // Обновляем основную информацию
            $updateQuery = "
                UPDATE goods_in_transit 
                SET departure_location = ?, departure_date = ?, arrival_date = ?, 
                    warehouse_id = ?, goods_info = ?, updated_at = CURRENT_TIMESTAMP
                WHERE id = ?
            ";
            
            // Обрабатываем файлы
            $updatedFiles = $existingFiles;
            
            // Удаляем файлы, помеченные для удаления
            if (!empty($filesToDelete)) {
                foreach ($updatedFiles as $key => $file) {
                    if (in_array($file['file_name'], $filesToDelete)) {
                        // Удаляем физический файл
                        if (file_exists($file['file_path'])) {
                            unlink($file['file_path']);
                        }
                        unset($updatedFiles[$key]);
                    }
                }
                $updatedFiles = array_values($updatedFiles); // Переиндексируем массив
            }
            
            // Добавляем новые файлы
            $updatedFiles = array_merge($updatedFiles, $newUploadedFiles);
            
            $stmt = $pdo->prepare($updateQuery);
            $stmt->execute([
                $departureLocation,
                $departureDate,
                !empty($arrivalDate) ? $arrivalDate : null,
                $warehouseId,
                json_encode($validGoodsInfo, JSON_UNESCAPED_UNICODE),
                $transitId
            ]);
            
            // Обновляем файлы отдельно
            $updateFilesQuery = "UPDATE goods_in_transit SET files = ? WHERE id = ?";
            $stmt = $pdo->prepare($updateFilesQuery);
            $stmt->execute([
                !empty($updatedFiles) ? json_encode($updatedFiles, JSON_UNESCAPED_UNICODE) : null,
                $transitId
            ]);
            
            $pdo->commit();
            $success = true;
            
            // Перенаправляем на страницу просмотра
            redirect("view.php?id=$transitId");
            
        } catch (Exception $e) {
            $pdo->rollBack();
            logError('Edit transit error: ' . $e->getMessage());
            $errors[] = 'Ошибка при сохранении данных. Попробуйте еще раз.';
            
            // Удаляем новые загруженные файлы при ошибке
            foreach ($newUploadedFiles as $file) {
                if (file_exists($file['file_path'])) {
                    unlink($file['file_path']);
                }
            }
        }
    }
}

include '../../includes/header.php';
?>

<div class="container-fluid">
    <div class="row">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <h1><?= htmlspecialchars($pageTitle) ?> #<?= $transit['id'] ?></h1>
                    <nav aria-label="breadcrumb">
                        <ol class="breadcrumb">
                            <li class="breadcrumb-item"><a href="index.php">Товар в пути</a></li>
                            <li class="breadcrumb-item"><a href="view.php?id=<?= $transit['id'] ?>">Просмотр #<?= $transit['id'] ?></a></li>
                            <li class="breadcrumb-item active">Редактировать</li>
                        </ol>
                    </nav>
                </div>
                <a href="view.php?id=<?= $transit['id'] ?>" class="btn btn-outline-secondary">
                    <i class="bi bi-arrow-left"></i> Назад к просмотру
                </a>
            </div>

            <?php if (!empty($errors)): ?>
                <div class="alert alert-danger">
                    <h6>Исправьте следующие ошибки:</h6>
                    <ul class="mb-0">
                        <?php foreach ($errors as $error): ?>
                            <li><?= htmlspecialchars($error) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <form method="POST" enctype="multipart/form-data" id="transitForm">
                <div class="row">
                    <div class="col-lg-8">
                        <!-- Основная информация -->
                        <div class="card mb-4">
                            <div class="card-header">
                                <h5 class="card-title mb-0">
                                    <i class="bi bi-info-circle"></i> Основная информация
                                </h5>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label for="departure_location" class="form-label">Место отгрузки <span class="text-danger">*</span></label>
                                            <input type="text" name="departure_location" id="departure_location" class="form-control" 
                                                   value="<?= htmlspecialchars($_POST['departure_location'] ?? $transit['departure_location']) ?>" 
                                                   placeholder="Введите место отгрузки" required>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label for="warehouse_id" class="form-label">Склад назначения <span class="text-danger">*</span></label>
                                            <select name="warehouse_id" id="warehouse_id" class="form-select" required>
                                                <option value="">Выберите склад</option>
                                                <?php foreach ($warehouses as $warehouse): ?>
                                                    <option value="<?= $warehouse['id'] ?>" 
                                                            <?= ($_POST['warehouse_id'] ?? $transit['warehouse_id']) == $warehouse['id'] ? 'selected' : '' ?>>
                                                        <?= htmlspecialchars($warehouse['company_name']) ?> - <?= htmlspecialchars($warehouse['name']) ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label for="departure_date" class="form-label">Дата отгрузки <span class="text-danger">*</span></label>
                                            <input type="date" name="departure_date" id="departure_date" class="form-control" 
                                                   value="<?= $_POST['departure_date'] ?? $transit['departure_date'] ?>" required>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label for="arrival_date" class="form-label">Планируемая дата поступления</label>
                                            <input type="date" name="arrival_date" id="arrival_date" class="form-control" 
                                                   value="<?= $_POST['arrival_date'] ?? $transit['arrival_date'] ?>">
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Информация о грузе -->
                        <div class="card mb-4">
                            <div class="card-header d-flex justify-content-between align-items-center">
                                <h5 class="card-title mb-0">
                                    <i class="bi bi-box-seam"></i> Информация о грузе
                                </h5>
                                <button type="button" class="btn btn-outline-primary btn-sm" onclick="addGoodItem()">
                                    <i class="bi bi-plus"></i> Добавить товар
                                </button>
                            </div>
                            <div class="card-body">
                                <div id="goodsContainer">
                                    <!-- Существующие товары будут загружены через JavaScript -->
                                </div>
                                
                                <div class="text-muted mt-3">
                                    <small>
                                        <i class="bi bi-info-circle"></i>
                                        Добавьте товары, которые находятся в данной отправке
                                    </small>
                                </div>
                            </div>
                        </div>

                        <!-- Существующие файлы -->
                        <?php if (!empty($existingFiles)): ?>
                        <div class="card mb-4">
                            <div class="card-header">
                                <h5 class="card-title mb-0">
                                    <i class="bi bi-paperclip"></i> Прикрепленные файлы
                                </h5>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <?php foreach ($existingFiles as $file): ?>
                                        <div class="col-md-6 mb-3">
                                            <div class="card">
                                                <div class="card-body">
                                                    <div class="form-check mb-2">
                                                        <input class="form-check-input" type="checkbox" name="delete_files[]" 
                                                               value="<?= htmlspecialchars($file['file_name']) ?>" 
                                                               id="delete_<?= htmlspecialchars($file['file_name']) ?>">
                                                        <label class="form-check-label text-danger" for="delete_<?= htmlspecialchars($file['file_name']) ?>">
                                                            Удалить файл
                                                        </label>
                                                    </div>
                                                    <h6 class="card-title">
                                                        <i class="bi bi-file-earmark"></i>
                                                        <?= htmlspecialchars($file['original_name']) ?>
                                                    </h6>
                                                    <p class="card-text">
                                                        <small class="text-muted">
                                                            Размер: <?= formatFileSize($file['file_size']) ?><br>
                                                            Загружен: <?= date('d.m.Y H:i', strtotime($file['uploaded_at'])) ?>
                                                        </small>
                                                    </p>
                                                    <a href="/<?= $file['file_path'] ?>" class="btn btn-outline-primary btn-sm" target="_blank">
                                                        <i class="bi bi-download"></i> Скачать
                                                    </a>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>

                        <!-- Новые файлы -->
                        <div class="card mb-4">
                            <div class="card-header">
                                <h5 class="card-title mb-0">
                                    <i class="bi bi-plus-circle"></i> Добавить новые файлы
                                </h5>
                            </div>
                            <div class="card-body">
                                <div class="mb-3">
                                    <label for="files" class="form-label">Загрузить файлы</label>
                                    <input type="file" name="files[]" id="files" class="form-control" multiple
                                           accept=".jpg,.jpeg,.png,.pdf,.doc,.docx,.xls,.xlsx,.txt,.odt,.rtf">
                                    <div class="form-text">
                                        Разрешенные форматы: JPG, JPEG, PNG, PDF, DOC, DOCX, XLS, XLSX, TXT, ODT, RTF. 
                                        Максимальный размер файла: 10MB
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Кнопки -->
                        <div class="d-grid gap-2 d-md-flex justify-content-md-end mb-4">
                            <a href="view.php?id=<?= $transit['id'] ?>" class="btn btn-secondary">
                                <i class="bi bi-x-circle"></i> Отмена
                            </a>
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-check-circle"></i> Сохранить изменения
                            </button>
                        </div>
                    </div>

                    <div class="col-lg-4">
                        <!-- Справка -->
                        <div class="card">
                            <div class="card-header">
                                <h5 class="card-title mb-0">
                                    <i class="bi bi-question-circle"></i> Справка
                                </h5>
                            </div>
                            <div class="card-body">
                                <h6>Редактирование:</h6>
                                <ul class="small">
                                    <li>Изменяйте информацию о доставке</li>
                                    <li>Добавляйте или удаляйте товары из груза</li>
                                    <li>Загружайте дополнительные файлы</li>
                                    <li>Отмечайте файлы для удаления</li>
                                </ul>
                                
                                <hr>
                                
                                <h6>Ограничения:</h6>
                                <ul class="small">
                                    <li>Нельзя редактировать подтвержденные отправки</li>
                                    <li>Дата поступления не может быть раньше отгрузки</li>
                                    <li>Обязательно указать хотя бы один товар</li>
                                </ul>
                            </div>
                        </div>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Шаблон для товара -->
<template id="goodItemTemplate">
    <div class="good-item border rounded p-3 mb-3">
        <div class="d-flex justify-content-between align-items-start mb-3">
            <h6 class="mb-0">Товар <span class="item-number"></span></h6>
            <button type="button" class="btn btn-outline-danger btn-sm" onclick="removeGoodItem(this)">
                <i class="bi bi-trash"></i>
            </button>
        </div>
        
        <div class="row">
            <div class="col-md-6">
                <div class="mb-3">
                    <label class="form-label">Тип товара <span class="text-danger">*</span></label>
                    <select name="goods_info[INDEX][template_id]" class="form-select template-select" required onchange="loadTemplateAttributes(this)">
                        <option value="">Выберите тип товара</option>
                        <?php foreach ($templates as $template): ?>
                            <option value="<?= $template['id'] ?>" data-unit="<?= htmlspecialchars($template['unit']) ?>">
                                <?= htmlspecialchars($template['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="col-md-6">
                <div class="mb-3">
                    <label class="form-label">Количество <span class="text-danger">*</span></label>
                    <div class="input-group">
                        <input type="number" name="goods_info[INDEX][quantity]" class="form-control" 
                               step="0.001" min="0.001" required>
                        <span class="input-group-text unit-display">шт</span>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="attributes-container">
            <!-- Атрибуты товара будут загружаться здесь -->
        </div>
    </div>
</template>

<script>
let goodItemIndex = 0;
const existingGoods = <?= json_encode($goodsInfo) ?>;

// Загружаем существующие товары при загрузке страницы
document.addEventListener('DOMContentLoaded', function() {
    if (existingGoods.length > 0) {
        existingGoods.forEach((good, index) => {
            addGoodItem(good);
        });
    } else {
        addGoodItem();
    }
});

function addGoodItem(existingGood = null) {
    const container = document.getElementById('goodsContainer');
    const template = document.getElementById('goodItemTemplate');
    const clone = template.content.cloneNode(true);
    
    // Заменяем INDEX на актуальный индекс
    const html = clone.querySelector('.good-item').outerHTML.replace(/INDEX/g, goodItemIndex);
    
    const div = document.createElement('div');
    div.innerHTML = html;
    const goodItem = div.firstChild;
    
    // Обновляем номер товара
    goodItem.querySelector('.item-number').textContent = '#' + (goodItemIndex + 1);
    
    // Если есть существующие данные, заполняем их
    if (existingGood) {
        const templateSelect = goodItem.querySelector('.template-select');
        const quantityInput = goodItem.querySelector('input[type="number"]');
        
        templateSelect.value = existingGood.template_id;
        quantityInput.value = existingGood.quantity;
        
        // Обновляем единицу измерения
        const selectedOption = templateSelect.options[templateSelect.selectedIndex];
        if (selectedOption.dataset.unit) {
            goodItem.querySelector('.unit-display').textContent = selectedOption.dataset.unit;
        }
        
        // Загружаем атрибуты
        loadTemplateAttributes(templateSelect, existingGood.attributes);
    }
    
    container.appendChild(goodItem);
    goodItemIndex++;
    
    updateItemNumbers();
}

function removeGoodItem(button) {
    const goodItem = button.closest('.good-item');
    goodItem.remove();
    updateItemNumbers();
}

function updateItemNumbers() {
    const items = document.querySelectorAll('.good-item');
    items.forEach((item, index) => {
        item.querySelector('.item-number').textContent = '#' + (index + 1);
    });
}

async function loadTemplateAttributes(select, existingAttributes = null) {
    const templateId = select.value;
    const goodItem = select.closest('.good-item');
    const attributesContainer = goodItem.querySelector('.attributes-container');
    const unitDisplay = goodItem.querySelector('.unit-display');
    
    // Обновляем единицу измерения
    const selectedOption = select.options[select.selectedIndex];
    if (selectedOption.dataset.unit) {
        unitDisplay.textContent = selectedOption.dataset.unit;
    } else {
        unitDisplay.textContent = 'шт';
    }
    
    // Очищаем контейнер атрибутов
    attributesContainer.innerHTML = '';
    
    if (!templateId) return;
    
    try {
        const response = await fetch(`/ajax/templates.php?action=get_attributes&template_id=${templateId}`);
        const data = await response.json();
        
        if (data.success && data.attributes.length > 0) {
            const index = Array.from(goodItem.parentNode.children).indexOf(goodItem);
            
            data.attributes.forEach(attr => {
                const attrHtml = createAttributeField(attr, index, existingAttributes);
                attributesContainer.insertAdjacentHTML('beforeend', attrHtml);
            });
        }
    } catch (error) {
        console.error('Ошибка загрузки атрибутов:', error);
    }
}

function createAttributeField(attr, index, existingAttributes = null) {
    const fieldName = `goods_info[${index}][attributes][${attr.variable}]`;
    const existingValue = existingAttributes ? (existingAttributes[attr.variable] || '') : '';
    
    let inputField = '';
    
    if (attr.data_type === 'select' && attr.options) {
        let options = [];
        try {
            // Если options это JSON строка, парсим её
            options = typeof attr.options === 'string' ? JSON.parse(attr.options) : attr.options;
        } catch (e) {
            // Если не JSON, разбиваем по запятым
            options = attr.options.split(',').map(opt => opt.trim());
        }
        
        inputField = `
            <select name="${fieldName}" class="form-select" ${attr.is_required ? 'required' : ''}>
                <option value="">Выберите значение</option>
                ${options.map(opt => `<option value="${opt}" ${existingValue === opt ? 'selected' : ''}>${opt}</option>`).join('')}
            </select>
        `;
    } else if (attr.data_type === 'number') {
        inputField = `
            <input type="number" name="${fieldName}" class="form-control" 
                   step="0.001" value="${existingValue}" ${attr.is_required ? 'required' : ''}>
        `;
    } else {
        inputField = `
            <input type="text" name="${fieldName}" class="form-control" 
                   value="${existingValue}" ${attr.is_required ? 'required' : ''}>
        `;
    }
    
    return `
        <div class="col-md-6 mb-3">
            <label class="form-label">
                ${attr.name} ${attr.is_required ? '<span class="text-danger">*</span>' : ''}
                ${attr.unit ? `<small class="text-muted">(${attr.unit})</small>` : ''}
            </label>
            ${inputField}
        </div>
    `;
}

// Валидация дат
document.getElementById('departure_date').addEventListener('change', function() {
    const departureDate = this.value;
    const arrivalDateInput = document.getElementById('arrival_date');
    
    if (departureDate) {
        arrivalDateInput.min = departureDate;
        if (arrivalDateInput.value && arrivalDateInput.value < departureDate) {
            arrivalDateInput.value = departureDate;
        }
    }
});

function formatFileSize(bytes) {
    if (bytes >= 1073741824) {
        return (bytes / 1073741824).toFixed(2) + ' GB';
    } else if (bytes >= 1048576) {
        return (bytes / 1048576).toFixed(2) + ' MB';
    } else if (bytes >= 1024) {
        return (bytes / 1024).toFixed(2) + ' KB';
    } else {
        return bytes + ' bytes';
    }
}
</script>

<?php
function formatFileSize($bytes) {
    if ($bytes >= 1073741824) {
        return number_format($bytes / 1073741824, 2) . ' GB';
    } elseif ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 2) . ' MB';
    } elseif ($bytes >= 1024) {
        return number_format($bytes / 1024, 2) . ' KB';
    } else {
        return $bytes . ' bytes';
    }
}
?>

<?php include '../../includes/footer.php'; ?>