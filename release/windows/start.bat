@echo off
rem Starts ona-web with Docker Desktop and opens it in the browser.
setlocal
cd /d "%~dp0"
title ona-web

where docker >nul 2>nul
if errorlevel 1 (
  echo Docker Desktop is required. Install it from https://www.docker.com/products/docker-desktop/ and run this again.
  start "" "https://www.docker.com/products/docker-desktop/"
  pause
  exit /b 1
)
docker info >nul 2>nul
if errorlevel 1 (
  echo Docker Desktop is not running. Start it, wait until it says "Engine running", then run this again.
  pause
  exit /b 1
)

if not exist .env (
  echo Creating .env with random database passwords...
  powershell -NoProfile -Command "Set-Content -Encoding ascii -Path .env -Value @('ONA_DB_ROOT_PASSWORD=' + [guid]::NewGuid().ToString('N'), 'ONA_DB_PASSWORD=' + [guid]::NewGuid().ToString('N'), 'ONA_WEB_PORT=8000', 'ONA_CLASSIC_PORT=8080', 'ONA_DEFAULT_DOMAIN=example.com')"
  if errorlevel 1 (
    echo Could not create .env.
    pause
    exit /b 1
  )
)

set "PORT=8000"
for /f "usebackq tokens=1,* delims==" %%a in (".env") do if /i "%%a"=="ONA_WEB_PORT" set "PORT=%%b"

echo Starting ona-web. The first start builds the images and sets up the database; this takes a few minutes.
docker compose up -d --build
if errorlevel 1 (
  echo Starting the containers failed; see the messages above.
  pause
  exit /b 1
)

echo Waiting for ona-web to be ready...
set /a TRIES=0
:wait
curl.exe -fs -o nul "http://localhost:%PORT%/api/v1/health" && goto ready
set /a TRIES+=1
if %TRIES% geq 150 (
  echo ona-web did not become ready. Check "docker compose logs" in this folder.
  pause
  exit /b 1
)
timeout /t 2 /nobreak >nul
goto wait

:ready
echo.
echo ona-web is running:
echo   Web UI:      http://localhost:%PORT%   (first login: admin / admin - change the password under Admin)
echo   Classic UI:  http://localhost:8080
echo Run stop.bat to stop it. Your data is kept between runs.
start "" "http://localhost:%PORT%/"
timeout /t 10 >nul
