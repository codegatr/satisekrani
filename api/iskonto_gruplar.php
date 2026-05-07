<?php
require __DIR__ . '/../inc/bootstrap.php';
require_login();
header('Content-Type: application/json; charset=utf-8');

$ag = (int)($_GET['ana_grup'] ?? 0);

if ($ag > 0) {
    $st = db()->prepare(
        'SELECT id, ad FROM tk_iskonto_gruplar 
         WHERE ana_grup_id = ? ORDER BY ad'
    );
    $st->execute([$ag]);
} else {
    $st = db()->query('SELECT id, ad FROM tk_iskonto_gruplar ORDER BY ad');
}

json_out(['ok' => true, 'gruplar' => $st->fetchAll()]);
