-- Script PostgreSQL pour le système de gestion de vente
-- Version adaptée depuis MariaDB/MySQL
-- Date : 2026-02-08

BEGIN;
use gestvente;
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
  vendeurId INTEGER NOT NULL
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

-- Insertion des données dans la table commentaire
INSERT INTO commentaire (id, produitId, utilisateurId, texte, type, reply_to, voice_uri, voice_duration, image_uri, is_edited, date_modification, dateCreation, vu) VALUES
(1, 702, 2, 'Salut', 'text', NULL, NULL, NULL, NULL, FALSE, NULL, '2025-07-03 04:27:04', TRUE),
(2, 702, 4, 'Yes???????????? on dit quoi', 'text', NULL, NULL, NULL, NULL, FALSE, NULL, '2025-07-03 04:31:14', TRUE),
(3, 702, 4, 'Yep', 'text', NULL, NULL, NULL, NULL, FALSE, NULL, '2025-07-03 05:11:42', TRUE),
(4, 702, 2, 'Suis là et toi ?', 'text', NULL, NULL, NULL, NULL, FALSE, NULL, '2025-07-03 05:24:40', TRUE),
(5, 702, 2, 'salut', 'text', NULL, NULL, NULL, NULL, FALSE, NULL, '2025-07-03 10:36:08', TRUE),
(6, 700, 2, 'vous vendez ce produit', 'text', NULL, NULL, NULL, NULL, FALSE, NULL, '2025-07-03 10:37:27', TRUE),
(7, 700, 4, 'Oui', 'text', NULL, NULL, NULL, NULL, FALSE, NULL, '2025-07-03 10:38:16', TRUE),
(8, 702, 2, 'héo', 'text', NULL, NULL, NULL, NULL, FALSE, NULL, '2025-07-03 12:38:22', TRUE),
(9, 700, 2, 'Salut', 'text', NULL, NULL, NULL, NULL, FALSE, NULL, '2025-07-04 06:14:21', TRUE),
(10, 700, 4, 'Yep on dit quoi ?', 'text', NULL, NULL, NULL, NULL, FALSE, NULL, '2025-07-04 06:15:43', TRUE),
(13, 702, 4, 'Oui', 'text', NULL, NULL, NULL, NULL, FALSE, NULL, '2025-07-04 08:31:04', TRUE),
(14, 700, 2, 'Suis là et toi', 'text', NULL, NULL, NULL, NULL, FALSE, NULL, '2025-07-04 08:39:55', TRUE),
(15, 702, 2, 'Comment ça va', 'text', NULL, NULL, NULL, NULL, FALSE, NULL, '2025-07-04 09:16:52', TRUE),
(16, 702, 2, 'Héo', 'text', NULL, NULL, NULL, NULL, FALSE, NULL, '2025-07-04 09:34:27', TRUE),
(21, 700, 5, 'Salut', 'text', NULL, NULL, NULL, NULL, FALSE, NULL, '2025-07-05 03:54:34', TRUE),
(22, 702, 2, 'Yo', 'text', NULL, NULL, NULL, NULL, FALSE, NULL, '2025-07-05 13:08:05', TRUE),
(23, 702, 5, 'Yes on dit quoi ?', 'text', NULL, NULL, NULL, NULL, FALSE, NULL, '2025-07-05 13:10:14', TRUE),
(24, 702, 2, 'Suis là et toi ?', 'text', NULL, NULL, NULL, NULL, FALSE, NULL, '2025-07-05 13:13:23', TRUE),
(25, 702, 7, 'Yo', 'text', NULL, NULL, NULL, NULL, FALSE, NULL, '2025-07-05 14:29:37', TRUE),
(26, 702, 5, 'Yep', 'text', NULL, NULL, NULL, NULL, FALSE, NULL, '2025-07-05 14:30:14', TRUE),
(27, 694, 5, 'Yo', 'text', NULL, NULL, NULL, NULL, FALSE, NULL, '2025-07-05 16:02:22', TRUE),
(28, 694, 2, 'yep', 'text', NULL, NULL, NULL, NULL, FALSE, NULL, '2025-07-05 16:06:10', TRUE),
(29, 694, 5, 'Comment ça va ?', 'text', NULL, NULL, NULL, NULL, FALSE, NULL, '2025-07-05 16:07:44', TRUE),
(30, 694, 2, 'bien et vous ?', 'text', NULL, NULL, NULL, NULL, FALSE, NULL, '2025-07-05 16:09:04', TRUE),
(31, 702, 2, 'on dit quoi ?', 'text', NULL, NULL, NULL, NULL, FALSE, NULL, '2025-07-05 17:18:39', TRUE),
(32, 702, 5, 'Suis là et toi', 'text', NULL, NULL, NULL, NULL, FALSE, NULL, '2025-07-05 17:19:10', TRUE),
(33, 702, 5, 'Héo', 'text', NULL, NULL, NULL, NULL, FALSE, NULL, '2025-07-05 18:26:31', TRUE),
(34, 702, 5, 'Eco', 'text', NULL, NULL, NULL, NULL, FALSE, NULL, '2025-07-05 19:05:25', TRUE),
(35, 702, 2, 'yes', 'text', NULL, NULL, NULL, NULL, FALSE, NULL, '2025-07-05 19:05:49', TRUE),
(36, 702, 5, 'Ue', 'text', NULL, NULL, NULL, NULL, FALSE, NULL, '2025-07-05 19:17:47', TRUE),
(37, 702, 2, 'papa', 'text', NULL, NULL, NULL, NULL, FALSE, NULL, '2025-07-05 19:18:09', TRUE),
(38, 702, 2, 'yo', 'text', NULL, NULL, NULL, NULL, FALSE, NULL, '2025-07-05 19:43:46', TRUE),
(39, 702, 5, 'Yes', 'text', NULL, NULL, NULL, NULL, FALSE, NULL, '2025-07-05 19:43:55', TRUE),
(43, 702, 7, 'On dit quoi', 'text', NULL, NULL, NULL, NULL, FALSE, NULL, '2025-07-05 21:20:34', TRUE),
(44, 699, 7, 'Bonjour', 'text', NULL, NULL, NULL, NULL, FALSE, NULL, '2025-07-05 21:57:26', TRUE),
(45, 699, 2, 'yes comment va tu ?', 'text', NULL, NULL, NULL, NULL, FALSE, NULL, '2025-07-05 21:59:18', TRUE),
(46, 699, 7, 'Posé et toi', 'text', NULL, NULL, NULL, NULL, FALSE, NULL, '2025-07-05 22:00:33', TRUE),
(47, 699, 7, 'Cool', 'text', NULL, NULL, NULL, NULL, FALSE, NULL, '2025-07-05 22:01:44', TRUE),
(48, 699, 5, 'Héo', 'text', NULL, NULL, NULL, NULL, FALSE, NULL, '2025-07-05 22:02:02', TRUE),
(49, 699, 2, 'yep', 'text', NULL, NULL, NULL, NULL, FALSE, NULL, '2025-07-05 22:02:47', TRUE),
(50, 699, 7, 'Salut junior', 'text', NULL, NULL, NULL, NULL, FALSE, NULL, '2025-07-05 22:03:16', TRUE),
(51, 699, 5, '????', 'text', NULL, NULL, NULL, NULL, FALSE, NULL, '2025-07-05 22:03:41', TRUE),
(52, 699, 5, '????????????????', 'text', NULL, NULL, NULL, NULL, FALSE, NULL, '2025-07-05 22:03:59', TRUE),
(53, 699, 2, 'tu rit avec qui', 'text', NULL, NULL, NULL, NULL, FALSE, NULL, '2025-07-05 22:04:40', TRUE),
(54, 699, 7, '??', 'text', NULL, NULL, NULL, NULL, FALSE, NULL, '2025-07-05 22:05:36', TRUE),
(55, 699, 5, '????', 'text', NULL, NULL, NULL, NULL, FALSE, NULL, '2025-07-05 22:06:29', TRUE),
(56, 702, 7, 'Yo', 'text', NULL, NULL, NULL, NULL, FALSE, NULL, '2025-07-06 09:02:38', TRUE),
(57, 702, 7, 'Yo', 'text', NULL, NULL, NULL, NULL, FALSE, NULL, '2025-07-06 09:02:45', TRUE),
(60, 702, 1, 'Salut', 'text', NULL, NULL, NULL, NULL, FALSE, NULL, '2025-07-06 18:29:34', TRUE),
(71, 703, 7, 'Salut', 'text', NULL, NULL, NULL, NULL, FALSE, NULL, '2025-07-12 18:29:48', TRUE),
(72, 703, 7, 'Heo', 'text', NULL, NULL, NULL, NULL, FALSE, NULL, '2025-07-12 18:30:18', TRUE),
(73, 703, 2, 'Yep', 'text', NULL, NULL, NULL, NULL, FALSE, NULL, '2025-07-12 18:30:46', TRUE),
(74, 702, 2, 'Yo', 'text', NULL, NULL, NULL, NULL, FALSE, NULL, '2025-07-12 18:32:03', TRUE),
(75, 702, 2, 'T', 'text', NULL, NULL, NULL, NULL, FALSE, NULL, '2025-07-12 18:32:28', TRUE),
(76, 702, 7, 'Yep', 'text', NULL, NULL, NULL, NULL, FALSE, NULL, '2025-07-12 18:33:12', TRUE),
(77, 702, 2, 'Salut', 'text', NULL, NULL, NULL, NULL, FALSE, NULL, '2025-07-12 18:34:13', TRUE),
(78, 702, 7, 'U', 'text', NULL, NULL, NULL, NULL, FALSE, NULL, '2025-07-12 18:34:38', TRUE),
(79, 703, 2, 'Salut', 'text', NULL, NULL, NULL, NULL, FALSE, NULL, '2025-07-12 18:40:38', TRUE),
(80, 703, 7, 'Yes', 'text', NULL, NULL, NULL, NULL, FALSE, NULL, '2025-07-12 18:41:20', TRUE),
(86, 732, 3, 'Salut', 'text', NULL, NULL, NULL, NULL, FALSE, NULL, '2025-08-08 08:10:43', TRUE),
(87, 732, 3, 'Salut', 'text', NULL, NULL, NULL, NULL, FALSE, NULL, '2025-08-08 08:14:36', TRUE),
(88, 732, 1, 'Yes', 'text', NULL, NULL, NULL, NULL, FALSE, NULL, '2025-08-08 08:15:19', TRUE),
(89, 732, 3, 'On dit quoi', 'text', NULL, NULL, NULL, NULL, FALSE, NULL, '2025-08-08 15:34:25', TRUE),
(90, 738, 2, 'J''ai aimé ça', 'text', NULL, NULL, NULL, NULL, FALSE, NULL, '2025-08-19 11:30:31', TRUE),
(91, 738, 2, 'Bonjour', 'text', NULL, NULL, NULL, NULL, FALSE, NULL, '2025-08-19 19:07:47', TRUE),
(92, 738, 3, 'bonjour', 'text', NULL, NULL, NULL, NULL, FALSE, NULL, '2025-08-19 19:08:07', TRUE),
(93, 732, 3, 'Uyfv', 'text', NULL, NULL, NULL, NULL, FALSE, NULL, '2025-08-21 17:35:42', TRUE),
(94, 732, 3, 'Hff', 'text', NULL, NULL, NULL, NULL, FALSE, NULL, '2025-08-21 17:35:52', TRUE),
(95, 732, 7, 'salut', 'text', NULL, NULL, NULL, NULL, FALSE, NULL, '2025-08-21 17:38:21', TRUE),
(96, 732, 3, 'Yes', 'text', NULL, NULL, NULL, NULL, FALSE, NULL, '2025-08-21 17:39:17', TRUE),
(97, 738, 3, 'ߑ˧', 'text', NULL, NULL, NULL, NULL, FALSE, NULL, '2025-10-12 22:58:13', TRUE),
(98, 738, 3, 'ߖݯ؏ Image', 'image', NULL, NULL, NULL, 'uploads/images/68ec24e5de6be_1760306405.jpg', FALSE, NULL, '2025-10-12 23:00:05', TRUE),
(99, 738, 2, 'ߘΧ', 'text', NULL, NULL, NULL, NULL, FALSE, NULL, '2025-10-12 23:05:13', TRUE),
(101, 733, 3, 'Hi', 'text', NULL, NULL, NULL, NULL, FALSE, NULL, '2025-10-13 05:51:57', FALSE),
(102, 733, 3, 'ߖݯ؏ Image', 'image', NULL, NULL, NULL, 'uploads/images/68ec858565d05_1760331141.jpg', FALSE, NULL, '2025-10-13 05:52:21', FALSE),
(103, 738, 20, 'C''est quoi ?', 'text', NULL, NULL, NULL, NULL, FALSE, NULL, '2025-10-14 10:28:43', TRUE),
(104, 738, 20, 'ߤհߘİߘİߘç', 'text', NULL, NULL, NULL, NULL, FALSE, NULL, '2025-10-14 10:29:37', TRUE),
(105, 738, 3, 'ߖݯ؏ Image', 'image', NULL, NULL, NULL, 'uploads/images/68ee183f75ed4_1760434239.jpg', FALSE, NULL, '2025-10-14 10:30:39', TRUE),
(106, 738, 3, 'ߘ§', 'text', NULL, NULL, NULL, NULL, FALSE, NULL, '2025-10-14 12:18:48', TRUE),
(107, 738, 3, 'ߖݯ؏ Image', 'image', NULL, NULL, NULL, 'uploads/images/68ee31acc94d9_1760440748.jpg', FALSE, NULL, '2025-10-14 12:19:08', TRUE),
(108, 738, 3, 'ߘ§', 'text', NULL, NULL, NULL, NULL, FALSE, NULL, '2025-10-14 13:56:34', TRUE),
(109, 738, 3, 'ߖݯ؏ Image', 'image', NULL, NULL, NULL, 'uploads/images/68ee4890dd0ed_1760446608.jpg', FALSE, NULL, '2025-10-14 13:56:48', TRUE),
(112, 738, 2, 'Yo', 'text', NULL, NULL, NULL, NULL, FALSE, NULL, '2025-10-15 16:03:39', TRUE),
(113, 738, 5, 'ߖݯ؏ Image', 'image', NULL, NULL, NULL, 'uploads/images/68efd20318bc1_1760547331.jpg', FALSE, NULL, '2025-10-15 17:55:31', TRUE),
(114, 738, 5, 'C''est ?', 'text', 109, NULL, NULL, NULL, FALSE, NULL, '2025-10-15 19:21:47', TRUE),
(115, 738, 2, 'ߖݯ؏ Image', 'image', NULL, NULL, NULL, 'uploads/images/68efe77824e6f_1760552824.jpg', FALSE, NULL, '2025-10-15 19:27:04', TRUE),
(116, 744, 5, 'Salutߑ̰ߑ˧', 'text', NULL, NULL, NULL, NULL, FALSE, NULL, '2025-10-18 20:52:14', TRUE),
(117, 744, 3, 'Yes', 'text', NULL, NULL, NULL, NULL, FALSE, NULL, '2025-10-18 20:54:20', FALSE),
(118, 741, 18, 'Salutߑ˧', 'text', NULL, NULL, NULL, NULL, FALSE, NULL, '2025-11-04 09:03:16', TRUE),
(119, 741, 18, 'ߖݯ؏ Image', 'image', NULL, NULL, NULL, 'uploads/images/6909b48fb43f8_1762243727.jpg', FALSE, NULL, '2025-11-04 09:08:47', TRUE),
(120, 741, 18, 'Pour ceux qui sont intéressé', 'text', NULL, NULL, NULL, NULL, FALSE, NULL, '2025-11-04 09:09:29', TRUE),
(121, 741, 5, 'Je suis intéressé', 'text', 119, NULL, NULL, NULL, FALSE, NULL, '2025-11-04 09:12:14', TRUE),
(122, 752, 2, 'Yo', 'text', NULL, NULL, NULL, NULL, FALSE, NULL, '2025-11-13 15:41:40', TRUE),
(123, 752, 18, 'Yep', 'text', NULL, NULL, NULL, NULL, FALSE, NULL, '2025-11-15 17:01:14', FALSE),
(124, 752, 2, 'Salut', 'text', NULL, NULL, NULL, NULL, FALSE, NULL, '2025-12-08 16:14:11', FALSE),
(125, 751, 5, 'Yo', 'text', NULL, NULL, NULL, NULL, FALSE, NULL, '2025-12-15 15:11:59', FALSE),
(126, 703, 2, 'Immagine', 'image', NULL, NULL, NULL, 'uploads/images/69402c57930d1_1765813335.jpg', FALSE, NULL, '2025-12-15 16:42:15', FALSE),
(127, 741, 2, 'Hi ߑ̰ߤ۰ߤڧ', 'text', NULL, NULL, NULL, NULL, FALSE, NULL, '2025-12-16 03:24:53', FALSE),
(128, 752, 23, 'Salut bro', 'text', NULL, NULL, NULL, NULL, FALSE, NULL, '2025-12-16 23:43:10', FALSE),
(129, 752, 2, 'Yes', 'text', 128, NULL, NULL, NULL, FALSE, NULL, '2025-12-16 23:45:12', FALSE),
(130, 752, 2, 'Je vais géré le style ci', 'text', NULL, NULL, NULL, NULL, FALSE, NULL, '2025-12-16 23:45:47', FALSE),
(131, 752, 23, 'Oui j''ai bien reçu', 'text', NULL, NULL, NULL, NULL, FALSE, NULL, '2025-12-16 23:48:51', FALSE),
(132, 752, 2, 'Yes tu a like sur ce produit non ? ߘϰߘϰߘΧ', 'text', NULL, NULL, NULL, NULL, FALSE, NULL, '2025-12-16 23:51:00', FALSE),
(133, 732, 2, 'Yo', 'text', NULL, NULL, NULL, NULL, FALSE, NULL, '2025-12-20 12:00:15', FALSE),
(134, 752, 7, 'Yo', 'text', NULL, NULL, NULL, NULL, FALSE, NULL, '2025-12-20 12:04:15', FALSE),
(135, 752, 5, 'Image', 'image', NULL, NULL, NULL, 'uploads/images/6953698a2482f_1767074186.jpg', FALSE, NULL, '2025-12-30 06:56:26', FALSE),
(136, 752, 5, 'Qui connais ça ?', 'text', 135, NULL, NULL, NULL, TRUE, '2025-12-30 06:57:58', '2025-12-30 06:57:20', FALSE),
(137, 752, 5, 'Je suis sûr que personne ne connais', 'text', 136, NULL, NULL, NULL, FALSE, NULL, '2025-12-30 07:23:11', FALSE),
(138, 752, 5, 'Image', 'image', NULL, NULL, NULL, 'uploads/images/69536ff085fbd_1767075824.jpg', FALSE, NULL, '2025-12-30 07:23:44', FALSE),
(139, 752, 5, 'Image', 'image', NULL, NULL, NULL, 'uploads/images/695370582c783_1767075928.jpg', FALSE, NULL, '2025-12-30 07:25:28', FALSE),
(140, 752, 2, 'Image', 'image', NULL, NULL, NULL, 'uploads/images/6953781aacdf7_1767077914.jpg', FALSE, NULL, '2025-12-30 07:58:34', FALSE),
(142, 752, 2, 'Image', 'image', NULL, NULL, NULL, 'uploads/images/695378209b7c5_1767077920.jpg', FALSE, NULL, '2025-12-30 07:58:40', FALSE),
(143, 752, 2, 'Image', 'image', NULL, NULL, NULL, 'uploads/images/69537822ece8d_1767077922.jpg', FALSE, NULL, '2025-12-30 07:58:42', FALSE),
(144, 752, 2, 'Image', 'image', NULL, NULL, NULL, 'uploads/images/69537823a5797_1767077923.jpg', FALSE, NULL, '2025-12-30 07:58:43', FALSE),
(148, 752, 2, 'Il était une fois un homme qui mangeait beaucoup de soupe il est parti en voyage et a laissé ces enfants seul a la maison et il a donné 5000f pour qu''il se débrouille a son absence ߘϰߘϰߧѰߧЧ', 'text', NULL, NULL, NULL, NULL, FALSE, NULL, '2025-12-30 10:51:17', FALSE),
(149, 752, 2, 'Et il est parti pour revenir après 10 jours ߘ԰ߘ԰ߘӠ', 'text', 148, NULL, NULL, NULL, FALSE, NULL, '2025-12-30 10:53:19', FALSE),
(151, 752, 2, 'ߓؠImage', 'image', NULL, NULL, NULL, 'uploads/images/6953a17e78c70_1767088510.jpg', FALSE, NULL, '2025-12-30 10:55:10', FALSE),
(152, 752, 26, 'ߘðߘðߘðߤİߤİߤİߥҧ', 'text', 151, NULL, NULL, NULL, FALSE, NULL, '2026-01-19 14:42:21', FALSE),
(153, 752, 26, 'Yes il pense que l''hibous fait peur ߘðߘ§', 'text', 152, NULL, NULL, NULL, FALSE, NULL, '2026-01-19 14:45:22', FALSE),
(154, 741, 26, 'Hi how are you', 'text', 127, NULL, NULL, NULL, TRUE, '2026-01-19 14:47:29', '2026-01-19 14:46:13', FALSE),
(156, 752, 20, 'Je te dis gars', 'text', 153, NULL, NULL, NULL, FALSE, NULL, '2026-01-23 16:06:51', FALSE),
(157, 752, 4, 'Yo', 'text', NULL, NULL, NULL, NULL, FALSE, NULL, '2026-01-24 11:10:19', FALSE),
(158, 732, 5, 'ߤߧ', 'text', NULL, NULL, NULL, NULL, FALSE, NULL, '2026-01-28 09:44:38', FALSE);

