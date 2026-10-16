<?php
declare(strict_types=1);

/** B7A: user-owned persistent devices; no raw token or client fingerprint displayed. */
function lc_devices_current_selector(): string {
    $cookie=(string)($_COOKIE[LC_REMEMBER_COOKIE]??'');
    return preg_match('/^([a-f0-9]{24})\.[a-f0-9]{64}$/D',$cookie,$m)?$m[1]:'';
}
function lc_devices_for_user(int $uid): array {
    return lc_all('SELECT id,selector,created_at,last_used_at,expires_at FROM account_remember_tokens
      WHERE user_id=? AND expires_at>NOW() ORDER BY id DESC LIMIT 8',[$uid]);
}
function lc_devices_revoke(int $uid,int $deviceId): bool {
    if($uid<1||$deviceId<1)throw new InvalidArgumentException('Dispositivo inválido.');
    $row=lc_one('SELECT selector FROM account_remember_tokens WHERE id=? AND user_id=?',[$deviceId,$uid]);
    if(!$row)return false;
    lc_query('DELETE FROM account_remember_tokens WHERE id=? AND user_id=?',[$deviceId,$uid]);
    if($row['selector']===lc_devices_current_selector())lc_remember_cookie('',time()-3600);
    return true;
}
function lc_devices_revoke_others(int $uid): int {
    if($uid<1)throw new InvalidArgumentException('Conta inválida.');
    $current=lc_devices_current_selector();
    if($current!==''){
        $stmt=lc_query('DELETE FROM account_remember_tokens WHERE user_id=? AND selector<>?',[$uid,$current]);
    }else{
        $stmt=lc_query('DELETE FROM account_remember_tokens WHERE user_id=?',[$uid]);
    }
    return $stmt->rowCount();
}
function lc_devices_routes(string $method,string $path): bool {
    if($path!=='/seguranca/dispositivos'&&$path!=='/seguranca/dispositivos/revogar'&&
       $path!=='/seguranca/dispositivos/revogar-outros')return false;
    $user=lc_login_required(false);
    $uid=(int)$user['id'];
    if($method==='POST'){
        try {
            if($path==='/seguranca/dispositivos/revogar'){
                $input=(string)($_POST['device_id']??'');
                if(!preg_match('/^[1-9][0-9]{0,14}$/D',$input))throw new InvalidArgumentException('Dispositivo inválido.');
                $ok=lc_devices_revoke($uid,(int)$input);
                lc_flash($ok?'Dispositivo revogado.':'Dispositivo não encontrado.');
            }elseif($path==='/seguranca/dispositivos/revogar-outros'){
                $count=lc_devices_revoke_others($uid);
                lc_flash($count.' dispositivo(s) adicional(is) revogado(s).');
            }else {http_response_code(405);return true;}
        }catch(InvalidArgumentException $e){lc_flash($e->getMessage(),'error');}
        lc_redirect('/seguranca/dispositivos');
    }
    if($path!=='/seguranca/dispositivos'||$method!=='GET'){
        http_response_code(405);header('Allow: GET, POST');return true;
    }
    $items=lc_devices_for_user($uid);
    $current=lc_devices_current_selector();
    lc_page('Dispositivos conectados',static function()use($items,$current){?>
<div class="container container-md" style="max-width:900px">
<?php lc_heading('Segurança da conta','Dispositivos conectados','Gerencie navegadores que podem lembrar seu login por até 30 dias.'); ?>
<section class="panel">
<h2>Seus dispositivos com “manter logado”</h2>
<p class="hint">Revogar impede novos logins automáticos pelo dispositivo. Sessões já abertas permanecem até o próximo logout ou expiração; para invalidar todas, altere sua senha.</p>
<?php if(!$items): ?><p>Nenhum dispositivo com login persistente ativo.</p><?php else: ?>
<div class="lc-ticket-table" role="list">
<?php foreach($items as $item): $self=hash_equals((string)$item['selector'],$current); ?>
<div class="lc-ticket-row" role="listitem" style="grid-template-columns: minmax(0,1fr) auto">
<div><strong>Dispositivo #<?=lc_e($item['id'])?></strong> <?=$self?'<strong>(este navegador)</strong>':''?>
<div class="hint">Ativado em <?=lc_e(date('d/m/Y H:i',strtotime($item['created_at'])))?> · Último uso:
<?=lc_e($item['last_used_at']?date('d/m/Y H:i',strtotime($item['last_used_at'])):'Ainda não utilizado')?></div>
<div class="hint">Expira em <?=lc_e(date('d/m/Y H:i',strtotime($item['expires_at'])))?></div></div>
<form method="post" action="/seguranca/dispositivos/revogar"><?=lc_csrf()?>
<input type="hidden" name="device_id" value="<?=lc_e($item['id'])?>">
<button class="btn btn-ghost" type="submit" aria-label="Revogar dispositivo <?=lc_e($item['id'])?>">Revogar</button></form>
</div>
<?php endforeach; ?></div>
<form method="post" action="/seguranca/dispositivos/revogar-outros" style="margin-top:20px">
<?=lc_csrf()?><button class="btn btn-ghost" type="submit">Revogar outros dispositivos</button>
</form>
<?php endif; ?>
</section>
</div>
<?php },$user['role']==='admin'?'admin':($user['role']==='criador'?'creator':'dashboard'));
    return true;
}
