<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/core.php';
require dirname(__DIR__).'/app/privacy-center.php';

function lgpdTest(bool $ok,string $why):void {
    if(!$ok)throw new RuntimeException('LGPD_PRIVACY_FAIL: '.$why);
}
$visitor=bin2hex(random_bytes(32));
$_COOKIE[LC_PRIVACY_COOKIE]=$visitor;
$owner=lc_one("SELECT id,role,status FROM users WHERE email='creator@leaguecut.test'");
$adm=lc_one("SELECT id,role,status FROM users WHERE email='admin@leaguecut.test'");
lgpdTest((bool)$owner&&(bool)$adm,'CI accounts available');
$created=null;
try{
    lgpdTest(lc_privacy_choice()===null,'default is no consent');
    lc_privacy_cookie_record('reject');
    $choice=lc_privacy_choice();
    lgpdTest($choice!==null&&(int)$choice['analytics_enabled']===0&&
       (int)$choice['marketing_enabled']===0,'reject denies all optional');
    lc_privacy_cookie_record('custom',true,false);
    $choice=lc_privacy_choice();
    lgpdTest((int)$choice['analytics_enabled']===1&&(int)$choice['marketing_enabled']===0,
      'granular choice');
    lc_privacy_cookie_record('reject');
    $choice=lc_privacy_choice();
    lgpdTest((int)$choice['analytics_enabled']===0&&
      (int)$choice['marketing_enabled']===0,'revocation persists');
    $events=lc_one('SELECT COUNT(*) AS n FROM privacy_cookie_choices WHERE visitor_hash=?',
      [hash('sha256',$visitor)]);
    lgpdTest((int)$events['n']===3,'audit history retained');
    lgpdTest(lc_privacy_safe_return('//evil.example')==='/'&&
      lc_privacy_safe_return('/admin/seo?token=123')==='/admin/seo',
      'no external redirect or query leak');
    $created=lc_privacy_request_create($owner,'access','Gostaria de obter todos os dados da minha conta.');
    $row=lc_one('SELECT user_id,status FROM privacy_subject_requests WHERE id=?',[$created]);
    lgpdTest((int)$row['user_id']===(int)$owner['id']&&$row['status']==='received',
       'owner scoped request');
    try{
       lc_privacy_request_create($owner,'access','Outro pedido para meus dados.');
       throw new RuntimeException('Throttling failed');
    }catch(InvalidArgumentException $expected){}
    try{
       lc_privacy_request_update($owner,$created,'completed','Uma resposta final completa.');
       throw new RuntimeException('Non-admin update accepted');
    }catch(InvalidArgumentException $expected){}
    lc_privacy_request_update($adm,$created,'in_review','Análise iniciada.');
    lc_privacy_request_update($adm,$created,'completed','Dados de acesso foram analisados e respondidos.');
    $row=lc_one('SELECT status,response_text FROM privacy_subject_requests WHERE id=?',[$created]);
    lgpdTest($row['status']==='completed'&&str_contains($row['response_text'],'respondidos'),
       'admin reply persisted');
    try{
       lc_privacy_request_update($adm,$created,'denied','Resposta posterior não autorizada.');
       throw new RuntimeException('Final overwrite accepted');
    }catch(InvalidArgumentException $expected){}
    $audit=lc_one('SELECT COUNT(*) AS n FROM privacy_request_audit WHERE request_id=?',[$created]);
    lgpdTest((int)$audit['n']===3,'create review and final audited');
    echo "LGPD_PRIVACY_CENTER_OK\n";
}finally{
    if($created)lc_query('DELETE FROM privacy_subject_requests WHERE id=?',[$created]);
    lc_query('DELETE FROM privacy_cookie_choices WHERE visitor_hash=?',[hash('sha256',$visitor)]);
    unset($_COOKIE[LC_PRIVACY_COOKIE]);
}
