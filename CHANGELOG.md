# Changelog

## 1.0.0 — 2026-11-08

Première version publique. Le client PHP d'AJA (`aja/sdk`) pour l'API d'AJA V2.

Les numéros 2.0.0 et 2.1.0 ne sont jamais sortis : c'étaient les versions de travail d'AJA V2. Tout ce
qu'elles contenaient est dans cette version, qui est la première.

### À savoir

- **L'adresse de l'API doit être en https.** Une adresse en http n'est pas acceptée, sauf
  vers un serveur de test local (localhost, réseau privé, `*.test`, `*.local`) : la clé
  d'API voyageait sinon en clair.
- **Les montants suivent ISO 4217** : un entier dans la plus petite unité de la devise
  (exposant 0, 2 ou 3), jamais le nombre de décimales qu'affiche la boutique — `Money::exponent()`, `Money::toMinor()`, `Money::fromMinor()`.

### Fonctions

- Montants : `Money::exponent()`, `Money::toMinor()`, `Money::fromMinor()`, en arithmétique de chaînes (jamais de flottant multiplié), arrondi
  au demi supérieur ; une valeur vide n'est pas un montant, jamais 0.
- Profil de la boutique : `site()->putProfile(currency, timezone, wakeUrl)` — la devise, le fuseau, et l'adresse de réveil qu'AJA
  appelle quand une action attend (indispensable si votre tâche ne tourne qu'aux visites).
- Catalogue : `products()->putSchema()`, `upsert()`, `delete()`.
- File des actions : le paramètre `wait` (attente longue) et la sonde de capacité.
- Preuve de propriété : `site()->handleWake($_GET)`, à appeler depuis votre adresse de réveil. Avant de passer un site
  en production, AJA appelle cette adresse avec `aja_verify=<code>` ; le SDK lui renvoie le code avec la clé du site
  (`POST /v1/site-verification`). Sans code, ou avec un code mal formé, rien ne part. `site()->confirmVerification($code)`
  fait l'envoi seul.
- Code d'erreur `ErrorCode::VERIFICATION_FAILED` (HTTP 422, jamais réessayé) : code inconnu ou expiré, ou domaine changé
  depuis l'envoi. `handleWake()` le rend en `['verified' => false]`, sans lever.
