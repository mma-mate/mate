<?php
declare(strict_types=1);
/**
 * Test-Anbindung (nur lokal): SQLite + JSON-Datei mit erfundenen Daten.
 * Erfüllt denselben Vertrag wie src/mcp/anbindung.php.
 */

const MCPT_DIR = '/tmp/mate-mcp-test';

function mcp_pdo(): PDO
{
    static $p = null;
    if ($p) return $p;
    $p = new PDO('sqlite:' . MCPT_DIR . '/db.sqlite');
    $p->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    return $p;
}

function mcp_cfg(string $k, $standard = null) { return $standard; }

function mcp_sitzung_starten(): void { if (session_status() !== PHP_SESSION_ACTIVE) { session_save_path(MCPT_DIR); session_name('MATESID'); session_start(); } }

function mcp_sitzung_benutzer(): ?array { $id = (int)($_SESSION['uid'] ?? 0); return $id ? mcp_benutzer($id) : null; }

function t_daten(): array { return json_decode(file_get_contents(MCPT_DIR . '/daten.json'), true); }
function t_speichern(array $d): void { file_put_contents(MCPT_DIR . '/daten.json', json_encode($d, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)); }

function mcp_anmelden(string $kennung, string $pw): array
{
    foreach (t_daten()['users'] as $u) {
        if (strcasecmp($u['mail'], $kennung) === 0 && $pw === 'test1234') {
            if (($u['pw'] ?? 'eigen') !== 'eigen') return ['ok' => false, 'meldung' => 'Melde dich zuerst in MATE an und leg dein eigenes Passwort fest.'];
            session_regenerate_id(true);
            $_SESSION['uid'] = $u['db'];
            return ['ok' => true, 'meldung' => ''];
        }
    }
    return ['ok' => false, 'meldung' => 'E-Mail oder Passwort stimmt nicht.'];
}

function mcp_benutzer(int $id): ?array
{
    foreach (t_daten()['users'] as $u) if ($u['db'] === $id)
        return ['id' => $u['db'], 'app_id' => $u['id'], 'vn' => $u['vn'], 'nn' => $u['nn'], 'rolle' => $u['rolle'], 'admin' => !empty($u['admin']), 'aktiv' => $u['aktiv'] ?? true];
    return null;
}

function mcp_lehrkraefte(): array
{
    $a = [];
    foreach (t_daten()['users'] as $u) if ($u['rolle'] === 'l') $a[] = ['id' => $u['db'], 'name' => $u['vn'] . ' ' . $u['nn'], 'mail' => $u['mail']];
    return $a;
}

function t_app_id(int $lk): string { return mcp_benutzer($lk)['app_id']; }

function mcp_kurse(int $lk): array { $me = t_app_id($lk); return array_values(array_filter(t_daten()['kurse'], fn($k) => in_array($me, $k['lehrer'], true))); }
function mcp_aufgaben(string $kurs): array { return array_values(array_filter(t_daten()['aufgaben'], fn($a) => $a['kurs'] === $kurs)); }
function mcp_aufgabe(string $id): ?array { foreach (t_daten()['aufgaben'] as $a) if ($a['id'] === $id) return $a; return null; }

function mcp_aufgabe_speichern(array $a, int $lk, bool $neu): void
{
    $d = t_daten();
    $k = null; foreach ($d['kurse'] as $x) if ($x['id'] === $a['kurs']) $k = $x;
    if (!$k || !in_array(t_app_id($lk), $k['lehrer'], true)) throw new RuntimeException('Rechte');
    if ($neu) $d['aufgaben'][] = $a;
    else foreach ($d['aufgaben'] as $i => $x) if ($x['id'] === $a['id']) $d['aufgaben'][$i] = $a;
    t_speichern($d);
}

function mcp_termine(int $lk): array
{
    $ids = array_column(mcp_kurse($lk), 'id');
    return array_values(array_filter(t_daten()['termine'], fn($t) => ($t['ebene'] ?? 'kurs') !== 'kurs' || in_array($t['kurs'], $ids, true)));
}

function mcp_termin_speichern(array $t, int $lk, bool $neu): void
{
    $d = t_daten();
    if ($neu) $d['termine'][] = $t;
    else foreach ($d['termine'] as $i => $x) if ($x['id'] === $t['id']) $d['termine'][$i] = $t;
    t_speichern($d);
}

function mcp_schueler(array $appIds): array
{
    $a = [];
    foreach (t_daten()['users'] as $u) if (in_array($u['id'], $appIds, true)) $a[$u['id']] = ['vn' => $u['vn'], 'nn' => $u['nn'], 'klasse' => $u['klasse'] ?? ''];
    return $a;
}

function mcp_datei(array $a, string $kind, array $datei): ?array
{
    $f = MCPT_DIR . '/dateien/' . ($datei['h'] ?? '');
    if (!is_file($f)) return null;
    $mime = str_ends_with($f, '.pdf') ? 'application/pdf' : (str_ends_with($f, '.png') ? 'image/png' : 'image/jpeg');
    return ['mime' => $mime, 'daten' => file_get_contents($f)];
}
