<?php
declare(strict_types=1);

/**
 * Bloco 2: request -> admin-confirmed receipt -> paid monthly cycle.
 * No gateway, no payment charge, and NO entitlement from pending cycles.
 */
function lc_creator_cycle_end(string $start): string {
    $date=DateTimeImmutable::createFromFormat('!Y-m-d',$start,new DateTimeZone('America/Fortaleza'));
    if(!$date||$date->format('Y-m-d')!==$start)throw new InvalidArgumentException('Data de início inválida.');
    $next=$date->modify('first day of next month');
    $day=min((int)$date->format('d'),(int)$next->format('t'));
    return $next->setDate((int)$next->format('Y'),(int)$next->format('m'),$day)->format('Y-m-d');
}
function lc_creator_today(): string {
    return (new DateTimeImmutable('now',new DateTimeZone('America/Fortaleza')))->format('Y-m-d');
}
function lc_creator_effective_balance(int $subscriptionId,int $creatorId): ?array {
    // This check is mandatory for later download/delivery flows.
    // A database status of active alone must NEVER unlock credits.
    return lc_one("SELECT cy.id,cy.cycle_start,cy.cycle_end,cy.clips_included,cy.clips_used,
                          (cy.clips_included-cy.clips_used) AS remaining,
                          s.fulfillment_mode_snapshot AS mode
       FROM creator_subscription_cycles cy JOIN creator_subscriptions s ON s.id=cy.subscription_id
       WHERE cy.subscription_id=? AND s.creator_id=?
         AND s.status IN ('active','cancelled')
         AND cy.payment_status='confirmed'
         AND cy.cycle_start<=? AND cy.cycle_end>?
         AND cy.clips_used<=cy.clips_included
       ORDER BY cy.cycle_start DESC LIMIT 1",[$subscriptionId,$creatorId,lc_creator_today(),lc_creator_today()]);
}
function lc_creator_request_subscription(int $creatorId,int $packageId): string {
    if($creatorId<1||$packageId<1)throw new RuntimeException('Solicitação inválida.');
    $db=lc_db();
    $lockName='lc_creator_package_'.sha1($creatorId.':'.$packageId);
    if((int)$db->query("SELECT GET_LOCK(".$db->quote($lockName).",5)")->fetchColumn()!==1)
        throw new RuntimeException('Outra solicitação está sendo processada. Tente novamente.');
    try {
        $db->beginTransaction();
        try{
            $user=lc_one('SELECT id FROM users WHERE id=? AND role=? AND status=? FOR UPDATE',
                [$creatorId,'criador','approved']);
            if(!$user)throw new RuntimeException('Conta de criador não autorizada.');
            $package=lc_one('SELECT * FROM creator_packages WHERE id=? AND status=? FOR UPDATE',
                [$packageId,'active']);
            if(!$package)throw new RuntimeException('Este pacote não está disponível para novas assinaturas.');
            $open=lc_one("SELECT id FROM creator_subscriptions WHERE creator_id=? AND package_id=?
                    AND status IN ('pending','active','past_due') ORDER BY id DESC LIMIT 1",
                    [$creatorId,$packageId]);
            if($open)throw new RuntimeException('Você já possui uma solicitação ou assinatura desse pacote.');
            $prepaid=lc_one("SELECT s.id FROM creator_subscriptions s
                JOIN creator_subscription_cycles cy ON cy.subscription_id=s.id
                WHERE s.creator_id=? AND s.package_id=? AND cy.payment_status='confirmed'
                  AND cy.cycle_end>? ORDER BY cy.cycle_end DESC LIMIT 1",
                [$creatorId,$packageId,lc_creator_today()]);
            if($prepaid)throw new RuntimeException('Seu ciclo mensal pago ainda está em andamento.');
            lc_query("INSERT INTO creator_subscriptions
               (creator_id,package_id,status,price_cents_snapshot,monthly_clips_snapshot,fulfillment_mode_snapshot)
               VALUES(?,?,'pending',?,?,?)",[
                 $creatorId,$packageId,$package['monthly_price_cents'],$package['monthly_clips'],
                 $package['fulfillment_mode']]);
            $id=(int)$db->lastInsertId();
            lc_realtime_emit('admin',null,'creator.subscription.requested','creator_subscription',$id);
            $db->commit();
            return 'Assinatura solicitada. Aguarde a equipe confirmar os dados e o pagamento antes de liberar clipes.';
        }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    }finally{$db->query("SELECT RELEASE_LOCK(".$db->quote($lockName).")");}
}
function lc_creator_confirm_payment(int $subscriptionId,int $adminId,bool $renew=false): string {
    $db=lc_db();$db->beginTransaction();
    try {
        $s=lc_one('SELECT * FROM creator_subscriptions WHERE id=? FOR UPDATE',[$subscriptionId]);
        if(!$s)throw new RuntimeException('Assinatura não encontrada.');
        $creator=lc_one("SELECT id FROM users WHERE id=? AND role='criador' AND status='approved'",[$s['creator_id']]);
        if(!$creator)throw new RuntimeException('Criador não habilitado.');
        $last=lc_one('SELECT * FROM creator_subscription_cycles WHERE subscription_id=? ORDER BY cycle_end DESC LIMIT 1 FOR UPDATE',[$subscriptionId]);
        if($renew){
            if($s['status']!=='active'||$s['cancelled_at']!==null||!$last||$last['payment_status']!=='confirmed')
                throw new RuntimeException('Esta assinatura não aceita renovação. Confira o status e o pagamento.');
            if($last['cycle_end']>lc_creator_today())
                throw new RuntimeException('O ciclo atual ainda está válido. Não é possível antecipar a próxima cobrança.');
            $start=lc_creator_today();
        }else{
            if($s['status']!=='pending'||$last)
                throw new RuntimeException('O pagamento inicial já foi confirmado ou a solicitação não está pendente.');
            $start=lc_creator_today();
        }
        $end=lc_creator_cycle_end($start);
        // This event documents a HUMAN declaration of externally verified
        // receipt. We do not communicate with banks or issue a charge here.
        lc_query("INSERT INTO creator_subscription_cycles
           (subscription_id,cycle_start,cycle_end,clips_included,clips_used,payment_status)
           VALUES(?,?,?,?,0,'confirmed')",
           [$subscriptionId,$start,$end,$s['monthly_clips_snapshot']]);
        lc_query("UPDATE creator_subscriptions SET status='active',
             started_at=COALESCE(started_at,UTC_TIMESTAMP()),next_renewal_at=? WHERE id=?",
             [$end.' 00:00:00',$subscriptionId]);
        lc_realtime_emit('user',(int)$s['creator_id'],'creator.subscription.confirmed','creator_subscription',$subscriptionId);
        lc_realtime_emit('admin',null,'creator.subscription.confirmed','creator_subscription',$subscriptionId);
        $db->commit();
        error_log('LeagueCut creator payment receipt confirmed manually: subscription='.$subscriptionId.' admin='.$adminId.' cycle='.$start);
        return $renew?'Renovação registrada após confirmação manual de pagamento.':'Recebimento confirmado manualmente. Primeiro ciclo mensal liberado.';
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
}
function lc_creator_cancel_subscription(int $subscriptionId,int $actorId,bool $admin): string {
    $db=lc_db();$db->beginTransaction();
    try {
        $s=lc_one('SELECT * FROM creator_subscriptions WHERE id=? FOR UPDATE',[$subscriptionId]);
        if(!$s || (!$admin && (int)$s['creator_id']!==$actorId))
            throw new RuntimeException('Assinatura não encontrada ou acesso negado.');
        if(!in_array($s['status'],['pending','active','past_due'],true))
            throw new RuntimeException('Esta assinatura já está encerrada.');
        lc_query("UPDATE creator_subscriptions SET status='cancelled',cancelled_at=UTC_TIMESTAMP()
              WHERE id=?",[$subscriptionId]);
        lc_realtime_emit('user',(int)$s['creator_id'],'creator.subscription.cancelled','creator_subscription',$subscriptionId);
        lc_realtime_emit('admin',null,'creator.subscription.cancelled','creator_subscription',$subscriptionId);
        $db->commit();
        return 'Assinatura cancelada. Se houver um ciclo pago em andamento, ele continuará válido até a data final; não haverá renovação automática.';
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
}
function lc_creator_subscription_label(array $subscription): string {
    if($subscription['status']==='cancelled')return 'Cancelada';
    if($subscription['status']==='pending')return 'Aguardando confirmação';
    if($subscription['status']==='active'){
       if(!empty($subscription['cycle_end']) && $subscription['cycle_end']<=lc_creator_today())return 'Vencida — aguardando renovação';
       return 'Ativa';
    }
    return match($subscription['status']){'past_due'=>'Pagamento pendente','expired'=>'Expirada',default=>'Em análise'};
}
function lc_creator_billing_routes(string $method,string $path): bool {
    if($method==='POST' && preg_match('~^/criador/pacotes/([0-9]+)/solicitar$~D',$path,$m)){
        $u=lc_creator_only();
        try{lc_flash(lc_creator_request_subscription((int)$u['id'],(int)$m[1]));}
        catch(Throwable $e){lc_flash($e->getMessage(),'error');}
        lc_redirect('/criador');
    }
    if($method==='POST' && preg_match('~^/criador/assinaturas/([0-9]+)/cancelar$~D',$path,$m)){
        $u=lc_creator_only();
        try{lc_flash(lc_creator_cancel_subscription((int)$m[1],(int)$u['id'],false));}
        catch(Throwable $e){lc_flash($e->getMessage(),'error');}
        lc_redirect('/criador');
    }
    if($path==='/admin/assinaturas'){
        $admin=lc_admin();
        $subscriptions=lc_all("SELECT s.*,p.name AS package_name,u.full_name AS creator_name,u.email AS creator_email,
            (SELECT COUNT(*) FROM creator_subscription_cycles cy WHERE cy.subscription_id=s.id) AS cycles,
            (SELECT cy.cycle_end FROM creator_subscription_cycles cy WHERE cy.subscription_id=s.id
              ORDER BY cy.cycle_end DESC LIMIT 1) AS cycle_end,
            (SELECT cy.clips_used FROM creator_subscription_cycles cy WHERE cy.subscription_id=s.id
              ORDER BY cy.cycle_end DESC LIMIT 1) AS clips_used
            FROM creator_subscriptions s JOIN creator_packages p ON p.id=s.package_id
            JOIN users u ON u.id=s.creator_id
            ORDER BY FIELD(s.status,'pending','active','past_due','cancelled','expired'),s.created_at DESC LIMIT 300");
        lc_page('Assinaturas de criadores',function()use($subscriptions){?>
<div class="container"><?php lc_heading('Criadores','Assinaturas mensais','Confirme pagamentos recebidos fora da plataforma antes de liberar cada ciclo. Nenhuma cobrança ocorre automaticamente.');?>
<div class="panel">
<div class="ws-module-head"><h2>Solicitações e ciclos</h2><span class="tag"><?=count($subscriptions)?> registros</span></div>
<?php if(!$subscriptions):lc_empty('Nenhuma assinatura solicitada','Quando um criador solicitar um pacote, ele aparecerá aqui para avaliação.');else:?>
<div class="table-wrap"><table>
<thead><tr><th>Criador</th><th>Plano</th><th>Valor / mês</th><th>Modelo</th><th>Clipes / mês</th><th>Situação</th><th>Ações</th></tr></thead><tbody>
<?php foreach($subscriptions as $s):?><tr>
<td><strong><?=lc_e($s['creator_name'])?></strong><small><?=lc_e($s['creator_email'])?></small></td>
<td><?=lc_e($s['package_name'])?></td><td><?=lc_money($s['price_cents_snapshot'])?></td>
<td><?=$s['fulfillment_mode_snapshot']==='download'?'Download':'Produção + postagem'?></td>
<td><?=(int)$s['monthly_clips_snapshot']?><?php if($s['cycle_end']):?><small>Período até <?=date('d/m/Y',strtotime($s['cycle_end']))?> (exclusive)</small><?php endif;?></td>
<td><span class="tag"><?=lc_e(lc_creator_subscription_label($s))?></span></td>
<td><div class="creator-billing-actions">
<?php if($s['status']==='pending' || ($s['status']==='active' && $s['cycle_end'] && $s['cycle_end']<=lc_creator_today() && !$s['cancelled_at'])):?>
<form method="post" action="/admin/assinaturas/<?=(int)$s['id']?>/confirmar" onsubmit="return confirm('Você verificou o recebimento do pagamento desta mensalidade FORA da plataforma? Confirme somente após conferir o extrato.');">
<?=lc_csrf()?><input type="hidden" name="receipt_confirmed" value="CONFIRMO_RECEBIMENTO">
<button class="btn btn-sm" type="submit"><?=$s['status']==='pending'?'Confirmar recebimento':'Confirmar renovação'?></button></form>
<?php endif;?>
<?php if(in_array($s['status'],['pending','active','past_due'],true)):?>
<form method="post" action="/admin/assinaturas/<?=(int)$s['id']?>/cancelar" onsubmit="return confirm('Cancelar esta assinatura? Um ciclo já pago permanece válido até o encerramento.');">
<?=lc_csrf()?><button class="btn btn-ghost btn-sm" type="submit">Cancelar</button></form>
<?php endif;?>
</div></td></tr><?php endforeach;?></tbody></table></div><?php endif;?>
</div></div>
<?php },'admin');return true;
    }
    if($method==='POST'&&preg_match('~^/admin/assinaturas/([0-9]+)/(confirmar|cancelar)$~D',$path,$m)){
        $admin=lc_admin();
        try{
            if($m[2]==='cancelar')$message=lc_creator_cancel_subscription((int)$m[1],(int)$admin['id'],true);
            else{
                if(lc_post('receipt_confirmed',40)!=='CONFIRMO_RECEBIMENTO')
                    throw new RuntimeException('Confirme o recebimento real do pagamento antes de liberar o ciclo.');
                $sub=lc_one('SELECT status FROM creator_subscriptions WHERE id=?',[(int)$m[1]]);
                if(!$sub)throw new RuntimeException('Assinatura não encontrada.');
                $message=lc_creator_confirm_payment((int)$m[1],(int)$admin['id'],$sub['status']==='active');
            }
            lc_flash($message);
        }catch(Throwable $e){lc_flash($e->getMessage(),'error');}
        lc_redirect('/admin/assinaturas');
    }
    return false;
}
