/**
 * Основной JavaScript для системы складского учета
 */

// Глобальные переменные
window.SUT = {
    csrfToken: window.csrfToken || '',
    apiUrl: '/api/',
    
    // Утилиты
    utils: {
        // Показать уведомление
        showNotification: function(message, type = 'success') {
            const alertClass = type === 'success' ? 'alert-success' : 'alert-danger';
            const icon = type === 'success' ? 'bi-check-circle' : 'bi-exclamation-triangle';
            
            const alert = `
                <div class="alert ${alertClass} alert-dismissible fade show" role="alert">
                    <i class="bi ${icon}"></i> ${message}
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            `;
            
            // Добавляем в начало main контейнера
            const main = document.querySelector('main');
            if (main) {
                main.insertAdjacentHTML('afterbegin', alert);
                
                // Автоматически скрываем через 5 секунд
                setTimeout(() => {
                    const alertElement = main.querySelector('.alert');
                    if (alertElement) {
                        const bsAlert = new bootstrap.Alert(alertElement);
                        bsAlert.close();
                    }
                }, 5000);
            }
        },
        
        // Показать загрузчик
        showLoader: function() {
            const loader = `
                <div class="spinner-overlay" id="globalLoader">
                    <div class="spinner-border" role="status">
                        <span class="visually-hidden">Загрузка...</span>
                    </div>
                </div>
            `;
            document.body.insertAdjacentHTML('beforeend', loader);
        },
        
        // Скрыть загрузчик
        hideLoader: function() {
            const loader = document.getElementById('globalLoader');
            if (loader) {
                loader.remove();
            }
        },
        
        // AJAX запрос с обработкой ошибок
        ajax: function(url, options = {}) {
            const defaultOptions = {
                method: 'GET',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': this.csrfToken
                }
            };
            
            const finalOptions = { ...defaultOptions, ...options };
            
            // Добавляем CSRF токен в данные для POST запросов
            if (finalOptions.method === 'POST' && finalOptions.body) {
                if (finalOptions.body instanceof FormData) {
                    finalOptions.body.append('csrf_token', window.SUT.csrfToken);
                } else if (typeof finalOptions.body === 'string') {
                    try {
                        const data = JSON.parse(finalOptions.body);
                        data.csrf_token = window.SUT.csrfToken;
                        finalOptions.body = JSON.stringify(data);
                    } catch (e) {
                        // Если не JSON, добавляем как параметр
                        finalOptions.body += '&csrf_token=' + encodeURIComponent(window.SUT.csrfToken);
                    }
                }
            }
            
            return fetch(url, finalOptions)
                .then(response => {
                    if (!response.ok) {
                        throw new Error(`HTTP error! status: ${response.status}`);
                    }
                    return response.json();
                })
                .catch(error => {
                    console.error('AJAX Error:', error);
                    this.showNotification('Произошла ошибка при выполнении запроса', 'error');
                    throw error;
                });
        },
        
        // Подтверждение действия
        confirm: function(message, callback) {
            if (confirm(message)) {
                callback();
            }
        },
        
        // Форматирование числа
        formatNumber: function(number, decimals = 2) {
            return new Intl.NumberFormat('ru-RU', {
                minimumFractionDigits: decimals,
                maximumFractionDigits: decimals
            }).format(number);
        },
        
        // Форматирование даты
        formatDate: function(dateString, options = {}) {
            const defaultOptions = {
                year: 'numeric',
                month: '2-digit',
                day: '2-digit',
                hour: '2-digit',
                minute: '2-digit'
            };
            
            const finalOptions = { ...defaultOptions, ...options };
            return new Date(dateString).toLocaleDateString('ru-RU', finalOptions);
        }
    },
    
    // Модули
    modules: {}
};

