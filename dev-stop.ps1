$root = $PSScriptRoot

Write-Host "============================================" -ForegroundColor Cyan
Write-Host "  LiveCode — остановка процессов" -ForegroundColor Cyan
Write-Host "============================================" -ForegroundColor Cyan

function Kill-ByCommandLine($pattern, $label) {
    $procs = Get-CimInstance Win32_Process -Filter "Name != 'cmd.exe'" | Where-Object {
        $_.CommandLine -match $pattern
    }
    if ($procs) {
        $procs | ForEach-Object { Stop-Process -Id $_.ProcessId -Force -ErrorAction SilentlyContinue }
        Write-Host "  $label: остановлено ($($procs.Count))" -ForegroundColor Green
    } else {
        Write-Host "  $label: не найден" -ForegroundColor Gray
    }
}

Write-Host "`n[Дев-серверы]" -ForegroundColor Magenta
Kill-ByCommandLine "artisan serve"       "Backend"
Kill-ByCommandLine "artisan reverb"      "Reverb"
Kill-ByCommandLine "vue-cli-service"     "Frontend"
Kill-ByCommandLine "terminal-gateway"    "Terminal Gateway"

Write-Host "`n[Инфраструктура]" -ForegroundColor Magenta

$forgejoProc = Get-Process -Name "forgejo" -ErrorAction SilentlyContinue
if ($forgejoProc) {
    & "$root\services\forgejo\scripts\stop.ps1" *>$null
    Write-Host "  Forgejo: остановлен" -ForegroundColor Green
} else {
    Write-Host "  Forgejo: не запущен" -ForegroundColor Gray
}

$redisProc = Get-Process -Name "redis-server" -ErrorAction SilentlyContinue
if ($redisProc) {
    & "$root\services\redis\scripts\stop.ps1" *>$null
    Write-Host "  Redis: остановлен" -ForegroundColor Green
} else {
    Write-Host "  Redis: не запущен" -ForegroundColor Gray
}

# Чистим PID-файл
if (Test-Path "$root\.dev-pids.json") { Remove-Item "$root\.dev-pids.json" -Force }

Write-Host "`n============================================" -ForegroundColor Cyan
Write-Host "  Всё остановлено" -ForegroundColor Green
Write-Host "============================================" -ForegroundColor Cyan
