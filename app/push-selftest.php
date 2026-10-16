<?php
declare(strict_types=1);

/** B7D: user-initiated background Push smoke test; no system secrets in payload. */
function lc_push_selftest_enqueue(array $user): int {
    if(($user['status']??'')!=='approved')throw new InvalidArgumentException('Conta não aprovada.');
    if(!lc_push_send_ready())throw new InvalidArgumentException('O Web Push ainda não foi liberado na VPS.');
    $uid=(int)$user['id'];if($uid<1)throw new InvalidArgumentException('Conta inválida.');
    $db=lc_db();$db->beginTransaction();
    try{
        $owner=lc_one('SELECT id,status FROM users WHERE id=? FOR UPDATE',[$uid]);
        if(!$owner||$owner['status']!=='approved')throw new InvalidArgumentException('Conta sem acesso.');
        $active=(int)(lc_one('SELECT COUNT(*) AS n FROM push_subscriptions s
          JOIN push_preferences p ON p.user_id=s.user_id AND p.enabled=1
          WHERE s.user_id=?',[$uid])['n']??0);
        if($active<1)throw new InvalidArgumentException('Ative Web Push neste dispositivo antes de solicitar um teste.');
        $previous=lc_one("SELECT id FROM realtime_events
           WHERE scope='user' AND user_id=? AND event_type='push.test'
             AND created_at>=DATE_SUB(NOW(),INTERVAL 5 MINUTE) LIMIT 1",[$uid]);
        if($previous)throw new InvalidArgumentException('Aguarde 5 minutos para solicitar outro teste.');
        lc_realtime_emit('user',$uid,'push.test','push_test',null);
        $id=(int)$db->lastInsertId();
        $db->commit();return $id;
    }catch(Throwable $e){
        if($db->inTransaction())$db->rollBack();
        throw $e;
    }
}
function lc_push_selftest_routes(string $method,string $path): bool {
    if($path!=='/notificacoes/teste')return false;
    $user=lc_login_required();
    if($method!=='POST'){http_response_code(405);header('Allow: POST');return true;}
    try{
        lc_push_selftest_enqueue($user);
        lc_flash('Teste registrado para suas assinaturas ativas. Aguarde até alguns minutos; somente o navegador pode confirmar o recebimento real.');
    }catch(InvalidArgumentException $e){lc_flash($e->getMessage(),'error');}
    catch(Throwable $e){error_log('LEAGUECUT_PUSH_TEST_FAILED');lc_flash('Não foi possível iniciar o teste.','error');}
    lc_redirect('/notificacoes');
}
