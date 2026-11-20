<?php
declare(strict_types=1);
/**
 * MATE – Claude-Connector (MCP): die Werkzeuge
 * Jedes Werkzeug arbeitet mit denselben Daten wie die App (Kurse, Aufgaben mit subs, Termine)
 * und hält sich an dieselben Regeln: nur eigene Kurse, archivierte Kurse nur lesen.
 */

require_once __DIR__ . '/kern.php';
date_default_timezone_set('Europe/Vienna');

/* ---------- Gruppen und Werkzeuge ---------- */

const MCP_GRUPPEN = [
    'lesen'     => 'Kurse, Aufgaben und Termine lesen',
    'schreiben' => 'Aufgaben und Termine anlegen und ändern',
    'abgaben'   => 'Abgaben lesen (Daten der SuS)',
    'bewerten'  => 'Bewerten, Feedback und Freigabe (Daten der SuS)',
];

function mcp_werkzeuge(): array
{
    $id = ['type' => 'string'];
    $datum = ['type' => 'string', 'description' => 'Datum im Format JJJJ-MM-TT, z. B. 2026-11-30'];
    $uhr = ['type' => 'string', 'description' => 'Uhrzeit HH:MM, z. B. 18:00'];
    $sicht = ['type' => 'string', 'description' => '„sofort“, „spaeter“ (erst sichtbar, wenn die LK sie in MATE freischaltet) oder Zeitpunkt „JJJJ-MM-TT HH:MM“'];
    $lesen = ['readOnlyHint' => true, 'destructiveHint' => false, 'openWorldHint' => false];
    $schreib = ['readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => false, 'openWorldHint' => false];
    return [
        'kurse_auflisten' => ['gruppe' => 'lesen', 'titel' => 'Meine Kurse auflisten', 'ann' => $lesen,
            'beschreibung' => 'Listet die MATE-Kurse der angemeldeten Lehrkraft (Klasse, Fach, Schuljahr, Anzahl SuS, Bewertungsart). Archivierte Kurse nur auf Wunsch.',
            'schema' => ['type' => 'object', 'properties' => ['archiv' => ['type' => 'boolean', 'description' => 'Auch archivierte Kurse zeigen (Standard: nein)']]]],
        'aufgaben_auflisten' => ['gruppe' => 'lesen', 'titel' => 'Aufgaben eines Kurses auflisten', 'ann' => $lesen,
            'beschreibung' => 'Listet die Aufgaben eines Kurses mit Nummer, Titel, Frist, Sichtbarkeit und Zählern (abgegeben, bewertet, freigegeben). Keine Namen von SuS.',
            'schema' => ['type' => 'object', 'properties' => ['kurs_id' => $id, 'ausgeblendete' => ['type' => 'boolean', 'description' => 'Auch ausgeblendete Aufgaben zeigen']], 'required' => ['kurs_id']]],
        'aufgabe_lesen' => ['gruppe' => 'lesen', 'titel' => 'Aufgabe lesen', 'ann' => $lesen,
            'beschreibung' => 'Zeigt eine Aufgabe vollständig: Beschreibung, Frist, Nachfrist, Bewertungsart, erreichbare Punkte, Sichtbarkeit, Zähler. Keine Namen von SuS.',
            'schema' => ['type' => 'object', 'properties' => ['aufgabe_id' => $id], 'required' => ['aufgabe_id']]],
        'termine_auflisten' => ['gruppe' => 'lesen', 'titel' => 'Termine auflisten', 'ann' => $lesen,
            'beschreibung' => 'Listet Termine (Schularbeiten/Tests, Lehrausgänge, Klassen- und Schultermine, Ferien) in einem Zeitraum, wahlweise nur für einen Kurs.',
            'schema' => ['type' => 'object', 'properties' => ['von' => $datum, 'bis' => $datum, 'kurs_id' => $id]]],
        'aufgabe_anlegen' => ['gruppe' => 'schreiben', 'titel' => 'Aufgabe anlegen', 'ann' => $schreib,
            'beschreibung' => 'Legt eine neue Aufgabe in einem eigenen Kurs an. MATE vergibt die Nummer selbst (Titel ohne Nummer). Standardwerte kommen aus dem Kurs. Für die Kinder sichtbar wird sie je nach „sichtbar“.',
            'schema' => ['type' => 'object', 'properties' => [
                'kurs_id' => $id, 'titel' => ['type' => 'string', 'description' => 'höchstens 120 Zeichen, ohne Nummer'],
                'beschreibung' => ['type' => 'string', 'description' => 'Auftrag für die SuS. Formatierung wie in MATE: **fett**, *kursiv*, „- “ Liste, „1. “ nummeriert, „## “ Zwischenüberschrift, „! “ Hinweis, [Text](https://…)'],
                'frist_datum' => $datum, 'frist_uhrzeit' => $uhr,
                'abgabe' => ['type' => 'string', 'enum' => ['online', 'schule', 'keine'], 'description' => 'online = Abgabe in MATE (Standard), schule = in der Schule, keine = ohne Abgabe'],
                'punkte' => ['type' => 'integer', 'description' => 'erreichbare Punkte (nur bei Bewertung mit Punkten)'],
                'sichtbar' => $sicht,
                'nachfrist_tage' => ['type' => 'integer', 'description' => '0 = keine verspätete Abgabe; leer = Standard des Kurses'],
            ], 'required' => ['kurs_id', 'titel', 'frist_datum']]],
        'aufgabe_aendern' => ['gruppe' => 'schreiben', 'titel' => 'Aufgabe ändern', 'ann' => $schreib,
            'beschreibung' => 'Ändert eine Aufgabe in einem eigenen Kurs. Nur angegebene Felder werden geändert. Die Nummer bleibt.',
            'schema' => ['type' => 'object', 'properties' => [
                'aufgabe_id' => $id, 'titel' => ['type' => 'string'], 'beschreibung' => ['type' => 'string'],
                'frist_datum' => $datum, 'frist_uhrzeit' => $uhr,
                'abgabe' => ['type' => 'string', 'enum' => ['online', 'schule', 'keine']],
                'punkte' => ['type' => 'integer'], 'sichtbar' => $sicht,
            ], 'required' => ['aufgabe_id']]],
        'termin_anlegen' => ['gruppe' => 'schreiben', 'titel' => 'Termin anlegen', 'ann' => $schreib,
            'beschreibung' => 'Trägt einen Kurstermin ein: art „pruefung“ (Schularbeit, Test …), „aussen“ (Lehrausgang), „geaendert“ (geänderter Unterricht) oder „info“. Nur für eigene Kurse.',
            'schema' => ['type' => 'object', 'properties' => [
                'kurs_id' => $id, 'art' => ['type' => 'string', 'enum' => ['pruefung', 'aussen', 'geaendert', 'info']],
                'titel' => ['type' => 'string'], 'datum' => $datum,
                'einheit_von' => ['type' => 'integer', 'description' => 'Unterrichtseinheit (1–10), z. B. 3'], 'einheit_bis' => ['type' => 'integer'],
                'uhrzeit_von' => $uhr, 'uhrzeit_bis' => $uhr,
                'beschreibung' => ['type' => 'string', 'description' => 'Stoff oder Beschreibung'], 'sichtbar' => $sicht,
            ], 'required' => ['kurs_id', 'art', 'datum']]],
        'termin_aendern' => ['gruppe' => 'schreiben', 'titel' => 'Termin ändern', 'ann' => $schreib,
            'beschreibung' => 'Ändert einen Kurstermin in einem eigenen Kurs. Nur angegebene Felder werden geändert.',
            'schema' => ['type' => 'object', 'properties' => [
                'termin_id' => $id, 'art' => ['type' => 'string', 'enum' => ['pruefung', 'aussen', 'geaendert', 'info']],
                'titel' => ['type' => 'string'], 'datum' => $datum, 'einheit_von' => ['type' => 'integer'], 'einheit_bis' => ['type' => 'integer'],
                'uhrzeit_von' => $uhr, 'uhrzeit_bis' => $uhr, 'beschreibung' => ['type' => 'string'], 'sichtbar' => $sicht,
            ], 'required' => ['termin_id']]],
        'abgaben_auflisten' => ['gruppe' => 'abgaben', 'titel' => 'Abgaben einer Aufgabe auflisten', 'ann' => $lesen,
            'beschreibung' => 'Zeigt für jedes Kind der Aufgabe: Name, Status (offen, abgegeben, verspätet, nachgereicht, bewertet, abwesend …), Anzahl Dateien, Bewertung, offene Fragen.',
            'schema' => ['type' => 'object', 'properties' => ['aufgabe_id' => $id], 'required' => ['aufgabe_id']]],
        'abgabe_lesen' => ['gruppe' => 'abgaben', 'titel' => 'Abgabe eines Kindes lesen', 'ann' => $lesen,
            'beschreibung' => 'Zeigt die Abgabe eines Kindes: Dateien (Fotos und PDFs werden mitgeschickt), Bewertung, Feedback-Verlauf und Fragen.',
            'schema' => ['type' => 'object', 'properties' => ['aufgabe_id' => $id, 'schueler_id' => $id,
                'dateien' => ['type' => 'boolean', 'description' => 'Fotos/PDFs mitschicken (Standard: ja)']], 'required' => ['aufgabe_id', 'schueler_id']]],
        'bewertung_eintragen' => ['gruppe' => 'bewerten', 'titel' => 'Bewertung eintragen', 'ann' => $schreib + ['idempotentHint' => true],
            'beschreibung' => 'Trägt die Bewertung eines Kindes ein (Punkte, Prozent oder Symbol je nach Aufgabe) oder vermerkt „abwesend“. Kinder sehen sie erst nach der Freigabe.',
            'schema' => ['type' => 'object', 'properties' => ['aufgabe_id' => $id, 'schueler_id' => $id,
                'bewertung' => ['type' => ['string', 'number', 'null'], 'description' => 'Punkte (z. B. 8 oder 7.5), Prozent (0–100) oder Symbol der Kursskala (z. B. „+“, „★★“); null löscht die Bewertung'],
                'abwesend' => ['type' => 'boolean', 'description' => 'true = als abwesend vermerken (löscht eine Bewertung)']], 'required' => ['aufgabe_id', 'schueler_id']]],
        'feedback_senden' => ['gruppe' => 'bewerten', 'titel' => 'Feedback senden', 'ann' => $schreib,
            'beschreibung' => 'Schickt einem Kind Feedback zu seiner Abgabe (erscheint sofort beim Kind unter „Feedback“). Formatierung wie in MATE.',
            'schema' => ['type' => 'object', 'properties' => ['aufgabe_id' => $id, 'schueler_id' => $id, 'text' => ['type' => 'string']], 'required' => ['aufgabe_id', 'schueler_id', 'text']]],
        'bewertungen_freigeben' => ['gruppe' => 'bewerten', 'titel' => 'Bewertungen freigeben', 'ann' => ['readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false],
            'beschreibung' => 'Gibt die Bewertungen einer Aufgabe für die Kinder frei (jedes Kind sieht nur seine eigene) oder nimmt die Freigabe zurück. Vorher nachfragen, ob wirklich freigegeben werden soll.',
            'schema' => ['type' => 'object', 'properties' => ['aufgabe_id' => $id, 'freigeben' => ['type' => 'boolean', 'description' => 'true = freigeben, false = Freigabe zurücknehmen']], 'required' => ['aufgabe_id', 'freigeben']]],
    ];
}

function mcp_werkzeug_namen(): array { return array_keys(mcp_werkzeuge()); }

/** Für tools/list: nur freigeschaltete Werkzeuge */
function mcp_werkzeuge_liste(): array
{
    $e = mcp_einstellungen();
    $aus = [];
    foreach (mcp_werkzeuge() as $n => $w) {
        if (empty($e['werkzeuge'][$n])) continue;
        $schema = $w['schema'];
        if (!isset($schema['properties']) || !$schema['properties']) $schema['properties'] = new stdClass();
        $aus[] = ['name' => $n, 'title' => $w['titel'], 'description' => $w['beschreibung'], 'inputSchema' => $schema,
                  'annotations' => ['title' => $w['titel']] + $w['ann']];
    }
    return $aus;
}

/* ---------- Fehler für Werkzeuge ---------- */

final class McpFehler extends RuntimeException {}
function mcp_fehler(string $text): never { throw new McpFehler($text); }

/* ---------- Hilfen (wie in der App) ---------- */

/** ID der LK, wie sie in den App-Daten steht (lehrer, autor, msgs.u) */
function w_ich(array $lk): string { return (string)($lk['app_id'] ?? $lk['id']); }

function w_ms(int $ms): string { return date('Y-m-d H:i', intdiv($ms, 1000)); }
function w_tag(int $ms): string { return date('Y-m-d', intdiv($ms, 1000)); }
function w_id(string $p): string { return $p . substr(base_convert(bin2hex(random_bytes(6)), 16, 36), 0, 8); }

function w_zeitpunkt(string $datum, ?string $uhr, string $feld): int
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $datum)) mcp_fehler("$feld: Datum bitte als JJJJ-MM-TT angeben.");
    $uhr = $uhr ?: '00:00';
    if (!preg_match('/^\d{1,2}:\d{2}$/', $uhr)) mcp_fehler("$feld: Uhrzeit bitte als HH:MM angeben.");
    $d = DateTime::createFromFormat('Y-m-d H:i', "$datum $uhr");
    if (!$d) mcp_fehler("$feld: ungültiges Datum.");
    return $d->getTimestamp() * 1000;
}

