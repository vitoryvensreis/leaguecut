<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit(1);}
require dirname(__DIR__).'/app/core.php';
require dirname(__DIR__).'/app/realtime.php';
require dirname(__DIR__).'/app/automation-engine.php';
require dirname(__DIR__).'/vendor/autoload.php';

function lc_automation_send_email(array $user,string $subject,string $body): void {
    foreach(['MAIL_HOST','MAIL_USERNAME','MAIL_PASSWORD','MAIL_FROM'] as $key){
        if(lc_env($key)==='')throw new RuntimeException('SMTP não está configurado.');
    }
    $mail=new \PHPMailer\PHPMailer\PHPMailer(true);
    $mail->isSMTP();
    $mail->Host=lc_env('MAIL_HOST');
    $mail->Port=(int)lc_env('MAIL_PORT','587');
    $mail->SMTPAuth=true;
    $mail->Username=lc_env('MAIL_USERNAME');
    $mail->Password=lc_env('MAIL_PASSWORD');
    $mail->SMTPSecure=\PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
    $mail->CharSet='UTF-8';
    $mail->setFrom(lc_env('MAIL_FROM'),lc_env('MAIL_FROM_NAME','LeagueCut'));
    $mail->addAddress($user['email'],$user['full_name']);
    $mail->Subject=$subject;
    $mail->Body=$body;
    $mail->send();
}
$pending=lc_all("SELECT d.id,d.attempts,r.id AS rule_id,r.enabled,r.name,COALESCE(d.action_type,r.action_type) AS action_type,
   COALESCE(d.message_template,r.message_template) AS message_template,
   e.trigger_code,e.context_json,u.id AS user_id,u.full_name,u.email,u.whatsapp,u.consent_whatsapp,u.status AS user_status
   FROM automation_deliveries d
   JOIN automation_rules r ON r.id=d.rule_id
   JOIN automation_events e ON e.id=d.event_id
   JOIN users u ON u.id=d.recipient_user_id
   WHERE (d.status='pending' OR (d.status='processing' AND d.available_at<NOW()))
      AND d.available_at<=NOW() AND d.attempts<5 ORDER BY d.id LIMIT 10");
foreach($pending as $job){
    $channel=$job['action_type'];
    // Unconfigured transports do not count as an attempt or claim a successful send.
    if($channel==='whatsapp'&&!lc_wpp_ready())continue;
    if($channel==='email'&&lc_env('MAIL_HOST')==='')continue;
    $claimed=lc_query("UPDATE automation_deliveries
       SET status='processing',attempts=attempts+1,available_at=DATE_ADD(NOW(),INTERVAL 5 MINUTE)
       WHERE id=? AND (status='pending' OR (status='processing' AND available_at<NOW()))",[$job['id']]);
    if($claimed->rowCount()!==1)continue;
    try {
        if(!(int)$job['enabled']||$job['user_status']!=='approved'){
            lc_query("UPDATE automation_deliveries SET status='skipped',last_error='Regra inativa ou usuário sem acesso' WHERE id=?",[$job['id']]);
            continue;
        }
        if($channel==='whatsapp' && !(int)$job['consent_whatsapp']){
            lc_query("UPDATE automation_deliveries SET status='skipped',last_error='Sem consentimento WhatsApp' WHERE id=?",[$job['id']]);
            continue;
        }
        $context=json_decode((string)$job['context_json'],true,512,JSON_THROW_ON_ERROR);
        $body=lc_automation_message((string)$job['message_template'],$job,$context);
        if($body==='')throw new RuntimeException('Mensagem vazia.');
        if($channel==='whatsapp')lc_wpp_send((string)$job['whatsapp'],$body);
        elseif($channel==='email')lc_automation_send_email($job,'LeagueCut · '.$job['name'],$body);
        elseif($channel==='in_app')lc_realtime_emit('user',(int)$job['user_id'],'automation.notice','automation',(int)$job['rule_id']);
        else throw new RuntimeException('Ação desconhecida.');
        lc_query("UPDATE automation_deliveries SET status='sent',sent_at=NOW(),last_error=NULL WHERE id=?",[$job['id']]);
        echo "AUTOMATION_SENT #".$job['id']." (".$channel.")\n";
        if($channel==='whatsapp')usleep(750000);
    }catch(Throwable $e){
        $tries=(int)$job['attempts']+1;
        $newStatus=$tries>=5?'failed':'pending';
        $delay=min(120,2*(2**$tries));
        lc_query("UPDATE automation_deliveries SET status=?,last_error=?,available_at=DATE_ADD(NOW(),INTERVAL ? MINUTE) WHERE id=?",
            [$newStatus,mb_substr($e->getMessage(),0,255),$delay,$job['id']]);
        fwrite(STDERR,"AUTOMATION_FAILED #".$job['id']." (".$channel.")\n");
    }
}
