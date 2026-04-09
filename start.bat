@echo off
setlocal EnableExtensions
cd /d "%~dp0"

echo [mysql-convex-watcher] Working directory: %CD%
echo.

rem Kill any previous watcher instance
taskkill /F /IM php.exe >nul 2>nul

rem ---------------------------------------------------------------------------
rem Phase 1 (first run): git pull, then relaunch updated script for Phase 2
rem ---------------------------------------------------------------------------
if "%1"=="--run" goto :run

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
    echo.
  ) else (
    echo WARNING: git not on PATH; skipping pull.
    echo.
  )
)

rem Relaunch the (possibly updated) script to actually run the watcher
call "%~f0" --run
exit /b %ERRORLEVEL%

rem ---------------------------------------------------------------------------
rem Phase 2: run the watcher (script is fully up to date by now)
rem ---------------------------------------------------------------------------
:run

if not exist ".env.local" (
  echo WARNING: .env.local not found. Copy .env.example to .env.local and fill in CONVEX_URL and MySQL settings.
  echo.
)

rem Find PHP — check XAMPP locations, then PATH
set PHP_EXE=
if exist "C:\xampp\php\php.exe" (
  set PHP_EXE=C:\xampp\php\php.exe
) else if exist "C:\xampp\php\php4\php.exe" (
  set PHP_EXE=C:\xampp\php\php4\php.exe
)
if "%PHP_EXE%"=="" where php >nul 2>nul && set PHP_EXE=php

if not "%PHP_EXE%"=="" (
  echo [mysql-convex-watcher] Starting PHP watcher ^(Ctrl+C to stop^)...
  echo Using: %PHP_EXE%
  echo.
  "%PHP_EXE%" watcher.php
  set EXIT=%ERRORLEVEL%
  if not "%EXIT%"=="0" echo ERROR: PHP watcher exited with code %EXIT%.
  exit /b %EXIT%
)

rem Fall back to Node.js
where node >nul 2>nul
if errorlevel 1 (
  echo ERROR: Neither PHP nor Node.js found on PATH.
  echo Install PHP from https://windows.php.net/download/ and add to PATH
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
