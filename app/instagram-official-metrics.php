<?php
declare(strict_types=1);
/** Instagram Login official media insights adapter; observational only. */
final class LcInstagramMetrics {
    public static function matchMedia(string $body,string $expectedShortcode,string $expectedUsername): ?string {
        if(strlen($body)>524288 || !preg_match('/^[A-Za-z0-9_-]{5,80}$/D',$expectedShortcode))return null;
        $json=json_decode($body,true);
        if(!is_array($json)||!is_array($json['data']??null))return null;
        foreach($json['data'] as $media){
            if(!is_array($media)||!is_scalar($media['id']??null))continue;
            $url=(string)($media['permalink']??'');
            $link=LcHybridVerification::identify($url);
            if(!$link||$link['network']!=='instagram'||$link['external_id']!==$expectedShortcode)continue;
            $username=$media['username']??null;
            if(!is_string($username)||strcasecmp(ltrim($username,'@'),ltrim($expectedUsername,'@'))!==0)continue;
            $id=(string)$media['id'];
            if(preg_match('/^[0-9]{5,40}$/D',$id))return $id;
        }
        return null;
    }
    public static function parseViews(string $body): ?array {
        if(strlen($body)>262144)return null;
        $json=json_decode($body,true);
        if(!is_array($json)||!is_array($json['data']??null))return null;
        foreach($json['data'] as $metric){
            if(!is_array($metric)||($metric['name']??'')!=='views')continue;
            $raw=$metric['values'][0]['value']??$metric['total_value']['value']??null;
            if((!is_string($raw)&&!is_int($raw))||!ctype_digit((string)$raw)
                ||filter_var((string)$raw,FILTER_VALIDATE_INT,['options'=>['min_range'=>0,'max_range'=>2000000000]])===false)return null;
            return ['source'=>'instagram_graph','views'=>(int)$raw];
        }
        return null;
    }
    public static function get(string $url,string $token): string {
        $host=strtolower((string)parse_url($url,PHP_URL_HOST));
        if($host!=='graph.instagram.com'||parse_url($url,PHP_URL_SCHEME)!=='https'||strlen($token)<10)
            throw new RuntimeException('Requisição rejeitada.');
        $h=curl_init($url);
        if($h===false)throw new RuntimeException('HTTP indisponível.');
        curl_setopt_array($h,[CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$token,'Accept: application/json'],
          CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>12,
          CURLOPT_FOLLOWLOCATION=>false,CURLOPT_MAXREDIRS=>0,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS]);
        $body=curl_exec($h);$status=(int)curl_getinfo($h,CURLINFO_HTTP_CODE);curl_close($h);
        if($status!==200||!is_string($body)||strlen($body)>524288)throw new RuntimeException('Consulta Meta indisponível.');
        return $body;
    }
    public static function fetch(string $shortcode,string $username,string $token): array {
        if(!preg_match('/^[A-Za-z0-9_-]{5,80}$/D',$shortcode))throw new InvalidArgumentException('Código inválido.');
        // Only first page. Absent item is unverified, not zero views. Pagination comes later.
        $listing=self::get('https://graph.instagram.com/me/media?fields=id,permalink,username&limit=100',$token);
        $id=self::matchMedia($listing,$shortcode,$username);
        if($id===null)throw new RuntimeException('Publicação não localizada na conta autorizada.');
        $insights=self::get('https://graph.instagram.com/'.$id.'/insights?metric=views',$token);
        $record=self::parseViews($insights);
        if($record===null)throw new RuntimeException('Views indisponíveis para esta publicação.');
        return $record;
    }
}
