<?php
declare(strict_types=1);
/**
 * MATE – Claude-Connector: Anmeldung (OAuth 2.1 mit PKCE)
 * Wege (per .htaccess hierher):
 *   /.well-known/oauth-protected-resource[/mcp]   Beschreibung der Ressource
 *   /.well-known/oauth-authorization-server       Beschreibung des Anmeldeservers
 *   /oauth/register    Claude meldet sich an (Dynamic Client Registration)
 *   /oauth/authorize   Lehrkraft meldet sich mit ihrem MATE-Konto an und erlaubt den Zugriff
 *   /oauth/token       Code bzw. Erneuerung gegen Token tauschen
 *   /oauth/revoke      Token zurückziehen
 */

require_once __DIR__ . '/../src/mcp/werkzeuge.php';

header('X-Content-Type-Options: nosniff');
$weg = (string)($_GET['weg'] ?? '');
if ($weg === '') $weg = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';
$weg = '/' . trim(preg_replace('#^.*?(/\.well-known/|/oauth/)#', '$1', $weg), '/');
$basis = mcp_basis();

switch (true) {
    case str_starts_with($weg, '/.well-known/oauth-protected-resource'):
        mcp_json(200, ['resource' => $basis . '/mcp', 'authorization_servers' => [$basis], 'bearer_methods_supported' => ['header'],
                       'scopes_supported' => ['mate'], 'resource_name' => 'MATE']);
        break;
    case str_starts_with($weg, '/.well-known/oauth-authorization-server') || str_starts_with($weg, '/.well-known/openid-configuration'):
        mcp_json(200, ['issuer' => $basis, 'authorization_endpoint' => $basis . '/oauth/authorize', 'token_endpoint' => $basis . '/oauth/token',
                       'registration_endpoint' => $basis . '/oauth/register', 'revocation_endpoint' => $basis . '/oauth/revoke',
                       'response_types_supported' => ['code'], 'grant_types_supported' => ['authorization_code', 'refresh_token'],
                       'code_challenge_methods_supported' => ['S256'], 'token_endpoint_auth_methods_supported' => ['none'],
                       'revocation_endpoint_auth_methods_supported' => ['none'], 'scopes_supported' => ['mate'],
                       'client_id_metadata_document_supported' => true]);
        break;
    case $weg === '/oauth/register':
        oauth_register();
        break;
    case $weg === '/oauth/authorize':
        oauth_authorize($basis);
        break;
    case $weg === '/oauth/token':
        oauth_token();
        break;
    case $weg === '/oauth/revoke':
        oauth_revoke();
        break;
    default:
        mcp_json(404, ['error' => 'not_found']);
}

/* ---------- Clients ---------- */

function oauth_register(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { mcp_json(405, ['error' => 'invalid_request']); return; }
    $d = mcp_body();
    $uris = $d['redirect_uris'] ?? [];
    if (!is_array($uris) || !$uris) { mcp_json(400, ['error' => 'invalid_redirect_uri', 'error_description' => 'redirect_uris fehlt']); return; }
    foreach ($uris as $u) if (!is_string($u) || !mcp_redirect_erlaubt($u)) {
        mcp_json(400, ['error' => 'invalid_redirect_uri', 'error_description' => 'MATE erlaubt nur Claude als Client.']); return;
    }
    $id = 'mate_c_' . mcp_zufall(18);
    $name = mb_substr(trim((string)($d['client_name'] ?? 'Claude')), 0, 200) ?: 'Claude';
    mcp_pdo()->prepare('INSERT INTO mcp_clients (client_id, name, redirect_uris, erstellt) VALUES (?,?,?,?)')
        ->execute([$id, $name, json_encode(array_values($uris)), mcp_jetzt()]);
    mcp_json(201, ['client_id' => $id, 'client_id_issued_at' => time(), 'client_name' => $name, 'redirect_uris' => array_values($uris),
                   'token_endpoint_auth_method' => 'none', 'grant_types' => ['authorization_code', 'refresh_token'], 'response_types' => ['code']]);
}

