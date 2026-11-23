@echo off
chcp 65001 >nul
cd /d "%~dp0"
echo Demarrage de COUSP Task Manager (la premiere fois : 5 a 15 minutes)...
docker compose -f docker-compose.demo.yml up -d --build --wait
if errorlevel 1 (
  echo.
  echo Le demarrage a echoue. Verifiez que Docker Desktop est ouvert, puis relancez.
  pause
  exit /b 1
)
echo.
echo Pret ! Adresse : http://localhost:8080
echo Compte : coordonnateur@cousp.local   Mot de passe : Cousp@2026!
start http://localhost:8080
pause