function w_sj_ende(string $sj): int { $y = (int)substr($sj, 0, 4); return (new DateTime(($y + 1) . '-08-31 23:59:59'))->getTimestamp() * 1000; }

function w_sichtbar(string $v, ?int $spaetestens, string $was): int
{
    $v = trim($v);
    $jetzt = mcp_jetzt();
    if ($v === 'sofort') return $jetzt - 1000;
    if ($v === 'spaeter' || $v === 'später') return $jetzt + 365 * 86400000;
    if (!preg_match('/^(\d{4}-\d{2}-\d{2})[ T](\d{1,2}:\d{2})$/', $v, $m)) mcp_fehler('sichtbar: „sofort“, „spaeter“ oder „JJJJ-MM-TT HH:MM“.');
    $t = w_zeitpunkt($m[1], $m[2], 'sichtbar');
    if ($t < $jetzt - 60000) mcp_fehler('„Sichtbar ab“ liegt in der Vergangenheit. Für sofort „sofort“ angeben.');
    if ($spaetestens !== null && $t > $spaetestens) mcp_fehler("Die $was muss vorher sichtbar werden.");
    return $t;
}

function w_sicht_text(int $s): string
{
    $j = mcp_jetzt();
    if ($s <= $j) return 'sichtbar';
    if ($s > $j + 300 * 86400000) return 'ausgeblendet (später, manuell)';
    return 'sichtbar ab ' . w_ms($s);
}

