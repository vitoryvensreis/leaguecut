<?php
declare(strict_types=1);

/**
 * Showcase fixtures: no demo user, campaign, clip, balance or payment is
 * inserted into production tables. The single persisted value is the switch.
 */
function lc_demo_enabled(): bool {
    static $enabled=null;
    if($enabled===null){
        $row=lc_one("SELECT setting_value FROM platform_settings WHERE setting_key='demo_mode_enabled'");
        $enabled=($row['setting_value']??'0')==='1';
    }
    return $enabled;
}
function lc_demo_campaigns(): array {
    $examples=[
      ['Desafio Energia em Movimento','Tecnologia','Cortes criativos sobre tecnologia, energia e soluções do futuro.',5500],
      ['Arena dos Criadores','Entretenimento','Selecione momentos marcantes de um grande evento de criadores.',6500],
      ['Conexões que Inspiram','Podcasts','Transforme conversas inspiradoras em vídeos curtos.',4200],
      ['O Melhor do Esporte','Esportes','Os lances mais comentados, reações e histórias de superação.',4800],
      ['Festival de Momentos','Música','Clipes vibrantes com os melhores momentos de um festival fictício.',6000],
      ['Universo Gamer','Games','Highlights, jogadas e reações de transmissões ao vivo.',5000],
      ['Empreenda Agora','Negócios','Insights sobre empreendedorismo e crescimento de negócios.',4500],
      ['Histórias que Conectam','Lifestyle','Conteúdos curtos com histórias e experiências especiais.',3800],
      ['Desafio Vozes do Futuro','Educação','Cortes educativos com dicas, ideias e descobertas.',4000],
      ['Bastidores em Alta','Entretenimento','Momentos espontâneos de produção e bastidores.',3700],
      ['Arena Fitness','Saúde e Bem-estar','Conteúdo de hábitos saudáveis e performance esportiva.',4300],
      ['Cultura em Cena','Cultura','Histórias da cultura brasileira contadas em poucos segundos.',4600],
      ['Live dos Milhões','Games','O melhor de uma transmissão fictícia cheia de desafios.',6800],
      ['Ideias em 60 Segundos','Educação','Explicações rápidas e conteúdos que despertam curiosidade.',3500],
      ['Mundo em Transformação','Tecnologia','Novas ideias, inovação e transformação digital.',5400],
      ['Palco dos Talentos','Música','Trechos de apresentações e talentos imaginários.',5200]
    ];
    $result=[];
    foreach($examples as $i=>$item){
        $slug='demo-'.lc_demo_slug($item[0]);
        $result[]=[
          'id'=>-($i+1),'title'=>$item[0],'slug'=>$slug,
          'description'=>$item[2],'category'=>$item[1],'cover_url'=>null,
          'status'=>'active','payment_per_video_cents'=>$item[3],
          'min_videos_week'=>5,'milestone_views'=>1000000,
          'milestone_bonus_cents'=>2000,'monthly_leader_bonus_cents'=>10000,
          'is_demo'=>true,
          'created_at'=>date('Y-m-d H:i:s',strtotime('-'.($i+1).' days'))
        ];
    }
    return $result;
}
function lc_demo_slug(string $title): string {
    $s=iconv('UTF-8','ASCII//TRANSLIT//IGNORE',$title)?:$title;
    return trim((string)preg_replace('/[^a-z0-9]+/','-',strtolower($s)),'-');
}
function lc_demo_ranking(int $limit=50): array {
    $handles=[
      'corte_mestre','vibe_clips','editora_x','flash_cortes','corteiro_pro',
      'pixel_maker','reels_do_dia','creator_wave','clip_arena','edit_lab',
      'focus_highlight','fastcuts','video_vision','motion_clip','nova_timeline',
      'vortex_reels','cortes_em_alta','clip_studio','clip_impacto','criativo_247',
      'frame_catcher','edit_expert','reels_factory','cortes_360','zoom_creator',
      'cena_de_ouro','top_highlights','corte_perfeito','smart_clipper','clip_mania'
    ];
    $results=[];
    foreach($handles as $i=>$nick){
        $results[]=[
          'nickname'=>$nick,'full_name'=>'Clipador Demo '.str_pad((string)($i+1),2,'0',STR_PAD_LEFT),
          'views'=>max(25000,8395000-$i*231750+(($i%4)*9700)),
          'clips'=>max(8,122-$i*3),'is_demo'=>true
        ];
    }
    return array_slice($results,0,max(0,min(50,$limit)));
}
function lc_demo_merge_ranking(array $real,int $limit): array {
    if(!lc_demo_enabled())return array_slice($real,0,$limit);
    $merged=array_merge($real,lc_demo_ranking(50));
    usort($merged,static fn(array $a,array $b)=>((int)$b['views']<=>(int)$a['views'])?:((int)$b['clips']<=>(int)$a['clips']));
    return array_slice($merged,0,$limit);
}
function lc_demo_merge_campaigns(array $real,string $term='',string $category=''): array {
    if(!lc_demo_enabled())return $real;
    foreach(lc_demo_campaigns() as $campaign){
        if($category!==''&&$campaign['category']!==$category)continue;
        if($term!=='' && mb_stripos($campaign['title'].' '.$campaign['description'],$term)===false)continue;
        $real[]=$campaign;
    }
    return $real;
}
function lc_demo_campaign(string $slug): ?array {
    foreach(lc_demo_campaigns() as $campaign){
        if($campaign['slug']===$slug)return $campaign;
    }
    return null;
}
function lc_demo_campaign_ranking(string $slug): array {
    $seed=(int)(crc32($slug)%15);
    return array_slice(lc_demo_ranking(30),$seed,8);
}
function lc_demo_routes(string $method,string $path): bool {
    if($path==='/admin/demo'){
        $actor=lc_admin();
        if($method==='POST'){
            if(!lc_account_admin_password($actor)){
                lc_flash('Confirme a senha administrativa e, se habilitado, o código 2FA.','error');
                lc_redirect('/admin/demo');
            }
            $enable=(string)($_POST['enabled']??'0')==='1';
            lc_query("INSERT INTO platform_settings(setting_key,setting_value)
                      VALUES('demo_mode_enabled',?)
                      ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)",[$enable?'1':'0']);
            lc_account_admin_audit((int)$actor['id'],$enable?'demo.enabled':'demo.disabled',null,['fields'=>['demo_mode_enabled']]);
            lc_flash($enable?'Demonstração ativada: todos os exemplos são fictícios.':'Demonstração desativada. Os dados reais não foram alterados.');
            lc_redirect('/admin/demo');
        }
        if($method==='GET'){
            $enabled=lc_demo_enabled();
            lc_page('Modo demonstração',function()use($enabled){?>
<div class="container container-md lc-demo-settings">
<?php lc_heading('Apresentações comerciais','Modo demonstração','Preencha a experiência com exemplos sem movimentar informações reais.'); ?>
<div class="panel">
  <span class="tag"><?=$enabled?'DEMO ATIVA':'DEMO DESATIVADA'?></span>
  <h2>Apresente o potencial do LeagueCut</h2>
  <p>Ao ativar, o painel de visão geral e os catálogos mostram 16 campanhas fictícias, 30 clipadores de exemplo e rankings ilustrativos. A página inicial e o ranking público exibem uma identificação clara de demonstração.</p>
  <p class="hint">Nenhuma conta, campanha, clipe ou pagamento é cadastrado no banco. A fila de aprovações, os saques, o financeiro e as assinaturas continuam mostrando apenas dados reais.</p>
  <form method="post" action="/admin/demo"><?=lc_csrf()?>
    <input type="hidden" name="enabled" value="<?=$enabled?'0':'1'?>">
    <div class="form-grid">
      <label class="field full"><span>Senha administrativa</span><input type="password" name="admin_password" autocomplete="current-password" required></label>
      <?php if(lc_mfa_enabled((int)lc_admin()['id'])):?>
      <label class="field full"><span>Autenticador 2FA</span><input name="mfa_code" required autocomplete="one-time-code" maxlength="60"></label>
      <?php endif;?>
    </div>
    <div class="form-actions">
      <button class="btn" type="submit"><?=$enabled?'Desativar demonstração':'Ativar demonstração'?></button>
      <a class="btn btn-ghost" href="/admin">Visão geral</a>
      <a class="btn btn-ghost" href="/explorar">Visualizar catálogo</a>
    </div>
  </form>
</div>
</div>
<?php },'admin');return true;
        }
        return false;
    }
    if($method==='GET'&&preg_match('~^/demo/campanhas/(demo-[a-z0-9-]+)$~D',$path,$m)){
        if(!lc_demo_enabled()){http_response_code(404);return true;}
        $campaign=lc_demo_campaign($m[1]);
        if(!$campaign){http_response_code(404);return true;}
        $rank=lc_demo_campaign_ranking($m[1]);
        lc_page($campaign['title'].' — Demonstração',function()use($campaign,$rank){?>
<div class="container container-md lc-demo-detail">
  <a class="btn btn-ghost btn-sm" href="/explorar">← Voltar ao catálogo</a>
  <div class="panel">
    <span class="tag">AMBIENTE DEMONSTRATIVO · CAMPANHA FICTÍCIA</span>
    <h1><?=lc_e($campaign['title'])?></h1>
    <p><?=lc_e($campaign['description'])?></p>
    <div class="rule-grid">
      <div class="metric"><small>Valor ilustrativo por clipe</small><strong><?=lc_money($campaign['payment_per_video_cents'])?></strong></div>
      <div class="metric"><small>Meta ilustrativa</small><strong>5 clipes/semana</strong></div>
    </div>
    <p class="hint">Esta campanha não está recebendo inscrições ou publicações. Nenhum pagamento é oferecido por este exemplo.</p>
  </div>
  <div class="panel"><h2>Ranking da campanha (simulado)</h2>
  <div class="lc-leaderboard" role="list"><?php foreach($rank as $i=>$player):?>
    <div class="lc-leaderboard-row" role="listitem">
      <span class="lc-leaderboard-pos"><?=$i+1?>º</span>
      <span class="lc-leaderboard-player">@<?=lc_e($player['nickname'])?> <?=lc_rank_trophy($i+1)?></span>
      <span class="lc-leaderboard-views"><?=number_format((int)$player['views'],0,',','.')?> <small>views fictícias</small></span>
    </div>
  <?php endforeach;?></div></div>
</div>
<?php },'home');return true;
    }
    return false;
}
