<?php
declare(strict_types=1);
/**
 * Production-only, master-controlled Pix verification console.
 * Does not modify creator credits, campaign balances or clipper withdrawals.
 * A single attempt is persisted before each potentially money-moving API call.
 */
function lc_asaas_test_live_enabled(): bool {
    return lc_asaas_env()==='production'
      && lc_env('ASAAS_LIVE_TEST_ENABLED','0')==='1'
      && str_starts_with(lc_asaas_api_key(),'$aact_prod_');
}
function lc_asaas_test_amount(string $raw): int {
    if(!preg_match('/^(?:[1-9][0-9]{0,2})(?:[,.][0-9]{1,2})?$/D',trim($raw)))
        throw new InvalidArgumentException('Digite um valor em reais, como 5,00.');
    $parts=explode('.',str_replace(',','.',trim($raw)));
    $cents=((int)$parts[0])*100+(int)str_pad($parts[1]??'00',2,'0');
    if($cents<100 || $cents>5000)throw new InvalidArgumentException('Use um valor de R$ 1,00 até R$ 50,00 por teste.');
    return $cents;
}
function lc_asaas_test_pix_key(string $raw,string $type): string {
    $key=trim($raw);
    switch($type){
        case 'CPF':
        case 'CNPJ':
            $key=preg_replace('/\D/','',$key)??'';
            if(strlen($key)!==($type==='CPF'?11:14))throw new InvalidArgumentException('Documento Pix inválido.');
            break;
        case 'EMAIL':
            if(!filter_var($key,FILTER_VALIDATE_EMAIL)||strlen($key)>150)throw new InvalidArgumentException('E-mail Pix inválido.');
            break;
        case 'PHONE':
            $key=preg_replace('/\D/','',$key)??'';
            if(strlen($key)!==11)throw new InvalidArgumentException('Telefone Pix com DDD deve ter 11 dígitos.');
            break;
        case 'EVP':
            if(!preg_match('/^[a-f0-9-]{36}$/iD',$key))throw new InvalidArgumentException('Chave aleatória Pix inválida.');
            break;
        default: throw new InvalidArgumentException('Selecione o tipo da chave Pix.');
    }
    return $key;
}
function lc_asaas_test_mask_key(string $key,string $type): string {
    if($type==='EMAIL'){
        [$prefix,$domain]=explode('@',$key,2);
        return mb_substr(substr($prefix,0,2).'***@'.$domain,0,85);
    }
    return substr($key,0,2).'••••'.substr($key,-4);
}
function lc_asaas_test_reference(string $flow): string {
    return 'leaguecut-pix-test-'.$flow.'-'.bin2hex(random_bytes(11));
}
/** Explicitly enumerated API endpoints. Legacy production writes stay blocked. */
function lc_asaas_test_api(string $method,string $path,?array $payload=null): array {
    if(lc_asaas_env()!=='production'||!str_starts_with(lc_asaas_api_key(),'$aact_prod_'))
        throw new RuntimeException('Configure uma API Key válida do Asaas de produção.');
    $writes=['/customers','/payments','/transfers'];
    if($method==='POST'){
        if(!lc_asaas_test_live_enabled()||!in_array($path,$writes,true))
            throw new RuntimeException('Testes financeiros reais desativados no servidor.');
    }elseif($method==='GET'){
        if($payload!==null||!preg_match('~^/(?:payments/pay_[A-Za-z0-9]+(?:/pixQrCode)?|transfers/[A-Za-z0-9_-]{3,100})$~D',$path))
            throw new InvalidArgumentException('Recurso de leitura não permitido.');
    }else throw new InvalidArgumentException('Método inválido.');
    if(!function_exists('curl_init'))throw new RuntimeException('PHP cURL indisponível.');
    $ch=curl_init('https://api.asaas.com/v3'.$path);
    $headers=['User-Agent: LeagueCut/1.0','Accept: application/json','access_token: '.lc_asaas_api_key()];
    if($payload!==null)$headers[]='Content-Type: application/json';
    curl_setopt_array($ch,[
      CURLOPT_RETURNTRANSFER=>true,CURLOPT_CUSTOMREQUEST=>$method,
      CURLOPT_HTTPHEADER=>$headers,CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_TIMEOUT=>23,
      CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,
      CURLOPT_FOLLOWLOCATION=>false,CURLOPT_MAXREDIRS=>0,
    ]);
    if($payload!==null)curl_setopt($ch,CURLOPT_POSTFIELDS,json_encode($payload,JSON_THROW_ON_ERROR));
    try{
        $response=curl_exec($ch);
        $status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);
        if($response===false)throw new RuntimeException('Resposta incerta do Asaas; confira o histórico antes de qualquer nova operação.');
    }finally{curl_close($ch);}
    $json=json_decode((string)$response,true);
    if($status<200||$status>=300||!is_array($json))
        throw new RuntimeException('Asaas retornou HTTP '.$status.'; confira a operação no painel do Asaas.');
    return $json;
}
function lc_asaas_test_master(): array {
    $actor=lc_admin();
    lc_admin_require_master($actor);
    return $actor;
}
function lc_asaas_test_create_charge(int $uid,string $token,int $cents,string $document): int {
    if(!lc_asaas_test_live_enabled())throw new RuntimeException('O modo de testes reais ainda está desativado.');
    $doc=preg_replace('/\D/','',$document)??'';
    if(!in_array(strlen($doc),[11,14],true))throw new InvalidArgumentException('Informe seu CPF/CNPJ de pagador.');
    if(!preg_match('/^[a-f0-9]{32}$/D',$token))throw new InvalidArgumentException('Atualize a página para iniciar um novo teste.');
    $previous=lc_one('SELECT id FROM asaas_pix_tests WHERE request_token=?',[$token]);
    if($previous){
        $same=lc_one('SELECT master_user_id FROM asaas_pix_tests WHERE id=?',[$previous['id']]);
        if((int)($same['master_user_id']??0)!==$uid)throw new InvalidArgumentException('Operação inválida.');
        return (int)$previous['id'];
    }
    $ref=lc_asaas_test_reference('charge');
    try{
        lc_query("INSERT INTO asaas_pix_tests(master_user_id,flow,amount_cents,request_token,external_reference,status)
                  VALUES(?,'charge',?,?,?,'creating')",[$uid,$cents,$token,$ref]);
    }catch(PDOException $e){
        $same=lc_one('SELECT id,master_user_id FROM asaas_pix_tests WHERE request_token=?',[$token]);
        if($same&&(int)$same['master_user_id']===$uid)return (int)$same['id'];
        throw $e;
    }
    $id=(int)lc_db()->lastInsertId();
    // The intent is permanently recorded before any remote writes. A timeout
    // must never automatically create a duplicate charge.
    try{
        $user=lc_one('SELECT id,full_name,email FROM users WHERE id=?',[$uid]);
        if(!$user)throw new RuntimeException('Conta master não encontrada.');
        $client=lc_one('SELECT provider_customer_id FROM asaas_customers WHERE user_id=? AND environment=?',[$uid,'production']);
        if($client)$customer=(string)$client['provider_customer_id'];
        else{
            $remote=lc_asaas_test_api('POST','/customers',[
                'name'=>$user['full_name'],'cpfCnpj'=>$doc,'email'=>$user['email'],
                'externalReference'=>'leaguecut-pix-test-master-'.$uid
            ]);
            $customer=(string)($remote['id']??'');
            if(!preg_match('/^cus_[A-Za-z0-9_-]+$/D',$customer))throw new RuntimeException('O cadastro de cliente precisa ser verificado no Asaas.');
            lc_query('INSERT INTO asaas_customers(user_id,environment,provider_customer_id) VALUES(?,?,?) ON DUPLICATE KEY UPDATE environment=VALUES(environment),provider_customer_id=VALUES(provider_customer_id)',[$uid,'production',$customer]);
        }
        lc_query("UPDATE asaas_pix_tests SET status='sending',attempted_at=NOW() WHERE id=?",[$id]);
        $remote=lc_asaas_test_api('POST','/payments',[
            'customer'=>$customer,'billingType'=>'PIX','value'=>$cents/100,
            'dueDate'=>(new DateTimeImmutable('today',new DateTimeZone('America/Fortaleza')))->modify('+2 days')->format('Y-m-d'),
            'description'=>'LeagueCut - teste Pix real, sem crédito na plataforma',
            'externalReference'=>$ref,
        ]);
        $providerId=(string)($remote['id']??'');
        if(!preg_match('/^pay_[A-Za-z0-9]+$/D',$providerId))
            throw new RuntimeException('Cobrança enviada; ID não confirmado. Consulte no Asaas.');
        lc_query("UPDATE asaas_pix_tests SET provider_id=?,status=? WHERE id=?",[$providerId,(string)($remote['status']??'PENDING'),$id]);
    }catch(Throwable $e){
        lc_query("UPDATE asaas_pix_tests SET status='review_required' WHERE id=? AND provider_id IS NULL",[$id]);
        throw $e;
    }
    return $id;
}
function lc_asaas_test_prepare_transfer(int $uid,string $token,int $cents,string $key,string $type): int {
    if(!preg_match('/^[a-f0-9]{32}$/D',$token))throw new InvalidArgumentException('Atualize a página para um novo teste.');
    $prior=lc_one('SELECT id,master_user_id FROM asaas_pix_tests WHERE request_token=?',[$token]);
    if($prior){
        if((int)$prior['master_user_id']!==$uid)throw new InvalidArgumentException('Solicitação não pertence a esta conta.');
        return (int)$prior['id'];
    }
    $key=lc_asaas_test_pix_key($key,$type);
    $reference=lc_asaas_test_reference('transfer');
    lc_query("INSERT INTO asaas_pix_tests
              (master_user_id,flow,amount_cents,request_token,external_reference,status,recipient_key_encrypted,recipient_masked,recipient_type)
              VALUES(?,'transfer',?,?,?,'prepared',?,?,?)",
              [$uid,$cents,$token,$reference,lc_encrypt($key),lc_asaas_test_mask_key($key,$type),$type]);
    return (int)lc_db()->lastInsertId();
}
function lc_asaas_test_send_transfer(int $uid,int $testId): void {
    if(!lc_asaas_test_live_enabled())throw new RuntimeException('Transferências reais de teste desativadas no servidor.');
    $db=lc_db();$db->beginTransaction();
    try{
        $row=lc_one("SELECT * FROM asaas_pix_tests WHERE id=? AND master_user_id=? AND flow='transfer' FOR UPDATE",[$testId,$uid]);
        if(!$row||$row['status']!=='prepared')throw new InvalidArgumentException('Solicitação já utilizada ou não encontrada.');
        // Constrain cumulative attempted transfers. Failed/unknown requests
        // also count against this limit to prevent unsafe retries.
        $usage=lc_one("SELECT COALESCE(SUM(amount_cents),0) AS cents,COUNT(*) AS qty
             FROM asaas_pix_tests WHERE flow='transfer' AND status<>'prepared'
               AND created_at>=CURRENT_DATE() AND created_at<CURRENT_DATE()+INTERVAL 1 DAY");
        if((int)$usage['cents']+(int)$row['amount_cents']>10000||(int)$usage['qty']>=5)
            throw new InvalidArgumentException('Limite de teste: R$ 100,00 e 5 transferências por dia.');
        $db->prepare("UPDATE asaas_pix_tests SET status='sending',attempted_at=NOW() WHERE id=?")
           ->execute([$testId]);
        $db->commit();
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    // This call is ONE SHOT. On network failure or HTTP error we retain
    // the intent for manual investigation; never automatically retry.
    try{
        $remote=lc_asaas_test_api('POST','/transfers',[
          'value'=>(int)$row['amount_cents']/100,
          'pixAddressKey'=>lc_decrypt((string)$row['recipient_key_encrypted']),
          'pixAddressKeyType'=>$row['recipient_type'],
          'description'=>'LeagueCut - teste controlado de Pix',
          'externalReference'=>$row['external_reference'],
        ]);
        $providerId=(string)($remote['id']??'');
        if($providerId===''||strlen($providerId)>100)
            throw new RuntimeException('Resposta da transferência precisa de verificação no painel.');
        lc_query("UPDATE asaas_pix_tests SET provider_id=?,status=? WHERE id=?",
          [$providerId,(string)($remote['status']??'PENDING'),$testId]);
    }catch(Throwable $e){
        lc_query("UPDATE asaas_pix_tests SET status='review_required' WHERE id=? AND provider_id IS NULL",[$testId]);
        throw $e;
    }
}
function lc_asaas_test_sync(int $uid,int $id): string {
    $row=lc_one('SELECT * FROM asaas_pix_tests WHERE id=? AND master_user_id=?',[$id,$uid]);
    if(!$row)throw new InvalidArgumentException('Operação não encontrada.');
    if(!$row['provider_id'])return 'Identificador do Asaas ainda não confirmado. Confira pelo painel Asaas.';
    $path=$row['flow']==='charge'?'/payments/':'/transfers/';
    $remote=lc_asaas_test_api('GET',$path.$row['provider_id']);
    if(!hash_equals((string)$row['provider_id'],(string)($remote['id']??''))||
       !hash_equals((string)$row['external_reference'],(string)($remote['externalReference']??''))||
       abs((float)($remote['value']??0)*100-(int)$row['amount_cents'])>0.01)
        throw new RuntimeException('Dados divergentes. Nenhuma alteração financeira realizada.');
    $status=(string)($remote['status']??'');
    if(!preg_match('/^[A-Z_]{3,36}$/D',$status))throw new RuntimeException('Status inesperado.');
    lc_query('UPDATE asaas_pix_tests SET status=? WHERE id=?',[$status,$id]);
    return 'Status atualizado diretamente pelo Asaas: '.$status.'.';
}
function lc_asaas_test_qr(array $row): array {
    if($row['flow']!=='charge'||!$row['provider_id'])return [];
    $remote=lc_asaas_test_api('GET','/payments/'.$row['provider_id'].'/pixQrCode');
    $encoded=(string)($remote['encodedImage']??'');
    $raw=base64_decode($encoded,true);
    $payload=(string)($remote['payload']??'');
    if(!$raw||strlen($raw)>200000||!str_starts_with($raw,"\x89PNG\r\n\x1a\n")||
       strlen($payload)>1500)return [];
    return ['image'=>$encoded,'payload'=>$payload,'expiration'=>$remote['expirationDate']??null];
}
function lc_asaas_test_routes(string $method,string $path): bool {
    if($path!=='/admin/asaas/testes' &&
       !preg_match('~^/admin/asaas/testes/(?:charge|prepare-transfer|confirm-transfer|sync)/[0-9]+$~D',$path) &&
       !in_array($path,['/admin/asaas/testes/charge','/admin/asaas/testes/prepare-transfer'],true))return false;
    $actor=lc_asaas_test_master();$uid=(int)$actor['id'];
    if($method==='POST'){
        try{
            if(!lc_account_admin_password($actor))throw new InvalidArgumentException('Confirme sua senha master e 2FA.');
            if(!hash_equals('CONFIRMO',lc_post('confirm',40)))
                throw new InvalidArgumentException('Marque a confirmação explícita da operação.');
            if($path==='/admin/asaas/testes/charge'){
                $id=lc_asaas_test_create_charge($uid,lc_post('request_token',32),
                    lc_asaas_test_amount(lc_post('amount',16)),lc_post('cpf_cnpj',23));
            }elseif($path==='/admin/asaas/testes/prepare-transfer'){
                $id=lc_asaas_test_prepare_transfer($uid,lc_post('request_token',32),
                    lc_asaas_test_amount(lc_post('amount',16)),
                    lc_post('pix_key',180),lc_post('pix_key_type',10));
            }elseif(preg_match('~^/admin/asaas/testes/confirm-transfer/([0-9]+)$~D',$path,$m)){
                if(lc_post('transfer_confirmation',40)!=='ENVIAR PIX')
                    throw new InvalidArgumentException('Digite ENVIAR PIX para confirmar a transferência real.');
                lc_asaas_test_send_transfer($uid,(int)$m[1]);
                $id=(int)$m[1];
            }elseif(preg_match('~^/admin/asaas/testes/sync/([0-9]+)$~D',$path,$m)){
                $message=lc_asaas_test_sync($uid,(int)$m[1]);$id=(int)$m[1];lc_flash($message);
            }else throw new InvalidArgumentException('Operação desconhecida.');
            lc_redirect('/admin/asaas/testes?operacao='.$id);
        }catch(Throwable $e){
            // Safe messages only; Asaas API body and secrets are never returned.
            $known=$e instanceof InvalidArgumentException||$e instanceof RuntimeException;
            lc_flash($known?$e->getMessage():'Não foi possível concluir. Verifique o histórico e a conta Asaas antes de repetir.','error');
            lc_redirect('/admin/asaas/testes');
        }
    }
    if($method!=='GET'||$path!=='/admin/asaas/testes')return false;
    $history=lc_all('SELECT id,flow,amount_cents,status,provider_id,recipient_masked,recipient_type,created_at FROM asaas_pix_tests WHERE master_user_id=? ORDER BY id DESC LIMIT 20',[$uid]);
    $selectedId=(int)($_GET['operacao']??0);
    $selected=$selectedId>0?lc_one('SELECT id,flow,amount_cents,status,provider_id,recipient_masked,recipient_type FROM asaas_pix_tests WHERE id=? AND master_user_id=?',[$selectedId,$uid]):null;
    $qr=[];$qrError=false;
    if($selected&&$selected['flow']==='charge'&&$selected['provider_id']){
        try{$qr=lc_asaas_test_qr($selected);}catch(Throwable){$qrError=true;}
    }
    $enabled=lc_asaas_test_live_enabled();
    lc_page('Asaas · Testes Pix',function()use($actor,$history,$selected,$qr,$qrError,$enabled){?>
<div class="container container-md lc-asaas-tests">
<?php lc_heading('Gestão financeira / Asaas','Testes Pix','Cobrança e transferência reais, separadas das carteiras e assinaturas do LeagueCut.'); ?>
<div class="panel"><span class="tag"><?=$enabled?'PRODUÇÃO · TESTES REAIS HABILITADOS':'PRODUÇÃO · TESTES DESATIVADOS'?></span>
<p class="hint">Esta aba usa dinheiro real. Apenas o master pode operar. Limite R$ 1 a R$ 50 por operação; transferências limitadas a R$ 100 e cinco tentativas por dia. Taxas do Asaas podem ser cobradas.</p>
<?php if(!$enabled):?><p class="alert">Para liberar, adicione <code>ASAAS_LIVE_TEST_ENABLED=1</code> no .env privado da VPS. Não altere <code>ASAAS_ENABLED=0</code>; os pagamentos regulares do LeagueCut continuam bloqueados.</p><?php endif;?>
<p class="hint">O saldo da conta Asaas precisa estar disponível antes de transferir. Não tente repetir uma operação com status “em verificação”.</p></div>
<div class="lc-asaas-test-grid">
<section class="panel"><h2>Receber · Gerar QR Code Pix</h2>
<p class="hint">Cria uma cobrança avulsa real. Pague pelo aplicativo de outro banco. Informe seu CPF ou CNPJ como pagador no primeiro uso.</p>
<form method="post" action="/admin/asaas/testes/charge" autocomplete="off"><?=lc_csrf()?>
<input type="hidden" name="request_token" value="<?=bin2hex(random_bytes(16))?>">
<label class="field"><span>Valor da cobrança (R$)</span><input name="amount" inputmode="decimal" placeholder="5,00" maxlength="12" required></label>
<label class="field"><span>CPF/CNPJ do pagador</span><input name="cpf_cnpj" inputmode="numeric" autocomplete="off" maxlength="23" required></label>
<?php lc_asaas_test_auth_fields($actor); ?>
<label class="lc-asaas-test-agree"><input type="checkbox" name="confirm" value="CONFIRMO" required> Autorizo criar uma cobrança Pix REAL neste valor.</label>
<button class="btn" type="submit" <?=$enabled?'':'disabled'?>>Gerar QR Code real</button></form></section>
<section class="panel"><h2>Enviar · Pix para chave</h2>
<p class="hint">A transferência terá uma tela separada de revisão. Digite uma chave Pix sua para receber em outro banco.</p>
<form method="post" action="/admin/asaas/testes/prepare-transfer" autocomplete="off"><?=lc_csrf()?>
<input type="hidden" name="request_token" value="<?=bin2hex(random_bytes(16))?>">
<label class="field"><span>Valor de envio (R$)</span><input name="amount" inputmode="decimal" placeholder="5,00" maxlength="12" required></label>
<label class="field"><span>Tipo da chave</span><select name="pix_key_type" required>
<option value="CPF">CPF</option><option value="CNPJ">CNPJ</option><option value="EMAIL">E-mail</option><option value="PHONE">Telefone</option><option value="EVP">Chave aleatória</option></select></label>
<label class="field"><span>Chave Pix de destino</span><input name="pix_key" maxlength="180" autocomplete="off" required></label>
<?php lc_asaas_test_auth_fields($actor); ?>
<label class="lc-asaas-test-agree"><input type="checkbox" name="confirm" value="CONFIRMO" required> Quero preparar um Pix REAL (nenhum envio nesta etapa).</label>
<button class="btn btn-ghost" type="submit">Revisar transferência</button></form></section>
</div>
<?php if($selected):?>
<section class="panel"><h2>Operação #<?=(int)$selected['id']?></h2>
<p><strong><?=$selected['flow']==='charge'?'Cobrança Pix':'Transferência Pix'?></strong> · <?=lc_money((int)$selected['amount_cents'])?> · <span class="tag"><?=lc_e($selected['status'])?></span></p>
<?php if($selected['flow']==='charge'&&$qr):?>
<p>Escaneie com o celular de outro banco ou copie o código Pix:</p>
<img class="lc-asaas-qr" width="260" height="260" alt="QR Code Pix desta cobrança" src="data:image/png;base64,<?=lc_e($qr['image'])?>">
<label class="field"><span>Pix copia e cola</span><textarea readonly rows="4" id="asaas-test-payload"><?=lc_e($qr['payload'])?></textarea></label>
<button type="button" class="btn btn-ghost btn-sm" data-copy-pix>Copiar código Pix</button>
<?php elseif($selected['flow']==='charge'):?><p class="hint"><?=$qrError?'QR Code indisponível no momento. Atualize a página.':'A cobrança está em verificação. Confira no painel Asaas antes de tentar criar outra.'?></p><?php endif;?>
<?php if($selected['flow']==='transfer'):?>
<p>Destino: <strong><?=lc_e($selected['recipient_masked'])?></strong> (<?=lc_e($selected['recipient_type'])?>)</p>
<?php if($selected['status']==='prepared'):?>
<form method="post" action="/admin/asaas/testes/confirm-transfer/<?=(int)$selected['id']?>" onsubmit="return confirm('Atenção: será enviado um PIX REAL de <?=lc_money((int)$selected['amount_cents'])?>. Deseja continuar?')"><?=lc_csrf()?>
<?php lc_asaas_test_auth_fields($actor); ?>
<label class="field"><span>Digite ENVIAR PIX para confirmar definitivamente</span><input name="transfer_confirmation" autocomplete="off" pattern="ENVIAR PIX" required></label>
<label class="lc-asaas-test-agree"><input type="checkbox" name="confirm" value="CONFIRMO" required> Autorizo enviar dinheiro real a esta chave.</label>
<button class="btn" <?=$enabled?'':'disabled'?> type="submit">Confirmar e enviar Pix real</button></form>
<?php else:?><p class="hint">O envio já foi tentado ou concluído. Não é possível repetir esta operação.</p><?php endif;?>
<?php endif;?>
<?php if($selected['provider_id']):?><form method="post" action="/admin/asaas/testes/sync/<?=(int)$selected['id']?>"><?=lc_csrf()?>
<?php lc_asaas_test_auth_fields($actor); ?><input type="hidden" name="confirm" value="CONFIRMO">
<button class="btn btn-ghost btn-sm" type="submit">Consultar status atualizado no Asaas</button></form><?php endif;?>
</section><?php endif;?>
<section class="panel"><h2>Histórico de testes</h2><div class="table-wrap"><table><thead><tr><th>Tipo</th><th>Valor</th><th>Status</th><th>Quando</th><th></th></tr></thead><tbody>
<?php foreach($history as $h):?><tr><td><?=$h['flow']==='charge'?'Cobrança':'Transferência'?></td>
<td><?=lc_money((int)$h['amount_cents'])?></td><td><span class="tag"><?=lc_e($h['status'])?></span></td>
<td><?=lc_e($h['created_at'])?></td><td><a href="/admin/asaas/testes?operacao=<?=(int)$h['id']?>">Ver</a></td></tr><?php endforeach;?>
</tbody></table></div><?php if(!$history):lc_empty('Nenhum teste realizado','Crie seu primeiro teste quando a liberação estiver ativa.');endif;?></section>
</div>
<script>
document.querySelector('[data-copy-pix]')?.addEventListener('click',async function(){
  const payload=document.getElementById('asaas-test-payload');
  if(!payload)return;
  try{await navigator.clipboard.writeText(payload.value);this.textContent='Pix copiado!';}
  catch(_){payload.focus();payload.select();this.textContent='Selecione e copie o código';}
});
</script>
<?php },'admin');
    return true;
}
function lc_asaas_test_auth_fields(array $actor): void {?>
<label class="field"><span>Senha do administrador master</span><input type="password" name="admin_password" autocomplete="current-password" required></label>
<?php if(lc_mfa_enabled((int)$actor['id'])):?><label class="field"><span>Código 2FA</span><input name="mfa_code" autocomplete="one-time-code" required></label><?php endif;?>
<?php }
