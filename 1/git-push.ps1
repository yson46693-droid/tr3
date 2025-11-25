# PowerShell Script لتسهيل عملية Git Push بدون تعطل

$ErrorActionPreference = "Stop"

Write-Host ""
Write-Host "========================================" -ForegroundColor Cyan
Write-Host "  Git Push Script" -ForegroundColor Cyan
Write-Host "========================================" -ForegroundColor Cyan
Write-Host ""

# دالة للتحقق من نجاح الأمر
function Test-GitCommand {
    param([scriptblock]$CommandBlock, [string]$ErrorMessage)
    try {
        & $CommandBlock
        if ($LASTEXITCODE -ne 0 -and $LASTEXITCODE -ne $null) {
            throw $ErrorMessage
        }
        return $true
    } catch {
        Write-Host "ERROR: $ErrorMessage" -ForegroundColor Red
        Write-Host $_.Exception.Message -ForegroundColor Red
        Read-Host "Press Enter to exit"
        exit 1
    }
}

# إضافة جميع الملفات
Write-Host "[1/3] Adding files..." -ForegroundColor Yellow
try {
    git add -A
    if ($LASTEXITCODE -ne 0 -and $LASTEXITCODE -ne $null) {
        throw "Failed to add files"
    }
} catch {
    Write-Host "ERROR: Failed to add files" -ForegroundColor Red
    Read-Host "Press Enter to exit"
    exit 1
}
Start-Sleep -Milliseconds 500

# Commit مع رسالة
Write-Host "[2/3] Committing changes..." -ForegroundColor Yellow

# التحقق من وجود تغييرات
$status = git status --porcelain
if ([string]::IsNullOrWhiteSpace($status)) {
    Write-Host "No changes to commit, skipping..." -ForegroundColor Yellow
} else {
    $commitMsg = Read-Host "Enter commit message (or press Enter for default)"
    if ([string]::IsNullOrWhiteSpace($commitMsg)) {
        $commitMsg = "Update - $(Get-Date -Format 'yyyy-MM-dd HH:mm')"
    }
    
    # تنفيذ commit بشكل مباشر بدون انتظار
    $process = Start-Process -FilePath "git" -ArgumentList "commit","-m","$commitMsg" -NoNewWindow -Wait -PassThru
    if ($process.ExitCode -ne 0) {
        Write-Host "WARNING: Commit may have failed (exit code: $($process.ExitCode))" -ForegroundColor Yellow
        # لا نوقف العملية، قد يكون لا يوجد شيء للـ commit
    }
}

# Push مع retry
Write-Host "[3/3] Pushing to remote..." -ForegroundColor Yellow
$maxRetries = 3
$retryCount = 0
$pushSuccess = $false

while ($retryCount -lt $maxRetries -and -not $pushSuccess) {
    try {
        $output = git push 2>&1
        if ($LASTEXITCODE -eq 0) {
            $pushSuccess = $true
        } else {
            $retryCount++
            if ($retryCount -lt $maxRetries) {
                Write-Host "Retry attempt $retryCount/$maxRetries..." -ForegroundColor Yellow
                Start-Sleep -Seconds 2
            } else {
                throw "Failed to push after $maxRetries attempts"
            }
        }
    } catch {
        $retryCount++
        if ($retryCount -ge $maxRetries) {
            Write-Host "ERROR: Failed to push after $maxRetries attempts" -ForegroundColor Red
            Write-Host $_.Exception.Message -ForegroundColor Red
            Read-Host "Press Enter to exit"
            exit 1
        }
        Start-Sleep -Seconds 2
    }
}

Write-Host ""
Write-Host "========================================" -ForegroundColor Green
Write-Host "  SUCCESS! All changes pushed." -ForegroundColor Green
Write-Host "========================================" -ForegroundColor Green
Write-Host ""
Read-Host "Press Enter to exit"

