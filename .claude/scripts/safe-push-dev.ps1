param(
  [string]$Remote = "origin"
)

$ErrorActionPreference = "Stop"

$env:SAFE_PUSH_ALLOW_DEV = "1"
& "$PSScriptRoot/safe-push.ps1" $Remote "dev"
$exitCode = $LASTEXITCODE
Remove-Item Env:SAFE_PUSH_ALLOW_DEV -ErrorAction SilentlyContinue
exit $exitCode