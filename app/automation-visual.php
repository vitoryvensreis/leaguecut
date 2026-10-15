<?php
declare(strict_types=1);

function lc_automation_visual_routes(string $method,string $path): bool {
    if(!str_starts_with($path,'/admin/automacoes'))return false;
    lc_admin();
    if($method==='POST' && $path==='/admin/automacoes/visual/salvar'){
        $id=(int)($_POST['id']??0);
        $name=lc_post('name',140);
        $raw=(string)($_POST['graph_json']??'');
        try{
            if(mb_strlen($name)<3 || strlen($raw)>150000)
                throw new InvalidArgumentException('Informe um nome e um fluxo válido.');
            $graph=lc_flow_validate(json_decode($raw,true,32,JSON_THROW_ON_ERROR));
            $trigger='user.approved';$firstAction=null;
            foreach($graph['nodes'] as $n){
                if($n['type']==='trigger')$trigger=$n['data']['trigger'];
                if(in_array($n['type'],['whatsapp','email','in_app'],true)&&$firstAction===null)
                    $firstAction=$n;
            }
            // A draft can contain only free/unconnected blocks. It must never
            // enqueue anything until an action is reachable from the trigger.
            $firstAction??=['type'=>'in_app','data'=>[
               'recipient'=>'user','message'=>'Fluxo ainda sem ações conectadas.'
            ]];
            $json=json_encode($graph,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
            if($id>0){
                if(!lc_one('SELECT id FROM automation_rules WHERE id=?',[$id]))
                    throw new InvalidArgumentException('Automação não encontrada.');
                lc_query('UPDATE automation_rules
                   SET name=?,trigger_code=?,condition_field="always",condition_value="",
                       action_type=?,recipient_type=?,message_template=?,graph_json=?
                   WHERE id=?',[$name,$trigger,$firstAction['type'],
                       $firstAction['data']['recipient'],$firstAction['data']['message'],$json,$id]);
            }else{
                lc_query('INSERT INTO automation_rules
                   (name,trigger_code,condition_field,action_type,recipient_type,message_template,graph_json,created_by)
                   VALUES(? ,?,"always",?,?,?,?,?)',
                   [$name,$trigger,$firstAction['type'],$firstAction['data']['recipient'],
                    $firstAction['data']['message'],$json,lc_admin()['id']]);
                $id=(int)lc_db()->lastInsertId();
            }
            lc_flash('Fluxo visual salvo. Ative a automação quando desejar.');
            lc_redirect('/admin/automacoes/'.$id.'/editor');
        }catch(Throwable $e){
            lc_flash('Não foi possível salvar: '.mb_substr($e->getMessage(),0,150),'error');
            lc_redirect($id>0?'/admin/automacoes/'.$id.'/editor':'/admin/automacoes/novo');
        }
    }
    $editId=null;
    if(preg_match('~^/admin/automacoes/([0-9]+)/editor$~D',$path,$m))$editId=(int)$m[1];
    if($method==='GET'&&($path==='/admin/automacoes/novo'||$editId!==null)){
        $rule=$editId!==null?lc_one('SELECT * FROM automation_rules WHERE id=?',[$editId]):null;
        if($editId!==null&&!$rule){lc_flash('Fluxo não encontrado.','error');lc_redirect('/admin/automacoes');}
        if($rule&&($rule['graph_json']??'')!==''){
            $graph=json_decode($rule['graph_json'],true);
        }elseif($rule){
            $graph=lc_flow_default($rule['trigger_code']);
            $graph['nodes'][1]['type']=$rule['action_type'];
            $graph['nodes'][1]['data']=['recipient'=>$rule['recipient_type'],'message'=>$rule['message_template']];
            if($rule['condition_field']!=='always'){
                $graph['nodes'][2]['x']=1130;
                $graph['nodes'][]=['id'=>'condicao','type'=>'condition','x'=>380,'y'=>190,
                    'data'=>['field'=>$rule['condition_field'],'value'=>$rule['condition_value']]];
                $graph['nodes'][1]['x']=740;
                $graph['edges']=[
                   ['from'=>'gatilho','to'=>'condicao','port'=>'next'],
                   ['from'=>'condicao','to'=>'acao1','port'=>'true'],
                   ['from'=>'acao1','to'=>'fim','port'=>'next']
                ];
            }
        }else $graph=lc_flow_default();
        $recent=$editId!==null?lc_all('SELECT d.status,d.created_at,d.sent_at,d.last_error,
             u.full_name FROM automation_deliveries d JOIN users u ON u.id=d.recipient_user_id
             WHERE d.rule_id=? ORDER BY d.id DESC LIMIT 12',[$editId]):[];
        $json=json_encode($graph,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_QUOT|JSON_HEX_APOS|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
        lc_page('Editor de automação',function()use($editId,$rule,$recent,$json){?>
        <div class="lc-flow-page" data-flow-editor>
            <header class="lc-flow-top lc-flow-editor-top">
              <div><a class="lc-flow-back" href="/admin/automacoes">← Voltar aos fluxos</a>
              <span class="eyebrow">AUTOMAÇÃO · EDITOR VISUAL</span>
              <label class="lc-flow-name-label" for="lc-flow-title">Nome do fluxo</label>
              <input id="lc-flow-title" class="lc-flow-name-input" type="text" maxlength="140" minlength="3" value="<?=lc_e($rule['name']??'Nova automação')?>" aria-label="Nome do fluxo"></div>
              <div class="lc-flow-toolbar">
                <?php if($rule):?><form method="post" action="/admin/automacoes/<?=(int)$editId?>/alternar"><?=lc_csrf()?>
                  <button type="submit" class="btn btn-ghost"><?=$rule['enabled']?'Pausar automação':'Ativar automação'?></button>
                </form><?php endif;?>
                <span id="lc-flow-dirty" class="lc-flow-dirty" aria-live="polite">Editor pronto</span>
                <button class="btn" type="button" id="lc-flow-save">Salvar automação</button>
              </div>
            </header>
            <div class="lc-flow-summary">
              <span><b id="lc-flow-node-count">0</b> blocos</span>
              <span><b id="lc-flow-edge-count">0</b> conexões</span>
              <span>Saídas múltiplas · Cards livres não executam</span>
              <span><?=count($recent)?> execuções recentes</span>
            </div>
            <div class="lc-flow-layout">
              <section class="lc-flow-canvas-shell">
                <div class="lc-flow-canvas-top">
                  <div><h2>Canvas livre da automação</h2><p>Arraste cards, mova o fundo do canvas e conecte uma saída a quantos destinos precisar.</p></div>
                  <div class="lc-flow-canvas-buttons">
                    <button type="button" class="btn btn-ghost btn-sm" id="lc-flow-add-condition">+ Condição</button>
                    <button type="button" class="btn btn-ghost btn-sm" id="lc-flow-add-action">+ Ação</button>
                    <button type="button" class="btn btn-ghost btn-sm" id="lc-flow-add-end">+ Fim</button>
                    <button type="button" class="btn btn-ghost btn-sm" id="lc-flow-fit">Enquadrar</button>
                  </div>
                </div>
                <div class="lc-flow-scroller" id="lc-flow-scroller" tabindex="0" aria-label="Canvas de automações: arraste cards e conexões; arraste o fundo para navegar; use a roda do mouse para zoom">
                  <div id="lc-flow-canvas" class="lc-flow-canvas">
                    <svg id="lc-flow-edges" aria-hidden="true" width="5000" height="5000"></svg>
                    <div id="lc-flow-nodes"></div>
                  </div>
                </div>
                <p class="lc-flow-hint" id="lc-flow-connect-hint" role="status">Arraste da saída para conectar a vários cards. Solte sobre o vazio para criar um novo card conectado.</p>
              </section>
              <aside class="lc-flow-side">
                <section class="lc-flow-inspector">
                  <span class="eyebrow">CONFIGURAÇÃO</span>
                  <h2 id="lc-flow-inspector-title">Selecione um bloco</h2>
                  <div id="lc-flow-inspector-body"><p>Escolha um bloco no canvas para editar seus parâmetros.</p></div>
                </section>
                <section class="lc-flow-recent">
                  <h2>Últimas execuções</h2>
                  <?php if(!$recent):?><p>Nenhuma execução registrada neste fluxo.</p><?php endif;?>
                  <?php foreach($recent as $event):?>
                    <div class="lc-flow-history-item"><span class="tag"><?=lc_e($event['status'])?></span>
                      <strong><?=lc_e($event['full_name'])?></strong>
                      <small><?=lc_e((string)($event['sent_at']??$event['created_at']))?></small>
                      <?php if($event['last_error']):?><small><?=lc_e($event['last_error'])?></small><?php endif;?>
                    </div>
                  <?php endforeach;?>
                </section>
              </aside>
            </div>
            <form id="lc-flow-form" method="post" action="/admin/automacoes/visual/salvar" hidden>
              <?=lc_csrf()?>
              <input type="hidden" name="id" value="<?=(int)($editId??0)?>">
              <input type="hidden" name="name" id="lc-flow-form-name">
              <input type="hidden" name="graph_json" id="lc-flow-form-json">
            </form>
            <script type="application/json" id="lc-flow-initial"><?=$json?></script>
            <script type="application/json" id="lc-flow-catalog"><?=json_encode([
                 'triggers'=>lc_automation_triggers(),
                 'conditions'=>lc_automation_condition_fields(),
                 'actions'=>lc_automation_actions()
            ],JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_QUOT|JSON_HEX_APOS|JSON_UNESCAPED_UNICODE)?></script>
          </div>
        <?php },'admin');
        return true;
    }
    if($method!=='GET'||$path!=='/admin/automacoes')return false;
    $rules=lc_all('SELECT r.*, (SELECT COUNT(*) FROM automation_deliveries d WHERE d.rule_id=r.id AND d.status="sent") AS done,
       (SELECT COUNT(*) FROM automation_deliveries d WHERE d.rule_id=r.id AND d.status="failed") AS failed
       FROM automation_rules r ORDER BY r.id DESC LIMIT 200');
    $stats=lc_one('SELECT COUNT(*) AS total,COALESCE(SUM(enabled=1),0) AS active FROM automation_rules')??[];
    $history=lc_all('SELECT d.status,d.created_at,r.name,u.full_name
      FROM automation_deliveries d JOIN automation_rules r ON r.id=d.rule_id
      JOIN users u ON u.id=d.recipient_user_id ORDER BY d.id DESC LIMIT 10');
    lc_page('Automações',function()use($rules,$stats,$history){?>
    <div class="container lc-flow-overview">
      <header class="lc-flow-top"><div><span class="eyebrow">AUTOMAÇÃO</span><h1>Fluxos automáticos</h1>
        <p>Crie gatilhos, condições e ações no editor visual — tudo sem código.</p></div>
        <div class="lc-flow-toolbar"><a class="btn btn-ghost" href="/admin/whatsapp">Conexão WhatsApp</a>
        <a class="btn" href="/admin/automacoes/novo">+ Nova automação</a></div>
      </header>
      <div class="lc-flow-summary lc-flow-overview-stats">
        <span><strong><?=(int)($stats['total']??0)?></strong> automações</span>
        <span><strong><?=(int)($stats['active']??0)?></strong> ativas</span>
        <span><strong><?=count($history)?></strong> execuções recentes</span>
        <span>WPPConnect: <?=lc_wpp_ready()?'Habilitado':'Envio pausado'?></span>
      </div>
      <section class="lc-flow-overview-panel">
        <div class="lc-flow-overview-head"><h2>Seus fluxos</h2><input type="search" placeholder="Buscar automação" id="lc-flow-search" aria-label="Buscar automações"></div>
        <?php if(!$rules):?><p class="lc-flow-empty">Nenhuma automação criada. Comece com um fluxo de aprovação de cadastro.</p><?php endif;?>
        <?php foreach($rules as $rule):?>
        <article class="lc-flow-list-row" data-flow-name="<?=lc_e(mb_strtolower($rule['name']))?>">
          <div class="lc-flow-list-icon">◇</div><div class="lc-flow-list-description">
            <a href="/admin/automacoes/<?=(int)$rule['id']?>/editor"><strong><?=lc_e($rule['name'])?></strong></a>
            <span class="tag <?=$rule['enabled']?'tag-approved':'tag-pending'?>"><?=$rule['enabled']?'ATIVA':'PAUSADA'?></span>
            <p><?=lc_e(lc_automation_triggers()[$rule['trigger_code']]??$rule['trigger_code'])?> · <?=(int)$rule['done']?> concluídas · <?=(int)$rule['failed']?> falhas</p>
          </div>
          <form method="post" action="/admin/automacoes/<?=(int)$rule['id']?>/alternar"><?=lc_csrf()?><button class="btn btn-ghost btn-sm"><?=$rule['enabled']?'Pausar':'Ativar'?></button></form>
          <a class="btn btn-ghost btn-sm" href="/admin/automacoes/<?=(int)$rule['id']?>/editor">Editar</a>
        </article>
        <?php endforeach;?>
      </section>
      <section class="lc-flow-overview-panel"><h2>Histórico de execuções</h2>
        <?php foreach($history as $row):?><div class="lc-flow-list-row">
          <span class="tag"><?=lc_e($row['status'])?></span><strong><?=lc_e($row['name'])?></strong>
          <span><?=lc_e($row['full_name'])?></span><small><?=lc_e($row['created_at'])?></small>
        </div><?php endforeach;?>
        <?php if(!$history):?><p>Nenhuma execução ainda.</p><?php endif;?>
      </section>
    </div>
    <?php },'admin');return true;
}
