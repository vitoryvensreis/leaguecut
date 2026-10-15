<?php
declare(strict_types=1);

function lc_automation_routes(string $method,string $path): bool {
    if(!str_starts_with($path,'/admin/automacoes'))return false;
    lc_admin();
    $back='/admin/automacoes';
    if($method==='POST'&&$path==='/admin/automacoes/salvar'){
        $id=(int)($_POST['id']??0);
        $name=lc_post('name',140);
        $trigger=lc_post('trigger_code',50);
        $action=lc_post('action_type',20);
        $recipient=lc_post('recipient_type',10);
        $field=lc_post('condition_field',30);
        $value=lc_post('condition_value',160);
        $message=lc_post('message_template',1800);
        if(mb_strlen($name)<3||!isset(lc_automation_triggers()[$trigger])
           ||!isset(lc_automation_actions()[$action])
           ||!in_array($recipient,['user','admins'],true)
           ||!isset(lc_automation_condition_fields()[$field])
           ||($field!=='always' && $value==='')||$message===''){
            lc_flash('Revise nome, gatilho, condição, destinatário e mensagem.','error');
            lc_redirect($back.($id>0?'?editar='.$id:''));
        }
        $value=$field==='always'?'':$value;
        if($id>0){
            $old=lc_one('SELECT id FROM automation_rules WHERE id=?',[$id]);
            if(!$old){lc_flash('Automação não encontrada.','error');lc_redirect($back);}
            lc_query('UPDATE automation_rules SET name=?,trigger_code=?,condition_field=?,condition_value=?,action_type=?,recipient_type=?,message_template=?,graph_json=NULL WHERE id=?',
               [$name,$trigger,$field,$value,$action,$recipient,$message,$id]);
        }else{
            // New rules are off until explicitly enabled by an administrator.
            lc_query('INSERT INTO automation_rules(name,trigger_code,condition_field,condition_value,action_type,recipient_type,message_template,created_by) VALUES(?,?,?,?,?,?,?,?)',
              [$name,$trigger,$field,$value,$action,$recipient,$message,lc_admin()['id']]);
        }
        lc_flash('Automação salva. Ative o fluxo quando estiver pronto.');
        lc_redirect($back);
    }
    if($method==='POST'&&preg_match('~^/admin/automacoes/([0-9]+)/alternar$~D',$path,$m)){
        lc_query('UPDATE automation_rules SET enabled=IF(enabled=1,0,1) WHERE id=?',[(int)$m[1]]);
        lc_flash('Estado da automação atualizado.');
        lc_redirect($back);
    }
    if($method==='POST'&&preg_match('~^/admin/automacoes/([0-9]+)/reprocessar$~D',$path,$m)){
        $job=lc_one("SELECT d.id FROM automation_deliveries d JOIN automation_rules r ON r.id=d.rule_id
              WHERE d.id=? AND d.status='failed' AND r.enabled=1",[(int)$m[1]]);
        if($job)lc_query("UPDATE automation_deliveries SET status='pending',attempts=0,available_at=NOW(),last_error=NULL WHERE id=?",[(int)$m[1]]);
        lc_flash($job?'Entrega colocada novamente na fila.':'Não é possível reprocessar esta entrega.');
        lc_redirect($back);
    }
    if($method!=='GET'||$path!==$back)return false;
    $rules=lc_all('SELECT r.*, (SELECT COUNT(*) FROM automation_deliveries d WHERE d.rule_id=r.id AND d.status="sent") AS deliveries_sent FROM automation_rules r ORDER BY r.id DESC LIMIT 200');
    $history=lc_all('SELECT d.id,d.status,d.attempts,d.created_at,d.sent_at,d.last_error,r.name AS rule_name,r.action_type,u.full_name
       FROM automation_deliveries d JOIN automation_rules r ON r.id=d.rule_id JOIN users u ON u.id=d.recipient_user_id
       ORDER BY d.id DESC LIMIT 40');
    $counts=lc_one("SELECT COUNT(*) AS total,COALESCE(SUM(enabled=1),0) AS active FROM automation_rules")??[];
    $pending=(int)(lc_one("SELECT COUNT(*) AS total FROM automation_deliveries WHERE status='pending'")['total']??0);
    $editing=isset($_GET['editar'])?lc_one('SELECT * FROM automation_rules WHERE id=?',[(int)$_GET['editar']]):null;
    lc_page('Automações',function()use($rules,$history,$counts,$pending,$editing){?>
<div class="container lc-automations">
  <header class="lc-automations-heading">
    <div><span class="eyebrow"><?=lc_icon('sparkles')?> CENTRAL DE AUTOMAÇÃO</span>
      <h1>Seu LeagueCut no piloto automático<span class="lc-accent-dot">.</span></h1>
      <p>Monte fluxos com gatilhos, condições e ações. Envie somente mensagens autorizadas e acompanhe cada tentativa.</p>
    </div>
    <a class="btn btn-ghost" href="/admin/integracoes"><?=lc_icon('settings-2')?> Integrações</a>
  </header>
  <section class="lc-auto-overview" aria-label="Indicadores de automação">
    <div class="lc-auto-stat"><span>Automações</span><strong><?=(int)($counts['total']??0)?></strong><small>Regras criadas</small></div>
    <div class="lc-auto-stat"><span>Ativas</span><strong><?=(int)($counts['active']??0)?></strong><small>Executam ao ocorrer o gatilho</small></div>
    <div class="lc-auto-stat"><span>Na fila</span><strong><?=$pending?></strong><small>Aguardando worker / provedor</small></div>
    <div class="lc-auto-stat"><span>WhatsApp</span><strong class="lc-auto-state"><?=lc_wpp_ready()?'Configurado':'Desligado'?></strong><small><?=lc_wpp_ready()?'Envio via WPPConnect':'Configure .env + sessão no servidor'?></small></div>
  </section>
  <div class="lc-auto-layout">
    <section class="panel lc-auto-builder">
      <div class="ws-module-head"><h2><?=$editing?'Editar automação':'Criar automação'?></h2><span class="tag">Gatilho → condição → ação</span></div>
      <form method="post" action="/admin/automacoes/salvar"><?=lc_csrf()?>
        <input type="hidden" name="id" value="<?=(int)($editing['id']??0)?>">
        <label class="field"><span>Nome do fluxo</span><input type="text" name="name" maxlength="140" required placeholder="Ex.: Boas-vindas ao clipador" value="<?=lc_e($editing['name']??'')?>"></label>
        <div class="lc-auto-step"><span class="lc-step-num">01</span><div><h3>Quando acontecer...</h3>
          <label class="field"><span>Gatilho</span><select name="trigger_code" required>
          <?php foreach(lc_automation_triggers() as $code=>$label):?><option value="<?=lc_e($code)?>" <?=($editing['trigger_code']??'user.approved')===$code?'selected':''?>><?=lc_e($label)?></option><?php endforeach;?></select></label></div></div>
        <div class="lc-auto-step"><span class="lc-step-num">02</span><div><h3>Se esta condição for atendida...</h3>
          <div class="form-grid">
            <label class="field"><span>Condição</span><select name="condition_field">
            <?php foreach(lc_automation_condition_fields() as $code=>$label):?><option value="<?=lc_e($code)?>" <?=($editing['condition_field']??'always')===$code?'selected':''?>><?=lc_e($label)?></option><?php endforeach;?></select></label>
            <label class="field"><span>Valor (exceto “Sempre”)</span><input type="text" name="condition_value" maxlength="160" placeholder="Ex.: clipador, approved, 8" value="<?=lc_e($editing['condition_value']??'')?>"></label>
          </div></div></div>
        <div class="lc-auto-step"><span class="lc-step-num">03</span><div><h3>Executar a ação</h3>
          <div class="form-grid">
            <label class="field"><span>Ação</span><select name="action_type">
            <?php foreach(lc_automation_actions() as $code=>$label):?><option value="<?=lc_e($code)?>" <?=($editing['action_type']??'whatsapp')===$code?'selected':''?>><?=lc_e($label)?></option><?php endforeach;?></select></label>
            <label class="field"><span>Destinatário</span><select name="recipient_type"><option value="user" <?=($editing['recipient_type']??'user')==='user'?'selected':''?>>Usuário do evento</option><option value="admins" <?=($editing['recipient_type']??'')==='admins'?'selected':''?>>Administradores</option></select></label>
          </div>
          <label class="field"><span>Mensagem</span><textarea name="message_template" rows="5" maxlength="1800" required placeholder="Olá, {nome}! Seu cadastro foi aprovado. Acesse: {link}"><?=lc_e($editing['message_template']??'Olá, {nome}! Seu cadastro foi aprovado no LeagueCut. Acesse: {link}')?></textarea></label>
          <p class="hint">Variáveis disponíveis: <code>{nome}</code>, <code>{nome_completo}</code>, <code>{titulo}</code>, <code>{campanha}</code>, <code>{status}</code>, <code>{link}</code>. Somente caminhos internos são aceitos nos links automáticos.</p>
        </div></div>
        <div class="form-actions"><button class="btn" type="submit"><?=lc_icon('check')?> Salvar fluxo</button><?php if($editing):?><a class="btn btn-ghost" href="/admin/automacoes">Cancelar edição</a><?php endif;?></div>
        <p class="hint">Novos fluxos são criados desligados. Você decide quando ativar.</p>
      </form>
    </section>
    <section class="lc-auto-rules">
      <div class="ws-module-head"><h2>Seus fluxos</h2><span class="tag"><?=count($rules)?> cadastrados</span></div>
      <?php if(!$rules):?><div class="panel"><p>Nenhuma automação criada. Monte o primeiro fluxo ao lado.</p></div><?php endif;?>
      <?php foreach($rules as $rule):?>
        <article class="lc-auto-rule">
          <div class="lc-auto-rule-top"><strong><?=lc_e($rule['name'])?></strong><span class="tag <?=$rule['enabled']?'tag-approved':'tag-pending'?>"><?=$rule['enabled']?'Ativa':'Pausada'?></span></div>
          <p><?=lc_e(lc_automation_triggers()[$rule['trigger_code']]??$rule['trigger_code'])?> <span aria-hidden="true">→</span> <?=lc_e(lc_automation_actions()[$rule['action_type']]??$rule['action_type'])?></p>
          <small><?=lc_e($rule['condition_field']==='always'?'Sem condições':'Condição: '.$rule['condition_field'].' = '.$rule['condition_value'])?> · <?=(int)$rule['deliveries_sent']?> entregas</small>
          <div class="lc-auto-rule-actions"><a class="btn btn-ghost btn-sm" href="/admin/automacoes?editar=<?=(int)$rule['id']?>">Editar</a>
           <form method="post" action="/admin/automacoes/<?=(int)$rule['id']?>/alternar"><?=lc_csrf()?><button class="btn btn-sm" type="submit"><?=$rule['enabled']?'Pausar':'Ativar'?></button></form></div>
        </article>
      <?php endforeach;?>
    </section>
  </div>
  <section class="panel lc-auto-history">
    <div class="ws-module-head"><h2>Histórico de execuções</h2><span class="tag">Últimas 40 entregas</span></div>
    <div class="table-wrap"><table><thead><tr><th>Fluxo</th><th>Destinatário</th><th>Canal</th><th>Estado</th><th>Quando</th><th>Ação</th></tr></thead><tbody>
      <?php foreach($history as $row):?><tr>
        <td><?=lc_e($row['rule_name'])?><?php if($row['last_error']):?><small><?=lc_e($row['last_error'])?></small><?php endif;?></td>
        <td><?=lc_e($row['full_name'])?></td><td><?=lc_e($row['action_type'])?></td>
        <td><span class="tag"><?=lc_e($row['status'])?> · <?=(int)$row['attempts']?> tentativas</span></td>
        <td><?=lc_e($row['sent_at']??$row['created_at'])?></td><td>
        <?php if($row['status']==='failed'):?><form method="post" action="/admin/automacoes/<?=(int)$row['id']?>/reprocessar"><?=lc_csrf()?><button class="btn btn-sm btn-ghost">Reprocessar</button></form><?php else:?>—<?php endif;?>
        </td>
      </tr><?php endforeach;?>
      <?php if(!$history):?><tr><td colspan="6">Ainda não há execuções registradas.</td></tr><?php endif;?>
    </tbody></table></div>
  </section>
</div>
<?php },'admin');
    return true;
}
