<?php
declare(strict_types=1);
/**
 * MATE – Claude-Connector (MCP): gemeinsamer Kern
 * Einstellungen, Freischaltung, Token, Protokoll, JSON-Antworten.
 * Alles, was den bestehenden MATE-Server betrifft (Datenbank, Anmeldung, Daten), steht in anbindung.php.
 */

require_once defined('MCP_ANBINDUNG') ? MCP_ANBINDUNG : __DIR__ . '/anbindung.php';   // Tests setzen eine eigene Anbindung

const MCP_VERSION       = '0.1.0';
const MCP_PROTOKOLLE    = ['2025-11-25', '2025-06-18', '2025-03-26'];
const MCP_ACCESS_SEK    = 3600;            // Zugangs-Token: 1 Stunde
const MCP_REFRESH_SEK   = 30 * 86400;      // Erneuerung: 30 Tage, verlängert sich bei Nutzung
const MCP_CODE_SEK      = 600;             // Einmal-Code aus der Anmeldung: 10 Minuten
const MCP_PROTOKOLL_TAGE = 90;
const MCP_LIMIT_PRO_10MIN = 300;           // Werkzeug-Aufrufe pro LK in 10 Minuten

/** Erlaubte Rücksprung-Adressen (nur Claude). */
function mcp_redirect_erlaubt(string $uri): bool
{
    $erlaubt = mcp_cfg('mcp_redirects', [
        'https://claude.ai/api/mcp/auth_callback',
        'https://claude.com/api/mcp/auth_callback',
    ]);
    return in_array($uri, $erlaubt, true);
}

function mcp_basis(): string
{
    $b = (string)(mcp_cfg('mcp_basis', '') ?: mcp_cfg('app_url', ''));
    if ($b !== '') return rtrim($b, '/');
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    return ($https ? 'https://' : 'http://') . $host;
}

function mcp_jetzt(): int { return (int)round(microtime(true) * 1000); }   // ms wie in der App
function mcp_zufall(int $bytes = 32): string { return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '='); }
function mcp_hash(string $s): string { return hash('sha256', $s); }

function mcp_json(int $code, $daten, array $kopf = []): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    foreach ($kopf as $k => $v) header("$k: $v");
    echo json_encode($daten, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function mcp_body(): array
{
    $roh = file_get_contents('php://input') ?: '';
    $ct = $_SERVER['CONTENT_TYPE'] ?? '';
    if (stripos($ct, 'application/x-www-form-urlencoded') !== false) { parse_str($roh, $d); return $d; }
    $d = json_decode($roh, true);
    return is_array($d) ? $d : [];
}

/* ---------- Einstellungen des Admins ---------- */

function mcp_einstellungen(): array
{
    $st = mcp_pdo()->query('SELECT daten FROM mcp_einstellungen WHERE id = 1');
    $roh = $st ? $st->fetchColumn() : false;
    $d = $roh ? json_decode((string)$roh, true) : null;
    if (!is_array($d)) $d = [];
    $w = [];
    foreach (mcp_werkzeug_namen() as $n) $w[$n] = !empty($d['werkzeuge'][$n]);
    return [
        'aktiv'     => !empty($d['aktiv']),
        'werkzeuge' => $w,
        'lk'        => array_values(array_map('strval', $d['lk'] ?? [])),
    ];
}

function mcp_einstellungen_speichern(array $neu): array
{
    $alt = mcp_einstellungen();
    $d = [
        'aktiv'     => array_key_exists('aktiv', $neu) ? (bool)$neu['aktiv'] : $alt['aktiv'],
        'werkzeuge' => $alt['werkzeuge'],
        'lk'        => $alt['lk'],
    ];
    if (isset($neu['werkzeuge']) && is_array($neu['werkzeuge']))
        foreach (mcp_werkzeug_namen() as $n) if (array_key_exists($n, $neu['werkzeuge'])) $d['werkzeuge'][$n] = (bool)$neu['werkzeuge'][$n];
    if (isset($neu['lk']) && is_array($neu['lk'])) {
        $ids = [];
        foreach ($neu['lk'] as $id) { $b = mcp_benutzer((string)$id); if ($b && $b['rolle'] === 'l') $ids[] = (string)$id; }
        $d['lk'] = array_values(array_unique($ids));
    }
    $pdo = mcp_pdo();
    $pdo->prepare('DELETE FROM mcp_einstellungen WHERE id = 1')->execute();
    $pdo->prepare('INSERT INTO mcp_einstellungen (id, daten, geaendert) VALUES (1, ?, ?)')
        ->execute([json_encode($d, JSON_UNESCAPED_UNICODE), mcp_jetzt()]);
    // Wer nicht mehr freigeschaltet ist, verliert seine Verbindungen sofort
    foreach (array_diff($alt['lk'], $d['lk']) as $weg) mcp_verbindungen_trennen((string)$weg);
    if (!$d['aktiv']) mcp_pdo()->exec('DELETE FROM mcp_tokens');
    return mcp_einstellungen();
}

/** Darf diese Person den Connector gerade benutzen? Liefert den Grund, wenn nicht. */
function mcp_zugang_grund(?array $b): ?string
{
    $e = mcp_einstellungen();
    if (!$e['aktiv']) return 'Der Claude-Connector ist in MATE ausgeschaltet.';
    if (!$b || empty($b['aktiv'])) return 'Dieses Konto ist nicht aktiv.';
    if ($b['rolle'] !== 'l') return 'Der Claude-Connector ist nur für Lehrkräfte.';
    if (!in_array((string)$b['id'], $e['lk'], true)) return 'Dein Konto ist für den Claude-Connector nicht freigeschaltet. Frag den Admin.';
    return null;
}

/* ---------- Token ---------- */

function mcp_token_paar(string $client, string $benutzer, ?string $verbindung = null): array
{
    $verbindung = $verbindung ?? bin2hex(random_bytes(12));
    $acc = 'mate_at_' . mcp_zufall();
    $ref = 'mate_rt_' . mcp_zufall();
    $t = mcp_jetzt();
    $ins = mcp_pdo()->prepare('INSERT INTO mcp_tokens (token_hash, art, verbindung, client_id, benutzer_id, erstellt, ablauf, zuletzt) VALUES (?,?,?,?,?,?,?,NULL)');
    $ins->execute([mcp_hash($acc), 'access', $verbindung, $client, $benutzer, $t, $t + MCP_ACCESS_SEK * 1000]);
    $ins->execute([mcp_hash($ref), 'refresh', $verbindung, $client, $benutzer, $t, $t + MCP_REFRESH_SEK * 1000]);
    return ['access_token' => $acc, 'token_type' => 'Bearer', 'expires_in' => MCP_ACCESS_SEK, 'refresh_token' => $ref, 'scope' => 'mate'];
}

function mcp_token_finden(string $token, string $art): ?array
{
    $st = mcp_pdo()->prepare('SELECT * FROM mcp_tokens WHERE token_hash = ? AND art = ?');
    $st->execute([mcp_hash($token), $art]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r || (int)$r['ablauf'] < mcp_jetzt()) return null;
    return $r;
}

function mcp_verbindungen_trennen(string $benutzer, ?string $verbindung = null): void
{
    if ($verbindung) mcp_pdo()->prepare('DELETE FROM mcp_tokens WHERE benutzer_id = ? AND verbindung = ?')->execute([$benutzer, $verbindung]);
    else mcp_pdo()->prepare('DELETE FROM mcp_tokens WHERE benutzer_id = ?')->execute([$benutzer]);
}

/** Aktive Verbindungen (für die Admin-Übersicht): pro Verbindung eine Zeile. */
function mcp_verbindungen(): array
{
    $st = mcp_pdo()->prepare("SELECT verbindung, benutzer_id, client_id, MIN(erstellt) AS seit, MAX(COALESCE(zuletzt, 0)) AS zuletzt FROM mcp_tokens WHERE ablauf > ? GROUP BY verbindung, benutzer_id, client_id ORDER BY seit");
    $st->execute([mcp_jetzt()]);
    $aus = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $b = mcp_benutzer((string)$r['benutzer_id']);
        $aus[] = ['verbindung' => $r['verbindung'], 'lk' => (string)$r['benutzer_id'], 'name' => $b ? trim($b['vn'] . ' ' . $b['nn']) : '?',
                  'seit' => (int)$r['seit'], 'zuletzt' => (int)$r['zuletzt'] ?: null];
    }
    return $aus;
}

