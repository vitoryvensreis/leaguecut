<?php
declare(strict_types=1);
/**
 * TikTok v2 video/query adapter.
 * Only request video IDs tied to the authenticated account's token.
 * No payout/leaderboard side effects.
 */
final class LcTikTokMetrics {
    public static function parse(string $body,string $expectedId): ?array {
        if(!preg_match('/^[0-9]{8,30}$/D',$expectedId)||strlen($body)>262144)return null;
        $decoded=json_decode($body,true);
        if(!is_array($decoded)||($decoded['error']['code']??'ok')!=='ok')return null;
        $videos=$decoded['data']['videos']??null;
        if(!is_array($videos))return null;
        foreach($videos as $v){
            if(!is_array($v)|| (string)($v['id']??'')!==$expectedId)continue;
            $count=$v['view_count']??null;
            if((!is_int($count)&&!is_string($count))||!ctype_digit((string)$count)
                ||filter_var((string)$count,FILTER_VALIDATE_INT,
                ['options'=>['min_range'=>0,'max_range'=>2000000000]])===false)return null;
            return ['source'=>'tiktok_display','views'=>(int)$count];
        }
        return null;
    }
    public static function fetch(string $videoId,string $accessToken): array {
        if(!preg_match('/^[0-9]{8,30}$/D',$videoId)||strlen($accessToken)<10)
            throw new InvalidArgumentException('ID ou token inválido.');
        $url='https://open.tiktokapis.com/v2/video/query/?fields=id,view_count';
        $h=curl_init($url);
        if($h===false)throw new RuntimeException('HTTP indisponível.');
        curl_setopt_array($h,[
            CURLOPT_POST=>true,
            CURLOPT_POSTFIELDS=>json_encode(['filters'=>['video_ids'=>[$videoId]]],JSON_THROW_ON_ERROR),
            CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$accessToken,'Accept: application/json','Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>12,
            CURLOPT_FOLLOWLOCATION=>false,CURLOPT_MAXREDIRS=>0,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS
        ]);
        $body=curl_exec($h);$status=(int)curl_getinfo($h,CURLINFO_HTTP_CODE);curl_close($h);
        if($status!==200||!is_string($body))throw new RuntimeException('Provedor indisponível.');
        $record=self::parse($body,$videoId);
        if($record===null)throw new RuntimeException('Vídeo não retornado ou métrica inválida.');
        return $record;
    }
}
