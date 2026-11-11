# KOPHI'S MUSIC STUDIO Manager — Journal des modifications

## 4.8.0

### Lecteur unifié (nouveau)
- Un seul lecteur pour tout le site public (`assets/js/kophi-player.js` + `assets/css/kophi-player.css`) :
  barre de lecture fixe en bas d'écran avec pochette, titre, précédent / suivant, barre de progression
  glissable (souris et doigt), volume mémorisé, enchaînement automatique des titres, commandes clavier
  (Espace, ← →) et commandes de l'écran verrouillé / du casque (Media Session).
- Utilisé par la boutique beats (`[kophi_beats]`), le modèle de page « Boutique Beats », les beats de la
  page d'accueil (`[kophi_beats_home]`) et la page d'écoute client.
- Plus de dépendance à WaveSurfer chargé depuis un CDN externe.

### Page d'écoute client (lien `?ksm_listen=…`) — refonte
- Présentation « lecteur de streaming » : grande pochette, titre du projet, liste des titres avec durées
  réelles et durée totale, bouton lecture principal, barre de lecture fixe.
- **Pochette** : nouveau champ « Pochette de la page d'écoute » dans la boîte « Écoute client » (sinon image
  mise en avant, sinon pochette générée aux couleurs du studio). La teinte de l'en-tête suit la pochette.
- Remarques par titre : panneau dédié, bouton « + Position actuelle » pour horodater une remarque.
- Page jamais mise en cache (`DONOTCACHEPAGE`, en-têtes no-cache, `X-Robots-Tag: noindex`).
- Le zoom n'est plus bloqué sur mobile (`maximum-scale=1` retiré), champs de saisie en 16 px (plus de zoom
  automatique sur iPhone).

### Boutique beats — corrections
- **Pochettes** : une balise `</div>` en trop fermait la carte trop tôt ; l'image s'affichait en taille
  géante hors de la grille et les informations du beat sortaient de la carte. Les pochettes sont désormais
  carrées, recadrées au centre et jamais déformées (boutique, modèle de page et page d'accueil).
- **Lecture** : le bouton lecture était imbriqué dans une zone elle-même cliquable ; un clic lançait puis
  mettait aussitôt en pause. La lecture démarrait aussi avant que le fichier soit prêt.
- Tailles de texte minimales relevées (descriptions, étiquettes).

### Réservation — corrections
- Les créneaux déjà passés (ou à moins d'une heure) ne sont plus proposés pour la date du jour.
- API publique `POST /kophi/v1/reservations` et formulaire de la page d'accueil : validation de la date, de
  l'heure et du téléphone, refus des dates passées, limitation de débit par adresse IP (ces points d'entrée
  créent un contenu et déclenchent e-mail + WhatsApp sans aucune limite auparavant).
- Formulaire de la page d'accueil : vérification des conflits de créneau (comme la page Réserver), message
  enregistré sous la clé lue par l'administration, URL AJAX correcte sur un site en sous-dossier, plus de
  « Session expirée » pour les visiteurs lorsque la page est servie par un cache.
- Date invalide transmise à l'API de disponibilité : réponse propre au lieu d'un calcul sur le 1er janvier 1970.

### Achat de beat — sécurité
- `POST /kophi/v1/beats/achat` acceptait n'importe quel identifiant de contenu (page privée, brouillon,
  session client…) et renvoyait son titre dans la réponse. Seuls les beats publiés sont acceptés.
- Licence exclusive déjà vendue refusée côté serveur, type de licence contrôlé, limitation de débit.
