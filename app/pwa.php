<?php
declare(strict_types=1);
/** B6D1 PWA: private pages and their API/data responses are always online-only. */
function lc_pwa_png_chunk(string $kind,string $data): string {
    return pack('N',strlen($data)).$kind.$data.pack('N',crc32($kind.$data));
}
/** Self-contained icon, no GD/Imagick dependency or external services. */
function lc_pwa_icon_png(int $size): string {
    if(!in_array($size,[192,512],true))throw new InvalidArgumentException('Tamanho de ícone inválido.');
    $bytes='';
    for($y=0;$y<$size;$y++){
        $bytes.="\x00";
        $yf=($y+.5)/$size;
        for($x=0;$x<$size;$x++){
            $xf=($x+.5)/$size;
            // Black LeagueCut tile with a red rounded-square, white cinematic play glyph.
            $r=11;$g=13;$b=20;
            $dx=max(abs($xf-.5)-.27,0);
            $dy=max(abs($yf-.5)-.27,0);
            if($dx*$dx+$dy*$dy<.060*.060 && abs($xf-.5)<.33 && abs($yf-.5)<.33){
                $r=228;$g=(int)(24+22*$yf);$b=(int)(42+9*$xf);
            }
            $inPlay=$xf>=.435 && $xf<=.65 &&
                abs($yf-.5)<(.65-$xf)*.88;
            if($inPlay){$r=255;$g=255;$b=255;}
            $bytes.=chr($r).chr($g).chr($b)."\xff";
        }
    }
    return "\x89PNG\r\n\x1a\n".
       lc_pwa_png_chunk('IHDR',pack('NNCCCCC',$size,$size,8,6,0,0,0)).
       lc_pwa_png_chunk('IDAT',gzcompress($bytes,8)).
       lc_pwa_png_chunk('IEND','');
}
function lc_pwa_sw_source(): string {
    return <<<'JS'
'use strict';
const CACHE='leaguecut-public-static-v1';
const STATIC=['/assets/app.css?v=10','/assets/v2.css?v=10','/assets/icons.svg','/app-icon/192.png'];
self.addEventListener('install',event=>{
  event.waitUntil(caches.open(CACHE).then(cache=>cache.addAll(STATIC)).then(()=>self.skipWaiting()));
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
JS;
}
function lc_pwa_routes(string $method,string $path): bool {
    if($method!=='GET')return false;
    if($path==='/manifest.webmanifest'){
        $s=lc_settings();
        $name=(string)($s['brand_name']??'LeagueCut');
        header('Content-Type: application/manifest+json; charset=UTF-8');
        header('Cache-Control: public, max-age=300');
        echo json_encode([
            'id'=>'/','name'=>$name,'short_name'=>'LeagueCut','start_url'=>'/',
            'scope'=>'/','display'=>'standalone','background_color'=>'#080a0e',
            'theme_color'=>'#e50914','lang'=>'pt-BR',
            'icons'=>[
                ['src'=>'/app-icon/192.png','type'=>'image/png','sizes'=>'192x192','purpose'=>'any maskable'],
                ['src'=>'/app-icon/512.png','type'=>'image/png','sizes'=>'512x512','purpose'=>'any maskable']
            ],
            'description'=>'Acesse suas campanhas, clipes e recompensas LeagueCut.'
        ],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        return true;
    }
    if($path==='/sw.js'){
        header('Content-Type: text/javascript; charset=UTF-8');
        header('Service-Worker-Allowed: /');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('X-Content-Type-Options: nosniff');
        echo lc_pwa_sw_source();
        return true;
    }
    if(preg_match('~^/app-icon/(192|512)\.png$~D',$path,$m)){
        $png=lc_pwa_icon_png((int)$m[1]);
        header('Content-Type: image/png');
        header('Content-Length: '.strlen($png));
        header('Cache-Control: public,max-age=86400,immutable');
        echo $png;
        return true;
    }
    return false;
}
