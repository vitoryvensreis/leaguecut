<?php
declare(strict_types=1);

/** Shared two-step upload dialog, available on every approved clipper page. */
function lc_quick_clip_modal(array $u): void {
    if(($u['role']??'')!=='clipador'||($u['status']??'')!=='approved')return;
    $campaigns=lc_all("SELECT id,title FROM campaigns WHERE status='active' ORDER BY created_at DESC LIMIT 100");
    $accounts=lc_all("SELECT id,network,username FROM social_accounts WHERE user_id=? ORDER BY id DESC",[(int)$u['id']]);
    $enabled=(bool)$campaigns && (bool)$accounts && lc_drive_ready();
?>
<dialog id="lc-quick-clip" class="lc-quick-dialog" aria-labelledby="lc-quick-title">
  <div class="lc-quick-head"><div><span class="eyebrow">LeagueCut Studio</span><h2 id="lc-quick-title">Publicar clipe</h2><p>Seu vídeo, sua campanha, sua entrega.</p></div><button type="button" class="lc-quick-close" data-clip-close aria-label="Fechar">×</button></div>
  <?php if(!$campaigns):?><p class="alert">Ainda não há campanhas ativas. Volte quando uma nova campanha for publicada.</p>
  <?php elseif(!$accounts):?><p class="alert">Primeiro cadastre seu Instagram ou TikTok em <a href="/redes">Minhas redes</a> para vincular a publicação.</p>
  <?php elseif(!lc_drive_ready()):?><p class="alert">Envios temporariamente indisponíveis: o administrador precisa conectar o Google Drive.</p>
  <?php endif;?>
  <?php if($enabled):?>
  <form id="lc-quick-form" action="/clipes" method="post" enctype="multipart/form-data" novalidate>
    <?=lc_csrf()?>
    <section class="lc-quick-step" id="lc-quick-step-file" aria-label="Etapa 1: vídeo">
      <div class="lc-quick-counter">ETAPA 01 DE 02 <span>Escolha seu vídeo</span></div>
      <label class="lc-quick-drop" for="lc-quick-file" tabindex="0">
        <?=lc_icon('upload-cloud')?>
        <strong>Arraste seu vídeo ou clique para procurar</strong>
        <span>MP4, MOV ou WebM • até 1 GB</span>
        <input type="file" id="lc-quick-file" name="video" accept=".mp4,.mov,.webm,video/mp4,video/quicktime,video/webm" required>
      </label>
      <p class="lc-quick-error" data-clip-error role="alert" hidden></p>
    </section>
    <section class="lc-quick-step" id="lc-quick-step-info" aria-label="Etapa 2: publicação" hidden>
      <div class="lc-quick-counter">ETAPA 02 DE 02 <span>Dados da publicação</span></div>
      <div class="lc-quick-selected"><div><strong data-clip-file-name>Vídeo selecionado</strong><small data-clip-file-size></small></div><button type="button" class="btn btn-ghost btn-sm" data-clip-back>Trocar vídeo</button></div>
      <div class="form-grid">
        <label class="field full"><span>Campanha</span><select name="campaign_id" required><option value="">Selecione a campanha</option><?php foreach($campaigns as $c):?><option value="<?=(int)$c['id']?>"><?=lc_e($c['title'])?></option><?php endforeach;?></select></label>
        <label class="field full"><span>Rede social e perfil</span><select name="social_account_id" required><option value="">Selecione a rede</option><?php foreach($accounts as $a):?><option value="<?=(int)$a['id']?>"><?=lc_e(ucfirst($a['network']).' · @'.$a['username'])?></option><?php endforeach;?></select></label>
        <label class="field full"><span>Link do vídeo já publicado</span><input type="url" name="published_url" placeholder="https://www.instagram.com/reel/..." required></label>
      </div>
      <p class="lc-quick-note">Seu clipe receberá um identificador automático. A conferência começa após o envio do vídeo e do link.</p>
      <div class="lc-quick-progress" data-clip-progress hidden><div><span data-clip-progress-label>Enviando arquivo…</span><strong data-clip-percent>0%</strong></div><progress max="100" value="0" data-clip-meter></progress><small data-clip-progress-help>Transferindo para a VPS.</small></div>
      <p class="lc-quick-error" data-clip-error role="alert" hidden></p>
      <button class="btn lc-quick-submit" type="submit">Enviar para conferência <?=lc_icon('arrow-right')?></button>
    </section>
  </form>
  <?php endif;?>
</dialog>
<?php
}
