<?php
// Lokaler Testserver: php -S 127.0.0.1:8099 test/router.php  (wie die .htaccess am Server)
define('MCP_ANBINDUNG', __DIR__ . '/anbindung-test.php');
$p = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$pub = __DIR__ . '/../public/';
if ($p === '/mcp') { require $pub . 'mcp.php'; return true; }
if (str_starts_with($p, '/oauth/') || str_starts_with($p, '/.well-known/')) { require $pub . 'oauth.php'; return true; }
if ($p === '/mcp-admin.php') { require $pub . 'mcp-admin.php'; return true; }
http_response_code(404); echo 'nicht gefunden';
return true;
