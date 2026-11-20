<?php
declare(strict_types=1);
/**
 * MATE – Claude-Connector: Tabellen anlegen (einmalig) – löscht sich danach selbst.
 * Aufruf: https://<mate>/mcp-setup.php  (nur als angemeldeter Admin)
 */
require_once __DIR__ . '/../src/mcp/werkzeuge.php';
header('Content-Type: text/plain; charset=utf-8');
mcp_sitzung_starten();
$ich = mcp_sitzung_benutzer();
if (!$ich || empty($ich['admin'])) { http_response_code(403); echo "Bitte zuerst in MATE als Admin anmelden.\n"; exit; }
$sql = (string)file_get_contents(__DIR__ . '/../src/mcp/mcp.sql');
$pdo = mcp_pdo();
$mysql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
$sql = str_replace('AUTO_INCREMENT_PLATZHALTER', $mysql ? 'AUTO_INCREMENT' : '', $sql);
if ($mysql) $sql = str_replace(['id INTEGER PRIMARY KEY AUTO_INCREMENT', 'daten TEXT NOT NULL'], ['id INT PRIMARY KEY AUTO_INCREMENT', 'daten MEDIUMTEXT NOT NULL'], $sql);
$sql = preg_replace('/^\s*--.*$/m', '', $sql);
$n = 0;
foreach (array_filter(array_map('trim', explode(';', $sql))) as $s) { $pdo->exec($s); $n++; }
echo "MATE Claude-Connector: $n Anweisungen ausgeführt, Tabellen bereit.\n";
foreach (['mcp_einstellungen', 'mcp_clients', 'mcp_codes', 'mcp_tokens', 'mcp_protokoll'] as $t)
    echo "  $t: " . (int)$pdo->query("SELECT COUNT(*) FROM $t")->fetchColumn() . " Zeilen\n";
echo @unlink(__FILE__) ? "Diese Datei hat sich gelöscht.\n" : "ACHTUNG: mcp-setup.php bitte von Hand löschen.\n";
