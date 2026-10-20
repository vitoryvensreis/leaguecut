<?php
declare(strict_types=1);
require_once __DIR__.'/admin-permissions.php';

/** Hard deletion is permitted only where there is no financial or contractual history. */
function lc_account_delete_blocker(int $id): ?string {
    $checks=[
      ['SELECT id FROM ledger_entries WHERE user_id=? LIMIT 1',[$id],
       'Esta conta possui lançamentos financeiros. Estorne ou trate a retenção legal antes de excluir.'],
      ['SELECT id FROM withdrawals WHERE user_id=? LIMIT 1',[$id],
       'Esta conta possui solicitações ou histórico de saques que exigem tratamento financeiro.'],
      ['SELECT id FROM referral_commissions WHERE referrer_user_id=? OR referred_user_id=? LIMIT 1',[$id,$id],
       'Esta conta participa de comissões de indicação que precisam de conferência financeira.'],
      ['SELECT id FROM creator_subscriptions WHERE creator_id=? LIMIT 1',[$id],
       'Esta conta possui assinaturas ou histórico contratual que precisam de tratamento prévio.'],
    ];
    foreach($checks as [$sql,$args,$message]){
        if(lc_one($sql,$args))return $message;
    }
    return null;
}
function lc_account_delete_execute(int $actorId,int $subjectId,string $typedEmail,bool $eraseFinancial=false): void {
    if($actorId===$subjectId)throw new InvalidArgumentException('Você não pode excluir a própria conta administrativa.');
    $actor=lc_one('SELECT id,email,role,status FROM users WHERE id=?',[$actorId]);
    if(!$actor || $actor['role']!=='admin' || $actor['status']!=='approved')
        throw new InvalidArgumentException('Apenas administradores ativos podem excluir contas.');
    $master=lc_is_master($actor);
    if($eraseFinancial&&!$master)
        throw new InvalidArgumentException('Exclusão de histórico financeiro é exclusiva do administrador master.');
    $db=lc_db();
    $db->beginTransaction();
    try{
        $user=lc_one('SELECT id,email,role,status FROM users WHERE id=? FOR UPDATE',[$subjectId]);
        if(!$user)throw new InvalidArgumentException('Conta inexistente ou já excluída.');
        if(lc_is_master($user)||$subjectId===lc_master_user_id())
            throw new InvalidArgumentException('A conta master não pode ser excluída.');
        if(!$master && $user['role']==='admin')
            throw new InvalidArgumentException('Somente o master pode excluir outra conta administrativa.');
        if(!hash_equals(mb_strtolower((string)$user['email']),mb_strtolower(trim($typedEmail))))
            throw new InvalidArgumentException('O e-mail digitado não corresponde à conta.');
        if($user['role']==='admin'){
            $admins=(int)(lc_one("SELECT COUNT(*) AS n FROM users WHERE role='admin' AND status='approved'")['n']??0);
            if($user['status']==='approved'&&$admins<=1)throw new InvalidArgumentException('Não é possível excluir o último administrador ativo.');
        }
        $blocker=lc_account_delete_blocker($subjectId);
        if($blocker!==null&&!$eraseFinancial)throw new InvalidArgumentException($blocker);
        if($eraseFinancial){
            // RESTRICT relations: dependent rows are deleted inside the same
            // transaction, most-specific children first.
            lc_query('DELETE FROM creator_deliveries WHERE creator_id=? OR subscription_id IN (SELECT id FROM creator_subscriptions WHERE creator_id=?)',[$subjectId,$subjectId]);
            lc_query('DELETE FROM creator_subscription_cycles WHERE subscription_id IN (SELECT id FROM creator_subscriptions WHERE creator_id=?)',[$subjectId]);
            lc_query('DELETE FROM creator_subscriptions WHERE creator_id=?',[$subjectId]);
            lc_query('DELETE FROM referral_commissions WHERE referrer_user_id=? OR referred_user_id=? OR referral_id IN (SELECT id FROM referrals WHERE referrer_user_id=? OR referred_user_id=?)',[$subjectId,$subjectId,$subjectId,$subjectId]);
            lc_query('DELETE FROM ledger_entries WHERE user_id=? OR clip_id IN (SELECT id FROM clips WHERE user_id=?)',[$subjectId,$subjectId]);
            lc_query('DELETE FROM withdrawals WHERE user_id=?',[$subjectId]);
        }
        $clips=lc_all('SELECT id,drive_file_id,original_filename FROM clips WHERE user_id=? FOR UPDATE',[$subjectId]);
        // Google Drive is outside the SQL transaction: any API error prevents
        // account deletion. Never silently claim external files were removed.
        foreach($clips as $clip){
            $driveId=trim((string)($clip['drive_file_id']??''));
            if($driveId!=='')lc_drive_delete_original($driveId,(string)($clip['original_filename']??''));
        }
        // Remove uncredited referrals first: their FKs intentionally use RESTRICT.
        lc_query('DELETE FROM referrals WHERE referrer_user_id=? OR referred_user_id=?',[$subjectId,$subjectId]);
        // Outbox and some core financial relations have non-cascading FKs.
        lc_query('DELETE FROM notification_outbox WHERE user_id=?',[$subjectId]);
        // Rows from other users must not keep stale administrator identifiers.
        lc_query('UPDATE users SET approved_by=NULL WHERE approved_by=?',[$subjectId]);
        lc_query('UPDATE clips SET reviewed_by=NULL WHERE reviewed_by=?',[$subjectId]);
        lc_query('UPDATE campaigns SET created_by=NULL WHERE created_by=?',[$subjectId]);
        // Audit logs are retained only without references to the deleted account.
        lc_query('DELETE FROM account_admin_audit WHERE actor_id=? OR subject_user_id=?',[$subjectId,$subjectId]);
        // All other existing user/clip relations use ON DELETE CASCADE/SET NULL.
        lc_query('DELETE FROM users WHERE id=?',[$subjectId]);
        if(lc_db()->inTransaction()){
            lc_account_admin_audit($actorId,'account.deleted',null,
                ['fields'=>$eraseFinancial?['account','clips','linked_records','financial_history_purged']:['account','clips','linked_records']]);
            $db->commit();
        }
    }catch(Throwable $e){
        if($db->inTransaction())$db->rollBack();
        throw $e;
    }
}
function lc_account_delete_routes(string $method,string $path): bool {
    if(!preg_match('~^/admin/contas/([0-9]+)/excluir$~D',$path,$match))return false;
    $actor=lc_admin();
    $id=(int)$match[1];
    if($id<=0){http_response_code(404);return true;}
    if($method==='POST'){
        if($id===(int)$actor['id']){
            lc_flash('Você não pode excluir a própria conta.','error');
            lc_redirect('/admin/contas');
        }
        if(lc_post('confirm_word',20)!=='EXCLUIR'){
            lc_flash('Digite EXCLUIR para confirmar.','error');
            lc_redirect('/admin/contas/'.$id.'/excluir');
        }
        if(!lc_account_admin_password($actor)){
            lc_flash('Senha administrativa ou código 2FA incorreto.','error');
            lc_redirect('/admin/contas/'.$id.'/excluir');
        }
        try{
            $force=lc_bool('erase_financial');
            if($force && !lc_is_master($actor)){
                lc_flash('Somente o master pode usar a exclusão financeira total.','error');
                lc_redirect('/admin/contas/'.$id.'/excluir');
            }
            if($force&&lc_post('financial_confirmation',40)!=='APAGAR FINANCEIRO'){
                lc_flash('Digite APAGAR FINANCEIRO para confirmar a exclusão de registros financeiros.','error');
                lc_redirect('/admin/contas/'.$id.'/excluir');
            }
            lc_account_delete_execute((int)$actor['id'],$id,lc_post('confirm_email',190),$force);
            lc_flash('Usuário e registros vinculados excluídos. A ação foi auditada.');
            lc_redirect('/admin/contas');
        }catch(Throwable $e){
            lc_flash($e instanceof InvalidArgumentException?$e->getMessage():
              'Não foi possível excluir a conta. Nenhuma exclusão SQL foi confirmada; confira os registros e o Drive.','error');
            lc_redirect('/admin/contas/'.$id.'/excluir');
        }
    }
    if($method==='GET'){
        $user=lc_one('SELECT id,full_name,email,role,status FROM users WHERE id=?',[$id]);
        if(!$user){http_response_code(404);lc_page('Conta não encontrada',fn()=>lc_empty('Usuário inexistente','A conta já pode ter sido excluída.'));return true;}
        if(!lc_is_master($actor) && ($user['role']==='admin'||$id===lc_master_user_id())){http_response_code(403);exit('A exclusão de administradores é exclusiva do master.');}
        $master=lc_is_master($actor);
        $blocker=lc_account_delete_blocker($id);
        $summary=lc_one('SELECT COUNT(*) AS n FROM clips WHERE user_id=?',[$id]);
        lc_page('Excluir usuário',function()use($user,$actor,$id,$blocker,$summary,$master){?>
<div class="container container-md lc-account-admin">
<?php lc_heading('Ação permanente','Excluir usuário','Exclusão irreversível do cadastro e de seus registros associados.'); ?>
<div class="panel">
  <span class="tag">ATENÇÃO · EXCLUSÃO DEFINITIVA</span>
  <h2><?=lc_private($user['full_name'])?></h2>
  <p><?=lc_private($user['email'])?> · <?=lc_e($user['role'])?></p>
  <p>Serão excluídos os clipes (<?=(int)($summary['n']??0)?>), arquivos originais do Drive, redes vinculadas, tokens de acesso, suporte, notificações e demais registros dependentes. Campanhas de outros usuários permanecerão intactas.</p>
  <p class="hint">A exclusão normal fica bloqueada por pagamentos, saques, comissões ou assinaturas. O master pode autorizar a remoção desses registros, após revisão de obrigações legais, fiscais e contábeis. Backups, registros externos e comprovantes de terceiros não são apagados automaticamente.</p>
  <?php if($blocker):?><div class="alert"><?=lc_e($blocker)?><?=($master?' · O master pode escolher a exclusão financeira abaixo.':'')?></div><?php endif;?>
  <?php if($id===(int)$actor['id']):?><div class="alert">Você não pode excluir a conta com que está conectado.</div>
  <?php elseif(!$blocker||$master):?>
  <form method="post" action="/admin/contas/<?=$id?>/excluir" autocomplete="off" onsubmit="return confirm('Excluir definitivamente esta conta e os arquivos associados? Esta ação não pode ser desfeita.');">
    <?=lc_csrf()?>
    <div class="form-grid">
      <label class="field full"><span>Digite o e-mail da conta</span><input type="email" name="confirm_email" placeholder="Confirme o e-mail exato" required autocomplete="off"></label>
      <label class="field full"><span>Digite EXCLUIR</span><input name="confirm_word" pattern="EXCLUIR" required autocomplete="off"></label>
      <?php if($master && $blocker):?>
      <label class="field full"><span><input type="checkbox" name="erase_financial" value="1" required> Autorizo a exclusão definitiva dos registros financeiros e contratuais deste usuário</span></label>
      <label class="field full"><span>Digite APAGAR FINANCEIRO para confirmar</span><input name="financial_confirmation" required pattern="APAGAR FINANCEIRO" autocomplete="off"></label>
      <?php endif;?>
      <label class="field full"><span>Sua senha administrativa</span><input type="password" name="admin_password" required autocomplete="current-password"></label>
      <?php if(lc_mfa_enabled((int)$actor['id'])):?>
      <label class="field full"><span>Código 2FA</span><input name="mfa_code" required autocomplete="one-time-code" maxlength="60"></label>
      <?php endif;?>
    </div>
    <div class="lc-delete-actions"><button class="btn" type="submit">Excluir permanentemente</button><a class="btn btn-ghost" href="/admin/contas">Cancelar</a><a class="lc-delete-back" href="/admin/contas">← Voltar às contas</a></div>
  </form>
  <?php endif;?>
  <?php if($id===(int)$actor['id'] || ($blocker&&!$master)): ?><a class="lc-delete-back" href="/admin/contas">← Voltar às contas</a><?php endif;?>
</div></div>
<?php },'admin');return true;
    }
    return false;
}
