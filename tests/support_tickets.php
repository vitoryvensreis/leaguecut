<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/core.php';
require dirname(__DIR__).'/app/realtime.php';
require dirname(__DIR__).'/app/support.php';
function support_assert(bool $pass,string $why):void {
    if(!$pass)throw new RuntimeException('B6E1_SUPPORT_FAILED: '.$why);
}
$stamp=bin2hex(random_bytes(5));
$created=[];
try{
    foreach(['a','b'] as $idx){
        lc_query("INSERT INTO users(full_name,nickname,email,password_hash,whatsapp,instagram,pix_key_enc,role,status)
           VALUES(?,?,?,?,?,?,?,'clipador','approved')",[
            'Support Test '.$idx,'supp_'.$idx.'_'.$stamp,'support_'.$idx.'_'.$stamp.'@leaguecut.test',
            password_hash('Test-Password-Support-2026',PASSWORD_DEFAULT),
            '85999998888','@test',''
        ]);
        $created[]=(int)lc_db()->lastInsertId();
    }
    [$alice,$bob]=$created;
    foreach([
        ['x','conta','Valid message that is long enough to open a ticket.'],
        ['Subject title','hacker','Valid message with more than twenty characters.'],
        ['Subject title','tecnico','short']
    ] as [$subject,$category,$message]){
        try{lc_support_validate($subject,$category,$message);throw new RuntimeException('Invalid ticket accepted');}
        catch(InvalidArgumentException $expected){}
    }
    $subject='Testando <img src=x onerror=alert(1)>';
    $body='Erro aconteceu em uma tela: <script>alert(1)</script> e segue o relato detalhado.';
    $id=lc_support_open($alice,$subject,'tecnico',$body);
    support_assert($id>0,'ticket created');
    $aliceTicket=lc_support_get($id,['id'=>$alice,'role'=>'clipador']);
    support_assert($aliceTicket!==null && $aliceTicket['subject']===$subject,'owner can see ticket');
    support_assert(lc_support_get($id,['id'=>$bob,'role'=>'clipador'])===null,'other user cannot read ticket');
    $admin=lc_support_get($id,['id'=>90000,'role'=>'admin']);
    support_assert($admin!==null,'ADM can inspect queue');
    $messages=lc_all('SELECT body FROM support_messages WHERE ticket_id=?',[$id]);
    support_assert(count($messages)===1 && $messages[0]['body']===$body,'initial message atomic');
    ob_start();lc_support_ticket_list([[
      'id'=>$id,'subject'=>$subject,'status'=>'open','created_at'=>'2026-10-16 08:00:00'
    ]],false);$markup=(string)ob_get_clean();
    support_assert(str_contains($markup,'&lt;img')&&!str_contains($markup,'<img src=x'),'subject escaped in HTML');
    ob_start();lc_support_ticket_list([[
      'id'=>$id,'subject'=>$subject,'status'=>'open','created_at'=>'2026-10-16 08:00:00',
      'nickname'=>'<script>bad</script>','email'=>'secret@example.com'
    ]],true);$markup=(string)ob_get_clean();
    support_assert(!str_contains($markup,'<script>')&&str_contains($markup,'&lt;script&gt;'),'admin list escapes identities');
    $event=lc_one("SELECT scope,event_type FROM realtime_events
      WHERE entity_type='support_ticket' AND entity_id=? ORDER BY id DESC LIMIT 1",[$id]);
    support_assert($event!==null&&$event['scope']==='admin'&&$event['event_type']==='support.ticket.created','admin-only notification');
    for($i=0;$i<4;$i++)lc_support_open($alice,'Valid ticket subject '.$i,'outros','Message with at least twenty characters for checking hourly limits.');
    try{
       lc_support_open($alice,'Overflow ticket subject','outros','Message with at least twenty characters for checking hourly limits.');
       throw new RuntimeException('ticket rate limit broken');
    }catch(InvalidArgumentException $expected){}
    support_assert((int)(lc_one('SELECT COUNT(*) AS n FROM support_tickets WHERE requester_id=?',[$alice])['n']??0)===5,'hourly limit prevents flood');
    echo "B6E1_SUPPORT_ISOLATION_OK\n";
}finally{
    foreach($created as $uid)lc_query('DELETE FROM users WHERE id=?',[$uid]);
}
