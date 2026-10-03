@echo off
title Gestionnaire de licences - laissez cette fenetre ouverte
cd /d "%~dp0"
where node >nul 2>nul || (echo Node.js n est pas installe : https://nodejs.org & pause & exit /b)
start "" http://localhost:4444
node server.mjs
pause