function w_kurs_name(array $k): string
{
    $n = implode('/', $k['klassen'] ?? []) . ' ' . ($k['fach'] ?? '');
    if (!empty($k['zusatz'])) $n .= ' · ' . $k['zusatz'];
    if (!empty($k['archiv'])) $n .= ' ' . ($k['sj'] ?? '');
    return trim($n);
}

/** Eigener Kurs der LK; beim Schreiben nicht archiviert. */
function w_kurs(array $lk, string $kursId, bool $schreiben = false): array
{
    foreach (mcp_kurse((int)$lk['id']) as $k) {
        if (($k['id'] ?? '') !== $kursId) continue;
        if (!in_array(w_ich($lk), array_map('strval', $k['lehrer'] ?? []), true)) break;
        if ($schreiben && !empty($k['archiv'])) mcp_fehler('Der Kurs ist archiviert und kann nur gelesen werden.');
        return $k;
    }
    mcp_fehler('Diesen Kurs gibt es nicht oder er gehört nicht zu deinen Kursen. Mit kurse_auflisten findest du die Kurs-IDs.');
}

function w_aufgabe(array $lk, string $aId, bool $schreiben = false): array
{
    $a = mcp_aufgabe($aId);
    if (!$a) mcp_fehler('Diese Aufgabe gibt es nicht. Mit aufgaben_auflisten findest du die Aufgaben-IDs.');
    $k = w_kurs($lk, (string)$a['kurs'], $schreiben);
    return [$a, $k];
}

function w_ziel(array $a, array $k): array
{
    $ids = ($a['fuer'] ?? 'alle') === 'auswahl' ? ($a['auswahl'] ?? []) : ($k['schueler'] ?? []);
    return array_values(array_map('strval', $ids));
}

function w_sub(array &$a, string $u): array
{
    if (!isset($a['subs'][$u]) || !is_array($a['subs'][$u])) $a['subs'][$u] = ['files' => [], 'score' => null, 'abwesend' => false, 'feedback' => '', 'zeit' => null];
    return $a['subs'][$u];
}

function w_frist(array $a, string $u): int { return (int)(($a['fristen'][$u] ?? null) ?: $a['frist']); }

function w_status(array $a, string $u): string
{
    $s = $a['subs'][$u] ?? ['files' => [], 'score' => null, 'abwesend' => false, 'zeit' => null];
    $j = mcp_jetzt();
    if (isset($s['score']) && $s['score'] !== null && $s['score'] !== '') return 'bewertet';
    if (!empty($s['files'])) return ((int)$s['zeit'] > w_frist($a, $u)) ? (!empty($s['abwesend']) ? 'nachgereicht' : 'verspätet') : 'abgegeben';
    if (!empty($s['abwesend'])) return 'abwesend';
    if (($a['abgabe'] ?? 'online') === 'keine') return 'offen';
    if (($a['abgabe'] ?? '') === 'schule') return $j > w_frist($a, $u) ? 'in der Schule fällig' : 'offen';
    return $j > w_frist($a, $u) ? 'überfällig' : 'offen';
}

function w_zaehler(array $a, array $k): array
{
    $z = w_ziel($a, $k);
    $abg = 0; $bew = 0; $abw = 0;
    foreach ($z as $u) {
        $s = $a['subs'][$u] ?? [];
        if (!empty($s['files'])) $abg++;
        if (isset($s['score']) && $s['score'] !== null && $s['score'] !== '') $bew++;
        elseif (!empty($s['abwesend']) && empty($s['files'])) $abw++;
    }
    return ['kinder' => count($z), 'abgegeben' => $abg, 'bewertet' => $bew, 'abwesend' => $abw, 'offen_zu_bewerten' => count($z) - $bew - $abw];
}

function w_aufgabe_kurz(array $a, array $k): array
{
    return ['id' => $a['id'], 'nr' => $a['nr'] ?? null, 'titel' => $a['titel'] ?? '', 'frist' => w_ms((int)$a['frist']),
            'abgabe' => $a['abgabe'] ?? 'online', 'sichtbar' => w_sicht_text((int)($a['sichtbar'] ?? 0)),
            'ausgeblendet' => !empty($a['aus']), 'freigegeben' => !empty($a['freigegeben'])] + w_zaehler($a, $k);
}

