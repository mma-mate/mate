<?php
// Gesamttest: php test/lauf.php (startet vorher selbst den Testserver)
declare(strict_types=1);
const B = 'http://127.0.0.1:8099';
const RED = 'https://claude.ai/api/mcp/auth_callback';
$fehler = 0; $ok = 0;
function pruef(bool $b, string $t): void { global $fehler, $ok; if ($b) { $ok++; echo "  ✓ $t\n"; } else { $fehler++; echo "  ✗ $t\n"; } }

function http(string $m, string $url, $body = null, array $kopf = [], ?string $jar = null, bool $form = false): array
{
    $c = curl_init(str_starts_with($url, 'http') ? $url : B . $url);
    $h = $kopf;
    if ($body !== null) {
        if ($form) { $body = http_build_query($body); $h[] = 'Content-Type: application/x-www-form-urlencoded'; }
        elseif (!is_string($body)) { $body = json_encode($body); $h[] = 'Content-Type: application/json'; }
        curl_setopt($c, CURLOPT_POSTFIELDS, $body);
    }
    curl_setopt_array($c, [CURLOPT_CUSTOMREQUEST => $m, CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_HTTPHEADER => $h, CURLOPT_FOLLOWLOCATION => false]);
    if ($jar) { curl_setopt($c, CURLOPT_COOKIEJAR, $jar); curl_setopt($c, CURLOPT_COOKIEFILE, $jar); }
    $r = curl_exec($c); $code = curl_getinfo($c, CURLINFO_HTTP_CODE); $hs = curl_getinfo($c, CURLINFO_HEADER_SIZE); curl_close($c);
    $kopfText = substr($r, 0, $hs); $text = substr($r, $hs);
    return ['code' => $code, 'kopf' => $kopfText, 'text' => $text, 'json' => json_decode($text, true)];
}

function versteckt(string $html): array { preg_match_all('/<input type="hidden" name="([^"]+)" value="([^"]*)">/', $html, $m, PREG_SET_ORDER); $a = []; foreach ($m as $x) $a[$x[1]] = html_entity_decode($x[2]); return $a; }

function verbinden(string $mail, string $jar, ?string &$verifier = null, ?string $clientId = null): array
{
    if (!$clientId) { $r = http('POST', '/oauth/register', ['client_name' => 'Claude', 'redirect_uris' => [RED]]); $clientId = $r['json']['client_id']; }
    $verifier = rtrim(strtr(base64_encode(random_bytes(40)), '+/', '-_'), '=');
    $ch = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    $q = http_build_query(['response_type' => 'code', 'client_id' => $clientId, 'redirect_uri' => RED, 'code_challenge' => $ch, 'code_challenge_method' => 'S256', 'state' => 'xyz', 'resource' => B . '/mcp']);
    $s1 = http('GET', '/oauth/authorize?' . $q, null, [], $jar);
    if (str_contains($s1['text'], 'name="schritt" value="login"')) {
        $f = versteckt($s1['text']); $f['kennung'] = $mail; $f['passwort'] = 'test1234';
        $s1 = http('POST', '/oauth/authorize', $f, [], $jar, true);
    }
    return ['client' => $clientId, 'seite' => $s1, 'q' => $q];
}

function erlauben(array $v, string $jar): ?string
{
    $f = versteckt($v['seite']['text']); $f['schritt'] = 'erlauben';
    $r = http('POST', '/oauth/authorize', $f, [], $jar, true);
    return preg_match('/Location: (\S+)/i', $r['kopf'], $m) ? $m[1] : null;
}

function rpc(string $tok, string $m, array $p = [], int $id = 1): array
{
    return http('POST', '/mcp', ['jsonrpc' => '2.0', 'id' => $id, 'method' => $m, 'params' => (object)$p], ['Authorization: Bearer ' . $tok, 'Accept: application/json, text/event-stream']);
}
function tool(string $tok, string $n, array $a = []): array { $r = rpc($tok, 'tools/call', ['name' => $n, 'arguments' => (object)$a]); return $r['json']['result'] ?? ['fehlt' => $r]; }
function adminPost(string $jar, string $csrf, array $d): array { return http('POST', '/mcp-admin.php', $d, ['X-MATE-MCP-CSRF: ' . $csrf], $jar); }

