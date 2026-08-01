param(
    [Parameter(Mandatory = $true)][string]$AppRoot,
    [Parameter(Mandatory = $true)][string]$PhpPath,
    [string]$AppEnvFile = "",
    [switch]$Remove
)

$ErrorActionPreference = 'Stop'
$bridgeTask = 'POSMAIN Print Bridge'
$workerTask = 'POSMAIN Print Worker'
if ($Remove) {
    Unregister-ScheduledTask -TaskName $bridgeTask -Confirm:$false -ErrorAction SilentlyContinue
    Unregister-ScheduledTask -TaskName $workerTask -Confirm:$false -ErrorAction SilentlyContinue
    Write-Output 'تم إيقاف التشغيل التلقائي لخدمات الطباعة.'
    exit 0
}
if (-not (Test-Path -LiteralPath $PhpPath -PathType Leaf)) { throw 'ملف تشغيل PHP غير موجود. أعد تثبيت تطبيق POSMAIN المحلي.' }
if (-not (Test-Path -LiteralPath $AppRoot -PathType Container)) { throw 'مجلد تطبيق POSMAIN غير موجود.' }
if ([string]::IsNullOrWhiteSpace($AppEnvFile)) { $AppEnvFile = Join-Path $AppRoot '.env' }

$runtime = Join-Path $env:LOCALAPPDATA 'POSMAIN\printing'
$state = Join-Path $runtime 'delivery-state'
$secret = Join-Path $runtime 'bridge.secret'
New-Item -ItemType Directory -Force -Path $runtime, $state | Out-Null
& $PhpPath (Join-Path $AppRoot 'tools\configure_print_runtime.php') --apply "--env-file=$AppEnvFile" "--secret-file=$secret"
if ($LASTEXITCODE -ne 0) { throw 'تعذر تجهيز إعداد خدمة الطباعة.' }

$bridgeArgs = '"' + (Join-Path $AppRoot 'tools\print_bridge.php') + '" --listen=127.0.0.1:17981 --secret-file="' + $secret + '" --state-dir="' + $state + '"'
$workerArgs = '"' + (Join-Path $AppRoot 'tools\print_worker_daemon.php') + '" --sleep=2 --pid-file="' + (Join-Path $runtime 'worker.pid') + '" --status-file="' + (Join-Path $runtime 'worker-status.json') + '"'
$trigger = New-ScheduledTaskTrigger -AtLogOn -User $env:USERNAME
$settings = New-ScheduledTaskSettingsSet -StartWhenAvailable -RestartCount 20 -RestartInterval (New-TimeSpan -Minutes 1) -ExecutionTimeLimit ([TimeSpan]::Zero)
$principal = New-ScheduledTaskPrincipal -UserId $env:USERNAME -LogonType Interactive -RunLevel Limited

Register-ScheduledTask -TaskName $bridgeTask -Action (New-ScheduledTaskAction -Execute $PhpPath -Argument $bridgeArgs -WorkingDirectory $AppRoot) -Trigger $trigger -Settings $settings -Principal $principal -Force | Out-Null
Register-ScheduledTask -TaskName $workerTask -Action (New-ScheduledTaskAction -Execute $PhpPath -Argument $workerArgs -WorkingDirectory $AppRoot) -Trigger $trigger -Settings $settings -Principal $principal -Force | Out-Null
Start-ScheduledTask -TaskName $bridgeTask
Start-Sleep -Seconds 1
Start-ScheduledTask -TaskName $workerTask
Write-Output 'تم تثبيت خدمة الطباعة وتشغيلها تلقائياً لهذا المستخدم.'
