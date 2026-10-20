<?php
declare(strict_types=1;

/** Accessible, workspace-wide clip submission modal. Upload first, metadata next. */
function lc_clipper_post_dialog(array $u): void {
    if(($u['role']??'')!=='clipador'||($u['status']??'')!=='approved')return;
    $campaigns=lc_all("SELECT id,title FROM campaigns WHERE status='active'
      AND (starts_at IS NULL OR starts_at<=CURRENT_DATE())
      AND (ends_at IS NULL OR ends_at>=CURRENT_DATE()) ORDER BY created_at DESC LIMIT 100");
    $accounts=lc_all('SELECT id,network,username FROM social_accounts WHERE user_id=? ORDER BY network,username',[(int)$u['id']]);
?>
<dialog id="lc-quick-post" class="lc-quick-post" aria-labelledby="lc-quick-post-title">
 <div class="lc-quick-inner">
  <div class="lc-quick-head"><div><span class="eyebrow">PUBLICAÇÃO DE CLIPES</span><h2 id="lc-quick-post-title">Publicar clipe</h2></div>
   <button class="lc-quick-close" type="button" data-quick-close aria-label="Fechar publicação">×</button></div>
  <?php if(!$campaigns):?><p class="alert">Ainda não há campanhas ativas. Aguarde a próxima oportunidade.</p>
  <?php elseif(!$accounts):?><p class="alert">Antes de publicar, <a href="/redes">cadastre seu Instagram ou TikTok</a>.</p>
  <?php elseif(!lc_drive_ready()):?><p class="alert">O Google Drive ainda não está conectado. Tente novamente mais tarde.</p>
  <?php else:?>
  <form id="lc-quick-form" action="/clipes" method="post" enctype="multipart/form-data"><?=lc_csrf()?>
   <div id="lc-quick-step-file" class="lc-quick-step">
    <label class="lc-quick-drop" id="lc-quick-drop" tabindex="0">
      <input id="lc-quick-file" type="file" name="video" accept=".mp4,.mov,.webm,video/mp4,video/quicktime,video/webm" required>
      <span class="lc-quick-drop-icon"><?=lc_icon('upload-cloud')?></span>
      <strong>Arraste seu vídeo aqui</strong><span>ou clique para selecionar um arquivo</span>
      <small>MP4, MOV ou WebM · máximo 1 GB</small>
    </label>
    <p id="lc-quick-filename" class="hint" aria-live="polite"></p>
    <button class="btn lc-quick-next" type="button" id="lc-quick-next" disabled>Continuar <?=lc_icon('arrow-right')?></button>
   </div>
   <div id="lc-quick-step-details" class="lc-quick-step" hidden>
    <p class="lc-quick-selected" id="lc-quick-selected"></p>
    <label class="field"><span>Campanha</span><select name="campaign_id" required>
     <option value="">Selecione a campanha</option>
     <?php foreach($campaigns as $c):?><option value="<?=(int)$c['id']?>"><?=lc_e($c['title'])?></option><?php endforeach;?>
    </select></label>
    <label class="field"><span>Rede / perfil usado</span><select name="social_account_id" required>
     <option value="">Selecione seu perfil</option>
     <?php foreach($accounts as $a):?><option value="<?=(int)$a['id']?>"><?=lc_e(ucfirst($a['network']).' · @'.$a['username'])?></option><?php endforeach;?>
    </select></label>
    <label class="field"><span>Link do vídeo já publicado</span><input type="url" name="published_url" placeholder="https://www.instagram.com/reel/..." required></label>
    <div class="lc-quick-progress" id="lc-quick-progress" hidden role="status" aria-live="polite">
     <span id="lc-quick-stage">Enviando vídeo…</span><strong id="lc-quick-percent">0%</strong>
     <progress id="lc-quick-meter" max="100" value="0"></progress>
     <small>O envio ao Google Drive pode continuar após alcançar 100%.</small>
    </div>
    <div class="alert alert-error" id="lc-quick-error" hidden role="alert"></div>
    <div class="lc-quick-actions"><button type="button" class="btn btn-ghost" id="lc-quick-back">Voltar</button>
     <button class="btn" id="lc-quick-submit" type="submit">Enviar para conferência <?=lc_icon('arrow-right')?></button></div>
   </div>
  </form>
  <?php endif;?>
 </div>
</dialog>
<?php
}
