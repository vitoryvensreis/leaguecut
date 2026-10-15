<?php
declare(strict_types=1);

/**
 * Official provider adapter. No public link is proof of clip ownership.
 * YouTube public views are observations only; financial validation remains manual.
 */
final class LcOfficialMetrics
{
    public static function youtubePayload(string $body, string $expectedVideoId): ?array
    {
        if (!preg_match('/^[A-Za-z0-9_-]{11}$/D', $expectedVideoId) || strlen($body) > 262144) return null;
        $json = json_decode($body, true);
        if (!is_array($json) || !is_array($json['items'] ?? null)) return null;
        foreach ($json['items'] as $item) {
            if (!is_array($item) || ($item['id'] ?? '') !== $expectedVideoId) continue;
            $v = $item['statistics']['viewCount'] ?? null;
            if (!(is_string($v) && ctype_digit($v)) && !is_int($v)) return null;
            if (filter_var((string)$v, FILTER_VALIDATE_INT, ['options'=>['min_range'=>0,'max_range'=>2000000000]]) === false) return null;
            return ['source'=>'youtube_data','views'=>(int)$v];
        }
        return null;
    }

    public static function fetchYouTube(string $videoId, string $apiKey): array
    {
        if (!preg_match('/^[A-Za-z0-9_-]{11}$/D',$videoId) || trim($apiKey)==='')
            throw new InvalidArgumentException('ID de vídeo ou chave não configurada.');
        $url='https://www.googleapis.com/youtube/v3/videos?' . http_build_query([
            'part'=>'statistics','id'=>$videoId,'key'=>$apiKey
        ],'','&',PHP_QUERY_RFC3986);
        $curl=curl_init($url);
        if ($curl===false) throw new RuntimeException('Falha ao iniciar consulta.');
        curl_setopt_array($curl,[
            CURLOPT_RETURNTRANSFER=>true,
            CURLOPT_CONNECTTIMEOUT=>5,
            CURLOPT_TIMEOUT=>12,
            CURLOPT_FOLLOWLOCATION=>false,
            CURLOPT_MAXREDIRS=>0,
            CURLOPT_HTTPHEADER=>['Accept: application/json'],
            CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS
        ]);
        $body=curl_exec($curl);
        $status=(int)curl_getinfo($curl,CURLINFO_HTTP_CODE);
        curl_close($curl);
        if ($status!==200 || !is_string($body))
            throw new RuntimeException('Provedor oficial indisponível ou limite de API atingido.');
        $parsed=self::youtubePayload($body,$videoId);
        if ($parsed===null)throw new RuntimeException('Vídeo sem métricas públicas utilizáveis.');
        return $parsed;
    }
}
