@echo off
title Autoriser le pilotage depuis un telephone
:: Demande les droits administrateur (necessaires pour modifier le pare-feu)
net session >nul 2>&1
if errorlevel 1 (
  powershell -NoProfile -Command "Start-Process -FilePath '%~f0' -Verb RunAs"
  exit /b
)
echo.
echo  Configuration du pare-feu Windows pour la regie des overlays...
echo.
:: Supprime les regles "bloquer" creees si la fenetre d'autorisation de Node.js a ete refusee
netsh advfirewall firewall delete rule name="Node.js JavaScript Runtime" >nul 2>&1
netsh advfirewall firewall delete rule name="Overlays OBS - regie" >nul 2>&1
:: Autorise le port 3333, uniquement depuis le reseau local (pas depuis Internet)
netsh advfirewall firewall add rule name="Overlays OBS - regie" dir=in action=allow protocol=TCP localport=3333 remoteip=localsubnet profile=any
if errorlevel 1 (
  echo.
  echo  Echec : relancez ce fichier par clic droit ^> Executer en tant qu'administrateur.
) else (
  echo.
  echo  C'est fait ! Relancez DEMARRER-avec-telephone.bat puis scannez le QR code.
)
echo.
pause
