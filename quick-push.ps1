# Script for Git Push
# UTF-8 encoding

[Console]::OutputEncoding = [System.Text.Encoding]::UTF8
$OutputEncoding = [System.Text.Encoding]::UTF8

# Change to script directory
$projectPath = Split-Path -Parent $MyInvocation.MyCommand.Path
Set-Location $projectPath

Write-Host "Adding files..." -ForegroundColor Yellow

# Add all files
git add -A

# Check for changes
$status = git status --porcelain
if ([string]::IsNullOrWhiteSpace($status)) {
    Write-Host "No changes to commit" -ForegroundColor Cyan
    exit 0
}

# Create commit
$msg = "Update - $(Get-Date -Format 'yyyy-MM-dd HH:mm')"
Write-Host "Creating commit..." -ForegroundColor Yellow
git commit -m $msg

if ($LASTEXITCODE -ne 0) {
    Write-Host "Error creating commit" -ForegroundColor Red
    exit 1
}

# Push to GitHub
Write-Host "Pushing to GitHub..." -ForegroundColor Yellow
git push origin main

if ($LASTEXITCODE -eq 0) {
    Write-Host "Push completed successfully!" -ForegroundColor Green
} else {
    Write-Host "Error during push" -ForegroundColor Red
    Write-Host "Please check:" -ForegroundColor Yellow
    Write-Host "1. Internet connection" -ForegroundColor Yellow
    Write-Host "2. Authentication credentials" -ForegroundColor Yellow
    Write-Host "3. Push permissions" -ForegroundColor Yellow
    exit 1
}