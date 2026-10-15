<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/core.php';
require dirname(__DIR__).'/app/realtime.php';
require dirname(__DIR__).'/app/automation-engine.php';
function check_graph(bool $condition,string $label): void{
    if(!$condition)throw new RuntimeException('FLOW_GRAPH_FAILED: '.$label);
}
$graph=lc_flow_default();
$clean=lc_flow_validate($graph);
check_graph(count($clean['nodes'])===3,'default flow');
$graph['nodes'][1]=['id'=>'cond','type'=>'condition','x'=>360,'y'=>190,
   'data'=>['field'=>'status','value'=>'approved']];
$graph['nodes'][2]=['id'=>'yes','type'=>'whatsapp','x'=>680,'y'=>90,
   'data'=>['recipient'=>'user','message'=>'Aprovado: {nome}']];
$graph['nodes'][]=['id'=>'no','type'=>'email','x'=>680,'y'=>310,
   'data'=>['recipient'=>'admins','message'=>'Pendente: {nome}']];
$graph['nodes'][]=['id'=>'end','type'=>'end','x'=>1060,'y'=>180,'data'=>[]];
$graph['edges']=[
  ['from'=>'gatilho','to'=>'cond','port'=>'next'],
  ['from'=>'cond','to'=>'yes','port'=>'true'],
  ['from'=>'cond','to'=>'no','port'=>'false'],
  ['from'=>'yes','to'=>'end','port'=>'next'],
  ['from'=>'no','to'=>'end','port'=>'next']
];
$graph=lc_flow_validate($graph);
$yes=lc_flow_actions($graph,['status'=>'approved']);
$no=lc_flow_actions($graph,['status'=>'pending']);
check_graph(count($yes)===1&&$yes[0]['node_id']==='yes','true branch');
check_graph(count($no)===1&&$no[0]['node_id']==='no','false branch');
$invalid=$graph;
$invalid['edges'][]=['from'=>'end','to'=>'cond','port'=>'next'];
try{lc_flow_validate($invalid);throw new RuntimeException('Cycle accepted');}
catch(InvalidArgumentException $e){check_graph(true,'end connection rejected');}
// A trigger may fan out to many actions/conditions at once.
$fan=lc_flow_default();
$fan['viewport']=['x'=>-170,'y'=>82,'zoom'=>0.73];
$fan['nodes'][]=['id'=>'acao2','type'=>'email','x'=>400,'y'=>360,
   'data'=>['recipient'=>'user','message'=>'Email automático']];
$fan['nodes'][]=['id'=>'acao3','type'=>'in_app','x'=>770,'y'=>380,
   'data'=>['recipient'=>'user','message'=>'Notificação']];
$fan['nodes'][]=['id'=>'condfan','type'=>'condition','x'=>430,'y'=>550,
   'data'=>['field'=>'status','value'=>'approved']];
$fan['nodes'][]=['id'=>'isolado','type'=>'whatsapp','x'=>1200,'y'=>1100,
   'data'=>['recipient'=>'user','message'=>'NUNCA DISPARAR']];
$fan['edges']=[
  ['from'=>'gatilho','to'=>'acao1','port'=>'next'],
  ['from'=>'gatilho','to'=>'acao2','port'=>'next'],
  ['from'=>'gatilho','to'=>'condfan','port'=>'next'],
  ['from'=>'condfan','to'=>'acao2','port'=>'true'],
  ['from'=>'condfan','to'=>'acao3','port'=>'true'],
  ['from'=>'condfan','to'=>'acao1','port'=>'false'],
  ['from'=>'acao1','to'=>'fim','port'=>'next'],
  ['from'=>'acao2','to'=>'fim','port'=>'next'],
  ['from'=>'acao3','to'=>'fim','port'=>'next']
];
$fan=lc_flow_validate($fan);
check_graph(count($fan['edges'])===9,'multiple outgoing edges');
check_graph($fan['viewport']['zoom']===0.73 && $fan['viewport']['x']===-170.0,'viewport saved');
$yesActions=lc_flow_actions($fan,['status'=>'approved']);
$noActions=lc_flow_actions($fan,['status'=>'pending']);
check_graph(count($yesActions)===3,'parallel actions plus true fanout and dedup on convergence');
check_graph(count($noActions)===2,'false branch preserves parallel actions');
check_graph(!in_array('isolado',array_column($yesActions,'node_id'),true),'disconnected card not executed');
$unlinked=$fan;
$unlinked['edges']=[];
check_graph(count(lc_flow_actions(lc_flow_validate($unlinked),[]))===0,'disconnected action means zero delivery');
$duplicate=$fan;
$duplicate['edges'][]=$fan['edges'][0];
try{lc_flow_validate($duplicate);throw new RuntimeException('Duplicate connection accepted');}
catch(InvalidArgumentException $e){check_graph(true,'duplicate rejected');}
$cycle=$fan;
$cycle['edges'][]=['from'=>'acao1','to'=>'condfan','port'=>'next'];
try{lc_flow_validate($cycle);throw new RuntimeException('Cycle accepted');}
catch(InvalidArgumentException $e){check_graph(true,'cycle rejected');}
$disconnectedCycle=$fan;
$disconnectedCycle['nodes'][]=['id'=>'island_cond','type'=>'condition','x'=>1350,'y'=>1500,
  'data'=>['field'=>'always','value'=>'']];
$disconnectedCycle['edges'][]=['from'=>'isolado','to'=>'island_cond','port'=>'next'];
$disconnectedCycle['edges'][]=['from'=>'island_cond','to'=>'isolado','port'=>'true'];
try{lc_flow_validate($disconnectedCycle);throw new RuntimeException('Disconnected cycle accepted');}
catch(InvalidArgumentException $e){check_graph(true,'even disconnected cycles rejected');}
$fanIdByRole=array_column($fan['nodes'],null,'id');
check_graph(isset($fanIdByRole['isolado']),'unlinked block persists');

$pdo=lc_db();
$pdo->beginTransaction();
try{
    $id=(int)(lc_one('SELECT id FROM users ORDER BY id LIMIT 1')['id']??0);
    check_graph($id>0,'seed user');
    lc_query('INSERT INTO automation_rules(name,trigger_code,action_type,recipient_type,message_template,graph_json,enabled) VALUES(?,?,?,?,?,?,1)',
      ['CI visual flow','user.approved','whatsapp','user','aprovado',
       json_encode($graph,JSON_THROW_ON_ERROR)]);
    $rid=(int)$pdo->lastInsertId();
    $key='visual.flow.test.'.bin2hex(random_bytes(10));
    check_graph(lc_automation_emit('user.approved',$key,$id,['status'=>'approved'])>=1,'event queued');
    $row=lc_one('SELECT node_id,action_type FROM automation_deliveries WHERE rule_id=?',[$rid]);
    check_graph(($row['node_id']??'')==='yes' && ($row['action_type']??'')==='whatsapp','action node snapshot');
    $pdo->rollBack();
}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
echo "VISUAL_FLOW_OK\n";
