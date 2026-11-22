<?php
declare(strict_types=1);
/**
 * MATE – Claude-Connector: Anbindung an den MATE-Server (Server-Paket 0.3.2)
 *
 * Lesen und Speichern laufen über dieselben Funktionen wie die App:
 *   - Lesen:     sync_laden($benutzer)  – genau das, was die LK in der App sieht
 *   - Speichern: sync_aendern($änderung, $benutzer) – dieselben Rechteprüfungen wie beim Sync der App
 * Dateien kommen aus storage/uploads, nur wenn sie zur Abgabe gehören (Tabelle dateien, ziel s:<Aufgabe>:<Kind>).
 *
 * Vertrag (test/anbindung-test.php verhält sich gleich):
 *  mcp_pdo(), mcp_cfg(), mcp_sitzung_starten(bool $oauth), mcp_sitzung_benutzer(), mcp_anmelden(),
 *  mcp_benutzer(string $id), mcp_lehrkraefte(), mcp_kurse(string $lk), mcp_aufgaben(string $kurs),
 *  mcp_aufgabe(string $id), mcp_aufgabe_speichern(array $a, string $lk, bool $neu), mcp_termine(string $lk),
 *  mcp_termin_speichern(array $t, string $lk, bool $neu), mcp_schueler(array $ids), mcp_datei(array $a, string $kind, array $datei)
 */

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/sync.php';
require_once dirname(__DIR__) . '/sync_laden.php';
require_once dirname(__DIR__) . '/sync_speichern.php';

function mcp_pdo(): PDO { return db(); }

function mcp_cfg(string $k, $standard = null) { return cfg($k, $standard); }

/**
 * Normale MATE-Sitzung (mcp-admin.php, gleicher Cookie wie die App) oder eigene Sitzung für die
 * Anmeldeseite des Connectors: Claude leitet von claude.ai dorthin weiter, und der App-Cookie ist
 * „SameSite=Strict“ – er würde nicht mitgeschickt und die App-Sitzung würde überschrieben.
 */
function mcp_sitzung_starten(bool $oauth = false): void
{
    if (!$oauth) { sitzung_starten(); return; }
    if (session_status() === PHP_SESSION_ACTIVE) return;
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    session_name((string)cfg('session_name', 'mate') . '_claude');
    session_save_path(storage('sessions'));
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_set_cookie_params(['lifetime' => 0, 'path' => '/oauth/', 'secure' => $https, 'httponly' => true, 'samesite' => 'Lax']);
    session_start();
    if (isset($_SESSION['zuletzt']) && time() - $_SESSION['zuletzt'] > 30 * 60) { $_SESSION = []; session_regenerate_id(true); }
    $_SESSION['zuletzt'] = time();
}

function mcp_sitzung_benutzer(): ?array
{
    $b = aktueller_benutzer();              // prüft aktiv und Passwortstand
    return $b ? mcp_benutzer_aus_zeile($b) : null;
}

function mcp_anmelden(string $kennung, string $pw): array
{
    try {
        $b = anmelden($kennung, $pw, false); // gleiche Sperre bei Fehlversuchen wie die App
    } catch (ApiFehler $e) {
        return ['ok' => false, 'meldung' => $e->getMessage()];
    }
    if (muss_passwort_wechseln($b)) {
        $_SESSION = [];
        return ['ok' => false, 'meldung' => 'Melde dich zuerst in MATE an und leg dein eigenes Passwort fest.'];
    }
    return ['ok' => true, 'meldung' => ''];
}

function mcp_benutzer_aus_zeile(array $r): array
{
    return ['id' => (string)$r['id'], 'app_id' => (string)$r['id'], 'vn' => (string)$r['vorname'], 'nn' => (string)$r['nachname'],
            'rolle' => $r['rolle'] === 's' ? 's' : 'l', 'admin' => (bool)(int)$r['ist_admin'],
            'aktiv' => (bool)(int)$r['aktiv'] && !muss_passwort_wechseln($r), 'zeile' => $r];
}

