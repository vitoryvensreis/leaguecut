<?php
declare(strict_types=1);

/**
 * Hybrid verification policy, independent from network transport and payouts.
 * Public URLs are submissions, never proof of ownership or verified views.
 */
final class LcHybridVerification
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_OBSERVED = 'observed';
    public const STATUS_REVIEW = 'manual_review';
    public const STATUS_UNAVAILABLE = 'unavailable';

    public static function identify(string $url): ?array
    {
        if (strlen($url) > 800 || filter_var($url, FILTER_VALIDATE_URL) === false) return null;
        $parts = parse_url($url);
        if (!is_array($parts) || strtolower((string)($parts['scheme'] ?? '')) !== 'https'
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])) return null;
        $host = strtolower(rtrim((string)($parts['host'] ?? ''), '.'));
        $path = (string)($parts['path'] ?? '');
        $network = null;
        $id = null;
        if (in_array($host, ['instagram.com', 'www.instagram.com'], true)
            && preg_match('~^/(?:p|reel|reels|tv)/([A-Za-z0-9_-]{5,80})/?$~D', $path, $m)) {
            $network = 'instagram'; $id = $m[1];
            $url = 'https://www.instagram.com/reel/' . $id . '/';
            // Canonical type is kept when supplied to avoid changing post routes.
            if (preg_match('~^/(p|tv)/~', $path, $type)) $url = 'https://www.instagram.com/' . $type[1] . '/' . $id . '/';
        } elseif (in_array($host, ['tiktok.com','www.tiktok.com'], true)
            && preg_match('~^/@[A-Za-z0-9._-]+/video/([0-9]{8,30})/?$~D', $path, $m)) {
            $network = 'tiktok'; $id = $m[1];
            $url = 'https://www.tiktok.com' . rtrim($path, '/') ;
        } elseif (in_array($host, ['youtube.com','www.youtube.com','m.youtube.com'], true)
            && preg_match('~^/shorts/([A-Za-z0-9_-]{11})/?$~D', $path, $m)) {
            $network = 'youtube'; $id = $m[1];
            $url = 'https://www.youtube.com/shorts/' . $id;
        } elseif ($host === 'youtu.be' && preg_match('~^/([A-Za-z0-9_-]{11})/?$~D', $path, $m)) {
            $network = 'youtube'; $id = $m[1];
            $url = 'https://www.youtube.com/watch?v=' . $id;
        } elseif (in_array($host, ['youtube.com','www.youtube.com','m.youtube.com'], true)
            && $path === '/watch') {
            parse_str((string)($parts['query'] ?? ''), $params);
            if (is_string($params['v'] ?? null) && preg_match('/^[A-Za-z0-9_-]{11}$/D', $params['v'])) {
                $network = 'youtube'; $id = $params['v'];
                $url = 'https://www.youtube.com/watch?v=' . $id;
            }
        }
        return $network === null ? null : ['network'=>$network,'external_id'=>$id,'canonical_url'=>$url];
    }

    public static function assess(array $observation, bool $ownerAuthorized = false): array
    {
        $source = (string)($observation['source'] ?? '');
        $views = $observation['views'] ?? null;
        $official = in_array($source, ['instagram_graph','tiktok_display','youtube_data'], true);
        $numberValid = is_int($views) && $views >= 0 && $views <= 2000000000;
        // YouTube public counts can be observed without linking an account.
        $authorized = $ownerAuthorized || $source === 'youtube_data';
        if ($official && $authorized && $numberValid) {
            return ['status'=>self::STATUS_OBSERVED,'observed_views'=>$views,
                'verified_views'=>null,'requires_admin_review'=>true];
        }
        return ['status'=>$source === 'unavailable' ? self::STATUS_UNAVAILABLE : self::STATUS_REVIEW,
            'observed_views'=>$numberValid ? $views : null,
            'verified_views'=>null,'requires_admin_review'=>true];
    }
}
