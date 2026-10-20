<?php
declare(strict_types=1);

function lc_clipper_banner_image_src(array $banner): string {
    $token=(string)($banner['image_token']??'');
    return preg_match('/^[a-f0-9]{36}$/D',$token)?'/media/clipper-banner/'.$token:'';
}
function lc_clipper_banners_active(int $limit=4): array {
    $limit=max(1,min(12,$limit));
    return lc_all('SELECT * FROM clipper_banners WHERE is_active=1 ORDER BY sort_order ASC,id DESC LIMIT '.(int)$limit);
}
/** Preview is deliberately an inert mock card: no campaign stats or publication state are invented. */
function lc_clipper_banner_preview(array $banner=[]): void {
    $src=lc_clipper_banner_image_src($banner);
    $title=(string)($banner['title']??'Novo banner informativo');
    $description=(string)($banner['description']??'Confira as novidades e campanhas na LeagueCut.');
    $cta=(string)($banner['cta_label']??'Saiba mais');
    ?>
    <div class="lc-banner-preview" data-banner-preview data-banner-view="desktop">
      <div class="lc-banner-preview-head"><span>Pré-visualização do banner <small>• Não publicado</small></span>
        <div class="lc-banner-preview-actions" role="group" aria-label="Visualizar no dispositivo">
          <button type="button" data-banner-mode="desktop" aria-pressed="true">Desktop</button>
          <button type="button" data-banner-mode="mobile" aria-pressed="false">Mobile</button>
        </div>
      </div>
      <div class="lc-banner-stage">
        <img data-banner-image src="<?=lc_e($src)?>" alt="Imagem de fundo do banner" <?=!$src?'hidden':''?> loading="lazy">
        <div class="lc-banner-stage-copy">
          <small>BANNER INFORMATIVO</small>
          <h3 data-banner-title><?=lc_e($title)?></h3>
          <p data-banner-description><?=lc_e($description)?></p>
          <span class="lc-banner-cta" data-banner-cta><?=lc_e($cta)?></span>
        </div>
      </div>
      <p class="lc-editor-preview-caption">Simulação do card de destaque. No painel do clipador, as campanhas ativas têm prioridade e os banners ocupam os espaços restantes.</p>
    </div>
    <?php
}

