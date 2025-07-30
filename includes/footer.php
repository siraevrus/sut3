    </main>
    
    <?php if (isLoggedIn()): ?>
    <!-- Футер -->
    <footer class="bg-light border-top mt-5 py-3">
        <div class="container-fluid">
            <div class="row align-items-center">
                <div class="col-md-6">
                    <small class="text-muted">
                        © <?= date('Y') ?> <?= APP_NAME ?> v<?= APP_VERSION ?>
                    </small>
                </div>
                <div class="col-md-6 text-md-end">
                    <small class="text-muted">
                        Пользователь: <?= e(getCurrentUser()['name']) ?> 
                        (<?= e(getCurrentUser()['role']) ?>)
                    </small>
                </div>
            </div>
        </div>
    </footer>
    <?php endif; ?>
    
    <!-- Bootstrap 5 JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <!-- Custom JS -->
    <script src="/assets/js/app.js"></script>
    
    <!-- CSRF Token для AJAX запросов -->
    <script>
        window.csrfToken = '<?= generateCSRFToken() ?>';
    </script>
</body>
</html>