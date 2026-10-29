# Portefeuille numérique multidevise — INF151 TP 2026/2027

Prototype pédagogique (PHP / MySQL / HTML) : portefeuilles XAF, USD et EUR, transferts avec conversion,
frais, taxe, commission commerçant, reçus, historique et rapports.
**Simulation uniquement** : aucune banque, carte ou mobile money réel, aucun fonds réel.

## Structure

```
connexion.php              Connexion PDO à la base (variable $pdo) — à adapter (hôte, utilisateur, mot de passe)
bootstrap.php              Session, chargement des classes, fonctions utilitaires
database/
  01_creation_base.sql     Création de la base et des tables (AUCUNE donnée)
  02_donnees_exemple.sql   Devises, utilisateurs, soldes, taux et paramètres d'exemple
src/                       Couche métier (modèles / services)
  Money.php                Arithmétique décimale BCMath + arrondi HALF_UP
  FeeCalculator.php        Frais, taxe, débit, commission (calcul pur, testable)
  ExchangeRateService.php  Recherche, conversion et gestion des taux
  TransferService.php      Validation + transfert atomique (transaction SQL, verrous)
  TransactionRepository.php  Historique et reçus
  ReportService.php        Rapports journaliers / mensuels
  UserService.php, Auth.php, Parametres.php, MetierException.php
includes/                  header.php, footer.php, detail_calcul.php (affichage commun simulation/reçu)
*.php (racine)             Pages : index, inscription, dashboard, transfert, historique, recu,
                           admin_taux, rapports, logout
assets/style.css           Styles
tests/                     run_tests.php (calculs) et test_base_donnees.php (MySQL)
```

Séparation des couches : interface (pages + `includes/`), services métier (`src/`), persistance (`connexion.php` + SQL).

## Installation (XAMPP / WAMP / LAMP)

Prérequis : PHP ≥ 8.0 avec les extensions **pdo_mysql** et **bcmath**, MySQL 8.0.16+ ou MariaDB 10.2+
(les contraintes `CHECK` doivent être actives).

1. Copier le dossier `portefeuille/` dans `htdocs/` (XAMPP) ou le dossier web de votre serveur.
2. Créer la base, **dans l'ordre** :
   ```
   mysql -u root -p < database/01_creation_base.sql
   mysql -u root -p < database/02_donnees_exemple.sql
   ```
   (ou importer les deux fichiers dans phpMyAdmin, onglet « Importer »).
3. Vérifier les identifiants dans `connexion.php` (par défaut : `localhost`, `root`, mot de passe vide, base `portefeuille_db`).
4. Ouvrir `http://localhost/portefeuille/`.

## Utilisateurs d'exemple (PIN : `1234`)

| Identifiant | Rôle | Soldes initiaux |
|---|---|---|
| `admin` | Administrateur | 0 |
| `alice` | Client | 500 000 XAF, 200 USD, 150 EUR |
| `bob` | Client | 200 000 XAF, 50 USD, 20 EUR |
| `boutique` | Commerçant | 10 000 XAF |

Un compte créé via « Créer un compte » démarre avec des soldes nuls (utiliser les comptes d'exemple pour émettre des transferts,
ou ajuster un solde dans phpMyAdmin pour une démonstration).

## Règles métier implémentées

