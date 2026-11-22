<?php
declare(strict_types=1);
/*
 * Täglicher Cronjob (z. B. jede Nacht um 03:15):
 *
 *   /usr/bin/php8.4-cli /pfad/zu/mate-prod/cron/taeglich.php
 *   /usr/bin/php8.4-cli /pfad/zu/mate-prod/cron/taeglich.php --trockenlauf   (zeigt nur, was passieren würde)
 * Auf IONOS ohne SSH-Crontab: über den Cronjob-Manager public/cron.php aufrufen (macht auch die Sicherung).
 *
 * 1. Aufräumen: alte Anmeldeversuche (30 Tage), Protokoll (12 Monate)
 * 2. Löschkonzept (wie in der App): Am Stichtag (Standard 30. September) werden die Abgabe-Dateien früherer
 *    Schuljahre gelöscht (Fotos, Dateien, Korrekturbilder, Anhänge und Bilder im Chat). Bewertungen,
 *    Feedback-Texte, Kurse, Aufgaben und Material der Lehrkräfte bleiben. Außerdem werden erledigte Meldungen
 *    und Feedback aus früheren Schuljahren gelöscht. Vorher (Standard 14 Tage) bekommt der Admin einen Hinweis.
 * 3. Ein Jahr nach Schuljahresende (am Stichtag) wird ein Schuljahr vollständig gelöscht: Kurse mit Aufgaben,
 *    Abgaben, Bewertungen, Chats und Dateien, Termine, Niveaus sowie Konten, die in dem Schuljahr abgemeldet wurden.
 *    Beispiel 2025/26: Dateien am 30.9.2026, alles am 30.9.2027.
 */

if (PHP_SAPI !== 'cli' && !defined('MATE_CRON_WEB')) { http_response_code(404); exit; }
require_once dirname(__DIR__) . '/src/bootstrap.php';
require_once dirname(__DIR__) . '/src/sync.php';

$trocken = in_array('--trockenlauf', $argv, true);
$heute = date('Y-m-d');
$sj = schuljahr();
$sjBeginn = substr($sj, 0, 4) . '-08-01';
$stichtag = substr($sj, 0, 4) . '-' . cfg('loeschen_stichtag', '09-30');
$ausgabe = [];
$sag = function (string $t) use (&$ausgabe) { $ausgabe[] = $t; echo $t . "\n"; };

$sag("MATE Cron $heute (" . (ist_dev() ? 'dev' : 'prod') . ($trocken ? ', TROCKENLAUF' : '') . ')');

// 1. Aufräumen ----------------------------------------------------
if (!$trocken) {
    q('DELETE FROM login_versuche WHERE zeit < ?', [date('Y-m-d H:i:s', strtotime('-30 days'))]);
    q('DELETE FROM protokoll WHERE zeit < ?', [date('Y-m-d H:i:s', strtotime('-12 months'))]);
    foreach (glob(storage('sessions') . '/sess_*') ?: [] as $f) {
        // „Angemeldet bleiben“-Sitzungen bis 91 Tage behalten, alle anderen wie bisher nach 2 × session_minuten löschen
        $alter = time() - filemtime($f);
        $bleibt = $alter < 91 * 86400 && str_contains((string)@file_get_contents($f), 'bleiben|i:1;');
        if ($alter > (int)cfg('session_minuten', 120) * 60 * 2 && !$bleibt) @unlink($f);
    }
    foreach (glob(storage('logs') . '/mate-*.log') ?: [] as $f) {
        if (filemtime($f) < strtotime('-12 months')) @unlink($f);
    }
}

// 2. Löschkonzept -------------------------------------------------
// Wie in der App: Am Stichtag werden die Abgabe-Dateien früherer Schuljahre gelöscht (Fotos, Dateien,
// Korrekturbilder, Anhänge der Kinder im Chat, Bilder im Chat). Bewertungen, Feedback-Texte, Kurse,
// Aufgaben und Material der Lehrkräfte bleiben. Die Datei-Einträge werden durch „… (am … gelöscht)“ ersetzt;
// die Dateien selbst entfernt danach Schritt 3 (nicht mehr verwendet).
function abgabe_dateien_loeschen(string $sj, string $tag, bool $nurZaehlen): array
{
    $n = 0; $abgaben = 0;
    $weg = function ($f) use (&$n, $tag) {
        if (!($f instanceof stdClass) || !empty($f->geloescht) || ($f->typ ?? '') === 'link') return $f;
        $n++;
        return (object)['n' => ($f->n ?? 'Datei') . " (am $tag gelöscht)", 'geloescht' => true];
    };
    $sql = 'SELECT ab.id, ab.extra FROM abgaben ab JOIN aufgaben a ON a.id = ab.aufgabe_id JOIN kurse k ON k.id = a.kurs_id WHERE k.sj < ?';
    foreach (q_alle($sql, [$sj]) as $ab) {
        $vorher = $n;
        $o = j_obj($ab['extra']);
        if (isset($o->files) && is_array($o->files)) $o->files = array_map($weg, $o->files);
        if (isset($o->korrektur)) { unset($o->korrektur); $n++; }
        foreach (q_alle('SELECT id, von_rolle, extra FROM nachrichten WHERE abgabe_id = ?', [$ab['id']]) as $m) {
            $mVorher = $n;
            $mo = j_obj($m['extra']);
            if (($mo->von ?? $m['von_rolle']) === 's' && isset($mo->files) && is_array($mo->files)) $mo->files = array_map($weg, $mo->files);
            if (isset($mo->bild)) { unset($mo->bild); $mo->bildGeloescht = true; $n++; }
            if ($n > $mVorher && !$nurZaehlen) aendern('nachrichten', ['extra' => j_enc($mo), 'geaendert' => jetzt()], 'id = ?', [$m['id']]);
        }
        if ($n > $vorher) {
            $abgaben++;
            if (!$nurZaehlen) aendern('abgaben', ['extra' => j_enc($o), 'geaendert' => jetzt()], 'id = ?', [$ab['id']]);
        }
    }
    return [$n, $abgaben];
}

