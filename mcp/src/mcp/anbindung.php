<?php
declare(strict_types=1);
/**
 * MATE – Claude-Connector: Anbindung an den bestehenden MATE-Server
 *
 * STAND 20. 11. 2026: ENTWURF. Der Server-Code (src/auth.php, src/sync.php, src/sync_laden.php, Datenbank-Hilfen)
 * liegt nicht im App-Repo. Vor dem Einspielen am Dev liest Claude diese Dateien über den Webspace-Explorer
 * und füllt die Funktionen unten passend aus. Alles andere (kern.php, werkzeuge.php, die Endpunkte)
 * bleibt unverändert.
 *
 * Vertrag – genau so verhält sich test/anbindung-test.php (die Tests laufen dagegen):
 *
 *  mcp_pdo(): PDO                       Datenbank von MATE (für die mcp_*-Tabellen)
 *  mcp_cfg(string $k, $standard)        Wert aus der MATE-Konfiguration
 *  mcp_sitzung_starten(): void          MATE-Sitzung starten (gleicher Cookie wie die App)
 *  mcp_sitzung_benutzer(): ?array       angemeldete Person der MATE-Sitzung (Form wie mcp_benutzer)
 *  mcp_anmelden($kennung, $pw): array   ['ok'=>bool, 'meldung'=>string]; bei ok ist danach die Sitzung angemeldet.
 *                                       Konten mit Startpasswort oder zurückgesetztem Passwort: ok=false
 *                                       („Melde dich zuerst in MATE an und leg dein eigenes Passwort fest.“)
 *  mcp_benutzer(int $id): ?array        ['id'=>int (DB), 'app_id'=>string (ID in den App-Daten), 'vn', 'nn',
 *                                        'rolle'=>'l'|'s', 'admin'=>bool, 'aktiv'=>bool]
 *  mcp_lehrkraefte(): array             alle aktiven LK: [['id'=>int,'name'=>string,'mail'=>string], …] (für den Admin)
 *  mcp_kurse(int $lk): array            alle Kurse, in denen die LK Lehrkraft ist (auch archivierte), als App-Objekte
 *  mcp_aufgaben(string $kurs): array    alle Aufgaben des Kurses als App-Objekte INKL. subs (Abgaben, Bewertungen, msgs)
 *  mcp_aufgabe(string $id): ?array      eine Aufgabe inkl. subs
 *  mcp_aufgabe_speichern(array $a, int $lk, bool $neu): void
 *                                       speichert wie ein Sync der LK: dieselben Prüfungen und Nebenwirkungen
 *                                       (Version hochzählen, damit offene Apps neu laden)
 *  mcp_termine(int $lk): array          alle Termine, die die LK in der App sieht (Kurs, Klasse, Schule)
 *  mcp_termin_speichern(array $t, int $lk, bool $neu): void
 *  mcp_schueler(array $appIds): array   [app_id => ['vn','nn','klasse']]
 *  mcp_datei(array $a, string $kind, array $datei): ?array
 *                                       Inhalt einer Abgabe-Datei: ['mime'=>…, 'daten'=>Bytes] oder null
 */

/* Server-Code von MATE laden (Pfade am Sonntag prüfen) */
require_once __DIR__ . '/../auth.php';      // TODO prüfen: Sitzung, anmelden(), cfg(), Datenbank-Hilfen
require_once __DIR__ . '/../sync.php';      // TODO prüfen: Regeln beim Speichern
require_once __DIR__ . '/../sync_laden.php';

function mcp_pdo(): PDO
{
    return db();                              // TODO: Name der PDO-Funktion im Server-Paket
}

function mcp_cfg(string $k, $standard = null)
{
    return cfg($k, $standard);
}

function mcp_sitzung_starten(): void
{
    sitzung_starten();                        // TODO: Funktion aus src/auth.php (Cookie-Name, „angemeldet bleiben“)
}

function mcp_sitzung_benutzer(): ?array
{
    $id = (int)($_SESSION['benutzer_id'] ?? 0);   // TODO: Sitzungs-Schlüssel aus src/auth.php
    return $id ? mcp_benutzer($id) : null;
}

function mcp_anmelden(string $kennung, string $pw): array
{
    try {
        $b = anmelden($kennung, $pw, false);  // setzt die Sitzung wie die normale Anmeldung (inkl. Sperre bei Fehlversuchen)
    } catch (Throwable $e) {
        return ['ok' => false, 'meldung' => $e->getMessage() ?: 'E-Mail oder Passwort stimmt nicht.'];
    }
    if (in_array($b['pw_status'] ?? '', ['start', 'reset'], true)) {   // TODO: Feldname prüfen
        return ['ok' => false, 'meldung' => 'Melde dich zuerst in MATE an und leg dein eigenes Passwort fest.'];
    }
    return ['ok' => true, 'meldung' => ''];
}

function mcp_benutzer(int $id): ?array
{
    $st = mcp_pdo()->prepare('SELECT * FROM benutzer WHERE id = ?');      // TODO: Spaltennamen prüfen
    $st->execute([$id]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r) return null;
    return ['id' => (int)$r['id'], 'app_id' => (string)($r['app_id'] ?? $r['id']), 'vn' => (string)$r['vorname'], 'nn' => (string)$r['nachname'],
            'rolle' => $r['rolle'] === 's' ? 's' : 'l', 'admin' => !empty($r['admin']), 'aktiv' => !empty($r['aktiv'])];
}

function mcp_lehrkraefte(): array
{
    $aus = [];
    foreach (mcp_pdo()->query("SELECT id, vorname, nachname, email FROM benutzer WHERE rolle <> 's' AND aktiv = 1 ORDER BY nachname, vorname") as $r)
        $aus[] = ['id' => (int)$r['id'], 'name' => $r['vorname'] . ' ' . $r['nachname'], 'mail' => (string)$r['email']];
    return $aus;
}

function mcp_kurse(int $lk): array
{
    // TODO: wie sync_laden.php die Kurse einer LK zusammenstellt (Tabelle kurse + Lehrkräfte + SuS)
    throw new RuntimeException('mcp_kurse: noch nicht an den Server angebunden');
}

function mcp_aufgaben(string $kurs): array
{
    // TODO: Aufgaben + Abgaben (subs) wie in sync_laden.php für eine LK
    throw new RuntimeException('mcp_aufgaben: noch nicht an den Server angebunden');
}

function mcp_aufgabe(string $id): ?array
{
    throw new RuntimeException('mcp_aufgabe: noch nicht an den Server angebunden');
}

function mcp_aufgabe_speichern(array $a, int $lk, bool $neu): void
{
    // TODO: über die Speicher-Funktion von sync.php (Rechte der LK, Version hochzählen)
    throw new RuntimeException('mcp_aufgabe_speichern: noch nicht an den Server angebunden');
}

function mcp_termine(int $lk): array
{
    throw new RuntimeException('mcp_termine: noch nicht an den Server angebunden');
}

function mcp_termin_speichern(array $t, int $lk, bool $neu): void
{
    throw new RuntimeException('mcp_termin_speichern: noch nicht an den Server angebunden');
}

function mcp_schueler(array $appIds): array
{
    throw new RuntimeException('mcp_schueler: noch nicht an den Server angebunden');
}

function mcp_datei(array $a, string $kind, array $datei): ?array
{
    // TODO: Pfad im storage-Ordner (Uploads, 1600 px) – wie die Download-Route der App
    return null;
}
