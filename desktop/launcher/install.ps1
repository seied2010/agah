$ErrorActionPreference = 'Stop'
if (-not [Environment]::Is64BitOperatingSystem) { throw 'Windows 10/11 x64 is required.' }
$source = Split-Path $PSScriptRoot -Parent
$target = Join-Path $env:LOCALAPPDATA 'AgahLocalPrototype\program'
if (-not (Test-Path $target)) {
    New-Item -ItemType Directory -Path $target -Force | Out-Null
    try {
        foreach ($name in @('runtime','app','launcher','README-fa.html','UX-VERIFICATION-FA.md','THIRD-PARTY.txt')) {
            Copy-Item -LiteralPath (Join-Path $source $name) -Destination $target -Recurse -Force
        }
        [IO.File]::WriteAllText((Join-Path $target 'installed.ok'), '0.2.1-ux1')
    } catch {
        Write-Host "Installation interrupted. Files were retained at $target. Re-extract and reinstall into an empty program folder."
        throw
    }
} elseif (-not (Test-Path (Join-Path $target 'installed.ok'))) {
    throw "An incomplete installation exists at $target. Rename that program folder and retry; do not remove the state folder."
}
if ((Get-Content -LiteralPath (Join-Path $target 'installed.ok') -Raw).Trim() -ne '0.2.1-ux1') { throw 'An older installation exists. Close it with Stop.cmd, then run Update.cmd from the new extracted package.' }
$shell = New-Object -ComObject WScript.Shell
$shortcut = $shell.CreateShortcut((Join-Path ([Environment]::GetFolderPath('Desktop')) 'Agah Local Prototype.lnk'))
$shortcut.TargetPath = Join-Path $env:SystemRoot 'System32\WindowsPowerShell\v1.0\powershell.exe'
$shortcut.Arguments = '-NoLogo -NoProfile -ExecutionPolicy Bypass -File "' + (Join-Path $target 'launcher\run.ps1') + '"'
$shortcut.WorkingDirectory = $target
$shortcut.Save()
& (Join-Path $target 'launcher\run.ps1')
