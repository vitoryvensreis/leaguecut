<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/app/core.php';
$email = $argv[1] ?? '';
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { fwrite(STDERR,"Uso: php bin/make-admin.php usuario@email.com\n"); exit(1); }
$u = lc_one('SELECT id FROM users WHERE email=?', [mb_strtolower($email)]);
if (!$u) { fwrite(STDERR,"Cadastre primeiro o email pelo site.\n"); exit(1); }
lc_query("UPDATE users SET role='admin',status='approved',approved_at=NOW() WHERE id=?",[$u['id']]);
echo "Administrador promovido: {$email}\n";
