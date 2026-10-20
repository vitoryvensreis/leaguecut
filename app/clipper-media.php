<?php
declare(strict_types=1);

/** Raster-only images stored in shared directories outside the document root. */
// Avatar and banner limits are intentionally separate (10 MB and 4 MB).
const LC_AVATAR_MAX_BYTES = 10 * 1024 * 1024;

function lc_clipper_image_upload_size_valid(int $size,int $limit): bool {
    return $size>=1 && $size<=$limit;
}
function lc_clipper_image_store(array $upload,string $directory,int $maxBytes=4*1024*1024): array {
    $error=(int)($upload['error']??UPLOAD_ERR_NO_FILE);
    if($error===UPLOAD_ERR_INI_SIZE || $error===UPLOAD_ERR_FORM_SIZE)
        throw new InvalidArgumentException('O servidor não aceitou o tamanho do arquivo. Confira o limite de upload configurado.');
    if($error!==UPLOAD_ERR_OK || !is_uploaded_file((string)($upload['tmp_name']??'')))
        throw new InvalidArgumentException('Selecione uma imagem válida.');
    if(!lc_clipper_image_upload_size_valid((int)($upload['size']??0),$maxBytes))
        throw new InvalidArgumentException('A imagem deve ter até '.(int)($maxBytes/1048576).' MB.');
    $tmp=(string)$upload['tmp_name'];
    $mime=(new finfo(FILEINFO_MIME_TYPE))->file($tmp);
    $supported=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'];
    if(!isset($supported[$mime]))throw new InvalidArgumentException('Use JPG, PNG ou WebP.');
    $info=@getimagesize($tmp);
    if(!$info || ($info['mime']??'')!==$mime || $info[0]<30 || $info[1]<30 || $info[0]>4096 || $info[1]>4096)
        throw new InvalidArgumentException('Imagem inválida ou resolução acima de 4096 px.');
    if(!is_dir($directory)&&!mkdir($directory,0750,true)&&!is_dir($directory))
        throw new RuntimeException('Diretório de mídia indisponível.');
    $token=bin2hex(random_bytes(18));
    $filename=$token.'.'.$supported[$mime];
    if(!move_uploaded_file($tmp,$directory.'/'.$filename))
        throw new RuntimeException('Não foi possível salvar a imagem.');
    @chmod($directory.'/'.$filename,0640);
    return ['token'=>$token,'filename'=>$filename,'mime'=>$mime];
}
function lc_avatar_dir(): string {
    return lc_env('AVATAR_MEDIA_DIR','/home/leaguecut/shared/storage/avatars');
}
function lc_clipper_banner_dir(): string {
    return lc_env('CLIPPER_BANNER_MEDIA_DIR','/home/leaguecut/shared/storage/clipper-banners');
}
function lc_avatar_src(int $userId): string {
    static $cache=[];
    if(!array_key_exists($userId,$cache)){
        $row=lc_one('SELECT token FROM user_avatars WHERE user_id=?',[$userId]);
        $cache[$userId]=$row?'/media/avatar/'.(string)$row['token']:'';
    }
    return $cache[$userId];
}
function lc_clipper_media_response(string $kind,string $token): never {
    if($kind==='avatar'){
        $row=lc_one('SELECT filename,mime FROM user_avatars WHERE token=?',[$token]);
        $directory=lc_avatar_dir();
    }else{
        $row=lc_one('SELECT image_filename AS filename,image_mime AS mime FROM clipper_banners WHERE image_token=?',[$token]);
        $directory=lc_clipper_banner_dir();
    }
    if(!$row){http_response_code(404);exit('Imagem indisponível.');}
    $file=(string)($row['filename']??'');
    $mime=(string)($row['mime']??'');
    if(!preg_match('/^[a-f0-9]{36}\\.(?:jpg|png|webp)$/D',$file)||!str_starts_with($file,$token.'.')||
       !in_array($mime,['image/jpeg','image/png','image/webp'],true)){
        http_response_code(404);exit('Imagem indisponível.');
    }
    $path=$directory.'/'.$file;
    if(!is_file($path)){http_response_code(404);exit('Imagem indisponível.');}
    header('Content-Type: '.$mime);
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: public, max-age=3600');
    header('Content-Length: '.filesize($path));
    readfile($path);
    exit;
}
function lc_avatar_routes(string $method,string $path): bool {
    if($path!=='/conta/foto'||$method!=='POST')return false;
    $u=lc_login_required();
    $existing=lc_one('SELECT filename FROM user_avatars WHERE user_id=?',[(int)$u['id']]);
    $stored=null;
    try{
        $stored=lc_clipper_image_store($_FILES['avatar']??[],lc_avatar_dir(),LC_AVATAR_MAX_BYTES);
        lc_query('INSERT INTO user_avatars(user_id,token,filename,mime) VALUES(?,?,?,?)
            ON DUPLICATE KEY UPDATE token=VALUES(token),filename=VALUES(filename),mime=VALUES(mime)',
            [(int)$u['id'],$stored['token'],$stored['filename'],$stored['mime']]);
        if($existing && preg_match('/^[a-f0-9]{36}\\.(?:jpg|png|webp)$/D',(string)$existing['filename']))
            @unlink(lc_avatar_dir().'/'.$existing['filename']);
        lc_flash('Sua foto de perfil foi atualizada.');
    }catch(Throwable $e){
        if($stored)@unlink(lc_avatar_dir().'/'.$stored['filename']);
        lc_flash($e instanceof InvalidArgumentException?$e->getMessage():'Não foi possível atualizar sua foto.','error');
    }
    lc_redirect('/conta');
}
