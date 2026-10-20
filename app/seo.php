<?php
declare(strict_types=1);

/** B6B — official footer links, never fetched server-side and never accepts foreign hosts. */
function lc_social_footer_specs(): array {
    return [
        'social_instagram'=>['label'=>'Instagram','hosts'=>['instagram.com','www.instagram.com']],
        'social_tiktok'=>['label'=>'TikTok','hosts'=>['tiktok.com','www.tiktok.com']],
        'social_youtube'=>['label'=>'YouTube','hosts'=>['youtube.com','www.youtube.com','youtu.be']],
        'social_facebook'=>['label'=>'Facebook','hosts'=>['facebook.com','www.facebook.com','m.facebook.com']],
        'social_x'=>['label'=>'X / Twitter','hosts'=>['x.com','www.x.com','twitter.com','www.twitter.com']],
        'social_linkedin'=>['label'=>'LinkedIn','hosts'=>['linkedin.com','www.linkedin.com']]
    ];
}
function lc_social_footer_valid(string $key,string $url): bool {
    $spec=lc_social_footer_specs()[$key]??null;
    if(!$spec || strlen($url)>500)return false;
    if($url==='')return true;
    if(!lc_valid_https($url) || preg_match('/[\\x00-\\x20\\x7f]/',$url))return false;
    $parts=parse_url($url);
    if(!is_array($parts) || isset($parts['user']) || isset($parts['pass']) || isset($parts['port']))return false;
    if(isset($parts['fragment']))return false;
    $host=strtolower((string)($parts['host']??''));
    return in_array($host,$spec['hosts'],true);
}
function lc_social_footer_links(array $s): array {
    $out=[];
    foreach(lc_social_footer_specs() as $key=>$spec){
        $url=trim((string)($s[$key]??''));
        if($url!=='' && lc_social_footer_valid($key,$url))
            $out[]=['label'=>$spec['label'],'url'=>$url];
    }
    return $out;
}

