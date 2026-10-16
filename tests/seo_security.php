<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/core.php';
require dirname(__DIR__).'/app/seo.php';
function seo_assert(bool $yes,string $why):void {
    if(!$yes)throw new RuntimeException('B5_SEO_FAILED: '.$why);
}
$base=['brand_name'=>'LeagueCut'];
$socialConfig=lc_seo_config([
  'social_instagram'=>'https://www.instagram.com/leaguecut/',
  'social_tiktok'=>'https://www.tiktok.com/@leaguecut',
  'social_youtube'=>'https://www.youtube.com/@leaguecut',
  'social_facebook'=>'https://www.facebook.com/leaguecut',
  'social_x'=>'https://x.com/leaguecut',
  'social_linkedin'=>'https://www.linkedin.com/company/leaguecut/'
]);
foreach(lc_social_footer_specs() as $key=>$spec){
  seo_assert(isset($socialConfig[$key]) && $socialConfig[$key]!=='','Saved '.$key.' must prefill SEO form');
}
seo_assert(lc_seo_config([])['social_instagram']==='','Missing social keys default to empty');
$home=lc_seo_meta('Início',$base,'/');
seo_assert($home['image']==='https://leaguecut.com/assets/leaguecut-share-clean-2026.jpg','default Open Graph image');
seo_assert($home['public'] && $home['robots']==='index, follow, max-image-preview:large','public home indexable');
seo_assert($home['canonical']==='https://leaguecut.com/','fixed canonical');
$admin=lc_seo_meta('Gerenciar dados',$base,'/admin/contas');
seo_assert(!$admin['public']&&$admin['canonical']===''&&$admin['image']===''&&
    str_starts_with($admin['robots'],'noindex'),'admin pages noindex with no canonical');
$private=lc_seo_meta('Meu financeiro',$base,'/financeiro');
seo_assert(!$private['public']&&!str_contains($private['description'],'Pix'),'private view not indexed');
$disabled=lc_seo_meta('Início',['seo_index_enabled'=>'0'],'/');
seo_assert(str_starts_with($disabled['robots'],'noindex'),'admin index switch applies');
seo_assert(lc_seo_absolute_asset('/media/seo/social/'.str_repeat('a',36))==='https://leaguecut.com/media/seo/social/'.str_repeat('a',36),'absolute social asset');
seo_assert(!lc_seo_valid_asset('javascript:alert(1)','social'),'never allow script URL');
seo_assert(!lc_seo_valid_asset('https://user:pass@example.com/a.png','social'),'reject userinfo URLs');
seo_assert(!lc_seo_valid_asset('/media/brand/'.str_repeat('a',36),'social'),'reject foreign private media namespace');
seo_assert(lc_seo_valid_asset('https://cdn.example.com/share.jpg','social'),'allow HTTPS images');
seo_assert(lc_seo_meta('Início',['seo_social_image_url'=>'https://cdn.example.com/other.jpg'],'/')['image']==='https://cdn.example.com/other.jpg','SEO custom social overrides default');
seo_assert(lc_seo_text('<script>alert(1)</script>Olá',100)==='alert(1)Olá','remove HTML tags');
$injected=lc_seo_meta('Início',[
    'seo_home_title'=>'Campanha "><script>alert(1)</script>',
    'seo_home_description'=>'<img src=x onerror=alert(1)> Promoção <b>segura</b>',
    'seo_keywords'=>'" onload="alert(1)',
    'seo_twitter_handle'=>'@leaguecut'
],'/');
ob_start();lc_seo_head($injected);$html=(string)ob_get_clean();
seo_assert(!str_contains($html,'<img src=x') && !str_contains($html,'<script>alert(1)</script>'),'do not output executable HTML');
seo_assert(str_contains($html,'&quot;'),'escape HTML attributes');
seo_assert(str_contains($html,'application/ld+json')&&
    str_contains($html,'https://schema.org'),'structured website metadata');
seo_assert(str_contains($html,'property="og:title"'),'Open Graph rendered');
seo_assert(str_contains($html,'og:image:width') && str_contains($html,'1200'),'social dimensions');
seo_assert(str_contains($html,'image/jpeg'),'social type must match static JPG');
seo_assert(lc_seo_default_share_image()==='https://leaguecut.com/assets/leaguecut-share-clean-2026.jpg','stable static share URL');
seo_assert(str_contains($html,'name="twitter:card"'),'Twitter card rendered');
ob_start();lc_seo_head($admin);$privateHead=(string)ob_get_clean();
seo_assert(!str_contains($privateHead,'og:title')&&!str_contains($privateHead,'canonical'),'no share metadata on private panel');
$candidate=lc_one("SELECT slug,title FROM campaigns WHERE status='active' LIMIT 1");
if($candidate){
    $c=lc_seo_meta('Ignorar',$base,'/campanhas/'.$candidate['slug']);
    seo_assert($c['public'] && str_contains($c['title'],$candidate['title']),'real campaign detail metadata');
}
$missing=lc_seo_meta('Falsa',$base,'/campanhas/campanha-inexistente-b5');
seo_assert(!$missing['public']&&str_starts_with($missing['robots'],'noindex'),'unknown campaign never indexed');
echo "B5_SEO_SECURITY_OK\n";
