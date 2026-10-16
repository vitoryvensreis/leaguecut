<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/core.php';
require dirname(__DIR__).'/app/seo.php';
function shareAssert(bool $ok,string $why):void {
    if(!$ok)throw new RuntimeException('SHARE_PREVIEW_FAILED: '.$why);
}
$path=dirname(__DIR__).'/public/assets/leaguecut-share-clean-2026.jpg';
shareAssert(is_file($path),'art imported into git');
shareAssert(filesize($path)===61999,'asset byte size');
shareAssert(hash_file('sha256',$path)==='a4d9749dd38008bd9dca3453ee6076c86fbd49a868280a2565461417ac293a4c','art fingerprint');
$info=getimagesize($path);
shareAssert((int)$info[0]===1200&&(int)$info[1]===630&&$info['mime']==='image/jpeg','genuine JPG at 1200x630');
$meta=lc_seo_meta('Home',[],'/');
shareAssert($meta['image']===lc_seo_default_share_image(),'default image');
$custom='https://example.org/custom-social.jpg';
$override=lc_seo_meta('Home',['seo_social_image_url'=>$custom],'/');
shareAssert($override['image']===$custom,'admin customization preserved');
ob_start();lc_seo_head($meta);$head=ob_get_clean();
shareAssert(str_contains($head,'property="og:image:type" content="image/jpeg"'),'OG MIME');
shareAssert(str_contains($head,'property="og:image:width" content="1200"'),'OG width');
shareAssert(str_contains($head,'property="og:image:height" content="630"'),'OG height');
shareAssert(str_contains($head,'name="twitter:card" content="summary_large_image"'),'Twitter image');
echo "LEAGUECUT_SOCIAL_CARD_OK\n";
