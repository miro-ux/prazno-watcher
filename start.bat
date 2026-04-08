@echo off
setlocal EnableExtensions
cd /d "%~dp0"

echo [mysql-convex-watcher] Working directory: %CD%
echo.

if exist ".git" (
  where git >nul 2>nul
  if not errorlevel 1 (
    echo [mysql-convex-watcher] Pulling latest from git...
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

if not exist ".env.local" (
  echo WARNING: .env.local not found. Copy .env.example to .env.local and fill in CONVEX_URL and MySQL settings.
  echo.
)

rem ---------------------------------------------------------------------------
rem Try PHP first (no Node/npm needed, no auth-plugin issues with MySQL)
rem ---------------------------------------------------------------------------
where php >nul 2>nul
if not errorlevel 1 (
  echo [mysql-convex-watcher] Starting PHP watcher ^(Ctrl+C to stop^)...
  echo.
  php watcher.php
  set EXIT=%ERRORLEVEL%
  if not "%EXIT%"=="0" echo ERROR: PHP watcher exited with code %EXIT%.
  exit /b %EXIT%
)

rem ---------------------------------------------------------------------------
rem Fall back to Node.js
rem ---------------------------------------------------------------------------
where node >nul 2>nul
if errorlevel 1 (
  echo ERROR: Neither PHP nor Node.js found on PATH.
  echo Install PHP from https://windows.php.net/download/ ^(or add to PATH^)
  echo OR install Node.js from https://nodejs.org/
  exit /b 1
)

where npm >nul 2>nul
if errorlevel 1 (
  echo ERROR: npm not found. Reinstall Node.js with npm included.
  exit /b 1
)

echo [mysql-convex-watcher] Installing Node dependencies...
call npm install --no-fund --no-audit
if errorlevel 1 (
  echo ERROR: npm install failed.
  exit /b 1
)

echo.
echo [mysql-convex-watcher] Starting Node watcher ^(Ctrl+C to stop^)...
echo.
call npm start
set EXIT=%ERRORLEVEL%
if not "%EXIT%"=="0" echo ERROR: Node watcher exited with code %EXIT%.
exit /b %EXIT%
