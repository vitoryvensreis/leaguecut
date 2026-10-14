<?php
declare(strict_types=1);

/** A creator may modify or delete only their own not-yet-approved clips. */
function lc_clipper_editable_clip(int $clipId,int $userId,bool $lock=false): array {
    $clip=lc_one('SELECT * FROM clips WHERE id=? AND user_id=?'.($lock?' FOR UPDATE':''),
        [$clipId,$userId]);
    if(!$clip){http_response_code(404);exit('Clipe não encontrado.');}
    if($clip['status']==='approved'){
        // During Drive replacement a concurrent admin may approve the clip.
        // Throw while holding a lock, so the newly uploaded file gets cleaned.
        if($lock)throw new RuntimeException('Esse clipe já foi aprovado e não pode mais ser alterado.');
        http_response_code(403);
        exit('Esse clipe já foi aprovado e não pode mais ser editado ou excluído pelo clipador.');
    }
    if(!in_array($clip['status'],['pending','rejected'],true)){
        http_response_code(403);exit('Esse envio não pode ser alterado.');
    }
    return $clip;
}
function lc_clip_social_url_valid(string $url,string $network): bool {
    if(!lc_valid_https($url))return false;
    $host=strtolower((string)parse_url($url,PHP_URL_HOST));
    return $network==='instagram'
        ? in_array($host,['instagram.com','www.instagram.com'],true)
        : ($network==='tiktok' && in_array($host,['tiktok.com','www.tiktok.com','vm.tiktok.com','vt.tiktok.com'],true));
}

/** Shared post-save Drive cleanup: never put the user's original upload at risk. */
function lc_clipper_cleanup_uploaded_file(?array $upload): void {
    if(!$upload || empty($upload['id']) || empty($upload['filename']))return;
    try {lc_drive_delete_original((string)$upload['id'],(string)$upload['filename']);}
    catch(Throwable $cleanupError){
        error_log('LeagueCut uploaded original cleanup failed after unsuccessful edit');
    }
}

/** Editing a rejected item resubmits it for admin review. */
function lc_clipper_update_clip(int $clipId,int $userId,array $post,array $files): string {
    $old=lc_clipper_editable_clip($clipId,$userId);
    $title=mb_substr(trim((string)($post['title']??'')),0,180);
    $url=mb_substr(trim((string)($post['published_url']??'')),0,800);
    $accountId=filter_var($post['social_account_id']??null,FILTER_VALIDATE_INT);
    if(!$accountId || (int)$accountId<1||mb_strlen($title)<4){
        throw new RuntimeException('Informe título com pelo menos quatro caracteres e selecione uma rede.');
    }
    $account=lc_one('SELECT * FROM social_accounts WHERE id=? AND user_id=?',[(int)$accountId,$userId]);
    if(!$account||!lc_clip_social_url_valid($url,(string)$account['network']))
        throw new RuntimeException('Informe um perfil seu e uma URL HTTPS válida dessa rede.');
    $duplicate=lc_one('SELECT id FROM clips WHERE user_id=? AND published_url=? AND id<>? LIMIT 1',
        [$userId,$url,$clipId]);
    if($duplicate)throw new RuntimeException('Esse link já foi enviado em outro clipe.');
    $file=$files['video']??null;
    $newVideo=is_array($file)&&($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_NO_FILE;
    if(!$newVideo && empty($old['drive_file_id']))
        throw new RuntimeException('Envie o vídeo original para corrigir este cadastro antigo.');
    $uploaded=null;
    if($newVideo){
        $uploaded=lc_drive_upload($file,$userId);
    }
    $committed=false;
    try{
        lc_db()->beginTransaction();
        try {
            $clip=lc_clipper_editable_clip($clipId,$userId,true);
            lc_clip_assert_no_financial_history($clip);
            if(!$newVideo && empty($clip['drive_file_id']))
                throw new RuntimeException('O vídeo original é obrigatório.');
            $previousFileId=(string)($clip['drive_file_id']??'');
            $previousFilename=(string)($clip['original_filename']??'');
            $fileId=$uploaded['id']??$previousFileId;
            $filename=$uploaded['filename']??$previousFilename;
            $changedPost=($url!==(string)$clip['published_url'] || (int)$accountId!==(int)$clip['social_account_id']);
            lc_query("UPDATE clips SET title=?,published_url=?,social_account_id=?,
                 drive_file_id=?,original_filename=?,status='pending',
                 reviewed_at=NULL,reviewed_by=NULL,verified_views=0 WHERE id=? AND user_id=?",
                 [$title,$url,(int)$accountId,$fileId,$filename,$clipId,$userId]);
            if($changedPost){
                // Never display metrics associated with a different Instagram post.
                lc_query('DELETE FROM clip_daily_views WHERE clip_id=?',[$clipId]);
                lc_query('DELETE FROM clip_view_samples WHERE clip_id=?',[$clipId]);
            }
            lc_realtime_emit('admin',null,'clip.updated','clip',$clipId);
            lc_realtime_emit('user',$userId,'clip.updated','clip',$clipId);
            lc_db()->commit();$committed=true;
        }catch(Throwable $e){
            if(lc_db()->inTransaction())lc_db()->rollBack();
            throw $e;
        }
        // Delete the replaced original only AFTER the database commit succeeded.
        // An error leaves the old file private but never deletes the new original.
        if($newVideo && $previousFileId!=='' && $previousFilename!==''){
            try {lc_drive_delete_original($previousFileId,$previousFilename);}
            catch(Throwable $e){
                error_log('LeagueCut old Drive original cleanup failed for edited clip '.$clipId);
                return 'Envio atualizado e reenviado para análise. O original antigo ficou pendente de limpeza no Drive.';
            }
        }
        return 'Envio atualizado e encaminhado para análise.';
    }catch(Throwable $e){
        if(!$committed)lc_clipper_cleanup_uploaded_file($uploaded);
        throw $e;
    }
}
function lc_clipper_delete_clip(int $clipId,int $userId): string {
    lc_clipper_editable_clip($clipId,$userId); // immediate 403 for an approved clip
    lc_db()->beginTransaction();
    try {
        $clip=lc_clipper_editable_clip($clipId,$userId,true);
        lc_clip_assert_no_financial_history($clip);
        $id=(string)($clip['drive_file_id']??'');
        if($id!=='')lc_drive_delete_original($id,(string)($clip['original_filename']??''));
        lc_query('DELETE FROM clips WHERE id=? AND user_id=?',[$clipId,$userId]);
        lc_realtime_emit('admin',null,'clip.deleted','clip',$clipId);
        lc_realtime_emit('user',$userId,'clip.deleted','clip',$clipId);
        lc_db()->commit();
        return 'Clipe excluído da sua biblioteca'.($id!==''?' e original removido do Google Drive.':'.');
    }catch(Throwable $e){
        if(lc_db()->inTransaction())lc_db()->rollBack();
        throw $e;
    }
}
