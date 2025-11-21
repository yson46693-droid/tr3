<?php
// استخدام JavaScript redirect بدلاً من header redirect
// لأن header.php تم تضمينه بالفعل وأرسل output

// التأكد من أن path_helper.php تم تضمينه
if (!function_exists('getBasePath')) {
    require_once __DIR__ . '/../../includes/path_helper.php';
}

$basePath = function_exists('getBasePath') ? getBasePath() : '';
$attendanceUrl = rtrim($basePath, '/') . '/attendance.php';
?>
<script>
window.location.href = '<?php echo htmlspecialchars($attendanceUrl, ENT_QUOTES, 'UTF-8'); ?>';
</script>
<?php
exit;   