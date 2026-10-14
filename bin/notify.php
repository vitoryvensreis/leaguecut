<?php
declare(strict_types=1);
/** Cron: every 5 minutes after SMTP + WhatsApp template have been configured.
 * sudo -u leaguecut /usr/bin/php8.4 /home/leaguecut/application/bin/notify.php
 */
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__) . '/app/core.php';
require dirname(__DIR__) . '/vendor/autoload.php';

function lc_notify_email(array $user,array $item,array $payload): void {
    foreach(['MAIL_HOST','MAIL_USERNAME','MAIL_PASSWORD','MAIL_FROM'] as $key) if(lc_env($key)==='')throw new RuntimeException('SMTP não configurado: ' . $key);
    $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
    $mail->isSMTP();
    $mail->Host = lc_env('MAIL_HOST');
    $mail->Port = (int)lc_env('MAIL_PORT','587');
    $mail->SMTPAuth = true;
    $mail->Username = lc_env('MAIL_USERNAME');
    $mail->Password = lc_env('MAIL_PASSWORD');
    $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
    $mail->CharSet = 'UTF-8';
    $mail->setFrom(lc_env('MAIL_FROM'),lc_env('MAIL_FROM_NAME','LeagueCut'));
    $mail->addAddress($user['email'],$user['full_name']);
    $mail->Subject = 'Seu cadastro no LeagueCut foi aprovado!';
    $mail->Body = 'Olá, ' . $user['full_name'] . "! Seu cadastro foi aprovado. Acesse: " . lc_env('APP_URL','https://leaguecut.com') . '/entrar';
    $mail->send();
}
function lc_notify_whatsapp(array $user,array $item,array $payload): void {
    foreach(['WHATSAPP_TOKEN','WHATSAPP_PHONE_NUMBER_ID','WHATSAPP_TEMPLATE'] as $key)if(lc_env($key)==='')throw new RuntimeException('WhatsApp não configurado: ' . $key);
    if(!(bool)$user['consent_whatsapp'])throw new RuntimeException('Usuário não autorizou WhatsApp.');
    $phone=preg_replace('/\D/','',$user['whatsapp']);
    if(strlen($phone)===11)$phone='55'.$phone;
    $body=[
      'messaging_product'=>'whatsapp','to'=>$phone,'type'=>'template',
      'template'=>['name'=>lc_env('WHATSAPP_TEMPLATE'), 'language'=>['code'=>lc_env('WHATSAPP_TEMPLATE_LANG','pt_BR')],
        'components'=>[['type'=>'body','parameters'=>[['type'=>'text','text'=>explode(' ',$user['full_name'])[0]]]]]]
    ];
    $ch=curl_init('https://graph.facebook.com/v24.0/' . rawurlencode(lc_env('WHATSAPP_PHONE_NUMBER_ID')) . '/messages');
    curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>25,CURLOPT_HTTPHEADER=>['Authorization: Bearer ' . lc_env('WHATSAPP_TOKEN'),'Content-Type: application/json'],CURLOPT_POSTFIELDS=>json_encode($body,JSON_THROW_ON_ERROR)]);
    $response=curl_exec($ch);$code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);$error=curl_error($ch);curl_close($ch);
    if($code<200||$code>=300)throw new RuntimeException('Falha API WhatsApp HTTP ' . $code . ': ' . substr((string)$response,0,140) . $error);
}
$jobs=lc_all("SELECT n.*,u.email,u.whatsapp,u.full_name,u.consent_whatsapp FROM notification_outbox n JOIN users u ON u.id=n.user_id
              WHERE n.status='pending' AND n.available_at<=NOW() AND n.attempts<5 ORDER BY n.id ASC LIMIT 30");
foreach($jobs as $job){
    $channel=$job['channel'];
    // Leave pending if providers have not been connected; never fake deliveries.
    if($channel==='email'&&lc_env('MAIL_HOST')==='')continue;
    if($channel==='whatsapp'&&lc_env('WHATSAPP_TOKEN')==='')continue;
    $locked=lc_query("UPDATE notification_outbox SET status='processing',attempts=attempts+1 WHERE id=? AND status='pending'",[$job['id']]);
    if($locked->rowCount()!==1)continue;
    try{
        $payload=json_decode($job['payload_json'],true,512,JSON_THROW_ON_ERROR);
        if($channel==='email')lc_notify_email($job,$job,$payload);
        elseif($channel==='whatsapp')lc_notify_whatsapp($job,$job,$payload);
        else throw new RuntimeException('Canal desconhecido');
        lc_query("UPDATE notification_outbox SET status='sent',sent_at=NOW(),last_error=NULL WHERE id=?",[$job['id']]);
        echo "Enviado #{$job['id']} ({$channel})\n";
    }catch(Throwable $e){
        $tries=(int)$job['attempts']+1;
        $state=$tries>=5?'failed':'pending';
        $delay=min(240,5*(2**$tries));
        lc_query('UPDATE notification_outbox SET status=?,last_error=?,available_at=DATE_ADD(NOW(),INTERVAL ? MINUTE) WHERE id=?',[$state,mb_substr($e->getMessage(),0,500),$delay,$job['id']]);
        fwrite(STDERR,"Falha #{$job['id']}: {$e->getMessage()}\n");
    }
}
