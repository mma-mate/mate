<?php
// Legt die Testdatenbank und erfundene Testdaten an (alles in /tmp/mate-mcp-test)
declare(strict_types=1);
$d = '/tmp/mate-mcp-test';
if (is_dir($d)) exec('rm -rf ' . escapeshellarg($d));
mkdir($d . '/dateien', 0777, true);
$pdo = new PDO('sqlite:' . $d . '/db.sqlite');
$sql = str_replace('AUTO_INCREMENT_PLATZHALTER', '', file_get_contents(__DIR__ . '/../src/mcp/mcp.sql'));
$pdo->exec($sql);

$ms = fn(string $s) => strtotime($s) * 1000;
$users = [
    ['db' => 1, 'id' => 'l1', 'vn' => 'Sabine', 'nn' => 'Demo', 'mail' => 'sabine.demo@demo.mate.test', 'rolle' => 'l'],
    ['db' => 2, 'id' => 'l2', 'vn' => 'Ali', 'nn' => 'Test', 'mail' => 'ali.test@demo.mate.test', 'rolle' => 'l'],
    ['db' => 3, 'id' => 'l3', 'vn' => 'Anna', 'nn' => 'Admin', 'mail' => 'anna.admin@demo.mate.test', 'rolle' => 'l', 'admin' => true],
    ['db' => 4, 'id' => 'l4', 'vn' => 'Neu', 'nn' => 'Lehrer', 'mail' => 'neu.lehrer@demo.mate.test', 'rolle' => 'l', 'pw' => 'start'],
];
$kinder = [['Lena', 'Bauer'], ['Jonas', 'Aigner'], ['Mia', 'Huber'], ['Elias', 'Wolf']];
foreach ($kinder as $i => [$vn, $nn]) $users[] = ['db' => 10 + $i, 'id' => 's' . (10 + $i), 'vn' => $vn, 'nn' => $nn, 'mail' => strtolower("$vn.$nn@demo.mate.test"), 'rolle' => 's', 'klasse' => '2A'];
$sus = ['s10', 's11', 's12', 's13'];
$kurse = [
    ['id' => 'k1', 'art' => 'Unterrichtsfach', 'fach' => 'Englisch', 'klassen' => ['2A'], 'sj' => '2026/27', 'bew' => 'punkte', 'punkte' => 10, 'lehrer' => ['l1', 'l2'], 'eigentuemer' => 'l1', 'schueler' => $sus, 'frist' => '08:00', 'nachfrist' => 30],
    ['id' => 'k2', 'art' => 'Unterrichtsfach', 'fach' => 'Geografie u. wirtschaftliche Bildung', 'klassen' => ['2A'], 'sj' => '2026/27', 'bew' => 'symbole', 'symbole' => ['art' => 'pm', 'n' => 3], 'lehrer' => ['l2'], 'schueler' => $sus],
    ['id' => 'k3', 'art' => 'Unterrichtsfach', 'fach' => 'Englisch', 'klassen' => ['1A'], 'sj' => '2025/26', 'bew' => 'punkte', 'punkte' => 10, 'lehrer' => ['l1'], 'schueler' => $sus, 'archiv' => true],
];
// Testbild
$img = imagecreatetruecolor(200, 120); imagefill($img, 0, 0, imagecolorallocate($img, 230, 240, 255));
imagestring($img, 5, 20, 50, 'Workbook S. 18', imagecolorallocate($img, 20, 20, 80)); imagejpeg($img, $d . '/dateien/demo-1.jpg', 80);
file_put_contents($d . '/dateien/demo-2.pdf', "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj 2 0 obj<</Type/Pages/Kids[]/Count 0>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF");
$sub = fn(array $o = []) => $o + ['files' => [], 'score' => null, 'abwesend' => false, 'feedback' => '', 'zeit' => null];
$aufgaben = [
    ['id' => 'a1', 'kurs' => 'k1', 'nr' => 1, 'titel' => 'Workbook S. 18–19', 'beschreibung' => 'Übungen 1 bis 5.', 'bew' => 'punkte', 'max' => 10,
     'abgabe' => 'online', 'fuer' => 'alle', 'auswahl' => [], 'frist' => $ms('-2 days 08:00'), 'spaet' => true, 'nachfrist' => ['art' => 'tage', 'tage' => 30],
     'sichtbar' => $ms('-10 days'), 'freigegeben' => false, 'anhaenge' => [], 'subs' => [
        's10' => $sub(['files' => [['n' => 'workbook.jpg', 'kb' => 12, 'h' => 'demo-1.jpg']], 'zeit' => $ms('-3 days')]),
        's11' => $sub(['files' => [['n' => 'arbeit.pdf', 'kb' => 1, 'h' => 'demo-2.pdf']], 'zeit' => $ms('-1 day'), 'msgs' => [['von' => 's', 'text' => 'Ist Übung 5 Pflicht?', 'zeit' => $ms('-1 day')]]]),
        's12' => $sub(['abwesend' => true]),
        's13' => $sub(),
     ]],
    ['id' => 'a2', 'kurs' => 'k2', 'nr' => 1, 'titel' => 'Fremde Aufgabe', 'bew' => 'symbole', 'abgabe' => 'online', 'fuer' => 'alle', 'frist' => $ms('+3 days'), 'sichtbar' => $ms('-1 day'), 'subs' => []],
];
$termine = [
    ['id' => 't1', 'ebene' => 'kurs', 'kurs' => 'k1', 'art' => 'pruefung', 'titel' => 'Schularbeit Englisch', 'datum' => $ms('+10 days 00:00'), 'zeit' => 'einheit', 'von' => 2, 'bis' => null, 'sichtbar' => $ms('-1 day'), 'autor' => 'l1', 'sj' => '2026/27'],
    ['id' => 't2', 'ebene' => 'schule', 'kurs' => null, 'art' => 'frei', 'titel' => 'Schulautonomer Tag', 'datum' => $ms('+5 days 00:00'), 'zeit' => 'ganztag', 'sichtbar' => $ms('-30 days')],
    ['id' => 't3', 'ebene' => 'kurs', 'kurs' => 'k2', 'art' => 'pruefung', 'titel' => 'Test GW (fremd)', 'datum' => $ms('+6 days 00:00'), 'zeit' => 'ganztag', 'sichtbar' => $ms('-1 day')],
];
file_put_contents($d . '/daten.json', json_encode(compact('users', 'kurse', 'aufgaben', 'termine'), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
echo "Testdaten bereit\n";
