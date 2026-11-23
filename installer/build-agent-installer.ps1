param(
    [string]$Version = "1.0.0"
)

$ErrorActionPreference = "Stop"

$root = Resolve-Path (Join-Path $PSScriptRoot "..")
$publishDir = Join-Path $root "dist\ClockingAgent-self-contained"
$installerScript = Join-Path $PSScriptRoot "ClockingAgent.iss"
$iscc = "C:\Program Files (x86)\Inno Setup 6\ISCC.exe"

if (-not (Test-Path -LiteralPath $iscc)) {
    throw "Inno Setup compiler not found at $iscc"
}

if (Test-Path -LiteralPath $publishDir) {
    Remove-Item -LiteralPath $publishDir -Recurse -Force
}

dotnet publish (Join-Path $root "ClockingAgent\ClockingAgent.csproj") `
    -c Release `
    -r win-x64 `
    --self-contained true `
    -o $publishDir `
    /p:Version=$Version `
    /p:AssemblyVersion="$Version.0" `
    /p:FileVersion="$Version.0"

$env:CLOCKING_AGENT_VERSION = $Version
& $iscc $installerScript

$installer = Join-Path $root "dist\Time-Attendance-Agent-Setup.exe"
if (-not (Test-Path -LiteralPath $installer)) {
    throw "Installer was not created."
}

Get-Item -LiteralPath $installer | Select-Object FullName, Length, LastWriteTime
