<?php
declare(strict_types=1);

/** Web Push B6D2b2. Secrets must only exist in the VPS' private shared .env. */
function lc_push_vapid_configured(): bool {
    $pub=lc_env('PUSH_VAPID_PUBLIC');
    $priv=lc_env('PUSH_VAPID_PRIVATE');
    $subject=lc_env('PUSH_VAPID_SUBJECT');
    $decode=static function(string $key):string|false {
        if(!preg_match('/^[A-Za-z0-9_-]+$/D',$key))return false;
        return base64_decode(strtr($key,'-_','+/').str_repeat('=',(4-strlen($key)%4)%4),true);
    };
    $publicBytes=$decode($pub);$privateBytes=$decode($priv);
    return is_string($publicBytes)&&strlen($publicBytes)===65&&$publicBytes[0]==="\x04"&&
       is_string($privateBytes)&&strlen($privateBytes)===32&&
       str_starts_with($subject,'mailto:')&&
       (bool)filter_var(substr($subject,7),FILTER_VALIDATE_EMAIL)&&
       class_exists(\Minishlink\WebPush\WebPush::class);
}
function lc_push_send_ready(): bool {
    return lc_push_vapid_configured() &&
       lc_push_registration_enabled() && lc_env('PUSH_SENDING_ENABLED','0')==='1';
}
function lc_push_payload(int $eventId,string $scope,string $role): string {
    $url=$scope==='admin'?'/admin':($role==='criador'?'/criador':'/dashboard');
    return json_encode([
        'id'=>$eventId,
        'title'=>'LeagueCut',
        // Do not send personally identifying or financial event data to third-party push providers.
        'body'=>$scope==='admin'?'Há uma nova atualização na administração.':
           'Uma informação da sua conta foi atualizada.',
        'url'=>$url
    ],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
}
function lc_push_queue_new(): int {
    if(!lc_push_send_ready())return 0;
    // Only messages produced AFTER subscription; existing history never triggers push.
    // Supported events are explicitly whitelisted to prevent floods from diagnostic events.
    return lc_query("INSERT IGNORE INTO push_deliveries (subscription_id,event_id)
      SELECT s.id,e.id FROM push_subscriptions s
      JOIN push_preferences p ON p.user_id=s.user_id AND p.enabled=1
      JOIN users u ON u.id=s.user_id AND u.status='approved'
      JOIN realtime_events e
        ON e.created_at>=s.created_at AND e.created_at>=DATE_SUB(NOW(),INTERVAL 48 HOUR)
        AND ((e.scope='user' AND e.user_id=s.user_id)
          OR (e.scope='admin' AND u.role='admin'))
        AND e.event_type IN
          ('creator.registered','creator.approved','creator.reviewed',
           'clip.submitted','clip.reviewed','withdrawal.requested',
           'withdrawal.updated','campaign.updated','automation.notice')
      ORDER BY e.id ASC LIMIT 120")->rowCount();
}
function lc_push_sender_instance(): \Minishlink\WebPush\WebPush {
    return new \Minishlink\WebPush\WebPush(['VAPID'=>[
        'subject'=>lc_env('PUSH_VAPID_SUBJECT'),
        'publicKey'=>lc_env('PUSH_VAPID_PUBLIC'),
        'privateKey'=>lc_env('PUSH_VAPID_PRIVATE')
    ]],['TTL'=>600,'urgency'=>'normal','batchSize'=>1]);
}
function lc_push_send_batch(?callable $deliver=null): array {
    $stats=['queued'=>0,'accepted'=>0,'retried'=>0,'expired'=>0];
    if(!lc_push_send_ready())return $stats;
    $stats['queued']=lc_push_queue_new();
    $jobs=lc_all("SELECT d.id,d.subscription_id,d.attempts,
      s.endpoint_enc,s.keys_enc,e.id AS event_id,e.scope,u.role
      FROM push_deliveries d
      JOIN push_subscriptions s ON s.id=d.subscription_id
      JOIN push_preferences p ON p.user_id=s.user_id AND p.enabled=1
      JOIN users u ON u.id=s.user_id AND u.status='approved'
      JOIN realtime_events e ON e.id=d.event_id
      WHERE d.available_at<=NOW() AND d.attempts<4
        AND (d.status='pending' OR (d.status='processing' AND d.available_at<NOW()))
        AND ((e.scope='user' AND e.user_id=s.user_id)
         OR (e.scope='admin' AND u.role='admin'))
      ORDER BY d.id ASC LIMIT 20");
    foreach($jobs as $job){
        $claim=lc_query("UPDATE push_deliveries SET status='processing',
           attempts=attempts+1,available_at=DATE_ADD(NOW(),INTERVAL 5 MINUTE)
           WHERE id=? AND attempts<4 AND available_at<=NOW()
           AND (status='pending' OR status='processing')",[(int)$job['id']]);
        if($claim->rowCount()!==1)continue;
        try{
            $endpoint=lc_decrypt((string)$job['endpoint_enc']);
            $keys=json_decode(lc_decrypt((string)$job['keys_enc']),true,6,JSON_THROW_ON_ERROR);
            $safe=lc_push_check_subscription(['endpoint'=>$endpoint,'keys'=>$keys]);
            $payload=lc_push_payload((int)$job['event_id'],(string)$job['scope'],(string)$job['role']);
            if($deliver!==null){
                $result=$deliver($safe,$payload);
                $success=($result['success']??false)===true;
                $expired=($result['expired']??false)===true;
            }else{
                $client=lc_push_sender_instance();
                $sub=\Minishlink\WebPush\Subscription::create($safe);
                $report=$client->sendOneNotification($sub,$payload);
                $success=$report->isSuccess();
                $expired=$report->isSubscriptionExpired();
            }
            if($expired){
                lc_query('DELETE FROM push_subscriptions WHERE id=?',[(int)$job['subscription_id']]);
                $stats['expired']++;
                continue;
            }
            if(!$success)throw new RuntimeException('Push provider refused delivery');
            // This means accepted by push provider, NOT proof of display on device.
            lc_query("UPDATE push_deliveries SET status='sent',delivered_at=NOW(),last_error=NULL WHERE id=?",[(int)$job['id']]);
            lc_query('UPDATE push_subscriptions SET last_success_at=NOW() WHERE id=?',[(int)$job['subscription_id']]);
            $stats['accepted']++;
        }catch(Throwable $e){
            $attempt=(int)$job['attempts']+1;
            $delay=min(120,5*(2**max(0,$attempt-1)));
            lc_query("UPDATE push_deliveries SET status=?,last_error=?,
               available_at=DATE_ADD(NOW(),INTERVAL ? MINUTE) WHERE id=?",[
                $attempt>=4?'failed':'pending','Push provider did not accept', $delay,(int)$job['id']
            ]);
            error_log('LEAGUECUT_PUSH_RETRY job='.(int)$job['id']);
            $stats['retried']++;
        }
    }
    return $stats;
}
