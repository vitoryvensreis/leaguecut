<?php
declare(strict_types=1);

/** Home banner management is restricted to logged-in administrators. */
function lc_home_admin_routes(string $method,string $path): bool {
    if($path!=='/admin/home')return false;
    $admin=lc_admin();
    if($method==='POST'){
        $enabled=lc_bool('enabled')?1:0;
        $fields=[
            'eyebrow'=>lc_post('eyebrow',160),
            'title_top'=>lc_post('title_top',160),
            'title_accent'=>lc_post('title_accent',160),
            'title_bottom'=>lc_post('title_bottom',160),
            'subtitle'=>lc_post('subtitle',900),
            'cta_primary_text'=>lc_post('cta_primary_text',80),
            'cta_primary_url'=>lc_post('cta_primary_url',600),
            'cta_secondary_text'=>lc_post('cta_secondary_text',80),
            'cta_secondary_url'=>lc_post('cta_secondary_url',600)
        ];
        $type=lc_post('media_type',12);
        $mediaUrl=lc_post('media_url',1000);
        $alignment=lc_post('text_alignment',12);
        $overlay=lc_int('overlay_opacity',50,85);
        foreach(['eyebrow','title_top','title_accent','title_bottom','subtitle','cta_primary_text','cta_secondary_text'] as $key){
            if(mb_strlen($fields[$key])<2){lc_flash('Preencha os títulos, a descrição e os textos dos botões.','error');lc_redirect('/admin/home');}
        }
        if(!lc_home_link($fields['cta_primary_url'])||!lc_home_link($fields['cta_secondary_url'])){
            lc_flash('Links dos botões devem ser caminhos internos (/cadastrar) ou URLs HTTPS válidas.','error');
            lc_redirect('/admin/home');
        }
        if(!in_array($type,['default','image','video'],true)||!in_array($alignment,['left','center'],true)){
            lc_flash('Escolha um tipo de mídia e alinhamento válidos.','error');lc_redirect('/admin/home');
        }
        $previous=lc_one('SELECT * FROM homepage_banners WHERE id=1');
        if(!$previous){lc_flash('A migração do banner ainda não foi aplicada.','error');lc_redirect('/admin/home');}
        $filename=null;$mime=null;
        if($type==='default'){
            $mediaUrl='';
        }else{
            $upload=$_FILES['media_upload']??null;
            if($upload && ($upload['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_NO_FILE){
                try{
                    $stored=lc_home_store_media($upload,$type);
                    $mediaUrl=$stored['media_url'];$filename=$stored['media_filename'];$mime=$stored['media_mime'];
                }catch(Throwable $e){lc_flash($e->getMessage(),'error');lc_redirect('/admin/home');}
            }elseif($mediaUrl!=='' && $mediaUrl===$previous['media_url'] &&
                    $type===$previous['media_type'] && $previous['media_filename']){
                $filename=$previous['media_filename'];$mime=$previous['media_mime'];
            }elseif(!lc_valid_https($mediaUrl) || !lc_home_link($mediaUrl)){
                lc_flash('Para imagem/vídeo, envie um arquivo ou informe uma URL HTTPS de mídia.','error');lc_redirect('/admin/home');
            }
        }
        lc_query('UPDATE homepage_banners SET enabled=?,eyebrow=?,title_top=?,title_accent=?,title_bottom=?,subtitle=?,
            cta_primary_text=?,cta_primary_url=?,cta_secondary_text=?,cta_secondary_url=?,media_type=?,media_url=?,
            media_filename=?,media_mime=?,overlay_opacity=?,text_alignment=?,updated_by=? WHERE id=1',[
            $enabled,$fields['eyebrow'],$fields['title_top'],$fields['title_accent'],$fields['title_bottom'],
            $fields['subtitle'],$fields['cta_primary_text'],$fields['cta_primary_url'],
            $fields['cta_secondary_text'],$fields['cta_secondary_url'],$type,$mediaUrl?:null,
            $filename,$mime,$overlay,$alignment,(int)$admin['id']
        ]);
        lc_flash('Banner da página inicial atualizado. Confira o resultado no site público.');
        lc_redirect('/admin/home');
    }
    if($method==='GET'){
        $banner=lc_one('SELECT * FROM homepage_banners WHERE id=1')??lc_home_defaults();
        lc_page('Banner da Home',function()use($banner){?>
<div class="container container-md">
 <?php lc_heading('Marketing e conteúdo','Banner da página inicial','Edite o hero da landing page, textos, botões, mídia e aparência sem alterar código.'); ?>
 <div class="panel home-admin-panel">
  <div class="panel-head"><h2>Personalização do banner</h2><a class="btn btn-ghost btn-sm" href="/" target="_blank" rel="noopener noreferrer">Ver página inicial <?=lc_icon('external-link')?></a></div>
  <div class="alert">Somente o banner principal da Home é alterado aqui. Vídeos precisam estar otimizados para web. O visual animado original é o fallback quando não houver mídia ativa.</div>
  <form action="/admin/home" method="post" enctype="multipart/form-data" class="home-banner-form"><?=lc_csrf()?>
   <div class="home-banner-switch"><label><input type="checkbox" name="enabled" value="1" <?=(int)$banner['enabled']?'checked':''?>> <strong>Ativar banner personalizado</strong></label><small>Desmarcado: a home utiliza os textos e a animação padrão do LeagueCut.</small></div>
   <h3 class="home-form-heading">Conteúdo principal</h3>
   <div class="form-grid">
    <?php lc_field('Selo superior','eyebrow','text',(string)$banner['eyebrow'],true,'Ex.: Criadores no centro de tudo','maxlength="160"'); ?>
    <?php lc_field('Título — linha 1','title_top','text',(string)$banner['title_top'],true,'Seu talento.','maxlength="160"'); ?>
    <?php lc_field('Título em destaque','title_accent','text',(string)$banner['title_accent'],true,'Mais alcance.','maxlength="160"'); ?>
    <?php lc_field('Título — linha 3','title_bottom','text',(string)$banner['title_bottom'],true,'Novas conquistas.','maxlength="160"'); ?>
    <label class="field full"><span>Descrição abaixo do título</span><textarea name="subtitle" maxlength="900" required><?=lc_e($banner['subtitle'])?></textarea></label>
   </div>
   <h3 class="home-form-heading">Botões e links</h3>
   <div class="form-grid">
    <?php lc_field('Botão principal — texto','cta_primary_text','text',(string)$banner['cta_primary_text'],true,'Quero ser clipador','maxlength="80"'); ?>
    <?php lc_field('Botão principal — link','cta_primary_url','text',(string)$banner['cta_primary_url'],true,'/cadastrar','maxlength="600"'); ?>
    <?php lc_field('Botão secundário — texto','cta_secondary_text','text',(string)$banner['cta_secondary_text'],true,'Conhecer campanhas','maxlength="80"'); ?>
    <?php lc_field('Botão secundário — link','cta_secondary_url','text',(string)$banner['cta_secondary_url'],true,'/explorar','maxlength="600"'); ?>
   </div>
   <h3 class="home-form-heading">Fundo e animação</h3>
   <div class="form-grid">
    <label class="field"><span>Tipo de mídia</span><select name="media_type" id="home-media-type">
     <option value="default" <?=$banner['media_type']==='default'?'selected':''?>>Animação original (CSS)</option>
     <option value="image" <?=$banner['media_type']==='image'?'selected':''?>>Imagem (JPG/PNG/WebP)</option>
     <option value="video" <?=$banner['media_type']==='video'?'selected':''?>>Vídeo (MP4/WebM)</option>
    </select></label>
    <label class="field"><span>Alinhamento do texto</span><select name="text_alignment">
      <option value="left" <?=$banner['text_alignment']==='left'?'selected':''?>>Esquerda</option>
      <option value="center" <?=$banner['text_alignment']==='center'?'selected':''?>>Centralizado</option>
    </select></label>
    <?php lc_field('Link externo da mídia (HTTPS) ou link atual','media_url','text',(string)($banner['media_url']??''),false,'https://cdn.exemplo.com/banner.mp4','maxlength="1000"'); ?>
    <label class="field"><span>Arquivo de mídia (opcional, substitui o link)</span><input name="media_upload" type="file" id="home-media-upload" accept=".png,.jpg,.jpeg,.webp,.mp4,.webm,image/png,image/jpeg,image/webp,video/mp4,video/webm"><small>Imagem: até 6 MB. Vídeo: até 30 MB, sujeito aos limites do PHP/Nginx. Os arquivos ficam fora da pasta pública.</small></label>
    <label class="field full"><span>Opacidade do overlay escuro: <output id="home-overlay-value"><?=(int)$banner['overlay_opacity']?>%</output></span><input type="range" name="overlay_opacity" id="home-overlay" min="0" max="85" value="<?=(int)$banner['overlay_opacity']?>" step="5"></label>
   </div>
   <p class="hint">O vídeo é reproduzido sem áudio, em loop, com fallback para usuários que preferem movimento reduzido. Para arquivos muito grandes, prefira um link HTTPS de CDN.</p>
   <div class="form-actions"><button type="submit" class="btn">Salvar banner <?=lc_icon('check')?></button><a class="btn btn-ghost" href="/">Visualizar a Home <?=lc_icon('external-link')?></a></div>
  </form>
 </div>
 <?php if($banner['media_type']!=='default'&&$banner['media_url']): ?>
  <div class="panel"><h2>Mídia configurada</h2>
  <?php if($banner['media_type']==='image'):?><img class="home-admin-preview" src="<?=lc_e($banner['media_url'])?>" alt="Prévia do banner" loading="lazy">
  <?php else:?><video class="home-admin-preview" controls muted playsinline preload="metadata" src="<?=lc_e($banner['media_url'])?>"></video><?php endif;?></div>
 <?php endif;?>
</div>
<script src="/assets/home-admin.js" defer></script>
<?php },'admin');return true;
    }
    return false;
}
