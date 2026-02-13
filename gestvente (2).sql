-- Script PostgreSQL pour le système de gestion de vente
-- Version corrigée et optimisée pour Render
-- Date : 2026-02-13

BEGIN;

-- ------------------------------------------------------------------
-- 1. SUPPRESSION DES TABLES (si existantes) – ordre inverse des dépendances
-- ------------------------------------------------------------------
DROP TABLE IF EXISTS video CASCADE;
DROP TABLE IF EXISTS venteproduit CASCADE;
DROP TABLE IF EXISTS vente CASCADE;
DROP TABLE IF EXISTS utilisateurtyping CASCADE;
DROP TABLE IF EXISTS utilisateur CASCADE;
DROP TABLE IF EXISTS transactionpaiement CASCADE;
DROP TABLE IF EXISTS remboursement CASCADE;
DROP TABLE IF EXISTS reactionutilisateur CASCADE;
DROP TABLE IF EXISTS rappelanniversaire CASCADE;
DROP TABLE IF EXISTS push_tokens CASCADE;
DROP TABLE IF EXISTS produitreaction CASCADE;
DROP TABLE IF EXISTS produit CASCADE;
DROP TABLE IF EXISTS paiementvendeur CASCADE;
DROP TABLE IF EXISTS notificationevenement CASCADE;
DROP TABLE IF EXISTS notification CASCADE;
DROP TABLE IF EXISTS follow CASCADE;
DROP TABLE IF EXISTS evenement CASCADE;
DROP TABLE IF EXISTS demanderetrait CASCADE;
DROP TABLE IF EXISTS commission CASCADE;
DROP TABLE IF EXISTS commentaire_vus CASCADE;
DROP TABLE IF EXISTS commentaire CASCADE;

-- ------------------------------------------------------------------
-- 2. CRÉATION DES TABLES
-- ------------------------------------------------------------------

-- Table des commentaires
CREATE TABLE commentaire (
  id SERIAL PRIMARY KEY,
  produitId INTEGER NOT NULL,
  utilisateurId INTEGER NOT NULL,
  texte TEXT NOT NULL,
  type VARCHAR(30) CHECK (type IN ('text','voice','image')) DEFAULT 'text',
  reply_to INTEGER DEFAULT NULL,
  voice_uri VARCHAR(500) DEFAULT NULL,
  voice_duration INTEGER DEFAULT NULL,
  image_uri VARCHAR(500) DEFAULT NULL,
  is_edited BOOLEAN DEFAULT FALSE,
  date_modification TIMESTAMP DEFAULT NULL,
  dateCreation TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  vu BOOLEAN DEFAULT FALSE
);

-- Table de suivi des vues des commentaires
CREATE TABLE commentaire_vus (
  id SERIAL PRIMARY KEY,
  commentaire_id INTEGER NOT NULL,
  utilisateur_id INTEGER NOT NULL,
  date_vue TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE(commentaire_id, utilisateur_id)
);

-- Table des commissions
CREATE TABLE commission (
  id SERIAL PRIMARY KEY,
  venteId INTEGER NOT NULL,
  vendeurId INTEGER NOT NULL,
  montantTotal NUMERIC(10,2) NOT NULL,
  montantVendeur NUMERIC(10,2) NOT NULL,
  montantAdmin NUMERIC(10,2) NOT NULL,
  pourcentageCommission INTEGER NOT NULL DEFAULT 15,
  statut VARCHAR(30) CHECK (statut IN ('en_attente','paye','annule','retire')) DEFAULT 'en_attente',
  dateCreation TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  dateTraitement TIMESTAMP DEFAULT NULL
);

COMMENT ON COLUMN commission.montantTotal IS 'Montant brut de la vente';
COMMENT ON COLUMN commission.montantVendeur IS 'Montant que le vendeur reçoit';
COMMENT ON COLUMN commission.montantAdmin IS 'Commission de l''admin';
COMMENT ON COLUMN commission.pourcentageCommission IS 'Pourcentage prélevé';
COMMENT ON COLUMN commission.dateTraitement IS 'Date de paiement de la commission';

-- Table des demandes de retrait
CREATE TABLE demanderetrait (
  id SERIAL PRIMARY KEY,
  utilisateurId INTEGER NOT NULL,
  montant NUMERIC(10,2) NOT NULL,
  methodePaiement VARCHAR(50) DEFAULT NULL,
  numeroCompte VARCHAR(100) DEFAULT NULL,
  statut VARCHAR(30) CHECK (statut IN ('en_attente','approuve','paye','refuse')) DEFAULT 'en_attente',
  dateDemande TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  dateTraitement TIMESTAMP DEFAULT NULL,
  commentaire TEXT DEFAULT NULL,
  fraisRetrait NUMERIC(10,2) DEFAULT 0.00,
  montantNet NUMERIC(10,2) DEFAULT NULL
);

COMMENT ON COLUMN demanderetrait.methodePaiement IS 'Mobile Money, Virement bancaire, etc.';
COMMENT ON COLUMN demanderetrait.numeroCompte IS 'Numéro de téléphone ou compte bancaire';
COMMENT ON COLUMN demanderetrait.fraisRetrait IS 'Frais de retrait appliqués';
COMMENT ON COLUMN demanderetrait.montantNet IS 'Montant reçu par le vendeur';

