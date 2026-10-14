<?php
declare(strict_types=1);

// The PHP CLI development/CI server uses index.php as router.
// Let it serve allowlisted /assets files directly, as Nginx does in production.
// Never expose .env, PHP source, dotfiles, or files outside public/assets.
if(PHP_SAPI==='cli-server' && in_array(($_SERVER['REQUEST_METHOD']??'GET'),['GET','HEAD'],true)){
    $requested=parse_url((string)($_SERVER['REQUEST_URI']??''),PHP_URL_PATH)?:'';
    if(preg_match('~^/assets/[a-zA-Z0-9_/-]+\\.(?:css|js|svg|png|jpg|jpeg|webp|gif|ico|woff2?|ttf)$~D',$requested)){
        $assetRoot=realpath(__DIR__.'/assets');
        $asset=realpath(__DIR__.$requested);
        if($assetRoot && $asset && str_starts_with($asset,$assetRoot.DIRECTORY_SEPARATOR) && is_file($asset)){
            return false;
        }
    }
}
require dirname(__DIR__) . '/app/core.php';
require dirname(__DIR__) . '/app/ui.php';
try {
    require dirname(__DIR__) . '/app/routes.php';
} catch (Throwable $e) {
    error_log('LeagueCut: ' . $e);
    http_response_code(500);
    echo '<!doctype html><html lang="pt-BR"><meta charset="utf-8"><title>LeagueCut</title><style>body{background:#101014;color:white;font:16px system-ui;padding:5rem}</style><h1>Não foi possível concluir esta operação.</h1><p>Se persistir, contate o suporte.</p></html>';
}
