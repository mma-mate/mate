<?php
declare(strict_types=1);
/**
 * MATE – Claude-Connector: Schnittstelle für den Admin-Bereich und das LK-Profil
 * GET  mcp-admin.php            → Einstellungen, Werkzeuge, LK, Verbindungen, Protokoll (Admin)
 *                                  bzw. nur der eigene Stand (LK)
 * POST mcp-admin.php            → {aktion: "speichern" | "trennen" | "meine_trennen"}  (Kopf X-MATE-MCP-CSRF)
 */

require_once __DIR__ . '/../src/mcp/werkzeuge.php';

header('X-Content-Type-Options: nosniff');
mcp_sitzung_starten();
$ich = mcp_sitzung_benutzer();
if (!$ich) { mcp_json(401, ['fehler' => 'Nicht angemeldet.']); exit; }
if (empty($_SESSION['mcp_csrf'])) $_SESSION['mcp_csrf'] = bin2hex(random_bytes(16));
$admin = !empty($ich['admin']);

function mcp_admin_stand(bool $admin, array $ich): array
{
    $e = mcp_einstellungen();
    $meine = array_values(array_filter(mcp_verbindungen(), fn($v) => $v['lk'] === (string)$ich['id']));
    $stand = ['csrf' => $_SESSION['mcp_csrf'], 'adresse' => mcp_basis() . '/mcp', 'aktiv' => $e['aktiv'],
              'ich' => ['freigeschaltet' => mcp_zugang_grund($ich) === null, 'verbindungen' => $meine]];
    if (!$admin) return $stand;
    $w = [];
    foreach (mcp_werkzeuge() as $n => $x) $w[] = ['name' => $n, 'titel' => $x['titel'], 'gruppe' => $x['gruppe'], 'beschreibung' => $x['beschreibung']];
    return $stand + ['einstellungen' => $e, 'gruppen' => MCP_GRUPPEN, 'werkzeuge' => $w,
                     'lehrkraefte' => mcp_lehrkraefte(), 'verbindungen' => mcp_verbindungen(), 'protokoll' => mcp_protokoll(100)];
}

$m = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($m === 'GET') { mcp_json(200, mcp_admin_stand($admin, $ich)); exit; }
if ($m !== 'POST') { mcp_json(405, ['fehler' => 'Nur GET und POST.']); exit; }
if (!hash_equals($_SESSION['mcp_csrf'], (string)($_SERVER['HTTP_X_MATE_MCP_CSRF'] ?? ''))) { mcp_json(403, ['fehler' => 'Sicherheitsprüfung fehlgeschlagen. Bitte die Seite neu laden.']); exit; }
$d = mcp_body();
$aktion = (string)($d['aktion'] ?? '');

if ($aktion === 'meine_trennen') {
    mcp_verbindungen_trennen((string)$ich['id'], isset($d['verbindung']) ? (string)$d['verbindung'] : null);
    mcp_json(200, mcp_admin_stand($admin, $ich));
    exit;
}
if (!$admin) { mcp_json(403, ['fehler' => 'Nur für Admins.']); exit; }
if ($aktion === 'speichern') {
    mcp_einstellungen_speichern(is_array($d['einstellungen'] ?? null) ? $d['einstellungen'] : []);
    mcp_json(200, mcp_admin_stand($admin, $ich));
    exit;
}
if ($aktion === 'trennen') {
    mcp_verbindungen_trennen((string)($d['lk'] ?? ''), isset($d['verbindung']) ? (string)$d['verbindung'] : null);
    mcp_json(200, mcp_admin_stand($admin, $ich));
    exit;
}
mcp_json(400, ['fehler' => 'Unbekannte Aktion.']);
