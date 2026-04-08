@echo off
setlocal EnableExtensions
cd /d "%~dp0"

echo [mysql-convex-watcher] Working directory: %CD%
echo.

if exist ".git" (
  where git >nul 2>nul
  if not errorlevel 1 (
    echo [mysql-convex-watcher] Pulling latest from git...
    rem Drop local lockfile so pull never blocks on package-lock.json conflicts
    if exist "package-lock.json" del /q "package-lock.json" 2>nul
    git pull --rebase --autostash
    if errorlevel 1 (
      git pull --no-rebase --autostash
      if errorlevel 1 (
        echo WARNING: git pull failed; continuing with current files.
      )
    )
  ) else (
    echo WARNING: git not on PATH; skipping pull.
  )
  echo.
)

where node >nul 2>nul
if errorlevel 1 (
  echo ERROR: Node.js is not installed or not on PATH.
  echo Install from https://nodejs.org/ then try again.
  exit /b 1
)

where npm >nul 2>nul
if errorlevel 1 (
  echo ERROR: npm not found. Reinstall Node.js with npm included.
  exit /b 1
)

if not exist ".env.local" (
  echo WARNING: .env.local not found. Copy .env.example to .env.local and set CONVEX_URL and MySQL settings.
  echo.
)

echo [mysql-convex-watcher] Installing dependencies...
call npm install --no-fund --no-audit
if errorlevel 1 (
  echo ERROR: npm install failed.
  exit /b 1
)

echo.
echo [mysql-convex-watcher] Starting watcher ^(Ctrl+C to stop^)...
echo.
call npm start
set EXIT=%ERRORLEVEL%
if not "%EXIT%"=="0" echo ERROR: Watcher exited with code %EXIT%.
exit /b %EXIT%
