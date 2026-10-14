<?php
declare(strict_types=1);

/**
 * Apify public metrics importer. Observations are NOT verified payouts.
 * Only exact shortcode AND author handles are accepted, never generic profile totals.
 */
function lc_apify_shortcode(string $url): ?string {
    $host=strtolower((string)parse_url($url,PHP_URL_HOST));
    if(!in_array($host,['instagram.com','www.instagram.com'],true))return null;
    $path=(string)parse_url($url,PHP_URL_PATH);
    return preg_match('~^/(?:p|reel|reels|tv)/([A-Za-z0-9_-]{5,80})(?:/|$)~D',$path,$m)?$m[1]:null;
}
function lc_apify_data_shortcode(array $row): ?string {
    foreach(['shortcode','shortCode','code','postCode'] as $key){
        if(is_string($row[$key]??null)&&preg_match('/^[A-Za-z0-9_-]{5,80}$/D',$row[$key]))return $row[$key];
    }
    foreach(['url','postUrl','post_url','permalink','instagram_url','permalink_url','link'] as $key){
        if(is_string($row[$key]??null)){
            $sc=lc_apify_shortcode($row[$key]);if($sc!==null)return $sc;
        }
    }
    return null;
}
function lc_apify_data_author(array $row): ?string {
    foreach(['ownerUsername','owner_username','username','owner','user','author'] as $key) {
        $value=$row[$key]??null;
        if(is_array($value))$value=$value['username']??$value['userName']??null;
        if(is_string($value)&&preg_match('/^[a-zA-Z0-9._]{2,100}$/D',ltrim($value,'@')))return strtolower(ltrim($value,'@'));
    }
    return null;
}
function lc_apify_post_views(array $row,string $username,string $shortcode): ?int {
    if(lc_apify_data_shortcode($row)!==$shortcode || lc_apify_data_author($row)!==strtolower(ltrim($username,'@')))return null;
    foreach(['videoPlayCount','videoViewCount','playCount','viewCount','video_view_count','video_play_count','videoViews','views','play_count','video_views','video_views_count','video_plays'] as $field){
        $v=$row[$field]??null;
        if(is_int($v)||is_string($v)&&ctype_digit($v)){
            if((string)$v!=='' && (float)$v <= 2000000000)return (int)$v;
        }
    }
    return null;
}
function lc_apify_observe(int $clipId,int $views): bool {
    if($clipId<1||$views<0||$views>2000000000)throw new InvalidArgumentException('Métricas inválidas.');
    $existing=lc_one('SELECT views FROM clip_view_samples WHERE clip_id=? ORDER BY id DESC LIMIT 1',[$clipId]);
    if($existing && (int)$existing['views'] >= $views)return false;
    lc_query('INSERT INTO clip_view_samples(clip_id,views,source) VALUES(?,?,"apify")',[$clipId,$views]);
    return true;
}
/**
 * Free validation of the configured Apify API token. Does not launch Actors,
 * collect personal data or create billing events.
 */
function lc_apify_check_connection(): bool {
    $token=lc_env('APIFY_API_TOKEN');
    if($token==='')return false;
    $handle=curl_init('https://api.apify.com/v2/users/me');
    if(!$handle)throw new RuntimeException('Falha ao abrir HTTP para a Apify.');
    curl_setopt_array($handle,[
      CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$token,'Accept: application/json'],
      CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>12,
      CURLOPT_FOLLOWLOCATION=>false,CURLOPT_MAXREDIRS=>0
    ]);
    $body=curl_exec($handle);
    $status=(int)curl_getinfo($handle,CURLINFO_HTTP_CODE);
    curl_close($handle);
    if(!is_string($body)||$status!==200)return false;
    $data=json_decode($body,true);
    // A verified response from the authenticated "current user" endpoint.
    return is_array($data) && is_array($data['data']??null) && !empty($data['data']['id']);
}

function lc_apify_usage(): array {
    $today=lc_one('SELECT COUNT(*) AS runs,COALESCE(SUM(max_charge_usd),0) AS max_reserved_usd FROM apify_profile_runs WHERE attempted_at>=UTC_DATE()')??[];
    return ['runs'=>(int)($today['runs']??0),'max_reserved_usd'=>(float)($today['max_reserved_usd']??0)];
}
function lc_apify_config(): array {
    return [
      'token'=>lc_env('APIFY_API_TOKEN'),
      'actor'=>'danek~instagram-posts-scraper-ppr',
      'max_posts'=>max(5,min(50,(int)lc_env('APIFY_MAX_POSTS','20'))),
      'max_run_usd'=>max(0.01,min(0.5,(float)lc_env('APIFY_MAX_RUN_CHARGE_USD','0.05'))),
      'daily_usd'=>max(0.01,min(5.0,(float)lc_env('APIFY_DAILY_BUDGET_USD','0.15'))),
      'daily_runs'=>max(1,min(12,(int)lc_env('APIFY_MAX_RUNS_PER_DAY','3'))),
      'min_hours'=>max(6,min(168,(int)lc_env('APIFY_MIN_HOURS_BETWEEN','24')))
    ];
}
/**
 * Apify's synchronous dataset endpoint documents 201 Created on success.
 * Some older clients may return 200; both are accepted, but errors are rejected.
 */
