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
    foreach(['videoViewCount','videoPlayCount','videoViews','viewCount','playCount','video_view_count','video_play_count','videoViews','views','play_count','video_views','video_views_count','video_plays'] as $field){
        $v=$row[$field]??null;
        if(is_int($v)||is_string($v)&&ctype_digit($v)){
            if((string)$v!=='' && (float)$v <= 2000000000)return (int)$v;
        }
    }
    return null;
}
function lc_apify_observe(int $clipId,int $views): bool {
    if($clipId<1||$views<0||$views>2000000000)throw new InvalidArgumentException('Métricas inválidas.');
    $date=(new DateTimeImmutable('now',new DateTimeZone('America/Fortaleza')))->format('Y-m-d');
    $observed=gmdate('Y-m-d H:i:s');
    $old=lc_one('SELECT views FROM clip_daily_views WHERE clip_id=? AND snapshot_date=?',[$clipId,$date]);
    lc_query('INSERT INTO clip_daily_views(clip_id,snapshot_date,views,source,observed_at)
       VALUES(?,? ,?,"apify",?) ON DUPLICATE KEY UPDATE views=VALUES(views),observed_at=VALUES(observed_at)',
       [$clipId,$date,$views,$observed]);
    // Preserve event history whenever the observed count changes, including
    // an unexpected decrease (the admin will decide which number to confirm).
    $latest=lc_one('SELECT views FROM clip_view_samples WHERE clip_id=? ORDER BY id DESC LIMIT 1',[$clipId]);
    if(!$latest||(int)$latest['views']!==$views)
        lc_query('INSERT INTO clip_view_samples(clip_id,views,source,observed_at)
           VALUES(?,?,"apify",?)',[$clipId,$views,$observed]);
    return !$old||(int)$old['views']!==$views;
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
      'actor'=>'apify~instagram-post-scraper',
      'max_posts'=>max(1,min(50,(int)lc_env('APIFY_MAX_POSTS','20'))),
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
/**
 * Official Apify Instagram Post Scraper accepts exact Instagram post URLs as
 * username[] and returns detailed views ONLY with dataDetailLevel=detailedData.
 * Details may cost more than basic data, so every request has a hard cost cap.
 */
function lc_apify_official_input(string $postUrl): array {
    return lc_apify_official_batch_input([$postUrl]);
}
function lc_apify_official_batch_input(array $urls): array {
    if(!$urls || count($urls)>50)throw new InvalidArgumentException('O lote deve conter entre 1 e 50 links.');
    $normalized=[];
    foreach($urls as $url){
        if(!is_string($url))throw new InvalidArgumentException('Link de publicação inválido.');
        $shortcode=lc_apify_shortcode($url);
        if(!$shortcode)throw new InvalidArgumentException('Link do Instagram inválido.');
        $path=(string)parse_url($url,PHP_URL_PATH);
        $type=preg_match('~^/(reel|reels)/~',$path)?'reel':'p';
        $normalized[$shortcode]='https://www.instagram.com/'.$type.'/'.$shortcode.'/';
    }
    return [
       'username'=>array_values($normalized),
       'resultsLimit'=>count($normalized),
       'dataDetailLevel'=>'detailedData'
    ];
}
function lc_apify_fetch(string $postUrl,array $config): array {
    return lc_apify_fetch_batch([$postUrl],$config);
}
function lc_apify_fetch_batch(array $urls,array $config): array {
    $input=lc_apify_official_batch_input($urls);
    if(($config['actor']??'')!=='apify~instagram-post-scraper')
       throw new RuntimeException('Actor não autorizado.');
    $total=count($input['username']);
    $url='https://api.apify.com/v2/acts/'.$config['actor'].'/run-sync-get-dataset-items?'.
        http_build_query([
            'timeout'=>110,
            'maxItems'=>$total,
            'maxTotalChargeUsd'=>number_format((float)$config['max_run_usd'],3,'.','')
        ]);
    $handle=curl_init($url);
    if(!$handle)throw new RuntimeException('Não foi possível abrir conexão Apify.');
    curl_setopt_array($handle,[
        CURLOPT_POST=>true,
        CURLOPT_POSTFIELDS=>json_encode($input,JSON_THROW_ON_ERROR),
        CURLOPT_HTTPHEADER=>[
           'Authorization: Bearer '.$config['token'],
           'Content-Type: application/json','Accept: application/json'
        ],
        CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>12,CURLOPT_TIMEOUT=>135,
        CURLOPT_FOLLOWLOCATION=>false,CURLOPT_MAXREDIRS=>0
    ]);
    $body=curl_exec($handle);
    $status=(int)curl_getinfo($handle,CURLINFO_HTTP_CODE);
    curl_close($handle);
    return lc_apify_parse_dataset_response($status,$body);
}
function lc_apify_import_clip(array $clip,array $rows): int {
    $shortcode=lc_apify_shortcode((string)$clip['published_url']);
    $username=strtolower(ltrim((string)$clip['username'],'@'));
    if(!$shortcode || !preg_match('/^[a-z0-9._]{2,100}$/D',$username))return 0;
    foreach($rows as $row){
        if(!is_array($row))continue;
        $views=lc_apify_post_views($row,$username,$shortcode);
        if($views!==null)return lc_apify_observe((int)$clip['id'],$views)?1:0;
    }
    return 0;
}
function lc_apify_import_profile(string $username,array $rows): int {
    $clips=lc_all("SELECT c.id,c.published_url,s.username FROM clips c
      JOIN social_accounts s ON s.id=c.social_account_id
      WHERE s.network='instagram' AND LOWER(s.username)=?
      AND c.status='approved' AND c.drive_file_id IS NOT NULL",[strtolower($username)]);
    $count=0;foreach($clips as $clip)$count+=lc_apify_import_clip($clip,$rows);
    return $count;
}
/** All real approved Instagram clipes, across campaigns, regardless of age. */
function lc_apify_eligible_clips(int $limit=5000): array {
    $limit=max(1,min(10000,$limit));
    return lc_all("SELECT c.id,c.published_url,s.username,c.submitted_at
        FROM clips c JOIN social_accounts s ON s.id=c.social_account_id
        WHERE s.network='instagram' AND c.status='approved'
          AND c.drive_file_id IS NOT NULL
        ORDER BY c.id DESC LIMIT ".$limit);
}
/**
 * Daily batch scheduler: a successful response with real author+post+view
 * produces one per-day MySQL snapshot. No payment is credited automatically.
 */
function lc_apify_sync(): void {
    $config=lc_apify_config();
    if($config['token']===''){echo "APIFY_DISABLED: no private token\n";return;}
    $db=lc_db();
    $locked=$db->query("SELECT GET_LOCK('leaguecut_apify_metrics_v1',0) AS acquired")->fetch(PDO::FETCH_ASSOC);
    if((int)($locked['acquired']??0)!==1){echo "APIFY_ALREADY_RUNNING\n";return;}
    try{
        $clips=lc_apify_eligible_clips();
        $eligible=[];
        $skip=0;
        foreach($clips as $clip){
            $sc=lc_apify_shortcode((string)$clip['published_url']);
            if(!$sc){$skip++;continue;}
            // Multiple users may submit the same public post. One charge per
            // shortcode, matched separately to each registered user handle.
            $eligible[$sc][]=$clip;
        }
        $usage=lc_apify_usage();
        $runs=$usage['runs'];$reserved=$usage['max_reserved_usd'];
        $ready=[];
        // Calendar-day idempotency in Fortaleza: avoids skipping the next day
        // when GitHub queues one execution a few minutes later than another.
        $midnight=(new DateTimeImmutable('today',new DateTimeZone('America/Fortaleza')))
           ->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        foreach($eligible as $sc=>$group){
            $recent=lc_one('SELECT shortcode FROM apify_post_checks
              WHERE shortcode=? AND last_checked_at>=?',[$sc,$midnight]);
            if(!$recent)$ready[$sc]=$group;
        }
        $batchSize=max(1,min(20,$config['max_posts']));
        // Process all eligible links in batches while daily budget permits.
        $attempted=0;$updated=0;
        foreach(array_chunk($ready,$batchSize,true) as $group){
            if($runs>=$config['daily_runs'] ||
               $reserved+$config['max_run_usd']>$config['daily_usd']+0.000001)break;
            $input=[];$scCodes=[];
            foreach($group as $sc=>$clipsForPost){
                $input[]=(string)$clipsForPost[0]['published_url'];
                $scCodes[]=$sc;
            }
            if(!$input)continue;
            $key='batch:'.bin2hex(random_bytes(10));
            lc_query('INSERT INTO apify_profile_runs(username,max_charge_usd,status)
                       VALUES(?,?,"reserved")',[$key,$config['max_run_usd']]);
            $runId=(int)$db->lastInsertId();
            $now=gmdate('Y-m-d H:i:s');
            foreach($scCodes as $sc){
                lc_query('INSERT INTO apify_post_checks(shortcode,last_checked_at,last_run_id)
                    VALUES(?,?,?) ON DUPLICATE KEY UPDATE
                    last_checked_at=VALUES(last_checked_at),last_run_id=VALUES(last_run_id)',
                    [$sc,$now,$runId]);
            }
            $runs++;$reserved+=$config['max_run_usd'];
            $attempted+=count($input);
            try{
                $rows=lc_apify_fetch_batch($input,$config);
                $result=0;
                foreach($group as $clipsForPost){
                    foreach($clipsForPost as $clip)$result+=lc_apify_import_clip($clip,$rows);
                }
                $updated+=$result;
                lc_query('UPDATE apify_profile_runs SET status="completed",matched_clips=?,result_count=?
                    WHERE id=?',[$result,count($rows),$runId]);
                echo "APIFY_BATCH posts=".count($input)." returned=".count($rows)." snapshots=".$result."\n";
            }catch(Throwable $e){
                $error=$e->getMessage();
                $category=preg_match('/HTTP ([0-9]+)/',$error,$m)?'HTTP '.$m[1]:'provider_error';
                lc_query('UPDATE apify_profile_runs SET status="failed",error_message=? WHERE id=?',[$category,$runId]);
                echo "APIFY_BATCH_FAILED category=".$category."\n";
            }
        }
        echo "APIFY_DAILY_RESULT eligible=".count($eligible)." attempted=".$attempted.
             " snapshots=".$updated." pending=".max(0,count($ready)-$attempted).
             " budget_reserved_usd=".number_format($reserved,3,'.','')."\n";
    }finally{$db->query("SELECT RELEASE_LOCK('leaguecut_apify_metrics_v1')");}
}
