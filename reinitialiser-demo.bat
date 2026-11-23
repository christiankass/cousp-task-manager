@echo off
cd /d "%~dp0"
echo Toutes les donnees de test vont etre effacees puis recreees.
pause
docker compose -f docker-compose.demo.yml down -v
call demarrer-demo.bat
