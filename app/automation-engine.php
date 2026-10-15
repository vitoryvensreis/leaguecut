<?php
declare(strict_types=1);
require_once __DIR__.'/automation-graph.php';

/** Declarative automations. Never eval user supplied rules or templates. */
function lc_automation_triggers(): array {
    return [
      'user.approved'=>'Cadastro aprovado',
      'clip.approved'=>'Clipe aprovado',
      'clip.rejected'=>'Clipe reprovado',
      'withdrawal.requested'=>'Saque solicitado',
      'withdrawal.paid'=>'Saque confirmado',
      'withdrawal.rejected'=>'Saque recusado'
    ];
}
function lc_automation_actions(): array {
    return ['whatsapp'=>'Mensagem WhatsApp (WPPConnect)','email'=>'Enviar e-mail','in_app'=>'Notificação na plataforma'];
}
function lc_automation_condition_fields(): array {
    return ['always'=>'Sempre','role'=>'Perfil do usuário','status'=>'Status do evento','campaign_id'=>'ID da campanha'];
}
function lc_automation_condition(array $rule,array $context): bool {
    $field=(string)$rule['condition_field'];
    if($field==='always')return true;
    if(!array_key_exists($field,lc_automation_condition_fields()))return false;
    return isset($context[$field]) && (string)$context[$field]===(string)$rule['condition_value'];
}
function lc_automation_has_trigger(string $trigger): bool {
    return (bool)lc_one('SELECT id FROM automation_rules WHERE trigger_code=? AND enabled=1 LIMIT 1',[$trigger]);
}
/**
 * Persist event and all deliveries inside the transaction of the business mutation.
 * A repeated event_key cannot create duplicate deliveries.
 */
function lc_automation_emit(string $trigger,string $eventKey,int $subjectUserId,array $context=[]): int {
    if(!isset(lc_automation_triggers()[$trigger]) || $subjectUserId<1
       || strlen($eventKey)>190 || !preg_match('/^[a-zA-Z0-9:._-]{4,190}$/D',$eventKey)){
        throw new InvalidArgumentException('Evento de automação inválido.');
    }
    $rules=lc_all('SELECT * FROM automation_rules WHERE enabled=1 AND trigger_code=? ORDER BY id',[$trigger]);
    if(!$rules)return 0;
    $context=array_intersect_key($context,array_flip(['nome','titulo','campanha','campaign_id','role','status','link']));
    foreach($context as $key=>$value)$context[$key]=mb_substr((string)$value,0,300);
    $context['evento']=$trigger;
    $created=lc_query('INSERT IGNORE INTO automation_events(event_key,trigger_code,subject_user_id,context_json) VALUES(?,?,?,?)',
        [$eventKey,$trigger,$subjectUserId,json_encode($context,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE)]);
    if($created->rowCount()===0)return 0;
    $eventId=(int)lc_db()->lastInsertId();
    $count=0;
    foreach($rules as $rule){
        $graphRaw=(string)($rule['graph_json']??'');
        if($graphRaw!==''){
            $graph=json_decode($graphRaw,true,32,JSON_THROW_ON_ERROR);
            $actions=lc_flow_actions($graph,$context);
        }else{
            if(!lc_automation_condition($rule,$context))continue;
            $actions=[['node_id'=>'legacy','action_type'=>$rule['action_type'],
               'recipient_type'=>$rule['recipient_type'],'message_template'=>$rule['message_template']]];
        }
        foreach($actions as $action){
            $ids=$action['recipient_type']==='admins'
                ?lc_all("SELECT id FROM users WHERE role='admin' AND status='approved' ORDER BY id LIMIT 20")
                :[['id'=>$subjectUserId]];
            foreach($ids as $item){
                $insert=lc_query('INSERT IGNORE INTO automation_deliveries
                  (event_id,rule_id,recipient_user_id,node_id,action_type,message_template) VALUES(?,?,?,?,?,?)',
                  [$eventId,$rule['id'],$item['id'],$action['node_id'],$action['action_type'],$action['message_template']]);
                $count+=$insert->rowCount();
            }
        }
    }
    return $count;
}
function lc_automation_message(string $template,array $user,array $context): string {
    $base=rtrim(lc_env('APP_URL','https://leaguecut.com'),'/');
    $values=[
      '{nome}'=>explode(' ',trim((string)($user['full_name']??'')))[0]??'',
      '{nome_completo}'=>(string)($user['full_name']??''),
      '{evento}'=>(string)($context['evento']??''),
      '{titulo}'=>(string)($context['titulo']??''),
      '{campanha}'=>(string)($context['campanha']??''),
      '{status}'=>(string)($context['status']??''),
      '{link}'=>$base.'/entrar'
    ];
    if(isset($context['link']) && preg_match('~^/[a-zA-Z0-9/_-]*$~D',(string)$context['link'])){
        $values['{link}']=$base.$context['link'];
    }
    return mb_substr(trim(strtr($template,$values)),0,1800);
}
/** Only loopback WPPConnect is reachable. Protect against SSRF via .env. */
function lc_wpp_base_url(): string {
    $base=rtrim(lc_env('WPP_BASE_URL','http://127.0.0.1:21465'),'/');
    if(!preg_match('~^http://127[.]0[.]0[.]1:[0-9]{2,5}$~D',$base)){
        throw new RuntimeException('WPP_BASE_URL deve apontar para 127.0.0.1 na VPS.');
    }
    return $base;
}
function lc_wpp_ready(): bool {
    $session=lc_env('WPP_SESSION','leaguecut');
    if($session!=='leaguecut'||lc_env('WPP_AUTH_TOKEN')==='')return false;
    try{
        $enabled=lc_one('SELECT enabled FROM automation_whatsapp_settings WHERE id=1');
        return (int)($enabled['enabled']??0)===1;
    }catch(Throwable $e){return false;}
}
function lc_wpp_send(string $phone,string $message): void {
    if(!lc_wpp_ready())throw new RuntimeException('WPPConnect desligado ou sem sessão/token.');
    $phone=preg_replace('/\D/','',$phone)??'';
    if(preg_match('/^[1-9][0-9]{9,10}$/D',$phone))$phone='55'.$phone;
    if(!preg_match('/^55[1-9][0-9]{9,10}$/D',$phone))throw new RuntimeException('Destinatário WhatsApp inválido.');
    $session=lc_env('WPP_SESSION','leaguecut');
    $ch=curl_init(lc_wpp_base_url().'/api/'.rawurlencode($session).'/send-message');
    if(!$ch)throw new RuntimeException('cURL indisponível para WPPConnect.');
    curl_setopt_array($ch,[
      CURLOPT_POST=>true,CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>3,
      CURLOPT_TIMEOUT=>16,CURLOPT_HTTPHEADER=>[
        'Authorization: Bearer '.lc_env('WPP_AUTH_TOKEN'),'Content-Type: application/json'
      ],
      CURLOPT_POSTFIELDS=>json_encode(['phone'=>$phone,'isGroup'=>false,'message'=>$message],JSON_THROW_ON_ERROR)
    ]);
    $response=curl_exec($ch);
    $status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);
    curl_close($ch);
    $json=is_string($response)?json_decode($response,true):null;
    if($response===false || $status<200 || $status>=300 ||
        (is_array($json)&&(($json['success']??true)===false ||
          in_array(strtolower((string)($json['status']??'')),['error','failed','failure'],true)))){
        throw new RuntimeException('WPPConnect não confirmou o envio (HTTP '.$status.').');
    }
}
