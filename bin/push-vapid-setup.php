<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit(1);}
require dirname(__DIR__).'/vendor/autoload.php';
if(count($argv)!==2||!preg_match('/^mailto:.+@.+\..+$/D',(string)$argv[1])){
    fwrite(STDERR,"Uso: php bin/push-vapid-setup.php mailto:contato@seudominio.com\n");
    exit(2);
}
if(!filter_var(substr($argv[1],7),FILTER_VALIDATE_EMAIL))exit(2);
$env='/home/leaguecut/shared/.env';
if(!is_file($env)||is_link($env)){
    fwrite(STDERR,"Arquivo .env privado não encontrado ou não é um arquivo regular.\n");
    exit(1);
}
$fp=fopen($env,'c+');
if(!$fp||!flock($fp,LOCK_EX))exit(1);
try{
    $original=stream_get_contents($fp);
    if(!is_string($original))throw new RuntimeException('Não foi possível ler o .env');
    foreach(['PUSH_VAPID_PUBLIC','PUSH_VAPID_PRIVATE'] as $key){
        if(preg_match('/^'.preg_quote($key,'/').'\s*=\s*(\S.*?)\s*$/m',$original,$m) && trim($m[1])!==''){
            echo "VAPID já está configurado. Não houve rotação ou alteração.\n";
            exit(0);
        }
    }
    $keys=\Minishlink\WebPush\VAPID::createVapidKeys();
    foreach(['publicKey','privateKey'] as $k)if(empty($keys[$k]))throw new RuntimeException('Erro na geração VAPID');
    $next=$original;
    $values=[
        'PUSH_VAPID_PUBLIC'=>$keys['publicKey'],
        'PUSH_VAPID_PRIVATE'=>$keys['privateKey'],
        'PUSH_VAPID_SUBJECT'=>$argv[1]
    ];
    foreach($values as $key=>$value){
        if(preg_match('/^'.preg_quote($key,'/').'\s*=/m',$next))
            $next=(string)preg_replace('/^'.preg_quote($key,'/').'\s*=.*$/m',$key.'='.$value,$next);
        else $next=rtrim($next)."\n".$key.'='.$value."\n";
    }
    $backup=$env.'.before-push-'.gmdate('YmdHis');
    if(file_put_contents($backup,$original,LOCK_EX)===false)throw new RuntimeException('Backup privado falhou');
    chmod($backup,0600);
    rewind($fp);
    if(!ftruncate($fp,0)||fwrite($fp,$next)!==strlen($next))
        throw new RuntimeException('Não foi possível atualizar .env');
    fflush($fp);
    chmod($env,0600);
    echo "Chaves VAPID salvas no .env privado. Nenhum segredo foi exibido.\n";
    echo "As flags permanecem DESLIGADAS. Habilite somente após teste em navegador real.\n";
}finally{
    flock($fp,LOCK_UN);
    fclose($fp);
}