function w_bew_text(array $a, array $k): string
{
    $b = $a['bew'] ?? 'punkte';
    if ($b === 'punkte') return 'Punkte (0 bis ' . ($a['max'] ?? 10) . ')';
    if ($b === 'prozent') return 'Prozent (0 bis 100)';
    return 'Symbole: ' . implode(' ', w_skala($k));
}

function w_skala(array $k): array
{
    $sk = ['pm' => [3 => ['+', '~', '–'], 4 => ['++', '+', '–', '––'], 5 => ['++', '+', '~', '–', '––'], 6 => ['+++', '++', '+', '–', '––', '–––']],
           'stern' => [3 => ['★★★', '★★', '★'], 4 => ['★★★★', '★★★', '★★', '★'], 5 => ['★★★★★', '★★★★', '★★★', '★★', '★'], 6 => ['★★★★★★', '★★★★★', '★★★★', '★★★', '★★', '★']]];
    $art = $k['symbole']['art'] ?? 'pm'; $n = (int)($k['symbole']['n'] ?? 3);
    if (!isset($sk[$art])) $art = 'pm';
    return $sk[$art][$n] ?? $sk[$art][3];
}

function w_zahl($v): string { $f = (float)$v; return rtrim(rtrim(number_format($f, 2, ',', ''), '0'), ','); }

function w_bewertung_pruefen(array $a, array $k, $wert)
{
    $b = $a['bew'] ?? 'punkte';
    if ($b === 'symbole') {
        $s = trim((string)$wert);
        $s = str_replace(['-', '—'], '–', $s);
        if (!in_array($s, w_skala($k), true)) mcp_fehler('Diese Aufgabe wird mit Symbolen bewertet: ' . implode(' ', w_skala($k)));
        return $s;
    }
    $t = str_replace(',', '.', trim((string)$wert));
    if (!is_numeric($t)) mcp_fehler('Bitte eine Zahl angeben.');
    $n = round((float)$t, 2);
    $max = $b === 'prozent' ? 100 : (float)($a['max'] ?? 10);
    if ($n < 0) mcp_fehler('Die Bewertung darf nicht negativ sein.');
    if ($n > $max) mcp_fehler('Höchstens ' . w_zahl($max) . ($b === 'prozent' ? ' %' : ' Punkte') . ' möglich.');
    return $n;
}

function w_score_text(array $a, $score): ?string
{
    if ($score === null || $score === '') return null;
    $b = $a['bew'] ?? 'punkte';
    if ($b === 'punkte') return w_zahl($score) . ' / ' . ($a['max'] ?? 10);
    if ($b === 'prozent') return w_zahl($score) . ' %';
    return (string)$score;
}

function w_naechste_nr(string $kursId, array $k): int
{
    $m = 0;
    foreach (mcp_aufgaben($kursId) as $a) $m = max($m, (int)($a['nr'] ?? 0));
    foreach ($k['luecken'] ?? [] as $l) $m = max($m, (int)($l['nr'] ?? 0));
    return $m + 1;
}

function w_titel(string $t): string
{
    $t = trim(preg_replace('/\s+/u', ' ', $t));
    if ($t === '') mcp_fehler('Der Titel fehlt.');
    if (mb_strlen($t) > 120) mcp_fehler('Der Titel darf höchstens 120 Zeichen haben.');
    return $t;
}

/* ---------- Ausführen ---------- */

function mcp_werkzeug_ausfuehren(array $lk, string $name, array $p): array
{
    $alle = mcp_werkzeuge();
    if (!isset($alle[$name])) mcp_fehler("Unbekanntes Werkzeug: $name");
    if (empty(mcp_einstellungen()['werkzeuge'][$name])) mcp_fehler('Dieses Werkzeug ist in MATE nicht freigeschaltet (Admin → Claude-Connector).');
    $f = 'w_' . $name;
    return $f($lk, $p);
}

function w_kurse_auflisten(array $lk, array $p): array
{
    $aus = [];
    foreach (mcp_kurse((int)$lk['id']) as $k) {
        if (!in_array(w_ich($lk), array_map('strval', $k['lehrer'] ?? []), true)) continue;
        if (!empty($k['archiv']) && empty($p['archiv'])) continue;
        $aus[] = ['id' => $k['id'], 'name' => w_kurs_name($k), 'fach' => $k['fach'] ?? '', 'klassen' => $k['klassen'] ?? [],
                  'schuljahr' => $k['sj'] ?? '', 'sus' => count($k['schueler'] ?? []), 'bewertung' => $k['bew'] ?? 'punkte',
                  'archiviert' => !empty($k['archiv']), 'verantwortlich' => (string)($k['eigentuemer'] ?? ($k['lehrer'][0] ?? '')) === w_ich($lk)];
    }
    return ['info' => count($aus) . ' Kurse', 'kurse' => $aus];
}

function w_aufgaben_auflisten(array $lk, array $p): array
{
    $k = w_kurs($lk, (string)($p['kurs_id'] ?? ''));
    $as = array_filter(mcp_aufgaben($k['id']), fn($a) => empty($a['aus']) || !empty($p['ausgeblendete']));
    usort($as, fn($x, $y) => ((int)($y['nr'] ?? 0)) <=> ((int)($x['nr'] ?? 0)));
    return ['info' => w_kurs_name($k), 'kurs' => w_kurs_name($k), 'aufgaben' => array_map(fn($a) => w_aufgabe_kurz($a, $k), array_values($as))];
}

function w_aufgabe_lesen(array $lk, array $p): array
{
    [$a, $k] = w_aufgabe($lk, (string)($p['aufgabe_id'] ?? ''));
    $nf = $a['nachfrist'] ?? ['art' => 'keine'];
    $nft = match ($nf['art'] ?? 'keine') { 'tage' => 'Nachfrist ' . ($nf['tage'] ?? 30) . ' Tage', 'bis' => 'Nachfrist bis ' . w_tag((int)$nf['bis']), 'sj' => 'bis Schulschluss', default => 'keine verspätete Abgabe' };
    return ['info' => 'Aufgabe ' . $a['id'] . ' · ' . w_kurs_name($k), 'kurs' => w_kurs_name($k), 'kurs_id' => $k['id']]
        + w_aufgabe_kurz($a, $k)
        + ['beschreibung' => $a['beschreibung'] ?? '', 'bewertung' => w_bew_text($a, $k), 'nachfrist' => $nft,
           'fuer' => ($a['fuer'] ?? 'alle') === 'auswahl' ? count($a['auswahl'] ?? []) . ' ausgewählte SuS' : 'alle SuS des Kurses',
           'anhaenge' => array_map(fn($x) => $x['titel'] ?? $x['n'] ?? $x['url'] ?? 'Anhang', $a['anhaenge'] ?? [])];
}