SELECT setval('commentaire_id_seq', COALESCE((SELECT MAX(id) FROM commentaire), 0) + 1);

-- Insertion des données dans la table commentaire_vus
INSERT INTO commentaire_vus (id, commentaire_id, utilisateur_id, date_vue) VALUES
(1, 122, 18, '2025-11-15 17:00:50'),
(2, 27, 22, '2025-11-16 14:16:20'),
(3, 28, 22, '2025-11-16 14:16:20'),
(4, 29, 22, '2025-11-16 14:16:20'),
(5, 30, 22, '2025-11-16 14:16:20'),
(6, 123, 2, '2025-11-27 17:57:04'),
(7, 86, 2, '2025-11-27 17:59:48'),
(8, 87, 2, '2025-11-27 17:59:48'),
(9, 88, 2, '2025-11-27 17:59:48'),
(10, 89, 2, '2025-11-27 17:59:48'),
(11, 93, 2, '2025-11-27 17:59:48'),
(12, 94, 2, '2025-11-27 17:59:48'),
(13, 95, 2, '2025-11-27 17:59:48'),
(14, 96, 2, '2025-11-27 17:59:48'),
(15, 118, 2, '2025-11-27 18:00:01'),
(16, 119, 2, '2025-11-27 18:00:01'),
(17, 120, 2, '2025-11-27 18:00:01'),
(18, 121, 2, '2025-11-27 18:00:01'),
(19, 71, 2, '2025-12-01 11:33:18'),
(20, 72, 2, '2025-12-01 11:33:19'),
(21, 80, 2, '2025-12-01 11:33:19'),
(22, 125, 2, '2025-12-16 03:48:20'),
(23, 122, 23, '2025-12-16 23:42:17'),
(24, 123, 23, '2025-12-16 23:42:18'),
(25, 124, 23, '2025-12-16 23:42:18'),
(26, 128, 2, '2025-12-16 23:44:37'),
(27, 131, 2, '2025-12-16 23:50:03'),
(28, 122, 24, '2025-12-17 18:51:27'),
(29, 123, 24, '2025-12-17 18:51:29'),
(30, 124, 24, '2025-12-17 18:51:30'),
(31, 128, 24, '2025-12-17 18:51:32'),
(32, 129, 24, '2025-12-17 18:51:33'),
(33, 130, 24, '2025-12-17 18:51:35'),
(34, 131, 24, '2025-12-17 18:51:36'),
(35, 132, 24, '2025-12-17 18:51:36'),
(36, 122, 3, '2025-12-18 16:24:21'),
(37, 123, 3, '2025-12-18 16:24:21'),
(38, 124, 3, '2025-12-18 16:24:21'),
(39, 128, 3, '2025-12-18 16:24:22'),
(40, 129, 3, '2025-12-18 16:24:22'),
(41, 130, 3, '2025-12-18 16:24:22'),
(42, 131, 3, '2025-12-18 16:24:22'),
(43, 132, 3, '2025-12-18 16:24:22'),
(44, 122, 7, '2025-12-20 12:03:56'),
(45, 123, 7, '2025-12-20 12:03:56'),
(46, 124, 7, '2025-12-20 12:03:56'),
(47, 128, 7, '2025-12-20 12:03:57'),
(48, 129, 7, '2025-12-20 12:03:57'),
(49, 130, 7, '2025-12-20 12:03:57'),
(50, 131, 7, '2025-12-20 12:03:58'),
(51, 132, 7, '2025-12-20 12:03:58'),
(52, 118, 5, '2025-12-30 06:35:38'),
(53, 119, 5, '2025-12-30 06:35:38'),
(54, 120, 5, '2025-12-30 06:35:38'),
(55, 127, 5, '2025-12-30 06:35:38'),
(56, 122, 5, '2025-12-30 06:55:56'),
(57, 123, 5, '2025-12-30 06:55:56'),
(58, 124, 5, '2025-12-30 06:55:56'),
(59, 128, 5, '2025-12-30 06:55:57'),
(60, 129, 5, '2025-12-30 06:55:57'),
(61, 130, 5, '2025-12-30 06:55:57'),
(62, 131, 5, '2025-12-30 06:55:57'),
(63, 132, 5, '2025-12-30 06:55:57'),
(64, 134, 5, '2025-12-30 06:55:57'),
(65, 134, 2, '2025-12-30 07:28:05'),
(66, 135, 2, '2025-12-30 07:28:06'),
(67, 136, 2, '2025-12-30 07:28:06'),
(68, 137, 2, '2025-12-30 07:28:06'),
(69, 138, 2, '2025-12-30 07:28:06'),
(70, 139, 2, '2025-12-30 07:28:06'),
(71, 86, 18, '2026-01-05 07:07:07'),
(72, 87, 18, '2026-01-05 07:07:07'),
(73, 88, 18, '2026-01-05 07:07:07'),
(74, 89, 18, '2026-01-05 07:07:08'),
(75, 93, 18, '2026-01-05 07:07:08'),
(76, 94, 18, '2026-01-05 07:07:08'),
(77, 95, 18, '2026-01-05 07:07:08'),
(78, 96, 18, '2026-01-05 07:07:08'),
(79, 133, 18, '2026-01-05 07:07:08'),
(80, 122, 26, '2026-01-07 10:28:45'),
(81, 123, 26, '2026-01-07 10:28:45'),
(82, 124, 26, '2026-01-07 10:28:45'),
(83, 128, 26, '2026-01-07 10:28:46'),
(84, 129, 26, '2026-01-07 10:28:46'),
(85, 130, 26, '2026-01-07 10:28:46'),
(86, 131, 26, '2026-01-07 10:28:46'),
(87, 132, 26, '2026-01-07 10:28:46'),
(88, 134, 26, '2026-01-07 10:28:46'),
(89, 135, 26, '2026-01-07 10:28:46'),
(90, 136, 26, '2026-01-07 10:28:47'),
(91, 137, 26, '2026-01-07 10:28:47'),
(92, 138, 26, '2026-01-07 10:28:47'),
(93, 139, 26, '2026-01-07 10:28:47'),
(94, 140, 26, '2026-01-07 10:28:47'),
(95, 142, 26, '2026-01-07 10:28:47'),
(96, 143, 26, '2026-01-07 10:28:47'),
(97, 144, 26, '2026-01-07 10:28:47'),
(98, 148, 26, '2026-01-07 10:28:47'),
(99, 149, 26, '2026-01-07 10:28:47'),
(100, 151, 26, '2026-01-07 10:28:47'),
(101, 118, 26, '2026-01-19 14:45:47'),
(102, 119, 26, '2026-01-19 14:45:48'),
(103, 120, 26, '2026-01-19 14:45:48'),
(104, 121, 26, '2026-01-19 14:45:48'),
(105, 127, 26, '2026-01-19 14:45:49'),
(106, 152, 2, '2026-01-23 13:04:21'),
(107, 153, 2, '2026-01-23 13:04:21'),
(108, 122, 20, '2026-01-23 16:05:46'),
(109, 123, 20, '2026-01-23 16:05:46'),
(110, 124, 20, '2026-01-23 16:05:47'),
(111, 128, 20, '2026-01-23 16:05:47'),
(112, 129, 20, '2026-01-23 16:05:47'),
(113, 130, 20, '2026-01-23 16:05:47'),
(114, 131, 20, '2026-01-23 16:05:47'),
(115, 132, 20, '2026-01-23 16:05:47'),
(116, 134, 20, '2026-01-23 16:05:47'),
(117, 135, 20, '2026-01-23 16:05:47'),
(118, 136, 20, '2026-01-23 16:05:47'),
(119, 137, 20, '2026-01-23 16:05:47'),
(120, 138, 20, '2026-01-23 16:05:47'),
(121, 139, 20, '2026-01-23 16:05:47'),
(122, 140, 20, '2026-01-23 16:05:47'),
(123, 142, 20, '2026-01-23 16:05:48'),
(124, 143, 20, '2026-01-23 16:05:48'),
(125, 144, 20, '2026-01-23 16:05:48'),
(126, 148, 20, '2026-01-23 16:05:48'),
(127, 149, 20, '2026-01-23 16:05:48'),
(128, 151, 20, '2026-01-23 16:05:48'),
(129, 152, 20, '2026-01-23 16:05:48'),
(130, 153, 20, '2026-01-23 16:05:49'),
(131, 122, 4, '2026-01-24 11:07:47'),
(132, 123, 4, '2026-01-24 11:07:52'),
(133, 124, 4, '2026-01-24 11:07:52'),
(134, 128, 4, '2026-01-24 11:07:53'),
(135, 129, 4, '2026-01-24 11:07:53'),
(136, 130, 4, '2026-01-24 11:07:53'),
(137, 131, 4, '2026-01-24 11:07:55'),
(138, 132, 4, '2026-01-24 11:07:57'),
(139, 134, 4, '2026-01-24 11:07:58'),
(140, 135, 4, '2026-01-24 11:07:59'),
(141, 136, 4, '2026-01-24 11:08:05'),
(142, 137, 4, '2026-01-24 11:08:06'),
(143, 138, 4, '2026-01-24 11:08:06'),
(144, 139, 4, '2026-01-24 11:08:06'),
(145, 140, 4, '2026-01-24 11:08:07'),
(146, 142, 4, '2026-01-24 11:08:07'),
(147, 143, 4, '2026-01-24 11:08:07'),
(148, 144, 4, '2026-01-24 11:08:07'),
(149, 148, 4, '2026-01-24 11:08:07'),
(150, 149, 4, '2026-01-24 11:08:08'),
(151, 151, 4, '2026-01-24 11:08:08'),
(152, 152, 4, '2026-01-24 11:08:10'),
(153, 153, 4, '2026-01-24 11:08:12'),
(154, 156, 4, '2026-01-24 11:08:12'),
(155, 27, 2, '2026-01-26 14:05:22'),
(156, 29, 2, '2026-01-26 14:05:22'),
(157, 140, 5, '2026-01-27 14:11:03'),
(158, 142, 5, '2026-01-27 14:11:03'),
(159, 143, 5, '2026-01-27 14:11:03'),
(160, 144, 5, '2026-01-27 14:11:03'),
(161, 148, 5, '2026-01-27 14:11:03'),
(162, 149, 5, '2026-01-27 14:11:04'),
(163, 151, 5, '2026-01-27 14:11:04'),
(164, 152, 5, '2026-01-27 14:11:04'),
(165, 153, 5, '2026-01-27 14:11:04'),
(166, 156, 5, '2026-01-27 14:11:04'),
(167, 157, 5, '2026-01-27 14:11:04'),
(168, 86, 5, '2026-01-28 09:44:24'),
(169, 87, 5, '2026-01-28 09:44:24'),
(170, 88, 5, '2026-01-28 09:44:25'),
(171, 89, 5, '2026-01-28 09:44:25'),
(172, 93, 5, '2026-01-28 09:44:25'),
(173, 94, 5, '2026-01-28 09:44:25'),
(174, 95, 5, '2026-01-28 09:44:25'),
(175, 96, 5, '2026-01-28 09:44:25'),
(176, 133, 5, '2026-01-28 09:44:25'),
(177, 156, 2, '2026-01-28 14:58:11'),
(178, 157, 2, '2026-01-28 14:58:11');

