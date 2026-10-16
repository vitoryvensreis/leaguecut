<?php
declare(strict_types=1);

/** Sanitized route category: no query string, document, name, slug, token, or form input. */
function lc_audit_route(string $path): string {
    $path=parse_url($path,PHP_URL_PATH)?:'/';
    $parts=array_values(array_filter(explode('/',trim($path,'/')),static fn($p)=>$p!==''));
    if(!$parts)return '/';
    $first=strtolower((string)$parts[0]);
    $fixed=['admin','seguranca','conta','financeiro','dashboard','biblioteca','clipes',
       'criador','campanhas','explorar','planos','redes','sair','entrar','cadastrar',
       'aguardando','assinaturas','integracoes','privacidade','termos'];
    if(!in_array($first,$fixed,true))return '/outros';
    $safe=['/'.$first];
    for($i=1;$i<min(count($parts),4);$i++){
        $p=(string)$parts[$i];
        // Campaign names, user handles, IDs, OAuth states, invite tokens, etc.
        // are never written to the request audit table.
        $isDynamic=ctype_digit($p)||strlen($p)>28 ||
          ($first==='campanhas' && $i===1) ||
          ($first==='clipes' && $i===1) ||
          ($first==='admin'&&$i>=2&&($parts[1]==='contas'||$parts[1]==='campanhas'));
        $safe[]=$isDynamic?'/{item}':
            (preg_match('/^[a-z][a-z0-9-]{0,27}$/D',$p)?'/'.$p:'/{item}');
    }
    return mb_substr(implode('',$safe),0,140);
}
function lc_audit_track(string $method,string $path): void {
    if(PHP_SAPI==='cli')return;
    if(!in_array($method,['GET','POST'],true))return;
    // Exclude static/media/realtime polling to avoid recording background noise.
    if($path==='/health'||str_starts_with($path,'/media/')||
       str_starts_with($path,'/realtime/')||str_starts_with($path,'/admin/whatsapp/api/')||
       str_starts_with($path,'/admin/automacoes/api/')||str_starts_with($path,'/assets/')||
       str_starts_with($path,'/.well-known/'))return;
    $before=(int)($_SESSION['uid']??0);
    $normalized=lc_audit_route($path);
    register_shutdown_function(static function()use($before,$normalized,$method):void {
        try{
            $uid=$before?:((int)($_SESSION['uid']??0));
            $status=http_response_code();
            if(!is_int($status)||$status<100||$status>599)$status=200;
            // If user deleted concurrently, save a guest event rather than breaking an FK.
            $record=$uid?lc_one('SELECT role FROM users WHERE id=?',[$uid]):null;
            $uid=$record?$uid:null;
            lc_query('INSERT INTO request_audit(user_id,actor_role,method,route_family,status_code,ip_hash)
                VALUES(?,?,?,?,?,?)',[
                $uid,$record['role']??'guest',$method,$normalized,$status,
                lc_mfa_ip_hash()
            ]);
        }catch(Throwable $ignored){
            // Auditing must not corrupt a response or reveal secrets on failure.
            error_log('LEAGUECUT_AUDIT_WRITE_FAILED');
        }
    });
}
function lc_request_audit_routes(string $method,string $path): bool {
    if($method!=='GET'||$path!=='/admin/auditoria')return false;
    lc_admin();
    $filterMethod=(string)($_GET['method']??'');
    if(!in_array($filterMethod,['GET','POST'],true))$filterMethod='';
    $filterUser=filter_var($_GET['user_id']??null,FILTER_VALIDATE_INT);
    $where=[];$params=[];
    if($filterMethod!==''){$where[]='r.method=?';$params[]=$filterMethod;}
    if($filterUser!==false&&$filterUser!==null&&$filterUser>0){
       $where[]='r.user_id=?';$params[]=$filterUser;
    }
    $sql="SELECT r.id,r.user_id,r.actor_role,r.method,r.route_family,r.status_code,r.created_at,
                 u.full_name AS actor_name
          FROM request_audit r LEFT JOIN users u ON u.id=r.user_id ".
          ($where?'WHERE '.implode(' AND ',$where):'')." ORDER BY r.id DESC LIMIT 250";
    $records=lc_all($sql,$params);
    lc_page('Auditoria de acessos',function()use($records,$filterMethod,$filterUser){?>
<div class="container"><?php lc_heading('Segurança • Auditoria','Acessos e operações','Eventos de navegação e ações, sem senhas, dados de formulários ou parâmetros de URL.'); ?>
<div class="panel">
<form method="get" action="/admin/auditoria" class="form-inline">
<label class="field"><span>Método</span><select name="method"><option value="">Todos</option><option value="GET" <?=$filterMethod==='GET'?'selected':''?>>GET — acesso</option><option value="POST" <?=$filterMethod==='POST'?'selected':''?>>POST — operação</option></select></label>
<label class="field"><span>ID da conta</span><input type="number" name="user_id" min="1" placeholder="Todos" value="<?=$filterUser?lc_e($filterUser):''?>"></label>
<button class="btn btn-ghost btn-sm">Filtrar</button></form>
<p class="hint">Até 250 eventos recentes. Retenção padrão: 90 dias após ativar a limpeza agendada. IP armazenado somente como HMAC, sem endereço em texto.</p>
<div class="table-wrap"><table><thead><tr><th>Data</th><th>Usuário</th><th>Ação</th><th>Rota normalizada</th><th>HTTP</th></tr></thead><tbody>
<?php foreach($records as $e):?><tr>
<td><?=lc_e($e['created_at'])?></td>
<td><?=lc_e($e['actor_name']??'Visitante')?> <?php if($e['user_id']):?><small>#<?=(int)$e['user_id']?> · <?=lc_e($e['actor_role'])?></small><?php endif;?></td>
<td><?=lc_e($e['method'])?></td><td><code><?=lc_e($e['route_family'])?></code></td><td><?=(int)$e['status_code']?></td>
</tr><?php endforeach;?></tbody></table></div>
</div></div>
<?php },'admin');
    return true;
}