| Élément | Règle |
|---|---|
| Frais en % | montant source × 2,5 % |
| Frais fixes | 100 XAF (convertis dans la devise source si ce n'est pas du XAF) |
| Frais de transaction | frais en % + frais fixes |
| Taxe | frais de transaction × 19,25 % (hypothèse de classe) |
| Débit expéditeur | montant source + frais + taxe |
| Conversion | table des taux ; taux inverse par division (10 000 XAF ÷ 600 = 16,67 USD) |
| Commission commerçant | 1 % du montant converti, **uniquement** pour un destinataire de rôle commerçant ; déduite du règlement si `commission_deduite = 1` |
| Commerçant reçoit | montant converti − commission |

Frais, taxe et commission sont séparés et tous configurables (page **Taux & paramètres**, table `parametres`).
Ils sont enregistrés avec chaque transaction (auditabilité), ainsi que le taux utilisé et son sens.

## Hypothèses et choix de conception

- **Taux simulés** saisis manuellement, pas des taux de marché. Convention de stockage : **source → cible**
  (`1 devise_source = taux devise_cible`). Si seule la paire opposée existe, le taux inverse est utilisé par division
  du montant (pas de taux inverse arrondi). Une mise à jour de taux = nouvelle ligne avec une date d'effet ; l'historique est conservé.
- **Taxe 19,25 %** et structure de frais : hypothèses de classe, **pas** des conseils juridiques ou fiscaux.
- **Arrondi (BCMath, aucun float)** : calculs intermédiaires à 20 décimales (troncature), puis arrondi **HALF_UP** à la fin,
  **par composante** (frais %, frais fixes, taxe, montant converti, commission), selon les décimales de la devise
  (XAF : 0, USD/EUR : 2). Le débit est la somme des composantes arrondies.
  Conséquence sur l'exemple de l'énoncé : taxe 67,375 → **67 XAF**, débit **10 417 XAF** (l'énoncé donne 10 417,375 « avant arrondi »).
- **Atomicité** : transaction SQL + `SELECT … FOR UPDATE` sur les deux portefeuilles (ordre d'id fixe, pas d'interblocage) ;
  `UPDATE … WHERE solde >= débit` et contrainte `CHECK (solde >= 0)` garantissent qu'un solde ne devient jamais négatif.
  En cas d'échec : rollback complet, aucun solde modifié, tentative inscrite dans `journal_echecs`.
- **Anti-doublon** : chaque confirmation porte une référence unique (`UNIQUE` en base) ; renvoyer le formulaire est rejeté.
- **Sécurité** : requêtes préparées PDO, échappement HTML, jeton CSRF, PIN haché (`password_hash`).
- Devises : XAF, USD, EUR (FR-01). Pour ajouter NGN : insérer la devise dans `devises`, créer ses portefeuilles et ses taux.

## Tests

```
php tests/run_tests.php            # calculs : conversion, frais, taxe, arrondi, commission, validation (sans base)
php tests/test_base_donnees.php    # solde insuffisant, transactions échouées, atomicité, doublons (MySQL requis)
```

`test_base_donnees.php` crée puis supprime une base distincte `portefeuille_test` (votre base n'est pas touchée).
Variables optionnelles : `DB_HOST`, `DB_PORT`, `DB_USER`, `DB_PASS`.

## Scénarios de test manuels

1. **Exemple de l'énoncé** : connecté en `alice`, transfert de 10 000 XAF vers `bob`, XAF → USD. Attendu : crédit 16,67 USD,
   frais 350 XAF, taxe 67 XAF, débit 10 417 XAF, taux 1 USD = 600 XAF (taux inverse).
2. **Même devise** : alice → bob, 5 000 XAF → XAF (aucune conversion, frais et taxe appliqués).
3. **Solde insuffisant** : `bob` envoie 1 000 000 XAF → message d'erreur, aucun solde modifié.
4. **Paiement commerçant** : `bob` → `boutique`, 10 000 XAF → XAF : commission 100 XAF déduite, commerçant reçoit 9 900 XAF.
5. **Validations** : montant `abc`, `-5`, `0`, `100.5` XAF, destinataire `fantome`, soi-même → messages clairs.
6. **Taux** : `admin` ajoute USD → XAF = 650 ; un nouveau transfert utilise ce taux, les anciens reçus gardent le leur.
7. **Rapports** : `admin` ouvre « Rapports » (vue journalière puis mensuelle).

## Limites

Prototype d'apprentissage : pas de limitation de tentatives de connexion, pas de gestion de comptes suspendus via l'interface,
pas de dépôt/retrait, taux non temps réel, une seule monnaie de frais par transaction (la devise source).
