# MATE – Claude-Connector (MCP), Server-Paket 0.1.0

Stand 20. 11. 2026. Vorbereitet für das **Dev-System** (`mate-dev.eibengasse.at`). Nicht ins Echtsystem, bevor Christian am Dev getestet und freigegeben hat.

## Was es macht
- Jede freigeschaltete Lehrkraft trägt in Claude einen eigenen Connector ein (Adresse `https://<mate>/mcp`), meldet sich mit ihrem MATE-Konto an (OAuth 2.1 + PKCE) und arbeitet in Claude mit **ihren** Kursen und **ihren** Rechten.
- Der Admin schaltet im Bereich **„Claude“** (Verwaltung) ein/aus, jedes der 13 Werkzeuge einzeln und jede LK einzeln frei; sieht Verbindungen (trennen) und das Protokoll (ohne Inhalte, 90 Tage).
- LK sehen im **Profil** die Adresse, eine Kurzanleitung und ihre Verbindung (trennen).
- Nur Claude als Client (Rücksprung-Adressen `claude.ai`/`claude.com`), Token nur als SHA-256 gespeichert, Zugang 1 h, Erneuerung 30 Tage mit Rotation; Freischaltung wird bei **jedem** Aufruf geprüft.

## Werkzeuge
| Gruppe | Werkzeuge |
|---|---|
| Lesen | kurse_auflisten, aufgaben_auflisten, aufgabe_lesen, termine_auflisten |
| Schreiben | aufgabe_anlegen, aufgabe_aendern, termin_anlegen, termin_aendern |
| Abgaben (SuS-Daten) | abgaben_auflisten, abgabe_lesen (Fotos/PDFs werden mitgeschickt, max. 8 MB pro Aufruf) |
| Bewerten (SuS-Daten) | bewertung_eintragen, feedback_senden, bewertungen_freigeben |

## Dateien (Pfade relativ zu `/mate-dev`)
| Datei | Zweck |
|---|---|
| `src/mcp/kern.php` | Einstellungen, Token, Protokoll |
| `src/mcp/werkzeuge.php` | die 13 Werkzeuge (Regeln wie in der App) |
| `src/mcp/anbindung.php` | Anbindung an den MATE-Server 0.3.2: lesen über `sync_laden`, speichern über `sync_aendern` (gleiche Rechteprüfung wie die App), Dateien aus `storage/uploads`; eigene Sitzung `<session_name>_claude` für die Anmeldeseite |
| `src/mcp/mcp.sql` | 5 neue Tabellen `mcp_*` |
| `public/mcp.php` | MCP-Endpunkt |
| `public/oauth.php` | Anmeldung (Beschreibung, Registrierung, Anmelde-/Erlauben-Seite, Token) |
| `public/mcp-admin.php` | Schnittstelle für Admin-Bereich und Profil |
| `public/mcp-setup.php` | legt die Tabellen an, löscht sich selbst (nur als Admin) |
| `public/htaccess-mcp.txt` | Ergänzung für `public/.htaccess` (+ Dev-Passwortschutz) |
| `test/` | `php test/lauf.php` → 61 Prüfungen (Testanbindung); `test/lauf-server.php` → 33 Prüfungen gegen den echten Server-Code (SQLite + Demo-Daten) |
| `dev/htaccess-dev`, `dev/taeglich.php` | fertige `public/.htaccess` und `cron/taeglich.php` fürs Dev (Stand 22. 11. 2026) |

Dazu im App-Repo **Build 202611201830** (Admin-Bereich „Claude“, Profil-Abschnitt, Hilfe-Frage) und die Ersetzungsliste `ersetzungen-202611201830.json` (7 Ersetzungen ab 202611190700).

## Einspielen am Dev (Sonntag/Montag, Claude in Chrome + IONOS-Explorer)
1. Christian meldet sich in Chrome bei IONOS an (und am Dev bei MATE als Admin).
2. **Server-Code lesen:** `src/auth.php`, `src/sync.php`, `src/sync_laden.php`, Datenbank-Hilfen, Download-Route, `public/.htaccess` – und `src/mcp/anbindung.php` ausfüllen (Vertrag steht oben in der Datei; `test/anbindung-test.php` ist die Referenz).
3. Sicherung: `storage/update-vor-202611201830` (index.html, sw.js, version.json, .htaccess, cron/taeglich.php).
4. Dateien hochladen (`src/mcp/*`, `public/mcp*.php`, `public/oauth.php`), Rechte 604, zurücklesen.
5. `public/.htaccess` ergänzen (siehe `htaccess-mcp.txt`), am Dev den Passwortschutz für `/mcp`, `/oauth/…`, `/.well-known/…` aufheben.
6. Als Admin `https://mate-dev.eibengasse.at/mcp-setup.php` aufrufen → Tabellen angelegt, Datei gelöscht.
7. App-Update 202611201830 über die Ersetzungsliste (sw.js, index.html, version.json zuletzt).
8. `cron/taeglich.php`: am Ende `require_once __DIR__ . '/../src/mcp/werkzeuge.php'; mcp_aufraeumen();`
9. Prüfen von außen: `/.well-known/oauth-protected-resource/mcp` (JSON), `POST /mcp` ohne Token → 401 mit `WWW-Authenticate`.
10. Admin-Bereich „Claude“: einschalten, Werkzeuge wählen, Christians LK-Konto (bzw. Demo-LK) freischalten.
11. Christian in Claude: Einstellungen → Connectors → „Add custom connector“, Name MATE, Adresse `https://mate-dev.eibengasse.at/mcp` → Verbinden → MATE-Anmeldung → Erlauben.
12. Testen mit den Demo-Kursen (nur erfundene Daten): Kurse/Aufgaben lesen, Aufgabe anlegen, Abgabe lesen, bewerten, Feedback, Freigabe; Admin: Protokoll, Werkzeug abschalten, LK-Freischaltung entfernen → Verbindung weg.

## Offen / bewusst nicht drin
- Datenschutz für das **Echtsystem**: nur mit Zustimmung der Schulleitung (Anthropic als Empfänger; private Konten vs. Team-Vertrag der Schule).
- Anhänge an Aufgaben hochladen, Termine auf Klassen-/Schulebene, Ausblenden/Löschen von Aufgaben – nicht über Claude.