/** Client finden: registriert, oder client_id ist eine https-Adresse mit Metadaten (Claude's published identity). */
function oauth_client(string $id): ?array
{
    if ($id === '') return null;
    $st = mcp_pdo()->prepare('SELECT * FROM mcp_clients WHERE client_id = ?');
    $st->execute([$id]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if ($r && (!str_starts_with($id, 'https://') || (int)$r['erstellt'] > mcp_jetzt() - 86400000))
        return ['client_id' => $id, 'name' => $r['name'], 'redirect_uris' => json_decode($r['redirect_uris'], true) ?: []];
    if (!str_starts_with($id, 'https://') || strlen($id) > 255) return null;
    $doc = mcp_url_laden($id);
    $m = $doc ? json_decode($doc, true) : null;
    if (!is_array($m) || ($m['client_id'] ?? '') !== $id || !is_array($m['redirect_uris'] ?? null)) return null;
    $uris = array_values(array_filter($m['redirect_uris'], fn($u) => is_string($u) && mcp_redirect_erlaubt($u)));
    if (!$uris) return null;
    $name = mb_substr((string)($m['client_name'] ?? 'Claude'), 0, 200);
    $pdo = mcp_pdo();
    $pdo->prepare('DELETE FROM mcp_clients WHERE client_id = ?')->execute([$id]);
    $pdo->prepare('INSERT INTO mcp_clients (client_id, name, redirect_uris, erstellt) VALUES (?,?,?,?)')->execute([$id, $name, json_encode($uris), mcp_jetzt()]);
    return ['client_id' => $id, 'name' => $name, 'redirect_uris' => $uris];
}

function mcp_url_laden(string $url): ?string
{
    if (!function_exists('curl_init')) return null;
    $c = curl_init($url);
    curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 6, CURLOPT_FOLLOWLOCATION => false,
                           CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_HTTPHEADER => ['Accept: application/json'], CURLOPT_MAXFILESIZE => 65536]);
    $r = curl_exec($c);
    $code = (int)curl_getinfo($c, CURLINFO_HTTP_CODE);
    curl_close($c);
    return ($r !== false && $code === 200 && strlen((string)$r) <= 65536) ? (string)$r : null;
}

/* ---------- Anmeldung der Lehrkraft ---------- */

function oauth_seite(string $titel, string $inhalt): void
{
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Frame-Options: DENY');
    header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; img-src 'self' data:; form-action 'self' https://claude.ai https://claude.com; frame-ancestors 'none'");
    header('Referrer-Policy: no-referrer');
    echo '<!DOCTYPE html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . mcp_e($titel) . ' · MATE</title><style>'
        . 'body{font:16px/1.5 system-ui,-apple-system,"Segoe UI",sans-serif;background:#f4f2f7;color:#1f1d24;margin:0;padding:24px}'
        . '.box{max-width:440px;margin:6vh auto;background:#fff;border-radius:14px;padding:28px;box-shadow:0 4px 18px rgba(60,30,90,.12)}'
        . '.band{height:6px;border-radius:3px;background:linear-gradient(90deg,#F7900A 0 25%,#A8C614 25% 50%,#1E9BDE 50% 75%,#5E2396 75%);margin-bottom:18px}'
        . 'h1{font-size:22px;margin:0 0 6px}p{margin:8px 0}label{display:block;font-weight:600;margin:14px 0 4px}'
        . 'input[type=email],input[type=password],input[type=text]{width:100%;box-sizing:border-box;font-size:16px;padding:10px;border:1px solid #c9c3d3;border-radius:8px}'
        . '.btns{display:flex;gap:10px;margin-top:20px;flex-wrap:wrap}button{font-size:16px;padding:10px 18px;border-radius:8px;border:1px solid #5E2396;cursor:pointer}'
        . '.p{background:#5E2396;color:#fff}.g{background:#fff;color:#5E2396}.note{background:#fff4e5;border-left:4px solid #F7900A;padding:10px 12px;border-radius:6px;margin:14px 0}'
        . 'ul{padding-left:20px;margin:6px 0}small{color:#6b6577}.fehler{background:#fde8e8;border-left:4px solid #c0392b;padding:10px 12px;border-radius:6px}'
        . '@media (prefers-color-scheme:dark){body{background:#17151b;color:#eee}.box{background:#24212b}input{background:#17151b;color:#eee;border-color:#4a4552}.g{background:#24212b;color:#cdb5ef;border-color:#cdb5ef}.note{background:#3a2c18}.fehler{background:#3d1f1f}small{color:#aaa}}'
        . '</style></head><body><div class="box"><div class="band"></div>' . $inhalt . '</div></body></html>';
}

