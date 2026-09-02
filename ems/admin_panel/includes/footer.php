<?php if (isLoggedIn()): ?>
</div> <!-- .page-content -->
</div> <!-- .main-content -->
<?php endif; ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script src="<?php echo BASE_URL; ?>js/script.js?v=<?php echo @filemtime(__DIR__ . '/../js/script.js') ?: '1'; ?>"></script>
</body>
</html>
