<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit(1);}
require dirname(__DIR__).'/vendor/autoload.php';
$keys=\Minishlink\WebPush\VAPID::createVapidKeys();
fwrite(STDERR,"Chaves VAPID: mantenha somente em /home/leaguecut/shared/.env; não publique logs nem compartilhe tokens.\n");
echo "PUSH_VAPID_PUBLIC=".$keys['publicKey']."\n";
echo "PUSH_VAPID_PRIVATE=".$keys['privateKey']."\n";
echo "PUSH_VAPID_SUBJECT=mailto:COLOQUE-EMAIL-VALIDO@SEU-DOMINIO\n";
