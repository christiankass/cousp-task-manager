@echo off
cd /d "%~dp0"
docker compose -f docker-compose.demo.yml stop
echo Application arretee. Vos donnees de test sont conservees.
pause
