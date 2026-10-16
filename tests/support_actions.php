<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/core.php';
require dirname(__DIR__).'/app/realtime.php';
require dirname(__DIR__).'/app/support.php';
require dirname(__DIR__).'/app/support-actions.php';
function assertSupportAction(bool $ok,string $why):void {
    if(!$ok)throw new RuntimeException('B6E2_ACTION_FAILED: '.$why);
}
$stamp=bin2hex(random_bytes(5));$users=[];
try{
    foreach(['admin','clipador','clipador'] as $n=>$role){
        lc_query("INSERT INTO users(full_name,nickname,email,password_hash,whatsapp,instagram,pix_key_enc,role,status)
        VALUES(?,?,?,?,?,?,?,?, 'approved')",[
            'B6E2 Test '.($n+1),'b6e2_'.$n.'_'.$stamp,'b6e2_'.$n.'_'.$stamp.'@leaguecut.test',
            password_hash('Temporary-Test-Pass-2026',PASSWORD_DEFAULT),
            '85999996666','@test','',$role
        ]);
        $users[]=(int)lc_db()->lastInsertId();
    }
    [$adminId,$requester,$other]=$users;
    $adm=['id'=>$adminId,'role'=>'admin','status'=>'approved'];
    $owner=['id'=>$requester,'role'=>'clipador','status'=>'approved'];
    $intruder=['id'=>$other,'role'=>'clipador','status'=>'approved'];
    $ticket=lc_support_open($requester,'Dúvida sobre campanha','campanha',
       'Preciso de ajuda para entender as regras de publicação da campanha.');
    try{lc_support_reply($ticket,$intruder,'Interferindo no chamado alheio');throw new RuntimeException('Other user replied');}
    catch(InvalidArgumentException $expected){}
    assertSupportAction((int)(lc_one('SELECT COUNT(*) AS n FROM support_messages WHERE ticket_id=?',[$ticket])['n']??0)===1,'cross-user reply denied');
    try{lc_support_admin_update($ticket,$intruder,'closed','high');throw new RuntimeException('Non-admin updated ticket');}
    catch(InvalidArgumentException $expected){}
    assertSupportAction(lc_support_admin_update($ticket,$adm,'in_progress','high'),'staff changes status and priority');
    assertSupportAction(!lc_support_admin_update($ticket,$adm,'in_progress','high'),'same update is idempotent');
    lc_support_reply($ticket,$adm,'Olá! Nossa equipe está analisando a situação e retornará em breve.');
    $state=lc_one('SELECT status,priority FROM support_tickets WHERE id=?',[$ticket]);
    assertSupportAction($state['status']==='answered'&&$state['priority']==='high','staff answer sets answered and preserves high priority');
    try{lc_support_reply($ticket,$adm,'Olá! Nossa equipe está analisando a situação e retornará em breve.');throw new RuntimeException('duplicate reply allowed');}
    catch(InvalidArgumentException $expected){}
    lc_support_reply($ticket,$owner,'Obrigado! Tenho mais algumas dúvidas sobre esta campanha.');
    assertSupportAction(lc_one('SELECT status FROM support_tickets WHERE id=?',[$ticket])['status']==='open','owner response reopens answered ticket');
    lc_support_admin_update($ticket,$adm,'closed','normal');
    try{lc_support_reply($ticket,$owner,'Eu ainda precisava de ajuda');throw new RuntimeException('Closed ticket accepted user message');}
    catch(InvalidArgumentException $expected){}
    lc_support_reply($ticket,$adm,'Este chamado foi encerrado. Se necessário, abriremos um novo protocolo.');
    assertSupportAction(lc_one('SELECT status FROM support_tickets WHERE id=?',[$ticket])['status']==='answered','admin can reopen closed ticket by replying');
    $audit=lc_all('SELECT action,old_value,new_value FROM support_ticket_activity WHERE ticket_id=? ORDER BY id ASC',[$ticket]);
    $types=array_column($audit,'action');
    assertSupportAction(in_array('message',$types,true)&&in_array('status',$types,true)&&in_array('priority',$types,true),'each action audited');
    $notices=lc_all("SELECT scope,user_id,event_type FROM realtime_events WHERE entity_type='support_ticket' AND entity_id=? ORDER BY id ASC",[$ticket]);
    assertSupportAction(count($notices)>=4,'notifications for support operations');
    foreach($notices as $event){
       if($event['scope']==='user')assertSupportAction((int)$event['user_id']===$requester,'notifications cannot leak to another user');
    }
    echo "B6E2_REPLY_AUDIT_OK\n";
}finally{
    foreach($users as $id)lc_query('DELETE FROM users WHERE id=?',[$id]);
}
