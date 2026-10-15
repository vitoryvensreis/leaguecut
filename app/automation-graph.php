<?php
declare(strict_types=1);

/** Strict, deterministic graph format: no PHP/JS expressions are executable. */
function lc_flow_validate(array $graph): array {
    $nodes=$graph['nodes']??null; $edges=$graph['edges']??null;
    if(!is_array($nodes)||!array_is_list($nodes)||count($nodes)<2||count($nodes)>32||
       !is_array($edges)||!array_is_list($edges)||count($edges)>64)
        throw new InvalidArgumentException('O fluxo deve ter entre 2 e 32 blocos e até 64 conexões.');
    $byId=[];$triggerIds=[];$actionCount=0;
    foreach($nodes as $node){
        if(!is_array($node))throw new InvalidArgumentException('Bloco inválido.');
        $id=$node['id']??null; $type=$node['type']??null; $data=$node['data']??[];
        if(!is_string($id)||!preg_match('/^[a-z0-9_-]{1,40}$/D',$id)||isset($byId[$id])||
           !in_array($type,['trigger','condition','whatsapp','email','in_app','end'],true)||
           !is_array($data)||array_is_list($data)&&$data!==[])
            throw new InvalidArgumentException('Bloco ou identificador inválido.');
        foreach(['x','y'] as $axis){
            if(!isset($node[$axis])||!is_numeric($node[$axis])||
               abs((float)$node[$axis])>5000)
                throw new InvalidArgumentException('Posição do bloco inválida.');
        }
        if($type==='trigger'){
            $triggerIds[]=$id;
            if(!isset(lc_automation_triggers()[$data['trigger']??'']))
                throw new InvalidArgumentException('Gatilho não reconhecido.');
        }
        if($type==='condition'){
            if(!isset(lc_automation_condition_fields()[$data['field']??''])||
               mb_strlen((string)($data['value']??''))>160)
               throw new InvalidArgumentException('Condição desconhecida.');
        }
        if(in_array($type,['whatsapp','email','in_app'],true)){
            ++$actionCount;
            if(!in_array($data['recipient']??'user',['user','admins'],true)||
               !is_string($data['message']??null)||
               mb_strlen(trim($data['message']))<1||
               mb_strlen($data['message'])>1800)
                throw new InvalidArgumentException('Configure destinatário e mensagem da ação.');
        }
        $byId[$id]=['id'=>$id,'type'=>$type,'data'=>$data,'x'=>(float)$node['x'],'y'=>(float)$node['y']];
    }
    if(count($triggerIds)!==1||$actionCount<1)
        throw new InvalidArgumentException('Um gatilho e pelo menos uma ação são obrigatórios.');
    $out=[];$seenEdges=[];
    foreach($edges as $edge){
        if(!is_array($edge))throw new InvalidArgumentException('Conexão inválida.');
        $from=$edge['from']??null;$to=$edge['to']??null;$port=$edge['port']??'next';
        if(!is_string($from)||!is_string($to)||!isset($byId[$from],$byId[$to])||
           $from===$to||!in_array($port,['next','true','false'],true)||
           ($byId[$from]['type']==='condition'?!in_array($port,['true','false'],true):$port!=='next')||
           $byId[$from]['type']==='end'||$byId[$to]['type']==='trigger')
            throw new InvalidArgumentException('Conexão incompatível com os blocos.');
        $key=$from.':'.$port;
        if(isset($seenEdges[$key]))throw new InvalidArgumentException('Cada saída permite somente uma conexão.');
        $seenEdges[$key]=true;
        $out[$from][$port]=$to;
    }
    $visiting=[];$visited=[];$reachableActions=0;
    $walk=function(string $id) use (&$walk,&$visiting,&$visited,&$reachableActions,$byId,$out): void {
        if(isset($visiting[$id]))throw new InvalidArgumentException('O fluxo não pode conter ciclos.');
        if(isset($visited[$id]))return;
        $visiting[$id]=true;
        if(in_array($byId[$id]['type'],['whatsapp','email','in_app'],true))$reachableActions++;
        foreach(($out[$id]??[]) as $target)$walk($target);
        unset($visiting[$id]);$visited[$id]=true;
    };
    $walk($triggerIds[0]);
    if($reachableActions===0)throw new InvalidArgumentException('Conecte ao menos uma ação ao gatilho.');
    foreach($byId as $id=>$node){
        if(!isset($visited[$id]))throw new InvalidArgumentException('Há blocos sem conexão ao fluxo.');
    }
    return ['nodes'=>array_values($byId),'edges'=>array_map(
      static fn($e)=>['from'=>$e['from'],'to'=>$e['to'],'port'=>$e['port']??'next'],$edges)];
}

/** Evaluate one event, following True/False exits. Returns each reached action. */
function lc_flow_actions(array $graph,array $context): array {
    $graph=lc_flow_validate($graph);
    $nodes=array_column($graph['nodes'],null,'id');
    $edges=[];
    foreach($graph['edges'] as $e)$edges[$e['from']][$e['port']]=$e['to'];
    $start=null;
    foreach($nodes as $id=>$n)if($n['type']==='trigger'){$start=$id;break;}
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
            if(isset($edges[$id][$port]))$queue[]=$edges[$id][$port];
        }elseif(isset($edges[$id]['next']))$queue[]=$edges[$id]['next'];
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
    ]];
}
