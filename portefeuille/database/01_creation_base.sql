-- =====================================================================
-- INF151 - TP 2026/2027 - Portefeuille numérique multidevise
-- Script 1/2 : CRÉATION DE LA BASE ET DES TABLES (aucune donnée ici)
-- Compatible MySQL 8.0.16+ / MariaDB 10.2+ (contraintes CHECK actives)
-- Exécution : mysql -u root -p < database/01_creation_base.sql
-- =====================================================================

CREATE DATABASE IF NOT EXISTS portefeuille_db
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE portefeuille_db;

-- ---------------------------------------------------------------------
-- Devises supportées (decimales = nombre de décimales de règlement)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS devises (
    code      CHAR(3)      NOT NULL,
    nom       VARCHAR(60)  NOT NULL,
    decimales TINYINT      NOT NULL DEFAULT 2,
    PRIMARY KEY (code),
    CONSTRAINT chk_devises_decimales CHECK (decimales BETWEEN 0 AND 4)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- Utilisateurs (PIN stocké haché avec password_hash, jamais en clair)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    nom_complet  VARCHAR(100) NOT NULL,
    identifiant  VARCHAR(50)  NOT NULL,
    pin_hash     VARCHAR(255) NOT NULL,
    role         ENUM('client','administrateur','commercant') NOT NULL DEFAULT 'client',
    statut       ENUM('actif','suspendu') NOT NULL DEFAULT 'actif',
    created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_users_identifiant (identifiant)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- Portefeuilles : un solde par utilisateur et par devise (jamais < 0)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS wallets (
    id       INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    user_id  INT UNSIGNED  NOT NULL,
    devise   CHAR(3)       NOT NULL,
    solde    DECIMAL(18,4) NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    UNIQUE KEY uq_wallet_user_devise (user_id, devise),
    CONSTRAINT fk_wallet_user   FOREIGN KEY (user_id) REFERENCES users(id),
    CONSTRAINT fk_wallet_devise FOREIGN KEY (devise)  REFERENCES devises(code),
    CONSTRAINT chk_wallet_solde_positif CHECK (solde >= 0)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- Taux de change (convention : 1 devise_source = taux devise_cible)
-- Une mise à jour = une nouvelle ligne avec une nouvelle date d'effet ;
-- l'historique est conservé pour l'audit.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS taux_change (
    id              INT UNSIGNED   NOT NULL AUTO_INCREMENT,
    devise_source   CHAR(3)        NOT NULL,
    devise_cible    CHAR(3)        NOT NULL,
    taux            DECIMAL(24,10) NOT NULL,
    date_effet      DATETIME       NOT NULL,
    modifie_par     INT UNSIGNED   NOT NULL,
    created_at      DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_taux_paire (devise_source, devise_cible, date_effet),
    CONSTRAINT fk_taux_source FOREIGN KEY (devise_source) REFERENCES devises(code),
    CONSTRAINT fk_taux_cible  FOREIGN KEY (devise_cible)  REFERENCES devises(code),
    CONSTRAINT fk_taux_admin  FOREIGN KEY (modifie_par)   REFERENCES users(id),
    CONSTRAINT chk_taux_positif CHECK (taux > 0),
    CONSTRAINT chk_taux_paire   CHECK (devise_source <> devise_cible)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- Paramètres configurables (frais, taxe, commission) - hypothèses de classe
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS parametres (
    cle          VARCHAR(50)  NOT NULL,
    valeur       VARCHAR(50)  NOT NULL,
    description  VARCHAR(255) NOT NULL,
    PRIMARY KEY (cle)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- Transactions réussies (audit complet : taux et paramètres utilisés)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS transactions (
    id                    INT UNSIGNED   NOT NULL AUTO_INCREMENT,
    reference             VARCHAR(40)    NOT NULL,
    expediteur_id         INT UNSIGNED   NOT NULL,
    destinataire_id       INT UNSIGNED   NOT NULL,
    type_transaction      ENUM('transfert','paiement_commercant') NOT NULL,
    montant_source        DECIMAL(18,4)  NOT NULL,
    devise_source         CHAR(3)        NOT NULL,
    montant_cible         DECIMAL(18,4)  NOT NULL,
    devise_cible          CHAR(3)        NOT NULL,
    taux_change_id        INT UNSIGNED   NULL,
    taux_utilise          DECIMAL(24,10) NOT NULL,
    sens_taux             ENUM('identique','direct','inverse') NOT NULL,
    taux_frais            DECIMAL(8,6)   NOT NULL,
    frais_pourcentage     DECIMAL(18,4)  NOT NULL,
    frais_fixes           DECIMAL(18,4)  NOT NULL,
    frais_total           DECIMAL(18,4)  NOT NULL,
    taux_taxe             DECIMAL(8,6)   NOT NULL,
    montant_taxe          DECIMAL(18,4)  NOT NULL,
    taux_commission       DECIMAL(8,6)   NOT NULL DEFAULT 0,
    montant_commission    DECIMAL(18,4)  NOT NULL DEFAULT 0,
    commission_deduite    TINYINT(1)     NOT NULL DEFAULT 0,
    debit_expediteur      DECIMAL(18,4)  NOT NULL,
    credit_destinataire   DECIMAL(18,4)  NOT NULL,
    statut                ENUM('reussie') NOT NULL DEFAULT 'reussie',
    created_at            DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_transactions_reference (reference),
    KEY idx_tx_expediteur (expediteur_id),
    KEY idx_tx_destinataire (destinataire_id),
    KEY idx_tx_date (created_at),
    CONSTRAINT fk_tx_expediteur   FOREIGN KEY (expediteur_id)   REFERENCES users(id),
    CONSTRAINT fk_tx_destinataire FOREIGN KEY (destinataire_id) REFERENCES users(id),
    CONSTRAINT fk_tx_dev_source   FOREIGN KEY (devise_source)   REFERENCES devises(code),
    CONSTRAINT fk_tx_dev_cible    FOREIGN KEY (devise_cible)    REFERENCES devises(code),
    CONSTRAINT fk_tx_taux         FOREIGN KEY (taux_change_id)  REFERENCES taux_change(id),
    CONSTRAINT chk_tx_montant     CHECK (montant_source > 0)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- Journal des tentatives rejetées (solde insuffisant, etc.).
-- Aucune modification de solde n'est associée à ces lignes.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS journal_echecs (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id     INT UNSIGNED NOT NULL,
    reference   VARCHAR(40)  NULL,
    motif       VARCHAR(255) NOT NULL,
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    CONSTRAINT fk_echec_user FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB;
