<?php
declare(strict_types=1);

/** Called by the existing managed LeagueCut cron, never as a web endpoint. */
function lc_push_worker_run(): void {
    if(!lc_push_vapid_ready())return; // Never claim delivery when VAPID not configured.
    $authorization=['VAPID'=>[
        'subject'=>lc_env('PUSH_VAPID_SUBJECT'),
        'publicKey'=>lc_env('PUSH_VAPID_PUBLIC'),
        'privateKey'=>lc_env('PUSH_VAPID_PRIVATE')
    ]];
    $push=new \Minishlink\WebPush\WebPush($authorization);
    // Build an idempotent queue for NEW events after user subscribed. No prior history is pushed.
    lc_query("INSERT IGNORE INTO push_deliveries(subscription_id,event_id)
       SELECT s.id,e.id FROM push_subscriptions s
       JOIN push_preferences p ON p.user_id=s.user_id AND p.enabled=1
       JOIN users u ON u.id=s.user_id AND u.status IN ('approved','pending')
       JOIN realtime_events e ON e.created_at>=s.created_at
         AND e.created_at>=DATE_SUB(NOW(),INTERVAL 48 HOUR)
         AND ((e.scope='user' AND e.user_id=s.user_id)
          OR (e.scope='admin' AND u.role='admin'))
       ORDER BY e.id DESC LIMIT 100");
    $jobs=lc_all("SELECT d.id,d.subscription_id,d.attempts,s.endpoint_enc,s.keys_enc,
       e.id AS event_id,e.event_type,e.scope,u.role AS user_role
       FROM push_deliveries d
       JOIN push_subscriptions s ON s.id=d.subscription_id
       JOIN realtime_events e ON e.id=d.event_id
       JOIN users u ON u.id=s.user_id
       JOIN push_preferences p ON p.user_id=s.user_id AND p.enabled=1
       WHERE (d.status='pending' OR (d.status='processing' AND d.available_at<NOW()))
         AND d.available_at<=NOW() AND d.attempts<4
       ORDER BY d.id ASC LIMIT 20");
    foreach($jobs as $job){
        $changed=lc_query("UPDATE push_deliveries SET status='processing',
             attempts=attempts+1,available_at=DATE_ADD(NOW(),INTERVAL 5 MINUTE)
             WHERE id=? AND (status='pending' OR (status='processing' AND available_at<NOW()))",[$job['id']]);
        if($changed->rowCount()!==1)continue;
        try{
            $endpoint=lc_decrypt((string)$job['endpoint_enc']);
            $keys=json_decode(lc_decrypt((string)$job['keys_enc']),true,6,JSON_THROW_ON_ERROR);
            if(!lc_push_endpoint_valid($endpoint)||!is_array($keys))throw new RuntimeException('Dispositivo inválido.');
            $payload=json_encode([
                'id'=>(int)$job['event_id'],
                'title'=>'LeagueCut',
                'body'=>$job['scope']==='admin'?'Há uma atualização aguardando no painel.':'Uma informação da sua conta foi atualizada.',
                'url'=>$job['user_role']==='admin'?'/admin':($job['user_role']==='criador'?'/criador':'/dashboard')
            ],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
            $sub=\Minishlink\WebPush\Subscription::create([
                'endpoint'=>$endpoint,'keys'=>$keys,
                'contentEncoding'=>'aes128gcm'
            ]);
            $push->queueNotification($sub,$payload);
            $sent=false;$expired=false;
            foreach($push->flush() as $report){
                $sent=$report->isSuccess();
                $expired=$report->isSubscriptionExpired();
            }
            if($expired){
                lc_query('DELETE FROM push_subscriptions WHERE id=?',[$job['subscription_id']]);
                continue;
            }
            if(!$sent)throw new RuntimeException('Provedor push não confirmou entrega.');
            lc_query("UPDATE push_deliveries SET status='sent',delivered_at=NOW(),last_error=NULL WHERE id=?",[$job['id']]);
            lc_query('UPDATE push_subscriptions SET last_success_at=NOW() WHERE id=?',[$job['subscription_id']]);
            echo "PUSH_PROVIDER_ACCEPTED #".(int)$job['id']."\n";
        }catch(Throwable $e){
            $tries=(int)$job['attempts']+1;
            lc_query("UPDATE push_deliveries SET status=?,last_error=?,
              available_at=DATE_ADD(NOW(),INTERVAL ? MINUTE) WHERE id=?",[
                 $tries>=4?'failed':'pending','Não confirmado pelo provedor',min(120,5*$tries),$job['id']
            ]);
            error_log('LEAGUECUT_PUSH_FAILED #'.(int)$job['id']);
        }
    }
}