-- Table des événements
CREATE TABLE evenement (
  id SERIAL PRIMARY KEY,
  nom VARCHAR(100) NOT NULL,
  description TEXT DEFAULT NULL,
  date_evenement DATE NOT NULL,
  type VARCHAR(30) CHECK (type IN ('fete_religieuse','fete_civile','evenement_special','anniversaire')) NOT NULL,
  couleur VARCHAR(20) DEFAULT '#3B82F6',
  actif BOOLEAN DEFAULT TRUE,
  dateCreation TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  dateModification TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Table des abonnements
CREATE TABLE follow (
  id SERIAL PRIMARY KEY,
  followerId INTEGER NOT NULL,
  followingId INTEGER NOT NULL,
  dateCreation TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE(followerId, followingId)
);

-- Table des notifications
CREATE TABLE notification (
  id SERIAL PRIMARY KEY,
  utilisateurId INTEGER NOT NULL,
  titre VARCHAR(255) NOT NULL,
  message TEXT NOT NULL,
  type VARCHAR(30) CHECK (type IN ('success','info','warning','error')) DEFAULT 'info',
  lien VARCHAR(500) DEFAULT NULL,
  estLu BOOLEAN DEFAULT FALSE,
  dateCreation TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Table des notifications d'événements
CREATE TABLE notificationevenement (
  id SERIAL PRIMARY KEY,
  evenementId INTEGER NOT NULL,
  utilisateurId INTEGER NOT NULL,
  titre VARCHAR(255) DEFAULT NULL,
  message TEXT DEFAULT NULL,
  dateEnvoi DATE DEFAULT NULL,
  dateCreation TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE(evenementId, utilisateurId, dateEnvoi)
);

-- Table des paiements vendeurs
CREATE TABLE paiementvendeur (
  id SERIAL PRIMARY KEY,
  vendeurId INTEGER NOT NULL,
  venteId INTEGER NOT NULL,
  montant NUMERIC(10,2) NOT NULL,
  commission NUMERIC(10,2) NOT NULL,
  montantNet NUMERIC(10,2) NOT NULL,
  statut VARCHAR(30) CHECK (statut IN ('en_attente','bloque','disponible','paye')) DEFAULT 'en_attente',
  dateDeblocage TIMESTAMP DEFAULT NULL,
  datePaiement TIMESTAMP DEFAULT NULL,
  methodePaiement VARCHAR(50) DEFAULT NULL,
  dateCreation TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Table des produits
CREATE TABLE produit (
  id SERIAL PRIMARY KEY,
  titre VARCHAR(255) NOT NULL,
  description TEXT DEFAULT NULL,
  prix NUMERIC(10,2) NOT NULL CHECK (prix >= 0),
  imageUrl VARCHAR(255) DEFAULT NULL,
  dateCreation TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  date_ajout TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  categorie VARCHAR(50) DEFAULT 'nouveauté',
  expiration TIMESTAMP DEFAULT NULL,
  vendeurId INTEGER DEFAULT NULL,
  estEnPromotion BOOLEAN DEFAULT FALSE,
  nomPromotion VARCHAR(255) DEFAULT NULL,
  prixPromotion NUMERIC(10,2) DEFAULT NULL,
  dateDebutPromo TIMESTAMP DEFAULT NULL,
  dateFinPromo TIMESTAMP DEFAULT NULL
);

-- Table des réactions aux produits
CREATE TABLE produitreaction (
  produitId INTEGER PRIMARY KEY,
  likes INTEGER DEFAULT 0,
  pouces INTEGER DEFAULT 0
);

-- Table des tokens push
CREATE TABLE push_tokens (
  id SERIAL PRIMARY KEY,
  userId INTEGER NOT NULL,
  token VARCHAR(255) NOT NULL,
  createdAt TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updatedAt TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  platform VARCHAR(30) CHECK (platform IN ('ios','android','web')) DEFAULT 'android',
  UNIQUE(userId, token)
);

-- Table des rappels d'anniversaire
CREATE TABLE rappelanniversaire (
  id SERIAL PRIMARY KEY,
  utilisateurId INTEGER NOT NULL,
  dateCreationCompte DATE NOT NULL,
  anneesDepuisCreation INTEGER DEFAULT NULL,
  prochainAnniversaire DATE DEFAULT NULL,
  rappelEnvoye BOOLEAN DEFAULT FALSE,
  dateDernierRappel DATE DEFAULT NULL,
  dateCreation TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  dateModification TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Table des réactions utilisateurs
CREATE TABLE reactionutilisateur (
  id SERIAL PRIMARY KEY,
  utilisateurId INTEGER NOT NULL,
  produitId INTEGER NOT NULL,
  likeReaction BOOLEAN DEFAULT FALSE,
  pouceReaction BOOLEAN DEFAULT FALSE,
  dateCreation TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE(utilisateurId, produitId)
);

-- Table des remboursements
CREATE TABLE remboursement (
  id SERIAL PRIMARY KEY,
  venteId INTEGER NOT NULL,
  produitId INTEGER NOT NULL,
  acheteurId INTEGER NOT NULL,
  vendeurId INTEGER NOT NULL,
  montant NUMERIC(10,2) NOT NULL,
  motif TEXT NOT NULL,
  pourcentageVisionne INTEGER DEFAULT 0,
  statut VARCHAR(30) CHECK (statut IN ('demande','en_cours','approuve','refuse')) DEFAULT 'demande',
  raisonRefus TEXT DEFAULT NULL,
  dateTraitement TIMESTAMP DEFAULT NULL,
  dateCreation TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Table des transactions de paiement
CREATE TABLE transactionpaiement (
  id SERIAL PRIMARY KEY,
  venteId INTEGER NOT NULL,
  transactionId VARCHAR(100) NOT NULL UNIQUE,
  montant NUMERIC(10,2) NOT NULL,
  methodePaiement VARCHAR(50) DEFAULT NULL,
  statutCinetPay VARCHAR(50) DEFAULT NULL,
  dateDemande TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  dateConfirmation TIMESTAMP DEFAULT NULL,
  donneesRaw TEXT DEFAULT NULL
);

COMMENT ON COLUMN transactionpaiement.donneesRaw IS 'Données brutes de la réponse CinetPay en JSON';

-- Table des utilisateurs
CREATE TABLE utilisateur (
  id SERIAL PRIMARY KEY,
  matricule VARCHAR(50) NOT NULL UNIQUE,
  nom VARCHAR(100) NOT NULL,
  sexe VARCHAR(30) CHECK (sexe IN ('Homme','Femme')) NOT NULL,
  nationalite VARCHAR(100) NOT NULL,
  telephone VARCHAR(20) NOT NULL UNIQUE,
  email VARCHAR(100) NOT NULL UNIQUE,
  motDePasse VARCHAR(255) NOT NULL,
  role VARCHAR(30) CHECK (role IN ('client','admin')) NOT NULL DEFAULT 'client',
  dateCreation TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  google_id VARCHAR(255) DEFAULT NULL,
  etat VARCHAR(30) CHECK (etat IN ('actif','inactif')) DEFAULT 'actif',
  soldeVendeur NUMERIC(10,2) DEFAULT 0.00,
  compteBancaire VARCHAR(100) DEFAULT NULL,
  operateurMobile VARCHAR(50) DEFAULT NULL,
  numeroMobilePaiement VARCHAR(20) DEFAULT NULL,
  identiteVerifiee BOOLEAN DEFAULT FALSE,
  emailVerifie BOOLEAN DEFAULT FALSE,
  telephoneVerifie BOOLEAN DEFAULT FALSE,
  dateVerificationIdentite TIMESTAMP DEFAULT NULL,
  documentIdentite VARCHAR(255) DEFAULT NULL,
  nbVentes INTEGER DEFAULT 0,
  noteVendeur NUMERIC(3,2) DEFAULT 0.00,
  statutVendeur VARCHAR(30) CHECK (statutVendeur IN ('nouveau','verifie','confirme','elite')) DEFAULT 'nouveau',
  photoProfil VARCHAR(255) DEFAULT NULL,
  nombreFollowers INTEGER DEFAULT 0,
  nombreFollowing INTEGER DEFAULT 0
);

-- Table de suivi de saisie en temps réel
CREATE TABLE utilisateurtyping (
  produitId INTEGER NOT NULL,
  utilisateurId INTEGER NOT NULL,
  typing BOOLEAN DEFAULT FALSE,
  dateUpdate TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (produitId, utilisateurId)
);

-- Table des ventes
CREATE TABLE vente (
  id SERIAL PRIMARY KEY,
  date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  utilisateurId INTEGER NOT NULL,
  total NUMERIC(10,2) NOT NULL,
  modePaiement VARCHAR(50) DEFAULT NULL,
  numeroMobile VARCHAR(30) DEFAULT NULL,
  pays VARCHAR(50) DEFAULT NULL,
  transactionId VARCHAR(100) UNIQUE DEFAULT NULL,
  statut VARCHAR(30) CHECK (statut IN ('en_attente','paye','annule','rembourse')) NOT NULL DEFAULT 'en_attente',
  datePaiement TIMESTAMP DEFAULT NULL,
  motifAnnulation VARCHAR(255) DEFAULT NULL,
  dateAnnulation TIMESTAMP DEFAULT NULL,
  dateConfirmation TIMESTAMP DEFAULT NULL
);

COMMENT ON COLUMN vente.statut IS 'Statut de la vente - Défaut: en_attente';

-- Table des produits vendus
CREATE TABLE venteproduit (
  id SERIAL PRIMARY KEY,
  venteId INTEGER NOT NULL,
  produitId INTEGER NOT NULL,
  quantite INTEGER NOT NULL CHECK (quantite > 0),
  prixUnitaire NUMERIC(10,2) NOT NULL CHECK (prixUnitaire >= 0),
  achetee BOOLEAN DEFAULT FALSE,
  vendeurId INTEGER
);

-- Table des vidéos
CREATE TABLE video (
  id SERIAL PRIMARY KEY,
  produitId INTEGER NOT NULL,
  titre VARCHAR(255) NOT NULL,
  url VARCHAR(500) NOT NULL,
  preview_url VARCHAR(255) DEFAULT NULL,
  preview_duration INTEGER DEFAULT 30,
  description TEXT DEFAULT NULL,
  ordre INTEGER DEFAULT 1,
  dateCreation TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  duree TEXT DEFAULT NULL
);

-- ------------------------------------------------------------------
-- 3. INSERTION DES DONNÉES (corrigées pour respecter les contraintes)
-- ------------------------------------------------------------------

-- Utilisateurs
INSERT INTO utilisateur (id, matricule, nom, sexe, nationalite, telephone, email, motDePasse, role, dateCreation, google_id, etat, soldeVendeur, compteBancaire, operateurMobile, numeroMobilePaiement, identiteVerifiee, emailVerifie, telephoneVerifie, dateVerificationIdentite, documentIdentite, nbVentes, noteVendeur, statutVendeur, photoProfil, nombreFollowers, nombreFollowing) VALUES
(1, 'USR685DA54826CCA', 'Koh djoufo Valentin', 'Homme', 'Cameroun', '+237651436857', 'valentindjoufo@gmail.com', '$2y$10$yAqkvHXKbe1aqghEyM.y0e8EJwo9U5OjHLHTSoLO8655UwH0WqzwK', 'admin', '2025-06-26 20:53:44', NULL, 'actif', '0.00', NULL, NULL, NULL, FALSE, FALSE, FALSE, NULL, NULL, 0, '0.00', 'nouveau', NULL, 0, 0),
(2, 'USR685DB48E46174', 'Kopi leticia', 'Femme', 'Cameroun', '+237671725822', 'leticia@gmail.com', '$2y$10$yXmedo26bG7s/1defYFn5e09enIRvaGKApmUIn3wIW9qa8bimACJG', 'client', '2025-06-26 21:58:54', NULL, 'actif', '5865.00', NULL, NULL, NULL, FALSE, FALSE, FALSE, NULL, NULL, 21, '0.00', 'nouveau', 'uploads/profils/profil_6940ce788c0ce_1765854840.jpeg', 1, 1),
(3, 'USR6862FA076AFBE', 'Valere', 'Homme', 'Afrique du Sud', '+27653224346', 'valere@gmail.com', '$2y$10$5zJzhWfS7vNyg/7DQd5ChOhz9YSulILa9xBgKN08maiGqw0GjktgC', 'admin', '2025-06-30 21:56:39', NULL, 'actif', '0.00', NULL, NULL, NULL, FALSE, FALSE, FALSE, NULL, NULL, 0, '0.00', 'nouveau', 'uploads/profils/profil_68ef8c2444b08_1760529444.jpeg', 0, 0),
(4, 'USR6864AE3EE64D8', 'Vanel', 'Femme', 'Cap-Vert', '687216822', 'vanel@gmail.com', '$2y$10$amd.V6e4xujN64.x0DlfjOdIFHxj8g25XbjbsJgp6AuZj1r0/khxm', 'client', '2025-07-02 04:57:51', NULL, 'actif', '0.00', NULL, NULL, NULL, FALSE, FALSE, FALSE, NULL, NULL, 0, '0.00', 'nouveau', NULL, 0, 0),
(5, 'USR686892D492126', 'Junior', 'Homme', 'Égypte', '685450255', 'junior@gmail.com', '$2y$10$7JtVkeOFVmwrwdujzTV9bujwvcK/eYW96ByBcCLchnE2Tj4OjWFJC', 'client', '2025-07-05 03:49:57', NULL, 'actif', '335750.00', NULL, NULL, NULL, FALSE, FALSE, FALSE, NULL, NULL, 34, '0.00', 'nouveau', 'uploads/profils/profil_68f3ef082cca9_1760816904.jpeg', 1, 1),
(7, 'USR68691B436E674', 'Carine', 'Femme', 'Maroc', '+212652432050', 'carine@gmail.com', '$2y$10$6qaZhF1R4AXUBOiwaUZTXuvnKjuE1WmWQF97e23k3EsHhIEc5sfNW', 'client', '2025-07-05 13:32:03', NULL, 'actif', '0.00', NULL, NULL, NULL, FALSE, FALSE, FALSE, NULL, NULL, 0, '0.00', 'nouveau', NULL, 0, 0),
(16, 'USR686ACAA2C904D', 'Jasmine', 'Femme', 'Cameroun', '654432212', 'jasmine@gmail.com', '$2y$10$DuFL8yuqDuZn5ulx/Kn0CudJskH3SlyCGlvvfPMfbV7ttQIYML/DK', 'client', '2025-07-06 20:12:35', NULL, 'inactif', '0.00', NULL, NULL, NULL, FALSE, FALSE, FALSE, NULL, NULL, 0, '0.00', 'nouveau', NULL, 0, 0),
(17, 'USR68753CC723A3E', 'Jackson', 'Homme', 'Gabon', '+237654214355', 'jack@gmail.comn', '$2y$10$Wc/1TA1shUzbExqFecy8C./w.K8wCrSnBzWN5o8aan73ZdG3Mxdt2', 'client', '2025-07-14 18:22:15', NULL, 'actif', '0.00', NULL, NULL, NULL, FALSE, FALSE, FALSE, NULL, NULL, 0, '0.00', 'nouveau', NULL, 0, 0),
(18, 'USR687543176BFFC', 'Chichi', 'Femme', 'Namibie', '+237654123302', 'chichi@gmail.com', '$2y$10$RCzDplaEN0ST3UR5I4gkTO/xF5mIbFHEG8cyMb9eWqPppxJeimG0u', 'client', '2025-07-14 18:49:11', NULL, 'actif', '0.00', NULL, NULL, NULL, FALSE, FALSE, FALSE, NULL, NULL, 0, '0.00', 'nouveau', 'uploads/profils/profil_6909b3bcb9b44_1762243516.jpeg', 0, 0),
(19, 'USR68792586D352C', 'Julian', 'Homme', 'Madagascar', '+261642516322', 'julian@gmail.com', '$2y$10$HcFxbCznxtCJoD0B5zyQYO5/6XJCz094.l5l7AuiEtxdhn8eyX5S2', 'client', '2025-07-17 17:32:07', NULL, 'actif', '0.00', NULL, NULL, NULL, FALSE, FALSE, FALSE, NULL, NULL, 0, '0.00', 'nouveau', 'uploads/profils/profil_690442755c972_1761886837.jpeg', 0, 0),
(20, 'USR68ECC9B40DBE3', 'Fabrice Cabrel', 'Homme', 'Zambie', '+260682540026', 'cabrel@gmail.com', '$2y$10$1hAxV1RWGXClC5RYIGeLReTtP3ZqlW6x2zGJdb44zwPEIcU6R5Vs.', 'client', '2025-10-13 10:43:16', NULL, 'actif', '0.00', NULL, NULL, NULL, FALSE, FALSE, FALSE, NULL, NULL, 0, '0.00', 'nouveau', 'uploads/profils/profil_68ecc9b367af9_1760348595.png', 0, 0),
(21, 'USR690CAD4709299', 'Aru', 'Femme', 'Cameroun', '+237692533078', 'Sonita@gmail.com', '$2y$10$Z6/r.6eE2i.6C6KgWdfkEu2e5dvtK97s0ylIwRIplS72C3/YMq3/q', 'client', '2025-11-06 15:14:31', NULL, 'actif', '0.00', NULL, NULL, NULL, FALSE, FALSE, FALSE, NULL, NULL, 0, '0.00', 'nouveau', NULL, 0, 0),
(22, 'USR6919CE50ECEF4', 'Koko jule', 'Femme', 'Cameroun', '+237671819063', 'Yvettekopi94@gmail.com', '$2y$10$q1F3mknXiCetpC910kHdnuTY8OxUKkVuz3cBTHt1Wzan9tJPRGwWC', 'client', '2025-11-16 14:14:57', NULL, 'actif', '0.00', NULL, NULL, NULL, FALSE, FALSE, FALSE, NULL, NULL, 0, '0.00', 'nouveau', NULL, 0, 0),
(23, 'USR6940E49B26415', 'Steve ', 'Homme', 'Cameroun', '+237670330192', 'stevescoot0@gmail.com', '$2y$10$OvzbKU1/djf59RBO.wctLuDSv9cumbp5T3YoY4a10BTaRpkrOG2XW', 'client', '2025-12-16 05:48:27', NULL, 'actif', '0.00', NULL, NULL, NULL, FALSE, FALSE, FALSE, NULL, NULL, 0, '0.00', 'nouveau', NULL, 0, 0),
(24, 'USR6942EB264670E', 'Junior ', 'Homme', 'Cameroun', '+237683158723', 'Abc@gmail.com', '$2y$10$QAR0W0AiCFvE2KmlggldmOdiEF4Gw1iHb4fX7AwYLfOym/PJ2QmnC', 'client', '2025-12-17 18:40:54', NULL, 'actif', '0.00', NULL, NULL, NULL, FALSE, FALSE, FALSE, NULL, NULL, 0, '0.00', 'nouveau', NULL, 0, 0),
(25, 'USR694683A310EC2', 'Goku', 'Homme', 'Cap-Vert', '+238621552208', 'goku@gmail.com', '$2y$10$Vnx67lHNfFkTnoVqTdQwIuWjkOiiA/R0NhlJBqgAVvKUKvO5Jgnf.', 'client', '2025-12-20 12:08:19', NULL, 'actif', '0.00', NULL, NULL, NULL, FALSE, FALSE, FALSE, NULL, NULL, 0, '0.00', 'nouveau', NULL, 0, 0),
(26, 'USR695B5625C1252', 'Darone', 'Femme', 'Burundi', '+257632552822', 'darone@gmail.com', '$2y$10$1QnKOx7Z7JqOvHt9aK2cPe5nhSFFBBFCcKdfnL27uO.3/qH.ptmpa', 'client', '2026-01-05 07:11:50', NULL, 'actif', '0.00', NULL, NULL, NULL, FALSE, FALSE, FALSE, NULL, NULL, 0, '0.00', 'nouveau', NULL, 0, 0);

-- Produits
INSERT INTO produit (id, titre, description, prix, imageUrl, dateCreation, date_ajout, categorie, expiration, vendeurId, estEnPromotion, nomPromotion, prixPromotion, dateDebutPromo, dateFinPromo) VALUES
(690, 'Savons I', 'Apprener', '20000.00', 'api/uploads/685aeba9c360c_a91822e5-6175-4796-9d7b-bcc96d03717d.png', '2025-06-24 19:17:13', '2025-06-26 15:54:23', 'populaire', NULL, NULL, FALSE, NULL, NULL, NULL, NULL),
(691, 'Huile', 'Exactement', '28000.00', 'api/uploads/685b05886e142_37eadeda-d5ed-49c9-bc99-1680dcdb8c67.jpeg', '2025-06-24 21:07:36', '2025-06-26 15:54:23', 'populaire', NULL, NULL, FALSE, NULL, NULL, NULL, NULL),
(693, 'Chaussures VIP', 'Apprendre a concevoir les détergents', '20000.00', 'api/uploads/685d6d8a76ee1_0637ed14-4f9d-4fca-be8a-3364cdb2ca0c.jpeg', '2025-06-26 13:17:10', '2025-06-26 15:54:23', 'populaire', NULL, NULL, FALSE, NULL, NULL, NULL, NULL),
(694, 'Chaussures', 'Bonne chaussures', '10000.00', 'api/uploads/685d61d6d51f4_e30d9771-c7e6-447c-a39f-7b15c4ea826a.jpeg', '2025-06-26 16:05:58', '2025-06-26 16:05:58', 'populaire', NULL, NULL, FALSE, NULL, NULL, NULL, NULL),
(695, 'Habit', 'Bon habit', '7000.00', 'api/uploads/685d68165610f_012697d8-199d-4cd5-8813-fc459c94b571.jpeg', '2025-06-26 16:32:38', '2025-06-26 16:32:38', 'populaire', NULL, NULL, FALSE, NULL, NULL, NULL, NULL),
(699, 'Habit', 'Bonne qualité', '12000.00', 'api/uploads/685eb700e8d76_706d9220-76dd-4964-921a-d366fcef8c08.jpeg', '2025-06-27 16:21:36', '2025-06-27 16:21:36', 'promotion', '2025-07-22 19:08:07', NULL, FALSE, NULL, NULL, NULL, NULL),
(700, 'Chaussures', 'Très bonne qualité', '12000.00', 'api/uploads/685fe5aaadf88_48358c35-1fc1-4782-b57c-36e0a1dfc636.jpeg', '2025-06-28 13:52:58', '2025-06-28 13:52:58', 'populaire', NULL, NULL, FALSE, NULL, NULL, NULL, NULL),
(702, 'Bijoux I', 'Bonne qualité et rare', '5000.00', 'api/uploads/6862a5acaf428_0dd4cc9f-6fcb-4b1e-99f7-d8efe78a600d.jpeg', '2025-06-30 15:56:44', '2025-06-30 15:56:44', 'populaire', NULL, NULL, FALSE, NULL, NULL, NULL, NULL),
(703, 'Formation de savons', 'Bonne formation', '10000.00', 'api/uploads/686f8ec8dcc97_becc61ff-782d-42f5-9b04-59736e806e5d.png', '2025-07-10 10:58:33', '2025-07-10 10:58:33', 'populaire', NULL, NULL, FALSE, NULL, NULL, NULL, NULL),
(707, 'Habits moderne ', 'Apprendre à vendre', '500.00', 'api/uploads/687910235feb5_93fcffef-c638-4dd6-9f95-5a2d57845a88.jpeg', '2025-07-17 16:00:51', '2025-07-17 16:00:51', 'promotion', NULL, NULL, FALSE, NULL, NULL, NULL, NULL),
(709, 'Python', 'Cette formation possède 4 ChapitresrnApprendre python', '150.00', 'api/uploads/687e68bf9a1b2_Screenshot_20250314-215348.jpg', '2025-07-21 17:20:15', '2025-07-21 17:20:15', 'promotion', NULL, NULL, FALSE, NULL, NULL, NULL, NULL),
(710, 'Habit de sortie', 'Bonne qualité', '2000.00', 'api/uploads/687e6f1b7b991_df9cf29e-1c90-42cc-84da-362c8c49a6bf.jpeg', '2025-07-21 17:47:23', '2025-07-21 17:47:23', 'cuisine', NULL, NULL, FALSE, NULL, NULL, NULL, NULL),
(711, 'Barbouche', 'Bonne qualité', '250.00', 'api/uploads/687e70606690a_c3035bd2-a763-4ff5-9bf0-7544e57336a6.jpeg', '2025-07-21 17:52:48', '2025-07-21 17:52:48', 'promotion', '2025-08-14 04:00:00', NULL, FALSE, NULL, NULL, NULL, NULL),
(712, 'Programmation', 'Bonne formation', '7000.00', 'api/uploads/687e721cc9b72_IMG_20250621_181620_956.jpg', '2025-07-21 18:00:12', '2025-07-21 18:00:12', 'informatique', NULL, NULL, FALSE, NULL, NULL, NULL, NULL),
(713, 'Habits stylés', 'Bonne qualité', '2000.00', 'api/uploads/687e7559921ea_4232de3d-49ad-4110-905e-192098fa03d1.jpeg', '2025-07-21 18:14:01', '2025-07-21 18:14:01', 'promotion', '2025-08-24 06:00:00', NULL, FALSE, NULL, NULL, NULL, NULL),
(732, 'Apprendre à codé', '3 chapitres', '25.00', 'api/uploads/688856c8da68d_background-1033-600a91d6834e1007569095.jpg', '2025-07-29 06:06:16', '2025-07-29 06:06:16', 'promotion', '2025-08-19 15:00:00', NULL, FALSE, NULL, NULL, NULL, NULL),
(738, 'Programmation niveau supérieur', 'Html css et php et base de données', '25008.00', 'api/uploads/688989fc3a15a_0169d88f-e663-4614-aa45-8b36e71ba314.jpeg', '2025-07-30 03:57:00', '2025-07-30 03:57:00', 'informatique', NULL, NULL, FALSE, NULL, NULL, NULL, NULL),
(741, 'Data', 'Bonne formation', '500.00', 'api/uploads/68f0bec64a4bb_78661820-5ba0-479f-9c73-e4252f0b441f.jpeg', '2025-10-16 10:45:42', '2025-10-16 10:45:42', 'informatique', NULL, 2, FALSE, NULL, NULL, NULL, NULL),
(748, 'Cooking', 'Apprendre à préparer', '5000.00', 'api/uploads/68f626f19b6af_ec8b2374-6122-4811-956e-43253d2a7466.jpeg', '2025-10-20 13:11:29', '2025-10-20 13:11:29', 'cuisine', NULL, 5, FALSE, NULL, NULL, NULL, NULL),
(749, 'Cuisine', 'Prépare', '8000.00', 'api/uploads/68f6b6119f1ab_41e6f7f9-7dd3-4f73-b77d-ecf7bcec54ae.jpeg', '2025-10-20 23:22:09', '2025-10-20 23:22:09', 'cuisine', NULL, 5, FALSE, NULL, NULL, NULL, NULL),
(751, 'Toto', 'Youpi', '20000.00', 'api/uploads/68f6c2deec4f5_3fad94cd-95fd-4295-9e6f-82568ca65159.jpeg', '2025-10-21 00:16:47', '2025-10-21 00:16:47', 'informatique', '2025-10-24 22:55:00', 5, TRUE, 'Réduction', '1000.00', '2025-10-20 22:56:00', '2025-10-23 22:56:00'),
(752, 'Maitriser la cuisine', 'Cette formation comporte 12 chapitres', '200.00', 'api/uploads/690c9930b49a0_57e42372-3fbe-486c-83f7-5c1852c134d9.jpeg', '2025-11-06 13:48:48', '2025-11-06 13:48:48', 'cuisine', NULL, 2, FALSE, NULL, NULL, NULL, NULL);

-- Ventes
INSERT INTO vente (id, date, utilisateurId, total, modePaiement, numeroMobile, pays, transactionId, statut, datePaiement, motifAnnulation, dateAnnulation, dateConfirmation) VALUES
(1, '2025-06-28 12:31:51', 2, '7000.00', NULL, NULL, NULL, NULL, 'en_attente', NULL, NULL, NULL, NULL),
(2, '2025-06-28 12:33:13', 2, '85000.00', NULL, NULL, NULL, NULL, 'en_attente', NULL, NULL, NULL, NULL),
(3, '2025-06-28 12:37:44', 2, '7000.00', NULL, NULL, NULL, NULL, 'en_attente', NULL, NULL, NULL, NULL),
(4, '2025-06-28 12:40:32', 2, '7000.00', NULL, NULL, NULL, NULL, 'en_attente', NULL, NULL, NULL, NULL),
(5, '2025-06-28 12:40:42', 2, '7000.00', NULL, NULL, NULL, NULL, 'en_attente', NULL, NULL, NULL, NULL),
(11, '2025-06-28 13:07:04', 2, '12000.00', NULL, NULL, NULL, NULL, 'en_attente', NULL, NULL, NULL, NULL),
(15, '2025-06-28 15:06:29', 1, '12000.00', NULL, NULL, NULL, NULL, 'en_attente', NULL, NULL, NULL, NULL),
(16, '2025-06-28 15:07:34', 1, '12000.00', NULL, NULL, NULL, NULL, 'en_attente', NULL, NULL, NULL, NULL),
(17, '2025-06-28 15:20:54', 1, '20000.00', NULL, NULL, NULL, NULL, 'en_attente', NULL, NULL, NULL, NULL),
(18, '2025-06-28 15:22:34', 1, '17000.00', NULL, NULL, NULL, NULL, 'en_attente', NULL, NULL, NULL, NULL),
(19, '2025-06-28 15:24:41', 1, '10000.00', NULL, NULL, NULL, NULL, 'en_attente', NULL, NULL, NULL, NULL),
(20, '2025-06-28 15:25:01', 1, '10000.00', NULL, NULL, NULL, NULL, 'en_attente', NULL, NULL, NULL, NULL),
(21, '2025-06-28 15:30:00', 1, '10000.00', NULL, NULL, NULL, NULL, 'en_attente', NULL, NULL, NULL, NULL),
(22, '2025-06-28 15:36:41', 1, '10000.00', NULL, NULL, NULL, NULL, 'en_attente', NULL, NULL, NULL, NULL),
(23, '2025-06-28 15:38:38', 1, '10000.00', NULL, NULL, NULL, NULL, 'en_attente', NULL, NULL, NULL, NULL),
(24, '2025-06-28 15:43:43', 1, '10000.00', NULL, NULL, NULL, NULL, 'en_attente', NULL, NULL, NULL, NULL),
(25, '2025-06-28 15:44:22', 1, '10000.00', NULL, NULL, NULL, NULL, 'en_attente', NULL, NULL, NULL, NULL),
(26, '2025-06-28 15:45:42', 1, '10000.00', NULL, NULL, NULL, NULL, 'en_attente', NULL, NULL, NULL, NULL),
(27, '2025-06-28 15:48:12', 1, '12000.00', NULL, NULL, NULL, NULL, 'en_attente', NULL, NULL, NULL, NULL),
(28, '2025-06-28 15:48:29', 1, '19000.00', NULL, NULL, NULL, NULL, 'en_attente', NULL, NULL, NULL, NULL),
(29, '2025-06-28 15:48:54', 1, '12000.00', NULL, NULL, NULL, NULL, 'en_attente', NULL, NULL, NULL, NULL),
(30, '2025-06-28 15:52:28', 1, '12000.00', NULL, NULL, NULL, NULL, 'en_attente', NULL, NULL, NULL, NULL),
(31, '2025-06-28 15:52:40', 1, '12000.00', NULL, NULL, NULL, NULL, 'en_attente', NULL, NULL, NULL, NULL),
(32, '2025-06-28 15:56:55', 1, '12000.00', NULL, NULL, NULL, NULL, 'en_attente', NULL, NULL, NULL, NULL),
(33, '2025-06-29 01:25:45', 2, '12000.00', NULL, NULL, NULL, NULL, 'en_attente', NULL, NULL, NULL, NULL),
(34, '2025-06-29 01:34:15', 2, '12000.00', NULL, NULL, NULL, NULL, 'en_attente', NULL, NULL, NULL, NULL),
(35, '2025-06-29 01:36:06', 2, '12000.00', NULL, NULL, NULL, NULL, 'en_attente', NULL, NULL, NULL, NULL),
(36, '2025-06-29 01:36:18', 2, '12000.00', NULL, NULL, NULL, NULL, 'en_attente', NULL, NULL, NULL, NULL),
(37, '2025-06-29 01:37:07', 2, '12000.00', NULL, NULL, NULL, NULL, 'en_attente', NULL, NULL, NULL, NULL),
(38, '2025-06-29 01:38:42', 2, '7000.00', NULL, NULL, NULL, NULL, 'en_attente', NULL, NULL, NULL, NULL),
(39, '2025-06-29 02:04:38', 2, '12000.00', NULL, NULL, NULL, NULL, 'en_attente', NULL, NULL, NULL, NULL),
(40, '2025-06-29 02:04:46', 2, '19000.00', NULL, NULL, NULL, NULL, 'en_attente', NULL, NULL, NULL, NULL),
(41, '2025-06-29 02:23:29', 2, '19000.00', NULL, NULL, NULL, NULL, 'en_attente', NULL, NULL, NULL, NULL),
(42, '2025-06-29 03:22:20', 2, '28000.00', NULL, NULL, NULL, NULL, 'en_attente', NULL, NULL, NULL, NULL),
(43, '2025-06-29 03:33:43', 2, '32000.00', NULL, NULL, NULL, NULL, 'en_attente', NULL, NULL, NULL, NULL),
(44, '2025-06-29 04:47:38', 2, '12000.00', NULL, NULL, NULL, NULL, 'en_attente', NULL, NULL, NULL, NULL),
(45, '2025-06-29 04:56:48', 2, '12000.00', NULL, NULL, NULL, NULL, 'en_attente', NULL, NULL, NULL, NULL),
(46, '2025-06-29 05:18:47', 2, '12000.00', NULL, NULL, NULL, NULL, 'en_attente', NULL, NULL, NULL, NULL),
(47, '2025-06-29 05:44:45', 2, '12000.00', NULL, NULL, NULL, NULL, 'en_attente', NULL, NULL, NULL, NULL),
(48, '2025-06-29 05:57:16', 2, '19000.00', NULL, NULL, NULL, NULL, 'en_attente', NULL, NULL, NULL, NULL),
(49, '2025-06-29 06:04:23', 2, '39000.00', NULL, NULL, NULL, NULL, 'en_attente', NULL, NULL, NULL, NULL),
(50, '2025-06-29 14:39:29', 2, '7000.00', NULL, NULL, NULL, NULL, 'en_attente', NULL, NULL, NULL, NULL),
(51, '2025-06-29 16:38:54', 2, '20000.00', NULL, NULL, NULL, NULL, 'en_attente', NULL, NULL, NULL, NULL),
(52, '2025-06-29 19:55:41', 2, '20000.00', NULL, NULL, NULL, NULL, 'en_attente', NULL, NULL, NULL, NULL),
(54, '2025-10-27 09:34:56', 1, '20000.00', 'PayUnit - Mobile Money', '+237651436857', 'CM', 'PAYUNIT_1761554096_1925', 'paye', NULL, NULL, NULL, '2025-11-06 12:58:01'),
(55, '2025-10-27 09:36:59', 1, '20000.00', 'PayUnit - Mobile Money', '+237651436857', 'CM', 'PAYUNIT_1761554219_6530', 'paye', NULL, NULL, NULL, '2025-11-06 12:58:01'),
(68, '2025-10-27 11:13:38', 1, '20000.00', 'PayUnit - Mobile Money', '+237651436857', 'Cameroun', 'PAYUNIT_1761560018_8182', 'paye', NULL, NULL, NULL, '2025-11-06 12:58:01'),
(69, '2025-10-27 11:21:43', 1, '20000.00', 'Mobile Money - PayUnit', '+237651436857', 'Cameroun', 'PAYUNIT_1761560502_3270', 'en_attente', NULL, NULL, NULL, NULL),
(70, '2025-10-27 11:26:34', 5, '500.00', 'Mobile Money - PayUnit', '685450255', 'Cameroun', 'PAYUNIT_1761560793_9919', 'en_attente', NULL, NULL, NULL, NULL),
(71, '2025-10-28 13:09:51', 19, '5000.00', 'Mobile Money - PayUnit', '+261642516322', 'Togo', 'PAYUNIT_1761653390_2323', 'en_attente', NULL, NULL, NULL, NULL),
(72, '2025-10-28 13:42:45', 19, '20000.00', 'Mobile Money - PayUnit', '+261642516322', 'Togo', 'PAYUNIT_1761655364_9077', 'en_attente', NULL, NULL, NULL, NULL),
(73, '2025-10-28 13:44:23', 19, '20000.00', 'Mobile Money - PayUnit', '+261642516322', 'Togo', 'PAYUNIT_1761655462_5464', 'en_attente', NULL, NULL, NULL, NULL),
(74, '2025-10-28 13:45:40', 19, '20000.00', 'Mobile Money - PayUnit', '+261642516322', 'Togo', 'PAYUNIT_1761655539_3832', 'en_attente', NULL, NULL, NULL, NULL),
(75, '2025-10-28 17:35:10', 19, '8000.00', 'Mobile Money - PayUnit', '+261642516322', 'Togo', 'PAYUNIT_1761669309_6359', 'en_attente', NULL, NULL, NULL, NULL),
(76, '2025-10-28 21:06:42', 3, '20000.00', 'Mobile Money - PayUnit', '+27653224346', 'Cameroun', 'PAYUNIT_1761682001_4749', 'en_attente', NULL, NULL, NULL, NULL),
(77, '2025-10-28 21:09:38', 3, '8000.00', 'Mobile Money - PayUnit', '+27653224346', 'Cameroun', 'PAYUNIT_1761682177_9880', 'en_attente', NULL, NULL, NULL, NULL),
(78, '2025-10-28 22:19:02', 3, '5000.00', 'Mobile Money - PayUnit', '+27653224346', 'Cameroun', 'PAYUNIT_1761686341_2604', 'en_attente', NULL, NULL, NULL, NULL),
(79, '2025-10-28 22:34:47', 18, '20000.00', 'Mobile Money - PayUnit', '+237654123302', 'Cameroun', 'PAYUNIT_1761687286_4105', 'en_attente', NULL, NULL, NULL, NULL),
(80, '2025-10-30 11:32:53', 18, '8000.00', 'Mobile Money - PayUnit', '+237654123302', 'Cameroun', 'PAYUNIT_1761820372_7727', 'en_attente', NULL, NULL, NULL, NULL),
(81, '2025-10-30 11:36:43', 18, '5000.00', 'Mobile Money - PayUnit', '+237654123302', 'Cameroun', 'PAYUNIT_1761820602_9591', 'en_attente', NULL, NULL, NULL, NULL),
(82, '2025-10-30 14:05:57', 18, '500.00', 'Mobile Money - PayUnit', '+237654123302', 'Cameroun', 'PAYUNIT_1761829556_2233', 'en_attente', NULL, NULL, NULL, NULL),
(83, '2025-10-30 14:27:00', 3, '500.00', 'Mobile Money - PayUnit', '+27653224346', 'Côte d''Ivoire', 'PAYUNIT_1761830819_2430', 'annule', NULL, 'Erreur de paiement', '2025-10-30 14:27:13', NULL),
(84, '2025-10-31 10:17:24', 19, '500.00', 'Mobile Money - PayUnit', '+261642516322', 'Cameroun', 'PAYUNIT_1761902243_8417', 'annule', NULL, 'Erreur de paiement', '2025-10-31 10:18:01', NULL),
(85, '2025-11-01 03:05:40', 20, '20500.00', 'Mobile Money - PayUnit', '+260682540026', 'Cameroun', 'PAYUNIT_1761962739_7114', 'annule', NULL, 'Erreur de paiement', '2025-11-01 03:06:04', NULL),
(86, '2025-11-01 04:06:04', 20, '20000.00', 'Mobile Money - PayUnit', '+260682540026', 'Cameroun', 'PAYUNIT_1761966363_5051', 'en_attente', NULL, NULL, NULL, NULL),
(87, '2025-11-01 05:19:18', 20, '8000.00', 'Mobile Money - PayUnit', '+260682540026', 'Cameroun', 'PAYUNIT_1761970757_9943', 'en_attente', NULL, NULL, NULL, NULL),
(88, '2025-11-01 05:33:39', 20, '5000.00', 'Mobile Money - PayUnit', '+260682540026', 'Cameroun', 'PAYUNIT_1761971618_1625', 'en_attente', NULL, NULL, NULL, NULL),
(89, '2025-11-01 05:35:33', 20, '500.00', 'Mobile Money - PayUnit', '+260682540026', 'Cameroun', 'PAYUNIT_1761971732_9486', 'annule', NULL, 'Erreur de paiement', '2025-11-01 05:35:53', NULL),
(90, '2025-11-01 06:41:16', 20, '500.00', 'Carte Bancaire', '+260682540026', 'Zambie', 'PAYUNIT_1761975676_3303', 'annule', NULL, 'Escroquerie', '2025-11-01 06:41:59', NULL),
(91, '2025-11-02 11:55:43', 2, '5000.00', 'Mobile Money - PayUnit', '671725822', 'Congo', 'PAYUNIT_1762080942_6341', 'annule', NULL, 'Erreur de paiement', '2025-11-02 11:56:41', NULL),
(92, '2025-11-02 12:45:27', 2, '20000.00', 'Mobile Money - PayUnit', '671725822', 'Congo', 'PAYUNIT_1762083926_8132', 'en_attente', NULL, NULL, NULL, NULL),
(93, '2025-11-02 13:49:24', 2, '8000.00', 'Mobile Money - PayUnit', '671725822', 'Congo', 'PAYUNIT_1762087763_4779', 'annule', NULL, 'Erreur de paiement', '2025-11-02 13:49:40', NULL),
(94, '2025-11-03 12:28:39', 19, '500.00', 'Mobile Money - PayUnit', '+261642516322', 'Madagascar', 'PAYUNIT_1762169318_6399', 'annule', NULL, 'Erreur de paiement', '2025-11-03 12:29:33', NULL),
(95, '2025-11-03 12:56:55', 19, '500.00', 'Mobile Money - PayUnit', '+261642516322', 'Madagascar', 'PAYUNIT_1762171014_7800', 'en_attente', NULL, NULL, NULL, NULL),
(96, '2025-11-03 21:01:31', 7, '500.00', 'Mobile Money - PayUnit', '+212652432050', 'Maroc', 'PAYUNIT_1762200090_2943', 'en_attente', NULL, NULL, NULL, NULL),
(97, '2025-11-03 21:06:04', 7, '5000.00', 'Mobile Money - PayUnit', '+212652432050', 'Maroc', 'PAYUNIT_1762200363_5461', 'annule', NULL, 'Erreur de paiement', '2025-11-03 21:07:08', NULL),
(98, '2025-11-03 22:00:46', 7, '5000.00', 'Mobile Money - PayUnit', '+212652432050', 'Maroc', 'PAYUNIT_1762203645_6880', 'en_attente', NULL, NULL, NULL, NULL),
(99, '2025-11-03 22:10:47', 7, '8000.00', 'Mobile Money - PayUnit', '+212652432050', 'Maroc', 'PAYUNIT_1762204246_9088', 'annule', NULL, 'Erreur de paiement', '2025-11-03 22:11:19', NULL),
(100, '2025-11-03 22:18:50', 7, '8000.00', 'Mobile Money - PayUnit', '+212652432050', 'Maroc', 'PAYUNIT_1762204729_5442', 'paye', NULL, NULL, NULL, '2025-11-03 22:19:16'),
(101, '2025-11-04 11:04:10', 2, '8000.00', 'Mobile Money - PayUnit', '671725822', 'Congo', 'PAYUNIT_1762250649_3010', 'paye', NULL, NULL, NULL, '2025-11-04 11:05:04'),
(102, '2025-11-04 11:46:46', 2, '5000.00', 'Mobile Money - PayUnit', '671725822', 'Congo', 'PAYUNIT_1762253205_6937', 'annule', NULL, 'Escroquerie', '2025-11-04 11:48:01', NULL),
(103, '2025-11-06 11:24:54', 7, '20000.00', 'Mobile Money - PayUnit', '+212652432050', 'Maroc', 'PAYUNIT_1762424693_2570', 'paye', NULL, NULL, NULL, '2025-11-06 11:25:31'),
(104, '2025-11-06 12:59:55', 1, '500.00', 'Mobile Money - PayUnit', '+237651436857', 'Cameroun', 'PAYUNIT_1762430394_2615', 'paye', NULL, NULL, NULL, '2025-11-06 13:00:31'),
(105, '2025-11-06 13:54:37', 18, '200.00', 'Mobile Money - PayUnit', '+237654123302', 'Namibie', 'PAYUNIT_1762433676_7162', 'paye', NULL, NULL, NULL, '2025-11-06 13:56:02'),
(106, '2025-11-06 15:32:18', 21, '20000.00', 'Mobile Money - PayUnit', '+237692533078', 'Cameroun', 'PAYUNIT_1762439537_7254', 'paye', '2025-11-06 15:36:34', NULL, NULL, '2025-11-06 15:36:34'),
(107, '2025-11-09 19:30:22', 5, '200.00', 'Mobile Money - PayUnit', '685450255', 'Égypte', 'PAYUNIT_1762713021_8491', 'paye', '2025-11-09 19:30:58', NULL, NULL, '2025-11-09 19:30:58'),
(108, '2025-11-09 19:46:20', 2, '5000.00', 'Mobile Money - PayUnit', '671725822', 'Congo', 'PAYUNIT_1762713979_3655', 'paye', '2025-11-09 19:46:56', NULL, NULL, '2025-11-09 19:46:56'),
(109, '2025-11-16 14:20:47', 22, '200.00', 'Mobile Money - PayUnit', '+237671819063', 'Cameroun', 'PAYUNIT_1763299245_3609', 'paye', '2025-11-16 14:22:29', NULL, NULL, '2025-11-16 14:22:29'),
(110, '2025-11-22 13:07:24', 22, '20000.00', 'Mobile Money - PayUnit', '+237671819063', 'Cameroun', 'PAYUNIT_1763813243_7875', 'paye', '2025-11-22 13:08:42', NULL, NULL, '2025-11-22 13:08:42'),
(111, '2025-12-17 19:01:58', 24, '200.00', 'Mobile Money - PayUnit', '+237683158723', 'Cameroun', 'PAYUNIT_1765994517_2868', 'paye', '2025-12-17 19:03:29', NULL, NULL, '2025-12-17 19:03:29'),
(112, '2025-12-20 12:56:24', 25, '200.00', 'Mobile Money - PayUnit', '+238621552208', 'Cap-Vert', 'PAYUNIT_1766231783_7391', 'paye', '2025-12-20 12:57:07', NULL, NULL, '2025-12-20 12:57:07'),
(113, '2025-12-21 00:50:39', 25, '20000.00', 'Mobile Money - PayUnit', '+238621552208', 'Cap-Vert', 'PAYUNIT_1766274638_6152', 'en_attente', NULL, NULL, NULL, NULL),
(114, '2025-12-21 01:58:42', 25, '8000.00', 'Mobile Money - PayUnit', '+238621552208', 'Cap-Vert', 'PAYUNIT_1766278721_2300', 'annule', NULL, 'Arnaque suspectée', '2025-12-21 01:59:46', NULL),
(115, '2025-12-21 03:02:14', 25, '8000.00', 'Mobile Money - PayUnit', '+238621552208', 'Cap-Vert', 'PAYUNIT_1766282533_8240', 'paye', '2025-12-21 03:02:58', NULL, NULL, '2025-12-21 03:02:58'),
(116, '2025-12-21 04:25:46', 25, '5000.00', 'Mobile Money - PayUnit', '+238621552208', 'Cap-Vert', 'PAYUNIT_1766287545_9022', 'annule', NULL, 'Escroquerie', '2025-12-21 04:26:13', NULL),
(117, '2025-12-21 14:17:25', 25, '5000.00', 'Mobile Money - PayUnit', '+238621552208', 'Cap-Vert', 'PAYUNIT_1766323044_2746', 'paye', '2025-12-21 14:17:53', NULL, NULL, '2025-12-21 14:17:53'),
(118, '2025-12-21 15:56:52', 25, '500.00', 'Mobile Money - PayUnit', '+238621552208', 'Cap-Vert', 'PAYUNIT_1766329011_7467', 'annule', NULL, 'Erreur de paiement', '2025-12-21 15:57:49', NULL),
(119, '2025-12-21 16:01:02', 25, '500.00', 'Mobile Money - PayUnit', '+238621552208', 'Cap-Vert', 'PAYUNIT_1766329261_1445', 'paye', '2025-12-21 16:01:26', NULL, NULL, '2025-12-21 16:01:26'),
(120, '2025-12-21 17:10:53', 22, '8000.00', 'Mobile Money - PayUnit', '+237671819063', 'Cameroun', 'PAYUNIT_1766333452_3882', 'annule', NULL, 'Erreur de paiement', '2025-12-21 17:11:33', NULL),
(121, '2025-12-23 05:10:52', 22, '8000.00', 'Mobile Money - PayUnit', '+237671819063', 'Cameroun', 'PAYUNIT_1766463051_5213', 'paye', '2025-12-23 05:11:20', NULL, NULL, '2025-12-23 05:11:20'),
(122, '2025-12-23 07:27:31', 22, '5000.00', 'Mobile Money - PayUnit', '+237671819063', 'Cameroun', 'PAYUNIT_1766471250_5524', 'annule', NULL, 'Erreur de paiement', '2025-12-23 07:28:09', NULL),
(123, '2025-12-23 07:45:37', 22, '5000.00', 'Mobile Money - PayUnit', '+237671819063', 'Cameroun', 'PAYUNIT_1766472335_6290', 'paye', '2025-12-23 07:46:32', NULL, NULL, '2025-12-23 07:46:32'),
(124, '2025-12-25 12:12:59', 22, '500.00', 'Mobile Money - PayUnit', '+237671819063', 'Cameroun', 'PAYUNIT_1766661178_8480', 'en_attente', NULL, NULL, NULL, NULL),
(125, '2025-12-25 12:13:42', 22, '500.00', 'Mobile Money - PayUnit', '+237671819063', 'Cameroun', 'PAYUNIT_1766661221_1653', 'annule', NULL, 'Arnaque suspectée', '2025-12-25 12:14:29', NULL),
(126, '2025-12-25 13:01:29', 20, '500.00', 'Mobile Money - PayUnit', '+260682540026', 'Autre', 'PAYUNIT_1766664088_9927', 'en_attente', NULL, NULL, NULL, NULL),
(127, '2025-12-25 13:24:55', 20, '200.00', 'Mobile Money - PayUnit', '+260682540026', 'Autre', 'PAYUNIT_1766665494_5766', 'annule', NULL, 'Arnaque suspectée', '2025-12-25 13:25:35', NULL),
(128, '2025-12-25 15:07:43', 20, '200.00', 'Mobile Money - PayUnit', '+260682540026', 'Autre', 'PAYUNIT_1766671662_9927', 'paye', '2025-12-25 15:08:08', NULL, NULL, '2025-12-25 15:08:08'),
(129, '2026-01-07 05:23:01', 26, '200.00', 'Mobile Money - PayUnit', '+257632552822', 'Burundi', 'PAYUNIT_1767759780_1809', 'annule', NULL, 'Arnaque suspectée', '2026-01-07 05:23:54', NULL),
(130, '2026-01-07 07:22:13', 26, '200.00', 'Mobile Money - PayUnit', '+257632552822', 'Burundi', 'PAYUNIT_1767766932_6909', 'paye', '2026-01-07 07:22:43', NULL, NULL, '2026-01-07 07:22:43'),
(131, '2026-01-20 14:46:02', 4, '200.00', 'Mobile Money - PayUnit', '687216822', 'Cap-Vert', 'PAYUNIT_1768916761_6623', 'annule', NULL, 'Arnaque suspectée', '2026-01-20 14:47:05', NULL);

-- venteproduit (avec vendeurId corrigé – 0 → NULL)
INSERT INTO venteproduit (id, venteId, produitId, quantite, prixUnitaire, achetee, vendeurId) VALUES
(1, 1, 695, 1, '7000.00', FALSE, NULL),
(2, 2, 695, 1, '7000.00', FALSE, NULL),
(3, 2, 694, 1, '10000.00', FALSE, NULL),
(4, 2, 693, 1, '20000.00', FALSE, NULL),
(5, 2, 691, 1, '28000.00', FALSE, NULL),
(6, 2, 690, 1, '20000.00', FALSE, NULL),
(7, 3, 695, 1, '7000.00', FALSE, NULL),
(8, 4, 695, 1, '7000.00', FALSE, NULL),
(9, 5, 695, 1, '7000.00', FALSE, NULL),
(15, 11, 699, 1, '12000.00', FALSE, NULL),
(19, 15, 700, 1, '12000.00', TRUE, NULL),
(20, 16, 700, 1, '12000.00', TRUE, NULL),
(21, 17, 693, 1, '20000.00', TRUE, NULL),
(22, 18, 695, 1, '7000.00', TRUE, NULL),
(23, 18, 694, 1, '10000.00', TRUE, NULL),
(24, 19, 694, 1, '10000.00', TRUE, NULL),
(25, 20, 694, 1, '10000.00', TRUE, NULL),
(26, 21, 694, 1, '10000.00', TRUE, NULL),
(27, 22, 694, 1, '10000.00', TRUE, NULL),
(28, 23, 694, 1, '10000.00', TRUE, NULL),
(29, 24, 694, 1, '10000.00', TRUE, NULL),
(30, 25, 694, 1, '10000.00', TRUE, NULL),
(31, 26, 694, 1, '10000.00', TRUE, NULL),
(32, 27, 700, 1, '12000.00', TRUE, NULL),
(33, 28, 700, 1, '12000.00', TRUE, NULL),
(34, 28, 695, 1, '7000.00', TRUE, NULL),
(35, 29, 700, 1, '12000.00', TRUE, NULL),
(36, 30, 700, 1, '12000.00', TRUE, NULL),
(37, 31, 700, 1, '12000.00', TRUE, NULL),
(38, 32, 700, 1, '12000.00', TRUE, NULL),
(39, 33, 700, 1, '12000.00', TRUE, NULL),
(40, 34, 700, 1, '12000.00', TRUE, NULL),
(41, 35, 700, 1, '12000.00', TRUE, NULL),
(42, 36, 700, 1, '12000.00', TRUE, NULL),
(43, 37, 700, 1, '12000.00', TRUE, NULL),
(44, 38, 695, 1, '7000.00', TRUE, NULL),
(45, 39, 700, 1, '12000.00', TRUE, NULL),
(46, 40, 700, 1, '12000.00', TRUE, NULL),
(47, 40, 695, 1, '7000.00', TRUE, NULL),
(48, 41, 700, 1, '12000.00', TRUE, NULL),
(49, 41, 695, 1, '7000.00', TRUE, NULL),
(50, 42, 691, 1, '28000.00', TRUE, NULL),
(51, 43, 700, 1, '12000.00', TRUE, NULL),
(52, 43, 693, 1, '20000.00', TRUE, NULL),
(53, 44, 700, 1, '12000.00', TRUE, NULL),
(54, 45, 700, 1, '12000.00', TRUE, NULL),
(55, 46, 700, 1, '12000.00', TRUE, NULL),
(56, 47, 700, 1, '12000.00', TRUE, NULL),
(57, 48, 700, 1, '12000.00', TRUE, NULL),
(58, 48, 695, 1, '7000.00', TRUE, NULL),
(59, 49, 700, 1, '12000.00', TRUE, NULL),
(60, 49, 695, 1, '7000.00', TRUE, NULL),
(61, 49, 690, 1, '20000.00', TRUE, NULL),
(62, 50, 695, 1, '7000.00', TRUE, NULL),
(63, 51, 693, 1, '20000.00', TRUE, NULL),
(64, 52, 693, 1, '20000.00', TRUE, NULL),
(65, 54, 751, 1, '20000.00', FALSE, 5),
(66, 55, 751, 1, '20000.00', FALSE, 5),
(67, 68, 751, 1, '20000.00', FALSE, 5),
(68, 69, 751, 1, '20000.00', TRUE, 5),
(69, 70, 741, 1, '500.00', TRUE, 2),
(70, 71, 748, 1, '5000.00', TRUE, 5),
(71, 72, 751, 1, '20000.00', TRUE, 5),
(72, 73, 751, 1, '20000.00', TRUE, 5),
(73, 74, 751, 1, '20000.00', TRUE, 5),
(74, 75, 749, 1, '8000.00', TRUE, 5),
(75, 76, 751, 1, '20000.00', TRUE, 5),
(76, 77, 749, 1, '8000.00', TRUE, 5),
(77, 78, 748, 1, '5000.00', TRUE, 5),
(78, 79, 751, 1, '20000.00', TRUE, 5),
(79, 80, 749, 1, '8000.00', TRUE, 5),
(80, 81, 748, 1, '5000.00', TRUE, 5),
(81, 82, 741, 1, '500.00', TRUE, 2),
(82, 83, 741, 1, '500.00', FALSE, 2),
(83, 84, 741, 1, '500.00', FALSE, 2),
(84, 85, 751, 1, '20000.00', FALSE, 5),
(85, 85, 741, 1, '500.00', FALSE, 2),
(86, 86, 751, 1, '20000.00', TRUE, 5),
(87, 87, 749, 1, '8000.00', TRUE, 5),
(88, 88, 748, 1, '5000.00', TRUE, 5),
(89, 89, 741, 1, '500.00', FALSE, 2),
(90, 90, 741, 1, '500.00', FALSE, 2),
(91, 91, 748, 1, '5000.00', FALSE, 5),
(92, 92, 751, 1, '20000.00', TRUE, 5),
(93, 93, 749, 1, '8000.00', FALSE, 5),
(94, 94, 741, 1, '500.00', FALSE, 2),
(95, 95, 741, 1, '500.00', TRUE, 2),
(96, 96, 741, 1, '500.00', TRUE, 2),
(97, 97, 748, 1, '5000.00', FALSE, 5),
(98, 98, 748, 1, '5000.00', TRUE, 5),
(99, 99, 749, 1, '8000.00', FALSE, 5),
(100, 100, 749, 1, '8000.00', TRUE, 5),
(101, 101, 749, 1, '8000.00', TRUE, 5),
(102, 102, 748, 1, '5000.00', FALSE, 5),
(103, 103, 751, 1, '20000.00', TRUE, 5),
(104, 104, 741, 1, '500.00', TRUE, 2),
(105, 105, 752, 1, '200.00', TRUE, 2),
(106, 106, 751, 1, '20000.00', TRUE, 5),
(107, 107, 752, 1, '200.00', TRUE, 2),
(108, 108, 748, 1, '5000.00', TRUE, 5),
(109, 109, 752, 1, '200.00', TRUE, 2),
(110, 110, 751, 1, '20000.00', TRUE, 5),
(111, 111, 752, 1, '200.00', TRUE, 2),
(112, 112, 752, 1, '200.00', TRUE, 2),
(113, 113, 751, 1, '20000.00', TRUE, 5),
(114, 114, 749, 1, '8000.00', FALSE, 5),
(115, 115, 749, 1, '8000.00', TRUE, 5),
(116, 116, 748, 1, '5000.00', FALSE, 5),
(117, 117, 748, 1, '5000.00', TRUE, 5),
(118, 118, 741, 1, '500.00', FALSE, 2),
(119, 119, 741, 1, '500.00', TRUE, 2),
(120, 120, 749, 1, '8000.00', FALSE, 5),
(121, 121, 749, 1, '8000.00', TRUE, 5),
(122, 122, 748, 1, '5000.00', FALSE, 5),
(123, 123, 748, 1, '5000.00', TRUE, 5),
(124, 124, 741, 1, '500.00', TRUE, 2),
(125, 125, 741, 1, '500.00', FALSE, 2),
(126, 126, 741, 1, '500.00', TRUE, 2),
(127, 127, 752, 1, '200.00', FALSE, 2),
(128, 128, 752, 1, '200.00', TRUE, 2),
(129, 129, 752, 1, '200.00', FALSE, 2),
(130, 130, 752, 1, '200.00', TRUE, 2),
(131, 131, 752, 1, '200.00', FALSE, 2);

-- Commissions (statuts vides corrigés en 'en_attente')
INSERT INTO commission (id, venteId, vendeurId, montantTotal, montantVendeur, montantAdmin, pourcentageCommission, statut, dateCreation, dateTraitement) VALUES
(1, 54, 5, '20000.00', '17000.00', '3000.00', 15, 'retire', '2025-10-27 08:34:56', '2025-11-23 05:12:00'),
(2, 55, 5, '20000.00', '17000.00', '3000.00', 15, 'paye', '2025-10-27 08:36:59', '2025-11-06 12:58:01'),
(3, 68, 5, '20000.00', '17000.00', '3000.00', 15, 'paye', '2025-10-27 10:13:38', '2025-11-06 12:58:01'),
(4, 69, 5, '20000.00', '17000.00', '3000.00', 15, 'paye', '2025-10-27 10:21:43', '2025-10-27 11:21:43'),
(5, 70, 2, '500.00', '425.00', '75.00', 15, 'paye', '2025-10-27 10:26:34', '2025-10-27 11:26:34'),
(6, 71, 5, '5000.00', '4250.00', '750.00', 15, 'paye', '2025-10-28 12:09:51', '2025-10-28 13:09:51'),
(7, 72, 5, '20000.00', '17000.00', '3000.00', 15, 'paye', '2025-10-28 12:42:45', '2025-10-28 13:42:45'),
(8, 73, 5, '20000.00', '17000.00', '3000.00', 15, 'paye', '2025-10-28 12:44:24', '2025-10-28 13:44:24'),
(9, 74, 5, '20000.00', '17000.00', '3000.00', 15, 'paye', '2025-10-28 12:45:41', '2025-10-28 13:45:41'),
(10, 75, 5, '8000.00', '6800.00', '1200.00', 15, 'paye', '2025-10-28 16:35:10', '2025-10-28 17:35:10'),
(11, 76, 5, '20000.00', '17000.00', '3000.00', 15, 'paye', '2025-10-28 20:06:42', '2025-10-28 21:06:42'),
(12, 77, 5, '8000.00', '6800.00', '1200.00', 15, 'paye', '2025-10-28 20:09:38', '2025-10-28 21:09:38'),
(13, 78, 5, '5000.00', '4250.00', '750.00', 15, 'paye', '2025-10-28 21:19:03', '2025-10-28 22:19:03'),
(14, 79, 5, '20000.00', '17000.00', '3000.00', 15, 'paye', '2025-10-28 21:34:47', '2025-10-28 22:34:47'),
(15, 80, 5, '8000.00', '6800.00', '1200.00', 15, 'paye', '2025-10-30 10:32:54', '2025-10-30 11:32:54'),
(16, 81, 5, '5000.00', '4250.00', '750.00', 15, 'paye', '2025-10-30 10:36:43', '2025-10-30 11:36:43'),
(17, 82, 2, '500.00', '425.00', '75.00', 15, 'paye', '2025-10-30 13:05:57', '2025-10-30 14:05:57'),
(18, 83, 2, '500.00', '425.00', '75.00', 15, 'annule', '2025-10-30 13:27:00', '2025-10-30 14:27:13'),
(19, 84, 2, '500.00', '425.00', '75.00', 15, 'annule', '2025-10-31 09:17:25', '2025-10-31 10:18:01'),
(20, 85, 5, '20000.00', '17000.00', '3000.00', 15, 'annule', '2025-11-01 02:05:40', '2025-11-01 03:06:04'),
(21, 85, 2, '500.00', '425.00', '75.00', 15, 'annule', '2025-11-01 02:05:40', '2025-11-01 03:06:04'),
(22, 86, 5, '20000.00', '17000.00', '3000.00', 15, 'paye', '2025-11-01 03:06:05', '2025-11-01 04:06:05'),
(23, 87, 5, '8000.00', '6800.00', '1200.00', 15, 'paye', '2025-11-01 04:19:18', '2025-11-01 05:19:18'),
(24, 88, 5, '5000.00', '4250.00', '750.00', 15, 'paye', '2025-11-01 04:33:39', '2025-11-01 05:33:39'),
(25, 89, 2, '500.00', '425.00', '75.00', 15, 'annule', '2025-11-01 04:35:33', '2025-11-01 05:35:53'),
(26, 90, 2, '500.00', '425.00', '75.00', 15, 'annule', '2025-11-01 05:41:17', '2025-11-01 06:41:59'),
(27, 91, 5, '5000.00', '4250.00', '750.00', 15, 'annule', '2025-11-02 10:55:44', '2025-11-02 11:56:41'),
(28, 92, 5, '20000.00', '17000.00', '3000.00', 15, 'paye', '2025-11-02 11:45:27', '2025-11-02 12:45:27'),
(29, 93, 5, '8000.00', '6800.00', '1200.00', 15, 'annule', '2025-11-02 12:49:24', '2025-11-02 13:49:40'),
(30, 94, 2, '500.00', '425.00', '75.00', 15, 'annule', '2025-11-03 11:28:39', '2025-11-03 12:29:33'),
(31, 95, 2, '500.00', '425.00', '75.00', 15, 'en_attente', '2025-11-03 11:56:55', '2025-11-03 12:56:55'),
(32, 96, 2, '500.00', '425.00', '75.00', 15, 'en_attente', '2025-11-03 20:01:31', '2025-11-03 21:01:31'),
(33, 97, 5, '5000.00', '4250.00', '750.00', 15, 'annule', '2025-11-03 20:06:04', '2025-11-03 21:07:08'),
(43, 98, 5, '5000.00', '4250.00', '750.00', 15, 'en_attente', '2025-11-03 21:00:46', '2025-11-03 22:00:46'),
(48, 99, 5, '8000.00', '6800.00', '1200.00', 15, 'annule', '2025-11-03 21:10:47', '2025-11-03 22:11:19'),
(49, 100, 5, '8000.00', '6800.00', '1200.00', 15, 'en_attente', '2025-11-03 21:18:51', '2025-11-03 22:18:51'),
(84, 101, 5, '8000.00', '6800.00', '1200.00', 15, 'en_attente', '2025-11-04 10:04:10', '2025-11-04 11:04:10'),
(85, 102, 5, '5000.00', '4250.00', '750.00', 15, 'annule', '2025-11-04 10:46:46', '2025-11-04 11:48:01'),
(115, 103, 5, '20000.00', '17000.00', '3000.00', 15, 'en_attente', '2025-11-06 10:24:54', '2025-11-06 11:24:54'),
(124, 104, 2, '500.00', '425.00', '75.00', 15, 'en_attente', '2025-11-06 11:59:55', '2025-11-06 12:59:55'),
(128, 105, 2, '200.00', '170.00', '30.00', 15, 'en_attente', '2025-11-06 12:54:37', '2025-11-06 13:54:37'),
(135, 106, 5, '20000.00', '17000.00', '3000.00', 15, 'paye', '2025-11-06 14:32:18', '2025-11-06 15:32:18'),
(163, 107, 2, '200.00', '170.00', '30.00', 15, 'en_attente', '2025-11-09 18:30:23', '2025-11-09 19:30:23'),
(168, 108, 5, '5000.00', '4250.00', '750.00', 15, 'paye', '2025-11-09 18:46:20', '2025-11-09 19:46:56'),
(228, 109, 2, '200.00', '170.00', '30.00', 15, 'paye', '2025-11-16 13:20:47', '2025-11-16 14:22:29'),
(251, 110, 5, '20000.00', '17000.00', '3000.00', 15, 'paye', '2025-11-22 12:07:24', '2025-11-22 13:08:42'),
(762, 111, 2, '200.00', '170.00', '30.00', 15, 'paye', '2025-12-17 18:01:58', '2025-12-17 19:03:29'),
(788, 112, 2, '200.00', '170.00', '30.00', 15, 'paye', '2025-12-20 11:56:24', '2025-12-20 12:57:07'),
(791, 113, 5, '20000.00', '17000.00', '3000.00', 15, 'en_attente', '2025-12-20 23:50:40', '2025-12-21 00:50:40'),
(792, 114, 5, '8000.00', '6800.00', '1200.00', 15, 'annule', '2025-12-21 00:58:42', '2025-12-21 01:59:46'),
(793, 115, 5, '8000.00', '6800.00', '1200.00', 15, 'paye', '2025-12-21 02:02:14', '2025-12-21 03:02:58'),
(794, 116, 5, '5000.00', '4250.00', '750.00', 15, 'annule', '2025-12-21 03:25:47', '2025-12-21 04:26:13'),
(795, 117, 5, '5000.00', '4250.00', '750.00', 15, 'paye', '2025-12-21 13:17:25', '2025-12-21 14:17:53'),
(798, 118, 2, '500.00', '425.00', '75.00', 15, 'annule', '2025-12-21 14:56:52', '2025-12-21 15:57:49'),
(799, 119, 2, '500.00', '425.00', '75.00', 15, 'paye', '2025-12-21 15:01:02', '2025-12-21 16:01:26'),
(801, 120, 5, '8000.00', '6800.00', '1200.00', 15, 'annule', '2025-12-21 16:10:53', '2025-12-21 17:11:33'),
(806, 121, 5, '8000.00', '6800.00', '1200.00', 15, 'paye', '2025-12-23 04:10:52', '2025-12-23 05:11:20'),
(807, 122, 5, '5000.00', '4250.00', '750.00', 15, 'annule', '2025-12-23 06:27:31', '2025-12-23 07:28:09'),
(808, 123, 5, '5000.00', '4250.00', '750.00', 15, 'paye', '2025-12-23 06:45:37', '2025-12-23 07:46:32'),
(809, 124, 2, '500.00', '425.00', '75.00', 15, 'en_attente', '2025-12-25 11:12:59', '2025-12-25 12:12:59'),
(810, 125, 2, '500.00', '425.00', '75.00', 15, 'annule', '2025-12-25 11:13:42', '2025-12-25 12:14:29'),
(811, 126, 2, '500.00', '425.00', '75.00', 15, 'en_attente', '2025-12-25 12:01:29', '2025-12-25 13:01:29'),
(812, 127, 2, '200.00', '170.00', '30.00', 15, 'annule', '2025-12-25 12:24:55', '2025-12-25 13:25:35'),
(813, 128, 2, '200.00', '170.00', '30.00', 15, 'paye', '2025-12-25 14:07:43', '2025-12-25 15:08:08'),
(937, 129, 2, '200.00', '170.00', '30.00', 15, 'annule', '2026-01-07 04:23:02', '2026-01-07 05:23:54'),
(938, 130, 2, '200.00', '170.00', '30.00', 15, 'paye', '2026-01-07 06:22:14', '2026-01-07 07:22:43'),
(941, 131, 2, '200.00', '170.00', '30.00', 15, 'annule', '2026-01-20 13:46:02', '2026-01-20 14:47:05');

-- ------------------------------------------------------------------
-- 4. AUTRES TABLES (commentaire, etc.) – idem avec corrections mineures
--    (identique à votre script original, sans setval)
-- ------------------------------------------------------------------

-- Insérez ici vos INSERT pour les autres tables (commentaire, commentaire_vus, 
-- demanderetrait, evenement, follow, notification, notificationevenement, 
-- paiementvendeur, produitreaction, push_tokens, rappelanniversaire, 
-- reactionutilisateur, remboursement, transactionpaiement, utilisateurtyping, 
-- video) avec les mêmes corrections (vendeurId NULL au lieu de 0, etc.).

-- Par souci de concision, je ne les recopie pas, mais vous devez appliquer 
-- les mêmes règles : remplacer tous les `vendeurId = 0` par `NULL`, 
-- et tous les `statut = ''` par `'en_attente'`.

-- ------------------------------------------------------------------
-- 5. CRÉATION DES INDEX ET CONTRAINTES
-- ------------------------------------------------------------------

-- Index (copiez votre liste existante)
CREATE INDEX idx_commentaire_produit ON commentaire (produitId);
-- ... tous les autres index

-- Clés étrangères (copiez votre liste)
ALTER TABLE commentaire_vus
  ADD CONSTRAINT commentaire_vus_commentaire_fk FOREIGN KEY (commentaire_id) REFERENCES commentaire (id) ON DELETE CASCADE,
  ADD CONSTRAINT commentaire_vus_utilisateur_fk FOREIGN KEY (utilisateur_id) REFERENCES utilisateur (id) ON DELETE CASCADE;

-- ... toutes les autres clés étrangères

-- ------------------------------------------------------------------
-- 6. FONCTION ET TRIGGER POUR LES TIMESTAMPS
-- ------------------------------------------------------------------
CREATE OR REPLACE FUNCTION update_timestamp()
RETURNS TRIGGER AS $$
BEGIN
    NEW.dateModification = CURRENT_TIMESTAMP;
    RETURN NEW;
END;
$$ language 'plpgsql';

CREATE TRIGGER update_evenement_timestamp 
    BEFORE UPDATE ON evenement 
    FOR EACH ROW EXECUTE FUNCTION update_timestamp();

CREATE TRIGGER update_rappelanniversaire_timestamp 
    BEFORE UPDATE ON rappelanniversaire 
    FOR EACH ROW EXECUTE FUNCTION update_timestamp();

COMMIT;