SELECT setval('commentaire_vus_id_seq', COALESCE((SELECT MAX(id) FROM commentaire_vus), 0) + 1);

-- Insertion des données dans la table commission
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
(791, 113, 5, '20000.00', '17000.00', '3000.00', 15, '', '2025-12-20 23:50:40', '2025-12-21 00:50:40'),
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
(809, 124, 2, '500.00', '425.00', '75.00', 15, '', '2025-12-25 11:12:59', '2025-12-25 12:12:59'),
(810, 125, 2, '500.00', '425.00', '75.00', 15, 'annule', '2025-12-25 11:13:42', '2025-12-25 12:14:29'),
(811, 126, 2, '500.00', '425.00', '75.00', 15, '', '2025-12-25 12:01:29', '2025-12-25 13:01:29'),
(812, 127, 2, '200.00', '170.00', '30.00', 15, 'annule', '2025-12-25 12:24:55', '2025-12-25 13:25:35'),
(813, 128, 2, '200.00', '170.00', '30.00', 15, 'paye', '2025-12-25 14:07:43', '2025-12-25 15:08:08'),
(937, 129, 2, '200.00', '170.00', '30.00', 15, 'annule', '2026-01-07 04:23:02', '2026-01-07 05:23:54'),
(938, 130, 2, '200.00', '170.00', '30.00', 15, 'paye', '2026-01-07 06:22:14', '2026-01-07 07:22:43'),
(941, 131, 2, '200.00', '170.00', '30.00', 15, 'annule', '2026-01-20 13:46:02', '2026-01-20 14:47:05');

SELECT setval('commission_id_seq', COALESCE((SELECT MAX(id) FROM commission), 0) + 1);

-- Insertion des données dans la table demanderetrait
INSERT INTO demanderetrait (id, utilisateurId, montant, methodePaiement, numeroCompte, statut, dateDemande, dateTraitement, commentaire, fraisRetrait, montantNet) VALUES
(1, 5, '1000.00', 'orange_money', '651436857', 'paye', '2025-11-23 04:12:00', '2025-11-23 05:12:00', 'Retrait immédiat automatique', '0.00', NULL);

SELECT setval('demanderetrait_id_seq', COALESCE((SELECT MAX(id) FROM demanderetrait), 0) + 1);

-- Insertion des données dans la table evenement
INSERT INTO evenement (id, nom, description, date_evenement, type, couleur, actif, dateCreation, dateModification) VALUES
(1, 'Nouvel An', 'Bonne année !', '2024-01-01', 'fete_civile', '#EF4444', TRUE, '2026-01-03 16:41:08', '2026-01-03 16:41:08'),
(2, 'Noël', 'Joyeux Noël', '2024-12-25', 'fete_religieuse', '#10B981', TRUE, '2026-01-03 16:41:08', '2026-01-03 16:41:08'),
(3, 'Pâques', 'Joyeuses Pâques', '2024-04-07', 'fete_religieuse', '#F59E0B', TRUE, '2026-01-03 16:41:08', '2026-01-03 16:41:08'),
(4, 'Fête nationale', 'Fête nationale du pays', '2024-07-01', 'fete_civile', '#3B82F6', TRUE, '2026-01-03 16:41:08', '2026-01-03 16:41:08'),
(5, 'Halloween', 'Joyeux Halloween', '2024-10-31', 'evenement_special', '#8B5CF6', TRUE, '2026-01-03 16:41:08', '2026-01-03 16:41:08'),
(6, 'Saint-Valentin', 'Joyeuse Saint-Valentin', '2024-02-14', 'evenement_special', '#EC4899', TRUE, '2026-01-03 16:41:08', '2026-01-03 16:41:08'),
(7, 'Fête des mères', 'Bonne fête à toutes les mamans', '2024-05-26', 'evenement_special', '#8B5CF6', TRUE, '2026-01-03 16:41:08', '2026-01-03 16:41:08'),
(8, 'Fête des pères', 'Bonne fête à tous les papas', '2024-06-16', 'evenement_special', '#3B82F6', TRUE, '2026-01-03 16:41:08', '2026-01-03 16:41:08');

