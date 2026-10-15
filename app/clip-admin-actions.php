<?php
declare(strict_types=1);

/**
 * Mutating an already-credited clip would invalidate weekly thresholds,
 * monthly leaderboards and balances. Require a separate audited reversal.
 */
function lc_clip_assert_no_financial_history(array $clip): void {
    $linked=lc_one('SELECT id FROM ledger_entries WHERE clip_id=? LIMIT 1',[(int)$clip['id']]);
    if($linked){
        throw new RuntimeException('Este clipe já gerou créditos financeiros. Para reprovar ou excluir, faça primeiro um estorno auditável; nenhum saldo será alterado automaticamente.');
    }
    $month=substr((string)$clip['submitted_at'],0,7);
    if(preg_match('/^\d{4}-\d{2}$/D',$month)){
        $leader=lc_one("SELECT id FROM ledger_entries WHERE campaign_id=? AND user_id=? AND kind='monthly_leader' AND dedupe_key=? LIMIT 1",
            [(int)$clip['campaign_id'],(int)$clip['user_id'],'leader:'.$clip['campaign_id'].':'.$month]);
        if($leader){
            throw new RuntimeException('Este clipe pertence a um mês com bônus já creditado. A reversão exige auditoria financeira antes da exclusão ou reprovação.');
        }
    }
}

/**
 * All admin clip actions use the row lock. The view count is NEVER sent by
 * the form: the provider's observed metrics live in clip_daily_views.
 */
function lc_admin_change_clip(int $clipId,string $decision,int $adminId): string {
    if(!in_array($decision,['approved','rejected'],true))throw new RuntimeException('Ação de revisão inválida.');
    lc_db()->beginTransaction();
    try {
        $clip=lc_one('SELECT * FROM clips WHERE id=? FOR UPDATE',[$clipId]);
        if(!$clip)throw new RuntimeException('Clipe não encontrado.');
        if($clip['status']===$decision){
            lc_db()->commit();
            return 'Este clipe já está com o status solicitado.';
        }
        if($decision==='approved' && !$clip['drive_file_id']){
            throw new RuntimeException('Não é possível aprovar um clipe sem vídeo original no Drive.');
        }
        if($decision==='rejected' && $clip['status']==='approved'){
            lc_clip_assert_no_financial_history($clip);
        }
        lc_query('UPDATE clips SET status=?,verified_views=IF(?="rejected",0,verified_views),reviewed_at=UTC_TIMESTAMP(),reviewed_by=? WHERE id=?',
            [$decision,$decision,$adminId,$clipId]);
        lc_realtime_emit('user',(int)$clip['user_id'],'clip.reviewed','clip',$clipId);
        lc_realtime_emit('admin',null,'clip.reviewed','clip',$clipId);
        $campaign=lc_one('SELECT title FROM campaigns WHERE id=?',[(int)$clip['campaign_id']]);
        lc_automation_emit('clip.'.$decision,'clip.'.$decision.':'.$clipId.':'.bin2hex(random_bytes(8)),(int)$clip['user_id'],[
            'titulo'=>$clip['title'],'campanha'=>$campaign['title']??'',
            'campaign_id'=>(string)$clip['campaign_id'],'status'=>$decision,
            'role'=>'clipador','link'=>'/biblioteca'
        ]);
        if($decision==='approved'){
            lc_settle_week((int)$clip['campaign_id'],(int)$clip['user_id'],(string)$clip['submitted_at']);
            lc_credit_views($clipId);
        }
        lc_db()->commit();
        return $decision==='approved'?'Clipe aprovado.':'Clipe reprovado.';
    }catch(Throwable $e){
        if(lc_db()->inTransaction())lc_db()->rollBack();
        throw $e;
    }
}

/** Delete Drive original (if any), clip and cascading daily/raw view samples. */
function lc_admin_delete_clip(int $clipId,int $adminId): string {
    lc_db()->beginTransaction();
    try {
        $clip=lc_one('SELECT * FROM clips WHERE id=? FOR UPDATE',[$clipId]);
        if(!$clip)throw new RuntimeException('Clipe não encontrado ou já excluído.');
        lc_clip_assert_no_financial_history($clip);
        $driveId=trim((string)($clip['drive_file_id']??''));
        if($driveId!==''){
            // External Google API call while the DB row is locked. A Drive
            // failure aborts the transaction; no broken DB reference is removed.
            lc_drive_delete_original($driveId,(string)($clip['original_filename']??''));
        }
        lc_query('DELETE FROM clips WHERE id=?',[$clipId]);
        lc_realtime_emit('user',(int)$clip['user_id'],'clip.deleted','clip',$clipId);
        lc_realtime_emit('admin',null,'clip.deleted','clip',$clipId);
        lc_db()->commit();
        error_log('LeagueCut admin clip deleted: clip_id='.$clipId.' admin_id='.$adminId);
        return $driveId===''?'Clipe excluído da plataforma.':'Clipe excluído da plataforma e original removido do Google Drive.';
    }catch(Throwable $e){
        if(lc_db()->inTransaction())lc_db()->rollBack();
        throw $e;
    }
}
