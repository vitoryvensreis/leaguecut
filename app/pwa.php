<?php
declare(strict_types=1);
/** B6D1 PWA: private pages and their API/data responses are always online-only. */
require_once __DIR__ . '/brand-images.php';
require_once __DIR__ . '/seo.php';

/** Resolve the saved favicon without fetching arbitrary remote assets on the VPS. */
function lc_pwa_active_icon(?array $settings=null): ?array {
    $cfg=lc_seo_config($settings??lc_settings());
    $src=trim((string)($cfg['seo_favicon_url']??''));
    if($src==='' || !lc_seo_valid_asset($src,'icon'))return null;
    if(preg_match('~^/media/seo/icon/([a-f0-9]{36})$~D',$src,$m)){
        $matches=glob(lc_seo_media_dir().'/icon-'.$m[1].'.*')?:[];
        if(count($matches)!==1 || !is_file($matches[0]))return null;
        $info=@getimagesize($matches[0]);
        if(!$info || ($info[0]??0)!==($info[1]??0) || !in_array($info['mime']??'',['image/png','image/jpeg','image/webp'],true))
            return null;
        $size=(int)$info[0];
        return ['src'=>$src,'type'=>(string)$info['mime'],'sizes'=>$size.'x'.$size,'width'=>$size];
    }
    // Admin may opt for an HTTPS asset. Never proxy or download it server-side.
    if(lc_valid_https($src))return ['src'=>$src,'type'=>null,'sizes'=>'any','width'=>0];
    return null;
}
function lc_pwa_icon_revision(?array $active): string {
    return substr(hash('sha256',(string)($active['src']??'default')),0,12);
}
function lc_pwa_manifest_icons(?array $active): array {
    $standard=[
        ['src'=>'/pwa/icon/192','type'=>'image/png','sizes'=>'192x192','purpose'=>'any'],
        ['src'=>'/pwa/icon/512','type'=>'image/png','sizes'=>'512x512','purpose'=>'any'],
        ['src'=>'/pwa/icon/192-maskable','type'=>'image/png','sizes'=>'192x192','purpose'=>'maskable'],
        ['src'=>'/pwa/icon/512-maskable','type'=>'image/png','sizes'=>'512x512','purpose'=>'maskable']
    ];
    if(!$active)return $standard;
    $chosen=['src'=>$active['src'],'sizes'=>$active['sizes'],'purpose'=>'any'];
    if($active['type'])$chosen['type']=$active['type'];
    // For a high-resolution square image, the customized icon becomes the primary
    // app artwork. Smaller favicons retain the installable built-in fallback.
    if($active['width']>=512){
        // Keep exact 192x192 fallbacks for installation engines which require both
        // 192 and 512 sizes. The configured high-resolution image remains primary.
        return [
            $chosen,
            ['src'=>'/pwa/icon/192','type'=>'image/png','sizes'=>'192x192','purpose'=>'any'],
            array_merge($chosen,['purpose'=>'maskable']),
            ['src'=>'/pwa/icon/192-maskable','type'=>'image/png','sizes'=>'192x192','purpose'=>'maskable']
        ];
    }
    return array_merge([$chosen],$standard);
}

