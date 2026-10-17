<?php
declare(strict_types=1);
/**
 * LeagueCut/Asaas phase 1: sandbox-only Pix invoices, authenticated event inbox,
 * server-verified payment reconciliation, and payout review (no transfers sent).
 *
 * Production writes are deliberately disabled until payment/refund/transfer
 * reconciliation and operational approvals have been homologated.
 */
function lc_asaas_env(): string {
    return lc_env('ASAAS_ENV','sandbox')==='production'?'production':'sandbox';
}
function lc_asaas_api_key(): string {
    return lc_env(lc_asaas_env()==='production'?'ASAAS_PRODUCTION_API_KEY':'ASAAS_SANDBOX_API_KEY');
}
function lc_asaas_enabled(): bool {
    return lc_env('ASAAS_ENABLED','0')==='1' && lc_asaas_api_key()!=='';
}
function lc_asaas_live_block(): void {
    if(lc_asaas_env()!=='sandbox')throw new RuntimeException('Operação em produção bloqueada até a homologação financeira.');
}
function lc_asaas_base_url(): string {
    return lc_asaas_env()==='production'?'https://api.asaas.com/v3':'https://api-sandbox.asaas.com/v3';
}
function lc_asaas_request(string $method,string $path,?array $body=null): array {
    if(!lc_asaas_enabled())throw new RuntimeException('Integração Asaas não configurada.');
    if(!preg_match('~^/(?:customers|payments|transfers|finance/balance)(?:/[A-Za-z0-9_-]+)?$~D',$path))
        throw new InvalidArgumentException('Recurso Asaas não autorizado.');
    if(!in_array($method,['GET','POST'],true))throw new InvalidArgumentException('Método não autorizado.');
    if($method==='POST'){
        lc_asaas_live_block();
        if(!in_array($path,['/customers','/payments'],true))
            throw new RuntimeException('Transferências reais estão desabilitadas nesta etapa.');
    }
    if($method==='GET'&&$body!==null)throw new InvalidArgumentException('GET não recebe corpo.');
    if(!function_exists('curl_init'))throw new RuntimeException('Extensão PHP cURL indisponível.');
    $ch=curl_init(lc_asaas_base_url().$path);
    $headers=['Accept: application/json','User-Agent: LeagueCut/1.0','access_token: '.lc_asaas_api_key()];
    if($body!==null)$headers[]='Content-Type: application/json';
    curl_setopt_array($ch,[
        CURLOPT_RETURNTRANSFER=>true,CURLOPT_CUSTOMREQUEST=>$method,
        CURLOPT_HTTPHEADER=>$headers,CURLOPT_CONNECTTIMEOUT=>6,CURLOPT_TIMEOUT=>20,
        CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,
        CURLOPT_FOLLOWLOCATION=>false,CURLOPT_MAXREDIRS=>0
    ]);
    if($body!==null)curl_setopt($ch,CURLOPT_POSTFIELDS,json_encode($body,JSON_THROW_ON_ERROR));
    try{
        $response=curl_exec($ch);$code=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);
        if($response===false)throw new RuntimeException('Falha na comunicação com Asaas. Confira a operação antes de tentar novamente.');
    }finally{curl_close($ch);}
    $json=json_decode((string)$response,true);
    if($code<200||$code>=300||!is_array($json))
        throw new RuntimeException('Asaas retornou HTTP '.$code.'. Confira credenciais, dados e o painel do Asaas.');
    return $json;
}
/**
 * Read-only production diagnostic. No charges, no transfers, no account balance
 * values leave this function. API key is read only from the private server env.
 */
