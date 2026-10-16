<?php
declare(strict_types=1);

/** B6E2: atomic, auditable and strictly ticket-owner-scoped discussions. */
function lc_support_reply(int $ticketId,array $actor,string $message): void {
    $message=trim(str_replace(["\r\n","\r"],"\n",$message));
    if(mb_strlen($message)<3||mb_strlen($message)>3000)
        throw new InvalidArgumentException('A resposta deve ter entre 3 e 3.000 caracteres.');
    $isAdmin=$actor['role']==='admin'&&$actor['status']==='approved';
    $db=lc_db();$db->beginTransaction();
    try{
        $ticket=lc_one('SELECT id,requester_id,status FROM support_tickets WHERE id=? FOR UPDATE',[$ticketId]);
        if(!$ticket||(!$isAdmin&&(int)$ticket['requester_id']!==(int)$actor['id']))
            throw new InvalidArgumentException('Chamado indisponível.');
        if($ticket['status']==='closed'&&!$isAdmin)
            throw new InvalidArgumentException('Este chamado foi encerrado. Abra um novo protocolo se precisar de mais ajuda.');
        // Idempotence guard for browser double-submit.
        $last=lc_one('SELECT author_id,body,(created_at>=DATE_SUB(NOW(), INTERVAL 10 SECOND)) AS recent
           FROM support_messages WHERE ticket_id=? ORDER BY id DESC LIMIT 1',[$ticketId]);
        if($last&&(int)$last['author_id']===(int)$actor['id']&&
           (int)$last['recent']===1&&hash_equals((string)$last['body'],$message))
            throw new InvalidArgumentException('Esta resposta já foi enviada.');
        lc_query('INSERT INTO support_messages(ticket_id,author_id,body) VALUES(?,?,?)',
            [$ticketId,(int)$actor['id'],$message]);
        lc_query("INSERT INTO support_ticket_activity(ticket_id,actor_id,action)
             VALUES(?,?,'message')",[$ticketId,(int)$actor['id']]);
        $next=$isAdmin?'answered':($ticket['status']==='answered'?'open':$ticket['status']);
        if($next!==$ticket['status']){
            lc_query('UPDATE support_tickets SET status=?,updated_at=NOW() WHERE id=?',[$next,$ticketId]);
            lc_query("INSERT INTO support_ticket_activity(ticket_id,actor_id,action,old_value,new_value)
             VALUES(?,?,'status',?,?)",[$ticketId,(int)$actor['id'],$ticket['status'],$next]);
        }else{
            lc_query('UPDATE support_tickets SET updated_at=NOW() WHERE id=?',[$ticketId]);
        }
        if($isAdmin)lc_realtime_emit('user',(int)$ticket['requester_id'],'support.ticket.answered','support_ticket',$ticketId);
        else lc_realtime_emit('admin',null,'support.ticket.replied','support_ticket',$ticketId);
        $db->commit();
    }catch(Throwable $e){
        if($db->inTransaction())$db->rollBack();
        throw $e;
    }
}
function lc_support_admin_update(int $ticketId,array $admin,string $status,string $priority): bool {
    if($admin['role']!=='admin'||$admin['status']!=='approved')
       throw new InvalidArgumentException('Acesso negado.');
    if(!in_array($status,['open','in_progress','answered','closed'],true)||
       !in_array($priority,['normal','high'],true))
       throw new InvalidArgumentException('Status ou prioridade inválidos.');
    $db=lc_db();$db->beginTransaction();
    try{
        $ticket=lc_one('SELECT id,requester_id,status,priority FROM support_tickets WHERE id=? FOR UPDATE',[$ticketId]);
        if(!$ticket)throw new InvalidArgumentException('Chamado não encontrado.');
        $changed=false;
        foreach(['status'=>$status,'priority'=>$priority] as $field=>$value){
            if($ticket[$field]===$value)continue;
            $changed=true;
            lc_query("INSERT INTO support_ticket_activity(ticket_id,actor_id,action,old_value,new_value)
                VALUES(?,?,?,?,?)",[$ticketId,(int)$admin['id'],$field,$ticket[$field],$value]);
        }
        if($changed){
            lc_query('UPDATE support_tickets SET status=?,priority=?,updated_at=NOW() WHERE id=?',[$status,$priority,$ticketId]);
            lc_realtime_emit('user',(int)$ticket['requester_id'],'support.ticket.updated','support_ticket',$ticketId);
        }
        $db->commit();
        return $changed;
    }catch(Throwable $e){
        if($db->inTransaction())$db->rollBack();
        throw $e;
    }
}
function lc_support_actions_routes(string $method,string $path): bool {
    if(!preg_match('~^/(suporte|admin/chamados)/([1-9][0-9]{0,13})/(responder|atualizar)$~D',$path,$m))return false;
    $staff=$m[1]==='admin/chamados';
    $actor=$staff?lc_admin():lc_login_required();
    $id=(int)$m[2];
    if($method!=='POST'){http_response_code(405);header('Allow: POST');return true;}
    if($m[3]==='atualizar'&&!$staff){http_response_code(403);return true;}
    $back=($staff?'/admin/chamados/':'/suporte/').$id;
    try{
        if($m[3]==='responder'){
           lc_support_reply($id,$actor,(string)($_POST['body']??''));
           lc_flash('Resposta enviada e registrada no histórico.');
        }elseif($m[3]==='atualizar'){
           $changed=lc_support_admin_update($id,$actor,
               (string)($_POST['status']??''),(string)($_POST['priority']??''));
           lc_flash($changed?'Chamado atualizado.':'Nenhuma alteração necessária.');
        }
    }catch(InvalidArgumentException $e){
        lc_flash($e->getMessage(),'error');
    }catch(Throwable $e){
        error_log('LEAGUECUT_SUPPORT_ACTION_FAILED');
        lc_flash('Não foi possível atualizar o chamado. Tente novamente.','error');
    }
    lc_redirect($back);
}
