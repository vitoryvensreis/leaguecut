<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/core.php';
require dirname(__DIR__).'/app/realtime.php';
require dirname(__DIR__).'/app/support.php';
function ensureListing(bool $ok,string $why):void{
    if(!$ok)throw new RuntimeException('B6F1_LISTING_FAILED: '.$why);
}
$stamp=bin2hex(random_bytes(5));$ids=[];
try{
    foreach(['admin','clipador','clipador'] as $n=>$role){
        lc_query("INSERT INTO users(full_name,nickname,email,password_hash,whatsapp,instagram,pix_key_enc,role,status)
          VALUES(?,?,?,?,?,?,?,?, 'approved')",[
          'Listing Test '.($n+1),'listing_'.$n.'_'.$stamp,
          'listing_'.$n.'_'.$stamp.'@leaguecut.test',
          password_hash('Temporary-Test-Pass-2026',PASSWORD_DEFAULT),'85999996666','@test','',$role
        ]);
        $ids[]=(int)lc_db()->lastInsertId();
    }
    [$admId,$ownerId,$otherId]=$ids;
    $owner=['id'=>$ownerId,'role'=>'clipador'];
    $admin=['id'=>$admId,'role'=>'admin'];
    for($i=0;$i<27;$i++){
        lc_query("INSERT INTO support_tickets(requester_id,subject,category,status,priority) VALUES(?,?,?,?,?)",
            [$ownerId,$i===0?'Percentual 100% legítimo':'Chamado teste '.($i+1),'tecnico',$i===0?'closed':'open','normal']);
    }
    lc_query("INSERT INTO support_tickets(requester_id,subject,category,status,priority) VALUES(?,?,?,?,?)",
            [$otherId,'Protocolo privado','tecnico','open','high']);
    $privateId=(int)lc_db()->lastInsertId();
    $mine1=lc_support_list_page($owner,false,['page'=>'1']);
    $mine2=lc_support_list_page($owner,false,['page'=>'2']);
    ensureListing(count($mine1['rows'])===25 && $mine1['has_next'] && !$mine1['has_previous'],'first page');
    ensureListing(count($mine2['rows'])===2 && !$mine2['has_next'] && $mine2['has_previous'],'last page');
    $private=lc_support_list_page($owner,false,['q'=>(string)$privateId]);
    ensureListing(count($private['rows'])===0,'owner must not find another requester ticket by ID');
    $wild=lc_support_list_page($owner,false,['q'=>'%']);
    ensureListing(count($wild['rows'])===1 && str_contains($wild['rows'][0]['subject'],'100%'),'LIKE treats wildcard literally');
    $adminOpen=lc_support_list_page($admin,true,['status'=>'open','q'=>'Protocolo privado']);
    ensureListing(count($adminOpen['rows'])===1 && (int)$adminOpen['rows'][0]['id']===$privateId,'admin can search other user');
    $adminClosed=lc_support_list_page($admin,true,['status'=>'closed','q'=>'legítimo']);
    ensureListing(count($adminClosed['rows'])===1,'admin filters status and searches accents');
    $invalid=lc_support_list_page($admin,true,['status'=>'invalid','page'=>'-1']);
    ensureListing($invalid['status']==='all' && $invalid['page']===1,'bad filters fail safely');
    ensureListing(str_contains(lc_support_list_url(true,'open','hello world',2),'q=hello%20world'),'URL encoding');
    echo "B6F1_SUPPORT_LISTING_OK\n";
} finally {
    foreach($ids as $id)lc_query('DELETE FROM users WHERE id=?',[$id]);
}
