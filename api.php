<?php
// ============================================================
//  TEOPICTURE — API PHP (api.php)
//  Placez ce fichier dans : C:/wamp64/www/teopicture/api.php
// ============================================================

// ── Configuration base de données ──────────────────────────
define('DB_HOST', 'localhost');
define('DB_NAME', 'teopicture');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_PORT', 3306);

// ── En-têtes CORS ──────────────────────────────────────────
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// ── Connexion PDO ────────────────────────────────────────────
function getDB(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        try {
            $dsn = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4';
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } catch (PDOException $e) {
            jsonError(500, 'Connexion base de données échouée : ' . $e->getMessage());
        }
    }
    return $pdo;
}

// ── Créer les tables si elles n'existent pas ─────────────────
function initializeDatabase(): void {
    $db = getDB();
    
    try {
        // Table réservations
        $db->exec("
            CREATE TABLE IF NOT EXISTS reservations (
                id INT AUTO_INCREMENT PRIMARY KEY,
                nom VARCHAR(255) NOT NULL,
                telephone VARCHAR(20) NOT NULL,
                evenement VARCHAR(100) NOT NULL,
                date_event DATE NOT NULL,
                heure TIME NOT NULL,
                lieu VARCHAR(255),
                message TEXT,
                statut ENUM('en attente', 'confirmé', 'annulé') DEFAULT 'en attente',
                cree_le TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY unique_slot (date_event, heure)
            ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci
        ");
        
        // Table contacts
        $db->exec("
            CREATE TABLE IF NOT EXISTS contacts (
                id INT AUTO_INCREMENT PRIMARY KEY,
                nom VARCHAR(255) NOT NULL,
                telephone VARCHAR(20),
                message TEXT NOT NULL,
                lu TINYINT(1) DEFAULT 0,
                cree_le TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci
        ");
        
    } catch (PDOException $e) {
        jsonError(500, 'Erreur initialisation BDD : ' . $e->getMessage());
    }
}

// ── Helpers ──────────────────────────────────────────────────
function jsonOk(array $data, int $code = 200): void {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

function jsonError(int $code, string $message): void {
    http_response_code($code);
    echo json_encode(['success' => false, 'message' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

function getBody(): array {
    $raw = file_get_contents('php://input');
    return json_decode($raw, true) ?? [];
}

// ── Routeur ──────────────────────────────────────────────────
$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

// Initialiser la BDD au premier appel
initializeDatabase();

// ── ROUTES ───────────────────────────────────────────────────

switch (true) {
    case ($method === 'POST' && $action === 'reservations'):
        creerReservation();
        break;

    case ($method === 'GET' && $action === 'reservations'):
        listerReservations();
        break;

    case ($method === 'PUT' && $action === 'statut'):
        changerStatut();
        break;

    case ($method === 'DELETE' && $action === 'reservations'):
        supprimerReservation();
        break;

    case ($method === 'POST' && $action === 'contacts'):
        creerContact();
        break;

    case ($method === 'GET' && $action === 'contacts'):
        listerContacts();
        break;

    case ($method === 'GET' && $action === 'stats'):
        getStats();
        break;

    default:
        jsonError(404, 'Route introuvable. Actions disponibles : reservations, contacts, statut, stats');
}

// ═══════════════════════════════════════════════════════════
//  FONCTIONS MÉTIER
// ═══════════════════════════════════════════════════════════

function creerReservation(): void {
    $db   = getDB();
    $body = getBody();

    $required = ['nom', 'telephone', 'evenement', 'date', 'heure'];
    foreach ($required as $field) {
        if (empty(trim($body[$field] ?? ''))) {
            jsonError(400, "Le champ « $field » est obligatoire.");
        }
    }

    $nom      = trim($body['nom']);
    $tel      = trim($body['telephone']);
    $event    = trim($body['evenement']);
    $date     = trim($body['date']);
    $heure    = trim($body['heure']);
    $lieu     = trim($body['lieu'] ?? 'Non précisé') ?: 'Non précisé';
    $message  = trim($body['message'] ?? '');

    // ✅ CORRECTION: Valider format date YYYY-MM-DD
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        jsonError(400, 'Format de date invalide (attendu : YYYY-MM-DD).');
    }

    // ✅ CORRECTION: Valider format heure HH:MM ou HH:MM:SS
    if (!preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $heure)) {
        jsonError(400, 'Format d\'heure invalide (attendu : HH:MM ou HH:MM:SS).');
    }

    if ($date < date('Y-m-d')) {
        jsonError(400, 'La date ne peut pas être dans le passé.');
    }

    // Vérifier créneau disponible
    $stmt = $db->prepare(
        "SELECT COUNT(*) FROM reservations WHERE date_event = ? AND heure = ? AND statut != 'annulé'"
    );
    $stmt->execute([$date, $heure]);
    if ($stmt->fetchColumn() > 0) {
        jsonError(409, 'Ce créneau est déjà réservé. Choisissez une autre date ou heure.');
    }

    try {
        $stmt = $db->prepare(
            "INSERT INTO reservations (nom, telephone, evenement, date_event, heure, lieu, message)
             VALUES (?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->execute([$nom, $tel, $event, $date, $heure, $lieu, $message ?: null]);

        jsonOk([
            'success' => true,
            'message' => 'Réservation enregistrée avec succès !',
            'id'      => (int) $db->lastInsertId(),
        ], 201);
    } catch (PDOException $e) {
        jsonError(400, 'Erreur lors de l\'enregistrement : ' . $e->getMessage());
    }
}

function listerReservations(): void {
    $db = getDB();

    try {
        $stmt = $db->query("SELECT * FROM reservations ORDER BY date_event ASC, heure ASC");
        $data = $stmt->fetchAll();

        jsonOk([
            'success' => true,
            'data'    => $data,
            'total'   => count($data),
        ]);
    } catch (PDOException $e) {
        jsonError(500, 'Erreur lecture données : ' . $e->getMessage());
    }
}

function changerStatut(): void {
    $db   = getDB();
    $body = getBody();

    $id     = (int) ($_GET['id'] ?? $body['id'] ?? 0);
    $statut = trim($body['statut'] ?? '');

    if (!$id) jsonError(400, 'Paramètre id manquant.');
    if (!in_array($statut, ['en attente', 'confirmé', 'annulé'], true)) {
        jsonError(400, 'Statut invalide. Valeurs acceptées : en attente, confirmé, annulé');
    }

    try {
        $stmt = $db->prepare("UPDATE reservations SET statut = ? WHERE id = ?");
        $stmt->execute([$statut, $id]);

        if ($stmt->rowCount() === 0) {
            jsonError(404, 'Réservation introuvable.');
        }

        jsonOk(['success' => true, 'message' => 'Statut mis à jour avec succès.']);
    } catch (PDOException $e) {
        jsonError(500, 'Erreur mise à jour : ' . $e->getMessage());
    }
}

function supprimerReservation(): void {
    $db = getDB();
    $id = (int) ($_GET['id'] ?? 0);

    if (!$id) jsonError(400, 'Paramètre id manquant.');

    try {
        $stmt = $db->prepare("DELETE FROM reservations WHERE id = ?");
        $stmt->execute([$id]);

        if ($stmt->rowCount() === 0) {
            jsonError(404, 'Réservation introuvable.');
        }

        jsonOk(['success' => true, 'message' => 'Réservation supprimée avec succès.']);
    } catch (PDOException $e) {
        jsonError(500, 'Erreur suppression : ' . $e->getMessage());
    }
}

function creerContact(): void {
    $db   = getDB();
    $body = getBody();

    $nom     = trim($body['nom'] ?? '');
    $tel     = trim($body['telephone'] ?? '');
    $message = trim($body['message'] ?? '');

    if (!$nom || !$message) {
        jsonError(400, 'Les champs nom et message sont obligatoires.');
    }

    try {
        $stmt = $db->prepare(
            "INSERT INTO contacts (nom, telephone, message) VALUES (?, ?, ?)"
        );
        $stmt->execute([$nom, $tel, $message]);

        jsonOk([
            'success' => true,
            'message' => 'Message envoyé avec succès !',
            'id'      => (int) $db->lastInsertId()
        ], 201);
    } catch (PDOException $e) {
        jsonError(500, 'Erreur création contact : ' . $e->getMessage());
    }
}

function listerContacts(): void {
    $db = getDB();

    try {
        $stmt = $db->query("SELECT * FROM contacts ORDER BY cree_le DESC");
        jsonOk(['success' => true, 'data' => $stmt->fetchAll()]);
    } catch (PDOException $e) {
        jsonError(500, 'Erreur lecture contacts : ' . $e->getMessage());
    }
}

function getStats(): void {
    $db = getDB();

    try {
        $stats = [];
        $stats['total']      = (int) $db->query("SELECT COUNT(*) FROM reservations")->fetchColumn();
        $stats['en_attente'] = (int) $db->query("SELECT COUNT(*) FROM reservations WHERE statut='en attente'")->fetchColumn();
        $stats['confirme']   = (int) $db->query("SELECT COUNT(*) FROM reservations WHERE statut='confirmé'")->fetchColumn();
        $stats['annule']     = (int) $db->query("SELECT COUNT(*) FROM reservations WHERE statut='annulé'")->fetchColumn();
        $stats['contacts']   = (int) $db->query("SELECT COUNT(*) FROM contacts")->fetchColumn();
        $stats['ce_mois']    = (int) $db->query(
            "SELECT COUNT(*) FROM reservations WHERE MONTH(date_event)=MONTH(NOW()) AND YEAR(date_event)=YEAR(NOW())"
        )->fetchColumn();

        jsonOk(['success' => true, 'stats' => $stats]);
    } catch (PDOException $e) {
        jsonError(500, 'Erreur stats : ' . $e->getMessage());
    }
}