function oauth_fehlerseite(string $text): void
{
    http_response_code(400);
    oauth_seite('Fehler', '<h1>Verbindung nicht möglich</h1><p class="fehler">' . mcp_e($text) . '</p><p><small>Schließ dieses Fenster und versuch es in Claude noch einmal. Wenn es wieder nicht klappt, melde dich beim MATE-Admin.</small></p>');
}

function oauth_authorize(string $basis): void
{
    mcp_sitzung_starten(true);
    $q = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' ? $_POST : $_GET;
    $client = oauth_client((string)($q['client_id'] ?? ''));
    $redirect = (string)($q['redirect_uri'] ?? '');
    if (!$client) { oauth_fehlerseite('Unbekannter Client.'); return; }
    if (!in_array($redirect, $client['redirect_uris'], true) || !mcp_redirect_erlaubt($redirect)) { oauth_fehlerseite('Ungültige Rücksprung-Adresse.'); return; }
    $state = (string)($q['state'] ?? '');
    $zurueck = function (array $p) use ($redirect, $state, $basis): void {
        if ($state !== '') $p['state'] = $state;
        $p['iss'] = $basis;
        header('Location: ' . $redirect . (str_contains($redirect, '?') ? '&' : '?') . http_build_query($p), true, 302);
    };
    if (($q['response_type'] ?? '') !== 'code') { $zurueck(['error' => 'unsupported_response_type']); return; }
    $challenge = (string)($q['code_challenge'] ?? '');
    if (($q['code_challenge_method'] ?? '') !== 'S256' || !preg_match('/^[A-Za-z0-9\-._~]{43,128}$/', $challenge)) {
        $zurueck(['error' => 'invalid_request', 'error_description' => 'PKCE (S256) erforderlich']); return;
    }
    $res = (string)($q['resource'] ?? '');
    if ($res !== '' && rtrim($res, '/') !== $basis . '/mcp' && rtrim($res, '/') !== $basis) { $zurueck(['error' => 'invalid_target']); return; }

    // versteckte Felder, damit die Parameter bei Anmeldung/Erlauben erhalten bleiben
    $felder = '';
    foreach (['response_type', 'client_id', 'redirect_uri', 'code_challenge', 'code_challenge_method', 'state', 'scope', 'resource'] as $k)
        if (isset($q[$k])) $felder .= '<input type="hidden" name="' . $k . '" value="' . mcp_e((string)$q[$k]) . '">';
    if (empty($_SESSION['mcp_csrf'])) $_SESSION['mcp_csrf'] = bin2hex(random_bytes(16));
    $csrf = $_SESSION['mcp_csrf'];
    $felder .= '<input type="hidden" name="mcp_csrf" value="' . mcp_e($csrf) . '">';
    $postOk = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && hash_equals($csrf, (string)($_POST['mcp_csrf'] ?? ''));
    $hinweis = '';

    // 1. Anmelden (falls noch keine MATE-Sitzung)
    if ($postOk && ($_POST['schritt'] ?? '') === 'login') {
        $r = mcp_anmelden(trim((string)($_POST['kennung'] ?? '')), (string)($_POST['passwort'] ?? ''));
        if (!$r['ok']) $hinweis = '<p class="fehler">' . mcp_e($r['meldung']) . '</p>';
        else { $_SESSION['mcp_csrf'] = $csrf; }
    }
    $ich = mcp_sitzung_benutzer();
    if (!$ich) {
        oauth_seite('Anmelden', '<h1>Claude mit MATE verbinden</h1><p>Melde dich mit deinem MATE-Konto an.</p>' . $hinweis
            . '<form method="post" autocomplete="on">' . $felder . '<input type="hidden" name="schritt" value="login">'
            . '<label for="k">E-Mail</label><input type="email" id="k" name="kennung" autocomplete="username" required autofocus>'
            . '<label for="p">Passwort</label><input type="password" id="p" name="passwort" autocomplete="current-password" required>'
            . '<div class="btns"><button class="p" type="submit">Anmelden</button></div></form>');
        return;
    }
    $grund = mcp_zugang_grund($ich);
    if ($grund !== null) {
        if ($postOk && ($_POST['schritt'] ?? '') === 'abbrechen') { $zurueck(['error' => 'access_denied']); return; }
        oauth_seite('Kein Zugang', '<h1>Claude mit MATE verbinden</h1><p>Angemeldet als <b>' . mcp_e(trim($ich['vn'] . ' ' . $ich['nn'])) . '</b>.</p><p class="fehler">' . mcp_e($grund) . '</p>'
            . '<form method="post">' . $felder . '<input type="hidden" name="schritt" value="abbrechen"><div class="btns"><button class="g" type="submit">Zurück zu Claude</button></div></form>');
        return;
    }

    // 2. Erlauben oder ablehnen
    if ($postOk && in_array($_POST['schritt'] ?? '', ['erlauben', 'ablehnen'], true)) {
        if ($_POST['schritt'] === 'ablehnen') { $zurueck(['error' => 'access_denied']); return; }
        $code = mcp_zufall(32);
        mcp_pdo()->prepare('INSERT INTO mcp_codes (code_hash, client_id, benutzer_id, redirect_uri, challenge, ablauf) VALUES (?,?,?,?,?,?)')
            ->execute([mcp_hash($code), $client['client_id'], (string)$ich['id'], $redirect, $challenge, mcp_jetzt() + MCP_CODE_SEK * 1000]);
        $zurueck(['code' => $code]);
        return;
    }
    $e = mcp_einstellungen();
    $grp = [];
    foreach (mcp_werkzeuge() as $n => $w) if (!empty($e['werkzeuge'][$n])) $grp[$w['gruppe']][] = $w['titel'];
    $liste = '';
    foreach (MCP_GRUPPEN as $g => $t) if (!empty($grp[$g])) $liste .= '<li><b>' . mcp_e($t) . '</b><br><small>' . mcp_e(implode(' · ', $grp[$g])) . '</small></li>';
    if ($liste === '') $liste = '<li>Der Admin hat noch keine Werkzeuge freigeschaltet.</li>';
    $sus = !empty($grp['abgaben']) || !empty($grp['bewerten']);
    oauth_seite('Zugriff erlauben', '<h1>Claude mit MATE verbinden</h1>'
        . '<p><b>' . mcp_e($client['name']) . '</b> möchte als <b>' . mcp_e(trim($ich['vn'] . ' ' . $ich['nn'])) . '</b> auf MATE zugreifen – nur auf deine Kurse und mit deinen Rechten.</p>'
        . '<p>Erlaubt sind:</p><ul>' . $liste . '</ul>'
        . ($sus ? '<div class="note">Dabei gehen Namen, Abgaben und Bewertungen deiner SuS an Claude (Anthropic). Nutze das nur so, wie es die Schule erlaubt.</div>' : '')
        . '<p><small>Trennen kannst du die Verbindung jederzeit in Claude (Einstellungen → Connectors) oder in MATE unter „Profil“. Jeder Aufruf wird in MATE protokolliert (ohne Inhalte).</small></p>'
        . '<form method="post">' . $felder . '<div class="btns"><button class="p" type="submit" name="schritt" value="erlauben">Erlauben</button><button class="g" type="submit" name="schritt" value="ablehnen">Ablehnen</button></div></form>');
}

