<?php
declare(strict_types=1);

/** Referral reporting and human-confirmed commission disbursement. */
function lc_referral_routes(string $method,string $path): bool {
    if($path==='/indicacoes'){
        $viewer=lc_login_required();
        if($method!=='GET')return false;
        $code=lc_referral_code((int)$viewer['id']);
        $base=rtrim(lc_env('APP_URL','https://leaguecut.com'),'/');
        // Force canonical HTTPS public domain for shareable links.
        if(!preg_match('~^https://leaguecut\.com$~i',$base))$base='https://leaguecut.com';
        $links=[
            'Clipador'=>$base.'/cadastrar?ref='.$code,
            'Criador'=>$base.'/cadastrar/criador?ref='.$code
        ];
        $rules=lc_all('SELECT referred_role,enabled,mode,value_int FROM referral_rules ORDER BY referred_role');
        $stats=lc_one("SELECT COUNT(*) AS referrals FROM referrals WHERE referrer_user_id=?",[$viewer['id']])?:[];
        $summary=lc_one("SELECT COUNT(*) AS commissions,
            COALESCE(SUM(IF(status='approved',amount_cents,0)),0) AS paid_credits,
            COALESCE(SUM(IF(status='pending',amount_cents,0)),0) AS pending_cents
            FROM referral_commissions WHERE referrer_user_id=?",[$viewer['id']])?:[];
        $history=lc_all("SELECT rc.amount_cents,rc.status,rc.created_at,rc.source_type,
            u.nickname AS referred_nick
            FROM referral_commissions rc JOIN users u ON u.id=rc.referred_user_id
            WHERE rc.referrer_user_id=? ORDER BY rc.id DESC LIMIT 40",[$viewer['id']]);
        lc_page('Minhas indicações',function()use($viewer,$links,$rules,$stats,$summary,$history){?>
<div class="container container-md lc-referral-area">
<?php lc_heading('Convide e conquiste','Minhas indicações',
   'Compartilhe sua indicação com clipadores ou criadores. Comissões dependem de pagamentos elegíveis e aprovação administrativa.'); ?>
<div class="ws-kpi-grid">
<div class="ws-kpi"><span>Indicados</span><strong><?=number_format((int)($stats['referrals']??0),0,',','.')?></strong></div>
<div class="ws-kpi"><span>Comissões em análise</span><strong><?=lc_money((int)($summary['pending_cents']??0))?></strong></div>
<div class="ws-kpi"><span>Créditos aprovados</span><strong><?=lc_money((int)($summary['paid_credits']??0))?></strong></div>
</div>
<div class="panel"><h2>Seu convite exclusivo</h2>
<?php foreach($links as $label=>$link):?><label class="field"><span>Indicar <?=$label?></span>
<div class="lc-referral-copy"><input type="text" readonly value="<?=lc_e($link)?>" aria-label="Link para indicar <?=$label?>" data-copy-field>
<button type="button" class="btn btn-ghost btn-sm" data-copy-ref>Copiar</button></div></label><?php endforeach;?>
<p class="hint">A comissão não é gerada pelo cadastro. Ela entra em análise após o primeiro evento financeiro elegível e só vira crédito depois da conferência do ADM.</p></div>
<div class="panel"><h2>Regras vigentes</h2><div class="lc-referral-rules">
<?php foreach($rules as $r):?><div><strong><?=lc_e(ucfirst($r['referred_role']))?></strong>
<span><?=!(int)$r['enabled']?'Programa pausado':($r['mode']==='fixed'?
  lc_money((int)$r['value_int']).' uma vez após o primeiro evento elegível':
  number_format((int)$r['value_int']/100,2,',','.').'% sobre cada evento financeiro elegível')?></span></div><?php endforeach;?></div></div>
<div class="panel"><h2>Minhas comissões</h2>
<?php if(!$history):?><p class="hint">Ainda não há eventos elegíveis para pagamento de indicação.</p>
<?php else:?><div class="table-wrap"><table><thead><tr><th>Indicado</th><th>Origem</th><th>Comissão</th><th>Situação</th></tr></thead><tbody>
<?php foreach($history as $entry):?><tr><td>@<?=lc_e($entry['referred_nick']??'indicado')?></td>
<td><?=lc_e($entry['source_type']==='clip_credit'?'Clipe creditado':'Mensalidade confirmada')?></td>
<td><?=lc_money((int)$entry['amount_cents'])?></td><td><?=lc_e($entry['status']==='pending'?'Em análise':($entry['status']==='approved'?'Aprovada':'Recusada'))?></td></tr><?php endforeach;?></tbody></table></div><?php endif;?></div></div>
<script src="/assets/referral-copy.js" defer></script>
<?php },$viewer['role']==='admin'?'admin':($viewer['role']==='criador'?'creator':'dashboard'));
        return true;
    }
    if(!str_starts_with($path,'/admin/indicacoes'))return false;
    $admin=lc_admin();
    if($method==='POST'&&$path==='/admin/indicacoes/regras'){
        if(!lc_account_admin_password($admin)){
            lc_flash('Confirme sua senha e o segundo fator para modificar as regras.','error');
            lc_redirect('/admin/indicacoes');
        }
        $role=(string)($_POST['referred_role']??'');
        $mode=(string)($_POST['mode']??'');
        $enabled=isset($_POST['enabled'])?1:0;
        if(!in_array($role,['clipador','criador'],true)||!in_array($mode,['fixed','percent'],true)){
            lc_flash('Regra inválida.','error');lc_redirect('/admin/indicacoes');
        }
        $value=$mode==='fixed'?lc_cents('fixed_value',0):lc_cents('percent_value',0);
        if($value<0||($mode==='fixed'&&$value>50000)||($mode==='percent'&&$value>5000)){
            lc_flash('Limite máximo: R$ 500,00 fixos ou 50% por evento.','error');
            lc_redirect('/admin/indicacoes');
        }
        lc_query("UPDATE referral_rules SET enabled=?,mode=?,value_int=?,updated_by=? WHERE referred_role=?",
            [$enabled,$mode,$value,$admin['id'],$role]);
        lc_account_admin_audit((int)$admin['id'],'referral.rule.changed',null,['fields'=>[$role,$mode]]);
        lc_flash('Regra salva. Alterações valem apenas para novos eventos de comissão.');
        lc_redirect('/admin/indicacoes');
    }
    if($method==='POST'&&preg_match('~^/admin/indicacoes/(\d+)/avaliar$~D',$path,$match)){
        if(!lc_account_admin_password($admin)){
            lc_flash('Confirme sua senha e o segundo fator para liberar ou recusar comissões.','error');
            lc_redirect('/admin/indicacoes');
        }
        $decision=(string)($_POST['decision']??'');
        $db=lc_db();$db->beginTransaction();
        try{
            lc_referral_review((int)$match[1],(int)$admin['id'],$decision);
            lc_account_admin_audit((int)$admin['id'],'referral.commission.'.$decision,null,
                ['fields'=>['commission_id:'.(int)$match[1]]]);
            $db->commit();
            lc_flash('Comissão analisada e decisão registrada.');
        }catch(InvalidArgumentException $e){
            if($db->inTransaction())$db->rollBack();
            lc_flash($e->getMessage(),'error');
        }catch(Throwable $e){
            if($db->inTransaction())$db->rollBack();
            lc_flash('Não foi possível processar essa comissão.','error');
        }
        lc_redirect('/admin/indicacoes');
    }
    if($method==='GET'&&$path==='/admin/indicacoes'){
        $rules=lc_all('SELECT * FROM referral_rules ORDER BY referred_role');
        $pending=lc_all("SELECT rc.*,parent.full_name AS parent_name,
          parent.nickname AS parent_nick,child.nickname AS child_nick
          FROM referral_commissions rc
          JOIN users parent ON parent.id=rc.referrer_user_id
          JOIN users child ON child.id=rc.referred_user_id
          WHERE rc.status='pending' ORDER BY rc.id ASC LIMIT 100");
        $totals=lc_one("SELECT
          COALESCE(SUM(status='pending'),0) AS pending,
          COALESCE(SUM(IF(status='pending',amount_cents,0)),0) AS pending_cents,
          COALESCE(SUM(IF(status='approved',amount_cents,0)),0) AS approved_cents
          FROM referral_commissions")?:[];
        lc_page('Comissões de indicações',function()use($admin,$rules,$pending,$totals){?>
<div class="container lc-referral-area"><?php lc_heading('Gamificação e receita','Indicações e comissões',
  'Configure por tipo de indicado. Cadastro não gera pagamento; cada comissão depende de receita elegível e aprovação.'); ?>
<div class="ws-kpi-grid">
<div class="ws-kpi"><span>Pendentes</span><strong><?=(int)($totals['pending']??0)?></strong></div>
<div class="ws-kpi"><span>Valor em análise</span><strong><?=lc_money((int)($totals['pending_cents']??0))?></strong></div>
<div class="ws-kpi"><span>Créditos aprovados</span><strong><?=lc_money((int)($totals['approved_cents']??0))?></strong></div></div>
<div class="panel"><h2>Configurar comissões</h2><div class="lc-referral-admin-rules">
<?php foreach($rules as $rule):?><form method="post" action="/admin/indicacoes/regras" class="lc-referral-rule-form"><?=lc_csrf()?>
<h3>Indicação de <?=lc_e($rule['referred_role'])?></h3>
<input type="hidden" name="referred_role" value="<?=lc_e($rule['referred_role'])?>">
<label class="field"><span>Tipo da comissão</span><select name="mode">
<option value="fixed" <?=$rule['mode']==='fixed'?'selected':''?>>Valor fixo uma vez</option>
<option value="percent" <?=$rule['mode']==='percent'?'selected':''?>>Percentual sobre evento elegível</option>
</select></label>
<label class="field"><span>Valor fixo (R$; máximo 500,00)</span><input type="text" name="fixed_value" value="<?=lc_e(number_format($rule['mode']==='fixed'?(int)$rule['value_int']/100:0,2,',','.'))?>"></label>
<label class="field"><span>Percentual (0 a 50,00%)</span><input type="text" name="percent_value" value="<?=lc_e(number_format($rule['mode']==='percent'?(int)$rule['value_int']/100:0,2,',','.'))?>"></label>
<label class="field"><span><input type="checkbox" name="enabled" value="1" <?=(int)$rule['enabled']===1?'checked':''?>> Regra ativa</span></label>
<label class="field"><span>Senha do ADM</span><input type="password" name="admin_password" autocomplete="current-password" required></label>
<?php if(lc_mfa_enabled((int)$admin['id'])):?><label class="field"><span>Código Google Authenticator</span>
<input name="mfa_code" autocomplete="one-time-code" required></label><?php endif;?>
<button class="btn btn-sm" type="submit">Salvar regra</button></form><?php endforeach;?></div>
<p class="hint">Percentuais: clipador = parte do valor de clipe que atingiu meta semanal; criador = parte da mensalidade com recebimento confirmado pelo ADM. Valor fixo é pago somente uma vez após o primeiro evento elegível, não no cadastro.</p>
</div>
<div class="panel"><div class="panel-head"><h2>Comissões aguardando aprovação</h2><span class="tag"><?=(int)($totals['pending']??0)?></span></div>
<?php if(!$pending):?><p class="hint">Nenhuma comissão pendente.</p><?php else:?>
<div class="table-wrap"><table><thead><tr><th>Indicador</th><th>Indicado</th><th>Origem</th><th>Base</th><th>Comissão</th><th>Decisão</th></tr></thead><tbody>
<?php foreach($pending as $row):?><tr><td><?=lc_e($row['parent_name'])?><small>@<?=lc_e($row['parent_nick'])?></small></td>
<td>@<?=lc_e($row['child_nick'])?></td><td><?=lc_e($row['source_type']==='clip_credit'?'Clipe creditado':'Mensalidade confirmada')?></td>
<td><?=lc_money((int)$row['base_amount_cents'])?></td><td><?=lc_money((int)$row['amount_cents'])?></td>
<td><form method="post" action="/admin/indicacoes/<?=(int)$row['id']?>/avaliar"><?=lc_csrf()?>
<label class="field"><span>Senha do ADM</span><input type="password" name="admin_password" required autocomplete="current-password"></label>
<?php if(lc_mfa_enabled((int)$admin['id'])):?><label class="field"><span>Código 2FA</span><input name="mfa_code" autocomplete="one-time-code" required></label><?php endif;?>
<div class="form-actions"><button class="btn btn-sm" name="decision" value="approved" type="submit">Aprovar</button>
<button class="btn btn-ghost btn-sm" name="decision" value="rejected" type="submit">Recusar</button></div></form></td></tr>
<?php endforeach;?></tbody></table></div><?php endif;?>
</div></div>
<?php },'admin');
        return true;
    }
    return false;
}
