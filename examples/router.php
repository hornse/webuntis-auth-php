<?php
/**
 * WebUntis Auth – Vollständiges Verwendungsbeispiel
 * ===================================================
 * Datei: examples/router.php
 *
 * Zeigt die komplette Integration in einen PHP built-in Server Router.
 * Kann 1:1 als Basis für neue Projekte verwendet werden.
 */

// ── SCHRITT 1: Session vorbereiten (MUSS ganz oben stehen!) ────────────────
// Vor jedem require/include – sonst schlägt Session-Wiederherstellung fehl.
require_once __DIR__ . '/../src/WebUntisSession.php';
WebUntisSession::prepare('mein_projekt_session'); // Cookie-Name anpassen

// ── SCHRITT 2: Konfiguration und Auth laden ─────────────────────────────────
$config = require __DIR__ . '/../config.php';
require_once __DIR__ . '/../src/WebUntisAuth.php';

// ── SCHRITT 3: Routing ──────────────────────────────────────────────────────
$uri    = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'];

// Session für jeden Request starten
WebUntisSession::start($config['session']);

// API-Endpunkte
if (str_starts_with($uri, '/api/')) {
    header('Content-Type: application/json; charset=utf-8');
    header('Access-Control-Allow-Origin: https://meinprojekt.example.com');
    header('Access-Control-Allow-Credentials: true');

    $route = trim(substr($uri, 5), '/');

    // ── POST /api/login ──────────────────────────────────────────────────────
    if ($method === 'POST' && $route === 'login') {
        $body     = json_decode(file_get_contents('php://input'), true) ?? [];
        $username = trim($body['username'] ?? $body['email'] ?? '');
        $pass     = $body['password']      ?? $body['passwort'] ?? '';
        $ip       = $_SERVER['REMOTE_ADDR'] ?? 'unbekannt';

        if (!$username || !$pass) {
            http_response_code(400);
            echo json_encode(['error' => 'Zugangsdaten erforderlich.']);
            exit;
        }

        // ── Lokaler Login (E-Mail + Passwort) ────────────────────────────────
        // Nur wenn E-Mail-Format erkannt wird
        if (filter_var($username, FILTER_VALIDATE_EMAIL)) {
            // Datenbankverbindung herstellen (projektspezifisch)
            $pdo = new PDO('mysql:host=127.0.0.1;dbname=meine_datenbank;charset=utf8mb4',
                           $config['db']['user'], $config['db']['pass'],
                           [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

            $stmt = $pdo->prepare('SELECT * FROM benutzer WHERE email = ? AND aktiv = 1');
            $stmt->execute([$username]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($user && password_verify($pass, $user['passwort_hash'])) {
                WebUntisSession::loginLokal($user);
                echo json_encode(['ok' => true, 'rolle' => $user['rolle'], 'typ' => 'lokal']);
                exit;
            }
        }

        // ── WebUntis-Login ────────────────────────────────────────────────────
        $pdo = $pdo ?? new PDO('mysql:host=127.0.0.1;dbname=meine_datenbank;charset=utf8mb4',
                               $config['db']['user'], $config['db']['pass'],
                               [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $wu = new WebUntisAuth($pdo, $config['webuntis']);

        $result = $wu->authenticate($username, $pass, $ip);

        if ($result === null) {
            http_response_code(401);
            echo json_encode(['error' => 'Ungültige Anmeldedaten.']);
            exit;
        }

        $personType   = $result['personType'];
        $adminKuerzel = $config['webuntis']['admin_kuerzel'] ?? [];

        // ── Lehrer oder WebUntis-Admin ────────────────────────────────────────
        if ($personType === WebUntisAuth::TYPE_LEHRER || $personType === WebUntisAuth::TYPE_ADMIN) {
            $kuerzel = $result['kuerzel'] ?? '';

            // Rolle bestimmen
            if ($personType === WebUntisAuth::TYPE_ADMIN ||
                in_array($kuerzel, $adminKuerzel, true)) {
                $rolle = 'admin';
            } else {
                $rolle = 'lernbegleiter';
            }

            // Optional: Lokalen DB-Eintrag per Kürzel suchen
            $dbId = 0;
            if ($kuerzel) {
                $stmt = $pdo->prepare('SELECT id, rolle FROM benutzer WHERE kuerzel = ? AND aktiv = 1');
                $stmt->execute([$kuerzel]);
                $dbUser = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($dbUser) {
                    $dbId  = (int)$dbUser['id'];
                    $rolle = $dbUser['rolle']; // DB-Rolle hat Vorrang
                }
            }

            WebUntisSession::loginLehrer($result, $rolle, $dbId, schuleId: 1);
            echo json_encode([
                'ok'       => true,
                'vorname'  => $result['vorname']  ?? '',
                'nachname' => $result['nachname'] ?? '',
                'rolle'    => $rolle,
                'typ'      => 'webuntis',
            ]);
            exit;
        }

        // ── Schüler ───────────────────────────────────────────────────────────
        if ($personType === WebUntisAuth::TYPE_SCHUELER) {
            $schildId = (int)($result['key'] ?? 0);

            if (!$schildId) {
                http_response_code(403);
                echo json_encode(['error' => 'Schüler-ID nicht übertragbar.']);
                exit;
            }

            // Schüler per Schild-ID in DB suchen
            // key aus WebUntis = schild_id aus Schild-NRW-Export
            $stmt = $pdo->prepare('SELECT * FROM schueler WHERE schild_id = ? AND aktiv = 1');
            $stmt->execute([$schildId]);
            $schueler = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$schueler) {
                http_response_code(403);
                echo json_encode(['error' => 'Schüler nicht gefunden. CSV-Import nötig.']);
                exit;
            }

            WebUntisSession::loginSchueler($schueler, schuleId: 1);
            echo json_encode([
                'ok'       => true,
                'vorname'  => $schueler['vorname'],
                'nachname' => $schueler['nachname'],
                'rolle'    => 'schueler',
                'typ'      => 'webuntis',
            ]);
            exit;
        }
    }

    // ── GET /api/me ──────────────────────────────────────────────────────────
    if ($method === 'GET' && $route === 'me') {
        $user = WebUntisSession::requireAuth();
        echo json_encode([
            'id'       => $user['id'],
            'vorname'  => WebUntisSession::displayName() ?: 'Unbekannt',
            'rolle'    => $user['rolle'],
            'typ'      => $user['typ'],
        ]);
        exit;
    }

    // ── POST /api/logout ─────────────────────────────────────────────────────
    if ($method === 'POST' && $route === 'logout') {
        WebUntisSession::logout();
        echo json_encode(['ok' => true]);
        exit;
    }

    // ── Geschützte Endpunkte (Beispiel) ──────────────────────────────────────
    if ($route === 'daten') {
        $user = WebUntisSession::requireAuth();
        echo json_encode(['message' => 'Hallo ' . $user['rolle'], 'user_id' => $user['id']]);
        exit;
    }

    // ── Admin-only Endpunkt (Beispiel) ───────────────────────────────────────
    if ($route === 'admin') {
        $user = WebUntisSession::requireAdmin();
        echo json_encode(['message' => 'Admin-Bereich', 'user_id' => $user['id']]);
        exit;
    }

    http_response_code(404);
    echo json_encode(['error' => 'Endpunkt nicht gefunden.']);
    exit;
}

// Statische Dateien
if ($uri !== '/' && file_exists(__DIR__ . '/public' . $uri)) {
    return false;
}

readfile(__DIR__ . '/public/index.html');
