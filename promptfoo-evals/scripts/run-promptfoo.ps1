# ILAS Site Assistant — Promptfoo runner (Windows PowerShell)
# Usage:
#   .\scripts\run-promptfoo.ps1 -Action eval    # run evaluation
#   .\scripts\run-promptfoo.ps1 -Action view    # open results viewer
#   .\scripts\run-promptfoo.ps1                 # defaults to eval

param(
    [ValidateSet("eval", "view")]
    [string]$Action = "eval",
    [string]$ConfigOverride = ""
)

$ErrorActionPreference = "Stop"

# ── Privacy / offline defaults ───────────────────────────────────────────────
$env:PROMPTFOO_DISABLE_TELEMETRY = "1"
$env:PROMPTFOO_DISABLE_UPDATE = "1"
$env:PROMPTFOO_DISABLE_REMOTE_GENERATION = "true"
$env:PROMPTFOO_DISABLE_SHARING = "1"
$env:PROMPTFOO_SELF_HOSTED = "1"

# ── Resolve paths ────────────────────────────────────────────────────────────
$ScriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$EvalsDir = Split-Path -Parent $ScriptDir
$Config = Join-Path $EvalsDir "promptfooconfig.yaml"
if ($ConfigOverride) {
    $Config = Join-Path $EvalsDir $ConfigOverride
}

# ── Project-local eval DB ─────────────────────────────────────────────────────
$env:PROMPTFOO_CONFIG_DIR = Join-Path $EvalsDir ".promptfoo"

if (-not (Test-Path $Config)) {
    Write-Error "Config not found at $Config"
    exit 1
}

# ── Ensure output directory exists ───────────────────────────────────────────
$OutputDir = Join-Path $EvalsDir "output"
if (-not (Test-Path $OutputDir)) {
    New-Item -ItemType Directory -Path $OutputDir -Force | Out-Null
}

# ── Use repo-installed Promptfoo CLI ────────────────────────────────────────
$PromptfooArgs = @("--no-install", "promptfoo")

# ── Run ──────────────────────────────────────────────────────────────────────
switch ($Action) {
    "eval" {
        Write-Host "Running Promptfoo evaluation..."
        $OutputFile = if ($env:PROMPTFOO_OUTPUT_FILE) { $env:PROMPTFOO_OUTPUT_FILE } else { Join-Path $OutputDir "results.json" }
        & npx @PromptfooArgs eval --config $Config --output $OutputFile
        Write-Host "Done. Results written to $OutputFile"
    }
    "view" {
        $Port = if ($env:PROMPTFOO_PORT) { [int]$env:PROMPTFOO_PORT } else { 15500 }
        $MaxPort = $Port + 10
        while ($Port -le $MaxPort) {
            $conn = Test-NetConnection -ComputerName localhost -Port $Port -WarningAction SilentlyContinue -ErrorAction SilentlyContinue
            if (-not $conn.TcpTestSucceeded) { break }
            Write-Host "Port $Port is in use, trying next..."
            $Port++
        }
        if ($Port -gt $MaxPort) {
            $startPort = if ($env:PROMPTFOO_PORT) { [int]$env:PROMPTFOO_PORT } else { 15500 }
            Write-Error "No free port in range $startPort-$MaxPort"
            exit 1
        }
        Write-Host ""
        Write-Host "Viewer running at http://localhost:$Port"
        Write-Host "Press Ctrl+C to stop the viewer."
        Write-Host ""
        & npx @PromptfooArgs view --port $Port --yes
    }
}