/** Admin controls text, CTA, background artwork and ordering of spare dashboard hero slots. */
function lc_clipper_banner_routes(string $method,string $path): bool {
    if($path!=='/admin/banners-clipador')return false;
    $admin=lc_admin();
    if($method==='POST'){
        $id=lc_int('banner_id',0,2147483647);
        $stored=null;
        $old=null;
        try{
            $old=$id>0?lc_one('SELECT * FROM clipper_banners WHERE id=?',[$id]):null;
            if($id>0&&!$old)throw new InvalidArgumentException('Banner não encontrado.');
            if(lc_bool('delete_banner')){
                if(!$old)throw new InvalidArgumentException('Banner inexistente.');
                lc_query('DELETE FROM clipper_banners WHERE id=?',[$id]);
                if(!empty($old['image_filename'])&&preg_match('/^[a-f0-9]{36}\\.(?:jpg|png|webp)$/D',(string)$old['image_filename']))
                    @unlink(lc_clipper_banner_dir().'/'.$old['image_filename']);
                lc_flash('Banner removido.');
                lc_redirect('/admin/banners-clipador');
            }
            $title=lc_post('title',160);
            $description=lc_post('description',500);
            $ctaLabel=lc_post('cta_label',70);
            $ctaUrl=lc_post('cta_url',700);
            $sort=lc_int('sort_order',0,10000);
            $active=lc_bool('is_active')?1:0;
            if(mb_strlen($title)<3||mb_strlen($ctaLabel)<2||!lc_home_link($ctaUrl))
                throw new InvalidArgumentException('Preencha título, botão e um endereço interno ou HTTPS válido.');
            if(isset($_FILES['image'])&&($_FILES['image']['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_NO_FILE)
                $stored=lc_clipper_image_store($_FILES['image'],lc_clipper_banner_dir());
            $token=$stored['token']??($old['image_token']??null);
            $filename=$stored['filename']??($old['image_filename']??null);
            $mime=$stored['mime']??($old['image_mime']??null);
            if($id>0){
                lc_query('UPDATE clipper_banners SET title=?,description=?,cta_label=?,cta_url=?,sort_order=?,is_active=?,image_token=?,image_filename=?,image_mime=? WHERE id=?',
                    [$title,$description,$ctaLabel,$ctaUrl,$sort,$active,$token,$filename,$mime,$id]);
            }else{
                lc_query('INSERT INTO clipper_banners(title,description,cta_label,cta_url,sort_order,is_active,image_token,image_filename,image_mime) VALUES(?,?,?,?,?,?,?,?,?)',
                    [$title,$description,$ctaLabel,$ctaUrl,$sort,$active,$token,$filename,$mime]);
            }
            if($stored&&$old&&!empty($old['image_filename'])&&preg_match('/^[a-f0-9]{36}\\.(?:jpg|png|webp)$/D',(string)$old['image_filename']))
                @unlink(lc_clipper_banner_dir().'/'.$old['image_filename']);
            lc_flash('Banner informativo salvo.');
        }catch(Throwable $e){
            if($stored)@unlink(lc_clipper_banner_dir().'/'.$stored['filename']);
            lc_flash($e instanceof InvalidArgumentException?$e->getMessage():'Não foi possível salvar o banner.','error');
        }
        lc_redirect('/admin/banners-clipador');
    }
    if($method==='GET'){
        $banners=lc_all('SELECT * FROM clipper_banners ORDER BY sort_order ASC,id DESC LIMIT 80');
        lc_page('Banners do clipador',function()use($banners){?>
        <div class="container container-md lc-banner-admin">
          <?php lc_heading('Personalização','Banners do clipador','Configure imagens, texto e botão com uma prévia desktop ou mobile antes de salvar.'); ?>
          <p class="hint">Até quatro cartões aparecem no topo do painel, priorizando as campanhas. Os banners informativos preenchem os espaços restantes. Use imagens horizontais ou verticais de alta resolução.</p>
          <?php foreach($banners as $b):?>
          <div class="panel lc-banner-admin-card">
            <div class="lc-banner-admin-head"><h2><?=lc_e($b['title'])?></h2><span class="tag"><?=(int)$b['is_active']?'ATIVO':'OCULTO'?></span></div>

            <form action="/admin/banners-clipador" method="post" enctype="multipart/form-data"><?=lc_csrf()?>
              <input type="hidden" name="banner_id" value="<?=(int)$b['id']?>">
              <?php lc_clipper_banner_preview($b); ?>
              <div class="form-grid">
                <label class="field full"><span>Título</span><input name="title" required maxlength="160" value="<?=lc_e($b['title'])?>"></label>
                <label class="field full"><span>Texto de apoio</span><textarea name="description" maxlength="500"><?=lc_e($b['description'])?></textarea></label>
                <label class="field"><span>Texto do botão</span><input name="cta_label" required maxlength="70" value="<?=lc_e($b['cta_label'])?>"></label>
                <label class="field"><span>Link do botão (HTTPS ou /rota)</span><input name="cta_url" required maxlength="700" value="<?=lc_e($b['cta_url'])?>"></label>
                <label class="field"><span>Ordem de exibição</span><input type="number" name="sort_order" min="0" max="10000" value="<?=(int)$b['sort_order']?>"></label>
                <label class="field"><span>Imagem de fundo (troca opcional)</span><input type="file" name="image" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp"></label>
                <label class="field full"><span><input type="checkbox" name="is_active" value="1" <?=(int)$b['is_active']?'checked':''?>> Banner ativo</span></label>
              </div>
              <div class="form-actions"><button class="btn" type="submit">Salvar banner</button><button class="btn btn-ghost" name="delete_banner" value="1" formnovalidate onclick="return confirm('Excluir este banner definitivamente?')" type="submit">Excluir</button></div>
            </form>
          </div>
          <?php endforeach;?>
          <div class="panel lc-banner-admin-card"><h2>Adicionar banner informativo</h2>
          <form action="/admin/banners-clipador" method="post" enctype="multipart/form-data"><?=lc_csrf()?>
          <?php lc_clipper_banner_preview(); ?>
          <div class="form-grid">
            <label class="field full"><span>Título</span><input name="title" required maxlength="160" placeholder="Nova oportunidade chegando"></label>
            <label class="field full"><span>Texto</span><textarea name="description" maxlength="500" placeholder="Conte o que seu clipador precisa saber."></textarea></label>
            <label class="field"><span>Texto do botão</span><input name="cta_label" required maxlength="70" value="Saiba mais"></label>
            <label class="field"><span>Link do botão</span><input name="cta_url" required maxlength="700" value="/explorar"></label>
            <label class="field"><span>Ordem</span><input type="number" name="sort_order" min="0" max="10000" value="0"></label>
            <label class="field"><span>Imagem de fundo</span><input type="file" name="image" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp"></label>
            <label class="field full"><span><input type="checkbox" name="is_active" value="1" checked> Banner ativo</span></label>
          </div>
          <button class="btn" type="submit">Criar banner</button></form></div>
        </div>
        <?php },'admin');return true;
    }
    return false;
}
