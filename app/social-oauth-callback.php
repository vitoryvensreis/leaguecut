<?php
declare(strict_types=1);
require_once __DIR__.'/social-oauth.php';

/** Only fixed official HTTPS hosts. Never log or expose tokens and codes. */
function lc_social_oauth_http(string $url, ?array $form=null, ?string $token=null): array {
    $host=strtolower((string)parse_url($url,PHP_URL_HOST));
    if (!in_array($host,['open.tiktokapis.com','api.instagram.com','graph.instagram.com'],true)
      || parse_url($url,PHP_URL_SCHEME)!=='https') throw new RuntimeException('Provedor recusado.');
    $h=curl_init($url);
    if($h===false)throw new RuntimeException('HTTP indisponível.');
    $headers=['Accept: application/json'];
    if($form!==null)$headers[]='Content-Type: application/x-www-form-urlencoded';
    if($token!==null)$headers[]='Authorization: Bearer '.$token;
    curl_setopt_array($h,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>5,
      CURLOPT_TIMEOUT=>15,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_MAXREDIRS=>0,
      CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_HTTPHEADER=>$headers]);
    if($form!==null){curl_setopt($h,CURLOPT_POST,true);curl_setopt($h,CURLOPT_POSTFIELDS,http_build_query($form,'','&',PHP_QUERY_RFC3986));}
    $body=curl_exec($h);$status=(int)curl_getinfo($h,CURLINFO_HTTP_CODE);curl_close($h);
    if($status!==200||!is_string($body)||strlen($body)>262144)throw new RuntimeException('Falha na autenticação oficial.');
    $data=json_decode($body,true);
    if(!is_array($data))throw new RuntimeException('Resposta inválida do provedor.');
    return $data;
}
function lc_instagram_long_lived_token(array $response): array {
    $token=$response['access_token']??null;
    $ttl=$response['expires_in']??null;
    if(!is_string($token)||strlen($token)<10||(!is_int($ttl)&&!is_string($ttl))
       ||!ctype_digit((string)$ttl)||filter_var((string)$ttl,FILTER_VALIDATE_INT,
           ['options'=>['min_range'=>3600,'max_range'=>31536000]])===false)
       throw new RuntimeException('Instagram long-lived token indisponível.');
    return ['access_token'=>$token,'expires_in'=>(int)$ttl];
}
function lc_instagram_exchange_result(array $initial,array $long): array {
    $verified=lc_instagram_long_lived_token($long);
    $remote=$initial['user_id']??null;
    if(!is_scalar($remote)||trim((string)$remote)==='')throw new RuntimeException('Instagram user ID ausente.');
    return ['access_token'=>$verified['access_token'],'remote_id'=>(string)$remote,
      'refresh_token'=>null,'expires_in'=>$verified['expires_in'],
      'scopes'=>is_string($initial['scope']??null)?$initial['scope']:''];
}
function lc_social_oauth_exchange(string $network,string $code): array {
    if($code===''||strlen($code)>2048)throw new InvalidArgumentException('Código inválido.');
    $cfg=LcSocialOAuth::config($network);
    if($network==='tiktok'){
        $data=lc_social_oauth_http('https://open.tiktokapis.com/v2/oauth/token/',[
          'client_key'=>$cfg['client'],'client_secret'=>$cfg['secret'],'code'=>$code,
          'grant_type'=>'authorization_code','redirect_uri'=>LcSocialOAuth::redirectUri($network)]);
        $access=$data['access_token']??null;$remote=$data['open_id']??null;
    }elseif($network==='instagram'){
        $data=lc_social_oauth_http('https://api.instagram.com/oauth/access_token',[
          'client_id'=>$cfg['client'],'client_secret'=>$cfg['secret'],'grant_type'=>'authorization_code',
          'redirect_uri'=>LcSocialOAuth::redirectUri($network),'code'=>$code]);
        $access=$data['access_token']??null;$remote=$data['user_id']??null;
        if(!is_string($access)||strlen($access)<10)throw new RuntimeException('Token Instagram ausente.');
        // Instagram Login issues a short-lived token first; exchange server-side.
        // Never persist the short-lived token as a verified connection.
        $long=lc_social_oauth_http('https://graph.instagram.com/access_token?'.http_build_query([
          'grant_type'=>'ig_exchange_token','client_secret'=>$cfg['secret'],'access_token'=>$access
        ],'','&',PHP_QUERY_RFC3986));
        return lc_instagram_exchange_result($data,$long);
    }else throw new InvalidArgumentException('Rede inválida.');
    if(!is_string($access)||strlen($access)<10||!is_scalar($remote)||trim((string)$remote)==='')
       throw new RuntimeException('Identidade ou token ausente.');
    return ['access_token'=>$access,'remote_id'=>(string)$remote,
      'refresh_token'=>is_string($data['refresh_token']??null)?$data['refresh_token']:null,
      'expires_in'=>max(0,min(31536000,(int)($data['expires_in']??0))),
      'scopes'=>is_string($data['scope']??null)?$data['scope']:''];
}
function lc_social_oauth_identity(string $network,string $access): array {
    if($network==='tiktok'){
        $data=lc_social_oauth_http('https://open.tiktokapis.com/v2/user/info/?fields=open_id,username',null,$access);
        $item=$data['data']['user']??null;
        return ['id'=>$item['open_id']??null,'username'=>$item['username']??null];
    }
    if($network==='instagram'){
        $data=lc_social_oauth_http('https://graph.instagram.com/me?fields=user_id,username',null,$access);
        return ['id'=>$data['user_id']??$data['id']??null,'username'=>$data['username']??null];
    }
    throw new InvalidArgumentException('Rede inválida.');
}
function lc_social_oauth_verified_profile(array $account,string $network,array $token,array $identity): bool {
    $username=$identity['username']??null;$id=$identity['id']??null;
    if(!is_string($username)||!is_scalar($id)||trim((string)$id)===''||$network!==($account['network']??null))return false;
    if(!hash_equals(strtolower(ltrim((string)$account['username'],'@')),strtolower(ltrim($username,'@'))))return false;
    if(!hash_equals((string)$token['remote_id'],(string)$id))return false;
    $scopes=array_filter(array_map('trim',explode(',',(string)$token['scopes'])));
    // TikTok may grant only subset; username requires user.info.profile.
    if($network==='tiktok' && !in_array('user.info.profile',$scopes,true))return false;
    return true;
}
function lc_social_oauth_save(array $account,string $network,array $token): void {
    // An unknown expiry cannot establish a valid connected state. Require reauthorization or provider-specific token exchange.
    if((int)($token['expires_in']??0)<60)throw new RuntimeException('Token sem prazo de validade confirmado.');
    $expiry=gmdate('Y-m-d H:i:s',time()+(int)$token['expires_in']);
    lc_query('INSERT INTO social_oauth_connections
       (social_account_id,provider,provider_user_id,access_token_enc,refresh_token_enc,scopes,expires_at,revoked_at)
       VALUES(?,?,?,?,?,?,?,NULL)
       ON DUPLICATE KEY UPDATE provider_user_id=VALUES(provider_user_id),
       access_token_enc=VALUES(access_token_enc),refresh_token_enc=VALUES(refresh_token_enc),
       scopes=VALUES(scopes),expires_at=VALUES(expires_at),revoked_at=NULL',
       [(int)$account['id'],$network,$token['remote_id'],lc_encrypt($token['access_token']),
       $token['refresh_token']!==null?lc_encrypt($token['refresh_token']):null,$token['scopes'],$expiry]);
    lc_query('UPDATE social_accounts SET oauth_verified=1 WHERE id=?',[(int)$account['id']]);
}
function lc_social_oauth_route(string $method,string $path): bool {
    if(!preg_match('~^/redes/oauth/(instagram|tiktok)/(iniciar|callback|desconectar)$~D',$path,$m))return false;
    $network=$m[1];$action=$m[2];
    if(!lc_social_oauth_provider_enabled($network)){http_response_code(404);exit('Integração indisponível.');}$user=lc_login_required();
    if($user['role']!=='clipador'){http_response_code(403);exit('Acesso restrito.');}
    if($action==='desconectar'&&$method==='POST'){
        $id=filter_var($_POST['account_id']??null,FILTER_VALIDATE_INT);
        if(!$id||$id<1){lc_flash('Perfil inválido.','error');lc_redirect('/redes');}
        try{lc_social_oauth_disconnect($id,(int)$user['id'],$network);lc_flash('Conta desconectada.');}
        catch(Throwable $e){lc_flash('Não foi possível desconectar agora.','error');}
        lc_redirect('/redes');
    }
    if($action==='iniciar'&&$method==='POST'){
        $account=lc_one('SELECT * FROM social_accounts WHERE id=? AND user_id=? AND network=?',
          [(int)($_POST['account_id']??0),(int)$user['id'],$network]);
        if(!$account){lc_flash('Perfil não encontrado.','error');lc_redirect('/redes');}
        try{header('Location: '.LcSocialOAuth::begin($network,(int)$user['id'],(int)$account['id']),true,303);exit;}
        catch(Throwable $e){lc_flash('Autorização indisponível. Confira a configuração da integração.','error');lc_redirect('/redes');}
    }
    if($action==='callback'&&$method==='GET'){
        try{
            $accountId=LcSocialOAuth::consume($network,(int)$user['id'],(string)($_GET['state']??''));
            if(isset($_GET['error']))throw new RuntimeException('Consentimento recusado.');
            $account=lc_one('SELECT * FROM social_accounts WHERE id=? AND user_id=? AND network=?',
              [$accountId,(int)$user['id'],$network]);
            if(!$account)throw new RuntimeException('Conta não encontrada.');
            $token=lc_social_oauth_exchange($network,(string)($_GET['code']??''));
            $identity=lc_social_oauth_identity($network,$token['access_token']);
            if(!lc_social_oauth_verified_profile($account,$network,$token,$identity))
                throw new RuntimeException('Identidade divergente ou escopo insuficiente.');
            lc_db()->beginTransaction();
            try{lc_social_oauth_save($account,$network,$token);lc_db()->commit();}
            catch(Throwable $e){if(lc_db()->inTransaction())lc_db()->rollBack();throw $e;}
            lc_flash('Conta social conectada e verificada.');
        }catch(Throwable $e){
            error_log('LeagueCut OAuth failed for network '.$network);
            lc_flash('Não foi possível verificar esta conta. Confirme o @, permissões e tente novamente.','error');
        }
        lc_redirect('/redes');
    }
    return false;
}