SELECT setval('evenement_id_seq', COALESCE((SELECT MAX(id) FROM evenement), 0) + 1);

-- Insertion des données dans la table follow
INSERT INTO follow (id, followerId, followingId, dateCreation) VALUES
(6, 2, 5, '2026-01-26 14:59:31'),
(24, 5, 2, '2026-01-28 13:55:26');

SELECT setval('follow_id_seq', COALESCE((SELECT MAX(id) FROM follow), 0) + 1);

-- Insertion des données dans la table notification
INSERT INTO notification (id, utilisateurId, titre, message, type, lien, estLu, dateCreation) VALUES
(1, 2, 'Demande de remboursement', '???? Remboursement demandé - 8000.00 FCFA - Motif: Erreur de paiement', 'warning', '/mes-achats', TRUE, '2025-11-02 13:49:40'),
(3, 1, 'Nouveau remboursement', '???? Remboursement #16 - Transaction: PAYUNIT_1762087763_4779 - Montant: 8000.00 FCFA - Motif: Erreur de paiement', 'warning', '/admin/remboursements', FALSE, '2025-11-02 13:49:40'),
(4, 3, 'Nouveau remboursement', '???? Remboursement #16 - Transaction: PAYUNIT_1762087763_4779 - Montant: 8000.00 FCFA - Motif: Erreur de paiement', 'warning', '/admin/remboursements', TRUE, '2025-11-02 13:49:40'),
(5, 5, 'Ajustement de solde', '???? Débit de 6800.00 FCFA - Remboursement client pour transaction: PAYUNIT_1762087763_4779', 'info', '/vendeur/solde', TRUE, '2025-11-02 13:49:40'),
(6, 19, 'Demande de remboursement', '???? Remboursement demandé - 500.00 FCFA - Motif: Erreur de paiement', 'warning', '/mes-achats', TRUE, '2025-11-03 12:29:34'),
(7, 2, 'Remboursement client', '⚠️ Remboursement demandé pour Data - 500.00 FCFA - Motif client: Erreur de paiement', 'warning', '/vendeur/ventes', TRUE, '2025-11-03 12:29:34'),
(8, 1, 'Nouveau remboursement', '???? Remboursement #17 - Transaction: PAYUNIT_1762169318_6399 - Montant: 500.00 FCFA - Motif: Erreur de paiement', 'warning', '/admin/remboursements', FALSE, '2025-11-03 12:29:35'),
(9, 3, 'Nouveau remboursement', '???? Remboursement #17 - Transaction: PAYUNIT_1762169318_6399 - Montant: 500.00 FCFA - Motif: Erreur de paiement', 'warning', '/admin/remboursements', TRUE, '2025-11-03 12:29:35'),
(10, 2, 'Ajustement de solde', '???? Débit de 425.00 FCFA - Remboursement client pour transaction: PAYUNIT_1762169318_6399', 'info', '/vendeur/solde', TRUE, '2025-11-03 12:29:35'),
(11, 7, 'Demande de remboursement', '???? Remboursement demandé - 5000.00 FCFA - Motif: Erreur de paiement', 'warning', '/mes-achats', TRUE, '2025-11-03 21:07:09'),
(12, 5, 'Remboursement client', '⚠️ Remboursement demandé pour Cooking - 5000.00 FCFA - Motif client: Erreur de paiement', 'warning', '/vendeur/ventes', FALSE, '2025-11-03 21:07:09'),
(13, 1, 'Nouveau remboursement', '???? Remboursement #18 - Transaction: PAYUNIT_1762200363_5461 - Montant: 5000.00 FCFA - Motif: Erreur de paiement', 'warning', '/admin/remboursements', FALSE, '2025-11-03 21:07:09'),
(14, 3, 'Nouveau remboursement', '???? Remboursement #18 - Transaction: PAYUNIT_1762200363_5461 - Montant: 5000.00 FCFA - Motif: Erreur de paiement', 'warning', '/admin/remboursements', TRUE, '2025-11-03 21:07:09'),
(15, 5, 'Ajustement de solde', '???? Débit de 4250.00 FCFA - Remboursement client pour transaction: PAYUNIT_1762200363_5461', 'info', '/vendeur/solde', FALSE, '2025-11-03 21:07:09'),
(16, 7, 'Demande de remboursement', '???? Remboursement demandé - 8000.00 FCFA - Motif: Erreur de paiement', 'warning', '/mes-achats', TRUE, '2025-11-03 22:11:20'),
(17, 5, 'Remboursement client', '⚠️ Remboursement demandé pour Cuisine - 8000.00 FCFA - Motif client: Erreur de paiement', 'warning', '/vendeur/ventes', FALSE, '2025-11-03 22:11:20'),
(18, 1, 'Nouveau remboursement', '???? Remboursement #19 - Transaction: PAYUNIT_1762204246_9088 - Montant: 8000.00 FCFA - Motif: Erreur de paiement', 'warning', '/admin/remboursements', FALSE, '2025-11-03 22:11:20'),
(19, 3, 'Nouveau remboursement', '???? Remboursement #19 - Transaction: PAYUNIT_1762204246_9088 - Montant: 8000.00 FCFA - Motif: Erreur de paiement', 'warning', '/admin/remboursements', TRUE, '2025-11-03 22:11:20'),
(20, 5, 'Ajustement de solde', '???? Débit de 6800.00 FCFA - Remboursement client pour transaction: PAYUNIT_1762204246_9088', 'info', '/vendeur/solde', TRUE, '2025-11-03 22:11:20'),
(21, 7, 'Achat confirmé', 'ߎɠVotre achat a été confirmé ! Formation débloquée.', 'success', '/mes-formations', TRUE, '2025-11-03 22:19:16'),
(22, 2, 'Achat confirmé', 'ߎɠVotre achat a été confirmé ! Formation débloquée.', 'success', '/mes-formations', FALSE, '2025-11-04 11:05:04'),
(23, 2, 'Demande de remboursement', '???? Remboursement demandé - 5000.00 FCFA - Motif: Escroquerie', 'warning', '/mes-achats', FALSE, '2025-11-04 11:48:02'),
(24, 5, 'Remboursement client', '⚠️ Remboursement demandé pour Cooking - 5000.00 FCFA - Motif client: Escroquerie', 'warning', '/vendeur/ventes', TRUE, '2025-11-04 11:48:02'),
(25, 1, 'Nouveau remboursement', '???? Remboursement #20 - Transaction: PAYUNIT_1762253205_6937 - Montant: 5000.00 FCFA - Motif: Escroquerie', 'warning', '/admin/remboursements', FALSE, '2025-11-04 11:48:03'),
(26, 3, 'Nouveau remboursement', '???? Remboursement #20 - Transaction: PAYUNIT_1762253205_6937 - Montant: 5000.00 FCFA - Motif: Escroquerie', 'warning', '/admin/remboursements', TRUE, '2025-11-04 11:48:03'),
(27, 5, 'Ajustement de solde', '???? Débit de 4250.00 FCFA - Remboursement client pour transaction: PAYUNIT_1762253205_6937', 'info', '/vendeur/solde', TRUE, '2025-11-04 11:48:03'),
(28, 7, 'Achat confirmé', 'ߎɠVotre achat a été confirmé ! Formation débloquée.', 'success', '/mes-formations', TRUE, '2025-11-06 11:25:32'),
(29, 1, 'Nouvelle commission', 'ߒܠNouvelle vente #103 - Total: 20 000 FCFA - Commissions: 0 FCFA', 'info', '/admin/commissions', FALSE, '2025-11-06 11:25:32'),
(30, 3, 'Nouvelle commission', 'ߒܠNouvelle vente #103 - Total: 20 000 FCFA - Commissions: 0 FCFA', 'info', '/admin/commissions', TRUE, '2025-11-06 11:25:32'),
(31, 1, 'Achat confirmé', 'ߎɠVotre achat a été confirmé ! Formation débloquée.', 'success', '/mes-formations', FALSE, '2025-11-06 13:00:31'),
(32, 1, 'Nouvelle commission', 'ߒܠNouvelle vente #104 - Total: 500 FCFA - Commissions: 0 FCFA', 'info', '/admin/commissions', FALSE, '2025-11-06 13:00:32'),
(33, 3, 'Nouvelle commission', 'ߒܠNouvelle vente #104 - Total: 500 FCFA - Commissions: 0 FCFA', 'info', '/admin/commissions', TRUE, '2025-11-06 13:00:32'),
(34, 18, 'Achat confirmé', 'ߎɠVotre achat a été confirmé ! Formation débloquée.', 'success', '/mes-formations', TRUE, '2025-11-06 13:56:02'),
(35, 1, 'Nouvelle commission', 'ߒܠNouvelle vente #105 - Total: 200 FCFA - Commissions: 0 FCFA', 'info', '/admin/commissions', FALSE, '2025-11-06 13:56:02'),
(36, 3, 'Nouvelle commission', 'ߒܠNouvelle vente #105 - Total: 200 FCFA - Commissions: 0 FCFA', 'info', '/admin/commissions', TRUE, '2025-11-06 13:56:03'),
(37, 21, 'Achat confirmé', 'ߎɠVotre achat a été confirmé ! Formation débloquée.', 'success', '/mes-formations', FALSE, '2025-11-06 15:36:35'),
(38, 1, 'Nouvelle commission', 'ߒܠNouvelle vente #106 - Total: 20 000 FCFA - Commissions: 0 FCFA', 'info', '/admin/commissions', FALSE, '2025-11-06 15:36:35'),
(39, 3, 'Nouvelle commission', 'ߒܠNouvelle vente #106 - Total: 20 000 FCFA - Commissions: 0 FCFA', 'info', '/admin/commissions', FALSE, '2025-11-06 15:36:35'),
(40, 5, 'Achat confirmé', 'ߎɠVotre achat a été confirmé ! Formation débloquée.', 'success', '/mes-formations', TRUE, '2025-11-09 19:30:58'),
(41, 1, 'Nouvelle commission', 'ߒܠNouvelle vente #107 - Total: 200 FCFA - Commissions: 0 FCFA', 'info', '/admin/commissions', FALSE, '2025-11-09 19:30:59'),
(42, 3, 'Nouvelle commission', 'ߒܠNouvelle vente #107 - Total: 200 FCFA - Commissions: 0 FCFA', 'info', '/admin/commissions', FALSE, '2025-11-09 19:30:59'),
(43, 2, 'Achat confirmé', 'ߎɠVotre achat a été confirmé ! Formation débloquée.', 'success', '/mes-formations', FALSE, '2025-11-09 19:46:56'),
(44, 5, 'Paiement reçu', 'ߒРNouvelle vente ! Vous avez reçu 4 250 FCFA immédiatement pour votre formation.', 'success', '/vendeur/ventes', TRUE, '2025-11-09 19:46:56'),
(45, 1, 'Nouvelle commission', 'ߒܠNouvelle vente #108 - Total: 5 000 FCFA - Commissions: 750 FCFA', 'info', '/admin/commissions', FALSE, '2025-11-09 19:46:56'),
(46, 3, 'Nouvelle commission', 'ߒܠNouvelle vente #108 - Total: 5 000 FCFA - Commissions: 750 FCFA', 'info', '/admin/commissions', FALSE, '2025-11-09 19:46:56'),
(47, 22, 'Achat confirmé', 'ߎɠVotre achat a été confirmé ! Formation débloquée.', 'success', '/mes-formations', TRUE, '2025-11-16 14:22:29'),
(48, 2, 'Paiement reçu', 'ߒРNouvelle vente ! Vous avez reçu 170 FCFA immédiatement pour votre formation.', 'success', '/vendeur/ventes', TRUE, '2025-11-16 14:22:29'),
(49, 1, 'Nouvelle commission', 'ߒܠNouvelle vente #109 - Total: 200 FCFA - Commissions: 30 FCFA', 'info', '/admin/commissions', FALSE, '2025-11-16 14:22:30'),
(50, 3, 'Nouvelle commission', 'ߒܠNouvelle vente #109 - Total: 200 FCFA - Commissions: 30 FCFA', 'info', '/admin/commissions', FALSE, '2025-11-16 14:22:30'),
(51, 22, 'Achat confirmé', 'ߎɠVotre achat a été confirmé ! Formation débloquée.', 'success', '/mes-formations', FALSE, '2025-11-22 13:08:42'),
(52, 5, 'Paiement reçu', 'ߒРNouvelle vente ! Vous avez reçu 17 000 FCFA immédiatement pour votre formation.', 'success', '/vendeur/ventes', FALSE, '2025-11-22 13:08:45'),
(53, 1, 'Nouvelle commission', 'ߒܠNouvelle vente #110 - Total: 20 000 FCFA - Commissions: 3 000 FCFA', 'info', '/admin/commissions', FALSE, '2025-11-22 13:08:46'),
(54, 3, 'Nouvelle commission', 'ߒܠNouvelle vente #110 - Total: 20 000 FCFA - Commissions: 3 000 FCFA', 'info', '/admin/commissions', TRUE, '2025-11-22 13:08:46'),
(55, 5, 'Retrait effectué', '✅ Votre retrait de 1 000 FCFA a été effectué avec succès via Orange money. Vous le recevrez sous 24-48h au 651436857.', 'success', NULL, TRUE, '2025-11-23 05:12:00'),
(56, 24, 'Achat confirmé', 'ߎɠVotre achat a été confirmé ! Formation débloquée.', 'success', '/mes-formations', FALSE, '2025-12-17 19:03:29'),
(57, 2, 'Paiement reçu', 'ߒРNouvelle vente ! Vous avez reçu 170 FCFA immédiatement pour votre formation.', 'success', '/vendeur/ventes', FALSE, '2025-12-17 19:03:29'),
(58, 1, 'Nouvelle commission', 'ߒܠNouvelle vente #111 - Total: 200 FCFA - Commissions: 30 FCFA', 'info', '/admin/commissions', FALSE, '2025-12-17 19:03:30'),
(59, 3, 'Nouvelle commission', 'ߒܠNouvelle vente #111 - Total: 200 FCFA - Commissions: 30 FCFA', 'info', '/admin/commissions', FALSE, '2025-12-17 19:03:31'),
(60, 25, 'Achat confirmé', 'ߎɠVotre achat a été confirmé ! Formation débloquée.', 'success', '/mes-formations', FALSE, '2025-12-20 12:57:07'),
(61, 2, 'Paiement reçu', 'ߒРNouvelle vente ! Vous avez reçu 170 FCFA immédiatement pour votre formation.', 'success', '/vendeur/ventes', FALSE, '2025-12-20 12:57:08'),
(62, 1, 'Nouvelle commission', 'ߒܠNouvelle vente #112 - Total: 200 FCFA - Commissions: 30 FCFA', 'info', '/admin/commissions', FALSE, '2025-12-20 12:57:08'),
(63, 3, 'Nouvelle commission', 'ߒܠNouvelle vente #112 - Total: 200 FCFA - Commissions: 30 FCFA', 'info', '/admin/commissions', FALSE, '2025-12-20 12:57:08'),
(64, 25, 'Demande de remboursement', '???? Remboursement demandé - 8000.00 FCFA - Motif: Arnaque suspectée', 'warning', '/mes-achats', TRUE, '2025-12-21 01:59:46'),
(65, 5, 'Remboursement client', '⚠️ Remboursement demandé pour Cuisine - 8000.00 FCFA - Motif client: Arnaque suspectée', 'warning', '/vendeur/ventes', FALSE, '2025-12-21 01:59:47'),
(66, 1, 'Nouveau remboursement', '???? Remboursement #21 - Transaction: PAYUNIT_1766278721_2300 - Montant: 8000.00 FCFA - Motif: Arnaque suspectée', 'warning', '/admin/remboursements', FALSE, '2025-12-21 01:59:47'),
(67, 3, 'Nouveau remboursement', '???? Remboursement #21 - Transaction: PAYUNIT_1766278721_2300 - Montant: 8000.00 FCFA - Motif: Arnaque suspectée', 'warning', '/admin/remboursements', FALSE, '2025-12-21 01:59:47'),
(68, 5, 'Ajustement de solde', '???? Débit de 6800.00 FCFA - Remboursement client pour transaction: PAYUNIT_1766278721_2300', 'info', '/vendeur/solde', FALSE, '2025-12-21 01:59:47'),
(70, 5, 'Paiement reçu', 'ߒРNouvelle vente ! Vous avez reçu 6 800 FCFA immédiatement pour votre formation.', 'success', '/vendeur/ventes', FALSE, '2025-12-21 03:02:58'),
(71, 1, 'Nouvelle commission', 'ߒܠNouvelle vente #115 - Total: 8 000 FCFA - Commissions: 1 200 FCFA', 'info', '/admin/commissions', FALSE, '2025-12-21 03:02:58'),
(72, 3, 'Nouvelle commission', 'ߒܠNouvelle vente #115 - Total: 8 000 FCFA - Commissions: 1 200 FCFA', 'info', '/admin/commissions', FALSE, '2025-12-21 03:02:59'),
(73, 25, 'Demande de remboursement', '???? Remboursement demandé - 5000.00 FCFA - Motif: Escroquerie', 'warning', '/mes-achats', FALSE, '2025-12-21 04:26:13'),
(74, 5, 'Remboursement client', '⚠️ Remboursement demandé pour Cooking - 5000.00 FCFA - Motif client: Escroquerie', 'warning', '/vendeur/ventes', FALSE, '2025-12-21 04:26:13'),
(75, 1, 'Nouveau remboursement', '???? Remboursement #22 - Transaction: PAYUNIT_1766287545_9022 - Montant: 5000.00 FCFA - Motif: Escroquerie', 'warning', '/admin/remboursements', FALSE, '2025-12-21 04:26:14'),
(76, 3, 'Nouveau remboursement', '???? Remboursement #22 - Transaction: PAYUNIT_1766287545_9022 - Montant: 5000.00 FCFA - Motif: Escroquerie', 'warning', '/admin/remboursements', FALSE, '2025-12-21 04:26:14'),
(77, 5, 'Ajustement de solde', '???? Débit de 4250.00 FCFA - Remboursement client pour transaction: PAYUNIT_1766287545_9022', 'info', '/vendeur/solde', FALSE, '2025-12-21 04:26:14'),
(78, 25, 'Achat confirmé', 'ߎɠVotre achat a été confirmé ! Formation débloquée.', 'success', '/mes-formations', FALSE, '2025-12-21 14:17:54'),
(79, 5, 'Paiement reçu', 'ߒРNouvelle vente ! Vous avez reçu 4 250 FCFA immédiatement pour votre formation.', 'success', '/vendeur/ventes', FALSE, '2025-12-21 14:17:54'),
(80, 1, 'Nouvelle commission', 'ߒܠNouvelle vente #117 - Total: 5 000 FCFA - Commissions: 750 FCFA', 'info', '/admin/commissions', FALSE, '2025-12-21 14:17:54'),
(81, 3, 'Nouvelle commission', 'ߒܠNouvelle vente #117 - Total: 5 000 FCFA - Commissions: 750 FCFA', 'info', '/admin/commissions', FALSE, '2025-12-21 14:17:54'),
(82, 25, 'Demande de remboursement', '???? Remboursement demandé - 500.00 FCFA - Motif: Erreur de paiement', 'warning', '/mes-achats', FALSE, '2025-12-21 15:57:50'),
(83, 2, 'Remboursement client', '⚠️ Remboursement demandé pour Data - 500.00 FCFA - Motif client: Erreur de paiement', 'warning', '/vendeur/ventes', FALSE, '2025-12-21 15:57:50'),
(84, 1, 'Nouveau remboursement', '???? Remboursement #23 - Transaction: PAYUNIT_1766329011_7467 - Montant: 500.00 FCFA - Motif: Erreur de paiement', 'warning', '/admin/remboursements', FALSE, '2025-12-21 15:57:50'),
(85, 3, 'Nouveau remboursement', '???? Remboursement #23 - Transaction: PAYUNIT_1766329011_7467 - Montant: 500.00 FCFA - Motif: Erreur de paiement', 'warning', '/admin/remboursements', FALSE, '2025-12-21 15:57:50'),
(86, 2, 'Ajustement de solde', '???? Débit de 425.00 FCFA - Remboursement client pour transaction: PAYUNIT_1766329011_7467', 'info', '/vendeur/solde', FALSE, '2025-12-21 15:57:50'),
(87, 25, 'Achat confirmé', 'ߎɠVotre achat a été confirmé ! Formation débloquée.', 'success', '/mes-formations', FALSE, '2025-12-21 16:01:26'),
(88, 2, 'Paiement reçu', 'ߒРNouvelle vente ! Vous avez reçu 425 FCFA immédiatement pour votre formation.', 'success', '/vendeur/ventes', FALSE, '2025-12-21 16:01:26'),
(89, 1, 'Nouvelle commission', 'ߒܠNouvelle vente #119 - Total: 500 FCFA - Commissions: 75 FCFA', 'info', '/admin/commissions', FALSE, '2025-12-21 16:01:26'),
(90, 3, 'Nouvelle commission', 'ߒܠNouvelle vente #119 - Total: 500 FCFA - Commissions: 75 FCFA', 'info', '/admin/commissions', FALSE, '2025-12-21 16:01:26'),
(91, 22, 'Demande de remboursement', '???? Remboursement demandé - 8000.00 FCFA - Motif: Erreur de paiement', 'warning', '/mes-achats', FALSE, '2025-12-21 17:11:34'),
(92, 5, 'Remboursement client', '⚠️ Remboursement demandé pour Cuisine - 8000.00 FCFA - Motif client: Erreur de paiement', 'warning', '/vendeur/ventes', FALSE, '2025-12-21 17:11:34'),
(93, 1, 'Nouveau remboursement', '???? Remboursement #24 - Transaction: PAYUNIT_1766333452_3882 - Montant: 8000.00 FCFA - Motif: Erreur de paiement', 'warning', '/admin/remboursements', FALSE, '2025-12-21 17:11:34'),
(94, 3, 'Nouveau remboursement', '???? Remboursement #24 - Transaction: PAYUNIT_1766333452_3882 - Montant: 8000.00 FCFA - Motif: Erreur de paiement', 'warning', '/admin/remboursements', FALSE, '2025-12-21 17:11:34'),
(95, 5, 'Ajustement de solde', '???? Débit de 6800.00 FCFA - Remboursement client pour transaction: PAYUNIT_1766333452_3882', 'info', '/vendeur/solde', FALSE, '2025-12-21 17:11:34'),
(96, 22, 'Achat confirmé', 'ߎɠVotre achat a été confirmé ! Formation débloquée.', 'success', '/mes-formations', FALSE, '2025-12-23 05:11:21'),
(97, 5, 'Paiement reçu', 'ߒРNouvelle vente ! Vous avez reçu 6 800 FCFA immédiatement pour votre formation.', 'success', '/vendeur/ventes', FALSE, '2025-12-23 05:11:21'),
(98, 1, 'Nouvelle commission', 'ߒܠNouvelle vente #121 - Total: 8 000 FCFA - Commissions: 1 200 FCFA', 'info', '/admin/commissions', FALSE, '2025-12-23 05:11:21'),
(99, 3, 'Nouvelle commission', 'ߒܠNouvelle vente #121 - Total: 8 000 FCFA - Commissions: 1 200 FCFA', 'info', '/admin/commissions', FALSE, '2025-12-23 05:11:21'),
(100, 22, 'Demande de remboursement', '???? Remboursement demandé - 5000.00 FCFA - Motif: Erreur de paiement', 'warning', '/mes-achats', FALSE, '2025-12-23 07:28:09'),
(101, 5, 'Remboursement client', '⚠️ Remboursement demandé pour Cooking - 5000.00 FCFA - Motif client: Erreur de paiement', 'warning', '/vendeur/ventes', FALSE, '2025-12-23 07:28:10'),
(102, 1, 'Nouveau remboursement', '???? Remboursement #25 - Transaction: PAYUNIT_1766471250_5524 - Montant: 5000.00 FCFA - Motif: Erreur de paiement', 'warning', '/admin/remboursements', FALSE, '2025-12-23 07:28:10'),
(103, 3, 'Nouveau remboursement', '???? Remboursement #25 - Transaction: PAYUNIT_1766471250_5524 - Montant: 5000.00 FCFA - Motif: Erreur de paiement', 'warning', '/admin/remboursements', FALSE, '2025-12-23 07:28:10'),
(104, 5, 'Ajustement de solde', '???? Débit de 4250.00 FCFA - Remboursement client pour transaction: PAYUNIT_1766471250_5524', 'info', '/vendeur/solde', FALSE, '2025-12-23 07:28:10'),
(105, 22, 'Achat confirmé', 'ߎɠVotre achat a été confirmé ! Formation débloquée.', 'success', '/mes-formations', FALSE, '2025-12-23 07:46:32'),
(106, 5, 'Paiement reçu', 'ߒРNouvelle vente ! Vous avez reçu 4 250 FCFA immédiatement pour votre formation.', 'success', '/vendeur/ventes', FALSE, '2025-12-23 07:46:32'),
(107, 1, 'Nouvelle commission', 'ߒܠNouvelle vente #123 - Total: 5 000 FCFA - Commissions: 750 FCFA', 'info', '/admin/commissions', FALSE, '2025-12-23 07:46:32'),
(108, 3, 'Nouvelle commission', 'ߒܠNouvelle vente #123 - Total: 5 000 FCFA - Commissions: 750 FCFA', 'info', '/admin/commissions', FALSE, '2025-12-23 07:46:32'),
(109, 22, 'Demande de remboursement', '???? Remboursement demandé - 500.00 FCFA - Motif: Arnaque suspectée', 'warning', '/mes-achats', FALSE, '2025-12-25 12:14:29'),
(110, 2, 'Remboursement client', '⚠️ Remboursement demandé pour Data - 500.00 FCFA - Motif client: Arnaque suspectée', 'warning', '/vendeur/ventes', FALSE, '2025-12-25 12:14:29'),
(111, 1, 'Nouveau remboursement', '???? Remboursement #26 - Transaction: PAYUNIT_1766661221_1653 - Montant: 500.00 FCFA - Motif: Arnaque suspectée', 'warning', '/admin/remboursements', FALSE, '2025-12-25 12:14:29'),
(112, 3, 'Nouveau remboursement', '???? Remboursement #26 - Transaction: PAYUNIT_1766661221_1653 - Montant: 500.00 FCFA - Motif: Arnaque suspectée', 'warning', '/admin/remboursements', FALSE, '2025-12-25 12:14:29'),
(113, 2, 'Ajustement de solde', '???? Débit de 425.00 FCFA - Remboursement client pour transaction: PAYUNIT_1766661221_1653', 'info', '/vendeur/solde', FALSE, '2025-12-25 12:14:30'),
(115, 2, 'Remboursement client', '⚠️ Remboursement demandé pour Maitriser la cuisine - 200.00 FCFA - Motif client: Arnaque suspectée', 'warning', '/vendeur/ventes', TRUE, '2025-12-25 13:25:36'),
(116, 1, 'Nouveau remboursement', '???? Remboursement #27 - Transaction: PAYUNIT_1766665494_5766 - Montant: 200.00 FCFA - Motif: Arnaque suspectée', 'warning', '/admin/remboursements', FALSE, '2025-12-25 13:25:36'),
(117, 3, 'Nouveau remboursement', '???? Remboursement #27 - Transaction: PAYUNIT_1766665494_5766 - Montant: 200.00 FCFA - Motif: Arnaque suspectée', 'warning', '/admin/remboursements', FALSE, '2025-12-25 13:25:36'),
(118, 2, 'Ajustement de solde', '???? Débit de 170.00 FCFA - Remboursement client pour transaction: PAYUNIT_1766665494_5766', 'info', '/vendeur/solde', FALSE, '2025-12-25 13:25:36'),
(119, 20, 'Achat confirmé', 'ߎɠVotre achat a été confirmé ! Formation débloquée.', 'success', '/mes-formations', FALSE, '2025-12-25 15:08:09'),
(120, 2, 'Paiement reçu', 'ߒРNouvelle vente ! Vous avez reçu 170 FCFA immédiatement pour votre formation.', 'success', '/vendeur/ventes', TRUE, '2025-12-25 15:08:09'),
(121, 1, 'Nouvelle commission', 'ߒܠNouvelle vente #128 - Total: 200 FCFA - Commissions: 30 FCFA', 'info', '/admin/commissions', FALSE, '2025-12-25 15:08:09'),
(122, 3, 'Nouvelle commission', 'ߒܠNouvelle vente #128 - Total: 200 FCFA - Commissions: 30 FCFA', 'info', '/admin/commissions', FALSE, '2025-12-25 15:08:09'),
(123, 26, 'Demande de remboursement', '???? Remboursement demandé - 200.00 FCFA - Motif: Arnaque suspectée', 'warning', '/mes-achats', FALSE, '2026-01-07 05:23:55'),
(124, 2, 'Remboursement client', '⚠️ Remboursement demandé pour Maitriser la cuisine - 200.00 FCFA - Motif client: Arnaque suspectée', 'warning', '/vendeur/ventes', FALSE, '2026-01-07 05:23:55'),
(125, 1, 'Nouveau remboursement', '???? Remboursement #28 - Transaction: PAYUNIT_1767759780_1809 - Montant: 200.00 FCFA - Motif: Arnaque suspectée', 'warning', '/admin/remboursements', FALSE, '2026-01-07 05:23:55'),
(126, 3, 'Nouveau remboursement', '???? Remboursement #28 - Transaction: PAYUNIT_1767759780_1809 - Montant: 200.00 FCFA - Motif: Arnaque suspectée', 'warning', '/admin/remboursements', FALSE, '2026-01-07 05:23:55'),
(127, 2, 'Ajustement de solde', '???? Débit de 170.00 FCFA - Remboursement client pour transaction: PAYUNIT_1767759780_1809', 'info', '/vendeur/solde', FALSE, '2026-01-07 05:23:55'),
(128, 26, 'Achat confirmé', 'ߎɠVotre achat a été confirmé ! Formation débloquée.', 'success', '/mes-formations', FALSE, '2026-01-07 07:22:44'),
(129, 2, 'Paiement reçu', 'ߒРNouvelle vente ! Vous avez reçu 170 FCFA immédiatement pour votre formation.', 'success', '/vendeur/ventes', FALSE, '2026-01-07 07:22:44'),
(130, 1, 'Nouvelle commission', 'ߒܠNouvelle vente #130 - Total: 200 FCFA - Commissions: 30 FCFA', 'info', '/admin/commissions', FALSE, '2026-01-07 07:22:44'),
(131, 3, 'Nouvelle commission', 'ߒܠNouvelle vente #130 - Total: 200 FCFA - Commissions: 30 FCFA', 'info', '/admin/commissions', FALSE, '2026-01-07 07:22:44'),
(133, 4, 'Demande de remboursement', '???? Remboursement demandé - 200.00 FCFA - Motif: Arnaque suspectée', 'warning', '/mes-achats', FALSE, '2026-01-20 14:47:05'),
(134, 2, 'Remboursement client', '⚠️ Remboursement demandé pour Maitriser la cuisine - 200.00 FCFA - Motif client: Arnaque suspectée', 'warning', '/vendeur/ventes', TRUE, '2026-01-20 14:47:06'),
(135, 1, 'Nouveau remboursement', '???? Remboursement #29 - Transaction: PAYUNIT_1768916761_6623 - Montant: 200.00 FCFA - Motif: Arnaque suspectée', 'warning', '/admin/remboursements', FALSE, '2026-01-20 14:47:06'),
(136, 3, 'Nouveau remboursement', '???? Remboursement #29 - Transaction: PAYUNIT_1768916761_6623 - Montant: 200.00 FCFA - Motif: Arnaque suspectée', 'warning', '/admin/remboursements', FALSE, '2026-01-20 14:47:06'),
(137, 2, 'Ajustement de solde', '???? Débit de 170.00 FCFA - Remboursement client pour transaction: PAYUNIT_1768916761_6623', 'info', '/vendeur/solde', FALSE, '2026-01-20 14:47:06'),
(138, 4, 'ߎ Joyeux anniversaire ߎ§', 'Toute l''équipe vous souhaite une excellente journée ! ߎ˜nnProfitez bien de cette journée spéciale !', 'info', NULL, FALSE, '2026-01-20 14:49:49'),
(139, 5, 'Nouvel abonné', 'Kopi leticia vous suit', 'info', '/profile?vendeurId=2', TRUE, '2026-01-26 14:59:32'),
(140, 2, 'Nouvel abonné', 'Junior vous suit', 'info', '/profile?vendeurId=5', FALSE, '2026-01-26 15:13:41'),
(141, 2, 'Nouvel abonné', 'Junior vous suit', 'info', '/profile?vendeurId=5', FALSE, '2026-01-26 15:14:17'),
(142, 2, 'Nouvel abonné', 'Junior vous suit', 'info', '/profile?vendeurId=5', FALSE, '2026-01-26 15:16:04'),
(157, 2, 'Nouvel abonné', 'Junior vous suit', 'info', '/profile?vendeurId=5', FALSE, '2026-01-28 13:55:27');

