<?php
declare(strict_types=1);
/* Pure URL regression: never transform an arbitrary external link to iframe src. */
function lc_valid_https(string $url): bool {
    return filter_var($url,FILTER_VALIDATE_URL)!==false &&
        strtolower((string)parse_url($url,PHP_URL_SCHEME))==='https';
}
require dirname(__DIR__).'/app/social-feed.php';
function feed_assert(bool $v,string $description): void {
    if(!$v)throw new RuntimeException('FEED FAILED: '.$description);
}
$ok='https://www.instagram.com/reel/DJ1Az-9_wXZ/?utm_source=ig_web_copy_link';
feed_assert(lc_feed_embed_url($ok,'instagram')==='https://www.instagram.com/reel/DJ1Az-9_wXZ/embed/','public Reel becomes Instagram embed');
feed_assert(lc_feed_embed_url('https://instagram.com/p/DbNqwiKx_7k/','instagram')==='https://www.instagram.com/p/DbNqwiKx_7k/embed/','regular Instagram posts use native embed');
feed_assert(lc_feed_embed_url('https://www.tiktok.com/@criador.leaguecut/video/7269876543210123456','tiktok')==='https://www.tiktok.com/embed/v2/7269876543210123456','TikTok videos use official player');
feed_assert(lc_feed_embed_url('https://vm.tiktok.com/short/','tiktok')==='','short links get external fallback, no unsafe embed');
foreach([
    'http://www.instagram.com/reel/DJ1Az-9_wXZ/',
    'https://www.instagram.com.evil.org/reel/DJ1Az-9_wXZ/',
    'https://user:pass@www.instagram.com/reel/DJ1Az-9_wXZ/',
    'https://www.instagram.com:444/reel/DJ1Az-9_wXZ/',
    'https://www.instagram.com/reel/DJ1Az-9_wXZ/../x',
    'https://www.instagram.com/reel/DJ1Az-9_wXZ/#fragment',
    'https://www.instagram.com/reel/DJ1Az-9_wXZ/"><script>',
    'javascript:alert(1)',
] as $bad){
    feed_assert(lc_feed_embed_url($bad,'instagram')==='','invalid URL cannot produce iframe: '.$bad);
}
feed_assert(lc_feed_safe_original('https://tiktok.com/@account/video/7269876543210123456','tiktok')!=='','valid original retained');
feed_assert(lc_feed_safe_original('https://youtube.com/watch?v=test','instagram')==='','foreign network URL excluded');
echo "SOCIAL_FEED_EMBED_SECURITY_OK\n";
