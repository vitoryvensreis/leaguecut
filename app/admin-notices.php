<?php
declare(strict_types=1);

/** B7B: a single authorized recipient per realtime event, never a public scope. */
function lc_notice_send(array $admin,string $audience,array $requested,string $title,string $body): int {
    if(($admin['role']??'')!=='admin'||($admin['status']??'')!=='approved')throw new InvalidArgumentException('Acesso negado.');
    if(!in_array($audience,['all','selected','clipador','criador'],true))throw new InvalidArgumentException('Público inválido.');
    $title=trim(preg_replace('/[\x00-\x1F\x7F]/u',' ', $title)??'');
    $body=trim(str_replace(["\r\n","\r"],"\n",$body));
    if(mb_strlen($title)<5||mb_strlen($title)>110||mb_strlen($body)<10||mb_strlen($body)>480)
        throw new InvalidArgumentException('Título entre 5 e 110 caracteres e mensagem entre 10 e 480 caracteres.');
    $requested=array_values(array_unique(array_map('intval',$requested)));
    if($audience==='selected'&&(count($requested)<1||count($requested)>30||min($requested)<1))
        throw new InvalidArgumentException('Escolha de 1 a 30 usuários válidos.');
    $db=lc_db();$db->beginTransaction();
    try {
        $operator=lc_one('SELECT id,role,status FROM users WHERE id=? FOR UPDATE',[(int)$admin['id']]);
        if(!$operator||$operator['role']!=='admin'||$operator['status']!=='approved')
            throw new InvalidArgumentException('Operador sem autorização.');
        // Operator row lock serializes submissions, making the throttle race-safe.
        $last=lc_one("SELECT id FROM realtime_events WHERE event_type='notice.direct'
          AND entity_type='admin_notice' AND created_at>=DATE_SUB(NOW(), INTERVAL 2 MINUTE)
          AND JSON_UNQUOTE(JSON_EXTRACT(payload_json,'$.sender_id'))=? LIMIT 1",[(string)$admin['id']]);
        if($last)throw new InvalidArgumentException('Aguarde dois minutos para enviar outra comunicação.');
        if($audience==='selected'){
            $marks=implode(',',array_fill(0,count($requested),'?'));
            $receivers=lc_all("SELECT id FROM users WHERE status='approved' AND id IN ($marks)",$requested);
            if(count($receivers)!==count($requested))throw new InvalidArgumentException('Um destinatário não existe ou não está aprovado.');
            $ids=array_map(static fn($u)=>(int)$u['id'],$receivers);
        }else{
            $where=$audience==='all'?"status='approved'":"status='approved' AND role=?";
            $params=$audience==='all'?[]:[$audience];
            $count=(int)(lc_one("SELECT COUNT(*) AS n FROM users WHERE $where",$params)['n']??0);
            if($count<1||$count>5000)throw new InvalidArgumentException('Público vazio ou maior que o limite de 5.000 contas.');
            $ids=array_map(static fn($u)=>(int)$u['id'],lc_all("SELECT id FROM users WHERE $where ORDER BY id ASC",$params));
        }
        $extra=['title'=>$title,'message'=>$body,'sender_id'=>(int)$admin['id']];
        foreach($ids as $recipient)lc_realtime_emit('user',$recipient,'notice.direct','admin_notice',null,$extra);
        $db->commit();return count($ids);
    }catch(Throwable $e){
        if($db->inTransaction())$db->rollBack();
        throw $e;
    }
}
function lc_admin_notice_routes(string $method,string $path): bool {
    if($path!=='/admin/notificacoes')return false;
    $admin=lc_admin();
    if($method==='POST'){
        try {
            $password=(string)($_POST['password']??'');
            $row=lc_one('SELECT password_hash FROM users WHERE id=?',[(int)$admin['id']]);
            if(!$row||!password_verify($password,(string)$row['password_hash']))
                throw new InvalidArgumentException('Confirme sua senha de administrador para enviar.');
            $raw=preg_split('/[\s,;]+/',trim((string)($_POST['user_ids']??'')), -1,PREG_SPLIT_NO_EMPTY)?:[];
            if(count($raw)>30)throw new InvalidArgumentException('Selecione até 30 contas individualmente.');
            foreach($raw as $id)if(!preg_match('/^[1-9][0-9]{0,12}$/D',$id))
                throw new InvalidArgumentException('IDs de usuários inválidos.');
            $count=lc_notice_send($admin,(string)($_POST['audience']??''),$raw,
                (string)($_POST['title']??''),(string)($_POST['message']??''));
            lc_flash("Aviso registrado para $count destinatário(s). Push depende de permissão e configuração VAPID.");
        }catch(InvalidArgumentException $e){lc_flash($e->getMessage(),'error');}
        catch(Throwable $e){error_log('LEAGUECUT_ADMIN_NOTICE_FAILED');lc_flash('Envio não concluído.','error');}
        lc_redirect('/admin/notificacoes');
    }
    if($method!=='GET'){http_response_code(405);return true;}
    $q=mb_substr(trim((string)($_GET['q']??'')),0,50);
    if($q!==''){
        $like='%'.str_replace(['!','%','_'],['!!','!%','!_'],$q).'%';
        $users=lc_all("SELECT id,nickname,email,role FROM users WHERE status='approved'
             AND (nickname LIKE ? ESCAPE '!' OR email LIKE ? ESCAPE '!')
             ORDER BY id DESC LIMIT 60",[$like,$like]);
    }else{
        $users=lc_all("SELECT id,nickname,email,role FROM users WHERE status='approved' ORDER BY id DESC LIMIT 60");
    }
    $sent=lc_all("SELECT e.created_at,e.payload_json,u.nickname
      FROM realtime_events e JOIN users u ON u.id=e.user_id WHERE e.event_type='notice.direct'
      AND e.entity_type='admin_notice' ORDER BY e.id DESC LIMIT 10");
    lc_page('Envio de notificações',static function()use($users,$q,$sent){?>
<div class="container container-md" style="max-width:1000px">
<?php lc_heading('Comunicação','Notificações direcionadas','Envie uma comunicação para contas específicas, perfis ou toda a plataforma.'); ?>
<section class="panel"><h2>Preparar aviso</h2>
<p class="hint">Cada destinatário recebe seu próprio evento privado. Nenhum aviso é publicado como evento público. Nunca inclua senhas, Pix ou informações pessoais de terceiros.</p>
<form method="post" action="/admin/notificacoes"><?=lc_csrf()?>
<div class="form-grid">
<label class="field"><span>Destinatários</span><select name="audience" required>
<option value="selected">Usuários específicos (IDs abaixo)</option>
<option value="all">Todos os usuários aprovados</option>
<option value="clipador">Todos os clipadores aprovados</option>
<option value="criador">Todos os criadores aprovados</option>
</select></label>
<label class="field"><span>IDs (somente para usuários específicos, separados por vírgula)</span>
<input name="user_ids" maxlength="450" placeholder="Ex.: 12, 24, 58" autocomplete="off"></label>
<label class="field"><span>Título do aviso</span><input name="title" minlength="5" maxlength="110" required></label>
<label class="field full"><span>Mensagem</span><textarea name="message" rows="4" minlength="10" maxlength="480" required></textarea></label>
<label class="field"><span>Confirme sua senha ADM</span><input name="password" type="password" autocomplete="current-password" required></label>
</div><button type="submit" class="btn">Enviar notificação</button>
</form></section>
<section class="panel"><h2>Localizar destinatários</h2>
<form method="get" action="/admin/notificacoes"><label class="field"><span>Pesquisar nickname ou e-mail</span>
<input name="q" value="<?=lc_e($q)?>" maxlength="50" placeholder="Buscar usuários"></label>
<button class="btn btn-ghost" type="submit">Pesquisar</button></form>
<div class="lc-ticket-table" style="margin-top:14px">
<?php foreach($users as $u): ?><div class="lc-ticket-row" style="grid-template-columns:80px minmax(0,1fr) 110px">
<strong>#<?=lc_e($u['id'])?></strong><span><?=lc_e($u['nickname']??'Conta')?> <small><?=lc_e($u['email'])?></small></span>
<span><?=lc_e($u['role'])?></span></div><?php endforeach; ?></div>
</section>
<section class="panel"><h2>Últimos avisos registrados</h2>
<?php foreach($sent as $item): $data=json_decode((string)$item['payload_json'],true)?:[]; ?>
<p><strong><?=lc_e($data['title']??'Aviso')?></strong> · para @<?=lc_e($item['nickname']??'usuário')?> · <?=lc_e($item['created_at'])?></p>
<?php endforeach; ?>
</section></div>
<?php },'admin');
    return true;
}