/* ---------- Token ---------- */

function oauth_token(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { mcp_json(405, ['error' => 'invalid_request']); return; }
    $d = mcp_body();
    $grant = (string)($d['grant_type'] ?? '');
    $clientId = (string)($d['client_id'] ?? '');
    $fehler = fn(string $e, string $t = '') => mcp_json(400, ['error' => $e] + ($t !== '' ? ['error_description' => $t] : []));
    $pdo = mcp_pdo();
    if ($grant === 'authorization_code') {
        $code = (string)($d['code'] ?? '');
        $st = $pdo->prepare('SELECT * FROM mcp_codes WHERE code_hash = ?');
        $st->execute([mcp_hash($code)]);
        $c = $st->fetch(PDO::FETCH_ASSOC);
        if ($c) $pdo->prepare('DELETE FROM mcp_codes WHERE code_hash = ?')->execute([$c['code_hash']]);   // nur einmal gültig
        if (!$c || (int)$c['ablauf'] < mcp_jetzt()) { $fehler('invalid_grant', 'Code ungültig oder abgelaufen'); return; }
        if ($clientId !== '' && $clientId !== $c['client_id']) { $fehler('invalid_grant', 'Falscher Client'); return; }
        if ((string)($d['redirect_uri'] ?? $c['redirect_uri']) !== $c['redirect_uri']) { $fehler('invalid_grant', 'Falsche Rücksprung-Adresse'); return; }
        $v = (string)($d['code_verifier'] ?? '');
        $soll = rtrim(strtr(base64_encode(hash('sha256', $v, true)), '+/', '-_'), '=');
        if (!preg_match('/^[A-Za-z0-9\-._~]{43,128}$/', $v) || !hash_equals($c['challenge'], $soll)) { $fehler('invalid_grant', 'PKCE stimmt nicht'); return; }
        $grund = mcp_zugang_grund(mcp_benutzer((string)$c['benutzer_id']));
        if ($grund !== null) { $fehler('invalid_grant', $grund); return; }
        mcp_json(200, mcp_token_paar($c['client_id'], (string)$c['benutzer_id']));
        return;
    }
    if ($grant === 'refresh_token') {
        $r = mcp_token_finden((string)($d['refresh_token'] ?? ''), 'refresh');
        if (!$r || ($clientId !== '' && $clientId !== $r['client_id'])) { $fehler('invalid_grant', 'Erneuerung ungültig – bitte neu verbinden'); return; }
        $grund = mcp_zugang_grund(mcp_benutzer((string)$r['benutzer_id']));
        if ($grund !== null) { mcp_verbindungen_trennen((string)$r['benutzer_id']); $fehler('invalid_grant', $grund); return; }
        $pdo->prepare('DELETE FROM mcp_tokens WHERE verbindung = ?')->execute([$r['verbindung']]);   // Rotation: alte Token weg
        mcp_json(200, mcp_token_paar($r['client_id'], (string)$r['benutzer_id'], $r['verbindung']));
        return;
    }
    $fehler('unsupported_grant_type');
}

function oauth_revoke(): void
{
    $d = mcp_body();
    $t = (string)($d['token'] ?? '');
    $r = mcp_token_finden($t, 'refresh') ?? mcp_token_finden($t, 'access');
    if ($r) mcp_pdo()->prepare('DELETE FROM mcp_tokens WHERE verbindung = ?')->execute([$r['verbindung']]);
    http_response_code(200);
    header('Content-Type: application/json');
    echo '{}';
}
