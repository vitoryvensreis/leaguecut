<?php
declare(strict_types=1);

/** One active home banner; the table is created by the additive database migration. */
function lc_home_defaults(): array {
    return [
        'id'=>1, 'enabled'=>1, 'eyebrow'=>'Criadores no centro de tudo',
        'title_top'=>'Seu talento.', 'title_accent'=>'Mais alcance.', 'title_bottom'=>'Novas conquistas.',
        'subtitle'=>'O espaço de quem transforma vídeos em oportunidades. Participe de campanhas, publique seus conteúdos e acompanhe recompensas verificadas conforme as regras.',
        'cta_primary_text'=>'Quero ser clipador', 'cta_primary_url'=>'/cadastrar',
        'cta_secondary_text'=>'Conhecer campanhas', 'cta_secondary_url'=>'/explorar',
        'media_type'=>'default', 'media_url'=>null, 'media_filename'=>null, 'media_mime'=>null,
        'overlay_opacity'=>50, 'text_alignment'=>'left'
    ];
}
function lc_home_banner(): array {
    $defaults = lc_home_defaults();
    $value = lc_one('SELECT * FROM homepage_banners WHERE id=1');
    if (!$value || !(int)$value['enabled']) return $defaults;
    return array_replace($defaults,$value);
}
function lc_home_link(string $url): bool {
    if (preg_match('~^/(?!/)[a-zA-Z0-9/_?#=&%-]*$~D',$url)) return true;
    if (!lc_valid_https($url)) return false;
    $host = strtolower((string)parse_url($url, PHP_URL_HOST));
    return $host!=='' && !in_array($host,['localhost','127.0.0.1','::1'],true);
}
function lc_home_media_dir(): string {
    return lc_env('HOME_MEDIA_DIR','/home/leaguecut/shared/storage/home-media');
}
/** Save image/video outside the web root and publish only by opaque random token. */
function lc_home_store_media(array $upload,string $type): array {
    if (($upload['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK || !is_uploaded_file((string)($upload['tmp_name']??''))) {
        throw new RuntimeException('Não foi possível receber a mídia enviada.');
    }
    $ext = strtolower(pathinfo((string)($upload['name']??''),PATHINFO_EXTENSION));
    $mimes = ['image'=>['jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png','webp'=>'image/webp'],
              'video'=>['mp4'=>'video/mp4','webm'=>'video/webm']];
    $limit = $type==='video' ? 30*1024*1024 : 6*1024*1024;
    if(!isset($mimes[$type][$ext]))throw new RuntimeException('Formato não permitido. Imagens: JPG, PNG, WebP. Vídeos: MP4 ou WebM.');
    if((int)($upload['size']??0)<1 || (int)$upload['size']>$limit)throw new RuntimeException('Limite excedido: imagem de até 6 MB ou vídeo de até 30 MB.');
    $real = (new finfo(FILEINFO_MIME_TYPE))->file((string)$upload['tmp_name']);
    $mime = $mimes[$type][$ext];
    if($real!==$mime)throw new RuntimeException('O conteúdo do arquivo não corresponde ao tipo declarado.');
    if ($type==='image' && getimagesize((string)$upload['tmp_name'])===false)throw new RuntimeException('Imagem inválida.');
    $directory = lc_home_media_dir();
    if(!is_dir($directory)&&!mkdir($directory,0700,true)&&!is_dir($directory))throw new RuntimeException('Não foi possível criar a pasta privada de mídia.');
    chmod($directory,0700);
    $token = bin2hex(random_bytes(18));
    $file = $token . '.' . $ext;
    $path = $directory . '/' . $file;
    if(!move_uploaded_file((string)$upload['tmp_name'],$path))throw new RuntimeException('Falha ao salvar a mídia.');
    chmod($path,0640);
    return ['media_url'=>'/media/home/'.$token,'media_filename'=>$file,'media_mime'=>$mime];
}
/** Serve the currently selected local media, including HTTP byte ranges for HTML5 video. */
function lc_home_media_response(string $token): never {
    $record=lc_one('SELECT media_url,media_filename,media_mime FROM homepage_banners WHERE id=1');
    $url='/media/home/'.$token;
    $filename=(string)($record['media_filename']??'');
    $mime=(string)($record['media_mime']??'');
    if (!$record || $record['media_url']!==$url ||
        !preg_match('/^[0-9a-f]{36}\.(jpg|jpeg|png|webp|mp4|webm)$/D',$filename) ||
        !str_starts_with($filename,$token.'.') ||
        !in_array($mime,['image/jpeg','image/png','image/webp','video/mp4','video/webm'],true)) {
        http_response_code(404);exit;
    }
    $path=lc_home_media_dir().'/'.$filename;
    if (!is_file($path) || !is_readable($path)) {http_response_code(404);exit;}
    $size=filesize($path);
    if($size===false||$size<1){http_response_code(404);exit;}
    $start=0;$end=$size-1;
    $range=(string)($_SERVER['HTTP_RANGE']??'');
    if($range!==''){
        if(!preg_match('/^bytes=(\d*)-(\d*)$/D',$range,$m) || ($m[1]===''&&$m[2]==='')){
            http_response_code(416);header("Content-Range: bytes */".$size);exit;
        }
        if($m[1]===''){
            $suffix=(int)$m[2];$start=max(0,$size-$suffix);
        }else{
            $start=(int)$m[1];$end=$m[2]!==''?min($size-1,(int)$m[2]):$size-1;
        }
        if($start>$end||$start>=$size){http_response_code(416);header("Content-Range: bytes */".$size);exit;}
        http_response_code(206);
        header("Content-Range: bytes $start-$end/$size");
    }
    header('Content-Type: '.$mime);
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: public, max-age=300');
    header('Accept-Ranges: bytes');
    header('Content-Length: '.($end-$start+1));
    $stream=fopen($path,'rb');if(!$stream){http_response_code(404);exit;}
    fseek($stream,$start);$left=$end-$start+1;
    while($left>0&&!connection_aborted()&&!feof($stream)){
        $data=fread($stream,min(65536,$left));
        if($data===false||$data==='')break;
        echo $data;$left-=strlen($data);
    }
    fclose($stream);exit;
}