function lc_asaas_check_connection(): array {
    $environment=lc_asaas_env();
    $key=lc_asaas_api_key();
    $token=lc_env('ASAAS_WEBHOOK_TOKEN');
    $expectedPrefix=$environment==='production'?'$aact_prod_':'$aact_hmlg_';
    $result=[
       'environment'=>$environment,
       'key_present'=>$key!=='',
       'webhook_ready'=>strlen($token)>=32 && strlen($token)<=255 && !hash_equals($key,$token),
       'operations_enabled'=>lc_env('ASAAS_ENABLED','0')==='1',
       'api_status'=>'NOT_TESTED',
       'webhook_status'=>'NOT_TESTED',
    ];
    if($key===''){$result['api_status']='KEY_MISSING';return $result;}
    if(!str_starts_with($key,$expectedPrefix)){
        $result['api_status']='KEY_ENVIRONMENT_MISMATCH';
        return $result;
    }
    if(!function_exists('curl_init')){$result['api_status']='CURL_UNAVAILABLE';return $result;}
    $ch=curl_init(lc_asaas_base_url().'/finance/balance');
    curl_setopt_array($ch,[
        CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_CUSTOMREQUEST=>'GET',
        CURLOPT_HTTPHEADER=>['Accept: application/json','User-Agent: LeagueCut/1.0','access_token: '.$key],
        CURLOPT_CONNECTTIMEOUT=>6,
        CURLOPT_TIMEOUT=>15,
        CURLOPT_SSL_VERIFYPEER=>true,
        CURLOPT_SSL_VERIFYHOST=>2,
        CURLOPT_FOLLOWLOCATION=>false,
    ]);
    try{
        $reply=curl_exec($ch);
        $code=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);
        $result['api_status']=$reply===false?'CONNECTION_FAILED':'HTTP_'.$code;
    }finally{
        curl_close($ch);
    }
    return $result;
}
function lc_asaas_check_webhook_reachability(): string {
    if(!function_exists('curl_init'))return 'CURL_UNAVAILABLE';
    // Deliberately NO authorization header. The expected app-level response
    // is 401. 403 means Cloudflare or another proxy may be blocking POSTs.
    $ch=curl_init('https://leaguecut.com/webhooks/asaas');
    curl_setopt_array($ch,[
        CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_CUSTOMREQUEST=>'POST',
        CURLOPT_POSTFIELDS=>'{}',
        CURLOPT_HTTPHEADER=>['Content-Type: application/json','User-Agent: LeagueCutWebhookCheck/1.0'],
        CURLOPT_CONNECTTIMEOUT=>6,
        CURLOPT_TIMEOUT=>15,
        CURLOPT_SSL_VERIFYPEER=>true,
        CURLOPT_SSL_VERIFYHOST=>2,
        CURLOPT_FOLLOWLOCATION=>false,
    ]);
    try{
        $reply=curl_exec($ch);
        $code=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);
        return $reply===false?'CONNECTION_FAILED':'HTTP_'.$code;
    }finally{curl_close($ch);}
}
function lc_asaas_customer_for(array $user,string $cpfCnpj): string {
    $uid=(int)$user['id'];
    $existing=lc_one('SELECT provider_customer_id,environment FROM asaas_customers WHERE user_id=?',[$uid]);
    if($existing && $existing['environment']===lc_asaas_env())return $existing['provider_customer_id'];
    $document=preg_replace('/\D/','',$cpfCnpj)??'';
    if(!in_array(strlen($document),[11,14],true))throw new InvalidArgumentException('Informe um CPF ou CNPJ válido.');
    $payload=['name'=>(string)$user['full_name'],'cpfCnpj'=>$document,
        'email'=>(string)$user['email'],'externalReference'=>'leaguecut-user-'.$uid];
    $created=lc_asaas_request('POST','/customers',$payload);
    $id=(string)($created['id']??'');
    if(!preg_match('/^cus_[a-zA-Z0-9]+$/D',$id))throw new RuntimeException('Cliente não confirmado pelo Asaas.');
    lc_query('INSERT INTO asaas_customers(user_id,environment,provider_customer_id) VALUES(?,?,?) ON DUPLICATE KEY UPDATE environment=VALUES(environment),provider_customer_id=VALUES(provider_customer_id)',[$uid,lc_asaas_env(),$id]);
    return $id;
}
function lc_asaas_checkout(int $creatorId,int $subscriptionId,string $cpfCnpj): string {
    if(!lc_asaas_enabled())throw new RuntimeException('Cobranças Asaas ainda não estão habilitadas.');
    lc_asaas_live_block();
    $db=lc_db();$lock='lc_asaas_checkout_'.$subscriptionId;
    if((int)lc_query('SELECT GET_LOCK(?,8) AS result',[$lock])->fetchColumn()!==1)
        throw new RuntimeException('Outra cobrança está em preparação. Aguarde.');
    try{
        $sub=lc_one("SELECT s.id,s.creator_id,s.price_cents_snapshot,s.status,p.name,
              u.full_name,u.email FROM creator_subscriptions s
              JOIN creator_packages p ON p.id=s.package_id JOIN users u ON u.id=s.creator_id
              WHERE s.id=? AND s.creator_id=? AND u.role='criador' AND u.status='approved'",
              [$subscriptionId,$creatorId]);
        if(!$sub||$sub['status']!=='pending')throw new InvalidArgumentException('Assinatura indisponível para cobrança inicial.');
        $charge=lc_one('SELECT id,status,invoice_url FROM asaas_charges WHERE subscription_id=? ORDER BY id DESC LIMIT 1',[$subscriptionId]);
        if($charge){
            if($charge['invoice_url'] && lc_valid_https((string)$charge['invoice_url']))return (string)$charge['invoice_url'];
            throw new RuntimeException('Há uma cobrança em verificação. Confira o painel antes de solicitar outra.');
        }
        $uid=(int)$sub['creator_id'];
        $client=lc_asaas_customer_for(['id'=>$uid,'full_name'=>$sub['full_name'],'email'=>$sub['email']],$cpfCnpj);
        $reference='leaguecut-sub-'.$subscriptionId.'-'.bin2hex(random_bytes(6));
        lc_query("INSERT INTO asaas_charges(creator_id,subscription_id,environment,reference_key,amount_cents,status)
                  VALUES(?,?,?,?,?,'creating')",[$uid,$subscriptionId,lc_asaas_env(),$reference,(int)$sub['price_cents_snapshot']]);
        $chargeId=(int)$db->lastInsertId();
        // Intent is recorded BEFORE the external request. On timeout, do not
        // repeat blindly: Asaas may have created a charge already.
        $created=lc_asaas_request('POST','/payments',[
            'customer'=>$client,'billingType'=>'PIX',
            'value'=>round((int)$sub['price_cents_snapshot']/100,2),
            'dueDate'=>(new DateTimeImmutable('now',new DateTimeZone('America/Fortaleza')))->modify('+3 days')->format('Y-m-d'),
            'description'=>'LeagueCut — '.mb_substr((string)$sub['name'],0,180),
            'externalReference'=>$reference
        ]);
        $paymentId=(string)($created['id']??'');
        $invoice=(string)($created['invoiceUrl']??'');
        if(!preg_match('/^pay_[A-Za-z0-9]+$/D',$paymentId))
            throw new RuntimeException('ID de cobrança retornado pelo Asaas é inválido. Verifique o painel.');
        $host=strtolower((string)parse_url($invoice,PHP_URL_HOST));
        if(!lc_valid_https($invoice)||!in_array($host,['asaas.com','www.asaas.com','sandbox.asaas.com','www.asaas.com.br'],true))
            throw new RuntimeException('Link do Asaas não reconhecido. Confira o painel.');
        lc_query("UPDATE asaas_charges SET provider_payment_id=?,invoice_url=?,status='PENDING' WHERE id=?",
            [$paymentId,$invoice,$chargeId]);
        return $invoice;
    }finally{lc_query('SELECT RELEASE_LOCK(?)',[$lock]);}
}
function lc_asaas_verified_charge(int $chargeId): array {
    $charge=lc_one('SELECT * FROM asaas_charges WHERE id=?',[$chargeId]);
    if(!$charge||!$charge['provider_payment_id'])
        throw new InvalidArgumentException('Cobrança ainda não cadastrada no Asaas.');
    if($charge['environment']!==lc_asaas_env())throw new RuntimeException('Ambiente da cobrança diferente das credenciais atuais.');
    $remote=lc_asaas_request('GET','/payments/'.$charge['provider_payment_id']);
    if(!hash_equals((string)$charge['provider_payment_id'],(string)($remote['id']??''))||
       !hash_equals((string)$charge['reference_key'],(string)($remote['externalReference']??''))||
       abs((float)($remote['value']??0)*100-(int)$charge['amount_cents'])>0.01)
       throw new RuntimeException('Dados financeiros divergentes. Conciliação bloqueada.');
    return [$charge,$remote];
}
function lc_asaas_reconcile(int $chargeId,int $actorId): string {
    lc_asaas_live_block();
    [$charge,$remote]=lc_asaas_verified_charge($chargeId);
    $status=(string)($remote['status']??'');
    if($status!=='RECEIVED')return 'Pagamento ainda não recebido pelo Asaas ('.($status?:'desconhecido').').';
    if($charge['status']==='settled')return 'Cobrança já conciliada.';
    $subscription=(int)$charge['subscription_id'];
    // Reuse the existing double-credit protections in the billing module.
    lc_creator_confirm_payment($subscription,$actorId,false);
    lc_query("UPDATE asaas_charges SET status='settled' WHERE id=?",[$chargeId]);
    return 'Recebimento confirmado no Asaas Sandbox e primeiro ciclo liberado.';
}
function lc_asaas_payout_preview(int $withdrawalId,array $actor): void {
    if(!lc_is_master($actor))throw new RuntimeException('Somente o master pode preparar o envio financeiro.');
    lc_asaas_live_block();
    $w=lc_one("SELECT id,amount_cents,status FROM withdrawals WHERE id=? AND status='requested'",[$withdrawalId]);
    if(!$w)throw new InvalidArgumentException('Saque inexistente ou não elegível.');
    lc_query("INSERT IGNORE INTO asaas_payout_intents(withdrawal_id,amount_cents,environment,status)
             VALUES(?,?,'sandbox','review_required')",[$withdrawalId,(int)$w['amount_cents']]);
    // No /transfers POST anywhere in this phase.
}
function lc_asaas_webhook_receive(string $raw,string $header): int {
    $expected=lc_env('ASAAS_WEBHOOK_TOKEN');
    if(strlen($expected)<32||strlen($expected)>255||!hash_equals($expected,$header))return 401;
    if(strlen($raw)>65536)return 413;
    try{$payload=json_decode($raw,true,24,JSON_THROW_ON_ERROR);}catch(Throwable){return 400;}
    if(!is_array($payload))return 400;
    $id=(string)($payload['id']??'');$event=(string)($payload['event']??'');
    $object=(string)($payload['payment']['id']??$payload['transfer']['id']??'');
    // Asaas event IDs can include an ampersand followed by a numeric suffix.
    // Reject HTML/control characters; use parameterized SQL and a unique PK.
    if(!preg_match('/^[A-Za-z0-9_&-]{3,100}$/D',$id)||!preg_match('/^[A-Z_]{5,90}$/D',$event)||
       strlen($object)>100)return 400;
    lc_query('INSERT IGNORE INTO asaas_webhook_events(event_id,event_type,object_id) VALUES(?,?,?)',[$id,$event,$object]);
    // Inbox only: no money, no credits, no balance mutations from incoming data.
    return 200;
}
function lc_asaas_routes(string $method,string $path): bool {
    if($path==='/webhooks/asaas'){
        if($method!=='POST'){http_response_code(405);return true;}
        header('Content-Type: text/plain; charset=UTF-8');
        $raw=file_get_contents('php://input',false,null,0,65537);
        http_response_code(lc_asaas_webhook_receive((string)$raw,(string)($_SERVER['HTTP_ASAAS_ACCESS_TOKEN']??'')));
        echo http_response_code()===200?'OK':'Webhook rejeitado';
        return true;
    }
    if($method==='POST' && preg_match('~^/criador/assinaturas/([0-9]+)/asaas-pix$~D',$path,$m)){
        $u=lc_creator_only();
        try{
            $url=lc_asaas_checkout((int)$u['id'],(int)$m[1],lc_post('cpf_cnpj',22));
            lc_flash('Cobrança Pix de teste criada. Abra seu link de pagamento abaixo.');
        }catch(Throwable $e){lc_flash($e instanceof InvalidArgumentException||$e instanceof RuntimeException?$e->getMessage():'Falha ao criar cobrança.','error');}
        lc_redirect('/criador');
    }
    if($path==='/admin/asaas'||
       ($method==='POST'&&($path==='/admin/asaas/verificar'||preg_match('~^/admin/asaas/(?:conciliar|preparar-saque)/[0-9]+$~D',$path)))){
        $actor=lc_admin();
        lc_admin_require_master($actor);
        if($method==='POST'){
            if(!lc_account_admin_password($actor)){lc_flash('Confirme sua senha master e 2FA.','error');lc_redirect('/admin/asaas');}
            try{
                if($path==='/admin/asaas/verificar'){
                    $check=lc_asaas_check_connection();
                    $webhook=lc_asaas_check_webhook_reachability();
                    $message='Teste sem movimentação: Asaas '.lc_e($check['api_status']).
                        '; Webhook POST sem token '.lc_e($webhook).
                        '; token configurado '.($check['webhook_ready']?'sim':'não').
                        '. HTTP_200 confirma API; HTTP_401 no Webhook confirma bloqueio sem token.';
                }elseif(preg_match('~^/admin/asaas/conciliar/([0-9]+)$~D',$path,$m))
                    $message=lc_asaas_reconcile((int)$m[1],(int)$actor['id']);
                elseif(preg_match('~^/admin/asaas/preparar-saque/([0-9]+)$~D',$path,$m)){
                    lc_asaas_payout_preview((int)$m[1],$actor);
                    $message='Solicitação registrada para conferência. Nenhuma transferência foi executada.';
                }else throw new InvalidArgumentException('Operação inválida.');
                lc_flash($message);
            }catch(Throwable $e){lc_flash($e->getMessage(),'error');}
            lc_redirect('/admin/asaas');
        }
        if($method==='GET'){
            $charges=lc_all('SELECT c.id,c.subscription_id,c.amount_cents,c.status,c.invoice_url,c.created_at,u.full_name
                      FROM asaas_charges c JOIN users u ON u.id=c.creator_id ORDER BY c.id DESC LIMIT 30');
            $withdrawals=lc_all("SELECT w.id,w.amount_cents,w.status,u.full_name,p.status AS asaas_status
                           FROM withdrawals w JOIN users u ON u.id=w.user_id
                           LEFT JOIN asaas_payout_intents p ON p.withdrawal_id=w.id
                           WHERE w.status='requested' ORDER BY w.id DESC LIMIT 25");
            $events=lc_all('SELECT event_id,event_type,object_id,occurred_at FROM asaas_webhook_events ORDER BY occurred_at DESC LIMIT 12');
            $configured=lc_asaas_enabled();$keyPresent=lc_asaas_api_key()!=='';$webhook=lc_env('ASAAS_WEBHOOK_TOKEN')!=='';
            lc_page('Integração Asaas',function()use($charges,$withdrawals,$events,$configured,$keyPresent,$webhook,$actor){?>
<div class="container container-md lc-account-admin">
<?php lc_heading('Gestão financeira','Asaas · Integração','Primeira etapa: cobranças Pix no Sandbox e preparação de saques, sem transferências automáticas.'); ?>
<div class="panel">
 <span class="tag"><?=lc_asaas_env()==='sandbox'?'SANDBOX':'PRODUÇÃO · ESCRITA BLOQUEADA'?></span>
 <p>Chave do ambiente atual: <strong><?=$keyPresent?'Presente no servidor':'Não encontrada'?></strong> · Operações: <strong><?=$configured?(lc_asaas_env()==='production'?'Solicitadas, mas produção bloqueada':'Sandbox habilitado'):'Desligadas'?></strong> · Webhook: <strong><?=$webhook?'Token presente':'Token ausente'?></strong></p>
 <form method="post" action="/admin/asaas/verificar"><?=lc_csrf()?>
 <label class="field"><span>Senha master para testar conexão (somente leitura)</span><input type="password" name="admin_password" autocomplete="current-password" required></label>
 <?php if(lc_mfa_enabled((int)$actor['id'])):?><label class="field"><span>Código 2FA</span><input name="mfa_code" autocomplete="one-time-code" maxlength="60" required></label><?php endif;?>
 <button class="btn btn-ghost btn-sm" type="submit">Testar conexão Asaas (sem movimentar dinheiro)</button>
 </form>
 <p>Endpoint do Webhook: <code>https://leaguecut.com/webhooks/asaas</code></p>
 <p><a class="btn btn-ghost btn-sm" href="/admin/asaas/testes">Abrir aba Testes Pix (cobrar e enviar) →</a></p>
 <p class="hint">Credenciais privadas: ASAAS_ENV=production, ASAAS_PRODUCTION_API_KEY, ASAAS_WEBHOOK_TOKEN e ASAAS_ENABLED=0 em /home/leaguecut/shared/.env. Não compartilhe as credenciais ou saldo.</p>
 <p class="hint">Cobranças somente no Sandbox. Confirmação requer consulta ao Asaas. Nenhum saque Pix é enviado automaticamente nesta versão.</p>
</div>
<div class="panel"><h2>Cobranças de criadores (testes)</h2>
<div class="table-wrap"><table><thead><tr><th>Criador</th><th>Assinatura</th><th>Valor</th><th>Status</th><th>Ação</th></tr></thead><tbody>
<?php foreach($charges as $c):?><tr>
<td><?=lc_private($c['full_name'])?></td><td>#<?=(int)$c['subscription_id']?></td><td><?=lc_private(lc_money($c['amount_cents']))?></td>
<td><?=lc_e($c['status'])?></td><td>
<?php if($c['invoice_url']):?><a href="<?=lc_e($c['invoice_url'])?>" rel="noopener noreferrer" target="_blank">Fatura</a><?php endif;?>
<?php if($c['status']!=='settled'):?><form method="post" action="/admin/asaas/conciliar/<?=(int)$c['id']?>"><?=lc_csrf()?>
<label class="field"><span>Senha master</span><input type="password" name="admin_password" autocomplete="current-password" required></label>
<?php if(lc_mfa_enabled((int)$actor['id'])):?><input name="mfa_code" aria-label="Código 2FA" autocomplete="one-time-code" required><?php endif;?>
<button class="btn btn-sm" type="submit">Conferir recebimento</button></form><?php endif;?></td></tr><?php endforeach;?></tbody></table></div>
</div>
<div class="panel"><h2>Saques — preparação sem envio</h2>
<div class="table-wrap"><table><thead><tr><th>Clipador</th><th>Valor</th><th>Situação</th><th>Ação</th></tr></thead><tbody>
<?php foreach($withdrawals as $w):?><tr><td><?=lc_private($w['full_name'])?></td><td><?=lc_private(lc_money($w['amount_cents']))?></td>
<td><?=lc_e($w['asaas_status']?:'Não preparado')?></td><td>
<?php if(!$w['asaas_status']):?><form method="post" action="/admin/asaas/preparar-saque/<?=(int)$w['id']?>"><?=lc_csrf()?>
<label class="field"><span>Senha master</span><input type="password" name="admin_password" autocomplete="current-password" required></label>
<?php if(lc_mfa_enabled((int)$actor['id'])):?><input name="mfa_code" aria-label="Código 2FA" autocomplete="one-time-code" required><?php endif;?>
<button class="btn btn-ghost btn-sm" type="submit">Preparar</button></form><?php endif;?></td></tr><?php endforeach;?></tbody></table></div>
</div>
<div class="panel"><h2>Webhooks recebidos</h2>
<div class="table-wrap"><table><thead><tr><th>Evento</th><th>Objeto</th><th>Recebido</th></tr></thead><tbody>
<?php foreach($events as $event):?><tr><td><?=lc_e($event['event_type'])?></td><td><?=lc_e($event['object_id'])?></td><td><?=lc_e($event['occurred_at'])?></td></tr><?php endforeach;?></tbody></table></div></div>
</div>
<?php },'admin');return true;
        }
        return false;
    }
    return false;
}
