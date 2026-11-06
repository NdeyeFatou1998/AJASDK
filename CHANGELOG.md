# Changelog

## 2.0.0 — 2026-11-06

Version de lancement d'AJA V2.

### À savoir avant de mettre à jour

- **L'adresse de l'API doit être en https.** Une adresse en http n'est plus acceptée, sauf
  vers un serveur de test local (localhost, réseau privé, `*.test`, `*.local`) : la clé
  d'API voyageait sinon en clair.
- **Les montants suivent ISO 4217** : un entier dans la plus petite unité de la devise
  (exposant 0, 2 ou 3), jamais le nombre de décimales qu'affiche la boutique — `Money::exponent()`, `Money::toMinor()`, `Money::fromMinor()`.

### Nouveau

- Montants : `Money::exponent()`, `Money::toMinor()`, `Money::fromMinor()`, en arithmétique de chaînes (jamais de flottant multiplié), arrondi
  au demi supérieur ; une valeur vide n'est pas un montant, jamais 0.
- Profil de la boutique : `site()->putProfile(currency, timezone, wakeUrl)` — la devise, le fuseau, et l'adresse de réveil qu'AJA
  appelle quand une action attend (indispensable si votre tâche ne tourne qu'aux visites).
- Catalogue : `products()->putSchema()`, `upsert()`, `delete()`.
- File des actions : le paramètre `wait` (attente longue) et la sonde de capacité.

### Corrigé

- Deux codes d'erreur que renvoient les routes de la boutique sont reconnus.
- Les extras d'une ligne de commande partent en objet.
