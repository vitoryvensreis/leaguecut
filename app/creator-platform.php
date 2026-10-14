<?php
declare(strict_types=1);

/**
 * Creator = buyer of monthly clips, distinct from clipador = producer who
 * submits content to campaigns. No billing or entitlement activation here.
 */
function lc_creator_only(): array {
    $u=lc_login_required();
    if($u['role']!=='criador'){http_response_code(403);exit('Área reservada aos criadores assinantes.');}
    return $u;
}
function lc_creator_package_form(?array $package): void {
    $id=(int)($package['id']??0);
    $get=static fn(string $key,string $fallback=''):string=>(string)($package[$key]??$fallback);
    $price=number_format(((int)($package['monthly_price_cents']??0))/100,2,',','.');
    ?>
<section class="panel creator-plan-editor">
  <h2><?=$id?'Editar pacote #'.$id:'Criar pacote mensal'?></h2>
  <form method="post" action="<?=$id?'/admin/pacotes/'.$id.'/editar':'/admin/pacotes'?>"><?=lc_csrf()?>
    <div class="form-grid">
      <?php lc_field('Nome do pacote','name','text',$get('name'),true,'Ex.: Live Start'); ?>
      <label class="field"><span>Modelo de serviço</span>
        <select name="fulfillment_mode" required>
          <option value="download" <?=$get('fulfillment_mode','download')==='download'?'selected':''?>>Clipes entregues para baixar</option>
          <option value="production" <?=$get('fulfillment_mode')==='production'?'selected':''?>>Produção + postagem pela equipe</option>
        </select>
      </label>
      <div class="full"><label class="field"><span>Descrição e benefícios</span><textarea name="description" maxlength="4000" required><?=lc_e($get('description'))?></textarea></label></div>
      <?php lc_field('Quantidade de clipes por mês','monthly_clips','number',$get('monthly_clips','10'),true,'10','min="1" max="10000"'); ?>
      <?php lc_field('Valor mensal em reais','monthly_price','text',$price,true,'299,90','inputmode="decimal"'); ?>
      <label class="field"><span>Disponibilidade</span>
        <select name="status">
          <?php foreach(['draft'=>'Rascunho','active'=>'Visível no catálogo','archived'=>'Arquivado'] as $key=>$label): ?>
          <option value="<?=$key?>" <?=$get('status','draft')===$key?'selected':''?>><?=$label?></option>
          <?php endforeach;?>
        </select>
      </label>
    </div>
    <p class="hint">Este bloco permite cadastrar a oferta. A contratação, cobrança recorrente e liberação automática de clipes serão implementadas nos próximos blocos. Nenhum pagamento é processado aqui.</p>
    <div class="form-actions"><button class="btn" type="submit">Salvar pacote <?=lc_icon('check')?></button><a href="/admin/pacotes">Voltar</a></div>
  </form>
</section>
<?php
}
function lc_creator_store_package(?int $id,int $adminId): never {
    $name=lc_post('name',140);$description=lc_post('description',4000);
    $mode=lc_post('fulfillment_mode',20);$status=lc_post('status',20);
    $quantity=filter_var($_POST['monthly_clips']??null,FILTER_VALIDATE_INT);
    $priceText=trim((string)($_POST['monthly_price']??''));
    if(mb_strlen($name)<3||mb_strlen($description)<10||
       !in_array($mode,['download','production'],true)||
       !in_array($status,['draft','active','archived'],true)||
       !$quantity||$quantity<1||$quantity>10000||
       !preg_match('/^\d{1,8}(?:[,.]\d{1,2})?$/D',$priceText)){
        lc_flash('Confira nome, descrição, modelo, quantidade mensal, preço e status.','error');
        lc_redirect($id?'/admin/pacotes/'.$id.'/editar':'/admin/pacotes');
    }
    $price=lc_cents('monthly_price',-1);
    if($price<=0 || $price>999999999){
        lc_flash('Informe um preço mensal válido e maior que zero.','error');
        lc_redirect($id?'/admin/pacotes/'.$id.'/editar':'/admin/pacotes');
    }
    $db=lc_db();$db->beginTransaction();
    try{
        if($id){
            $existing=lc_one('SELECT id FROM creator_packages WHERE id=? FOR UPDATE',[$id]);
            if(!$existing)throw new RuntimeException('Pacote inexistente.');
            lc_query('UPDATE creator_packages SET name=?,description=?,fulfillment_mode=?,monthly_clips=?,monthly_price_cents=?,status=? WHERE id=?',
               [$name,$description,$mode,(int)$quantity,$price,$status,$id]);
        }else{
            lc_query('INSERT INTO creator_packages(name,slug,description,fulfillment_mode,monthly_clips,monthly_price_cents,status,created_by)
                       VALUES(?,?,?,?,?,?,?,?)',
                [$name,lc_slug($name),$description,$mode,(int)$quantity,$price,$status,$adminId]);
        }
        $db->commit();
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    lc_flash($id?'Pacote atualizado.':'Pacote criado. A contratação ainda não está disponível.');
    lc_redirect('/admin/pacotes');
}
function lc_creator_admin_routes(string $method,string $path): bool {
    if(!($path==='/admin/pacotes'||preg_match('~^/admin/pacotes/([0-9]+)/editar$~D',$path)))return false;
    $admin=lc_admin();
    if($path==='/admin/pacotes'){
        if($method==='POST')lc_creator_store_package(null,(int)$admin['id']);
        $packages=lc_all('SELECT p.*, (SELECT COUNT(*) FROM creator_subscriptions s WHERE s.package_id=p.id) AS subscriptions FROM creator_packages p ORDER BY FIELD(p.status,"active","draft","archived"),p.id DESC');
        lc_page('Pacotes mensais',function()use($packages){?>
<div class="container">
<?php lc_heading('Clipes por assinatura','Pacotes para criadores','Gerencie as ofertas de download e de produção com postagem. Os pacotes não processam pagamentos nesta etapa.'); ?>
<div class="panel"><div class="ws-module-head"><h2>Pacotes cadastrados</h2><span class="tag"><?=count($packages)?> cadastrados</span></div>
<?php if(!$packages):lc_empty('Nenhum pacote ainda','Crie uma oferta mensal usando um dos dois modelos.');else:?>
<div class="table-wrap"><table>
<thead><tr><th>Pacote</th><th>Modelo</th><th>Clipes/mês</th><th>Mensalidade</th><th>Status</th><th>Assinaturas</th><th>Gestão</th></tr></thead><tbody>
<?php foreach($packages as $p):?><tr>
<td><strong><?=lc_e($p['name'])?></strong><small><?=lc_e(mb_strimwidth($p['description'],0,105,'…'))?></small></td>
<td><?=$p['fulfillment_mode']==='download'?'Baixar e utilizar':'Produção + postagem'?></td>
<td><?=(int)$p['monthly_clips']?></td><td><?=lc_money($p['monthly_price_cents'])?></td>
<td><?=lc_e(['draft'=>'Rascunho','active'=>'Visível','archived'=>'Arquivado'][$p['status']]??$p['status'])?></td>
<td><?=(int)$p['subscriptions']?></td>
<td><a class="btn btn-ghost btn-sm" href="/admin/pacotes/<?=(int)$p['id']?>/editar">Editar</a></td>
</tr><?php endforeach;?></tbody></table></div><?php endif;?></div>
<?php lc_creator_package_form(null);?>
</div>
<?php },'admin');return true;
    }
    preg_match('~^/admin/pacotes/([0-9]+)/editar$~D',$path,$matches);
    $id=(int)$matches[1];
    if($method==='POST')lc_creator_store_package($id,(int)$admin['id']);
    $p=lc_one('SELECT * FROM creator_packages WHERE id=?',[$id]);
    if(!$p){http_response_code(404);exit('Pacote não encontrado.');}
    lc_page('Editar pacote',function()use($p){?>
<div class="container container-md"><?php lc_heading('Administração','Editar pacote',$p['name']);lc_creator_package_form($p);?></div>
<?php },'admin');return true;
}
function lc_creator_routes(string $method,string $path): bool {
    if($path==='/planos' && $method==='GET'){
        $packages=lc_all("SELECT id,name,description,fulfillment_mode,monthly_clips,monthly_price_cents FROM creator_packages WHERE status='active' ORDER BY fulfillment_mode,id DESC");
        $viewer=lc_user();
        lc_page('Planos para criadores',function()use($packages,$viewer){?>
<div class="container">
<?php lc_heading('Para quem cria lives e conteúdo','Pacotes mensais','Escolha entre receber cortes prontos para baixar ou contratar produção com postagem feita pela equipe LeagueCut.'); ?>
<div class="creator-plan-grid">
<?php if(!$packages):lc_empty('Novos planos em preparação','O LeagueCut está preparando seus pacotes de cortes para criadores.');endif;?>
<?php foreach($packages as $p): ?>
<article class="panel creator-plan-card">
  <span class="eyebrow"><?=$p['fulfillment_mode']==='download'?'Entrega de clipes':'Produção gerenciada'?></span>
  <h2><?=lc_e($p['name'])?></h2><p><?=lc_e($p['description'])?></p>
  <div class="creator-plan-price"><?=lc_money($p['monthly_price_cents'])?><small>/ mês</small></div>
  <p><strong><?=(int)$p['monthly_clips']?> clipes</strong> por mês</p>
  <p class="hint">Solicitação sem cobrança online. A equipe confirma manualmente o pagamento recebido antes de liberar o primeiro ciclo mensal.</p>
  <?php if($viewer && $viewer['role']==='criador'):?>
   <form method="post" action="/criador/pacotes/<?=(int)$p['id']?>/solicitar"><?=lc_csrf()?>
     <button class="btn" type="submit">Solicitar assinatura <?=lc_icon('arrow-right')?></button>
   </form>
  <?php elseif($viewer):?>
   <span class="hint">Para contratar, acesse uma conta do tipo criador.</span>
  <?php else:?>
   <a class="btn btn-ghost" href="/cadastrar/criador">Criar conta de criador <?=lc_icon('arrow-right')?></a>
  <?php endif;?>
</article><?php endforeach;?>
</div>
</div>
<?php },'explore');return true;
    }
    if($path==='/cadastrar/criador'){
        if(lc_user())lc_redirect('/dashboard');
        if($method==='GET'){
            lc_page('Cadastro de criador',function(){?>
<div class="auth-layout"><div class="auth-box">
<span class="eyebrow">Criador assinante</span><h1>Receba clipes da sua live.</h1>
<p>Crie seu acesso para acompanhar pacotes mensais de cortes para baixar ou contratar produção com postagem. Os pagamentos ainda não estão habilitados.</p>
<div class="panel"><form method="post" action="/cadastrar/criador"><?=lc_csrf()?>
<div class="form-grid">
<?php lc_field('Nome completo','full_name','text','',true,'Seu nome','autocomplete="name"'); ?>
<?php lc_field('E-mail','email','email','',true,'voce@exemplo.com','autocomplete="email"'); ?>
<?php lc_field('Senha (mínimo 10 caracteres)','password','password','',true,'Crie uma senha','minlength="10" autocomplete="new-password"'); ?>
<?php lc_field('WhatsApp com DDD','whatsapp','tel','',true,'85999999999'); ?>
<label class="field full"><span><input type="checkbox" name="consent_whatsapp" value="1" style="width:auto"> Quero receber comunicações operacionais sobre minhas entregas por WhatsApp.</span></label>
</div><div class="form-actions"><button class="btn" type="submit">Criar conta <?=lc_icon('arrow-right')?></button><a href="/planos">Ver pacotes</a></div>
</form></div></div></div><?php
            });return true;
        }
        $name=lc_post('full_name',160);$email=mb_strtolower(lc_post('email',190));
        $password=(string)($_POST['password']??'');
        $whatsapp=preg_replace('/\D/','',lc_post('whatsapp',24));
        if(mb_strlen($name)<3||!filter_var($email,FILTER_VALIDATE_EMAIL)||strlen($password)<10||
           strlen($password)>128||strlen($whatsapp)<10||strlen($whatsapp)>13){
            lc_flash('Confira seu nome, e-mail, WhatsApp e senha.','error');lc_redirect('/cadastrar/criador');
        }
        if(lc_one('SELECT id FROM users WHERE email=?',[$email])){
            lc_flash('Este e-mail já está cadastrado. Faça login.','error');lc_redirect('/entrar');
        }
        try{
            lc_query("INSERT INTO users(full_name,email,password_hash,whatsapp,instagram,pix_key_enc,role,status,consent_whatsapp,approved_at)
                       VALUES(?,?,?,?,? ,?,'criador','approved',?,UTC_TIMESTAMP())",
                [$name,$email,password_hash($password,PASSWORD_DEFAULT),$whatsapp,'','',
                 lc_bool('consent_whatsapp')?1:0]);
        }catch(PDOException $e){
            if($e->getCode()==='23000'){lc_flash('Este e-mail já está cadastrado.','error');lc_redirect('/entrar');}
            throw $e;
        }
        session_regenerate_id(true);
        $_SESSION['uid']=(int)lc_db()->lastInsertId();
        lc_flash('Conta de criador criada. Você já pode solicitar um pacote mensal.');
        lc_redirect('/criador');
    }
    if($path==='/criador' && $method==='GET'){
        $u=lc_creator_only();
        $packages=lc_all("SELECT * FROM creator_packages WHERE status='active' ORDER BY fulfillment_mode,id DESC LIMIT 30");
        $subscriptions=lc_all("SELECT s.*,p.name AS package_name,
                 (SELECT cy.cycle_start FROM creator_subscription_cycles cy WHERE cy.subscription_id=s.id ORDER BY cy.cycle_end DESC LIMIT 1) AS cycle_start,
                 (SELECT cy.cycle_end FROM creator_subscription_cycles cy WHERE cy.subscription_id=s.id ORDER BY cy.cycle_end DESC LIMIT 1) AS cycle_end
             FROM creator_subscriptions s JOIN creator_packages p ON p.id=s.package_id
             WHERE s.creator_id=? ORDER BY s.id DESC LIMIT 25",[(int)$u['id']]);
        foreach($subscriptions as &$sub){
            $sub['balance']=lc_creator_effective_balance((int)$sub['id'],(int)$u['id']);
        }
        unset($sub);
        lc_page('Minha área de criador',function()use($u,$packages,$subscriptions){?>
<div class="container">
<?php lc_heading('Área do criador','Seus clipes, sua marca','Solicite planos, acompanhe o pagamento e veja seus ciclos mensais. Entregas e downloads ficarão disponíveis após a etapa de distribuição.'); ?>
<div class="panel">
<h2>Minhas assinaturas</h2>
<?php if(!$subscriptions):lc_empty('Nenhuma assinatura ainda','Solicite um dos pacotes disponíveis e aguarde a confirmação de pagamento pela equipe.');else:?>
<div class="table-wrap"><table><thead><tr><th>Pacote</th><th>Modelo</th><th>Situação</th><th>Créditos mensais</th><th>Período pago</th><th>Ações</th></tr></thead><tbody>
<?php foreach($subscriptions as $s):?><tr>
<td><?=lc_e($s['package_name'])?></td>
<td><?=$s['fulfillment_mode_snapshot']==='download'?'Download':'Produção + postagem'?></td>
<td><span class="tag"><?=lc_e(lc_creator_subscription_label($s))?></span></td>
<td><?php if($s['balance']):?><strong><?=(int)$s['balance']['remaining']?></strong> / <?=(int)$s['balance']['clips_included']?> clipes
<?php else:?>Não liberados<?php endif;?></td>
<td><?php if($s['balance']):?><?=date('d/m/Y',strtotime($s['balance']['cycle_start']))?> — <?=date('d/m/Y',strtotime($s['balance']['cycle_end']))?>
<small>Data final exclusiva</small><?php else:?>Aguardando confirmação de recebimento<?php endif;?></td>
<td><?php if(in_array($s['status'],['pending','active','past_due'],true)):?>
<form method="post" action="/criador/assinaturas/<?=(int)$s['id']?>/cancelar" onsubmit="return confirm('Cancelar assinatura? O período pago ficará disponível até o fim do ciclo.');">
<?=lc_csrf()?><button class="btn btn-ghost btn-sm" type="submit">Cancelar</button>
</form>
<?php else:?>—<?php endif;?></td>
</tr><?php endforeach;?>
</tbody></table></div><?php endif;?>
</div>
<h2>Pacotes disponíveis</h2>
<div class="creator-plan-grid"><?php foreach($packages as $p):?>
<article class="panel creator-plan-card">
<span class="eyebrow"><?=$p['fulfillment_mode']==='download'?'Baixar clipes':'Produção e postagem'?></span>
<h3><?=lc_e($p['name'])?></h3><p><?=lc_e($p['description'])?></p>
<div class="creator-plan-price"><?=lc_money($p['monthly_price_cents'])?><small>/ mês</small></div>
<p><?=(int)$p['monthly_clips']?> clipes mensais</p>
<form method="post" action="/criador/pacotes/<?=(int)$p['id']?>/solicitar"><?=lc_csrf()?>
<button type="submit" class="btn btn-ghost btn-sm">Solicitar assinatura <?=lc_icon('arrow-right')?></button>
</form>
<p class="hint">Sem cobrança no site. A ativação depende de confirmação manual do recebimento.</p>
</article><?php endforeach;?></div>
<?php if(!$packages):lc_empty('Pacotes em preparação','A equipe ainda está configurando as ofertas mensais.');endif;?>
</div>
<?php },'creator');return true;
    }
    return false;
}
