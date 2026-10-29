-- =====================================================================
-- INF151 - TP 2026/2027 - Portefeuille numérique multidevise
-- Script 2/2 : DONNÉES D'EXEMPLE (à exécuter après 01_creation_base.sql)
-- Exécution : mysql -u root -p < database/02_donnees_exemple.sql
-- Tous les comptes de démonstration ont le PIN : 1234
-- =====================================================================

USE portefeuille_db;

-- Devises supportées (XAF sans décimale, USD et EUR avec 2 décimales)
INSERT INTO devises (code, nom, decimales) VALUES
    ('XAF', 'Franc CFA (CEMAC)', 0),
    ('USD', 'Dollar américain',  2),
    ('EUR', 'Euro',              2);

-- Utilisateurs de démonstration (PIN = 1234, haché avec password_hash)
INSERT INTO users (id, nom_complet, identifiant, pin_hash, role) VALUES
    (1, 'Administrateur Système', 'admin',   '$2y$10$1hTsGA34deIVPJopaQJPa.lt/LW6LIxhLmXIko/2T2fqo5dBP28gC', 'administrateur'),
    (2, 'Alice Mballa',           'alice',   '$2y$10$1hTsGA34deIVPJopaQJPa.lt/LW6LIxhLmXIko/2T2fqo5dBP28gC', 'client'),
    (3, 'Bob Ndongo',             'bob',     '$2y$10$1hTsGA34deIVPJopaQJPa.lt/LW6LIxhLmXIko/2T2fqo5dBP28gC', 'client'),
    (4, 'Boutique Mokolo',        'boutique','$2y$10$1hTsGA34deIVPJopaQJPa.lt/LW6LIxhLmXIko/2T2fqo5dBP28gC', 'commercant');

-- Portefeuilles (XAF, USD, EUR pour chaque utilisateur)
INSERT INTO wallets (user_id, devise, solde) VALUES
    (1, 'XAF', 0),        (1, 'USD', 0),      (1, 'EUR', 0),
    (2, 'XAF', 500000),   (2, 'USD', 200),    (2, 'EUR', 150),
    (3, 'XAF', 200000),   (3, 'USD', 50),     (3, 'EUR', 20),
    (4, 'XAF', 10000),    (4, 'USD', 0),      (4, 'EUR', 0);

-- Taux de change simulés (1 devise_source = taux devise_cible)
-- Le taux inverse (ex. XAF -> USD) est déduit automatiquement : 1 / taux.
INSERT INTO taux_change (devise_source, devise_cible, taux, date_effet, modifie_par) VALUES
    ('USD', 'XAF',  600.0000000000, '2026-01-01 00:00:00', 1),
    ('EUR', 'XAF',  656.0000000000, '2026-01-01 00:00:00', 1),
    ('EUR', 'USD',    1.0900000000, '2026-01-01 00:00:00', 1);

-- Paramètres configurables (hypothèses de classe, pas des conseils fiscaux)
INSERT INTO parametres (cle, valeur, description) VALUES
    ('frais_pourcentage',  '0.025',  'Frais en pourcentage du montant source (2,5 %)'),
    ('frais_fixe_xaf',     '100',    'Frais fixes exprimés en XAF (convertis si la devise source est différente)'),
    ('taux_taxe',          '0.1925', 'Taxe sur les frais de transaction (19,25 % - hypothèse de classe)'),
    ('taux_commission',    '0.01',   'Commission commerçant sur le montant converti (1 %)'),
    ('commission_deduite', '1',      '1 = commission déduite du règlement du commerçant, 0 = non déduite');
