$root = $PSScriptRoot

# ─── Проверка: первый запуск? ───────────────────────────────────
if (-not (Get-Process -Name "redis-server" -ErrorAction SilentlyContinue) -and -not (Get-Command "redis-server" -ErrorAction SilentlyContinue)) {
    $redisBin = Join-Path $root "services\redis\bin\redis-server.exe"
    if (-not (Test-Path $redisBin)) {
        Write-Host "`nRedis/Forgejo не установлены. Запусти сначала setup:`n  .\services\setup.ps1" -ForegroundColor Yellow
    }
}
if (-not (Get-Process -Name "forgejo" -ErrorAction SilentlyContinue) -and -not (Get-Command "forgejo" -ErrorAction SilentlyContinue)) {
    $forgejoBin = Join-Path $root "services\forgejo\bin\forgejo.exe"
    if (-not (Test-Path $forgejoBin)) {
        Write-Host "`nForgejo не установлен. Запусти сначала setup:`n  .\services\setup.ps1" -ForegroundColor Yellow
    }
}

Write-Host "============================================" -ForegroundColor Cyan
Write-Host "  LiveCode — запуск всех сервисов" -ForegroundColor Cyan
Write-Host "============================================" -ForegroundColor Cyan

# ─── Инфраструктура (тихо, в фоне) ─────────────────────────────
Write-Host "`n[Инфраструктура]" -ForegroundColor Magenta

Write-Host "  [1] Redis... " -ForegroundColor Green -NoNewline
$redisProc = Get-Process -Name "redis-server" -ErrorAction SilentlyContinue
if (-not $redisProc) {
    & "$root\services\redis\scripts\start.ps1" *>$null
    Start-Sleep -Seconds 2
    $redisProc = Get-Process -Name "redis-server" -ErrorAction SilentlyContinue
}
if ($redisProc) { Write-Host "OK (PID: $($redisProc.Id))" -ForegroundColor Green } else { Write-Host "FAIL" -ForegroundColor Red }

Write-Host "  [2] Forgejo... " -ForegroundColor Green -NoNewline
$forgejoProc = Get-Process -Name "forgejo" -ErrorAction SilentlyContinue
if (-not $forgejoProc) {
    & "$root\services\forgejo\scripts\start.ps1" *>$null
    Start-Sleep -Seconds 3
    $forgejoProc = Get-Process -Name "forgejo" -ErrorAction SilentlyContinue
}
if ($forgejoProc) { Write-Host "OK (PID: $($forgejoProc.Id))" -ForegroundColor Green } else { Write-Host "FAIL" -ForegroundColor Red }

# ─── Дев-серверы (каждый в своём окне) ─────────────────────────
Write-Host "`n[Дев-серверы]" -ForegroundColor Magenta

$commands = @(
    @{ Title = "Backend  (php artisan serve)";          Cmd = "php artisan serve --host=0.0.0.0 --port=8000";              Dir = $root }
    @{ Title = "Reverb   (php artisan reverb:start)";   Cmd = "php artisan reverb:start --host=0.0.0.0 --port=8081";       Dir = $root }
    @{ Title = "Frontend (vue-cli-service serve)";      Cmd = "npm run serve";                                              Dir = "$root\frontend" }
    @{ Title = "Terminal (node src/index.js)";          Cmd = "npm run dev";                                                Dir = "$root\terminal-gateway" }
)

foreach ($c in $commands) {
    Write-Host "  [$(($commands.IndexOf($c)+3))] $($c.Title)..." -ForegroundColor Green
    $wshell = New-Object -ComObject WScript.Shell
    $wshell.Run("powershell.exe -NoExit -Command `"cd '$($c.Dir)'; $($c.Cmd)`"", 1, $false) | Out-Null
}

Write-Host "`n============================================" -ForegroundColor Cyan
Write-Host "  PID-файл: $root\.dev-pids.json" -ForegroundColor Gray

# Сохраняем PID'ы сервисов для dev-stop.ps1
@{
    Redis   = if ($redisProc) { $redisProc.Id } else { $null }
    Forgejo = if ($forgejoProc) { $forgejoProc.Id } else { $null }
} | ConvertTo-Json -Compress | Set-Content -Path "$root\.dev-pids.json"

Write-Host "  Всё запущено!" -ForegroundColor Green
Write-Host "  Остановить: .\dev-stop.ps1" -ForegroundColor Cyan
Write-Host "============================================" -ForegroundColor Cyan