SELECT setval('notification_id_seq', COALESCE((SELECT MAX(id) FROM notification), 0) + 1);

-- Insertion des données dans la table produit
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

SELECT setval('produit_id_seq', COALESCE((SELECT MAX(id) FROM produit), 0) + 1);

-- Insertion des données dans la table produitreaction
INSERT INTO produitreaction (produitId, likes, pouces) VALUES
(694, 2, 2),
(695, 1, 1),
(699, 2, 0),
(700, 1, 0),
(702, 5, 4),
(703, 1, 1),
(711, 1, 0),
(713, 0, 0),
(732, 1, 0),
(733, 0, 1),
(738, 1, 3),
(740, 1, 1),
(741, 1, 1),
(748, 1, 0),
(749, 2, 1),
(751, 2, 1),
(752, 4, 2);

-- Insertion des données dans la table push_tokens
INSERT INTO push_tokens (id, userId, token, createdAt, updatedAt, platform) VALUES
(1, 5, 'ExponentPushToken[dRe82xFziLkFB_rYH_hgbz]', '2025-12-20 08:31:11', '2026-01-04 07:19:45', 'android'),
(6, 2, 'ExponentPushToken[dRe82xFziLkFB_rYH_hgbz]', '2025-12-20 11:55:52', '2026-01-03 13:25:16', 'android'),
(8, 7, 'ExponentPushToken[dRe82xFziLkFB_rYH_hgbz]', '2025-12-20 12:03:03', '2025-12-20 12:03:03', 'android'),
(9, 18, 'ExponentPushToken[dRe82xFziLkFB_rYH_hgbz]', '2025-12-20 12:05:30', '2026-01-05 07:03:39', 'android'),
(10, 25, 'ExponentPushToken[dRe82xFziLkFB_rYH_hgbz]', '2025-12-20 12:09:02', '2025-12-21 17:04:42', 'android'),
(66, 22, 'ExponentPushToken[dRe82xFziLkFB_rYH_hgbz]', '2025-12-21 17:09:55', '2025-12-25 12:53:40', 'android'),
(116, 20, 'ExponentPushToken[dRe82xFziLkFB_rYH_hgbz]', '2025-12-25 12:58:58', '2025-12-28 15:01:52', 'android'),
(282, 26, 'ExponentPushToken[dRe82xFziLkFB_rYH_hgbz]', '2026-01-05 07:13:00', '2026-01-19 14:36:56', 'android');