function w_termin_kurz(array $t, array $kurse): array
{
    $e = $t['ebene'] ?? (!empty($t['kurs']) ? 'kurs' : 'schule');
    $wo = $e === 'kurs' ? (isset($kurse[$t['kurs']]) ? w_kurs_name($kurse[$t['kurs']]) : 'Kurs') : ($e === 'klasse' ? 'Klasse ' . implode(', ', $t['klassen'] ?? []) : 'Schule');
    $zeit = match ($t['zeit'] ?? '') {
        'mehrtag' => 'bis ' . w_tag((int)($t['datumBis'] ?? $t['datum'])),
        'uhrzeit' => trim(($t['uvon'] ?? '') . (!empty($t['ubis']) ? '–' . $t['ubis'] : '')),
        'einheit' => isset($t['von']) && $t['von'] !== null ? $t['von'] . '.' . (isset($t['bis']) && $t['bis'] !== null ? '–' . $t['bis'] . '.' : '') . ' Einheit' : '',
        default => isset($t['von']) && $t['von'] !== null ? $t['von'] . '. Einheit' : 'ganztägig',
    };
    return ['id' => $t['id'], 'datum' => w_tag((int)$t['datum']), 'zeit' => $zeit, 'art' => $t['art'] ?? 'info', 'titel' => $t['titel'] ?? '',
            'wo' => $wo, 'kurs_id' => $e === 'kurs' ? $t['kurs'] : null, 'beschreibung' => $t['beschreibung'] ?? '',
            'sichtbar' => w_sicht_text((int)($t['sichtbar'] ?? 0))];
}

function w_termine_auflisten(array $lk, array $p): array
{
    $von = isset($p['von']) && $p['von'] !== '' ? w_zeitpunkt((string)$p['von'], null, 'von') : (int)(strtotime('today') * 1000);
    $bis = isset($p['bis']) && $p['bis'] !== '' ? w_zeitpunkt((string)$p['bis'], '23:59', 'bis') : $von + 31 * 86400000;
    if ($bis < $von) mcp_fehler('„bis“ liegt vor „von“.');
    $kurse = [];
    foreach (mcp_kurse((int)$lk['id']) as $k) $kurse[$k['id']] = $k;
    if (!empty($p['kurs_id'])) w_kurs($lk, (string)$p['kurs_id']);
    $aus = [];
    foreach (mcp_termine((int)$lk['id']) as $t) {
        $ende = (int)(($t['zeit'] ?? '') === 'mehrtag' && !empty($t['datumBis']) ? $t['datumBis'] : $t['datum']) + 86399999;
        if ($ende < $von || (int)$t['datum'] > $bis) continue;
        if (!empty($p['kurs_id']) && ($t['kurs'] ?? null) !== $p['kurs_id']) continue;
        $aus[] = w_termin_kurz($t, $kurse);
    }
    usort($aus, fn($x, $y) => strcmp($x['datum'], $y['datum']));
    return ['info' => count($aus) . ' Termine', 'von' => w_tag($von), 'bis' => w_tag($bis), 'termine' => $aus];
}

function w_aufgabe_anlegen(array $lk, array $p): array
{
    $k = w_kurs($lk, (string)($p['kurs_id'] ?? ''), true);
    $titel = w_titel((string)($p['titel'] ?? ''));
    if (preg_match('/^\s*(?:nr\.?\s*)?\d{1,3}\s*[.):]\s*\S/iu', $titel)) $titel = trim(preg_replace('/^\s*(?:nr\.?\s*)?\d{1,3}\s*[.):]\s*/iu', '', $titel));
    $frist = w_zeitpunkt((string)($p['frist_datum'] ?? ''), (string)(($p['frist_uhrzeit'] ?? '') ?: ($k['frist'] ?? '08:00')), 'Frist');
    if ($frist < mcp_jetzt()) mcp_fehler('Die Frist liegt in der Vergangenheit.');
    if ($frist > w_sj_ende((string)$k['sj'])) mcp_fehler('Die Frist liegt nach dem Ende des Schuljahres ' . $k['sj'] . '.');
    $abgabe = (string)($p['abgabe'] ?? 'online');
    if (!in_array($abgabe, ['online', 'schule', 'keine'], true)) mcp_fehler('abgabe: online, schule oder keine.');
    $bew = $k['bew'] ?? 'punkte';
    $max = (int)($k['punkte'] ?? 10) ?: 10;
    if (isset($p['punkte']) && $p['punkte'] !== null) {
        if ($bew !== 'punkte') mcp_fehler('Dieser Kurs bewertet nicht mit Punkten.');
        $max = (int)$p['punkte'];
        if ($max < 1 || $max > 1000) mcp_fehler('Erreichbare Punkte: 1 bis 1000.');
    }
    $nfTage = isset($p['nachfrist_tage']) && $p['nachfrist_tage'] !== null ? (int)$p['nachfrist_tage'] : (int)($k['nachfrist'] ?? 30);
    if ($nfTage < 0 || $nfTage > 180) mcp_fehler('Nachfrist: 0 bis 180 Tage.');
    $nachfrist = ($abgabe === 'keine' || $nfTage === 0) ? ['art' => 'keine'] : ['art' => 'tage', 'tage' => $nfTage];
    $sichtbar = w_sichtbar((string)($p['sichtbar'] ?? 'sofort'), $frist, 'Aufgabe');
    $a = ['id' => w_id('a'), 'kurs' => $k['id'], 'nr' => w_naechste_nr($k['id'], $k), 'titel' => $titel,
          'beschreibung' => trim((string)($p['beschreibung'] ?? '')), 'anhaenge' => [], 'fuer' => 'alle', 'auswahl' => [],
          'bew' => $bew, 'max' => $max, 'abgabe' => $abgabe, 'frist' => $frist, 'spaet' => $nachfrist['art'] !== 'keine',
          'nachfrist' => $nachfrist, 'gruppe' => false, 'sichtbar' => $sichtbar, 'freigegeben' => false, 'subs' => []];
    foreach (w_ziel($a, $k) as $u) w_sub($a, $u);
    mcp_aufgabe_speichern($a, (int)$lk['id'], true);
    return ['info' => 'Aufgabe ' . $a['id'] . ' · ' . w_kurs_name($k), 'ergebnis' => 'Aufgabe Nr. ' . $a['nr'] . ' „' . $titel . '“ angelegt.', 'kurs' => w_kurs_name($k)] + w_aufgabe_kurz($a, $k);
}