/** Aufräumen (aus dem täglichen Cronjob aufrufen). */
function mcp_aufraeumen(): void
{
    $t = mcp_jetzt();
    $pdo = mcp_pdo();
    $pdo->prepare('DELETE FROM mcp_tokens WHERE ablauf < ?')->execute([$t]);
    $pdo->prepare('DELETE FROM mcp_codes WHERE ablauf < ?')->execute([$t]);
    $pdo->prepare('DELETE FROM mcp_protokoll WHERE zeit < ?')->execute([$t - MCP_PROTOKOLL_TAGE * 86400000]);
    // Clients ohne Verbindung nach 90 Tagen löschen
    $pdo->prepare('DELETE FROM mcp_clients WHERE erstellt < ? AND client_id NOT IN (SELECT client_id FROM mcp_tokens)')->execute([$t - 90 * 86400000]);
}

/* ---------- Protokoll ---------- */

function mcp_protokollieren(string $benutzer, string $werkzeug, bool $ok, string $info = ''): void
{
    mcp_pdo()->prepare('INSERT INTO mcp_protokoll (zeit, benutzer_id, werkzeug, ok, info) VALUES (?,?,?,?,?)')
        ->execute([mcp_jetzt(), $benutzer, substr($werkzeug, 0, 60), $ok ? 1 : 0, mb_substr($info, 0, 200)]);
}

function mcp_protokoll(int $anzahl = 100): array
{
    $st = mcp_pdo()->prepare('SELECT zeit, benutzer_id, werkzeug, ok, info FROM mcp_protokoll ORDER BY zeit DESC LIMIT ' . max(1, min(500, $anzahl)));
    $st->execute();
    $namen = [];
    $aus = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $id = (string)$r['benutzer_id'];
        if (!isset($namen[$id])) { $b = mcp_benutzer($id); $namen[$id] = $b ? trim($b['vn'] . ' ' . $b['nn']) : '?'; }
        $aus[] = ['zeit' => (int)$r['zeit'], 'lk' => $id, 'name' => $namen[$id], 'werkzeug' => $r['werkzeug'], 'ok' => (bool)$r['ok'], 'info' => $r['info']];
    }
    return $aus;
}

function mcp_limit_ok(string $benutzer): bool
{
    $st = mcp_pdo()->prepare('SELECT COUNT(*) FROM mcp_protokoll WHERE benutzer_id = ? AND zeit > ?');
    $st->execute([$benutzer, mcp_jetzt() - 600000]);
    return (int)$st->fetchColumn() < MCP_LIMIT_PRO_10MIN;
}

function mcp_e(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
