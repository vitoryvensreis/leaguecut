<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/core.php';
require dirname(__DIR__).'/app/realtime.php';
require dirname(__DIR__).'/app/admin-notices.php';
function verifyNotice(bool $yes,string $why):void {if(!$yes)throw new RuntimeException('B7B_NOTICE_FAILED: '.$why);}
$admin=lc_one("SELECT id,role,status FROM users WHERE email='admin@leaguecut.test'");
$a=lc_one("SELECT id,role,status FROM users WHERE email='creator@leaguecut.test'");
verifyNotice((bool)$admin&&(bool)$a,'fixture accounts');
$uid=(int)$a['id'];$aid=(int)$admin['id'];
try {
 $num=lc_notice_send($admin,'selected',[$uid],'Atualização de campanha','Confira as regras atualizadas da campanha.');
 verifyNotice($num===1,'exactly one recipient');
 $rows=lc_realtime_events($a,0);
 verifyNotice((bool)array_filter($rows,static fn($e)=>($e['type']??'')==='notice.direct'&&
    ($e['title']??'')==='Atualização de campanha'),'recipient sees notice');
 $other=lc_one("SELECT id,role,status FROM users WHERE role='clipador' AND status='approved' AND id<>? LIMIT 1",[$uid]);
 if($other){
  $theirs=lc_realtime_events($other,0);
  verifyNotice(!array_filter($theirs,static fn($e)=>($e['type']??'')==='notice.direct'&&
     ($e['title']??'')==='Atualização de campanha'),'non recipient cannot read');
 }
 try{lc_notice_send($admin,'all',[],'Another notice','A new general platform update.');throw new RuntimeException('throttle failed');}
 catch(InvalidArgumentException $expected){}
 echo "B7B_NOTICE_TARGETING_OK\n";
} finally {
 lc_query("DELETE FROM realtime_events WHERE event_type='notice.direct' AND entity_type='admin_notice'
    AND JSON_UNQUOTE(JSON_EXTRACT(payload_json,'$.sender_id'))=? AND
    JSON_UNQUOTE(JSON_EXTRACT(payload_json,'$.title'))=?",[(string)$aid,'Atualização de campanha']);
}
