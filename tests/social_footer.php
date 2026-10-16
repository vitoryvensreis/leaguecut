<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/core.php';
require dirname(__DIR__).'/app/seo.php';
function checkSocial(bool $ok,string $message):void{
    if(!$ok)throw new RuntimeException('B6_SOCIAL_FAILED: '.$message);
}
$valid=[
    'social_instagram'=>'https://www.instagram.com/leaguecut/',
    'social_tiktok'=>'https://www.tiktok.com/@leaguecut',
    'social_youtube'=>'https://www.youtube.com/@leaguecut',
    'social_facebook'=>'https://www.facebook.com/leaguecut',
    'social_x'=>'https://x.com/leaguecut',
    'social_linkedin'=>'https://www.linkedin.com/company/leaguecut'
];
foreach($valid as $key=>$url)checkSocial(lc_social_footer_valid($key,$url),'legitimate '.$key);
$bad=[
    ['social_instagram','https://instagram.com.evil.test/leaguecut'],
    ['social_instagram','http://instagram.com/leaguecut'],
    ['social_tiktok','https://example.com/@leaguecut'],
    ['social_x','https://someuser:password@x.com/leaguecut'],
    ['social_x','javascript:alert(1)'],
    ['social_youtube','https://youtube.com:444/@leaguecut'],
    ['social_facebook','https://facebook.com/leaguecut#private']
];
foreach($bad as [$key,$url])checkSocial(!lc_social_footer_valid($key,$url),'blocked bad URL');
$links=lc_social_footer_links($valid);
checkSocial(count($links)===6,'all configured networks rendered');
checkSocial(count(lc_social_footer_links(['social_instagram'=>'https://instagram.com.evil.test/'] ))===0,'unsafe link never rendered');
checkSocial(lc_social_footer_valid('social_linkedin',''),'empty profile permitted');
echo "B6_SOCIAL_LINKS_OK\n";
