<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/core.php';
require dirname(__DIR__).'/app/realtime.php';
require dirname(__DIR__).'/app/automation-engine.php';
function check_auto(bool $value,string $name): void {
    if(!$value)throw new RuntimeException('Automation check failed: '.$name);
}
check_auto(count(lc_automation_triggers())>=6,'trigger catalog');
check_auto(lc_automation_condition(['condition_field'=>'always','condition_value'=>''],[]),'always condition');
check_auto(lc_automation_condition(['condition_field'=>'role','condition_value'=>'clipador'],['role'=>'clipador']),'role equality');
check_auto(!lc_automation_condition(['condition_field'=>'role','condition_value'=>'admin'],['role'=>'clipador']),'role mismatch');
check_auto(!lc_automation_condition(['condition_field'=>'unknown','condition_value'=>''],[]),'unknown field');
$rendered=lc_automation_message('Olá {nome}, {titulo} {link}',
    ['full_name'=>'Ana Maria'],['titulo'=>'Meu clipe','link'=>'/biblioteca']);
check_auto(str_contains($rendered,'Ana')&&str_contains($rendered,'Meu clipe'),'template placeholders');
check_auto(!str_contains(lc_automation_message('{link}',[],['link'=>'https://evil.example']), 'evil.example'),'no external template redirect');
check_auto(!lc_wpp_ready(),'transport must be disabled in CI');
$pdo=lc_db();
$pdo->beginTransaction();
try {
    $id=(int)(lc_one("SELECT id FROM users ORDER BY id LIMIT 1")['id']??0);
    lc_query("INSERT INTO automation_rules(name,trigger_code,condition_field,condition_value,action_type,recipient_type,message_template,enabled)
       VALUES('CI approval','user.approved','always','','in_app','user','Olá, {nome}',1)");
    $rule=(int)$pdo->lastInsertId();
    if($id>0){
        $key='ci_automation:'.bin2hex(random_bytes(10));
        check_auto(lc_automation_emit('user.approved',$key,$id,['role'=>'clipador','status'=>'approved'])===1,'first queue');
        check_auto(lc_automation_emit('user.approved',$key,$id,['role'=>'clipador','status'=>'approved'])===0,'deduplicated');
        check_auto((int)(lc_one('SELECT COUNT(*) AS total FROM automation_deliveries WHERE rule_id=?',[$rule])['total']??0)===1,'single delivery');
    }
    $pdo->rollBack();
}catch(Throwable $e){
    if($pdo->inTransaction())$pdo->rollBack();
    throw $e;
}
echo "AUTOMATION_RULES_OK\n";