$monate = ['Jänner', 'Februar', 'März', 'April', 'Mai', 'Juni', 'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember'];
$tagText = date('j. ', strtotime($stichtag)) . $monate[(int)date('n', strtotime($stichtag)) - 1] . date(' Y', strtotime($stichtag));
$abgabenText = fn (int $n) => $n === 1 ? 'einer Abgabe' : "$n Abgaben";
[, $offeneAbgaben] = abgabe_dateien_loeschen($sj, $tagText, true);

// Vollständige Löschung: alles vor dieser Grenze (am Stichtag rückt sie um ein Schuljahr vor)
$sjText = fn (int $y) => $y . '/' . substr((string)($y + 1), 2);
$y0 = (int)substr($sj, 0, 4);
$grenzeNachStichtag = $sjText($y0 - 1);
$grenze = $heute >= $stichtag ? $grenzeNachStichtag : $sjText($y0 - 2);
$komplettSj = $sjText($y0 - 2);   // das Schuljahr, das am heurigen Stichtag vollständig gelöscht wird
$zaehleAlt = fn (string $g) => [
    'kurse' => (int)q_wert("SELECT COUNT(*) FROM kurse WHERE sj < ? AND sj LIKE '____/__'", [$g]),
    'termine' => (int)q_wert("SELECT COUNT(*) FROM termine WHERE sj < ? AND sj LIKE '____/__'", [$g]),
    'konten' => (int)q_wert('SELECT COUNT(*) FROM benutzer WHERE aktiv = 0 AND abgang IS NOT NULL AND abgang < ?', [substr($g, 0, 4) . '-08-01']),
];
$altBald = $zaehleAlt($grenzeNachStichtag);
$gibtKomplett = array_sum($altBald) > 0;

$vorwarnung = date('Y-m-d', strtotime($stichtag . ' -' . (int)cfg('loeschen_vorwarnung_tage', 14) . ' days'));
$schonGewarnt = q_wert("SELECT wert FROM einstellungen WHERE schluessel = 'loeschhinweis'") === $sj;

if (($offeneAbgaben || $gibtKomplett) && $heute >= $vorwarnung && $heute < $stichtag && !$schonGewarnt) {
    $text = "Am " . date('d.m.Y', strtotime($stichtag)) . " löscht MATE automatisch:";
    if ($offeneAbgaben) $text .= " die Fotos und Dateien von " . $abgabenText($offeneAbgaben) . " aus früheren Schuljahren (Bewertungen, Feedback-Texte und Material der Lehrkräfte bleiben)" . ($gibtKomplett ? ';' : '.');
    if ($gibtKomplett) $text .= " das Schuljahr $komplettSj und älter vollständig: {$altBald['kurse']} Kurse mit Aufgaben, Abgaben und Bewertungen, {$altBald['termine']} Termine und {$altBald['konten']} abgemeldete Konten.";
    $text .= " Wer etwas aufheben muss, etwa für eine Beschwerde, lädt es bis dahin herunter.";
    $sag("Hinweis an Admin: $text");
    if (!$trocken) {
        einfuegen('meldungen', ['id' => neue_id('m'), 'typ' => 'system', 'benutzer_id' => null, 'text' => $text, 'erledigt' => 0, 'erstellt' => jetzt()]);
        q("DELETE FROM einstellungen WHERE schluessel = 'loeschhinweis'");
        einfuegen('einstellungen', ['schluessel' => 'loeschhinweis', 'wert' => $sj]);
        mail_senden((string)cfg('mail.admin_an'), 'MATE: Löschung alter Abgabe-Dateien', $text);
    }
}

