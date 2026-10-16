<?php
declare(strict_types=1);

/** B6E1: private, owner-scoped user support and read-only admin queue. */
function lc_support_categories(): array {
    return [
      'conta'=>'Minha conta','campanha'=>'Campanhas',
      'clipes'=>'Clipes e envios','financeiro'=>'Financeiro',
      'tecnico'=>'Problema técnico','outros'=>'Outros assuntos'
    ];
}
function lc_support_validate(string $subject,string $category,string $body): array {
    $subject=trim(preg_replace('/[\x00-\x1F\x7F]/u',' ',$subject)??'');
    $body=trim(str_replace(["\r\n","\r"],"\n",$body));
    if(mb_strlen($subject)<6||mb_strlen($subject)>160||
       !array_key_exists($category,lc_support_categories())||
       mb_strlen($body)<20||mb_strlen($body)>5000)
       throw new InvalidArgumentException('Preencha assunto (6–160 caracteres) e mensagem (20–5.000 caracteres).');
    return [$subject,$category,$body];
}
function lc_support_open(int $uid,string $subject,string $category,string $body): int {
    [$subject,$category,$body]=lc_support_validate($subject,$category,$body);
    $db=lc_db();$db->beginTransaction();
    try{
        // Lock user to serialize rate limit requests within the account.
        $owner=lc_one('SELECT id,status FROM users WHERE id=? FOR UPDATE',[$uid]);
        if(!$owner || $owner['status']!=='approved')
            throw new InvalidArgumentException('Conta sem acesso ao suporte.');
        $recent=(int)(lc_one("SELECT COUNT(*) AS n FROM support_tickets
             WHERE requester_id=? AND created_at>=DATE_SUB(NOW(),INTERVAL 1 HOUR)",[$uid])['n']??0);
        $active=(int)(lc_one("SELECT COUNT(*) AS n FROM support_tickets
             WHERE requester_id=? AND status<>'closed'",[$uid])['n']??0);
        if($recent>=5||$active>=20)
            throw new InvalidArgumentException('Você atingiu o limite de chamados. Aguarde ou acompanhe os protocolos existentes.');
        lc_query('INSERT INTO support_tickets(requester_id,subject,category) VALUES(?,?,?)',[$uid,$subject,$category]);
        $id=(int)$db->lastInsertId();
        lc_query('INSERT INTO support_messages(ticket_id,author_id,body) VALUES(?,?,?)',[$id,$uid,$body]);
        lc_realtime_emit('admin',null,'support.ticket.created','support_ticket',$id);
        $db->commit();return $id;
    }catch(Throwable $e){
        if($db->inTransaction())$db->rollBack();
        throw $e;
    }
}
function lc_support_get(int $id,array $viewer): ?array {
    if($id<1)return null;
    // Never fetch another user's ticket body for a non-admin.
    if($viewer['role']==='admin'){
        return lc_one("SELECT t.*,u.nickname,u.full_name,u.email FROM support_tickets t
             JOIN users u ON u.id=t.requester_id WHERE t.id=?",[$id]);
    }
    return lc_one("SELECT t.* FROM support_tickets t
        WHERE t.id=? AND t.requester_id=?",[$id,(int)$viewer['id']]);
}
function lc_support_state_label(string $status): string {
    return ['open'=>'Aberto','in_progress'=>'Em atendimento',
      'answered'=>'Respondido','closed'=>'Encerrado'][$status]??'Em análise';
}
function lc_support_ticket_list(array $rows,bool $admin): void {
    if(!$rows){?><p class="hint">Nenhum chamado encontrado nesta lista.</p><?php return;}
    ?><div class="lc-ticket-table" role="list">
    <?php foreach($rows as $t):
      $id=(int)$t['id'];
      $href=$admin?'/admin/chamados/'.$id:'/suporte/'.$id;
      ?><a class="lc-ticket-row" role="listitem" href="<?=lc_e($href)?>">
      <span class="lc-ticket-number">#<?=lc_e($id)?></span>
      <span class="lc-ticket-name"><?=lc_e($t['subject'])?>
      <?php if($admin): ?><small><?=lc_e($t['nickname']?:$t['email'])?></small><?php endif; ?></span>
      <span class="lc-ticket-status"><?=lc_e(lc_support_state_label($t['status']))?></span>
      <time datetime="<?=lc_e($t['created_at'])?>"><?=lc_e(date('d/m/Y',strtotime($t['created_at'])))?></time>
      </a>
    <?php endforeach; ?></div><?php
}
function lc_support_routes(string $method,string $path): bool {
    if($path!=='/suporte' && $path!=='/admin/chamados' &&
       !preg_match('~^/(?:suporte|admin/chamados)/[1-9][0-9]{0,13}$~D',$path))return false;
    $isAdminRoute=str_starts_with($path,'/admin/chamados');
    $viewer=$isAdminRoute?lc_admin():lc_login_required();
    $adminView=$isAdminRoute;
    if($method==='POST'){
        if($path!=='/suporte'){http_response_code(405);header('Allow: GET');exit('Método não permitido.');}
        try{
            $id=lc_support_open((int)$viewer['id'],
               (string)($_POST['subject']??''),(string)($_POST['category']??''),(string)($_POST['body']??''));
            lc_flash('Seu chamado #'.$id.' foi registrado.');
            lc_redirect('/suporte/'.$id);
        }catch(InvalidArgumentException $e){
            lc_flash($e->getMessage(),'error');
        }catch(Throwable $e){
            error_log('LEAGUECUT_SUPPORT_CREATE_FAILED');
            lc_flash('Não foi possível registrar o chamado. Tente novamente.','error');
        }
        lc_redirect('/suporte');
    }
    if($method!=='GET')return false;
    if($path==='/suporte'){
        $rows=lc_all('SELECT id,subject,status,created_at FROM support_tickets
             WHERE requester_id=? ORDER BY id DESC LIMIT 100',[(int)$viewer['id']]);
        lc_page('Suporte',function()use($rows){?>
<div class="container lc-support-page">
<?php lc_heading('Central de ajuda','Suporte LeagueCut','Abra um chamado e acompanhe seus protocolos em um só lugar.'); ?>
<div class="lc-support-grid">
<section class="panel"><h2>Novo chamado</h2>
<form method="post" action="/suporte"><?=lc_csrf()?>
<label class="field"><span>Assunto</span><input name="subject" minlength="6" maxlength="160" required placeholder="Resuma sua dúvida ou problema"></label>
<label class="field"><span>Categoria</span><select name="category" required>
<?php foreach(lc_support_categories() as $value=>$label): ?><option value="<?=lc_e($value)?>"><?=lc_e($label)?></option><?php endforeach; ?></select></label>
<label class="field"><span>Mensagem</span><textarea name="body" rows="7" minlength="20" maxlength="5000" required placeholder="Explique o que aconteceu, incluindo informações necessárias para te ajudar. Não envie senhas ou códigos de acesso."></textarea></label>
<p class="hint">Não informe senhas, códigos 2FA, dados de cartão ou chaves privadas. Até 5 chamados por hora.</p>
<div class="form-actions"><button class="btn" type="submit">Abrir chamado →</button></div>
</form></section>
<section class="panel"><h2>Meus protocolos</h2>
<?php lc_support_ticket_list($rows,false); ?></section>
</div></div>
<?php },'dashboard');return true;
    }
    if($path==='/admin/chamados'){
        $filter=(string)($_GET['status']??'all');
        if(!in_array($filter,['all','open','in_progress','answered','closed'],true))$filter='all';
        $rows=$filter==='all'?
           lc_all('SELECT t.id,t.subject,t.status,t.created_at,u.nickname,u.email FROM support_tickets t JOIN users u ON u.id=t.requester_id ORDER BY FIELD(t.status,\'open\',\'in_progress\',\'answered\',\'closed\'),t.id DESC LIMIT 150'):
           lc_all('SELECT t.id,t.subject,t.status,t.created_at,u.nickname,u.email FROM support_tickets t JOIN users u ON u.id=t.requester_id WHERE t.status=? ORDER BY t.id DESC LIMIT 150',[$filter]);
        lc_page('Chamados',function()use($rows,$filter){?>
<div class="container lc-support-page">
<?php lc_heading('Atendimento','Chamados dos usuários','Acompanhe solicitações recebidas de clipadores e criadores.'); ?>
<section class="panel"><nav class="lc-ticket-filters" aria-label="Filtrar chamados">
<?php foreach(['all'=>'Todos','open'=>'Abertos','in_progress'=>'Em atendimento','answered'=>'Respondidos','closed'=>'Encerrados'] as $value=>$label):
$link='/admin/chamados'.($value==='all'?'':'?status='.$value);?>
<a href="<?=lc_e($link)?>" <?=$filter===$value?'aria-current="page"':''?>><?=lc_e($label)?></a><?php endforeach; ?>
</nav>
<?php lc_support_ticket_list($rows,true); ?>
<p class="hint">Respostas e alterações de status serão adicionadas no B6E2.</p>
</section></div><?php },'admin');return true;
    }
    $id=(int)substr($path,strrpos($path,'/')+1);
    $ticket=lc_support_get($id,$viewer);
    if(!$ticket){http_response_code(404);lc_page('Chamado não encontrado',function(){?><div class="container"><h1>Chamado não encontrado</h1><p>Confira o número do protocolo.</p></div><?php },$adminView?'admin':'dashboard');return true;}
    $messages=lc_all('SELECT m.body,m.created_at,u.nickname,u.role FROM support_messages m
        JOIN users u ON u.id=m.author_id WHERE m.ticket_id=? ORDER BY m.id ASC LIMIT 200',[$id]);
    lc_page('Chamado #'.$id,function()use($ticket,$messages,$adminView,$id){?>
<div class="container lc-support-page">
<a href="<?=$adminView?'/admin/chamados':'/suporte'?>">← Voltar aos chamados</a>
<?php lc_heading('Protocolo #'.$id,(string)$ticket['subject'],
  lc_support_state_label((string)$ticket['status']).' · '.(lc_support_categories()[$ticket['category']]??'Outros')); ?>
<section class="panel">
<h2>Histórico</h2>
<div class="lc-ticket-messages">
<?php foreach($messages as $message): ?>
<article class="lc-ticket-message">
<div class="lc-ticket-message-top"><strong><?=lc_e($message['nickname']??'Usuário')?></strong>
<time><?=lc_e(date('d/m/Y H:i',strtotime($message['created_at'])))?></time></div>
<p><?=nl2br(lc_e($message['body']))?></p>
</article>
<?php endforeach; ?></div>
<p class="hint">Acompanhamento disponível. Respostas e alterações de status entram no B6E2.</p>
</section></div>
<?php },$adminView?'admin':'dashboard');return true;
}