// Инициализация при загрузке страницы
document.addEventListener('DOMContentLoaded', function() {
    // Инициализация всех tooltip'ов
    const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    tooltipTriggerList.map(function(tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
    });
    
    // Инициализация всех popover'ов
    const popoverTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="popover"]'));
    popoverTriggerList.map(function(popoverTriggerEl) {
        return new bootstrap.Popover(popoverTriggerEl);
    });
    
    // Автоматическое скрытие алертов
    const alerts = document.querySelectorAll('.alert:not(.alert-dismissible)');
    alerts.forEach(alert => {
        setTimeout(() => {
            alert.style.opacity = '0';
            setTimeout(() => alert.remove(), 300);
        }, 5000);
    });
    
    // Обработка форм с AJAX
    const ajaxForms = document.querySelectorAll('form[data-ajax="true"]');
    ajaxForms.forEach(form => {
        form.addEventListener('submit', function(e) {
            e.preventDefault();
            handleAjaxForm(this);
        });
    });
    
    // Подтверждение удаления
    const deleteButtons = document.querySelectorAll('[data-confirm-delete]');
    deleteButtons.forEach(button => {
        button.addEventListener('click', function(e) {
            e.preventDefault();
            const message = this.dataset.confirmDelete || 'Вы уверены, что хотите удалить этот элемент?';
            window.SUT.utils.confirm(message, () => {
                if (this.tagName === 'A') {
                    window.location.href = this.href;
                } else if (this.tagName === 'BUTTON') {
                    this.closest('form').submit();
                }
            });
        });
    });
    
    // Обработка drag & drop для файлов
    const fileUploadAreas = document.querySelectorAll('.file-upload-area');
    fileUploadAreas.forEach(area => {
        const input = area.querySelector('input[type="file"]');
        
        area.addEventListener('dragover', function(e) {
            e.preventDefault();
            this.classList.add('dragover');
        });
        
        area.addEventListener('dragleave', function(e) {
            e.preventDefault();
            this.classList.remove('dragover');
        });
        
        area.addEventListener('drop', function(e) {
            e.preventDefault();
            this.classList.remove('dragover');
            
            if (input && e.dataTransfer.files.length > 0) {
                input.files = e.dataTransfer.files;
                // Триггерим событие change
                input.dispatchEvent(new Event('change', { bubbles: true }));
            }
        });
        
        area.addEventListener('click', function() {
            if (input) {
                input.click();
            }
        });
    });
    
    // Поиск с debounce
    const searchInputs = document.querySelectorAll('input[data-search]');
    searchInputs.forEach(input => {
        let timeout;
        input.addEventListener('input', function() {
            clearTimeout(timeout);
            timeout = setTimeout(() => {
                performSearch(this.value, this.dataset.search);
            }, 300);
        });
    });
});

// Обработка AJAX форм
function handleAjaxForm(form) {
    const formData = new FormData(form);
    const url = form.action || window.location.href;
    
    window.SUT.utils.showLoader();
    
    window.SUT.utils.ajax(url, {
        method: 'POST',
        body: formData,
        headers: {} // Убираем Content-Type для FormData
    })
    .then(response => {
        if (response.success) {
            window.SUT.utils.showNotification(response.message || 'Операция выполнена успешно');
            
            // Если указан redirect, переходим
            if (response.redirect) {
                setTimeout(() => {
                    window.location.href = response.redirect;
                }, 1000);
            }
            
            // Если указан reload, перезагружаем страницу
            if (response.reload) {
                setTimeout(() => {
                    window.location.reload();
                }, 1000);
            }
            
            // Сброс формы
            if (response.resetForm !== false) {
                form.reset();
            }
        } else {
            window.SUT.utils.showNotification(response.message || 'Произошла ошибка', 'error');
        }
    })
    .catch(error => {
        console.error('Form submission error:', error);
    })
    .finally(() => {
        window.SUT.utils.hideLoader();
    });
}

// Поиск
function performSearch(query, target) {
    // Реализация поиска в зависимости от target
    console.log('Searching for:', query, 'in:', target);
}

// Калькулятор формул (для шаблонов товаров)
window.SUT.modules.FormulaCalculator = {
    // Вычисление формулы
    calculate: function(formula, variables) {
        try {
            // Простой калькулятор для базовых операций
            let expression = formula;
            
            // Заменяем переменные на значения
            for (const [variable, value] of Object.entries(variables)) {
                const regex = new RegExp('\\b' + variable + '\\b', 'g');
                expression = expression.replace(regex, parseFloat(value) || 0);
            }
            
            // Проверяем на безопасность (только числа и базовые операторы)
            if (!/^[0-9+\-*/.() ]+$/.test(expression)) {
                throw new Error('Недопустимые символы в формуле');
            }
            
            // Вычисляем
            const result = Function('"use strict"; return (' + expression + ')')();
            
            return {
                success: true,
                result: Math.round(result * 100) / 100 // Округляем до 2 знаков
            };
        } catch (error) {
            return {
                success: false,
                error: error.message
            };
        }
    },
    
    // Валидация формулы
    validate: function(formula, availableVariables) {
        // Проверяем синтаксис
        const calculation = this.calculate(formula, 
            Object.fromEntries(availableVariables.map(v => [v, 1]))
        );
        
        if (!calculation.success) {
            return { valid: false, error: calculation.error };
        }
        
        // Проверяем, что все переменные в формуле существуют
        const usedVariables = formula.match(/[a-zA-Z_][a-zA-Z0-9_]*/g) || [];
        const unknownVariables = usedVariables.filter(v => !availableVariables.includes(v));
        
        if (unknownVariables.length > 0) {
            return { 
                valid: false, 
                error: 'Неизвестные переменные: ' + unknownVariables.join(', ') 
            };
        }
        
        return { valid: true };
    }
};

// Экспорт для использования в других скриптах
window.SUT = window.SUT;