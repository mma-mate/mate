<?php
// Integrationstest gegen den ECHTEN MATE-Server-Code (lokal, SQLite, Demo-Daten aus bin/demo-daten.php)
// Aufruf: php lauf-server.php <server-ordner>   (Server läuft auf 127.0.0.1:8098 mit router.php)
declare(strict_types=1);
const B = 'http://127.0.0.1:8098';
const RED = 'https://claude.ai/api/mcp/auth_callback';
const PW = 'Demo-Passwort-2026';
$ordner = $argv[1];
$fehler = 0; $ok = 0;
function pruef(bool $b, string $t): void { global $fehler, $ok; if ($b) { $ok++; echo "  ✓ $t\n"; } else { $fehler++; echo "  ✗ $t\n"; } }
function http(string $m, string $url, $body = null, array $kopf = [], ?string $jar = null, string $art = 'json'): array
{
    $c = curl_init(B . $url); $h = $kopf;
    if ($body !== null) {
        if ($art === 'form') { $body = http_build_query($body); $h[] = 'Content-Type: application/x-www-form-urlencoded'; }
        elseif ($art === 'json') { $body = json_encode($body); $h[] = 'Content-Type: application/json'; }
        curl_setopt($c, CURLOPT_POSTFIELDS, $body);
    }
    curl_setopt_array($c, [CURLOPT_CUSTOMREQUEST => $m, CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_HTTPHEADER => $h]);
    if ($jar) { curl_setopt($c, CURLOPT_COOKIEJAR, $jar); curl_setopt($c, CURLOPT_COOKIEFILE, $jar); }
    $r = curl_exec($c); $code = curl_getinfo($c, CURLINFO_HTTP_CODE); $hs = curl_getinfo($c, CURLINFO_HEADER_SIZE);
    return ['code' => $code, 'kopf' => substr($r, 0, $hs), 'text' => substr($r, $hs), 'json' => json_decode(substr($r, $hs), true)];
}
function versteckt(string $html): array { preg_match_all('/<input type="hidden" name="([^"]+)" value="([^"]*)">/', $html, $m, PREG_SET_ORDER); $a = []; foreach ($m as $x) $a[$x[1]] = html_entity_decode($x[2]); return $a; }
function app_login(string $mail, string $jar): array { $r = http('POST', '/api/login', ['kennung' => $mail, 'passwort' => PW], [], $jar); return $r['json'] ?? []; }
function verbinden(string $mail, string $jar, ?string &$ver): array
{
    $cl = http('POST', '/oauth/register', ['client_name' => 'Claude', 'redirect_uris' => [RED]])['json']['client_id'];
    $ver = rtrim(strtr(base64_encode(random_bytes(40)), '+/', '-_'), '=');
    $ch = rtrim(strtr(base64_encode(hash('sha256', $ver, true)), '+/', '-_'), '=');
    $q = http_build_query(['response_type' => 'code', 'client_id' => $cl, 'redirect_uri' => RED, 'code_challenge' => $ch, 'code_challenge_method' => 'S256', 'state' => 's1']);
    $s = http('GET', '/oauth/authorize?' . $q, null, [], $jar);
    if (str_contains($s['text'], 'value="login"')) { $f = versteckt($s['text']); $f['kennung'] = $mail; $f['passwort'] = PW; $s = http('POST', '/oauth/authorize', $f, [], $jar, 'form'); }
    return ['client' => $cl, 'seite' => $s];
}
function token(array $v, string $jar, string $ver): string
{
    $f = versteckt($v['seite']['text']); $f['schritt'] = 'erlauben';
    $r = http('POST', '/oauth/authorize', $f, [], $jar, 'form');
    preg_match('/Location: (\S+)/i', $r['kopf'], $m); parse_str((string)parse_url($m[1] ?? '', PHP_URL_QUERY), $q);
    return http('POST', '/oauth/token', ['grant_type' => 'authorization_code', 'code' => $q['code'] ?? '', 'redirect_uri' => RED, 'client_id' => $v['client'], 'code_verifier' => $ver], [], null, 'form')['json']['access_token'] ?? '';
}
function tool(string $tok, string $n, array $a = []): array
{
    $r = http('POST', '/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => $n, 'arguments' => (object)$a]], ['Authorization: Bearer ' . $tok]);
    return $r['json']['result'] ?? ['fehlt' => $r['code'] . ' ' . substr($r['text'], 0, 300), 'isError' => true, 'content' => [['text' => $r['text']]]];
}
$pdo = new PDO('sqlite:' . $ordner . '/storage/test.sqlite');
$id = fn(string $mail) => $pdo->query("SELECT id FROM benutzer WHERE mail = '$mail'")->fetchColumn();
$sabine = $id('sabine.demo@demo.mate.test'); $lena = $id('lena.bauer@demo.mate.test'); $petra = $id('petra.probe@demo.mate.test');
$tmp = sys_get_temp_dir() . '/mate-int'; @mkdir($tmp);
foreach (glob("$tmp/*") as $f) unlink($f);

echo "Setup über die echte App-Schnittstelle\n";
$jarS = "$tmp/sabine-app.jar";
$ich = app_login('sabine.demo@demo.mate.test', $jarS); pruef(!empty($ich['csrf']), 'Sabine meldet sich in der App an');
$sync = http('GET', '/api/sync', null, [], $jarS)['json'];
$kursE = array_values(array_filter($sync['kurse'], fn($k) => $k['fach'] === 'Englisch'))[0];
$aufgE = array_values(array_filter($sync['aufgaben'], fn($a) => $a['kurs'] === $kursE['id'] && str_starts_with($a['titel'], 'Unit 2')))[0];
// Lena gibt mit Foto ab (Upload + Sync wie die App)
$jarL = "$tmp/lena.jar"; $l = app_login('lena.bauer@demo.mate.test', $jarL);
$img = imagecreatetruecolor(300, 200); imagefill($img, 0, 0, imagecolorallocate($img, 240, 240, 255)); imagestring($img, 5, 30, 90, 'Workbook S.18 Lena', 0); imagejpeg($img, "$tmp/foto.jpg");
$up = http('POST', '/api/upload', ['ziel' => "s:{$aufgE['id']}:$lena", 'dateien[]' => new CURLFile("$tmp/foto.jpg", 'image/jpeg', 'foto.jpg')], ['X-CSRF-Token: ' . $l['csrf']], $jarL, 'multipart');
$datei = $up['json']['dateien'][0] ?? null; pruef((bool)$datei, 'Lena lädt ein Foto hoch');
$jetzt = (int)(microtime(true) * 1000);
$r = http('POST', '/api/sync', ['aenderungen' => [['key' => "s:{$aufgE['id']}:$lena", 'op' => 'aendern', 'set' => ['files' => [['n' => 'foto.jpg', 'kb' => $datei['kb'], 'url' => $datei['url'], 'zeit' => $jetzt]], 'zeit' => $jetzt], 'unset' => []],
    ['key' => "m:{$aufgE['id']}:$lena:s" . base_convert((string)$jetzt, 10, 36), 'op' => 'neu', 'set' => ['von' => 's', 'text' => 'Ist Übung 5 Pflicht?', 'zeit' => $jetzt], 'unset' => []]]], ['X-CSRF-Token: ' . $l['csrf']], $jarL);
pruef(($r['json']['ergebnisse'][0]['status'] ?? '') === 'ok' && ($r['json']['ergebnisse'][1]['status'] ?? '') === 'ok', 'Lena gibt ab und stellt eine Frage (Sync)');

echo "Tabellen und Admin\n";
$jarA = "$tmp/admin.jar";
$v = verbinden('chefin@schule.test', $jarA, $ver);   // keine Demo-Adresse: Passwort anders
$f = versteckt($v['seite']['text']); $f['kennung'] = 'chefin@schule.test'; $f['passwort'] = 'Kiwi-Lama-Mond-77';
// Admin-API braucht die normale App-Sitzung
$jarAA = "$tmp/admin-app.jar"; http('POST', '/api/login', ['kennung' => 'chefin@schule.test', 'passwort' => 'Kiwi-Lama-Mond-77'], [], $jarAA);
$r = http('GET', '/mcp-setup.php', null, [], $jarAA); pruef(str_contains($r['text'], 'Tabellen bereit'), 'mcp-setup.php legt die Tabellen an (als Admin)');
$st = http('GET', '/mcp-admin.php', null, [], $jarAA)['json'];
pruef(count($st['werkzeuge'] ?? []) === 13 && count($st['lehrkraefte']) === 4, 'Admin-Schnittstelle: 13 Werkzeuge, 4 LK');
$r = http('POST', '/mcp-admin.php', ['aktion' => 'speichern', 'einstellungen' => ['aktiv' => true, 'werkzeuge' => array_fill_keys(array_column($st['werkzeuge'], 'name'), true), 'lk' => [$sabine, $lena]]], ['X-MATE-MCP-CSRF: ' . $st['csrf']], $jarAA);
pruef(($r['json']['einstellungen']['lk'] ?? null) === [$sabine], 'Sabine freigeschaltet (Kind wird ignoriert)');
$r = http('GET', '/mcp-admin.php', null, [], $jarS)['json']; pruef(!isset($r['einstellungen']) && $r['ich']['freigeschaltet'], 'Sabine (LK) sieht nur ihren Stand');

echo "Anmeldung über Claude\n";
$jar = "$tmp/sabine-oauth.jar";
$v = verbinden('petra.probe@demo.mate.test', "$tmp/petra.jar", $verP); pruef(str_contains($v['seite']['text'], 'nicht freigeschaltet'), 'Petra (nicht freigeschaltet): kein Zugang');
$v = verbinden('lena.bauer@demo.mate.test', "$tmp/lena-o.jar", $verL); pruef(str_contains($v['seite']['text'], 'nur für Lehrkräfte'), 'Kind: kein Zugang');
$v = verbinden('sabine.demo@demo.mate.test', $jar, $ver); pruef(str_contains($v['seite']['text'], 'Erlauben'), 'Sabine: Erlauben-Seite');
$tok = token($v, $jar, $ver); pruef($tok !== '', 'Token erhalten');
$r = http('GET', '/api/ich', null, [], $jarS); pruef($r['code'] === 200, 'App-Sitzung von Sabine bleibt dabei angemeldet');

echo "Werkzeuge mit echten Daten\n";
$x = tool($tok, 'kurse_auflisten'); pruef(count($x['structuredContent']['kurse'] ?? []) === 1 && $x['structuredContent']['kurse'][0]['id'] === $kursE['id'], 'kurse_auflisten: nur Englisch (Sabine)');
$kursM = $pdo->query("SELECT id FROM kurse WHERE fach = 'Mathematik'")->fetchColumn();
$x = tool($tok, 'aufgaben_auflisten', ['kurs_id' => $kursM]); pruef(!empty($x['isError']), 'fremder Kurs (Mathe von Petra): Fehler');
$x = tool($tok, 'aufgaben_auflisten', ['kurs_id' => $kursE['id']]); $liste = $x['structuredContent']['aufgaben'] ?? [];
pruef(count($liste) === 2, 'aufgaben_auflisten: 2 Aufgaben in Englisch');
$x = tool($tok, 'termine_auflisten', ['von' => date('Y-m-d', strtotime('-60 days')), 'bis' => date('Y-m-d', strtotime('+120 days'))]);
pruef(in_array('1. Schularbeit Englisch', array_column($x['structuredContent']['termine'] ?? [], 'titel'), true), 'termine_auflisten: Schularbeit Englisch');
$x = tool($tok, 'aufgabe_anlegen', ['kurs_id' => $kursE['id'], 'titel' => 'Vokabeln Unit 3', 'frist_datum' => date('Y-m-d', strtotime('+5 days')), 'sichtbar' => 'spaeter', 'beschreibung' => '**Lernen**']);
$neu = $x['structuredContent'] ?? []; pruef(!empty($neu['id']) && $neu['nr'] >= 1, 'aufgabe_anlegen über den Sync (Nr. ' . ($neu['nr'] ?? '?') . ')' . (!empty($x['isError']) ? ' – ' . $x['content'][0]['text'] : ''));
$row = $pdo->query("SELECT * FROM aufgaben WHERE id = '" . ($neu['id'] ?? '') . "'")->fetch(PDO::FETCH_ASSOC);
pruef($row && $row['titel'] === 'Vokabeln Unit 3' && $row['erstellt_von'] === $sabine && $row['kurs_id'] === $kursE['id'], 'in der Datenbank wie aus der App (erstellt_von, kurs)');
$x = tool($tok, 'aufgabe_aendern', ['aufgabe_id' => $neu['id'] ?? '', 'sichtbar' => 'sofort', 'titel' => 'Vokabeln Unit 3 (Seite 40)']); pruef(empty($x['isError']) && ($x['structuredContent']['sichtbar'] ?? '') === 'sichtbar', 'aufgabe_aendern' . (!empty($x['isError']) ? ' – ' . $x['content'][0]['text'] : ''));
$x = tool($tok, 'termin_anlegen', ['kurs_id' => $kursE['id'], 'art' => 'pruefung', 'titel' => 'Vokabeltest', 'datum' => date('Y-m-d', strtotime('+9 days')), 'einheit_von' => 2]);
$tn = $x['structuredContent'] ?? []; pruef(!empty($tn['id']), 'termin_anlegen' . (!empty($x['isError']) ? ' – ' . $x['content'][0]['text'] : ''));
$row = $pdo->query("SELECT * FROM termine WHERE id = '" . ($tn['id'] ?? '') . "'")->fetch(PDO::FETCH_ASSOC); pruef($row && $row['autor_id'] === $sabine && $row['ebene'] === 'kurs', 'Termin in der Datenbank mit Autorin Sabine');
$x = tool($tok, 'termin_aendern', ['termin_id' => $tn['id'] ?? '', 'titel' => 'Vokabeltest Unit 3']); pruef(empty($x['isError']), 'termin_aendern' . (!empty($x['isError']) ? ' – ' . $x['content'][0]['text'] : ''));

$x = tool($tok, 'abgaben_auflisten', ['aufgabe_id' => $aufgE['id']]); $ab = array_column($x['structuredContent']['abgaben'] ?? [], null, 'schueler_id');
pruef(count($ab) === count($kursE['schueler']) && in_array($ab[$lena]['status'] ?? '', ['abgegeben', 'verspätet'], true) && $ab[$lena]['wartet_auf_antwort'] && $ab[$lena]['name'] === 'Lena Bauer', 'abgaben_auflisten: Lenas Abgabe mit Frage');
$x = tool($tok, 'abgabe_lesen', ['aufgabe_id' => $aufgE['id'], 'schueler_id' => $lena]);
pruef(($x['content'][1]['type'] ?? '') === 'image', 'abgabe_lesen: Lenas Foto aus storage/uploads wird mitgeschickt');
$x = tool($tok, 'bewertung_eintragen', ['aufgabe_id' => $aufgE['id'], 'schueler_id' => $lena, 'bewertung' => '8,5']); pruef(empty($x['isError']), 'bewertung_eintragen 8,5' . (!empty($x['isError']) ? ' – ' . $x['content'][0]['text'] : ''));
$x = tool($tok, 'feedback_senden', ['aufgabe_id' => $aufgE['id'], 'schueler_id' => $lena, 'text' => 'Ja, Übung 5 ist Pflicht. Sonst super!']); pruef(empty($x['isError']), 'feedback_senden' . (!empty($x['isError']) ? ' – ' . $x['content'][0]['text'] : ''));
$x = tool($tok, 'bewertungen_freigeben', ['aufgabe_id' => $aufgE['id'], 'freigeben' => true]); pruef(empty($x['isError']), 'bewertungen_freigeben' . (!empty($x['isError']) ? ' – ' . $x['content'][0]['text'] : ''));

echo "Was die App danach sieht\n";
$sL = http('GET', '/api/sync', null, [], $jarL)['json'];
$aL = array_values(array_filter($sL['aufgaben'], fn($a) => $a['id'] === $aufgE['id']))[0];
$subL = $aL['subs'][$lena] ?? [];
pruef(($subL['score'] ?? null) == 8.5 && end($subL['msgs'])['von'] === 'l' && end($subL['msgs'])['u'] === $sabine && !empty($subL['sNeu']), 'Lena sieht 8,5 Punkte und das Feedback von Sabine');
pruef(in_array($neu['id'] ?? '-', array_column($sL['aufgaben'], 'id'), true), 'Lena sieht die neue, sofort sichtbare Aufgabe');
$sS = http('GET', '/api/sync', null, [], $jarS)['json'];
$aS = array_values(array_filter($sS['aufgaben'], fn($a) => $a['id'] === $aufgE['id']))[0];
pruef(!empty($aS['freigegeben']) && count($aS['subs'][$lena]['msgs']) === 2, 'Sabines App: freigegeben, 2 Nachrichten');
$x = tool($tok, 'abgabe_lesen', ['aufgabe_id' => $aufgE['id'], 'schueler_id' => $lena, 'dateien' => false]); pruef(count($x['structuredContent']['feedback_verlauf'] ?? []) === 2, 'abgabe_lesen danach: Verlauf mit 2 Nachrichten');

echo "Trennen\n";
$st = http('GET', '/mcp-admin.php', null, [], $jarAA)['json'];
pruef(count($st['verbindungen']) === 1 && count($st['protokoll']) >= 14, 'Admin sieht Verbindung und Protokoll');
http('POST', '/mcp-admin.php', ['aktion' => 'speichern', 'einstellungen' => ['lk' => []]], ['X-MATE-MCP-CSRF: ' . $st['csrf']], $jarAA);
$r = http('POST', '/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'], ['Authorization: Bearer ' . $tok]); pruef($r['code'] === 401, 'Freischaltung entzogen → 401');
echo "\n$ok bestanden, $fehler fehlgeschlagen\n";
exit($fehler ? 1 : 0);
