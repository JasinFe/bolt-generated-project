/*
 * Suivi énergie CIE — capteur d'impulsions pour ESP32 (Arduino IDE, carte « ESP32 Dev Module »).
 *
 * Une photodiode / un phototransistor collé sur le voyant « 1600 imp/kWh » du compteur compte
 * les clignotements. Aucune ouverture du compteur, aucun contact électrique avec lui.
 *
 * Toutes les INTERVALLE_MESURE_S secondes, le cumul d'impulsions est horodaté ; les mesures sont
 * envoyées par lot à POST /api/iot/mesures. Sans Wi-Fi, elles attendent en mémoire (TAMPON mesures).
 * Le cumul est sauvegardé en mémoire flash : une coupure de courant ne le remet pas à zéro.
 *
 * Câblage type (module capteur de lumière à sortie numérique « DO ») :
 *   VCC → 3V3, GND → GND, DO → GPIO 27.  Réglez le potentiomètre pour que la LED du module
 *   ne s'allume qu'au clignotement du voyant.
 *
 * Bibliothèque à installer : ArduinoJson (v7). Le reste est fourni avec le support ESP32.
 *
 * NON TESTÉ SUR MATÉRIEL : à valider sur votre montage (front montant ou descendant, anti-rebond).
 */

#include <WiFi.h>
#include <WiFiClientSecure.h>
#include <HTTPClient.h>
#include <Preferences.h>
#include <ArduinoJson.h>
#include <time.h>

// ----------------------------------------------------------------------------- À RÉGLER
const char* WIFI_SSID      = "votre-wifi";
const char* WIFI_MOT_PASSE = "mot-de-passe-wifi";

const char* URL_API     = "https://votre-domaine.tld/api/iot/mesures";
const char* IDENTIFIANT = "ESP32-A1B2";            // celui saisi dans Réglages → Capteur
const char* JETON       = "collez-ici-le-jeton";   // affiché une seule fois à l'association

// Certificat racine de votre site (format PEM). Laissez vide pour ne PAS vérifier le certificat :
// acceptable pour un premier essai, déconseillé ensuite (le jeton pourrait être intercepté).
const char* CERTIFICAT_RACINE = "";

const uint8_t  BROCHE_CAPTEUR       = 27;
const bool     FRONT_MONTANT        = true;   // false si la sortie du module passe à 0 quand le voyant s'allume
const uint32_t ANTI_REBOND_MS       = 40;     // 1600 imp/kWh à 10 kW = une impulsion toutes les 225 ms
const uint32_t INTERVALLE_MESURE_S  = 60;     // une mesure par minute
const uint32_t INTERVALLE_ENVOI_S   = 300;    // un envoi toutes les 5 minutes
const uint32_t SAUVEGARDE_FLASH_S   = 600;    // limite l'usure de la mémoire flash
const uint16_t IMPULSIONS_PAR_KWH   = 1600;
const char*    VERSION_FIRMWARE     = "1.0.0";
// --------------------------------------------------------------------------------------

struct Mesure { uint32_t horodatage; uint64_t cumul; uint32_t puissanceW; };

const size_t TAMPON = 240;        // 4 h de mesures à la minute
const size_t LOT_MAX = 60;        // mesures par requête
Mesure tampon[TAMPON];
size_t debutTampon = 0, tailleTampon = 0;

Preferences memoire;
portMUX_TYPE verrou = portMUX_INITIALIZER_UNLOCKED;
volatile uint64_t cumulImpulsions = 0;
volatile uint32_t derniereImpulsionMs = 0;

uint64_t cumulSauvegarde = 0, cumulPrecedent = 0;
uint32_t derniereMesure = 0, dernierEnvoi = 0, derniereSauvegarde = 0;

void IRAM_ATTR surImpulsion() {
  uint32_t maintenant = millis();
  if (maintenant - derniereImpulsionMs < ANTI_REBOND_MS) return;
  derniereImpulsionMs = maintenant;
  portENTER_CRITICAL_ISR(&verrou);
  cumulImpulsions++;
  portEXIT_CRITICAL_ISR(&verrou);
}

uint64_t lireCumul() {
  portENTER_CRITICAL(&verrou);
  uint64_t valeur = cumulImpulsions;
  portEXIT_CRITICAL(&verrou);
  return valeur;
}

void connecterWifi() {
  if (WiFi.status() == WL_CONNECTED) return;
  WiFi.mode(WIFI_STA);
  WiFi.begin(WIFI_SSID, WIFI_MOT_PASSE);
  for (uint8_t i = 0; i < 40 && WiFi.status() != WL_CONNECTED; i++) delay(250);   // 10 s au plus
  Serial.println(WiFi.status() == WL_CONNECTED ? "Wi-Fi connecté" : "Wi-Fi indisponible");
}

