<?php
declare(strict_types=1);

function lc_clipper_image_dir(string $kind): string {
    if(!in_array($kind,['avatar','banner'],true))throw new InvalidArgumentException('Tipo de arquivo inválido.');
    return lc_env('CLIPPER_MEDIA_DIR','/home/leaguecut/shared/storage/clipper-media').'/'.$kind;
}
function lc_clipper_image_store(array $upload,string $kind): string {
    if(!in_array($kind,['avatar','banner'],true))throw new InvalidArgumentException('Imagem não autorizada.');
    if(($upload['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK||
       !is_uploaded_file((string)($upload['tmp_name']??'')))
        throw new InvalidArgumentException('A imagem não foi enviada.');
    $size=(int)($upload['size']??0);
    if($size<1||$size>5*1024*1024)throw new InvalidArgumentException('A imagem deve ter até 5 MB.');
    $path=(string)$upload['tmp_name'];
    $mime=(new finfo(FILEINFO_MIME_TYPE))->file($path);
    $ext=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'];
    $info=@getimagesize($path);
    $min=$kind==='avatar'?96:320;
    if(!is_array($info)||!isset($ext[$mime])||($info['mime']??'')!==$mime||
       $info[0]<$min||$info[1]<($kind==='banner'?180:96)||
       $info[0]>6000||$info[1]>6000)
        throw new InvalidArgumentException('Imagem inválida. Use JPG, PNG ou WebP em boa resolução.');
    $dir=lc_clipper_image_dir($kind);
    if(!is_dir($dir)&&!mkdir($dir,0700,true)&&!is_dir($dir))
       throw new RuntimeException('Armazenamento de imagem indisponível.');
    chmod($dir,0700);
    $token=bin2hex(random_bytes(18));$dest=$dir.'/'.$token.'.'.$ext[$mime];
    if(!move_uploaded_file($path,$dest))throw new RuntimeException('Falha ao salvar imagem.');
    chmod($dest,0600);
    return '/media/clipper/'.$kind.'/'.$token;
}
function lc_clipper_image_discard(string $uri,string $kind): void {
    if(!preg_match('~^/media/clipper/'.preg_quote($kind,'~').'/([a-f0-9]{36})$~D',$uri,$m))return;
    foreach(['jpg','png','webp'] as $ext){
        $file=lc_clipper_image_dir($kind).'/'.$m[1].'.'.$ext;
        if(is_file($file)&&!is_link($file))@unlink($file);
    }
}
function lc_clipper_image_response(string $kind,string $token): never {
    $uri='/media/clipper/'.$kind.'/'.$token;
    if(!in_array($kind,['avatar','banner'],true) ||
      !lc_one($kind==='avatar'
         ?'SELECT id FROM users WHERE profile_photo_url=? LIMIT 1'
         :'SELECT id FROM clipper_info_banners WHERE image_url=? LIMIT 1',[$uri])){
         http_response_code(404);exit;
    }
    foreach(['jpg'=>'image/jpeg','png'=>'image/png','webp'=>'image/webp'] as $ext=>$mime){
        $file=lc_clipper_image_dir($kind).'/'.$token.'.'.$ext;
        if(!is_file($file)||is_link($file)||!is_readable($file))continue;
        $bytes=filesize($file);
        if($bytes===false||$bytes<1||$bytes>5*1024*1024)break;
        header('Content-Type: '.$mime);
        header('X-Content-Type-Options: nosniff');
        header("Content-Security-Policy: default-src 'none'; sandbox");
        header('Cache-Control: public,max-age=86400,immutable');
        header('Content-Length: '.$bytes);
        readfile($file);exit;
    }
    http_response_code(404);exit;
}
function lc_profile_photo_routes(string $method,string $path): bool {
    if($path!=='/conta/foto')return false;
    $user=lc_login_required(false);
    if($method==='POST'){
        $file=$_FILES['photo']??[];
        try{
            $url=lc_clipper_image_store($file,'avatar');
            try{
                $old=lc_one('SELECT profile_photo_url FROM users WHERE id=?',[(int)$user['id']]);
                lc_query('UPDATE users SET profile_photo_url=? WHERE id=?',[$url,(int)$user['id']]);
            }catch(Throwable $e){lc_clipper_image_discard($url,'avatar');throw $e;}
            if(!empty($old['profile_photo_url']))lc_clipper_image_discard((string)$old['profile_photo_url'],'avatar');
            lc_flash('Foto de perfil atualizada.');
        }catch(Throwable $e){
            lc_flash($e instanceof InvalidArgumentException?$e->getMessage():'Não foi possível salvar a foto.','error');
        }
        lc_redirect('/conta');
    }
    lc_redirect('/conta');
}
function lc_clipper_banner_admin_routes(string $method,string $path): bool {
    if($path!=='/admin/banners-clipador' && !preg_match('~^/admin/banners-clipador/(?:[0-9]+/(?:salvar|excluir))$~D',$path))return false;
    $admin=lc_admin();
    if(!lc_admin_can_url($admin,'/admin/banners-clipador')){http_response_code(403);exit('Acesso negado.');}
    if($method==='POST'){
        $return='/admin/banners-clipador';
        try{
            if(preg_match('~^/admin/banners-clipador/([0-9]+)/excluir$~D',$path,$m)){
                $id=(int)$m[1];$old=lc_one('SELECT image_url FROM clipper_info_banners WHERE id=?',[$id]);
                if($old){
                    lc_query('DELETE FROM clipper_info_banners WHERE id=?',[$id]);
                    lc_clipper_image_discard((string)($old['image_url']??''),'banner');
                }
                lc_flash('Banner excluído.');lc_redirect($return);
            }
            $id=0;
            if(preg_match('~^/admin/banners-clipador/([0-9]+)/salvar$~D',$path,$m))$id=(int)$m[1];
            elseif($path!=='/admin/banners-clipador')throw new InvalidArgumentException('Rota inválida.');
            $old=$id?lc_one('SELECT image_url FROM clipper_info_banners WHERE id=?',[$id]):null;
            if($id&&!$old)throw new InvalidArgumentException('Banner inexistente.');
            $title=lc_post('title',160);$text=lc_post('description',500);
            $button=lc_post('button_text',70)?:'Saiba mais';$url=lc_post('button_url',650)?:'/explorar';
            $priority=lc_int('sort_order',50,999);
            if(mb_strlen($title)<3||!lc_home_link($url)||mb_strlen($button)<2)
                throw new InvalidArgumentException('Revise título, botão e link.');
            $image=(string)($old['image_url']??'');$uploaded='';
            $file=$_FILES['banner_image']??null;
            if($file&&($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_NO_FILE){
                $uploaded=lc_clipper_image_store($file,'banner');$image=$uploaded;
            }
            try{
                if($id){
                    lc_query('UPDATE clipper_info_banners SET title=?,description=?,button_text=?,button_url=?,image_url=?,is_active=?,sort_order=? WHERE id=?',
                        [$title,$text,$button,$url,$image?:null,lc_bool('is_active')?1:0,$priority,$id]);
                }else{
                    lc_query('INSERT INTO clipper_info_banners(title,description,button_text,button_url,image_url,is_active,sort_order) VALUES(?,?,?,?,?,?,?)',
                        [$title,$text,$button,$url,$image?:null,lc_bool('is_active')?1:0,$priority]);
                }
            }catch(Throwable $e){if($uploaded!=='')lc_clipper_image_discard($uploaded,'banner');throw $e;}
            if($uploaded!==''&&!empty($old['image_url']))
                lc_clipper_image_discard((string)$old['image_url'],'banner');
            lc_flash('Banner informativo salvo.');
        }catch(Throwable $e){
            lc_flash($e instanceof InvalidArgumentException?$e->getMessage():'Não foi possível salvar o banner.','error');
        }
        lc_redirect($return);
    }
    $rows=lc_all('SELECT * FROM clipper_info_banners ORDER BY sort_order ASC,id DESC LIMIT 100');
    lc_page('Banners do clipador',function()use($rows){?>
<div class="container container-md">
 <?php lc_heading('Conteúdo do clipador','Banners informativos','Complete as quatro posições do painel quando houver poucas campanhas ativas.'); ?>
 <div class="panel"><h2>Novo banner</h2><?php lc_clipper_banner_fields(null);?></div>
 <?php foreach($rows as $banner):?><div class="panel">
  <h2><?=lc_e($banner['title'])?></h2>
  <?php if($banner['image_url']):?><img style="max-width:100%;width:360px;aspect-ratio:16/9;object-fit:cover" src="<?=lc_e($banner['image_url'])?>" alt="Prévia do banner"><?php endif;?>
  <?php lc_clipper_banner_fields($banner);?>
  <form method="post" action="/admin/banners-clipador/<?=(int)$banner['id']?>/excluir" onsubmit="return confirm('Excluir este banner informativo?')"><?=lc_csrf()?>
  <button type="submit" class="btn btn-ghost btn-sm">Excluir banner</button></form>
 </div><?php endforeach;?>
</div>
<?php },'admin');return true;
}
function lc_clipper_banner_fields(?array $c): void {
    $id=$c?(int)$c['id']:0;$value=fn(string $key,string $default='')=>lc_e((string)($c[$key]??$default));?>
<form method="post" enctype="multipart/form-data" action="<?=$id?'/admin/banners-clipador/'.$id.'/salvar':'/admin/banners-clipador'?>">
 <?=lc_csrf()?><div class="form-grid">
 <label class="field"><span>Título</span><input name="title" maxlength="160" value="<?=$value('title')?>" required></label>
 <label class="field"><span>Ordem</span><input type="number" name="sort_order" min="0" max="999" value="<?=$value('sort_order','50')?>"></label>
 <label class="field full"><span>Descrição</span><textarea name="description" maxlength="500"><?=$value('description')?></textarea></label>
 <label class="field"><span>Texto do botão</span><input name="button_text" maxlength="70" value="<?=$value('button_text','Saiba mais')?>" required></label>
 <label class="field"><span>Link de ação</span><input name="button_url" maxlength="650" value="<?=$value('button_url','/explorar')?>" required></label>
 <label class="field full"><span>Imagem de fundo (JPG, PNG ou WebP, até 5 MB)</span><input type="file" name="banner_image" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp"></label>
 <label class="field full"><span><input type="checkbox" name="is_active" value="1" <?=$c?((int)$c['is_active']?'checked':''):'checked'?>> Ativo</span></label>
 </div><div class="form-actions"><button class="btn" type="submit"><?=$id?'Salvar banner':'Adicionar banner'?></button></div>
</form>
<?php
}