function mcp_benutzer(string $id): ?array
{
    if ($id === '') return null;
    $r = q_eins('SELECT * FROM benutzer WHERE id = ?', [$id]);
    return $r ? mcp_benutzer_aus_zeile($r) : null;
}

function mcp_lehrkraefte(): array
{
    $aus = [];
    foreach (q_alle("SELECT id, vorname, nachname, mail FROM benutzer WHERE rolle = 'l' AND aktiv = 1 ORDER BY nachname, vorname") as $r)
        $aus[] = ['id' => (string)$r['id'], 'name' => $r['vorname'] . ' ' . $r['nachname'], 'mail' => (string)$r['mail']];
    return $aus;
}

/* ---------- Daten wie in der App (pro Anfrage einmal geladen) ---------- */

function mcp_stand(string $lk, bool $neu = false): array
{
    static $cache = [];
    if ($neu) { unset($cache[$lk]); return []; }
    if (!isset($cache[$lk])) {
        $b = q_eins('SELECT * FROM benutzer WHERE id = ?', [$lk]);
        if (!$b) throw new McpFehler('Konto nicht gefunden.');
        $d = json_decode(json_encode(sync_laden($b), JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION), true);
        $cache[$lk] = [
            'kurse'    => array_values(array_filter($d['kurse'] ?? [], fn($k) => empty($k['fremd']) && in_array($lk, $k['lehrer'] ?? [], true))),
            'aufgaben' => $d['aufgaben'] ?? [],
            'termine'  => $d['termine'] ?? [],
            'b'        => $b,
        ];
    }
    return $cache[$lk];
}

/** LK des laufenden Werkzeug-Aufrufs (setzt mcp_werkzeug_ausfuehren) */
function mcp_ich(): string { return (string)($GLOBALS['mcp_lk'] ?? ''); }

function mcp_kurse(string $lk): array { return mcp_stand($lk)['kurse']; }

/** Aufgaben eines Kurses – nur aus den Kursen der aktuellen LK (sync_laden liefert nur eigene) */
function mcp_aufgaben(string $kurs): array
{
    if (mcp_ich() === '') return [];
    return array_values(array_filter(mcp_stand(mcp_ich())['aufgaben'], fn($a) => ($a['kurs'] ?? '') === $kurs));
}

function mcp_aufgabe(string $id): ?array
{
    if (mcp_ich() === '') return null;
    foreach (mcp_stand(mcp_ich())['aufgaben'] as $a) if (($a['id'] ?? '') === $id) return $a;
    return null;
}

function mcp_termine(string $lk): array { return mcp_stand($lk)['termine']; }

function mcp_schueler(array $ids): array
{
    $ids = array_values(array_filter(array_map('strval', $ids)));
    if (!$ids) return [];
    $aus = [];
    foreach (zeilen_in("SELECT id, vorname, nachname, klasse_id FROM benutzer WHERE rolle = 's' AND id IN (%s)", $ids) as $r)
        $aus[(string)$r['id']] = ['vn' => $r['vorname'], 'nn' => $r['nachname'], 'klasse' => kl_name($r['klasse_id']) ?? ''];
    return $aus;
}

/* ---------- Speichern über den Sync der App ---------- */

/** Wert für den Sync: assoziative Arrays → Objekte, Listen bleiben Listen */
function mcp_obj($v)
{
    if (!is_array($v)) return $v;
    if ($v === []) return [];
    if (array_is_list($v)) return array_map('mcp_obj', $v);
    $o = new stdClass;
    foreach ($v as $k => $x) $o->$k = mcp_obj($x);
    return $o;
}

/** Änderung {key, op, set, unset} zwischen altem und neuem Stand (nur oberste Ebene, wie mate-server.js) */
function mcp_aenderung(string $key, ?array $alt, array $neu, array $ohne): ?stdClass
{
    foreach ($ohne as $f) unset($neu[$f]);
    if ($alt !== null) foreach ($ohne as $f) unset($alt[$f]);
    $set = new stdClass; $unset = [];
    foreach ($neu as $k => $v) {
        if ($alt !== null && array_key_exists($k, $alt) && json_encode($alt[$k]) === json_encode($v)) continue;
        $set->$k = mcp_obj($v);
    }
    if ($alt !== null) foreach ($alt as $k => $v) if (!array_key_exists($k, $neu)) $unset[] = (string)$k;
    if ($alt !== null && !get_object_vars($set) && !$unset) return null;
    return (object)['key' => $key, 'op' => $alt === null ? 'neu' : 'aendern', 'set' => $set, 'unset' => $unset];
}