function w_aufgabe_aendern(array $lk, array $p): array
{
    [$a, $k] = w_aufgabe($lk, (string)($p['aufgabe_id'] ?? ''), true);
    $geaendert = [];
    if (isset($p['titel'])) { $a['titel'] = w_titel((string)$p['titel']); $geaendert[] = 'Titel'; }
    if (isset($p['beschreibung'])) { $a['beschreibung'] = trim((string)$p['beschreibung']); $geaendert[] = 'Beschreibung'; }
    if (isset($p['frist_datum']) || isset($p['frist_uhrzeit'])) {
        $alt = (int)$a['frist'];
        $d = (string)($p['frist_datum'] ?? w_tag($alt));
        $h = (string)($p['frist_uhrzeit'] ?? date('H:i', intdiv($alt, 1000)));
        $neu = w_zeitpunkt($d, $h, 'Frist');
        if ($neu < mcp_jetzt() && intdiv($neu, 60000) !== intdiv($alt, 60000)) mcp_fehler('Die neue Frist liegt in der Vergangenheit.');
        if ($neu > w_sj_ende((string)$k['sj'])) mcp_fehler('Die Frist liegt nach dem Ende des Schuljahres ' . $k['sj'] . '.');
        $a['frist'] = $neu; $geaendert[] = 'Frist';
    }
    if (isset($p['abgabe'])) {
        if (!in_array($p['abgabe'], ['online', 'schule', 'keine'], true)) mcp_fehler('abgabe: online, schule oder keine.');
        $a['abgabe'] = $p['abgabe']; $geaendert[] = 'Abgabe';
    }
    if (isset($p['punkte'])) {
        if (($a['bew'] ?? 'punkte') !== 'punkte') mcp_fehler('Diese Aufgabe wird nicht mit Punkten bewertet.');
        $max = (int)$p['punkte'];
        $hoechst = 0.0;
        foreach ($a['subs'] ?? [] as $s) if (isset($s['score']) && is_numeric($s['score'])) $hoechst = max($hoechst, (float)$s['score']);
        if ($max < 1 || $max > 1000) mcp_fehler('Erreichbare Punkte: 1 bis 1000.');
        if ($max < $hoechst) mcp_fehler('Es wurden schon ' . w_zahl($hoechst) . ' Punkte vergeben. Die erreichbaren Punkte müssen mindestens so hoch sein.');
        $a['max'] = $max; $geaendert[] = 'Punkte';
    }
    if (isset($p['sichtbar'])) {
        $war = (int)$a['sichtbar'] <= mcp_jetzt();
        $v = (string)$p['sichtbar'];
        if ($v === 'sofort') { if (!$war) $a['sichtbar'] = mcp_jetzt() - 1000; }
        else $a['sichtbar'] = w_sichtbar($v, (int)$a['frist'], 'Aufgabe');
        $geaendert[] = 'Sichtbarkeit';
    }
    if (!$geaendert) mcp_fehler('Nichts zu ändern – gib mindestens ein Feld an.');
    mcp_aufgabe_speichern($a, (int)$lk['id'], false);
    return ['info' => 'Aufgabe ' . $a['id'] . ' · ' . implode(', ', $geaendert), 'ergebnis' => 'Geändert: ' . implode(', ', $geaendert) . '.'] + w_aufgabe_kurz($a, $k);
}

function w_termin_felder(array &$t, array $p, array $k): array
{
    $g = [];
    if (isset($p['art'])) { if (!in_array($p['art'], ['pruefung', 'aussen', 'geaendert', 'info'], true)) mcp_fehler('art: pruefung, aussen, geaendert oder info.'); $t['art'] = $p['art']; $g[] = 'Art'; }
    if (isset($p['datum'])) {
        $d = w_zeitpunkt((string)$p['datum'], null, 'Datum');
        if ($d < (int)(strtotime('today') * 1000) && $d !== (int)($t['datum'] ?? 0)) mcp_fehler('Das Datum liegt in der Vergangenheit.');
        if ($d > w_sj_ende((string)$k['sj'])) mcp_fehler('Das Datum liegt nach dem Ende des Schuljahres ' . $k['sj'] . '.');
        $t['datum'] = $d; $g[] = 'Datum';
    }
    if (isset($p['einheit_von'])) {
        $v = (int)$p['einheit_von']; $b = isset($p['einheit_bis']) ? (int)$p['einheit_bis'] : null;
        if ($v < 1 || $v > 12 || ($b !== null && ($b < $v || $b > 12))) mcp_fehler('Einheit: 1 bis 12, „bis“ nicht vor „von“.');
        $t['zeit'] = 'einheit'; $t['von'] = $v; $t['bis'] = ($b === null || $b === $v) ? null : $b; $t['uvon'] = ''; $t['ubis'] = ''; $g[] = 'Einheit';
    } elseif (isset($p['uhrzeit_von'])) {
        $v = (string)$p['uhrzeit_von']; $b = (string)($p['uhrzeit_bis'] ?? '');
        if (!preg_match('/^\d{1,2}:\d{2}$/', $v) || ($b !== '' && (!preg_match('/^\d{1,2}:\d{2}$/', $b) || $b <= $v))) mcp_fehler('Uhrzeit: HH:MM, das Ende nach dem Beginn.');
        $t['zeit'] = 'uhrzeit'; $t['uvon'] = $v; $t['ubis'] = $b; $t['von'] = null; $t['bis'] = null; $g[] = 'Uhrzeit';
    }
    if (isset($p['titel'])) { $t['titel'] = trim((string)$p['titel']) === '' ? '' : w_titel((string)$p['titel']); $g[] = 'Titel'; }
    if (isset($p['beschreibung'])) { $t['beschreibung'] = trim((string)$p['beschreibung']); $g[] = 'Beschreibung'; }
    if (isset($p['sichtbar'])) {
        $v = (string)$p['sichtbar'];
        if ($v === 'sofort') { if ((int)($t['sichtbar'] ?? PHP_INT_MAX) > mcp_jetzt()) $t['sichtbar'] = mcp_jetzt() - 1000; }
        else $t['sichtbar'] = w_sichtbar($v, (int)$t['datum'] + 86400000 - 1, 'Termin');
        $g[] = 'Sichtbarkeit';
    }
    if (($t['titel'] ?? '') === '') $t['titel'] = match ($t['art']) { 'pruefung' => 'Schularbeit ' . $k['fach'], 'aussen' => 'Lehrausgang ' . $k['fach'], default => (string)$k['fach'] };
    return $g;
}