passthru('php ' . __DIR__ . '/vorbereiten.php');
$srv = proc_open(['php', '-S', '127.0.0.1:8099', __DIR__ . '/router.php'], [1 => ['file', '/tmp/mate-mcp-test/server.log', 'a'], 2 => ['file', '/tmp/mate-mcp-test/server.log', 'a']], $pipes);
usleep(600000);

try {
echo "Beschreibung und Schutz\n";
$r = http('GET', '/.well-known/oauth-protected-resource/mcp'); pruef($r['json']['resource'] === B . '/mcp', 'Ressource beschrieben');
$r = http('GET', '/.well-known/oauth-authorization-server'); pruef(($r['json']['code_challenge_methods_supported'][0] ?? '') === 'S256', 'Anmeldeserver beschrieben (PKCE S256)');
$r = http('POST', '/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize']); pruef($r['code'] === 401 && str_contains($r['kopf'], 'resource_metadata='), 'ohne Token: 401 mit Hinweis auf die Anmeldung');
$r = http('GET', '/mcp'); pruef($r['code'] === 405, 'GET auf /mcp: 405');
$r = http('POST', '/oauth/register', ['client_name' => 'Böse', 'redirect_uris' => ['https://boese.example/cb']]); pruef($r['code'] === 400, 'fremde Rücksprung-Adresse wird abgelehnt');

echo "Admin richtet ein\n";
$jarA = '/tmp/mate-mcp-test/admin.jar';
$v = verbinden('anna.admin@demo.mate.test', $jarA);
pruef(str_contains($v['seite']['text'], 'ausgeschaltet'), 'Connector anfangs aus – Anmeldung zeigt das');
$st = http('GET', '/mcp-admin.php', null, [], $jarA)['json'];
pruef(isset($st['einstellungen']) && count($st['werkzeuge']) === 13, 'Admin sieht Einstellungen und 13 Werkzeuge');
pruef(count($st['lehrkraefte']) === 4, 'Admin sieht die LK-Liste');
$r = adminPost($jarA, 'falsch', ['aktion' => 'speichern', 'einstellungen' => ['aktiv' => true]]); pruef($r['code'] === 403, 'ohne CSRF kein Speichern');
$alle = array_fill_keys(array_column($st['werkzeuge'], 'name'), true);
$r = adminPost($jarA, $st['csrf'], ['aktion' => 'speichern', 'einstellungen' => ['aktiv' => true, 'werkzeuge' => $alle, 'lk' => ['1', '10']]]);
pruef($r['json']['einstellungen']['aktiv'] === true && $r['json']['einstellungen']['lk'] === ['1'], 'eingeschaltet, nur Sabine freigeschaltet (Kind-ID wird ignoriert)');

echo "LK ohne Freischaltung / mit Startpasswort\n";
$v = verbinden('ali.test@demo.mate.test', '/tmp/mate-mcp-test/ali.jar');
pruef(str_contains($v['seite']['text'], 'nicht freigeschaltet'), 'Ali (nicht freigeschaltet) bekommt keinen Zugang');
$v = verbinden('neu.lehrer@demo.mate.test', '/tmp/mate-mcp-test/neu.jar');
pruef(str_contains($v['seite']['text'], 'eigenes Passwort'), 'Konto mit Startpasswort wird abgewiesen');
$v = verbinden('lena.bauer@demo.mate.test', '/tmp/mate-mcp-test/kind.jar');
pruef(str_contains($v['seite']['text'], 'nur für Lehrkräfte') || str_contains($v['seite']['text'], 'stimmt nicht'), 'Kind bekommt keinen Zugang');