/** Public SEO never derives a canonical host from the Host header or a query string. */
function lc_seo_origin(): string { return 'https://leaguecut.com'; }
/** Static, cacheable and crawler-friendly 1200x630 JPG; ADM upload can override it. */
function lc_seo_default_share_image(): string {
    return lc_seo_origin().'/assets/leaguecut-share-clean-2026.jpg';
}
function lc_seo_public_path(string $path): bool {
    return in_array($path,['/','/explorar','/ranking','/planos'],true) ||
      (bool)preg_match('~^/campanhas/[a-z0-9-]{1,210}$~D',$path);
}
function lc_seo_absolute_asset(string $url): string {
    if(preg_match('~^/media/(?:seo/(?:icon|social)|campaign)/[a-f0-9]{36}$~D',$url))return lc_seo_origin().$url;
    if(lc_valid_https($url) && !parse_url($url,PHP_URL_USER) && !parse_url($url,PHP_URL_PASS))
        return $url;
    return '';
}
function lc_seo_valid_asset(string $url,string $kind): bool {
    return $url==='' ||
        (bool)preg_match('~^/media/seo/'.preg_quote($kind,'~').'/[a-f0-9]{36}$~D',$url) ||
        (lc_valid_https($url) &&
         !parse_url($url,PHP_URL_USER) && !parse_url($url,PHP_URL_PASS) &&
         strlen($url)<=800 && !preg_match('/[\x00-\x1f\x7f]/',$url));
}
function lc_seo_text(string $v,int $max): string {
    $v=trim(preg_replace('/[\x00-\x1f\x7f]+/u',' ',strip_tags($v))??'');
    if(mb_strlen($v)>$max)throw new InvalidArgumentException('Texto SEO ultrapassa o limite de '.$max.' caracteres.');
    return $v;
}
function lc_seo_defaults(array $s): array {
    $brand=(string)($s['brand_name']??'LeagueCut');
    return [
        'seo_home_title'=>$brand.' — Crie, publique e conquiste',
        'seo_home_description'=>'Transforme vídeos em oportunidades. Descubra campanhas, publique clipes e acompanhe rankings e recompensas na LeagueCut.',
        'seo_default_description'=>'Conheça a LeagueCut: campanhas para clipadores, rankings de conteúdo e serviços de cortes para criadores.',
        'seo_explore_title'=>'Campanhas para clipadores · '.$brand,
        'seo_ranking_title'=>'Ranking de clipadores · '.$brand,
        'seo_plans_title'=>'Planos de cortes para criadores · '.$brand,
        'seo_keywords'=>'LeagueCut, clipadores, cortes de vídeo, campanhas, criadores',
        'seo_favicon_url'=>'',
        'seo_social_image_url'=>'',
        'seo_twitter_handle'=>'',
        'seo_canonical_base'=>lc_seo_origin(),
        'seo_index_enabled'=>'1',
        // Include persisted social links in the admin SEO form's whitelist.
        'social_instagram'=>'','social_tiktok'=>'','social_youtube'=>'',
        'social_facebook'=>'','social_x'=>'','social_linkedin'=>''
    ];
}
function lc_seo_config(array $s): array { return array_merge(lc_seo_defaults($s),array_intersect_key($s,lc_seo_defaults($s))); }
function lc_seo_meta(string $routeTitle,array $s,string $path,int $httpStatus=200): array {
    $cfg=lc_seo_config($s);
    $public=lc_seo_public_path($path)&&$httpStatus<400;
    if($public&&str_starts_with($path,'/campanhas/')){
        $slug=substr($path,11);
        $campaign=lc_one("SELECT title,description,cover_url FROM campaigns WHERE slug=? AND status='active' LIMIT 1",[$slug]);
        if(!$campaign)$public=false;
    }
    $index=$public&&$cfg['seo_index_enabled']==='1';
    $canonical=$public?rtrim($cfg['seo_canonical_base'],'/').($path==='/'?'/':$path):'';
    $site=(string)($s['brand_name']??'LeagueCut');
    $title=$path==='/'?(string)$cfg['seo_home_title']:
           ($path==='/explorar'?(string)$cfg['seo_explore_title']:
           ($path==='/ranking'?(string)$cfg['seo_ranking_title']:
           ($path==='/planos'?(string)$cfg['seo_plans_title']:$routeTitle.' · '.$site)));
    $desc=$path==='/'?(string)$cfg['seo_home_description']:(string)$cfg['seo_default_description'];
    if(isset($campaign) && $public){
        $title=$campaign['title'].' · '.$site;
        $desc=mb_substr(trim(preg_replace('/\s+/u',' ',strip_tags((string)$campaign['description']))??''),0,200);
    }
    $image=lc_seo_absolute_asset((string)$cfg['seo_social_image_url']);
    if($image==='')$image=lc_seo_default_share_image();
    if(isset($campaign)&&$public && (lc_seo_valid_asset((string)$campaign['cover_url'],'social') ||
       (bool)preg_match('~^/media/campaign/[a-f0-9]{36}$~D',(string)$campaign['cover_url']))){
        $cover=lc_seo_absolute_asset((string)$campaign['cover_url']);
        if($cover!=='')$image=$cover;
    }
    return [
       'title'=>$title,
       'description'=>$desc,
       'keywords'=>(string)$cfg['seo_keywords'],
       'robots'=>$index?'index, follow, max-image-preview:large':'noindex, nofollow, noarchive',
       'canonical'=>$canonical,
       'image'=>$public?$image:'',
       'icon'=>lc_seo_absolute_asset((string)$cfg['seo_favicon_url']),
       'twitter'=>(string)$cfg['seo_twitter_handle'],
       'public'=>$public,
       'brand'=>$site,
       'origin'=>lc_seo_origin()
    ];
}
function lc_seo_head(array $meta): void {
    ?>
<meta name="description" content="<?=lc_e($meta['description'])?>">
<meta name="robots" content="<?=lc_e($meta['robots'])?>">
<?php if($meta['public']): ?>
<?php if($meta['keywords']!==''):?><meta name="keywords" content="<?=lc_e($meta['keywords'])?>"><?php endif;?>
<link rel="canonical" href="<?=lc_e($meta['canonical'])?>">
<meta property="og:type" content="website">
<meta property="og:locale" content="pt_BR">
<meta property="og:site_name" content="<?=lc_e($meta['brand'])?>">
<meta property="og:title" content="<?=lc_e($meta['title'])?>">
<meta property="og:description" content="<?=lc_e($meta['description'])?>">
<meta property="og:url" content="<?=lc_e($meta['canonical'])?>">
<meta name="twitter:card" content="<?=$meta['image']!==''?'summary_large_image':'summary'?>">
<meta name="twitter:title" content="<?=lc_e($meta['title'])?>">
<meta name="twitter:description" content="<?=lc_e($meta['description'])?>">
<?php if($meta['image']!==''):?>
<meta property="og:image" content="<?=lc_e($meta['image'])?>">
<meta property="og:image:secure_url" content="<?=lc_e($meta['image'])?>">
<meta property="og:image:alt" content="<?=lc_e($meta['title'])?>">
<?php if($meta['image']===lc_seo_default_share_image()):?>
<meta property="og:image:type" content="image/jpeg">
<meta property="og:image:width" content="1200">
<meta property="og:image:height" content="630">
<?php elseif(str_starts_with($meta['image'],lc_seo_origin().'/share/leaguecut.png')):?>
<meta property="og:image:type" content="image/png">
<meta property="og:image:width" content="1200">
<meta property="og:image:height" content="630">
<?php endif;?>
<meta name="twitter:image" content="<?=lc_e($meta['image'])?>">
<?php endif;?>
<?php if($meta['twitter']!==''):?><meta name="twitter:site" content="<?=lc_e($meta['twitter'])?>"><?php endif;?>
<?php
$structured=['@context'=>'https://schema.org','@type'=>'WebSite','name'=>$meta['brand'],
    'url'=>$meta['origin'].'/','inLanguage'=>'pt-BR','description'=>$meta['description']];
echo '<script type="application/ld+json">'.json_encode($structured,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_THROW_ON_ERROR).'</script>'."\n";
endif;
if($meta['icon']!==''):?>
<!-- Optional custom SEO favicon; the PWA/iOS app icon remains LeagueCut-branded. -->
<link rel="icon" href="<?=lc_e($meta['icon'])?>">
<?php endif;
}
/** Durable shared directory. This is not under public/ or a GitHub release. */
function lc_seo_media_dir(): string {
    return lc_env('SEO_MEDIA_DIR','/home/leaguecut/shared/storage/seo');
}
function lc_seo_store_image(array $file,string $kind): string {
    if(!in_array($kind,['icon','social'],true))throw new InvalidArgumentException('Tipo de imagem não permitido.');
    if(($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK ||
       !is_uploaded_file((string)($file['tmp_name']??'')))
        throw new InvalidArgumentException('O upload não foi concluído.');
    $size=(int)($file['size']??0);
    if($size<1||$size>4*1024*1024)throw new InvalidArgumentException('Envie uma imagem de até 4 MB.');
    $path=(string)$file['tmp_name'];
    $info=@getimagesize($path);
    $mime=(new finfo(FILEINFO_MIME_TYPE))->file($path);
    $allowed=['image/png'=>'png','image/jpeg'=>'jpg','image/webp'=>'webp'];
    if(!$info || !is_string($mime)||!isset($allowed[$mime])||($info['mime']??'')!==$mime ||
       $info[0]<48||$info[1]<48||$info[0]>6000||$info[1]>6000)
       throw new InvalidArgumentException('Imagem inválida. Use PNG, JPG ou WebP entre 48 e 6000 pixels.');
    if($kind==='icon' && $info[0]!==$info[1])
       throw new InvalidArgumentException('O favicon precisa ser quadrado (ex.: 512 × 512 px).');
    if($kind==='social' && ($info[0]<300||$info[1]<157))
       throw new InvalidArgumentException('Imagem de compartilhamento muito pequena. Recomendado 1200 × 630 px.');
    $dir=lc_seo_media_dir();
    if(!is_dir($dir)&&!mkdir($dir,0700,true)&&!is_dir($dir))
       throw new RuntimeException('Não foi possível criar armazenamento privado SEO.');
    $id=bin2hex(random_bytes(18));$target=$dir.'/'.$kind.'-'.$id.'.'.$allowed[$mime];
    if(!move_uploaded_file($path,$target))throw new RuntimeException('Não foi possível salvar a imagem.');
    chmod($target,0600);
    return '/media/seo/'.$kind.'/'.$id;
}
function lc_seo_media_response(string $kind,string $id): never {
    $key=$kind==='icon'?'seo_favicon_url':'seo_social_image_url';
    $stored=(string)(lc_one('SELECT setting_value FROM platform_settings WHERE setting_key=?',[$key])['setting_value']??'');
    if($stored!=='/media/seo/'.$kind.'/'.$id){http_response_code(404);exit;}
    $found=glob(lc_seo_media_dir().'/'.$kind.'-'.$id.'.*')?:[];
    if(count($found)!==1){http_response_code(404);exit;}
    $file=$found[0];$ext=strtolower(pathinfo($file,PATHINFO_EXTENSION));
    $mimes=['png'=>'image/png','jpg'=>'image/jpeg','webp'=>'image/webp'];
    if(!isset($mimes[$ext])||!is_file($file)||!is_readable($file)){http_response_code(404);exit;}
    header('Content-Type: '.$mimes[$ext]);
    header('X-Content-Type-Options: nosniff');
    header("Content-Security-Policy: default-src 'none'; sandbox");
    header('Cache-Control: public, max-age=300');
    header('Content-Length: '.filesize($file));
    readfile($file);exit;
}
function lc_seo_robots(): never {
    $cfg=lc_seo_config(lc_settings());
    header('Content-Type: text/plain; charset=UTF-8');
    header('Cache-Control: public, max-age=300');
    echo "User-agent: *\n";
    if($cfg['seo_index_enabled']!=='1')echo "Disallow: /\n";
    else {
       foreach(['/admin/','/conta','/financeiro','/dashboard','/biblioteca','/redes',
          '/criador','/seguranca/','/entrar','/cadastrar','/aguardando','/clipes',
          '/indicacoes','/sair','/media/'] as $private)echo 'Disallow: '.$private."\n";
    }
    echo 'Sitemap: '.lc_seo_origin()."/sitemap.xml\n";
    exit;
}
function lc_seo_sitemap(): never {
    header('Content-Type: application/xml; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: public, max-age=600');
    $settings=lc_seo_config(lc_settings());
    echo '<?xml version="1.0" encoding="UTF-8"?>'."\n";
    echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n";
    if($settings['seo_index_enabled']==='1'){
        $base=rtrim((string)$settings['seo_canonical_base'],'/');
        foreach(['/','/explorar','/ranking','/planos'] as $path)
           echo '<url><loc>'.lc_e($base.$path).'</loc></url>'."\n";
        foreach(lc_all("SELECT slug,updated_at FROM campaigns WHERE status='active' ORDER BY id DESC LIMIT 5000") as $c){
           $slug=(string)$c['slug'];
           if(!preg_match('/^[a-z0-9-]{1,210}$/D',$slug))continue;
           echo '<url><loc>'.lc_e($base.'/campanhas/'.$slug).'</loc><lastmod>'.
                lc_e(date('Y-m-d',strtotime((string)$c['updated_at']))).'</lastmod></url>'."\n";
        }
    }
    echo '</urlset>';exit;
}
function lc_seo_public_routes(string $method,string $path): bool {
    if($method!=='GET')return false;
    if($path==='/robots.txt')lc_seo_robots();
    if($path==='/sitemap.xml')lc_seo_sitemap();
    if($path==='/favicon.ico'){
        $url=lc_seo_absolute_asset((string)(lc_seo_config(lc_settings())['seo_favicon_url']??''));
        if($url!==''){header('Location: '.$url,true,302);exit;}
        // Old browsers request favicon.ico directly, without reading the head.
        require_once __DIR__ . '/brand-images.php';
        $ico=lc_brand_favicon_ico();
        header('Content-Type: image/x-icon');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: public,max-age=604800');
        header('Content-Length: '.strlen($ico));
        echo $ico;exit;
    }
    return false;
}