function w_termin_anlegen(array $lk, array $p): array
{
    $k = w_kurs($lk, (string)($p['kurs_id'] ?? ''), true);
    $t = ['id' => w_id('t'), 'ebene' => 'kurs', 'kurs' => $k['id'], 'klassen' => [], 'stufen' => [], 'art' => 'info', 'titel' => '',
          'datum' => 0, 'zeit' => 'ganztag', 'datumBis' => null, 'uvon' => '', 'ubis' => '', 'von' => null, 'bis' => null,
          'beschreibung' => '', 'anhaenge' => [], 'fuer' => 'alle', 'auswahl' => [], 'sichtbar' => mcp_jetzt() - 1000,
          'autor' => w_ich($lk), 'sj' => $k['sj']];
    if (!isset($p['art'], $p['datum'])) mcp_fehler('art und datum fehlen.');
    w_termin_felder($t, $p, $k);
    mcp_termin_speichern($t, (int)$lk['id'], true);
    return ['info' => 'Termin ' . $t['id'] . ' · ' . w_kurs_name($k), 'ergebnis' => '„' . $t['titel'] . '“ am ' . w_tag((int)$t['datum']) . ' eingetragen.'] + w_termin_kurz($t, [$k['id'] => $k]);
}

function w_termin_aendern(array $lk, array $p): array
{
    $id = (string)($p['termin_id'] ?? '');
    $t = null;
    foreach (mcp_termine((int)$lk['id']) as $x) if (($x['id'] ?? '') === $id) { $t = $x; break; }
    if (!$t) mcp_fehler('Diesen Termin gibt es nicht. Mit termine_auflisten findest du die Termin-IDs.');
    if (($t['ebene'] ?? 'kurs') !== 'kurs' || empty($t['kurs'])) mcp_fehler('Über Claude lassen sich nur Kurstermine ändern.');
    $k = w_kurs($lk, (string)$t['kurs'], true);
    $g = w_termin_felder($t, $p, $k);
    if (!$g) mcp_fehler('Nichts zu ändern – gib mindestens ein Feld an.');
    $t['gesehen'] = new stdClass();
    mcp_termin_speichern($t, (int)$lk['id'], false);
    return ['info' => 'Termin ' . $t['id'] . ' · ' . implode(', ', $g), 'ergebnis' => 'Geändert: ' . implode(', ', $g) . '.'] + w_termin_kurz($t, [$k['id'] => $k]);
}

function w_abgaben_auflisten(array $lk, array $p): array
{
    [$a, $k] = w_aufgabe($lk, (string)($p['aufgabe_id'] ?? ''));
    $z = w_ziel($a, $k);
    $namen = mcp_schueler($z);
    $aus = [];
    foreach ($z as $u) {
        $s = $a['subs'][$u] ?? [];
        $msgs = $s['msgs'] ?? [];
        $frage = false;
        if ($msgs) { $l = end($msgs); $frage = ($l['von'] ?? '') === 's' && empty($l['sys']); }
        $aus[] = ['schueler_id' => $u, 'name' => isset($namen[$u]) ? trim($namen[$u]['vn'] . ' ' . $namen[$u]['nn']) : $u,
                  'klasse' => $namen[$u]['klasse'] ?? '', 'status' => w_status($a, $u), 'dateien' => count($s['files'] ?? []),
                  'abgegeben_am' => !empty($s['zeit']) ? w_ms((int)$s['zeit']) : null,
                  'bewertung' => w_score_text($a, $s['score'] ?? null), 'abwesend' => !empty($s['abwesend']),
                  'eigene_frist' => isset($a['fristen'][$u]) ? w_ms((int)$a['fristen'][$u]) : null,
                  'wartet_auf_antwort' => $frage];
    }
    usort($aus, fn($x, $y) => strcmp($x['name'], $y['name']));
    return ['info' => 'Aufgabe ' . $a['id'] . ' · ' . count($aus) . ' SuS', 'aufgabe' => ($a['nr'] ?? '') . ' ' . $a['titel'], 'kurs' => w_kurs_name($k),
            'bewertung' => w_bew_text($a, $k), 'freigegeben' => !empty($a['freigegeben'])] + w_zaehler($a, $k) + ['abgaben' => $aus];
}