echo "Sabine verbindet Claude\n";
$jar = '/tmp/mate-mcp-test/sabine.jar';
$v = verbinden('sabine.demo@demo.mate.test', $jar, $ver);
pruef(str_contains($v['seite']['text'], 'Erlauben') && str_contains($v['seite']['text'], 'Abgaben lesen'), 'Erlauben-Seite mit Liste der Werkzeuge');
$loc = erlauben($v, $jar);
parse_str((string)parse_url((string)$loc, PHP_URL_QUERY), $q);
pruef(str_starts_with((string)$loc, RED) && ($q['state'] ?? '') === 'xyz' && !empty($q['code']), 'Rücksprung zu Claude mit Code und state');
$r = http('POST', '/oauth/token', ['grant_type' => 'authorization_code', 'code' => $q['code'], 'redirect_uri' => RED, 'client_id' => $v['client'], 'code_verifier' => 'falsch' . str_repeat('x', 50)], [], null, true);
pruef($r['code'] === 400, 'falscher PKCE-Verifier: abgelehnt (und Code verbraucht)');
$v = verbinden('sabine.demo@demo.mate.test', $jar, $ver, $v['client']);
pruef(str_contains($v['seite']['text'], 'Erlauben') && !str_contains($v['seite']['text'], 'name="passwort"'), 'zweites Mal: schon angemeldet, gleich Erlauben');
$loc = erlauben($v, $jar); parse_str((string)parse_url((string)$loc, PHP_URL_QUERY), $q);
$r = http('POST', '/oauth/token', ['grant_type' => 'authorization_code', 'code' => $q['code'], 'redirect_uri' => RED, 'client_id' => $v['client'], 'code_verifier' => $ver], [], null, true);
$tok = $r['json']['access_token'] ?? ''; $ref = $r['json']['refresh_token'] ?? '';
pruef($tok !== '' && $ref !== '', 'Token erhalten');
$r2 = http('POST', '/oauth/token', ['grant_type' => 'authorization_code', 'code' => $q['code'], 'redirect_uri' => RED, 'client_id' => $v['client'], 'code_verifier' => $ver], [], null, true);
pruef($r2['code'] === 400, 'Code nur einmal verwendbar');