function lc_pwa_icon_png(int $size,bool $maskable=false): string {
    return lc_brand_icon_png($size,$maskable);
}
function lc_pwa_sw_source(): string {
    return <<<'JS'
'use strict';
const CACHE='leaguecut-public-static-v4';
const STATIC=['/assets/app.css?v=10','/assets/v2.css?v=10','/assets/icons.svg','/pwa/icon/192'];
self.addEventListener('install',event=>{
  // Missing optional static files must not block activation or app installation.
  event.waitUntil(caches.open(CACHE).then(cache=>Promise.allSettled(STATIC.map(url=>cache.add(url)))).then(()=>self.skipWaiting()));
});
self.addEventListener('activate',event=>{
  event.waitUntil(caches.keys().then(keys=>Promise.all(keys.filter(x=>x.startsWith('leaguecut-public-static-')&&x!==CACHE).map(x=>caches.delete(x)))).then(()=>self.clients.claim()));
});
self.addEventListener('fetch',event=>{
  const req=event.request;
  if(req.method!=='GET' || req.mode==='navigate' || req.headers.has('Authorization'))return;
  const url=new URL(req.url);
  if(url.origin!==self.location.origin)return;
  // The service worker NEVER intercepts HTML, private files, API, finance, login or user media.
  const staticAsset=/^\/assets\/[a-zA-Z0-9_/-]+\.(?:css|js|svg|png|jpg|jpeg|webp|gif|ico|woff2?)$/.test(url.pathname);
  if(!staticAsset)return;
  event.respondWith(caches.open(CACHE).then(async cache=>{
    const saved=await cache.match(req);
    try{
      const fresh=await fetch(req);
      if(fresh.ok && fresh.type==='basic' && (fresh.headers.get('content-type')||'').match(/text\/css|javascript|image\/|font\//))
        await cache.put(req,fresh.clone());
      return fresh;
    }catch(err){
      if(saved)return saved;
      throw err;
    }
  }));
});
self.addEventListener('push',event=>{
  let input={};
  try{input=event.data?event.data.json():{};}catch(_){input={};}
  const id=Number(input.id)||0;
  const allowed=['/admin','/dashboard','/criador','/notificacoes'];
  const target=allowed.includes(input.url)?input.url:'/notificacoes';
  const body=typeof input.body==='string'&&input.body.length<150?input.body:
    'Você tem uma nova atualização.';
  event.waitUntil(self.registration.showNotification('LeagueCut',{
    body,icon:'/pwa/icon/192',badge:'/pwa/icon/48',
    tag:'leaguecut-event-'+id,
    data:{target},renotify:false
  }));
});
self.addEventListener('notificationclick',event=>{
  event.notification.close();
  const target=event.notification.data?.target||'/notificacoes';
  event.waitUntil(self.clients.matchAll({type:'window',includeUncontrolled:true}).then(async windows=>{
    for(const client of windows){
      if(new URL(client.url).origin===self.location.origin){
        await client.focus();
        return client.navigate(target);
      }
    }
    return self.clients.openWindow(target);
  }));
});
JS;
}
function lc_pwa_routes(string $method,string $path): bool {
    if($method!=='GET')return false;
    // Extensionless canonical endpoints bypass common Nginx static-file rules
    // that intercept .js/.png/.webmanifest before PHP is reached.
    if($path==='/pwa/manifest' || $path==='/manifest.webmanifest'){
        $s=lc_settings();
        $name=(string)($s['brand_name']??'LeagueCut');
        $selectedIcon=lc_pwa_active_icon($s);
        header('Content-Type: application/manifest+json; charset=UTF-8');
        header('Cache-Control: public, max-age=300');
        echo json_encode([
            'id'=>'/','name'=>$name,'short_name'=>'LeagueCut','start_url'=>'/',
            'scope'=>'/','display'=>'standalone','background_color'=>'#080a0e',
            'theme_color'=>'#e50914','lang'=>'pt-BR',
            'icons'=>lc_pwa_manifest_icons($selectedIcon),
            'description'=>'Acesse suas campanhas, clipes e recompensas LeagueCut.'
        ],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        return true;
    }
    if($path==='/pwa/worker' || $path==='/sw.js'){
        header('Content-Type: text/javascript; charset=UTF-8');
        header('Service-Worker-Allowed: /');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('X-Content-Type-Options: nosniff');
        echo lc_pwa_sw_source();
        return true;
    }
    if(preg_match('~^/pwa/icon/(32|48|180|192|512)(-maskable)?$~D',$path,$m) ||
       preg_match('~^/app-icon/(32|48|180|192|512)(-maskable)?\.png$~D',$path,$m)){
        $png=lc_pwa_icon_png((int)$m[1],($m[2]??'')==='-maskable');
        header('Content-Type: image/png');
        header('X-Content-Type-Options: nosniff');
        header('Content-Length: '.strlen($png));
        header('Cache-Control: public,max-age=604800,immutable');
        echo $png;
        return true;
    }
    if($path==='/share/leaguecut.png'){
        $png=lc_brand_social_png();
        header('Content-Type: image/png');
        header('X-Content-Type-Options: nosniff');
        header('Content-Length: '.strlen($png));
        header('Cache-Control: public,max-age=604800,immutable');
        echo $png;
        return true;
    }
    return false;
}
