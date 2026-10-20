<?php
declare(strict_types=1);

/** Campaign covers survive deployments in the private shared storage directory. */
function lc_campaign_cover_dir(): string {
    return lc_env('CAMPAIGN_MEDIA_DIR','/home/leaguecut/shared/storage/campaign-covers');
}
function lc_campaign_cover_is_local(string $url): bool {
    return (bool)preg_match('~^/media/campaign/([a-f0-9]{36})$~D',$url);
}
function lc_campaign_cover_valid(string $url): bool {
    return $url!=='' && (lc_campaign_cover_is_local($url) || lc_valid_https($url));
}
/** Stores only raster images (no SVG/scripts); public path holds an opaque token. */
function lc_campaign_cover_store(array $upload): string {
    if(($upload['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK ||
       !is_uploaded_file((string)($upload['tmp_name']??'')))
       throw new InvalidArgumentException('Não foi possível receber a capa. Confira o limite do upload.');
    $size=(int)($upload['size']??0);
    if($size<1||$size>6*1024*1024)
        throw new InvalidArgumentException('A capa deve ter no máximo 6 MB.');
    $tmp=(string)$upload['tmp_name'];
    $mime=(new finfo(FILEINFO_MIME_TYPE))->file($tmp);
    $types=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'];
    $info=@getimagesize($tmp);
    if(!is_string($mime)||!isset($types[$mime])||!is_array($info)||
       ($info['mime']??'')!==$mime||$info[0]<320||$info[1]<180||
       $info[0]>6000||$info[1]>6000)
        throw new InvalidArgumentException('Imagem inválida. Envie JPG, PNG ou WebP entre 320×180 e 6000×6000 px.');
    $dir=lc_campaign_cover_dir();
    if(!is_dir($dir)&&!mkdir($dir,0700,true)&&!is_dir($dir))
        throw new RuntimeException('Não foi possível criar o armazenamento privado da capa.');
    chmod($dir,0700);
    $token=bin2hex(random_bytes(18));
    $target=$dir.'/'.$token.'.'.$types[$mime];
    if(!move_uploaded_file($tmp,$target))throw new RuntimeException('Não foi possível salvar a imagem da campanha.');
    chmod($target,0600);
    return '/media/campaign/'.$token;
}
/** Cleanup only a local token (never a user-supplied URL or arbitrary path). */
function lc_campaign_cover_discard(string $url): void {
    if(!lc_campaign_cover_is_local($url))return;
    $token=substr($url,strlen('/media/campaign/'));
    foreach(['jpg','png','webp'] as $ext){
        $file=lc_campaign_cover_dir().'/'.$token.'.'.$ext;
        if(is_file($file)&&!is_link($file))@unlink($file);
    }
}
function lc_campaign_cover_response(string $token): never {
    $uri='/media/campaign/'.$token;
    // Only a cover referenced in the DB is publicly visible, never an
    // arbitrary private file with a guessed name.
    if(!lc_campaign_cover_is_local($uri) ||
       !lc_one('SELECT id FROM campaigns WHERE cover_url=? LIMIT 1',[$uri])){
        http_response_code(404);exit;
    }
    foreach(['jpg'=>'image/jpeg','png'=>'image/png','webp'=>'image/webp'] as $ext=>$mime){
        $file=lc_campaign_cover_dir().'/'.$token.'.'.$ext;
        if(!is_file($file)||is_link($file)||!is_readable($file))continue;
        $size=filesize($file);
        if($size===false||$size<1||$size>6*1024*1024)break;
        header('Content-Type: '.$mime);
        header('X-Content-Type-Options: nosniff');
        header("Content-Security-Policy: default-src 'none'; sandbox");
        header('Cache-Control: public, max-age=86400, immutable');
        header('Content-Length: '.$size);
        readfile($file);
        exit;
    }
    http_response_code(404);
    exit;
}
