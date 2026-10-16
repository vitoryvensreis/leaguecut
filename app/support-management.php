<?php
declare(strict_types=1);

/** B7C: explicit assignment, deadline since creation and staff-only management reports. */
function lc_support_tracking(int $ticketId): ?array {
    return lc_one('SELECT tr.*,u.nickname AS assigned_name FROM support_ticket_tracking tr
      LEFT JOIN users u ON u.id=tr.assigned_admin_id WHERE tr.ticket_id=?',[$ticketId]);
}
function lc_support_manage_update(int $ticketId,array $actor,int $assignedAdminId,int $slaHours): bool {
    if(($actor['role']??'')!=='admin'||($actor['status']??'')!=='approved')
        throw new InvalidArgumentException('Somente administradores podem gerenciar atendimento.');
    if($ticketId<1||$assignedAdminId<0||$slaHours<1||$slaHours>720)
        throw new InvalidArgumentException('Prazo SLA entre 1 e 720 horas e responsável válido.');
    $db=lc_db();$db->beginTransaction();
    try{
        $ticket=lc_one('SELECT id,requester_id,created_at FROM support_tickets WHERE id=? FOR UPDATE',[$ticketId]);
        if(!$ticket)throw new InvalidArgumentException('Chamado não encontrado.');
        if($assignedAdminId>0){
            $admin=lc_one('SELECT id FROM users WHERE id=? AND role=? AND status=?',
                [$assignedAdminId,'admin','approved']);
            if(!$admin)throw new InvalidArgumentException('Responsável precisa ser um ADM aprovado.');
        }
        $date=lc_one('SELECT DATE_ADD(?, INTERVAL ? HOUR) AS target_at',
            [(string)$ticket['created_at'],$slaHours]);
        $target=(string)($date['target_at']??'');
        if($target==='')throw new RuntimeException('Falha ao calcular SLA.');
        $previous=lc_one('SELECT assigned_admin_id,target_at FROM support_ticket_tracking WHERE ticket_id=? FOR UPDATE',[$ticketId]);
        $oldId=(int)($previous['assigned_admin_id']??0);
        $oldTarget=(string)($previous['target_at']??'');
        if($oldId===$assignedAdminId&&$oldTarget===$target){$db->commit();return false;}
        lc_query('INSERT INTO support_ticket_tracking(ticket_id,assigned_admin_id,target_at,updated_by)
           VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE
             assigned_admin_id=VALUES(assigned_admin_id),target_at=VALUES(target_at),updated_by=VALUES(updated_by)',
           [$ticketId,$assignedAdminId>0?$assignedAdminId:null,$target,(int)$actor['id']]);
        lc_query('INSERT INTO support_ticket_management_audit
          (ticket_id,actor_id,old_admin_id,new_admin_id,old_target_at,new_target_at)
          VALUES(?,?,?,?,?,?)',[
           $ticketId,(int)$actor['id'],$oldId?:null,$assignedAdminId?:null,
           $oldTarget?:null,$target
        ]);
        lc_realtime_emit('user',(int)$ticket['requester_id'],'support.ticket.updated','support_ticket',$ticketId);
        $db->commit();return true;
    }catch(Throwable $e){
        if($db->inTransaction())$db->rollBack();
        throw $e;
    }
}
function lc_support_management_routes(string $method,string $path): bool {
    if($path==='/admin/chamados/relatorios'){
        $actor=lc_admin();
        if($method!=='GET'){http_response_code(405);return true;}
        $summary=lc_one("SELECT COUNT(*) AS total,
          COALESCE(SUM(t.status='open'),0) AS open_count,
          COALESCE(SUM(t.status='in_progress'),0) AS in_progress,
          COALESCE(SUM(t.status='answered'),0) AS answered,
          COALESCE(SUM(t.status='closed'),0) AS closed,
          COALESCE(SUM(t.status<>'closed' AND tr.target_at<NOW()),0) AS overdue,
          COALESCE(SUM(t.status<>'closed' AND tr.target_at IS NULL),0) AS missing_sla
          FROM support_tickets t LEFT JOIN support_ticket_tracking tr ON tr.ticket_id=t.id")??[];
        $byAdmin=lc_all("SELECT u.nickname,COUNT(*) AS assigned,
            COALESCE(SUM(t.status<>'closed'),0) AS active,
            COALESCE(SUM(t.status<>'closed' AND tr.target_at<NOW()),0) AS overdue
          FROM support_ticket_tracking tr
          JOIN users u ON u.id=tr.assigned_admin_id
          JOIN support_tickets t ON t.id=tr.ticket_id
          GROUP BY u.id,u.nickname ORDER BY active DESC,u.id ASC LIMIT 100");
        $late=lc_all("SELECT t.id,t.subject,t.priority,t.status,tr.target_at,u.nickname
          FROM support_ticket_tracking tr JOIN support_tickets t ON t.id=tr.ticket_id
          LEFT JOIN users u ON u.id=tr.assigned_admin_id
          WHERE t.status<>'closed' AND tr.target_at<NOW()
          ORDER BY tr.target_at ASC LIMIT 100");
        lc_page('Relatórios de suporte',static function()use($summary,$byAdmin,$late){?>
<div class="container lc-support-page">
<?php lc_heading('Atendimento','Relatórios de suporte','Visão dos chamados, responsáveis e prazos de resposta.'); ?>
<p><a href="/admin/chamados">← Voltar aos chamados</a></p>
<section class="panel"><h2>Indicadores gerais</h2><div class="lc-support-kpis">
<?php foreach(['total'=>'Total','open_count'=>'Abertos','in_progress'=>'Em atendimento','answered'=>'Respondidos',
'closed'=>'Encerrados','overdue'=>'SLA vencido','missing_sla'=>'Sem SLA definido'] as $key=>$label): ?>
<div><strong><?=lc_e($summary[$key]??0)?></strong><span><?=lc_e($label)?></span></div>
<?php endforeach; ?></div></section>
<section class="panel"><h2>Por responsável</h2>
<div class="lc-ticket-table">
<?php if(!$byAdmin): ?><p class="hint">Nenhum chamado atribuído.</p><?php endif; ?>
<?php foreach($byAdmin as $r): ?><div class="lc-ticket-row" style="grid-template-columns:minmax(0,1fr) auto">
<strong>@<?=lc_e($r['nickname'])?></strong><span><?=lc_e($r['active'])?> ativos · <?=lc_e($r['overdue'])?> vencidos · <?=lc_e($r['assigned'])?> totais</span></div><?php endforeach; ?>
</div></section>
<section class="panel"><h2>Chamados com prazo vencido</h2>
<?php if(!$late): ?><p class="hint">Nenhum chamado com SLA vencido.</p><?php endif; ?>
<?php foreach($late as $r): ?><p>
<a href="/admin/chamados/<?=lc_e($r['id'])?>">#<?=lc_e($r['id'])?> — <?=lc_e($r['subject'])?></a>
· até <?=lc_e(date('d/m/Y H:i',strtotime($r['target_at'])))?> · <?=lc_e($r['nickname']??'Sem responsável')?></p><?php endforeach; ?>
</section></div>
<?php },'admin');
        return true;
    }
    if(!preg_match('~^/admin/chamados/([1-9][0-9]{0,13})/gestao$~D',$path,$matches))return false;
    $actor=lc_admin();
    if($method!=='POST'){http_response_code(405);return true;}
    $id=(int)$matches[1];
    try {
        $assigned=(string)($_POST['assigned_admin_id']??'0');
        $hours=(string)($_POST['sla_hours']??'48');
        if(!preg_match('/^[0-9]{1,12}$/D',$assigned)||!preg_match('/^[1-9][0-9]{0,2}$/D',$hours))
            throw new InvalidArgumentException('Informe responsável e prazo válidos.');
        $changed=lc_support_manage_update($id,$actor,(int)$assigned,(int)$hours);
        lc_flash($changed?'Atribuição e SLA salvos com auditoria.':'Nenhuma alteração necessária.');
    }catch(InvalidArgumentException $e){lc_flash($e->getMessage(),'error');}
     catch(Throwable $e){error_log('LEAGUECUT_SUPPORT_MANAGEMENT_FAILED');lc_flash('Não foi possível salvar gestão.','error');}
    lc_redirect('/admin/chamados/'.$id);
}
