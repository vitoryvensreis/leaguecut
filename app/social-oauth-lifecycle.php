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
      WHERE o.social_account_id=? AND s.user_id=? AND s.network=? AND o.provider COLLATE utf8mb4_general_ci = CAST(s.network AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_general_ci',
      [$socialId,$userId,$network]);
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

/** Conservative eligibility: valid token, older than 24h, with <=30 days left. */
function lc_instagram_refresh_due(array $row, ?int $now=null): bool {
    if(($row['provider']??'')!=='instagram'||!empty($row['revoked_at'])||empty($row['access_token_enc']))return false;
    $now??=time();
    $created=strtotime((string)($row['connected_at']??'').' UTC');
    $expiry=strtotime((string)($row['expires_at']??'').' UTC');
    return $created!==false && $expiry!==false
       && $created<=$now-86400 && $expiry>$now+3600 && $expiry<=$now+30*86400;
}
function lc_social_oauth_refresh_instagram(array $row): bool {
    if(!lc_instagram_refresh_due($row))return false;
    $existing=lc_decrypt((string)$row['access_token_enc']);
    if(strlen($existing)<10)return false;
    // Instagram Login refreshes long-lived access tokens using the same token.
    // Credentials stay on backend; no user-controlled URL or automatic retry.
    $endpoint='https://graph.instagram.com/refresh_access_token?'.http_build_query([
      'grant_type'=>'ig_refresh_token','access_token'=>$existing
    ],'','&',PHP_QUERY_RFC3986);
    $data=lc_social_oauth_http($endpoint);
    $long=lc_instagram_long_lived_token($data);
    $newExpiry=gmdate('Y-m-d H:i:s',time()+$long['expires_in']);
    $updated=lc_query('UPDATE social_oauth_connections SET access_token_enc=?,expires_at=?
      WHERE id=? AND provider=? AND revoked_at IS NULL AND access_token_enc=?',
      [lc_encrypt($long['access_token']),$newExpiry,(int)$row['id'],'instagram',$row['access_token_enc']]);
    return $updated->rowCount()===1;
}

/** UI state must follow encrypted token validity, not only the legacy oauth_verified flag. */
function lc_social_oauth_connection_label(array $row, ?int $now=null): string {
    return match(lc_social_oauth_status($row,$now)) {
        'connected' => 'Conectada',
        'expired' => 'Expirada — reconectar',
        'unknown' => 'Verificação pendente',
        default => 'Informada manualmente'
    };
}
