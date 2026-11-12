<?php

return [
    /*
     | Secret servant à calculer l'empreinte HMAC des codes (anti-doublon).
     | Distinct de APP_KEY. À générer une fois (ex. `openssl rand -hex 32`)
     | et à NE JAMAIS changer : sinon les doublons ne sont plus détectés.
     */
    'code_pepper' => env('ENERGIE_CODE_PEPPER'),

    // Fenêtre de la moyenne mobile pour l'autonomie (jours).
    'fenetre_moyenne_jours' => (int) env('ENERGIE_FENETRE_MOYENNE', 7),

    // Un code « à saisir » depuis plus de N jours déclenche une alerte.
    'alerte_code_en_attente_jours' => 3,

    // Écart (en %) au-dessus de la moyenne du même jour de semaine → surconsommation.
    'seuil_surconsommation_pct' => 30,

    // Fuseau des journées de consommation (Côte d'Ivoire : GMT, sans heure d'été).
    'fuseau' => 'Africa/Abidjan',

    // Capteur IoT sans mesure depuis N heures → alerte « capteur muet ».
    'capteur_muet_heures' => 6,

    // Canaux des notifications : database, mail (puis FCM / SMS selon vos fournisseurs).
    'canaux_alertes' => array_filter(explode(',', env('ENERGIE_CANAUX_ALERTES', 'database'))),
];
