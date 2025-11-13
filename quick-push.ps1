# Script بسيط وسريع لـ Git Push بدون تعطل

# إضافة الملفات
git add -A

# Commit مع رسالة تلقائية
$msg = "Update - $(Get-Date -Format 'yyyy-MM-dd HH:mm')"
git commit -m $msg

# Push
git push

Write-Host "Done!" -ForegroundColor Green