SELECT setval('push_tokens_id_seq', COALESCE((SELECT MAX(id) FROM push_tokens), 0) + 1);

-- Insertion des données dans la table reactionutilisateur
INSERT INTO reactionutilisateur (id, utilisateurId, produitId, likeReaction, pouceReaction, dateCreation) VALUES
(1, 4, 702, TRUE, TRUE, '2025-07-02 07:00:16'),
(2, 2, 702, TRUE, TRUE, '2025-07-04 08:18:28'),
(3, 2, 700, FALSE, FALSE, '2025-07-04 09:33:07'),
(4, 2, 695, FALSE, FALSE, '2026-01-26 10:54:21'),
(5, 4, 700, TRUE, FALSE, '2025-07-02 09:00:20'),
(6, 4, 695, TRUE, TRUE, '2025-07-02 09:06:38'),
(7, 4, 694, FALSE, TRUE, '2025-07-02 09:01:01'),
(8, 2, 694, TRUE, TRUE, '2026-01-26 10:54:29'),
(13, 5, 694, TRUE, FALSE, '2025-07-05 15:48:17'),
(14, 7, 702, TRUE, TRUE, '2025-07-05 15:43:05'),
(15, 5, 695, FALSE, FALSE, '2025-07-05 14:29:06'),
(16, 5, 702, TRUE, TRUE, '2025-07-06 18:44:36'),
(18, 5, 700, FALSE, FALSE, '2025-07-05 15:48:08'),
(22, 2, 703, TRUE, TRUE, '2025-07-12 18:29:16'),
(23, 7, 703, FALSE, FALSE, '2025-07-13 22:30:27'),
(27, 7, 699, TRUE, FALSE, '2025-07-17 14:05:03'),
(28, 1, 699, TRUE, FALSE, '2025-07-17 14:06:05'),
(30, 3, 732, TRUE, FALSE, '2025-08-08 08:10:33'),
(31, 3, 702, TRUE, FALSE, '2025-08-14 03:43:59'),
(32, 2, 738, TRUE, TRUE, '2025-11-12 14:23:15'),
(33, 3, 733, FALSE, TRUE, '2025-10-13 05:52:44'),
(34, 1, 713, FALSE, FALSE, '2025-08-19 18:56:23'),
(35, 3, 738, FALSE, TRUE, '2025-10-14 20:09:21'),
(36, 5, 738, FALSE, TRUE, '2025-10-12 23:06:13'),
(37, 3, 740, TRUE, TRUE, '2025-10-14 20:09:31'),
(38, 20, 741, FALSE, TRUE, '2025-11-01 06:20:02'),
(39, 5, 751, FALSE, FALSE, '2025-11-02 05:48:03'),
(40, 5, 749, TRUE, FALSE, '2025-11-02 05:48:10'),
(41, 4, 751, TRUE, FALSE, '2025-11-03 05:08:29'),
(42, 2, 711, TRUE, FALSE, '2025-11-12 16:36:01'),
(43, 2, 751, FALSE, TRUE, '2026-01-26 11:21:58'),
(44, 2, 752, TRUE, TRUE, '2025-12-16 23:51:32'),
(45, 18, 751, FALSE, FALSE, '2025-11-15 01:25:03'),
(46, 18, 752, TRUE, FALSE, '2025-11-15 16:12:04'),
(47, 2, 741, TRUE, FALSE, '2025-12-15 16:25:23'),
(48, 23, 752, TRUE, FALSE, '2025-12-16 23:41:02'),
(49, 23, 751, TRUE, FALSE, '2025-12-16 23:41:54'),
(50, 24, 752, TRUE, FALSE, '2025-12-17 18:52:02'),
(51, 26, 752, FALSE, TRUE, '2026-01-20 03:18:21'),
(52, 2, 748, TRUE, FALSE, '2026-01-26 08:41:20'),
(53, 2, 749, TRUE, TRUE, '2026-01-26 13:59:00');

