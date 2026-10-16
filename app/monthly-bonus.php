<?php
declare(strict_types=1);

/** Existing legacy campaign leader bonus remains intact; these programs coexist. */
function lc_monthly_bonus_award(int $programId,string $month): array {
    if(!preg_match('/^(20\d{2})-(0[1-9]|1[0-2])$/D',$month) ||
       $month>=(new DateTimeImmutable('now',new DateTimeZone('America/Fortaleza')))->format('Y-m'))
        throw new InvalidArgumentException('Escolha um mês completo e já encerrado.');
    $rule=lc_one('SELECT * FROM monthly_bonus_programs WHERE id=? FOR UPDATE',[$programId]);
    if(!$rule || (int)$rule['enabled']!==1)
        throw new InvalidArgumentException('Este bônus não está ativo.');
    if((int)$rule['amount_cents']<1|| (int)$rule['amount_cents']>10000000)
        throw new InvalidArgumentException('Valor do bônus inválido.');
    $dedupe="monthly_bonus:{$programId}:{$month}";
    $paid=lc_one('SELECT id,user_id,amount_cents FROM ledger_entries WHERE dedupe_key=?',[$dedupe]);
    if($paid)return ['status'=>'already','user_id'=>(int)$paid['user_id'],'amount_cents'=>(int)$paid['amount_cents']];
    $start=$month.'-01 00:00:00';
    $end=(new DateTimeImmutable($start))->modify('+1 month')->format('Y-m-d H:i:s');
    $metric=$rule['metric']==='approved_clips'?'approved_clips':'verified_views';
    $sort=$metric==='approved_clips'?'approved_clips':'views';
    $ranking=lc_one("SELECT c.user_id, COUNT(*) AS approved_clips,
           COALESCE(SUM(c.verified_views),0) AS views
           FROM clips c INNER JOIN users u ON u.id=c.user_id
           WHERE c.campaign_id=? AND c.status='approved'
             AND u.role='clipador' AND u.status='approved'
             AND c.submitted_at>=? AND c.submitted_at<?
           GROUP BY c.user_id ORDER BY {$sort} DESC,c.user_id ASC LIMIT 1",
           [$rule['campaign_id'],$start,$end]);
    if(!$ranking || (int)$ranking[$sort]===0)
        return ['status'=>'empty','user_id'=>null,'amount_cents'=>0];
    lc_query("INSERT IGNORE INTO ledger_entries(user_id,campaign_id,kind,amount_cents,dedupe_key,description)
       VALUES(?,?,'adjustment',?,?,?)",[
        $ranking['user_id'],$rule['campaign_id'],$rule['amount_cents'],$dedupe,
        mb_substr('Bônus mensal '.$rule['name'].' ('.$month.')',0,255)
    ]);
    return [
       'status'=>(int)lc_db()->query('SELECT ROW_COUNT()')->fetchColumn()===1?'created':'already',
       'user_id'=>(int)$ranking['user_id'],'amount_cents'=>(int)$rule['amount_cents']
    ];
}
function lc_monthly_bonus_routes(string $method,string $path): bool {
    if($method!=='POST'||!str_starts_with($path,'/admin/bonus-mensal/'))return false;
    $admin=lc_admin();
    if(!lc_account_admin_password($admin)){
        lc_flash('Confirme senha e Google Authenticator, quando ativo.','error');
        lc_redirect('/admin/bonus-mensal');
    }
    if($path==='/admin/bonus-mensal/criar'){
        $campaignId=lc_int('campaign_id',0,2147483647);
        $name=trim((string)($_POST['bonus_name']??''));
        $metric=lc_post('metric',30);
        $amount=lc_cents('bonus_amount',0);
        if(mb_strlen($name)<3 || mb_strlen($name)>120 ||
           !in_array($metric,['verified_views','approved_clips'],true)||
           $amount<100||$amount>10000000 ||
           !lc_one('SELECT id FROM campaigns WHERE id=?',[$campaignId])){
            lc_flash('Informe campanha, nome, critério e valor entre R$ 1,00 e R$ 100.000,00.','error');
            lc_redirect('/admin/bonus-mensal');
        }
        lc_query("INSERT INTO monthly_bonus_programs
             (campaign_id,name,metric,amount_cents,enabled,created_by)
             VALUES(?,?,?,?,1,?)",[$campaignId,$name,$metric,$amount,$admin['id']]);
        $newId=(int)lc_db()->lastInsertId();
        lc_account_admin_audit((int)$admin['id'],'monthly_bonus.created',null,
            ['fields'=>['program_id:'.$newId,'campaign_id:'.$campaignId]]);
        lc_flash('Bônus mensal cadastrado. A apuração exige ação manual.');
        lc_redirect('/admin/bonus-mensal');
    }
    if(preg_match('~^/admin/bonus-mensal/(\d+)/estado$~D',$path,$m)){
        $programId=(int)$m[1];$active=isset($_POST['enabled'])?1:0;
        $rule=lc_one('SELECT id FROM monthly_bonus_programs WHERE id=?',[$programId]);
        if(!$rule){lc_flash('Bônus não encontrado.','error');lc_redirect('/admin/bonus-mensal');}
        lc_query('UPDATE monthly_bonus_programs SET enabled=? WHERE id=?',[$active,$programId]);
        lc_account_admin_audit((int)$admin['id'],'monthly_bonus.state',null,['fields'=>['program_id:'.$programId]]);
        lc_flash($active?'Bônus ativado.':'Bônus pausado. Histórico financeiro preservado.');
        lc_redirect('/admin/bonus-mensal');
    }
    if(preg_match('~^/admin/bonus-mensal/(\d+)/apurar$~D',$path,$m)){
        $db=lc_db();$db->beginTransaction();
        try{
            $result=lc_monthly_bonus_award((int)$m[1],lc_post('month',7));
            if($result['status']==='created'){
                lc_account_admin_audit((int)$admin['id'],'monthly_bonus.paid',null,
                    ['fields'=>['program_id:'.(int)$m[1],'month:'.lc_post('month',7)]]);
            }
            $db->commit();
            $message=match($result['status']){
               'created'=>'Bônus creditado uma vez e registrado no extrato do vencedor.',
               'already'=>'Este bônus/mês já foi creditado. Nenhum pagamento duplicado.',
               default=>'Sem clipadores elegíveis com pontuação nesse mês.'
            };
            lc_flash($message);
        }catch(InvalidArgumentException $e){
            if($db->inTransaction())$db->rollBack();
            lc_flash($e->getMessage(),'error');
        }catch(Throwable $e){
            if($db->inTransaction())$db->rollBack();
            lc_flash('Erro ao apurar prêmio. Nenhum crédito confirmado.','error');
        }
        lc_redirect('/admin/bonus-mensal');
    }
    return false;
}
