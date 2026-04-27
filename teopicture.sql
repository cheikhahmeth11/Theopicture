-- ============================================================
--  TEOPICTURE — Script SQL pour WAMP (MySQL)
--  Copiez-collez ce code dans phpMyAdmin > Onglet SQL
-- ============================================================

-- 1. Créer la base de données
CREATE DATABASE IF NOT EXISTS teopicture
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE teopicture;

-- 2. Table des réservations
CREATE TABLE IF NOT EXISTS reservations (
  id          INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
  nom         VARCHAR(120)    NOT NULL,
  telephone   VARCHAR(30)     NOT NULL,
  evenement   VARCHAR(60)     NOT NULL,
  date_event  DATE            NOT NULL,
  heure       TIME            NOT NULL,
  lieu        VARCHAR(200)    NOT NULL DEFAULT 'Non précisé',
  message     TEXT            NULL,
  statut      ENUM('en attente','confirmé','annulé') NOT NULL DEFAULT 'en attente',
  cree_le     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_date (date_event),
  INDEX idx_statut (statut)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Table des messages de contact
CREATE TABLE IF NOT EXISTS contacts (
  id        INT UNSIGNED  AUTO_INCREMENT PRIMARY KEY,
  nom       VARCHAR(120)  NOT NULL,
  telephone VARCHAR(30)   NOT NULL,
  message   TEXT          NOT NULL,
  lu        TINYINT(1)    NOT NULL DEFAULT 0,
  cree_le   DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Utilisateur admin (optionnel, pour l'interface admin.html)
CREATE TABLE IF NOT EXISTS admin_users (
  id           INT UNSIGNED  AUTO_INCREMENT PRIMARY KEY,
  username     VARCHAR(60)   NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  cree_le      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insérer un admin par défaut (mot de passe : admin123)
INSERT INTO admin_users (username, password_hash) VALUES
  ('admin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uHV1iDEXe');

-- ============================================================
--  Terminé ! Base de données prête.
-- ============================================================
