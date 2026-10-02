@echo off
chcp 65001 >nul
title Mister Preacher
cd /d "%~dp0"
where node >nul 2>nul
if errorlevel 1 (
  echo Node.js n'est pas installe. Telechargez-le sur https://nodejs.org puis relancez ce fichier.
  start https://nodejs.org
  pause
  exit /b 1
)
if not exist node_modules (
  echo Premiere utilisation : installation des composants...
  call npm install --omit=dev --no-audit --no-fund
)
echo Demarrage de Mister Preacher... (fermez cette fenetre pour arreter)
node server\index.js
pause
