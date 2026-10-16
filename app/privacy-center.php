<?php
declare(strict_types=1);

/** Privacy preferences are per browser. Necessary session/security cookies do not require opt-in. */
const LC_PRIVACY_VERSION='2026-10-16';
const LC_PRIVACY_COOKIE='lc_privacy_choice';

function lc_privacy_cookie_token(): string {
    $raw=(string)($_COOKIE[LC_PRIVACY_COOKIE]??'');
    return preg_match('/^[a-f0-9]{64}$/D',$raw)?$raw:'';
}
function lc_privacy_choice(): ?array {
    $token=lc_privacy_cookie_token();
    if($token==='')return null;
    $row=lc_one('SELECT policy_version,choice,analytics_enabled,marketing_enabled,created_at
      FROM privacy_cookie_choices WHERE visitor_hash=? ORDER BY id DESC LIMIT 1',[hash('sha256',$token)]);
    return $row && $row['policy_version']===LC_PRIVACY_VERSION?$row:null;
}
function lc_privacy_cookie_record(string $choice,bool $analytics=false,bool $marketing=false): void {
    if(!in_array($choice,['accept','reject','custom'],true))throw new InvalidArgumentException('Escolha inválida.');
    if($choice==='accept'){$analytics=true;$marketing=true;}
    if($choice==='reject'){$analytics=false;$marketing=false;}
    $token=lc_privacy_cookie_token();
    if($token==='')$token=bin2hex(random_bytes(32));
    $viewer=lc_user();
    lc_query('INSERT INTO privacy_cookie_choices
      (visitor_hash,user_id,policy_version,choice,analytics_enabled,marketing_enabled)
      VALUES(?,?,?,?,?,?)',[
        hash('sha256',$token),$viewer?(int)$viewer['id']:null,LC_PRIVACY_VERSION,
        $choice,$analytics?1:0,$marketing?1:0
      ]);
    $secure=(!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off')
      ||str_starts_with(lc_env('APP_URL','https://leaguecut.com'),'https://');
    setcookie(LC_PRIVACY_COOKIE,$token,[
      'expires'=>time()+180*86400,'path'=>'/','secure'=>$secure,
      'httponly'=>true,'samesite'=>'Lax'
    ]);
    $_COOKIE[LC_PRIVACY_COOKIE]=$token;
}
function lc_privacy_safe_return(string $path): string {
    if($path===''||$path[0]!=='/'||str_starts_with($path,'//')||
       preg_match('/[\x00-\x20\\\\]/',$path))return '/';
    $only=parse_url($path,PHP_URL_PATH);
    return is_string($only)&&preg_match('~^/[a-zA-Z0-9/_-]{0,200}$~D',$only)?$only:'/';
}
function lc_privacy_cookie_form(bool $compact=false): void {
    $saved=lc_privacy_choice();
    $back=lc_privacy_safe_return((string)($_SERVER['REQUEST_URI']??'/'));
?>
<form method="post" action="/privacidade/cookies" class="lc-cookie-form"><?=lc_csrf()?>
<input type="hidden" name="return_to" value="<?=lc_e($back)?>">
<?php if(!$compact):?>
<p>Usamos cookies necessários para login e segurança. As categorias opcionais permanecem desligadas até você autorizá-las. A recusa não bloqueia o site.</p>
<?php endif;?>
<details class="lc-cookie-details" <?=$compact?'':'data-cookie-details'?>>
<summary>Personalizar escolhas</summary>
<p><strong>Necessários:</strong> sessão, autenticação e proteção de formulários. Sempre ativos.</p>
<label><input type="checkbox" name="analytics" value="1" <?=!empty($saved['analytics_enabled'])?'checked':''?>>
 Estatísticas opcionais (nenhum rastreador de terceiros está ativado neste módulo).</label>
<label><input type="checkbox" name="marketing" value="1" <?=!empty($saved['marketing_enabled'])?'checked':''?>>
 Publicidade opcional (nenhum pixel publicitário é carregado neste módulo).</label>
<p class="lc-cookie-note">Estas preferências valem somente para as finalidades descritas nesta versão. Novos rastreadores exigirão aviso atualizado; sua escolha não ativa serviços inexistentes.</p>
<button class="btn btn-ghost btn-sm" type="submit" name="choice" value="custom">Salvar minhas escolhas</button>
</details>
<div class="lc-cookie-actions">
<button class="btn btn-ghost btn-sm" type="submit" name="choice" value="reject">Rejeitar opcionais</button>
<button class="btn btn-sm" type="submit" name="choice" value="accept">Aceitar opcionais</button>
</div>
</form>
<?php
}
function lc_privacy_banner(): void {
    $path=parse_url((string)($_SERVER['REQUEST_URI']??'/'),PHP_URL_PATH)?:'/';
    if($path==='/preferencias-de-privacidade'||lc_privacy_choice())return;
?>
<section class="lc-cookie-banner" aria-labelledby="lc-cookie-title" role="region">
 <div class="lc-cookie-header"><strong id="lc-cookie-title">Sua privacidade importa</strong>
 <a href="/politica-de-privacidade">Política de Privacidade</a></div>
 <?php lc_privacy_cookie_form(); ?>
</section>
<?php
}
function lc_privacy_request_create(array $user,string $kind,string $details): int {
    $allowed=['access','correction','deletion','portability','opposition','consent_withdrawal','information'];
    if(!in_array($kind,$allowed,true))throw new InvalidArgumentException('Tipo de solicitação inválido.');
    $details=trim(preg_replace('/[\x00-\x1F\x7F]+/u',' ',strip_tags($details))??'');
    if(mb_strlen($details)<12||mb_strlen($details)>1200)
        throw new InvalidArgumentException('Explique seu pedido em 12 a 1.200 caracteres.');
    $uid=(int)($user['id']??0);
    if($uid<1)throw new InvalidArgumentException('Conta inválida.');
    $db=lc_db();$db->beginTransaction();
    try{
        $owner=lc_one('SELECT id FROM users WHERE id=? FOR UPDATE',[$uid]);
        if(!$owner)throw new InvalidArgumentException('Conta não encontrada.');
        $recent=lc_one('SELECT id FROM privacy_subject_requests
          WHERE user_id=? AND request_kind=? AND created_at>DATE_SUB(NOW(),INTERVAL 24 HOUR)
          LIMIT 1',[$uid,$kind]);
        if($recent)throw new InvalidArgumentException('Já existe um pedido deste tipo nas últimas 24 horas.');
        lc_query('INSERT INTO privacy_subject_requests(user_id,request_kind,details) VALUES(?,?,?)',
          [$uid,$kind,$details]);
        $id=(int)$db->lastInsertId();
        lc_query("INSERT INTO privacy_request_audit(request_id,actor_id,action,status_after)
           VALUES(?,?,'created','received')",[$id,$uid]);
        $db->commit();return $id;
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
}
function lc_privacy_request_update(array $admin,int $requestId,string $status,string $response):void {
    if(($admin['role']??'')!=='admin'||($admin['status']??'')!=='approved')throw new InvalidArgumentException('Acesso restrito.');
    if(!in_array($status,['in_review','completed','denied'],true)||$requestId<1)
       throw new InvalidArgumentException('Status ou protocolo inválido.');
    $response=trim(preg_replace('/[\x00-\x1F\x7F]+/u',' ',strip_tags($response))??'');
    if($status!=='in_review'&&(mb_strlen($response)<15||mb_strlen($response)>1200))
        throw new InvalidArgumentException('A resposta final deve ter 15 a 1.200 caracteres.');
    if($status==='in_review'&&mb_strlen($response)>1200)
        throw new InvalidArgumentException('Resposta longa demais.');
    $db=lc_db();$db->beginTransaction();
    try{
        $old=lc_one('SELECT status FROM privacy_subject_requests WHERE id=? FOR UPDATE',[$requestId]);
        if(!$old)throw new InvalidArgumentException('Protocolo não encontrado.');
        if(in_array($old['status'],['completed','denied'],true))
            throw new InvalidArgumentException('O protocolo já recebeu resposta final.');
        lc_query('UPDATE privacy_subject_requests SET status=?,response_text=?,handled_by=?,
           handled_at=IF(? IN (\'completed\',\'denied\'),NOW(),NULL) WHERE id=?',
           [$status,$response?:null,(int)$admin['id'],$status,$requestId]);
        lc_query('INSERT INTO privacy_request_audit(request_id,actor_id,action,status_after) VALUES(?,?,?,?)',
          [$requestId,(int)$admin['id'],$status==='denied'?'denied':($status==='completed'?'responded':'reviewed'),$status]);
        $db->commit();
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
}
function lc_privacy_request_labels():array {
    return ['access'=>'Acesso aos dados','correction'=>'Correção','deletion'=>'Exclusão de dados',
     'portability'=>'Portabilidade','opposition'=>'Oposição','consent_withdrawal'=>'Revogar consentimento',
     'information'=>'Informações sobre tratamento'];
}
function lc_privacy_routes(string $method,string $path):bool {
    if($path==='/privacidade/cookies'){
        if($method!=='POST'){http_response_code(405);return true;}
        try{
            $kind=(string)($_POST['choice']??'');
            lc_privacy_cookie_record($kind,!empty($_POST['analytics']),!empty($_POST['marketing']));
            lc_flash('Suas escolhas de privacidade foram registradas.');
        }catch(InvalidArgumentException $e){lc_flash($e->getMessage(),'error');}
        lc_redirect(lc_privacy_safe_return((string)($_POST['return_to']??'/')));
    }
    if($path==='/preferencias-de-privacidade'&&$method==='GET'){
        $saved=lc_privacy_choice();
        lc_page('Preferências de privacidade',static function()use($saved){?>
<div class="container container-md">
<?php lc_heading('Sua privacidade','Preferências de cookies','Escolha livremente quais categorias opcionais autorizar.'); ?>
<section class="panel"><h2>Cookies e armazenamento local</h2>
<p>Cookies necessários mantêm a sessão e a segurança. Você pode rejeitar opcionais sem perder acesso.</p>
<p class="hint"><?= $saved?'Escolha registrada para este navegador. Você pode alterá-la abaixo.':'Nenhuma escolha opcional registrada neste navegador.' ?></p>
<?php lc_privacy_cookie_form(true); ?>
<p><a href="/politica-de-privacidade">Leia a política completa</a>.</p></section></div>
<?php });return true;
    }
    if($path==='/admin/privacidade'){
        $admin=lc_admin();
        if($method==='POST'){
            try{
                $raw=(string)($_POST['request_id']??'0');
                if(!preg_match('/^[1-9][0-9]{0,15}$/D',$raw))throw new InvalidArgumentException('Protocolo inválido.');
                lc_privacy_request_update($admin,(int)$raw,(string)($_POST['status']??''),
                  (string)($_POST['response_text']??''));
                lc_flash('Solicitação LGPD atualizada.');
            }catch(InvalidArgumentException $e){lc_flash($e->getMessage(),'error');}
            lc_redirect('/admin/privacidade');
        }
        if($method!=='GET'){http_response_code(405);return true;}
        $requests=lc_all("SELECT r.*,u.nickname,u.email FROM privacy_subject_requests r
          JOIN users u ON u.id=r.user_id ORDER BY FIELD(r.status,'received','in_review','completed','denied'),r.id DESC LIMIT 100");
        lc_page('Solicitações LGPD',static function()use($requests){?>
<div class="container">
<?php lc_heading('Privacidade','Solicitações LGPD','Responda pedidos dos titulares; a alteração de status não apaga dados automaticamente.'); ?>
<?php if(!$requests):?><section class="panel">Nenhum pedido recebido.</section><?php endif;?>
<?php foreach($requests as $r):?>
<section class="panel lc-privacy-request-card">
<h2>Protocolo #<?=lc_e($r['id'])?> — <?=lc_e(lc_privacy_request_labels()[$r['request_kind']]??$r['request_kind'])?></h2>
<p><strong>Conta:</strong> @<?=lc_e($r['nickname'])?> — <?=lc_e($r['email'])?> · <?=lc_e($r['created_at'])?></p>
<p><?=lc_e($r['details'])?></p>
<p class="hint">Situação: <?=lc_e($r['status'])?></p>
<?php if(in_array($r['status'],['received','in_review'],true)):?>
<form method="post" action="/admin/privacidade"><?=lc_csrf()?>
<input type="hidden" name="request_id" value="<?=lc_e($r['id'])?>">
<label class="field"><span>Resposta ao titular</span><textarea name="response_text" rows="3" maxlength="1200"><?=lc_e($r['response_text']??'')?></textarea></label>
<label class="field"><span>Estado</span><select name="status">
<option value="in_review">Em análise</option><option value="completed">Respondido / concluído</option>
<option value="denied">Negado com justificativa</option></select></label>
<button type="submit" class="btn btn-sm">Registrar andamento</button>
</form>
<?php else:?><p><strong>Resposta:</strong> <?=lc_e($r['response_text']??'')?></p><?php endif;?>
</section>
<?php endforeach;?></div>
<?php },'admin');return true;
    }
    if($path!=='/privacidade/solicitacoes')return false;
    $viewer=lc_login_required(false);
    if($method==='POST'){
        try{
            $uid=(int)$viewer['id'];
            $password=(string)($_POST['current_password']??'');
            $hash=lc_one('SELECT password_hash FROM users WHERE id=?',[$uid]);
            if(!$hash||!password_verify($password,(string)$hash['password_hash']))
                throw new InvalidArgumentException('Confirme sua senha para registrar a solicitação.');
            $id=lc_privacy_request_create($viewer,(string)($_POST['kind']??''),(string)($_POST['details']??''));
            lc_flash('Solicitação LGPD #'.$id.' recebida. Acompanhe por esta página.');
        }catch(InvalidArgumentException $e){lc_flash($e->getMessage(),'error');}
        lc_redirect('/privacidade/solicitacoes');
    }
    if($method!=='GET'){http_response_code(405);return true;}
    $rows=lc_all('SELECT id,request_kind,details,status,response_text,created_at,handled_at FROM privacy_subject_requests
      WHERE user_id=? ORDER BY id DESC LIMIT 40',[(int)$viewer['id']]);
    lc_page('Meus direitos LGPD',static function()use($rows){?>
<div class="container container-md">
<?php lc_heading('Direitos do titular','Minhas solicitações de privacidade','Peça acesso, correção, exclusão ou esclarecimentos sem enviar seus documentos por e-mail.'); ?>
<section class="panel"><h2>Novo pedido</h2>
<p>A exclusão pode ser parcial quando houver obrigações legais ou registros de transações a preservar. Nenhum pedido exclui dados automaticamente.</p>
<form method="post" action="/privacidade/solicitacoes"><?=lc_csrf()?>
<label class="field"><span>O que deseja solicitar?</span><select name="kind" required>
<?php foreach(lc_privacy_request_labels() as $key=>$label):?><option value="<?=lc_e($key)?>"><?=lc_e($label)?></option><?php endforeach;?>
</select></label>
<label class="field"><span>Descreva seu pedido (não informe senhas nem documentos)</span>
<textarea name="details" minlength="12" maxlength="1200" rows="4" required></textarea></label>
<label class="field"><span>Confirme a senha da conta</span>
<input name="current_password" type="password" autocomplete="current-password" required></label>
<button class="btn" type="submit">Registrar solicitação</button></form>
</section>
<section class="panel"><h2>Seus protocolos</h2>
<?php if(!$rows):?><p>Nenhum pedido registrado.</p><?php endif;?>
<?php foreach($rows as $r):?>
<div class="lc-privacy-request-card">
<strong>#<?=lc_e($r['id'])?> — <?=lc_e(lc_privacy_request_labels()[$r['request_kind']]??$r['request_kind'])?></strong>
<p class="hint">Enviado em <?=lc_e($r['created_at'])?> · <?=lc_e($r['status'])?></p>
<p><?=lc_e($r['details'])?></p>
<?php if($r['response_text']):?><p><strong>Resposta:</strong> <?=lc_e($r['response_text'])?></p><?php endif;?>
</div>
<?php endforeach;?></section></div>
<?php },$viewer['role']==='admin'?'admin':($viewer['role']==='criador'?'creator':'dashboard'));return true;
}
