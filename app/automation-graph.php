<?php
declare(strict_types=1);

/**
 * Declarative flow DAG. Multiple edges may leave one port (parallel branches).
 * Disconnected blocks are kept in the editor, but are never traversed/executed.
 */
function lc_flow_validate(array $graph): array {
    $nodes=$graph['nodes']??null;
    $edges=$graph['edges']??null;
    if(!is_array($nodes)||!array_is_list($nodes)||count($nodes)<1||count($nodes)>60||
       !is_array($edges)||!array_is_list($edges)||count($edges)>160)
        throw new InvalidArgumentException('O fluxo permite de 1 a 60 blocos e até 160 conexões.');
    $byId=[];$triggerIds=[];
    foreach($nodes as $node){
        if(!is_array($node))throw new InvalidArgumentException('Bloco inválido.');
        $id=$node['id']??null;$type=$node['type']??null;$data=$node['data']??[];
        if(!is_string($id)||!preg_match('/^[a-z0-9_-]{1,40}$/D',$id)||
           isset($byId[$id])||
           !in_array($type,['trigger','condition','whatsapp','email','in_app','end'],true)||
           !is_array($data)||($data!==[] && array_is_list($data)))
            throw new InvalidArgumentException('Tipo, configuração ou identificador inválido.');
        foreach(['x','y'] as $axis){
            if(!isset($node[$axis])||!is_numeric($node[$axis])||
               !is_finite((float)$node[$axis])||abs((float)$node[$axis])>5000)
                throw new InvalidArgumentException('Posição de card inválida.');
        }
        if($type==='trigger'){
            $triggerIds[]=$id;
            if(!isset(lc_automation_triggers()[$data['trigger']??'']))
                throw new InvalidArgumentException('Gatilho não reconhecido.');
        }
        if($type==='condition'){
            if(!isset(lc_automation_condition_fields()[$data['field']??''])||
               !is_string($data['value']??null)||mb_strlen($data['value'])>160)
                throw new InvalidArgumentException('Condição desconhecida.');
        }
        if(in_array($type,['whatsapp','email','in_app'],true)){
            if(!in_array($data['recipient']??null,['user','admins'],true)||
               !is_string($data['message']??null)||
               mb_strlen(trim($data['message']))<1||
               mb_strlen($data['message'])>1800)
                throw new InvalidArgumentException('Configure destinatário e mensagem da ação.');
        }
        $byId[$id]=['id'=>$id,'type'=>$type,'data'=>$data,
            'x'=>(float)$node['x'],'y'=>(float)$node['y']];
    }
    if(count($triggerIds)!==1)
        throw new InvalidArgumentException('Exatamente um gatilho deve existir no fluxo.');

    $out=[];$pairs=[];$perPort=[];
    foreach($edges as $edge){
        if(!is_array($edge))throw new InvalidArgumentException('Conexão inválida.');
        $from=$edge['from']??null;$to=$edge['to']??null;$port=$edge['port']??'next';
        if(!is_string($from)||!is_string($to)||!isset($byId[$from],$byId[$to])||
           $from===$to||!in_array($port,['next','true','false'],true)||
           ($byId[$from]['type']==='condition'
              ? !in_array($port,['true','false'],true) : $port!=='next')||
           $byId[$from]['type']==='end'||$byId[$to]['type']==='trigger')
            throw new InvalidArgumentException('Conexão incompatível com os cards.');
        $pair=$from.'|'.$port.'|'.$to;
        if(isset($pairs[$pair]))throw new InvalidArgumentException('Conexão duplicada.');
        $pairs[$pair]=true;
        $key=$from.'|'.$port;
        $perPort[$key]=($perPort[$key]??0)+1;
        if($perPort[$key]>20)
            throw new InvalidArgumentException('Máximo de 20 destinos por saída.');
        $out[$from][$port][]=$to;
    }
    // Check all nodes, including disconnected islands, for cycles.
    $visiting=[];$visited=[];
    $walk=function(string $id) use (&$walk,&$visiting,&$visited,$out): void {
        if(isset($visiting[$id]))throw new InvalidArgumentException('O fluxo não pode conter ciclos.');
        if(isset($visited[$id]))return;
        $visiting[$id]=true;
        foreach(($out[$id]??[]) as $targets)
            foreach($targets as $next)$walk($next);
        unset($visiting[$id]);$visited[$id]=true;
    };
    foreach(array_keys($byId) as $id)$walk($id);

    $viewport=$graph['viewport']??['x'=>0,'y'=>0,'zoom'=>1];
    if(!is_array($viewport))throw new InvalidArgumentException('Visualização inválida.');
    foreach(['x','y','zoom'] as $field)
        if(!isset($viewport[$field])||!is_numeric($viewport[$field])||
           !is_finite((float)$viewport[$field]))
            throw new InvalidArgumentException('Zoom ou deslocamento inválido.');
    $x=(float)$viewport['x'];$y=(float)$viewport['y'];$zoom=(float)$viewport['zoom'];
    if(abs($x)>15000||abs($y)>15000||$zoom<.3||$zoom>2.5)
        throw new InvalidArgumentException('Visualização fora dos limites.');

    return [
      'nodes'=>array_values($byId),
      'edges'=>array_map(static fn($e)=>[
        'from'=>$e['from'],'to'=>$e['to'],'port'=>$e['port']??'next'
      ],$edges),
      'viewport'=>['x'=>$x,'y'=>$y,'zoom'=>$zoom]
    ];
}

/** Every reached action is queued once, even if several branches converge. */
function lc_flow_actions(array $graph,array $context): array {
    $validated=lc_flow_validate($graph);
    $nodes=array_column($validated['nodes'],null,'id');
    $out=[];
    foreach($validated['edges'] as $e)$out[$e['from']][$e['port']][]=$e['to'];
    $start=null;
    foreach($nodes as $id=>$node){
        if($node['type']==='trigger'){$start=$id;break;}
    }
    $queue=[$start];$visited=[];$actions=[];
    while($queue){
        $id=array_shift($queue);
        if(isset($visited[$id]))continue;
        $visited[$id]=true;
        $node=$nodes[$id];$type=$node['type'];$data=$node['data'];
        if(in_array($type,['whatsapp','email','in_app'],true)){
            $actions[]=['node_id'=>$id,'action_type'=>$type,
               'recipient_type'=>$data['recipient'],'message_template'=>$data['message']];
        }
        if($type==='condition'){
            $passed=lc_automation_condition([
                'condition_field'=>$data['field'],
                'condition_value'=>(string)($data['value']??'')
            ],$context);
            $port=$passed?'true':'false';
            foreach(($out[$id][$port]??[]) as $next)$queue[]=$next;
        }else{
            foreach(($out[$id]['next']??[]) as $next)$queue[]=$next;
        }
    }
    return $actions;
}
function lc_flow_default(string $trigger='user.approved'): array {
    return ['nodes'=>[
       ['id'=>'gatilho','type'=>'trigger','x'=>65,'y'=>190,'data'=>['trigger'=>$trigger]],
       ['id'=>'acao1','type'=>'whatsapp','x'=>415,'y'=>190,
        'data'=>['recipient'=>'user','message'=>'Olá, {nome}! Temos uma atualização no LeagueCut. {link}']],
       ['id'=>'fim','type'=>'end','x'=>775,'y'=>190,'data'=>[]]
    ],'edges'=>[
       ['from'=>'gatilho','to'=>'acao1','port'=>'next'],
       ['from'=>'acao1','to'=>'fim','port'=>'next']
    ],'viewport'=>['x'=>10,'y'=>20,'zoom'=>1]];
}
