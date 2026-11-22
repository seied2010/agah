$ErrorActionPreference = 'Stop'
$source = Split-Path $PSScriptRoot -Parent
$root = Join-Path $env:LOCALAPPDATA 'AgahLocalPrototype'
$program = Join-Path $root 'program'
if (-not (Test-Path (Join-Path $program 'installed.ok'))) { throw 'No complete installation found. Use Install.cmd for a new installation.' }
if (([IO.Path]::GetFullPath($source)).TrimEnd('\') -eq ([IO.Path]::GetFullPath($program)).TrimEnd('\')) { throw 'Run Update.cmd from the NEW extracted ZIP, not from the installed program folder.' }
$mutex = New-Object Threading.Mutex($false, 'Local\AgahLocalPrototype01')
$locked = $false; $swapped = $false; $oldMoved = $false
$plugin = Join-Path $program 'app\wp-content\plugins\agah-erp'
$stamp = [Guid]::NewGuid().ToString('N')
$stage = Join-Path $root ('update-stage-' + $stamp)
$backup = Join-Path $root ('program-backups\' + $stamp)
try {
    $locked = $mutex.WaitOne(0)
    if (-not $locked) { throw 'Close Agah with Stop.cmd and wait for shutdown before updating.' }
    foreach ($port in @(18817,33417)) {
        $tcp = New-Object Net.Sockets.TcpClient
        $listening = $false
        try { $listening = $tcp.ConnectAsync('127.0.0.1',$port).Wait(200) -and $tcp.Connected }
        catch { $listening = $false }
        finally { $tcp.Dispose() }
        if ($listening) { throw "Port $port is still running. Stop Agah and retry." }
    }
    $manifest = Get-Content -LiteralPath (Join-Path $source 'MANIFEST-SHA256.json') -Raw | ConvertFrom-Json
    $prefix = 'app/wp-content/plugins/agah-erp/'
    $expected = @($manifest.PSObject.Properties | Where-Object { $_.Name.StartsWith($prefix) })
    if ($expected.Count -lt 10) { throw 'Package plugin manifest is incomplete.' }
    $inputPlugin = Join-Path $source 'app\wp-content\plugins\agah-erp'
    if ((Get-ChildItem -LiteralPath $inputPlugin -File -Recurse).Count -ne $expected.Count) { throw 'Package contains missing or extra plugin files.' }
    foreach ($entry in $expected) {
        $path = Join-Path $source $entry.Name.Replace('/','\')
        if ((Get-FileHash -LiteralPath $path -Algorithm SHA256).Hash.ToLowerInvariant() -ne $entry.Value) { throw 'Package integrity failed. Extract the original ZIP again.' }
    }
    New-Item -ItemType Directory -Path $stage,$backup -Force | Out-Null
    Copy-Item -LiteralPath $inputPlugin -Destination (Join-Path $stage 'agah-erp') -Recurse
    Copy-Item -LiteralPath (Join-Path $program 'launcher') -Destination (Join-Path $backup 'launcher') -Recurse
    Copy-Item -LiteralPath (Join-Path $program 'installed.ok') -Destination (Join-Path $backup 'installed.ok')
    Move-Item -LiteralPath $plugin -Destination (Join-Path $backup 'agah-erp'); $oldMoved = $true
    Move-Item -LiteralPath (Join-Path $stage 'agah-erp') -Destination $plugin; $swapped = $true
    foreach ($name in @('install.ps1','update.ps1')) {
        Copy-Item -LiteralPath (Join-Path $source ('launcher\' + $name)) -Destination (Join-Path $program ('launcher\' + $name)) -Force
    }
    Copy-Item -LiteralPath (Join-Path $source 'README-fa.html') -Destination (Join-Path $program 'README-fa.html') -Force
    [IO.File]::WriteAllText((Join-Path $program 'installed.ok'),'0.2.0')
    Write-Host 'Updated to desktop 0.2.0 / ERP 0.28.0-alpha1. State, database, settings and keys were not replaced.'
    Write-Host "Previous program code retained at: $backup"
    Write-Host 'Use your existing desktop shortcut to start Agah.'
} catch {
    if ($swapped -and (Test-Path $plugin)) { Remove-Item -LiteralPath $plugin -Recurse -Force }
    if ($oldMoved -and (Test-Path (Join-Path $backup 'agah-erp'))) { Move-Item -LiteralPath (Join-Path $backup 'agah-erp') -Destination $plugin }
    if (Test-Path (Join-Path $backup 'launcher')) { Copy-Item -Path (Join-Path $backup 'launcher\*') -Destination (Join-Path $program 'launcher') -Recurse -Force }
    if (Test-Path (Join-Path $backup 'installed.ok')) { Copy-Item -LiteralPath (Join-Path $backup 'installed.ok') -Destination (Join-Path $program 'installed.ok') -Force }
    throw
} finally {
    if (Test-Path $stage) { Remove-Item -LiteralPath $stage -Recurse -Force }
    if ($locked) { $mutex.ReleaseMutex() }; $mutex.Dispose()
}