// L'heure vient d'Internet (NTP, en UTC). Tant qu'elle n'est pas connue, aucune mesure n'est horodatée.
bool heureConnue() { return time(nullptr) > 1600000000; }

void sauvegarder(uint64_t cumul) {
  if (cumul == cumulSauvegarde) return;
  memoire.putULong64("cumul", cumul);
  cumulSauvegarde = cumul;
}

void ajouterMesure(uint64_t cumul, uint32_t puissanceW) {
  if (tailleTampon == TAMPON) {            // tampon plein : la plus ancienne mesure est perdue,
    debutTampon = (debutTampon + 1) % TAMPON;   // mais le cumul reste juste (seul le détail horaire manque)
    tailleTampon--;
  }
  tampon[(debutTampon + tailleTampon) % TAMPON] = { (uint32_t) time(nullptr), cumul, puissanceW };
  tailleTampon++;
}

// Envoie un lot ; les mesures ne quittent le tampon que si le serveur répond 200.
bool envoyerLot() {
  size_t nombre = min(tailleTampon, LOT_MAX);
  if (nombre == 0) return false;

  JsonDocument doc;
  doc["identifiant"] = IDENTIFIANT;
  doc["firmware"] = VERSION_FIRMWARE;
  JsonArray mesures = doc["mesures"].to<JsonArray>();
  for (size_t i = 0; i < nombre; i++) {
    const Mesure& m = tampon[(debutTampon + i) % TAMPON];
    JsonObject o = mesures.add<JsonObject>();
    o["mesure_le"] = m.horodatage;
    o["impulsions_cumul"] = m.cumul;
    o["puissance_w"] = m.puissanceW;
  }
  String corps;
  serializeJson(doc, corps);

  WiFiClientSecure client;
  if (strlen(CERTIFICAT_RACINE) > 0) client.setCACert(CERTIFICAT_RACINE); else client.setInsecure();

  HTTPClient http;
  http.setTimeout(15000);
  if (!http.begin(client, URL_API)) return false;
  http.addHeader("Content-Type", "application/json");
  http.addHeader("Accept", "application/json");
  http.addHeader("Authorization", String("Bearer ") + JETON);
  int code = http.POST(corps);
  http.end();

  Serial.printf("Envoi de %u mesure(s) : HTTP %d\n", (unsigned) nombre, code);
  if (code == 401) Serial.println("Jeton ou identifiant refusé : vérifiez Réglages → Capteur.");
  if (code != 200) return false;

  debutTampon = (debutTampon + nombre) % TAMPON;
  tailleTampon -= nombre;
  return true;
}

void setup() {
  Serial.begin(115200);

  memoire.begin("energie", false);
  cumulImpulsions = cumulSauvegarde = cumulPrecedent = memoire.getULong64("cumul", 0);
  Serial.printf("Cumul repris : %llu impulsions\n", cumulSauvegarde);

  pinMode(BROCHE_CAPTEUR, INPUT);
  attachInterrupt(digitalPinToInterrupt(BROCHE_CAPTEUR), surImpulsion, FRONT_MONTANT ? RISING : FALLING);

  connecterWifi();
  configTime(0, 0, "pool.ntp.org", "time.google.com");   // UTC = heure d'Abidjan

  derniereMesure = dernierEnvoi = derniereSauvegarde = millis();
}

void loop() {
  uint32_t maintenant = millis();

  if (maintenant - derniereMesure >= INTERVALLE_MESURE_S * 1000UL) {
    uint32_t dureeMs = maintenant - derniereMesure;
    derniereMesure = maintenant;

    uint64_t cumul = lireCumul();
    // Puissance moyenne sur l'intervalle : impulsions × 3 600 000 / (imp/kWh × durée en s) watts.
    uint32_t puissanceW = (uint32_t) ((double) (cumul - cumulPrecedent) * 3600000.0 / IMPULSIONS_PAR_KWH / (dureeMs / 1000.0));
    cumulPrecedent = cumul;

    if (heureConnue()) ajouterMesure(cumul, puissanceW);
  }

  if (maintenant - derniereSauvegarde >= SAUVEGARDE_FLASH_S * 1000UL) {
    derniereSauvegarde = maintenant;
    sauvegarder(lireCumul());
  }

  if (maintenant - dernierEnvoi >= INTERVALLE_ENVOI_S * 1000UL) {
    dernierEnvoi = maintenant;
    connecterWifi();
    if (WiFi.status() == WL_CONNECTED && heureConnue()) {
      while (envoyerLot() && tailleTampon > 0) {}   // rattrape le retard après une coupure Wi-Fi
    }
  }

  delay(20);
}
