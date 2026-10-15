<?php
declare(strict_types=1);
/** Explicit lifecycle actions. Tokens never leave the backend. */
function lc_social_oauth_status(array $connection, ?int $now=null): string {
    if(!empty($connection['revoked_at']))return 'disconnected';
    if(empty($connection['access_token_enc']))return 'disconnected';
    if(empty($connection['expires_at']))return 'unknown';
    $expiry=strtotime((string)$connection['expires_at'].' UTC');
    if($expiry===false)return 'unknown';
    return $expiry<=($now??time())?'expired':'connected';
}
function lc_social_oauth_refresh_tiktok(array $row): bool {
    if(($row['provider']??'')!=='tiktok'||empty($row['refresh_token_enc'])||!empty($row['revoked_at']))return false;
    $cfg=LcSocialOAuth::config('tiktok');
    if($cfg['client']===''||$cfg['secret']==='')return false;
    $refresh=lc_decrypt((string)$row['refresh_token_enc']);
    if($refresh==='')return false;
    $data=lc_social_oauth_http('https://open.tiktokapis.com/v2/oauth/token/',[
      'client_key'=>$cfg['client'],'client_secret'=>$cfg['secret'],
      'grant_type'=>'refresh_token','refresh_token'=>$refresh]);
    if(!is_string($data['access_token']??null)||!is_string($data['refresh_token']??null)
       ||(string)($data['open_id']??'')!==(string)$row['provider_user_id']
       ||(int)($data['expires_in']??0)<60) return false;
    $encrypted=lc_encrypt($data['access_token']);
    $encryptedRefresh=lc_encrypt($data['refresh_token']);
    $expires=gmdate('Y-m-d H:i:s',time()+min(31536000,(int)$data['expires_in']));
    $updated=lc_query('UPDATE social_oauth_connections SET access_token_enc=?,refresh_token_enc=?,scopes=?,expires_at=?
      WHERE id=? AND provider=? AND revoked_at IS NULL AND refresh_token_enc=?',
      [$encrypted,$encryptedRefresh,(string)($data['scope']??$row['scopes']),$expires,
       (int)$row['id'],'tiktok',$row['refresh_token_enc']]);
    // A concurrent disconnect or refresh invalidates this stale token snapshot.
    return $updated->rowCount()===1;
}
/** Local disconnect is immediate. Remote revoke is best effort, never blocks user disconnect. */
function lc_social_oauth_disconnect(int $socialId,int $userId,string $network): void {
    $row=lc_one('SELECT o.* FROM social_oauth_connections o
      JOIN social_accounts s ON s.id=o.social_account_id
      WHERE o.social_account_id=? AND s.user_id=? AND s.network=? AND o.provider=?',
      [$socialId,$userId,$network,$network]);
    if(!$row)return;
    $token=lc_decrypt((string)$row['access_token_enc']);
    if($network==='tiktok'&&$token!==''){
        try{
            $cfg=LcSocialOAuth::config('tiktok');
            lc_social_oauth_http('https://open.tiktokapis.com/v2/oauth/revoke/',[
              'client_key'=>$cfg['client'],'client_secret'=>$cfg['secret'],'token'=>$token]);
        }catch(Throwable $e){error_log('LeagueCut remote OAuth revoke unavailable');}
    }
    lc_db()->beginTransaction();
    try{
      lc_query('UPDATE social_oauth_connections SET access_token_enc=?,refresh_token_enc=NULL,revoked_at=UTC_TIMESTAMP(),expires_at=NULL WHERE id=? AND revoked_at IS NULL',
        ['',(int)$row['id']]);
      lc_query('UPDATE social_accounts SET oauth_verified=0 WHERE id=? AND user_id=?',[$socialId,$userId]);
      lc_db()->commit();
    }catch(Throwable $e){if(lc_db()->inTransaction())lc_db()->rollBack();throw $e;}
}
