<?php
// ============================================================
//  TEOPICTURE — API PHP (api.php)
//  Placez ce fichier dans : C:/wamp64/www/teopicture/api.php
// ============================================================

// ── Configuration base de données ──────────────────────────
define('DB_HOST', 'localhost');
define('DB_NAME', 'teopicture');
define('DB_USER', 'root');       // utilisateur WAMP par défaut
define('DB_PASS', '');           // mot de passe WAMP par défaut (vide)
define('DB_PORT', 3306);

// ── En-têtes CORS (permet au HTML d'appeler l'API) ──────────
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Répondre aux pre-flight CORS
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

// ── Routeur simple ────────────────────────────────────────────
$method = $_SERVER['REQUEST_METHOD'];
$path   = trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');

// Supprimer le préfixe si le fichier est dans un sous-dossier
// ex : teopicture/api.php → on normalise le chemin
$segments = explode('/', $path);
// On récupère la dernière partie significative
$resource = '';
foreach ($segments as $seg) {
    if ($seg !== '' && $seg !== 'api.php') $resource = $seg;
}

// Paramètre ?action= en GET
$action = $_GET['action'] ?? $resource;

// ── ROUTES ───────────────────────────────────────────────────

switch (true) {

    // ── POST /api.php?action=reservations  ──────────────────
    case ($method === 'POST' && $action === 'reservations'):
        creerReservation();
        break;

    // ── GET  /api.php?action=reservations  ──────────────────
    case ($method === 'GET' && $action === 'reservations'):
        listerReservations();
        break;

    // ── PUT  /api.php?action=statut&id=X  ───────────────────
    case ($method === 'PUT' && $action === 'statut'):
        changerStatut();
        break;

    // ── DELETE /api.php?action=reservations&id=X  ───────────
    case ($method === 'DELETE' && $action === 'reservations'):
        supprimerReservation();
        break;

    // ── POST /api.php?action=contacts  ──────────────────────
    case ($method === 'POST' && $action === 'contacts'):
        creerContact();
        break;

    // ── GET  /api.php?action=contacts  ──────────────────────
    case ($method === 'GET' && $action === 'contacts'):
        listerContacts();
        break;

    // ── GET  /api.php?action=stats  ─────────────────────────
    case ($method === 'GET' && $action === 'stats'):
        getStats();
        break;

    default:
        jsonError(404, 'Route introuvable. Actions disponibles : reservations, contacts, statut, stats');
}

// ═══════════════════════════════════════════════════════════
//  FONCTIONS MÉTIER
// ═══════════════════════════════════════════════════════════

// ── Créer une réservation ────────────────────────────────────
function creerReservation(): void {
    $db   = getDB();
    $body = getBody();

    // Validation
    $required = ['nom', 'telephone', 'evenement', 'date', 'heure'];
    foreach ($required as $field) {
        if (empty(trim($body[$field] ?? ''))) {
            jsonError(400, "Le champ « $field » est obligatoire.");
        }
    }

    $nom      = trim($body['nom']);
    $tel      = trim($body['telephone']);
    $event    = trim($body['evenement']);
    $date     = trim($body['date']);       // format YYYY-MM-DD
    $heure    = trim($body['heure']);      // format HH:MM
    $lieu     = trim($body['lieu'] ?? 'Non précisé') ?: 'Non précisé';
    $message  = trim($body['message'] ?? '');

    // Vérifier le format de la date
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        jsonError(400, 'Format de date invalide (attendu : YYYY-MM-DD).');
    }

    // Vérifier que la date n'est pas dans le passé
    if ($date < date('Y-m-d')) {
        jsonError(400, 'La date ne peut pas être dans le passé.');
    }

    // Vérifier si le créneau est déjà pris
    $stmt = $db->prepare(
        "SELECT COUNT(*) FROM reservations WHERE date_event = ? AND heure = ? AND statut != 'annulé'"
    );
    $stmt->execute([$date, $heure]);
    if ($stmt->fetchColumn() > 0) {
        jsonError(409, 'Ce créneau est déjà réservé. Choisissez une autre date ou heure.');
    }

    // Insérer
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
}