function w_abgabe_lesen(array $lk, array $p): array
{
    [$a, $k] = w_aufgabe($lk, (string)($p['aufgabe_id'] ?? ''));
    $u = (string)($p['schueler_id'] ?? '');
    if (!in_array($u, w_ziel($a, $k), true)) mcp_fehler('Dieses Kind gehört nicht zu dieser Aufgabe. Mit abgaben_auflisten findest du die IDs.');
    $s = $a['subs'][$u] ?? ['files' => []];
    $n = mcp_schueler([$u])[$u] ?? ['vn' => '', 'nn' => $u, 'klasse' => ''];
    $mitDateien = !array_key_exists('dateien', $p) || !empty($p['dateien']);
    $inhalt = []; $liste = []; $budget = 8 * 1024 * 1024;
    $alleDateien = [];
    foreach ($s['files'] ?? [] as $f) $alleDateien[] = ['f' => $f, 'wo' => 'Abgabe'];
    foreach ($s['msgs'] ?? [] as $i => $m) if (($m['von'] ?? '') === 's') foreach ($m['files'] ?? [] as $f) $alleDateien[] = ['f' => $f, 'wo' => 'Verbesserung ' . w_ms((int)($m['zeit'] ?? 0))];
    foreach ($alleDateien as $x) {
        $f = $x['f'];
        $e = ['name' => $f['n'] ?? 'Datei', 'kb' => $f['kb'] ?? null, 'teil' => $x['wo']];
        if ($mitDateien) {
            $d = mcp_datei($a, $u, $f);
            if ($d && strlen($d['daten']) <= $budget) {
                $budget -= strlen($d['daten']);
                if (str_starts_with($d['mime'], 'image/')) { $inhalt[] = ['type' => 'image', 'data' => base64_encode($d['daten']), 'mimeType' => $d['mime']]; $e['mitgeschickt'] = true; }
                elseif ($d['mime'] === 'application/pdf') { $inhalt[] = ['type' => 'resource', 'resource' => ['uri' => 'mate://abgabe/' . $a['id'] . '/' . $u . '/' . rawurlencode($e['name']), 'mimeType' => 'application/pdf', 'blob' => base64_encode($d['daten'])]]; $e['mitgeschickt'] = true; }
                else $e['hinweis'] = 'Dateityp kann nicht mitgeschickt werden – bitte in MATE öffnen.';
            } elseif ($d) $e['hinweis'] = 'zu groß zum Mitschicken – bitte in MATE öffnen.';
        }
        $liste[] = $e;
    }
    $verlauf = [];
    foreach ($s['msgs'] ?? [] as $m) {
        if (!empty($m['sys'])) continue;
        $verlauf[] = ['von' => ($m['von'] ?? '') === 'l' ? 'Lehrkraft' : 'Kind', 'zeit' => w_ms((int)($m['zeit'] ?? 0)), 'text' => (string)($m['text'] ?? ''),
                      'dateien' => count($m['files'] ?? [])];
    }
    $daten = ['info' => 'Abgabe · Aufgabe ' . $a['id'], 'aufgabe' => ($a['nr'] ?? '') . ' ' . $a['titel'], 'kurs' => w_kurs_name($k),
              'schueler_id' => $u, 'name' => trim($n['vn'] . ' ' . $n['nn']), 'klasse' => $n['klasse'] ?? '', 'status' => w_status($a, $u),
              'frist' => w_ms(w_frist($a, $u)), 'abgegeben_am' => !empty($s['zeit']) ? w_ms((int)$s['zeit']) : null,
              'bewertung' => w_score_text($a, $s['score'] ?? null), 'bewertungsart' => w_bew_text($a, $k), 'abwesend' => !empty($s['abwesend']),
              'dateien' => $liste, 'feedback_verlauf' => $verlauf];
    return $daten + ['_inhalt' => $inhalt];
}

function w_bewertung_eintragen(array $lk, array $p): array
{
    [$a, $k] = w_aufgabe($lk, (string)($p['aufgabe_id'] ?? ''), true);
    $u = (string)($p['schueler_id'] ?? '');
    if (!in_array($u, w_ziel($a, $k), true)) mcp_fehler('Dieses Kind gehört nicht zu dieser Aufgabe.');
    w_sub($a, $u);
    if (!empty($p['abwesend'])) {
        $a['subs'][$u]['abwesend'] = true; $a['subs'][$u]['score'] = null; $erg = 'Als abwesend vermerkt.';
    } elseif (array_key_exists('bewertung', $p) && $p['bewertung'] === null) {
        $a['subs'][$u]['score'] = null; $erg = 'Bewertung gelöscht.';
    } elseif (array_key_exists('bewertung', $p)) {
        $a['subs'][$u]['score'] = w_bewertung_pruefen($a, $k, $p['bewertung']);
        $a['subs'][$u]['abwesend'] = false;
        $erg = 'Bewertung ' . w_score_text($a, $a['subs'][$u]['score']) . ' eingetragen.' . (!empty($a['freigegeben']) ? ' Die Bewertungen sind freigegeben – das Kind sieht sie sofort.' : ' Das Kind sieht sie erst nach der Freigabe.');
    } else mcp_fehler('Gib „bewertung“ oder „abwesend: true“ an.');
    mcp_aufgabe_speichern($a, (int)$lk['id'], false);
    return ['info' => 'Aufgabe ' . $a['id'], 'ergebnis' => $erg, 'status' => w_status($a, $u)] + w_zaehler($a, $k);
}

function w_feedback_senden(array $lk, array $p): array
{
    [$a, $k] = w_aufgabe($lk, (string)($p['aufgabe_id'] ?? ''), true);
    $u = (string)($p['schueler_id'] ?? '');
    if (!in_array($u, w_ziel($a, $k), true)) mcp_fehler('Dieses Kind gehört nicht zu dieser Aufgabe.');
    $text = trim((string)($p['text'] ?? ''));
    if ($text === '') mcp_fehler('Der Feedback-Text fehlt.');
    if (mb_strlen($text) > 5000) mcp_fehler('Das Feedback ist zu lang (höchstens 5000 Zeichen).');
    w_sub($a, $u);
    $s = &$a['subs'][$u];
    $s['msgs'] = $s['msgs'] ?? [];
    $s['msgs'][] = ['von' => 'l', 'u' => w_ich($lk), 'text' => $text, 'bild' => null, 'zeit' => mcp_jetzt()];
    $s['sNeu'] = true; $s['erledigt'] = false; $s['lOffen'] = false; $s['lNeu'] = false;
    unset($s);
    mcp_aufgabe_speichern($a, (int)$lk['id'], false);
    return ['info' => 'Aufgabe ' . $a['id'], 'ergebnis' => 'Feedback gesendet. Das Kind sieht es unter „Feedback“.'];
}

function w_bewertungen_freigeben(array $lk, array $p): array
{
    [$a, $k] = w_aufgabe($lk, (string)($p['aufgabe_id'] ?? ''), true);
    if (!array_key_exists('freigeben', $p)) mcp_fehler('freigeben: true oder false angeben.');
    $z = w_zaehler($a, $k);
    if (!empty($p['freigeben'])) {
        if ($z['bewertet'] < 1) mcp_fehler('Noch kein Kind ist bewertet – freigeben geht erst danach.');
        $a['freigegeben'] = true;
        $erg = 'Bewertungen freigegeben (' . $z['bewertet'] . ' von ' . $z['kinder'] . ' bewertet). Jedes Kind sieht nur seine eigene.'
            . ($z['offen_zu_bewerten'] > 0 ? ' Achtung: ' . $z['offen_zu_bewerten'] . ' noch ohne Bewertung – später Eingetragenes ist sofort sichtbar.' : '');
    } else { $a['freigegeben'] = false; $erg = 'Freigabe zurückgenommen. Die Kinder sehen ihre Bewertung nicht mehr.'; }
    mcp_aufgabe_speichern($a, (int)$lk['id'], false);
    return ['info' => 'Aufgabe ' . $a['id'], 'ergebnis' => $erg] + $z;
}
