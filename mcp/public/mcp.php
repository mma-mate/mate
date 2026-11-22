<?php
declare(strict_types=1);
/**
 * MATE – Claude-Connector: MCP-Endpunkt (Streamable HTTP, nur POST mit JSON-Antwort)
 * Adresse: https://<mate>/mcp  (Weiterleitung per .htaccess)
 */

require_once __DIR__ . '/../src/mcp/werkzeuge.php';

header('X-Content-Type-Options: nosniff');
$methode = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($methode === 'OPTIONS') { http_response_code(204); header('Allow: POST'); exit; }
if ($methode !== 'POST') { header('Allow: POST'); mcp_json(405, ['error' => 'Nur POST.']); exit; }

/* ---------- Anmeldung über Bearer-Token ---------- */
function mcp_bearer(): string
{
    $h = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if ($h === '' && function_exists('apache_request_headers')) {
        foreach (apache_request_headers() as $k => $v) if (strcasecmp($k, 'Authorization') === 0) $h = $v;
    }
    return preg_match('/^Bearer\s+(\S+)$/i', trim($h), $m) ? $m[1] : '';
}

$basis = mcp_basis();
$kopf401 = ['WWW-Authenticate' => 'Bearer realm="MATE", resource_metadata="' . $basis . '/.well-known/oauth-protected-resource/mcp"'];
$tok = mcp_bearer();
$zeile = $tok !== '' ? mcp_token_finden($tok, 'access') : null;
if (!$zeile) {
    $k = $kopf401;
    if ($tok !== '') $k['WWW-Authenticate'] .= ', error="invalid_token"';
    mcp_json(401, ['error' => 'Anmeldung bei MATE nötig.'], $k);
    exit;
}
$lk = mcp_benutzer((string)$zeile['benutzer_id']);
$grund = mcp_zugang_grund($lk);
if ($grund !== null) {
    mcp_verbindungen_trennen((string)$zeile['benutzer_id']);
    mcp_json(401, ['error' => $grund], $kopf401 + []);
    exit;
}
mcp_pdo()->prepare('UPDATE mcp_tokens SET zuletzt = ? WHERE verbindung = ?')->execute([mcp_jetzt(), $zeile['verbindung']]);

/* ---------- JSON-RPC ---------- */
$roh = file_get_contents('php://input') ?: '';
$anfrage = json_decode($roh, true);
if (!is_array($anfrage)) { mcp_json(400, ['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32700, 'message' => 'Ungültiges JSON']]); exit; }

$einzeln = array_keys($anfrage) !== range(0, count($anfrage) - 1);
$liste = $einzeln ? [$anfrage] : $anfrage;
$antworten = [];
foreach ($liste as $nachricht) {
    $a = mcp_rpc(is_array($nachricht) ? $nachricht : [], $lk);
    if ($a !== null) $antworten[] = $a;
}
if (!$antworten) { http_response_code(202); exit; }
mcp_json(200, $einzeln ? $antworten[0] : $antworten);

function mcp_anweisungen(array $lk): string
{
    return "MATE ist die Aufgaben- und Terminplattform der Modularen Mittelstufe Aspern (Wien). Du arbeitest als Lehrkraft "
        . trim($lk['vn'] . ' ' . $lk['nn']) . " und siehst nur ihre Kurse.\n"
        . "- IDs: zuerst kurse_auflisten, dann aufgaben_auflisten bzw. abgaben_auflisten; IDs nie erfinden.\n"
        . "- Aufgaben haben eine Nummer pro Kurs, die MATE selbst vergibt – Titel ohne Nummer.\n"
        . "- Zeiten sind Wiener Zeit, Datum JJJJ-MM-TT.\n"
        . "- Bevor du etwas anlegst, änderst, bewertest, Feedback schickst oder freigibst: kurz zusammenfassen und die Lehrkraft fragen.\n"
        . "- Feedback ist für 10- bis 14-jährige Kinder: freundlich, konkret, kurz, mit Du-Anrede.\n"
        . "- Namen, Abgaben und Bewertungen der SuS sind vertraulich: nicht in andere Dienste kopieren oder weitergeben.";
}

function mcp_rpc(array $n, array $lk): ?array
{
    $id = $n['id'] ?? null;
    $m = (string)($n['method'] ?? '');
    $p = is_array($n['params'] ?? null) ? $n['params'] : [];
    if (!array_key_exists('id', $n)) return null;               // Benachrichtigung (z. B. notifications/initialized)
    $ok = fn($r) => ['jsonrpc' => '2.0', 'id' => $id, 'result' => $r];
    $err = fn(int $c, string $t) => ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $c, 'message' => $t]];
    switch ($m) {
        case 'initialize':
            $v = (string)($p['protocolVersion'] ?? '');
            return $ok(['protocolVersion' => in_array($v, MCP_PROTOKOLLE, true) ? $v : MCP_PROTOKOLLE[0],
                        'capabilities' => ['tools' => ['listChanged' => false]],
                        'serverInfo' => ['name' => 'mate', 'title' => 'MATE', 'version' => MCP_VERSION],
                        'instructions' => mcp_anweisungen($lk)]);
        case 'ping':
            return $ok(new stdClass());
        case 'tools/list':
            return $ok(['tools' => mcp_werkzeuge_liste()]);
        case 'resources/list':
            return $ok(['resources' => []]);
        case 'prompts/list':
            return $ok(['prompts' => []]);
        case 'tools/call':
            $name = (string)($p['name'] ?? '');
            $arg = is_array($p['arguments'] ?? null) ? $p['arguments'] : [];
            if (!mcp_limit_ok((string)$lk['id'])) return $ok(['content' => [['type' => 'text', 'text' => 'Zu viele Aufrufe in kurzer Zeit. Bitte in ein paar Minuten noch einmal.']], 'isError' => true]);
            try {
                $daten = mcp_werkzeug_ausfuehren($lk, $name, $arg);
                $extra = $daten['_inhalt'] ?? [];
                $info = (string)($daten['info'] ?? '');
                unset($daten['_inhalt'], $daten['info']);
                mcp_protokollieren((string)$lk['id'], $name, true, $info);
                $text = json_encode($daten, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
                return $ok(['content' => array_merge([['type' => 'text', 'text' => $text]], $extra), 'structuredContent' => $daten, 'isError' => false]);
            } catch (McpFehler $e) {
                mcp_protokollieren((string)$lk['id'], $name, false, mb_substr($e->getMessage(), 0, 120));
                return $ok(['content' => [['type' => 'text', 'text' => $e->getMessage()]], 'isError' => true]);
            } catch (Throwable $e) {
                error_log('MATE-MCP ' . $name . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
                mcp_protokollieren((string)$lk['id'], $name, false, 'Serverfehler');
                return $ok(['content' => [['type' => 'text', 'text' => 'In MATE ist ein Fehler aufgetreten. Bitte später noch einmal versuchen.']], 'isError' => true]);
            }
        default:
            return $err(-32601, 'Unbekannte Methode: ' . $m);
    }
}
