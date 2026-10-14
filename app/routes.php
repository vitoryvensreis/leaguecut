<?php
declare(strict_types=1);
require __DIR__ . '/finance.php';
require __DIR__ . '/home.php';
require __DIR__ . '/routes-home-admin.php';
require __DIR__ . '/drive.php';
require __DIR__ . '/routes-public.php';
require __DIR__ . '/routes-clipador.php';
require __DIR__ . '/routes-admin.php';
if (is_file(LC_ROOT . '/vendor/autoload.php')) require_once LC_ROOT . '/vendor/autoload.php';

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$path = parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/';
$path = $path==='/'?'/':rtrim($path,'/');
if (!in_array($method,['GET','POST'],true)) {http_response_code(405);header('Allow: GET, POST');exit('Método não permitido.');}
if ($method==='POST') lc_check_csrf();
if($method==='GET' && preg_match('~^/media/home/([a-f0-9]{36})$~D',$path,$mediaMatch)) {
    lc_home_media_response($mediaMatch[1]);
}
if ($path==='/health' && $method==='GET') {
    header('Content-Type: text/plain; charset=UTF-8');
    lc_db()->query('SELECT 1');
    echo 'LeagueCut OK';return;
}
if(lc_home_admin_routes($method,$path))return;
if(lc_admin_routes($method,$path))return;
if(lc_clipper_routes($method,$path))return;
if(lc_public_routes($method,$path))return;
http_response_code(404);
lc_page('Página não encontrada', function(){?><div class="container container-sm"><?php lc_empty('Esta página não existe','Confira o endereço ou descubra campanhas disponíveis.');?><p><a class="btn" href="/explorar">Explorar campanhas</a></p></div><?php });
