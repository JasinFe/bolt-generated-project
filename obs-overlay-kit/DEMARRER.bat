@echo off
title Overlays OBS - laissez cette fenetre ouverte
cd /d "%~dp0"
where node >nul 2>nul || (echo Node.js n est pas installe : https://nodejs.org & pause & exit /b)
start "" http://localhost:3333/controle.html
node server.mjs
pause
