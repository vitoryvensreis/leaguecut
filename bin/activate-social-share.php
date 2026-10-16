<?php
declare(strict_types=1);

/** One-time, idempotent publication of the approved LeagueCut WhatsApp card.
 * The previous admin setting is preserved; later edits via /admin/seo are never overwritten.
 */
if(PHP_SAPI!=='cli')exit(1);
require dirname(__DIR__).'/app/core.php';
require dirname(__DIR__).'/app/seo.php';
$asset='public/assets/leaguecut-share-clean-2026.jpg';
$expected='a4d9749dd38008bd9dca3453ee6076c86fbd49a868280a2565461417ac293a4c';
$file=dirname(__DIR__).'/'.$asset;
if(!is_file($file)||hash_file('sha256',$file)!==$expected)
    throw new RuntimeException('Approved social image is missing or changed.');
$dimensions=@getimagesize($file);
if(!$dimensions||$dimensions[0]!==1200||$dimensions[1]!==630||$dimensions['mime']!=='image/jpeg')
    throw new RuntimeException('Social image requires JPEG 1200x630.');
$key='seo_share_clean_20261016_applied';
$newValue=lc_seo_default_share_image();
$db=lc_db();$db->beginTransaction();
try {
    $marker=lc_one('SELECT setting_value FROM platform_settings WHERE setting_key=? FOR UPDATE',[$key]);
    if($marker && (string)$marker['setting_value']==='1'){
        $db->commit();
        echo "LEAGUECUT_SOCIAL_SHARE_ALREADY_APPLIED\n";
        exit(0);
    }
    $previous=lc_one('SELECT setting_value FROM platform_settings WHERE setting_key=? FOR UPDATE',
      ['seo_social_image_url']);
    lc_query('INSERT INTO platform_settings(setting_key,setting_value) VALUES(?,?)
      ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)',
      ['seo_social_image_url_before_clean_20261016',(string)($previous['setting_value']??'')]);
    lc_query('INSERT INTO platform_settings(setting_key,setting_value) VALUES(?,?)
      ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)',
      ['seo_social_image_url',$newValue]);
    lc_query('INSERT INTO platform_settings(setting_key,setting_value) VALUES(?,?)
      ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)',[$key,'1']);
    $db->commit();
    echo "LEAGUECUT_SOCIAL_SHARE_ENABLED\n";
} catch(Throwable $e) {
    if($db->inTransaction())$db->rollBack();
    throw $e;
}
