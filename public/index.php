<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/core.php';
require dirname(__DIR__) . '/app/ui.php';
try {
    require dirname(__DIR__) . '/app/routes.php';
} catch (Throwable $e) {
    error_log('LeagueCut: ' . $e);
    http_response_code(500);
    echo '<!doctype html><html lang="pt-BR"><meta charset="utf-8"><title>LeagueCut</title><style>body{background:#101014;color:white;font:16px system-ui;padding:5rem}</style><h1>Não foi possível concluir esta operação.</h1><p>Se persistir, contate o suporte.</p></html>';
}