echo "MCP\n";
$r = rpc($tok, 'initialize', ['protocolVersion' => '2025-06-18', 'capabilities' => new stdClass(), 'clientInfo' => ['name' => 'test', 'version' => '1']]);
pruef(($r['json']['result']['protocolVersion'] ?? '') === '2025-06-18' && str_contains($r['json']['result']['instructions'], 'Sabine'), 'initialize');
$r = http('POST', '/mcp', ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'], ['Authorization: Bearer ' . $tok]); pruef($r['code'] === 202, 'Benachrichtigung: 202');
$r = rpc($tok, 'tools/list'); pruef(count($r['json']['result']['tools']) === 13, 'tools/list: 13 Werkzeuge');

$x = tool($tok, 'kurse_auflisten'); $ks = $x['structuredContent']['kurse'];
pruef(count($ks) === 1 && $ks[0]['id'] === 'k1' && $ks[0]['verantwortlich'], 'kurse_auflisten: nur eigener, laufender Kurs');
$x = tool($tok, 'kurse_auflisten', ['archiv' => true]); pruef(count($x['structuredContent']['kurse']) === 2, 'mit Archiv: 2 Kurse');
$x = tool($tok, 'aufgaben_auflisten', ['kurs_id' => 'k1']); $a1 = $x['structuredContent']['aufgaben'][0];
pruef($a1['abgegeben'] === 2 && $a1['abwesend'] === 1 && $a1['kinder'] === 4, 'aufgaben_auflisten: Zähler stimmen');
$x = tool($tok, 'aufgaben_auflisten', ['kurs_id' => 'k2']); pruef($x['isError'] === true, 'fremder Kurs: Fehler');
$x = tool($tok, 'aufgabe_lesen', ['aufgabe_id' => 'a2']); pruef($x['isError'] === true, 'fremde Aufgabe lesen: Fehler');
$x = tool($tok, 'aufgabe_lesen', ['aufgabe_id' => 'a1']); pruef(str_contains($x['structuredContent']['bewertung'], '0 bis 10'), 'aufgabe_lesen');
$x = tool($tok, 'termine_auflisten', ['von' => date('Y-m-d'), 'bis' => date('Y-m-d', strtotime('+20 days'))]);
$tt = array_column($x['structuredContent']['termine'], 'id'); pruef(in_array('t1', $tt) && in_array('t2', $tt) && !in_array('t3', $tt), 'termine_auflisten: eigene + Schultermine, keine fremden Kurstermine');

$x = tool($tok, 'aufgabe_anlegen', ['kurs_id' => 'k1', 'titel' => '3. Vokabeln Unit 3', 'frist_datum' => date('Y-m-d', strtotime('+7 days')), 'sichtbar' => 'spaeter', 'beschreibung' => '**Lernen** und abfotografieren']);
$neu = $x['structuredContent'] ?? [];
pruef(($neu['nr'] ?? 0) === 2 && $neu['titel'] === 'Vokabeln Unit 3' && str_contains($neu['sichtbar'], 'ausgeblendet') && str_contains($neu['frist'], '08:00'), 'aufgabe_anlegen: Nr. 2, Nummer aus Titel entfernt, später sichtbar, Frist-Uhrzeit vom Kurs');
$x = tool($tok, 'aufgabe_anlegen', ['kurs_id' => 'k1', 'titel' => 'Alt', 'frist_datum' => '2020-01-01']); pruef($x['isError'] && str_contains($x['content'][0]['text'], 'Vergangenheit'), 'Frist in der Vergangenheit: Fehler');
$x = tool($tok, 'aufgabe_anlegen', ['kurs_id' => 'k3', 'titel' => 'X', 'frist_datum' => date('Y-m-d', strtotime('+3 days'))]); pruef($x['isError'] && str_contains($x['content'][0]['text'], 'archiviert'), 'archivierter Kurs: nur lesen');
$x = tool($tok, 'aufgabe_aendern', ['aufgabe_id' => $neu['id'], 'sichtbar' => 'sofort', 'punkte' => 20]); pruef(($x['structuredContent']['sichtbar'] ?? '') === 'sichtbar', 'aufgabe_aendern: sofort sichtbar, 20 Punkte');
$x = tool($tok, 'aufgabe_aendern', ['aufgabe_id' => $neu['id']]); pruef($x['isError'], 'aufgabe_aendern ohne Felder: Fehler');
$x = tool($tok, 'termin_anlegen', ['kurs_id' => 'k1', 'art' => 'pruefung', 'datum' => date('Y-m-d', strtotime('+14 days')), 'einheit_von' => 3, 'beschreibung' => 'Unit 1–3']);
$tn = $x['structuredContent'] ?? []; pruef(($tn['titel'] ?? '') === 'Schularbeit Englisch' && ($tn['zeit'] ?? '') === '3. Einheit', 'termin_anlegen: Standardtitel, 3. Einheit');
$x = tool($tok, 'termin_aendern', ['termin_id' => $tn['id'], 'titel' => 'Test Unit 1–3', 'sichtbar' => 'spaeter']); pruef(($x['structuredContent']['titel'] ?? '') === 'Test Unit 1–3', 'termin_aendern');
$x = tool($tok, 'termin_aendern', ['termin_id' => 't2', 'titel' => 'Hack']); pruef($x['isError'], 'Schultermin ändern: Fehler');
$x = tool($tok, 'termin_aendern', ['termin_id' => $tn['id'], 'einheit_bis' => 4]); pruef(($x['structuredContent']['zeit'] ?? '') === '3.–4. Einheit', 'termin_aendern: nur einheit_bis, Beginn bleibt');
$x = tool($tok, 'termin_aendern', ['termin_id' => $tn['id'], 'uhrzeit_von' => '9:00', 'uhrzeit_bis' => '11:30']); pruef(($x['structuredContent']['zeit'] ?? '') === '09:00–11:30', 'termin_aendern: Uhrzeit, einstellige Stunde vor zweistelliger');
$x = tool($tok, 'termin_aendern', ['termin_id' => $tn['id'], 'uhrzeit_von' => '08:30']); pruef(($x['structuredContent']['zeit'] ?? '') === '08:30–11:30', 'termin_aendern: nur uhrzeit_von, Endzeit bleibt');
$x = tool($tok, 'termin_aendern', ['termin_id' => $tn['id'], 'uhrzeit_bis' => '12:00']); pruef(($x['structuredContent']['zeit'] ?? '') === '08:30–12:00', 'termin_aendern: nur uhrzeit_bis, Beginn bleibt');
$x = tool($tok, 'termin_aendern', ['termin_id' => $tn['id'], 'uhrzeit_von' => '12:30']); pruef($x['isError'] && str_contains($x['content'][0]['text'], 'uhrzeit_bis'), 'termin_aendern: neuer Beginn nach alter Endzeit: Fehler');

$x = tool($tok, 'abgaben_auflisten', ['aufgabe_id' => 'a1']); $ab = array_column($x['structuredContent']['abgaben'], null, 'schueler_id');
pruef($ab['s10']['status'] === 'abgegeben' && $ab['s11']['status'] === 'verspätet' && $ab['s12']['status'] === 'abwesend' && $ab['s13']['status'] === 'überfällig' && $ab['s11']['wartet_auf_antwort'], 'abgaben_auflisten: Status wie in der App');
$x = tool($tok, 'abgabe_lesen', ['aufgabe_id' => 'a1', 'schueler_id' => 's10']);
pruef(($x['content'][1]['type'] ?? '') === 'image' && ($x['content'][1]['mimeType'] ?? '') === 'image/jpeg', 'abgabe_lesen: Foto wird mitgeschickt');
$x = tool($tok, 'abgabe_lesen', ['aufgabe_id' => 'a1', 'schueler_id' => 's11']);
pruef(($x['content'][1]['resource']['mimeType'] ?? '') === 'application/pdf' && $x['structuredContent']['feedback_verlauf'][0]['von'] === 'Kind', 'abgabe_lesen: PDF und Frage des Kindes');
$x = tool($tok, 'abgabe_lesen', ['aufgabe_id' => 'a1', 'schueler_id' => 's99']); pruef($x['isError'], 'fremdes Kind: Fehler');

$x = tool($tok, 'bewertung_eintragen', ['aufgabe_id' => 'a1', 'schueler_id' => 's10', 'bewertung' => '8,5']); pruef(str_contains($x['structuredContent']['ergebnis'] ?? '', '8,5 / 10'), 'bewertung_eintragen: 8,5 / 10');
$x = tool($tok, 'bewertung_eintragen', ['aufgabe_id' => 'a1', 'schueler_id' => 's11', 'bewertung' => 11]); pruef($x['isError'] && str_contains($x['content'][0]['text'], 'Höchstens 10'), 'zu viele Punkte: Fehler');
$x = tool($tok, 'bewertung_eintragen', ['aufgabe_id' => 'a1', 'schueler_id' => 's12', 'bewertung' => 7]); pruef(($x['structuredContent']['status'] ?? '') === 'bewertet', 'Bewertung hebt „abwesend“ auf');
$x = tool($tok, 'feedback_senden', ['aufgabe_id' => 'a1', 'schueler_id' => 's11', 'text' => 'Ja, Übung 5 ist Pflicht. 😊']); pruef(!$x['isError'], 'feedback_senden');
$x = tool($tok, 'abgabe_lesen', ['aufgabe_id' => 'a1', 'schueler_id' => 's11', 'dateien' => false]);
pruef(count($x['content']) === 1 && end($x['structuredContent']['feedback_verlauf'])['von'] === 'Lehrkraft', 'Feedback steht im Verlauf, ohne Dateien');
$x = tool($tok, 'bewertungen_freigeben', ['aufgabe_id' => 'a1', 'freigeben' => true]); pruef(str_contains($x['structuredContent']['ergebnis'] ?? '', '2 von 4'), 'bewertungen_freigeben mit Hinweis auf Offene');
$d = json_decode(file_get_contents('/tmp/mate-mcp-test/daten.json'), true); $a = array_column($d['aufgaben'], null, 'id')['a1'];
pruef($a['freigegeben'] === true && $a['subs']['s11']['msgs'][1]['u'] === 'l1' && !empty($a['subs']['s11']['msgs'][1]['id']) && $a['subs']['s11']['sNeu'] === true && $a['subs']['s12']['abwesend'] === false, 'gespeicherte Daten wie in der App (msgs.u, sNeu, abwesend)');

echo "Erneuern, Freischaltung, Protokoll\n";
$r = http('POST', '/oauth/token', ['grant_type' => 'refresh_token', 'refresh_token' => $ref, 'client_id' => $v['client']], [], null, true);
$tok2 = $r['json']['access_token'] ?? ''; pruef($tok2 !== '', 'Token erneuert');
pruef(rpc($tok, 'tools/list')['code'] === 401, 'altes Token nach Erneuerung ungültig');
$r = http('POST', '/oauth/token', ['grant_type' => 'refresh_token', 'refresh_token' => $ref], [], null, true); pruef($r['code'] === 400, 'alter Erneuerungs-Token ungültig');
$st = http('GET', '/mcp-admin.php', null, [], $jarA)['json'];
pruef(count($st['verbindungen']) === 1 && $st['verbindungen'][0]['name'] === 'Sabine Demo', 'Admin sieht die Verbindung');
pruef(count($st['protokoll']) >= 20 && !str_contains(json_encode($st['protokoll']), 'Lena') && !str_contains(json_encode($st['protokoll']), 'Pflicht'), 'Protokoll ohne Namen/Inhalte');
$w = $alle; $w['bewertungen_freigeben'] = false; $w['abgabe_lesen'] = false;
adminPost($jarA, $st['csrf'], ['aktion' => 'speichern', 'einstellungen' => ['werkzeuge' => $w]]);
$r = rpc($tok2, 'tools/list'); pruef(count($r['json']['result']['tools']) === 11, 'Werkzeuge einzeln abgeschaltet: 11 übrig');
$x = tool($tok2, 'bewertungen_freigeben', ['aufgabe_id' => 'a1', 'freigeben' => false]); pruef($x['isError'] && str_contains($x['content'][0]['text'], 'nicht freigeschaltet'), 'abgeschaltetes Werkzeug: Fehler');
$st = http('GET', '/mcp-admin.php', null, [], $jar)['json'];
pruef(!isset($st['einstellungen']) && $st['ich']['freigeschaltet'] && count($st['ich']['verbindungen']) === 1, 'LK sieht nur den eigenen Stand');
pruef(adminPost($jar, $st['csrf'], ['aktion' => 'speichern', 'einstellungen' => ['aktiv' => false]])['code'] === 403, 'LK darf nicht speichern');
$stA = http('GET', '/mcp-admin.php', null, [], $jarA)['json'];
adminPost($jarA, $stA['csrf'], ['aktion' => 'speichern', 'einstellungen' => ['lk' => []]]);
pruef(rpc($tok2, 'tools/list')['code'] === 401, 'Freischaltung entzogen: sofort getrennt');

adminPost($jarA, $stA['csrf'], ['aktion' => 'speichern', 'einstellungen' => ['lk' => ['1']]]);
$v = verbinden('sabine.demo@demo.mate.test', $jar, $ver); $loc = erlauben($v, $jar); parse_str((string)parse_url((string)$loc, PHP_URL_QUERY), $q);
$tok3 = http('POST', '/oauth/token', ['grant_type' => 'authorization_code', 'code' => $q['code'], 'redirect_uri' => RED, 'client_id' => $v['client'], 'code_verifier' => $ver], [], null, true)['json']['access_token'];
$st = http('GET', '/mcp-admin.php', null, [], $jar)['json'];
adminPost($jar, $st['csrf'], ['aktion' => 'meine_trennen']);
pruef(rpc($tok3, 'tools/list')['code'] === 401, 'LK trennt selbst im Profil');
adminPost($jarA, $stA['csrf'], ['aktion' => 'speichern', 'einstellungen' => ['aktiv' => false]]);
$v = verbinden('sabine.demo@demo.mate.test', $jar, $ver); pruef(str_contains($v['seite']['text'], 'ausgeschaltet'), 'ausgeschaltet: keine neue Verbindung');
} finally {
    proc_terminate($srv);
}
$log = @file_get_contents('/tmp/mate-mcp-test/server.log') ?: '';
$warn = preg_match_all('/PHP (Warning|Notice|Deprecated|Fatal)[^\n]*/', $log, $mm);
pruef($warn === 0, 'keine PHP-Warnungen im Server-Log' . ($warn ? ': ' . implode(' | ', array_slice(array_unique($mm[0]), 0, 3)) : ''));
echo "\n$ok bestanden, $fehler fehlgeschlagen\n";
exit($fehler ? 1 : 0);