// ── Lister les réservations (pour admin) ─────────────────────
function listerReservations(): void {
    $db = getDB();

    $statut = $_GET['statut'] ?? null;
    $sql    = "SELECT * FROM reservations";
    $params = [];

    if ($statut) {
        $sql    .= " WHERE statut = ?";
        $params[] = $statut;
    }

    $sql .= " ORDER BY date_event ASC, heure ASC";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);

    jsonOk([
        'success' => true,
        'data'    => $stmt->fetchAll(),
        'total'   => $stmt->rowCount(),
    ]);
}

// ── Changer le statut d'une réservation ──────────────────────
function changerStatut(): void {
    $db   = getDB();
    $body = getBody();

    $id     = (int) ($_GET['id'] ?? $body['id'] ?? 0);
    $statut = trim($body['statut'] ?? '');

    if (!$id) jsonError(400, 'Paramètre id manquant.');
    if (!in_array($statut, ['en attente', 'confirmé', 'annulé'], true)) {
        jsonError(400, 'Statut invalide. Valeurs : en attente, confirmé, annulé');
    }

    $stmt = $db->prepare("UPDATE reservations SET statut = ? WHERE id = ?");
    $stmt->execute([$statut, $id]);

    if ($stmt->rowCount() === 0) jsonError(404, 'Réservation introuvable.');

    jsonOk(['success' => true, 'message' => 'Statut mis à jour.']);
}

// ── Supprimer une réservation ────────────────────────────────
function supprimerReservation(): void {
    $db = getDB();
    $id = (int) ($_GET['id'] ?? 0);

    if (!$id) jsonError(400, 'Paramètre id manquant.');

    $stmt = $db->prepare("DELETE FROM reservations WHERE id = ?");
    $stmt->execute([$id]);

    if ($stmt->rowCount() === 0) jsonError(404, 'Réservation introuvable.');

    jsonOk(['success' => true, 'message' => 'Réservation supprimée.']);
}

// ── Créer un message de contact ──────────────────────────────
function creerContact(): void {
    $db   = getDB();
    $body = getBody();

    $nom     = trim($body['nom'] ?? '');
    $tel     = trim($body['telephone'] ?? '');
    $message = trim($body['message'] ?? '');

    if (!$nom || !$message) {
        jsonError(400, 'Les champs nom et message sont obligatoires.');
    }

    $stmt = $db->prepare(
        "INSERT INTO contacts (nom, telephone, message) VALUES (?, ?, ?)"
    );
    $stmt->execute([$nom, $tel, $message]);

    jsonOk(['success' => true, 'message' => 'Message envoyé !', 'id' => (int) $db->lastInsertId()], 201);
}

// ── Lister les contacts ──────────────────────────────────────
function listerContacts(): void {
    $db   = getDB();
    $stmt = $db->query("SELECT * FROM contacts ORDER BY cree_le DESC");
    jsonOk(['success' => true, 'data' => $stmt->fetchAll()]);
}

// ── Statistiques pour le dashboard admin ─────────────────────
function getStats(): void {
    $db = getDB();

    $stats = [];

    // Total réservations
    $stats['total']         = (int) $db->query("SELECT COUNT(*) FROM reservations")->fetchColumn();
    $stats['en_attente']    = (int) $db->query("SELECT COUNT(*) FROM reservations WHERE statut='en attente'")->fetchColumn();
    $stats['confirme']      = (int) $db->query("SELECT COUNT(*) FROM reservations WHERE statut='confirmé'")->fetchColumn();
    $stats['annule']        = (int) $db->query("SELECT COUNT(*) FROM reservations WHERE statut='annulé'")->fetchColumn();
    $stats['contacts']      = (int) $db->query("SELECT COUNT(*) FROM contacts")->fetchColumn();
    $stats['non_lus']       = (int) $db->query("SELECT COUNT(*) FROM contacts WHERE lu=0")->fetchColumn();

    // Réservations du mois en cours
    $stats['ce_mois']       = (int) $db->query(
        "SELECT COUNT(*) FROM reservations WHERE MONTH(date_event)=MONTH(NOW()) AND YEAR(date_event)=YEAR(NOW())"
    )->fetchColumn();

    jsonOk(['success' => true, 'stats' => $stats]);
}
