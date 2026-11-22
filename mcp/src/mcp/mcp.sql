-- MATE – Claude-Connector (MCP), Tabellen
-- Einspielen am Dev: einmalig über mcp-setup.php (legt die Tabellen an und löscht sich danach selbst).
-- Läuft auf MariaDB 11.8 und (für die lokalen Tests) auf SQLite.

-- Einstellungen des Admins (eine Zeile, id = 1)
CREATE TABLE IF NOT EXISTS mcp_einstellungen (
  id INTEGER PRIMARY KEY,
  daten TEXT NOT NULL,              -- JSON: {"aktiv":false,"werkzeuge":{"kurse_auflisten":true,...},"lk":[12,15]}
  geaendert BIGINT NOT NULL
);

-- Registrierte Clients (Claude meldet sich selbst an: Dynamic Client Registration
-- oder „Claude's published identity“ = client_id ist eine https-Adresse)
CREATE TABLE IF NOT EXISTS mcp_clients (
  client_id VARCHAR(255) PRIMARY KEY,
  name VARCHAR(200) NOT NULL,
  redirect_uris TEXT NOT NULL,      -- JSON-Liste
  erstellt BIGINT NOT NULL
);

-- Einmal-Codes aus der Anmeldung (10 Minuten gültig)
CREATE TABLE IF NOT EXISTS mcp_codes (
  code_hash CHAR(64) PRIMARY KEY,
  client_id VARCHAR(255) NOT NULL,
  benutzer_id VARCHAR(20) NOT NULL,
  redirect_uri TEXT NOT NULL,
  challenge VARCHAR(128) NOT NULL,
  ablauf BIGINT NOT NULL
);

-- Zugangs- und Erneuerungs-Token (nur als SHA-256 gespeichert)
CREATE TABLE IF NOT EXISTS mcp_tokens (
  token_hash CHAR(64) PRIMARY KEY,
  art VARCHAR(10) NOT NULL,         -- 'access' oder 'refresh'
  verbindung VARCHAR(32) NOT NULL,  -- gemeinsame Kennung von access + refresh einer Verbindung
  client_id VARCHAR(255) NOT NULL,
  benutzer_id VARCHAR(20) NOT NULL,
  erstellt BIGINT NOT NULL,
  ablauf BIGINT NOT NULL,
  zuletzt BIGINT NULL
);
CREATE INDEX IF NOT EXISTS mcp_tokens_benutzer ON mcp_tokens (benutzer_id);
CREATE INDEX IF NOT EXISTS mcp_tokens_verbindung ON mcp_tokens (verbindung);

-- Protokoll: wer hat wann welches Werkzeug benutzt (ohne Inhalte, nach 90 Tagen gelöscht)
CREATE TABLE IF NOT EXISTS mcp_protokoll (
  id INTEGER PRIMARY KEY AUTO_INCREMENT_PLATZHALTER,
  zeit BIGINT NOT NULL,
  benutzer_id VARCHAR(20) NOT NULL,
  werkzeug VARCHAR(60) NOT NULL,
  ok TINYINT NOT NULL,
  info VARCHAR(200) NOT NULL        -- z. B. „Aufgabe a123 · 2B Digitale Grundbildung“, nie Namen oder Texte
);
CREATE INDEX IF NOT EXISTS mcp_protokoll_zeit ON mcp_protokoll (zeit);