function lc_apify_parse_dataset_response(int $status,string|bool $body): array {
    if(!is_string($body)||!in_array($status,[200,201],true))
        throw new RuntimeException('Falha/limite do provedor Apify (HTTP '.$status.').');
    if(strlen($body)>8*1024*1024)throw new RuntimeException('Resposta Apify muito grande.');
    $rows=json_decode($body,true);
    if(!is_array($rows)||!array_is_list($rows))
        throw new RuntimeException('Apify retornou formato inesperado.');
    return $rows;
}
/** Intended for the CLI job only: GET public Instagram posts for one profile. */
function lc_apify_fetch(string $username,array $config): array {
    if(!preg_match('/^[a-zA-Z0-9._]{2,100}$/D',$username))throw new InvalidArgumentException('Perfil Instagram inválido.');
    $url='https://api.apify.com/v2/acts/'.rawurlencode($config['actor']).'/run-sync-get-dataset-items?'.
         http_build_query(['timeout'=>110,'maxItems'=>$config['max_posts'],
                          'maxTotalChargeUsd'=>number_format($config['max_run_usd'],3,'.','')]);
    // Apify documents actor endpoints with actorId using ~ notation.
    $handle=curl_init($url);
    if(!$handle)throw new RuntimeException('Cliente HTTP não iniciou.');
    curl_setopt_array($handle,[
        CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>json_encode(['username'=>$username,'max_posts'=>$config['max_posts']],JSON_THROW_ON_ERROR),
        CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$config['token'],'Content-Type: application/json','Accept: application/json'],
        CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>12,CURLOPT_TIMEOUT=>135,
        CURLOPT_FOLLOWLOCATION=>false,
        CURLOPT_MAXREDIRS=>0
    ]);
    $body=curl_exec($handle);$status=(int)curl_getinfo($handle,CURLINFO_HTTP_CODE);
    curl_close($handle);
    return lc_apify_parse_dataset_response($status,$body);
}
function lc_apify_import_profile(string $username,array $rows): int {
    $clips=lc_all("SELECT c.id,c.published_url FROM clips c JOIN social_accounts s ON s.id=c.social_account_id
      JOIN campaigns p ON p.id=c.campaign_id WHERE s.network='instagram' AND LOWER(s.username)=?
      AND c.status IN ('pending','approved') AND c.submitted_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 30 DAY)
      AND p.status='active'",[strtolower($username)]);
    $matched=0;
    foreach($clips as $clip){
        $code=lc_apify_shortcode($clip['published_url']);
        if(!$code)continue;
        foreach($rows as $row){
            if(!is_array($row))continue;
            $views=lc_apify_post_views($row,$username,$code);
            if($views===null)continue;
            if(lc_apify_observe((int)$clip['id'],$views))$matched++;
            break;
        }
    }
    return $matched;
}
function lc_apify_sync(): void {
    $config=lc_apify_config();
    if($config['token']===''){echo "APIFY_DISABLED: configure APIFY_API_TOKEN in private .env\n";return;}
    $db=lc_db();
    $lock=$db->query("SELECT GET_LOCK('leaguecut_apify_metrics_v1',0) AS acquired")->fetch(PDO::FETCH_ASSOC);
    if((int)($lock['acquired']??0)!==1){echo "APIFY_ALREADY_RUNNING\n";return;}
    try {
        $profiles=lc_all("SELECT LOWER(s.username) AS username,MAX(c.submitted_at) AS last_submit
           FROM clips c JOIN social_accounts s ON s.id=c.social_account_id
           JOIN campaigns p ON p.id=c.campaign_id
           WHERE s.network='instagram' AND p.status='active' AND c.status IN ('pending','approved')
           AND c.submitted_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 30 DAY)
           GROUP BY LOWER(s.username) ORDER BY last_submit DESC LIMIT 80");
        $usage=lc_apify_usage();
        $runs=$usage['runs'];$spent=$usage['max_reserved_usd'];
        foreach($profiles as $profile) {
            if($runs >= $config['daily_runs'] || $spent+$config['max_run_usd']>$config['daily_usd']+0.00001)break;
            $username=$profile['username'];
            $recent=lc_one('SELECT id FROM apify_profile_runs WHERE username=? AND attempted_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL ? HOUR) ORDER BY id DESC LIMIT 1',[$username,$config['min_hours']]);
            if($recent)continue;
            lc_query('INSERT INTO apify_profile_runs(username,max_charge_usd,status) VALUES(?,?,"reserved")',[$username,$config['max_run_usd']]);
            $runId=(int)$db->lastInsertId();$runs++;$spent+=$config['max_run_usd'];
            try{
                $items=lc_apify_fetch($username,$config);
                $matched=lc_apify_import_profile($username,$items);
                lc_query('UPDATE apify_profile_runs SET status="completed",matched_clips=?,result_count=? WHERE id=?',[$matched,count($items),$runId]);
                echo "APIFY_SYNC profile=$username items=".count($items)." updated=$matched\n";
            }catch(Throwable $e){
                lc_query('UPDATE apify_profile_runs SET status="failed",error_message=? WHERE id=?',[mb_substr($e->getMessage(),0,190),$runId]);
                echo "APIFY_FAILED profile=$username; waiting until next allowed run\n";
            }
        }
        echo "APIFY_DAILY_RESERVED_USD=".number_format($spent,3,'.','')." runs=$runs\n";
    }finally{$db->query("SELECT RELEASE_LOCK('leaguecut_apify_metrics_v1')");}
}
