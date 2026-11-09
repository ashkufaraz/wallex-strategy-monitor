@echo off
cd /d "%~dp0"
where php >nul 2>nul
if errorlevel 1 (
 echo PHP not found. Add PHP 8.2+ to PATH. See README-fa.md
 pause
 exit /b 1
)
php check.php
if errorlevel 1 (
 pause
 exit /b 1
)
start "Wallex Worker" /min cmd /k php worker.php
start "Wallex Panel" /min cmd /k php -S 127.0.0.1:5078 -t public
start "" http://127.0.0.1:5078