SELECT setval('reactionutilisateur_id_seq', COALESCE((SELECT MAX(id) FROM reactionutilisateur), 0) + 1);

-- Insertion des données dans la table remboursement
INSERT INTO remboursement (id, venteId, produitId, acheteurId, vendeurId, montant, motif, pourcentageVisionne, statut, raisonRefus, dateTraitement, dateCreation) VALUES
(8, 83, 741, 3, 2, '500.00', 'Erreur de paiement', 0, 'demande', NULL, NULL, '2025-10-30 14:27:14'),
(9, 84, 741, 19, 2, '500.00', 'Erreur de paiement', 0, 'demande', NULL, NULL, '2025-10-31 10:18:02'),
(10, 85, 751, 20, 5, '20500.00', 'Erreur de paiement', 0, 'demande', NULL, NULL, '2025-11-01 03:06:04'),
(11, 89, 741, 20, 2, '500.00', 'Erreur de paiement', 0, 'demande', NULL, NULL, '2025-11-01 05:35:53'),
(12, 90, 741, 20, 2, '500.00', 'Escroquerie', 0, 'demande', NULL, NULL, '2025-11-01 06:41:59'),
(13, 91, 748, 2, 5, '5000.00', 'Erreur de paiement', 0, 'demande', NULL, NULL, '2025-11-02 11:56:41'),
(16, 93, 749, 2, 5, '8000.00', 'Erreur de paiement', 0, 'demande', NULL, NULL, '2025-11-02 13:49:40'),
(17, 94, 741, 19, 2, '500.00', 'Erreur de paiement', 0, 'demande', NULL, NULL, '2025-11-03 12:29:34'),
(18, 97, 748, 7, 5, '5000.00', 'Erreur de paiement', 0, 'demande', NULL, NULL, '2025-11-03 21:07:09'),
(19, 99, 749, 7, 5, '8000.00', 'Erreur de paiement', 0, 'demande', NULL, NULL, '2025-11-03 22:11:20'),
(20, 102, 748, 2, 5, '5000.00', 'Escroquerie', 0, 'demande', NULL, NULL, '2025-11-04 11:48:02'),
(21, 114, 749, 25, 5, '8000.00', 'Arnaque suspectée', 0, 'demande', NULL, NULL, '2025-12-21 01:59:46'),
(22, 116, 748, 25, 5, '5000.00', 'Escroquerie', 0, 'demande', NULL, NULL, '2025-12-21 04:26:13'),
(23, 118, 741, 25, 2, '500.00', 'Erreur de paiement', 0, 'demande', NULL, NULL, '2025-12-21 15:57:50'),
(24, 120, 749, 22, 5, '8000.00', 'Erreur de paiement', 0, 'demande', NULL, NULL, '2025-12-21 17:11:34'),
(25, 122, 748, 22, 5, '5000.00', 'Erreur de paiement', 0, 'demande', NULL, NULL, '2025-12-23 07:28:09'),
(26, 125, 741, 22, 2, '500.00', 'Arnaque suspectée', 0, 'demande', NULL, NULL, '2025-12-25 12:14:29'),
(27, 127, 752, 20, 2, '200.00', 'Arnaque suspectée', 0, 'demande', NULL, NULL, '2025-12-25 13:25:35'),
(28, 129, 752, 26, 2, '200.00', 'Arnaque suspectée', 0, 'demande', NULL, NULL, '2026-01-07 05:23:55'),
(29, 131, 752, 4, 2, '200.00', 'Arnaque suspectée', 0, 'demande', NULL, NULL, '2026-01-20 14:47:05');

SELECT setval('remboursement_id_seq', COALESCE((SELECT MAX(id) FROM remboursement), 0) + 1);
select *  from utilisateur;
-- Insertion des données dans la table utilisateur
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

SELECT setval('utilisateur_id_seq', COALESCE((SELECT MAX(id) FROM utilisateur), 0) + 1);

-- Insertion des données dans la table utilisateurtyping
INSERT INTO utilisateurtyping (produitId, utilisateurId, typing, dateUpdate) VALUES
(694, 5, FALSE, '2025-07-05 16:02:18'),
(702, 2, FALSE, '2025-07-05 16:18:40'),
(702, 5, FALSE, '2025-07-05 16:19:12');

-- Insertion des données dans la table vente
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
(113, '2025-12-21 00:50:39', 25, '20000.00', 'Mobile Money - PayUnit', '+238621552208', 'Cap-Vert', 'PAYUNIT_1766274638_6152', '', NULL, NULL, NULL, NULL),
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
(124, '2025-12-25 12:12:59', 22, '500.00', 'Mobile Money - PayUnit', '+237671819063', 'Cameroun', 'PAYUNIT_1766661178_8480', '', NULL, NULL, NULL, NULL),
(125, '2025-12-25 12:13:42', 22, '500.00', 'Mobile Money - PayUnit', '+237671819063', 'Cameroun', 'PAYUNIT_1766661221_1653', 'annule', NULL, 'Arnaque suspectée', '2025-12-25 12:14:29', NULL),
(126, '2025-12-25 13:01:29', 20, '500.00', 'Mobile Money - PayUnit', '+260682540026', 'Autre', 'PAYUNIT_1766664088_9927', '', NULL, NULL, NULL, NULL),
(127, '2025-12-25 13:24:55', 20, '200.00', 'Mobile Money - PayUnit', '+260682540026', 'Autre', 'PAYUNIT_1766665494_5766', 'annule', NULL, 'Arnaque suspectée', '2025-12-25 13:25:35', NULL),
(128, '2025-12-25 15:07:43', 20, '200.00', 'Mobile Money - PayUnit', '+260682540026', 'Autre', 'PAYUNIT_1766671662_9927', 'paye', '2025-12-25 15:08:08', NULL, NULL, '2025-12-25 15:08:08'),
(129, '2026-01-07 05:23:01', 26, '200.00', 'Mobile Money - PayUnit', '+257632552822', 'Burundi', 'PAYUNIT_1767759780_1809', 'annule', NULL, 'Arnaque suspectée', '2026-01-07 05:23:54', NULL),
(130, '2026-01-07 07:22:13', 26, '200.00', 'Mobile Money - PayUnit', '+257632552822', 'Burundi', 'PAYUNIT_1767766932_6909', 'paye', '2026-01-07 07:22:43', NULL, NULL, '2026-01-07 07:22:43'),
(131, '2026-01-20 14:46:02', 4, '200.00', 'Mobile Money - PayUnit', '687216822', 'Cap-Vert', 'PAYUNIT_1768916761_6623', 'annule', NULL, 'Arnaque suspectée', '2026-01-20 14:47:05', NULL);

SELECT setval('vente_id_seq', COALESCE((SELECT MAX(id) FROM vente), 0) + 1);

