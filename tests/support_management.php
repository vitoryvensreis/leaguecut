<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/core.php';
require dirname(__DIR__).'/app/realtime.php';
require dirname(__DIR__).'/app/support.php';
require dirname(__DIR__).'/app/support-management.php';
function slaAssert(bool $ok,string $why):void {if(!$ok)throw new RuntimeException('B7C_SLA_FAILED: '.$why);}
$adm=lc_one("SELECT id,role,status FROM users WHERE email='admin@leaguecut.test'");
$owner=lc_one("SELECT id,role,status FROM users WHERE email='creator@leaguecut.test'");
slaAssert((bool)$adm&&(bool)$owner,'fixture');
$ticket=lc_support_open((int)$owner['id'],'Prazo de resposta de suporte','tecnico',
 'Gostaria de saber quanto tempo o atendimento leva para responder.');
try {
 try{
   lc_support_manage_update($ticket,$owner,(int)$adm['id'],8);
   throw new RuntimeException('Non-admin assignment accepted');
 }catch(InvalidArgumentException $expected){}
 slaAssert(lc_support_manage_update($ticket,$adm,(int)$adm['id'],8),'first assignment');
 $tracking=lc_support_tracking($ticket);
 slaAssert((int)$tracking['assigned_admin_id']===(int)$adm['id'],'assigned admin only');
 $start=lc_one('SELECT created_at FROM support_tickets WHERE id=?',[$ticket]);
 slaAssert((int)(strtotime($tracking['target_at'])-strtotime($start['created_at']))===8*3600,'SLA based on opening');
 slaAssert(!lc_support_manage_update($ticket,$adm,(int)$adm['id'],8),'idempotence');
 slaAssert(lc_support_manage_update($ticket,$adm,0,48),'unassign and change SLA');
 $events=lc_all('SELECT id FROM support_ticket_management_audit WHERE ticket_id=?',[$ticket]);
 slaAssert(count($events)===2,'audit only changes');
 $another=lc_one("SELECT id FROM users WHERE role='clipador' AND status='approved' LIMIT 1");
 if($another){
   try{lc_support_manage_update($ticket,$adm,(int)$another['id'],4);throw new RuntimeException('Non-admin assignee accepted');}
   catch(InvalidArgumentException $expected){}
 }
 echo "B7C_SUPPORT_MANAGEMENT_OK\n";
} finally {
 lc_query("DELETE FROM realtime_events WHERE entity_type='support_ticket' AND entity_id=?",[$ticket]);
 lc_query('DELETE FROM support_tickets WHERE id=?',[$ticket]);
}
