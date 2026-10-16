<?php
declare(strict_types=1);

function lc_seo_admin_routes(string $method,string $path): bool {
    if($path!=='/admin/seo')return false;
    $admin=lc_admin();
    if($method==='POST'){
        if(!lc_account_admin_password($admin)){
            lc_flash('Confirme a senha do administrador e o 2FA, quando ativo.','error');
            lc_redirect('/admin/seo');
        }
        $old=lc_seo_config(lc_settings());
        try{
            $max=[
                'seo_home_title'=>120,'seo_home_description'=>200,
                'seo_default_description'=>200,'seo_explore_title'=>120,
                'seo_ranking_title'=>120,'seo_plans_title'=>120,'seo_keywords'=>255
            ];
            $next=[];
            foreach($max as $key=>$maxChars){
                $value=lc_seo_text((string)($_POST[$key]??''),$maxChars);
                if(($key==='seo_home_title'||str_ends_with($key,'description'))&&mb_strlen($value)<10)
                    throw new InvalidArgumentException('Preencha o título principal e as descrições com pelo menos 10 caracteres.');
                $next[$key]=$value;
            }
            $base=rtrim(trim((string)($_POST['seo_canonical_base']??'')),'/');
            if($base!==lc_seo_origin())
                throw new InvalidArgumentException('A URL canônica deve usar https://leaguecut.com sem caminhos extras.');
            $next['seo_canonical_base']=$base;
            $handle=trim((string)($_POST['seo_twitter_handle']??''));
            if($handle!==''&&!preg_match('/^@?[a-zA-Z0-9_]{1,15}$/D',$handle))
                throw new InvalidArgumentException('O usuário X/Twitter deve ter até 15 caracteres, sem links.');
            $next['seo_twitter_handle']=$handle!==''?'@'.ltrim($handle,'@'):'';
            $next['seo_index_enabled']=isset($_POST['seo_index_enabled'])?'1':'0';
            foreach(lc_social_footer_specs() as $key=>$spec){
                $url=trim((string)($_POST[$key]??''));
                if(!lc_social_footer_valid($key,$url))
                    throw new InvalidArgumentException('Confira o link HTTPS do '.$spec['label'].'. É necessário usar a rede social correspondente.');
                $next[$key]=$url;
            }
            foreach(['seo_favicon_url'=>'icon','seo_social_image_url'=>'social'] as $key=>$kind){
                $url=trim((string)($_POST[$key]??$old[$key]??''));
                if(!lc_seo_valid_asset($url,$kind))
                    throw new InvalidArgumentException('Imagem SEO inválida: use uma URL HTTPS sem credenciais ou um upload.');
                $next[$key]=$url;
            }
            foreach(['seo_favicon_upload'=>['seo_favicon_url','icon'],
                     'seo_social_image_upload'=>['seo_social_image_url','social']] as $uploadKey=>[$key,$kind]){
                $upload=$_FILES[$uploadKey]??null;
                if(!$upload||($upload['error']??UPLOAD_ERR_NO_FILE)===UPLOAD_ERR_NO_FILE)continue;
                $next[$key]=lc_seo_store_image($upload,$kind);
            }
            $db=lc_db();$db->beginTransaction();
            foreach($next as $key=>$value){
                lc_query("INSERT INTO platform_settings(setting_key,setting_value) VALUES(?,?)
                    ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)",[$key,$value]);
            }
            lc_account_admin_audit((int)$admin['id'],'seo.settings.updated',null,
               ['fields'=>array_keys($next)]);
            $db->commit();
            lc_flash('SEO atualizado. Verifique a prévia e os links de compartilhamento.');
        }catch(InvalidArgumentException $e){
            if(lc_db()->inTransaction())lc_db()->rollBack();
            lc_flash($e->getMessage(),'error');
        }catch(Throwable $e){
            if(lc_db()->inTransaction())lc_db()->rollBack();
            error_log('LEAGUECUT_SEO_UPDATE_FAILED');
            lc_flash('Não foi possível salvar o SEO. Confira as imagens e tente novamente.','error');
        }
        lc_redirect('/admin/seo');
    }
    if($method!=='GET')return false;
    $cfg=lc_seo_config(lc_settings());
    $brand=(string)(lc_settings()['brand_name']??'LeagueCut');
    lc_page('SEO e compartilhamento',function()use($cfg,$brand,$admin){?>
<div class="container lc-seo-admin">
<?php lc_heading('Busca e redes sociais','SEO e compartilhamento',
  'Configure como a LeagueCut aparece no Google, WhatsApp, Instagram e outras redes. As páginas privadas não são indexadas.'); ?>
<div class="lc-seo-preview-grid">
<section class="panel lc-seo-search-preview">
<span class="eyebrow">Prévia ilustrativa no buscador</span>
<div class="lc-seo-search-url">leaguecut.com</div>
<h2><?=lc_e($cfg['seo_home_title'])?></h2>
<p><?=lc_e($cfg['seo_home_description'])?></p>
<small>Os buscadores podem apresentar títulos e descrições diferentes.</small>
</section>
<section class="panel lc-seo-share-preview">
<span class="eyebrow">Prévia da imagem de compartilhamento</span>
<?php $image=lc_seo_absolute_asset((string)$cfg['seo_social_image_url']);
if($image==='')$image=lc_seo_default_share_image();
if($image!==''):?>
<img src="<?=lc_e($image)?>" alt="Prévia da imagem compartilhada" class="lc-seo-social-image">
<?php else:?><div class="lc-seo-social-empty">Adicione uma imagem 1200 × 630 para personalizar o compartilhamento.</div><?php endif;?>
<strong><?=lc_e($cfg['seo_home_title'])?></strong>
<p><?=lc_e($cfg['seo_home_description'])?></p><small>leaguecut.com</small>
</section></div>
<div class="panel">
<form method="post" action="/admin/seo" enctype="multipart/form-data"><?=lc_csrf()?>
<div class="lc-seo-form-sections">
<section><h2>Metadados principais</h2><p class="hint">Campos de texto gerenciados no painel. O conteúdo é escapado em HTML e nunca é executado como código.</p>
<div class="form-grid">
<?php lc_field('Título da página inicial','seo_home_title','text',$cfg['seo_home_title'],true,'LeagueCut — Crie e publique','maxlength="120"'); ?>
<label class="field full"><span>Descrição da página inicial</span><textarea name="seo_home_description" maxlength="200" minlength="10" required rows="3"><?=lc_e($cfg['seo_home_description'])?></textarea></label>
<label class="field full"><span>Descrição padrão das páginas públicas</span><textarea name="seo_default_description" maxlength="200" minlength="10" required rows="3"><?=lc_e($cfg['seo_default_description'])?></textarea></label>
<?php lc_field('Título do catálogo de campanhas','seo_explore_title','text',$cfg['seo_explore_title'],false,'Campanhas LeagueCut','maxlength="120"'); ?>
<?php lc_field('Título do ranking público','seo_ranking_title','text',$cfg['seo_ranking_title'],false,'Ranking de clipadores','maxlength="120"'); ?>
<?php lc_field('Título dos planos para criadores','seo_plans_title','text',$cfg['seo_plans_title'],false,'Planos para criadores','maxlength="120"'); ?>
<label class="field full"><span>Palavras-chave (separadas por vírgula)</span><textarea name="seo_keywords" maxlength="255" rows="2"><?=lc_e($cfg['seo_keywords'])?></textarea><small>Keywords são informativas: não garantem posicionamento no Google.</small></label>
</div></section>
<section><h2>Ícones e imagem de compartilhamento</h2>
<p class="hint">A arte padrão LeagueCut (1200 × 630) já está ativa. Para trocar, envie um novo JPG/PNG/WebP ou informe a URL HTTPS da imagem. Os uploads ficam em armazenamento privado da plataforma.</p>
<p class="hint"><a href="<?=lc_e(lc_seo_default_share_image())?>" target="_blank" rel="noopener noreferrer">Ver imagem padrão atual ↗</a></p>
<div class="form-grid">
<label class="field"><span>Enviar novo favicon</span><input type="file" name="seo_favicon_upload" accept="image/png,image/jpeg,image/webp"></label>
<label class="field"><span>Enviar imagem social</span><input type="file" name="seo_social_image_upload" accept="image/png,image/jpeg,image/webp"></label>
<?php lc_field('URL HTTPS do favicon (alternativa ao upload)','seo_favicon_url','text',$cfg['seo_favicon_url'],false,'https://.../favicon.png','maxlength="800"'); ?>
<?php lc_field('URL HTTPS da imagem social (alternativa ao upload)','seo_social_image_url','text',$cfg['seo_social_image_url'],false,'https://.../capa.jpg','maxlength="800"'); ?>
</div></section>
<section><h2>Indexação, canonical e redes sociais</h2>
<div class="form-grid">
<?php lc_field('Base canônica HTTPS do site','seo_canonical_base','url',$cfg['seo_canonical_base'],true,lc_seo_origin(),'maxlength="80"'); ?>
<?php lc_field('Usuário X/Twitter (opcional)','seo_twitter_handle','text',$cfg['seo_twitter_handle'],false,'@leaguecut','maxlength="16"'); ?>
<label class="field full lc-seo-index-control"><span><input type="checkbox" name="seo_index_enabled" value="1" <?=$cfg['seo_index_enabled']==='1'?'checked':''?>> Permitir indexação das páginas públicas</span>
<small>Ao desativar, o site serve noindex e bloqueio em robots.txt. Não afeta os acessos dos usuários.</small></label>
</div>
<div class="lc-seo-route-links">
<a href="/robots.txt" target="_blank" rel="noopener">Conferir robots.txt ↗</a>
<a href="/sitemap.xml" target="_blank" rel="noopener">Conferir sitemap.xml ↗</a>
<a href="/" target="_blank" rel="noopener">Ver página inicial ↗</a>
</div></section>
<section><h2>Redes sociais oficiais no rodapé</h2>
<p class="hint">Adicione os links completos dos perfis oficiais. Deixe em branco para ocultar a rede no rodapé. Somente URLs HTTPS da respectiva plataforma são aceitas.</p>
<div class="form-grid">
<?php foreach(lc_social_footer_specs() as $key=>$spec): ?>
<label class="field"><span><?=lc_e($spec['label'])?></span>
<input type="url" name="<?=lc_e($key)?>" value="<?=lc_e($cfg[$key]??'')?>" placeholder="https://..." maxlength="500" autocomplete="url">
</label>
<?php endforeach; ?>
</div></section>
<section><h2>Confirmar alteração</h2>
<p class="hint">Por segurança, a alteração dos metadados exige sua senha e um código 2FA quando habilitado.</p>
<div class="form-grid">
<label class="field"><span>Senha atual do ADM</span><input name="admin_password" type="password" autocomplete="current-password" required></label>
<?php if(lc_mfa_enabled((int)$admin['id'])):?><label class="field"><span>Código Google Authenticator</span><input name="mfa_code" autocomplete="one-time-code" required></label><?php endif;?>
</div></section>
</div>
<div class="form-actions"><button class="btn" type="submit">Salvar SEO e compartilhamento →</button></div></form>
</div></div>
<?php },'admin');
    return true;
}
