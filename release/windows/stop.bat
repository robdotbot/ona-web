@echo off
rem Stops ona-web. Data is kept; it is back next time you run start.bat.
cd /d "%~dp0"
docker compose down
echo ona-web stopped.
timeout /t 5 >nul