function mcp_sync(array $aenderungen, string $lk): void
{
    $b = mcp_stand($lk)['b'];
    transaktion(function () use ($aenderungen, $b) {
        foreach ($aenderungen as $c) {
            try {
                $e = sync_aendern($c, $b);
            } catch (ApiFehler $ex) {
                throw new McpFehler('MATE hat die Änderung abgelehnt: ' . $ex->getMessage());
            }
            if ($e->status !== 'ok') throw new McpFehler('MATE hat die Änderung nicht übernommen (keine Berechtigung).');
        }
    });
    mcp_stand($lk, true);   // beim nächsten Lesen frisch laden
}

const MCP_LEERE_ABGABE = ['files' => [], 'score' => null, 'abwesend' => false, 'feedback' => '', 'zeit' => null];

function mcp_aufgabe_speichern(array $a, string $lk, bool $neu): void
{
    $alt = null;
    if (!$neu) foreach (mcp_stand($lk)['aufgaben'] as $x) if ($x['id'] === $a['id']) $alt = $x;
    if (!$neu && $alt === null) throw new McpFehler('Die Aufgabe gibt es nicht mehr.');
    $liste = [];
    $c = mcp_aenderung('a:' . $a['id'], $alt, $a, ['subs']);
    if ($c) $liste[] = $c;
    foreach (($a['subs'] ?? []) as $uid => $s) {
        $uid = (string)$uid;
        $altS = $alt['subs'][$uid] ?? null;
        $vergleich = $altS ?? MCP_LEERE_ABGABE;
        $sNeu = $s; unset($sNeu['msgs']);
        $sAlt = $vergleich; unset($sAlt['msgs']);
        if (json_encode($sNeu) !== json_encode($sAlt)) {
            $c = mcp_aenderung("s:{$a['id']}:$uid", $altS === null ? [] : $sAlt, $sNeu, []);
            if ($c) { $c->op = 'aendern'; $liste[] = $c; }
        }
        $altIds = array_column($altS['msgs'] ?? [], 'id');
        foreach ($s['msgs'] ?? [] as $m) {
            if (!isset($m['id']) || in_array($m['id'], $altIds, true)) continue;
            $mm = $m; unset($mm['id']);
            $liste[] = (object)['key' => "m:{$a['id']}:$uid:{$m['id']}", 'op' => 'neu', 'set' => mcp_obj($mm), 'unset' => []];
        }
    }
    if ($liste) mcp_sync($liste, $lk);
}

function mcp_termin_speichern(array $t, string $lk, bool $neu): void
{
    $alt = null;
    if (!$neu) foreach (mcp_stand($lk)['termine'] as $x) if ($x['id'] === $t['id']) $alt = $x;
    if (!$neu && $alt === null) throw new McpFehler('Den Termin gibt es nicht mehr.');
    $c = mcp_aenderung('t:' . $t['id'], $alt, $t, []);
    if ($c) mcp_sync([$c], $lk);
}

/* ---------- Dateien einer Abgabe ---------- */

function mcp_datei(array $a, string $kind, array $datei): ?array
{
    if (!preg_match('~api/dateien/([A-Za-z0-9_-]{4,40})~', (string)($datei['url'] ?? ''), $m)) return null;
    $d = q_eins('SELECT * FROM dateien WHERE id = ?', [$m[1]]);
    if (!$d || $d['ziel'] !== 's:' . $a['id'] . ':' . $kind) return null;   // nur Dateien dieser Abgabe
    $voll = storage('uploads') . '/' . $d['pfad'];
    if (!is_file($voll)) return null;
    return ['mime' => (string)$d['mime'], 'daten' => (string)file_get_contents($voll)];
}
