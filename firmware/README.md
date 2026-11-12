# Capteur ESP32

`capteur-esp32/capteur-esp32.ino` compte les clignotements du voyant du compteur (1600 impulsions par kWh)
et les envoie à l'application. **Ce programme n'a pas été testé sur du matériel** : il est à valider sur votre montage.

1. Dans l'application : Réglages → Capteur de consommation → saisir un identifiant (ex. `ESP32-A1B2`) → **copier le jeton**.
2. Dans l'Arduino IDE : installer le support des cartes ESP32 et la bibliothèque **ArduinoJson** (version 7).
3. Ouvrir le fichier `.ino` et remplir le bloc « À RÉGLER » : Wi-Fi, adresse du site, identifiant, jeton.
4. Téléverser, puis ouvrir le moniteur série (115200 bauds) : `Envoi de N mesure(s) : HTTP 200` confirme la réception.

Ce qu'il faut savoir :

- Après une coupure de courant, jusqu'à 10 minutes d'impulsions peuvent manquer (le cumul est sauvegardé toutes les 10 minutes
  pour ménager la mémoire flash). L'application calcule sur les différences de cumul : rien n'est faussé, la consommation de ces minutes manque.
- Sans Wi-Fi, 4 heures de mesures sont gardées en mémoire puis renvoyées ; au-delà, le total reste juste, seul le détail horaire est perdu.
- `CERTIFICAT_RACINE` vide = certificat du site non vérifié. À renseigner une fois le montage validé.
- Si le capteur n'envoie rien pendant 6 heures, l'application crée une alerte « capteur muet ».
