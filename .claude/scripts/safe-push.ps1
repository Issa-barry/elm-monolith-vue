param(
  [string]$Remote = "origin",
  [string]$Branch = ""
)

$ErrorActionPreference = "Stop"

if ([string]::IsNullOrWhiteSpace($Branch)) {
  $Branch = (git rev-parse --abbrev-ref HEAD).Trim()
}

if ($Branch -eq "HEAD") {
  Write-Error "Refusing push from detached HEAD. Checkout a branch first."
  exit 1
}

if ($Branch -in @("main", "pre-prod")) {
  Write-Error "Direct push to '$Branch' is blocked by policy."
  exit 1
}

$allowDev = ($env:SAFE_PUSH_ALLOW_DEV -eq "1")
$isFeature = $Branch -like "feature/*"
$isDevAllowed = ($Branch -eq "dev" -and $allowDev)

if (-not ($isFeature -or $isDevAllowed)) {
  Write-Error "Direct push allowed only on 'feature/*' (or 'dev' with SAFE_PUSH_ALLOW_DEV=1). Branch '$Branch' is not allowed."
  exit 1
}

git show-ref --verify --quiet "refs/heads/$Branch"
if ($LASTEXITCODE -ne 0) {
  Write-Error "Local branch '$Branch' not found."
  exit 1
}

Write-Host "Pushing '$Branch' to '$Remote'..."
$refspec = "refs/heads/$($Branch):refs/heads/$($Branch)"
git push $Remote $refspec
exit $LASTEXITCODE