-- Insertion des données dans la table venteproduit
INSERT INTO venteproduit (id, venteId, produitId, quantite, prixUnitaire, achetee, vendeurId) VALUES
(1, 1, 695, 1, '7000.00', FALSE, 0),
(2, 2, 695, 1, '7000.00', FALSE, 0),
(3, 2, 694, 1, '10000.00', FALSE, 0),
(4, 2, 693, 1, '20000.00', FALSE, 0),
(5, 2, 691, 1, '28000.00', FALSE, 0),
(6, 2, 690, 1, '20000.00', FALSE, 0),
(7, 3, 695, 1, '7000.00', FALSE, 0),
(8, 4, 695, 1, '7000.00', FALSE, 0),
(9, 5, 695, 1, '7000.00', FALSE, 0),
(15, 11, 699, 1, '12000.00', FALSE, 0),
(19, 15, 700, 1, '12000.00', TRUE, 0),
(20, 16, 700, 1, '12000.00', TRUE, 0),
(21, 17, 693, 1, '20000.00', TRUE, 0),
(22, 18, 695, 1, '7000.00', TRUE, 0),
(23, 18, 694, 1, '10000.00', TRUE, 0),
(24, 19, 694, 1, '10000.00', TRUE, 0),
(25, 20, 694, 1, '10000.00', TRUE, 0),
(26, 21, 694, 1, '10000.00', TRUE, 0),
(27, 22, 694, 1, '10000.00', TRUE, 0),
(28, 23, 694, 1, '10000.00', TRUE, 0),
(29, 24, 694, 1, '10000.00', TRUE, 0),
(30, 25, 694, 1, '10000.00', TRUE, 0),
(31, 26, 694, 1, '10000.00', TRUE, 0),
(32, 27, 700, 1, '12000.00', TRUE, 0),
(33, 28, 700, 1, '12000.00', TRUE, 0),
(34, 28, 695, 1, '7000.00', TRUE, 0),
(35, 29, 700, 1, '12000.00', TRUE, 0),
(36, 30, 700, 1, '12000.00', TRUE, 0),
(37, 31, 700, 1, '12000.00', TRUE, 0),
(38, 32, 700, 1, '12000.00', TRUE, 0),
(39, 33, 700, 1, '12000.00', TRUE, 0),
(40, 34, 700, 1, '12000.00', TRUE, 0),
(41, 35, 700, 1, '12000.00', TRUE, 0),
(42, 36, 700, 1, '12000.00', TRUE, 0),
(43, 37, 700, 1, '12000.00', TRUE, 0),
(44, 38, 695, 1, '7000.00', TRUE, 0),
(45, 39, 700, 1, '12000.00', TRUE, 0),
(46, 40, 700, 1, '12000.00', TRUE, 0),
(47, 40, 695, 1, '7000.00', TRUE, 0),
(48, 41, 700, 1, '12000.00', TRUE, 0),
(49, 41, 695, 1, '7000.00', TRUE, 0),
(50, 42, 691, 1, '28000.00', TRUE, 0),
(51, 43, 700, 1, '12000.00', TRUE, 0),
(52, 43, 693, 1, '20000.00', TRUE, 0),
(53, 44, 700, 1, '12000.00', TRUE, 0),
(54, 45, 700, 1, '12000.00', TRUE, 0),
(55, 46, 700, 1, '12000.00', TRUE, 0),
(56, 47, 700, 1, '12000.00', TRUE, 0),
(57, 48, 700, 1, '12000.00', TRUE, 0),
(58, 48, 695, 1, '7000.00', TRUE, 0),
(59, 49, 700, 1, '12000.00', TRUE, 0),
(60, 49, 695, 1, '7000.00', TRUE, 0),
(61, 49, 690, 1, '20000.00', TRUE, 0),
(62, 50, 695, 1, '7000.00', TRUE, 0),
(63, 51, 693, 1, '20000.00', TRUE, 0),
(64, 52, 693, 1, '20000.00', TRUE, 0),
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

SELECT setval('venteproduit_id_seq', COALESCE((SELECT MAX(id) FROM venteproduit), 0) + 1);

-- Insertion des données dans la table video
INSERT INTO video (id, produitId, titre, url, preview_url, preview_duration, description, ordre, dateCreation, duree) VALUES
(18, 732, 'Yoga', 'video/video_68a340e2e3b669.36224417', NULL, 30, NULL, 1, '2025-08-18 16:04:04', NULL),
(22, 713, 'Yuyu', 'video/video_68a4a5fa9c7f67.32343276', NULL, 30, NULL, 2, '2025-08-19 17:27:38', NULL),
(24, 713, 'Pilé', 'video/video_68a4ac2d673c16.07014888', NULL, 30, NULL, 3, '2025-08-19 17:54:05', NULL),
(28, 741, 'Tutoriels', 'video/video_1760723056_d71e879df0910697.mp4', 'video/preview/preview_1760723056_11383b4954f31c7f.mp4', 30, NULL, 1, '2025-10-17 18:44:16', NULL),
(32, 748, 'Introduction', 'video/video_1760962327_bd573c82172c6edc.mp4', 'video/preview/preview_1760962327_2177e2f1be842992.mp4', 30, '', 1, '2025-10-20 13:12:07', NULL),
(33, 748, 'Ingrédients', 'video/video_1760962342_32b81cad79a9565a.mp4', 'video/preview/preview_1760962342_72d711cf3e247c11.mp4', 30, '', 2, '2025-10-20 13:12:22', NULL),
(35, 749, 'Tzsdg', 'video/video_1760998944_f82c6983454f3fe3.mp4', 'video/preview/preview_1760998944_b569403ea1223285.mp4', 30, '', 1, '2025-10-20 23:22:24', NULL),
(37, 751, 'Toto', 'video/video_1761002218_17e6be986f2ec57a.mp4', 'video/preview/preview_1761002218_d9d78f3acce48ff5.mp4', 30, '', 1, '2025-10-21 00:16:58', NULL),
(38, 752, 'Introduction', 'video/video_1762433356_0e79c8629a5850b5.mp4', 'video/preview/preview_1762433356_a21617c86c77c1c0.mp4', 30, '', 1, '2025-11-06 13:49:16', NULL),
(39, 752, 'Hira', 'video/video_1769837101_5224a2e6cb303131.mp4', 'video/preview/preview_1769837101_186b66ac16e026a5.mp4', 30, '', 1, '2026-01-31 06:25:02', NULL),
(40, 752, 'R', 'video/video_1770159461_49f4c60c977a4ea4.mp4', 'video/preview/preview_1770159461_47f52f5c6dc7d823.mp4', 30, '', 1, '2026-02-03 23:57:42', NULL),
(41, 752, 'Bois', 'video/video_1770159668_ef9880135630f339.mp4', NULL, 30, '', 1, '2026-02-04 00:01:09', NULL);

SELECT setval('video_id_seq', COALESCE((SELECT MAX(id) FROM video), 0) + 1);

-- Création des index
CREATE INDEX idx_commentaire_produit ON commentaire (produitId);
CREATE INDEX idx_commentaire_utilisateur ON commentaire (utilisateurId);
CREATE INDEX idx_commentaire_vus_utilisateur ON commentaire_vus (utilisateur_id);
CREATE INDEX idx_commission_vendeur ON commission (vendeurId);
CREATE INDEX idx_commission_vente ON commission (venteId);
CREATE INDEX idx_commission_statut ON commission (statut);
CREATE INDEX idx_demanderetrait_utilisateur ON demanderetrait (utilisateurId);
CREATE INDEX idx_demanderetrait_statut ON demanderetrait (statut);
CREATE INDEX idx_evenement_date ON evenement (date_evenement);
CREATE INDEX idx_evenement_type ON evenement (type);
CREATE INDEX idx_evenement_actif ON evenement (actif);
CREATE INDEX idx_follow_following ON follow (followingId);
CREATE INDEX idx_notification_utilisateur ON notification (utilisateurId);
CREATE INDEX idx_notification_estLu ON notification (estLu);
CREATE INDEX idx_notification_date ON notification (dateCreation);
CREATE INDEX idx_notificationevenement_utilisateur_date ON notificationevenement (utilisateurId, dateEnvoi);
CREATE INDEX idx_paiementvendeur_vendeur ON paiementvendeur (vendeurId);
CREATE INDEX idx_paiementvendeur_vente ON paiementvendeur (venteId);
CREATE INDEX idx_produit_vendeur ON produit (vendeurId);
CREATE INDEX idx_push_tokens_user ON push_tokens (userId);
CREATE INDEX idx_rappelanniversaire_utilisateur ON rappelanniversaire (utilisateurId);
CREATE INDEX idx_rappelanniversaire_prochain ON rappelanniversaire (prochainAnniversaire);
CREATE INDEX idx_rappelanniversaire_envoye ON rappelanniversaire (rappelEnvoye);
CREATE INDEX idx_reactionutilisateur_produit ON reactionutilisateur (produitId);
CREATE INDEX idx_remboursement_vente ON remboursement (venteId);
CREATE INDEX idx_remboursement_produit ON remboursement (produitId);
CREATE INDEX idx_remboursement_acheteur ON remboursement (acheteurId);
CREATE INDEX idx_remboursement_vendeur ON remboursement (vendeurId);
CREATE INDEX idx_transactionpaiement_vente ON transactionpaiement (venteId);
CREATE INDEX idx_transactionpaiement_transaction ON transactionpaiement (transactionId);
CREATE INDEX idx_vente_utilisateur ON vente (utilisateurId);
CREATE INDEX idx_vente_transaction ON vente (transactionId);
CREATE INDEX idx_venteproduit_vente ON venteproduit (venteId);
CREATE INDEX idx_venteproduit_produit ON venteproduit (produitId);
CREATE INDEX idx_video_produit ON video (produitId);

-- Création des contraintes de clés étrangères
ALTER TABLE commentaire_vus
  ADD CONSTRAINT commentaire_vus_commentaire_fk FOREIGN KEY (commentaire_id) REFERENCES commentaire (id) ON DELETE CASCADE,
  ADD CONSTRAINT commentaire_vus_utilisateur_fk FOREIGN KEY (utilisateur_id) REFERENCES utilisateur (id) ON DELETE CASCADE;

ALTER TABLE commission
  ADD CONSTRAINT commission_vente_fk FOREIGN KEY (venteId) REFERENCES vente (id) ON DELETE CASCADE,
  ADD CONSTRAINT commission_vendeur_fk FOREIGN KEY (vendeurId) REFERENCES utilisateur (id) ON DELETE CASCADE;

ALTER TABLE demanderetrait
  ADD CONSTRAINT demanderetrait_utilisateur_fk FOREIGN KEY (utilisateurId) REFERENCES utilisateur (id) ON DELETE CASCADE;

ALTER TABLE follow
  ADD CONSTRAINT follow_follower_fk FOREIGN KEY (followerId) REFERENCES utilisateur (id) ON DELETE CASCADE,
  ADD CONSTRAINT follow_following_fk FOREIGN KEY (followingId) REFERENCES utilisateur (id) ON DELETE CASCADE;

ALTER TABLE notification
  ADD CONSTRAINT notification_utilisateur_fk FOREIGN KEY (utilisateurId) REFERENCES utilisateur (id);

ALTER TABLE notificationevenement
  ADD CONSTRAINT notificationevenement_evenement_fk FOREIGN KEY (evenementId) REFERENCES evenement (id) ON DELETE CASCADE,
  ADD CONSTRAINT notificationevenement_utilisateur_fk FOREIGN KEY (utilisateurId) REFERENCES utilisateur (id) ON DELETE CASCADE;

ALTER TABLE paiementvendeur
  ADD CONSTRAINT paiementvendeur_vendeur_fk FOREIGN KEY (vendeurId) REFERENCES utilisateur (id),
  ADD CONSTRAINT paiementvendeur_vente_fk FOREIGN KEY (venteId) REFERENCES vente (id);

ALTER TABLE produit
  ADD CONSTRAINT produit_vendeur_fk FOREIGN KEY (vendeurId) REFERENCES utilisateur (id);

ALTER TABLE push_tokens
  ADD CONSTRAINT push_tokens_user_fk FOREIGN KEY (userId) REFERENCES utilisateur (id) ON DELETE CASCADE;

ALTER TABLE rappelanniversaire
  ADD CONSTRAINT rappelanniversaire_utilisateur_fk FOREIGN KEY (utilisateurId) REFERENCES utilisateur (id) ON DELETE CASCADE;

ALTER TABLE reactionutilisateur
  ADD CONSTRAINT reactionutilisateur_utilisateur_fk FOREIGN KEY (utilisateurId) REFERENCES utilisateur (id),
  ADD CONSTRAINT reactionutilisateur_produit_fk FOREIGN KEY (produitId) REFERENCES produit (id);

ALTER TABLE remboursement
  ADD CONSTRAINT remboursement_vente_fk FOREIGN KEY (venteId) REFERENCES vente (id),
  ADD CONSTRAINT remboursement_produit_fk FOREIGN KEY (produitId) REFERENCES produit (id),
  ADD CONSTRAINT remboursement_acheteur_fk FOREIGN KEY (acheteurId) REFERENCES utilisateur (id),
  ADD CONSTRAINT remboursement_vendeur_fk FOREIGN KEY (vendeurId) REFERENCES utilisateur (id);

ALTER TABLE transactionpaiement
  ADD CONSTRAINT transactionpaiement_vente_fk FOREIGN KEY (venteId) REFERENCES vente (id) ON DELETE CASCADE;

ALTER TABLE vente
  ADD CONSTRAINT vente_utilisateur_fk FOREIGN KEY (utilisateurId) REFERENCES utilisateur (id);

ALTER TABLE venteproduit
  ADD CONSTRAINT venteproduit_vente_fk FOREIGN KEY (venteId) REFERENCES vente (id),
  ADD CONSTRAINT venteproduit_produit_fk FOREIGN KEY (produitId) REFERENCES produit (id);

ALTER TABLE video
  ADD CONSTRAINT video_produit_fk FOREIGN KEY (produitId) REFERENCES produit (id);

-- Création des triggers pour la mise à jour automatique des timestamps
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