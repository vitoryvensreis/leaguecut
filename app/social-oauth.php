<?php
declare(strict_types=1);
/** OAuth preparation: state is single-use, user-bound and expires after ten minutes. */
/** Per-provider rollout lock: global permission alone must never enable both networks. */
function lc_social_oauth_provider_enabled(string $provider): bool {
    $flag=match($provider) {
        'instagram'=>'HYBRID_INSTAGRAM_OAUTH_ENABLED',
        'tiktok'=>'HYBRID_TIKTOK_OAUTH_ENABLED',
        default=>null
    };
    return $flag!==null && lc_env('HYBRID_SOCIAL_OAUTH_ENABLED','0')==='1'
        && lc_env($flag,'0')==='1';
}

final class LcSocialOAuth
{
    public static function config(string $provider): array
    {
        return match($provider) {
            'tiktok' => ['client'=>lc_env('TIKTOK_CLIENT_KEY'),'secret'=>lc_env('TIKTOK_CLIENT_SECRET'),
                'scope'=>'user.info.basic,user.info.profile,video.list','authorize'=>'https://www.tiktok.com/v2/auth/authorize/'],
            'instagram' => ['client'=>lc_env('INSTAGRAM_APP_ID'),'secret'=>lc_env('INSTAGRAM_APP_SECRET'),
                'scope'=>'instagram_business_basic','authorize'=>'https://www.instagram.com/oauth/authorize'],
            default => throw new InvalidArgumentException('Rede não suportada.')
        };
    }
    public static function redirectUri(string $provider): string
    {
        self::config($provider);
        $base=rtrim(lc_env('APP_URL'),'/');
        if(!preg_match('~^https://[a-z0-9.-]+(?::443)?$~iD',$base))throw new RuntimeException('APP_URL HTTPS obrigatório.');
        return $base.'/redes/oauth/'.$provider.'/callback';
    }
    public static function begin(string $provider,int $userId,int $socialAccountId): string
    {
        if($userId<1||$socialAccountId<1)throw new InvalidArgumentException('Conta inválida.');
        $config=self::config($provider);
        if($config['client']===''||$config['secret']==='')throw new RuntimeException('Credenciais OAuth não configuradas.');
        $redirectUri=self::redirectUri($provider);
        $state=bin2hex(random_bytes(32));
        $_SESSION['lc_social_oauth']=['state'=>$state,'provider'=>$provider,'user_id'=>$userId,
            'social_account_id'=>$socialAccountId,'redirect_uri'=>$redirectUri,'expires_at'=>time()+600];
        $params=['response_type'=>'code','redirect_uri'=>$redirectUri,'state'=>$state,
            'scope'=>$config['scope']];
        if($provider==='tiktok')$params['client_key']=$config['client'];
        else $params['client_id']=$config['client'];
        return $config['authorize'].'?'.http_build_query($params,'','&',PHP_QUERY_RFC3986);
    }
    public static function consume(string $provider,int $userId,string $state,?string &$redirectUri=null): int
    {
        $pending=$_SESSION['lc_social_oauth']??null;
        unset($_SESSION['lc_social_oauth']); // invalid even if callback fails
        if(!is_array($pending)||!is_string($pending['state']??null)||
            !hash_equals($pending['state'],$state)||
            ($pending['provider']??null)!==$provider||
            (int)($pending['user_id']??0)!==$userId||
            (int)($pending['expires_at']??0)<time())
            throw new RuntimeException('Autorização inválida ou expirada.');
        $saved=$pending['redirect_uri']??null;
        if(!is_string($saved)||$saved!==self::redirectUri($provider))
            throw new RuntimeException('OAuth redirect URI altered during authorization.');
        $redirectUri=$saved;
        return (int)$pending['social_account_id'];
    }
}