if ($heute >= $stichtag) {
    if ($offeneAbgaben) {
        $sag("Löschtermin erreicht – Abgabe-Dateien vor $sj: $offeneAbgaben Abgaben" . ($trocken ? ' (würden geleert)' : ''));
        if (!$trocken) {
            [$n, $abg] = transaktion(fn () => abgabe_dateien_loeschen($sj, $tagText, false));
            protokoll('loeschkonzept', $sj, "$n Dateien aus $abg Abgaben", null);
            einfuegen('meldungen', ['id' => neue_id('m'), 'typ' => 'system', 'benutzer_id' => null,
                'text' => "Die Fotos und Dateien von " . $abgabenText($abg) . " aus früheren Schuljahren wurden gelöscht. Bewertungen und Feedback-Texte sind erhalten.",
                'erledigt' => 0, 'erstellt' => jetzt()]);
        }
    }
    if (!$trocken) q("DELETE FROM meldungen WHERE (erledigt = 1 OR typ = 'feedback') AND erstellt < ?", [$sjBeginn . ' 00:00:00']);
}

$alt = $zaehleAlt($grenze);
if (array_sum($alt) > 0) {
    $sag("Vollständige Löschung vor $grenze: {$alt['kurse']} Kurse, {$alt['termine']} Termine, {$alt['konten']} Konten" . ($trocken ? ' (würden gelöscht)' : ''));
    if (!$trocken) {
        foreach (q_spalte("SELECT id FROM kurse WHERE sj < ? AND sj LIKE '____/__'", [$grenze]) as $kid) kurs_loeschen($kid);
        transaktion(function () use ($grenze) {
            foreach (q_spalte("SELECT id FROM termine WHERE sj < ? AND sj LIKE '____/__'", [$grenze]) as $tid) termin_loeschen($tid);
            q("DELETE FROM niveaus WHERE sj < ? AND sj LIKE '____/__'", [$grenze]);
            dateien_entfernen_wo("sj < ? AND sj LIKE '____/__'", [$grenze]);   // Reste
            foreach (q_spalte('SELECT id FROM benutzer WHERE aktiv = 0 AND abgang IS NOT NULL AND abgang < ?', [substr($grenze, 0, 4) . '-08-01']) as $uid) {
                dateien_entfernen_wo('ziel = ?', ['u:' . $uid]);
                q('DELETE FROM benutzer WHERE id = ?', [$uid]);
            }
        });
        protokoll('loeschkonzept_komplett', $grenze, "{$alt['kurse']} Kurse, {$alt['termine']} Termine, {$alt['konten']} Konten", null);
        einfuegen('meldungen', ['id' => neue_id('m'), 'typ' => 'system', 'benutzer_id' => null,
            'text' => "Die Schuljahre vor $grenze wurden vollständig gelöscht ({$alt['kurse']} Kurse, {$alt['termine']} Termine, {$alt['konten']} abgemeldete Konten).",
            'erledigt' => 0, 'erstellt' => jetzt()]);
    }
}

// 3. Verwaiste Dateien: hochgeladen, aber nirgends (mehr) verwendet – nach 2 Tagen löschen -----
$verwaist = 0;
foreach (q_alle('SELECT * FROM dateien WHERE erstellt < ?', [date('Y-m-d H:i:s', strtotime('-2 days'))]) as $d) {
    $t = explode(':', $d['ziel']);
    $json = match ($t[0]) {
        'u' => q_wert('SELECT extra FROM benutzer WHERE id = ?', [$t[1] ?? '']),
        'k' => q_wert('SELECT extra FROM kurse WHERE id = ?', [$t[1] ?? '']),
        'a' => q_wert('SELECT extra FROM aufgaben WHERE id = ?', [$t[1] ?? '']),
        't' => q_wert('SELECT extra FROM termine WHERE id = ?', [$t[1] ?? '']),
        'me' => q_wert('SELECT extra FROM meldungen WHERE id = ?', [$t[1] ?? '']),
        's' => (function () use ($t) {
            $ab = q_eins('SELECT id, extra FROM abgaben WHERE aufgabe_id = ? AND schueler_id = ?', [$t[1] ?? '', $t[2] ?? '']);
            if (!$ab) return null;
            return $ab['extra'] . implode('', q_spalte('SELECT extra FROM nachrichten WHERE abgabe_id = ?', [$ab['id']]));
        })(),
        default => null,
    };
    if ($json === null || !str_contains((string)$json, 'api/dateien/' . $d['id'])) {
        $verwaist++;
        if (!$trocken) datei_entfernen($d);
    }
}
if ($verwaist) $sag("Verwaiste Dateien: $verwaist" . ($trocken ? ' (würden gelöscht)' : ' gelöscht'));

// 4. Claude-Connector: abgelaufene Token und Codes, Protokoll nach 90 Tagen löschen (22.11.2026)
if (is_file(dirname(__DIR__) . '/src/mcp/werkzeuge.php')) {
    require_once dirname(__DIR__) . '/src/mcp/werkzeuge.php';
    if (!$trocken) {
        try { mcp_aufraeumen(); $sag('Claude-Connector aufgeräumt'); } catch (Throwable $e) { $sag('Claude-Connector: ' . $e->getMessage()); }
    }
}

$sag('Fertig.');
log_schreiben('CRON ' . implode(' | ', $ausgabe));
