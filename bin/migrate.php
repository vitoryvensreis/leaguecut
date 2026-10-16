<?php
declare(strict_types=1);
/**
 * Safe, additive, idempotent schema migration. Before the FIRST production
 * change, create a complete consistent MySQL dump outside the web root.
 */
if(PHP_SAPI!=='cli'){http_response_code(404);exit(1);}
require dirname(__DIR__).'/app/core.php';

function lc_migration_backup(): void {
    $dbName=lc_env('DB_NAME','leaguecut');
    if($dbName!=='leaguecut')throw new RuntimeException('Backup bloqueado: banco inesperado.');
    $bin=trim((string)shell_exec('command -v mysqldump 2>/dev/null'));
    if($bin===''||!is_executable($bin))throw new RuntimeException('mysqldump indisponível; migração interrompida sem alterações.');
    $base=lc_env('DB_BACKUP_DIR','/home/leaguecut/shared/backups');
    if(!is_dir($base)&&!mkdir($base,0700,true)&&!is_dir($base))throw new RuntimeException('Não foi possível criar diretório de backup.');
    chmod($base,0700);
    $client=tempnam($base,'.auth-');
    if($client===false)throw new RuntimeException('Sem arquivo temporário seguro de credenciais.');
    chmod($client,0600);
    $quote=static fn(string $v):string=>'"'.str_replace(['\\','"'],['\\\\','\\"'],$v).'"';
    $ini="[client]\nuser=".$quote(lc_env('DB_USER'))."\npassword=".$quote(lc_env('DB_PASSWORD'))."\nhost=".$quote(lc_env('DB_HOST','127.0.0.1'))."\nport=".(int)lc_env('DB_PORT','3306')."\n";
    $backup=$base.'/pre-home-banner-'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(3)).'.sql';
    try{
        if(file_put_contents($client,$ini)===false)throw new RuntimeException('Erro ao criar credencial temporária.');
        $out=fopen($backup,'xb');
        if(!$out)throw new RuntimeException('Erro ao abrir arquivo de backup.');
        chmod($backup,0600);
        // Child process receives credential filename, never the password on CLI.
        $cmd=[$bin,'--defaults-extra-file='.$client,'--single-transaction','--skip-lock-tables',
              '--no-tablespaces','--default-character-set=utf8mb4',$dbName];
        $spec=[0=>['pipe','r'],1=>$out,2=>['pipe','w']];
        $proc=proc_open($cmd,$spec,$pipes);
        if(!is_resource($proc)){fclose($out);throw new RuntimeException('Não foi possível executar mysqldump.');}
        fclose($pipes[0]);
        $errors=stream_get_contents($pipes[2]);fclose($pipes[2]);
        $status=proc_close($proc);fclose($out);
        if($status!==0 || filesize($backup)<100){
            @unlink($backup);
            throw new RuntimeException('Backup falhou. Nenhuma migração executada. Verifique permissões de exportação MySQL. '.substr((string)$errors,0,180));
        }
        echo "Backup MySQL criado fora do Document Root.\n";
    }finally{
        @unlink($client);
    }
}
try{
    if(lc_env('DB_NAME')!=='leaguecut')throw new RuntimeException('DB_NAME deve ser leaguecut.');
    $db=lc_db();
    $existing=(bool)lc_one("SELECT 1 AS found FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='homepage_banners'");
    if(!$existing){
        lc_migration_backup();
    }
    $file=dirname(__DIR__).'/database/migrations/20261014_002_homepage_banner.sql';
    $sql=file_get_contents($file);
    if($sql===false)throw new RuntimeException('SQL da migração indisponível.');
    $statements=array_values(array_filter(array_map('trim',explode(';',$sql))));
    if(count($statements)!==2 ||
        !preg_match('/^CREATE TABLE IF NOT EXISTS homepage_banners\b/i',$statements[0]) ||
        !preg_match('/^INSERT INTO homepage_banners\b/i',$statements[1])){
        throw new RuntimeException('Migração inválida ou modificada inesperadamente.');
    }
    foreach($statements as $sql) $db->exec($sql);
    $columns=$db->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='homepage_banners'")->fetchAll(PDO::FETCH_COLUMN);
    foreach(['id','enabled','title_top','title_accent','title_bottom','subtitle','media_type','media_url',
             'media_filename','media_mime','overlay_opacity','text_alignment','updated_by'] as $field){
        if(!in_array($field,$columns,true))throw new RuntimeException('Coluna ausente: '.$field);
    }
    $row=$db->query('SELECT id FROM homepage_banners WHERE id=1')->fetchColumn();
    if(!$row)throw new RuntimeException('Banner padrão não encontrado.');
    echo "MIGRACAO_HOME_BANNER_OK\n";
    $realtimeExists=(bool)lc_one("SELECT 1 AS found FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='realtime_events'");
    if(!$realtimeExists)lc_migration_backup();
    $realtimeSql=trim((string)file_get_contents(dirname(__DIR__).'/database/migrations/20261014_003_realtime_events.sql'));
    if(!preg_match('/^CREATE TABLE IF NOT EXISTS realtime_events\\b/i',$realtimeSql) ||
        substr_count($realtimeSql,';')!==1)throw new RuntimeException('Migração realtime inválida.');
    $db->exec($realtimeSql);
    $columns=$db->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='realtime_events'")->fetchAll(PDO::FETCH_COLUMN);
    if(array_diff(['id','scope','user_id','event_type','entity_type','entity_id','payload_json','created_at'],$columns)){
        throw new RuntimeException('Tabela realtime_events possui estrutura incompatível.');
    }
    echo "MIGRACAO_REALTIME_OK\n";
    $driveExists=(bool)lc_one("SELECT 1 AS found FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='google_drive_connections'");
    if(!$driveExists)lc_migration_backup();
    $driveSql=trim((string)file_get_contents(dirname(__DIR__).'/database/migrations/20261014_004_google_drive_connections.sql'));
    $driveSql=trim(preg_replace('/^--[^\n]*\n/', '', $driveSql)??$driveSql);
    if(!preg_match('/^CREATE TABLE IF NOT EXISTS google_drive_connections\\b/i',$driveSql) ||
       substr_count($driveSql,';')!==1)throw new RuntimeException('Migração Drive inválida.');
    $db->exec($driveSql);
    $driveColumns=$db->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='google_drive_connections'")->fetchAll(PDO::FETCH_COLUMN);
    if(array_diff(['id','refresh_token_enc','folder_id','connected_by','connected_at'],$driveColumns))throw new RuntimeException('Tabela Drive incompleta.');
    echo "MIGRACAO_DRIVE_OK\n";
    $metricTable=lc_one("SELECT 1 AS present FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='clip_view_samples'");
    $costTable=lc_one("SELECT 1 AS present FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='apify_profile_runs'");
    if(!$metricTable || !$costTable)lc_migration_backup();
    $sqlText=(string)file_get_contents(dirname(__DIR__).'/database/migrations/20261014_005_apify_metrics.sql');
    $metricsSql=trim(preg_replace('/^--[^\n]*\n/', '', $sqlText)??$sqlText);
    $parts=array_values(array_filter(array_map('trim',explode(';',$metricsSql))));
    if(count($parts)!==2 || !preg_match('/^CREATE TABLE IF NOT EXISTS clip_view_samples\\b/i',$parts[0]) ||
       !preg_match('/^CREATE TABLE IF NOT EXISTS apify_profile_runs\\b/i',$parts[1]))throw new RuntimeException('Migração Apify inválida.');
    foreach($parts as $statement)$db->exec($statement);
    $required=['clip_view_samples'=>['clip_id','views','source','observed_at'],
               'apify_profile_runs'=>['username','attempted_at','max_charge_usd','status']];
    foreach($required as $table=>$cols){
       $actual=$db->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=".$db->quote($table))->fetchAll(PDO::FETCH_COLUMN);
       if(array_diff($cols,$actual))throw new RuntimeException('Schema de métricas incompatível.');
    }
    echo "MIGRACAO_APIFY_OK\n";
    $dailyTable=(bool)lc_one("SELECT 1 AS ok FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='clip_daily_views'");
    $checksTable=(bool)lc_one("SELECT 1 AS ok FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='apify_post_checks'");
    if(!$dailyTable||!$checksTable)lc_migration_backup();
    $dailySql=trim((string)file_get_contents(dirname(__DIR__).'/database/migrations/20261014_006_daily_clip_views.sql'));
    $dailySql=trim(preg_replace('/^--[^\n]*\n/', '', $dailySql)??$dailySql);
    $dailyParts=array_values(array_filter(array_map('trim',explode(';',$dailySql))));
    // Statements may contain comment lines between them; remove SQL comments safely.
    $dailyParts=array_map(static fn(string $sql):string=>
        trim((string)preg_replace('/^\s*--[^\n]*\n/m','',$sql)),$dailyParts);
    if(count($dailyParts)!==2 ||
       !preg_match('/^CREATE TABLE IF NOT EXISTS clip_daily_views\b/i',$dailyParts[0]) ||
       !preg_match('/^CREATE TABLE IF NOT EXISTS apify_post_checks\b/i',$dailyParts[1]))
       throw new RuntimeException('Migração diária inválida.');
    foreach($dailyParts as $statement)$db->exec($statement);
    foreach(['clip_daily_views'=>['clip_id','snapshot_date','views','observed_at'],
             'apify_post_checks'=>['shortcode','last_checked_at','last_run_id']] as $table=>$cols){
       $actual=$db->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=".$db->quote($table))->fetchAll(PDO::FETCH_COLUMN);
       if(array_diff($cols,$actual))throw new RuntimeException('Migração de views diárias incompleta.');
    }
    echo "MIGRACAO_DAILY_VIEWS_OK\n";

    // Bloco 1: new role and additive creator subscription tables.
    $roleType=lc_one("SELECT COLUMN_TYPE AS t FROM information_schema.COLUMNS
       WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='users' AND COLUMN_NAME='role'");
    if(!$roleType)throw new RuntimeException('Papel de usuário indisponível.');
    $creatorTables=['creator_packages','creator_subscriptions',
                    'creator_subscription_cycles','creator_deliveries'];
    $creatorMissing=[];
    foreach($creatorTables as $table){
        if(!lc_one("SELECT 1 AS ok FROM information_schema.TABLES
                     WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?",[$table]))$creatorMissing[]=$table;
    }
    $roleNeedsUpgrade=!str_contains((string)$roleType['t'],"'criador'");
    if($roleNeedsUpgrade || $creatorMissing){
        lc_migration_backup();
    }
    if($roleNeedsUpgrade){
        // MySQL ENUM addition preserves the values of existing admin/clipador users.
        $db->exec("ALTER TABLE users MODIFY COLUMN role ENUM('clipador','admin','criador')
                   NOT NULL DEFAULT 'clipador'");
    }
    $script=file_get_contents(dirname(__DIR__).'/database/migrations/20261014_007_creator_subscriptions.sql');
    if(!is_string($script))throw new RuntimeException('SQL de assinaturas indisponível.');
    $script=(string)preg_replace('/^\s*--[^\n]*\n/m','',$script);
    $statements=array_values(array_filter(array_map('trim',explode(';',$script))));
    $expected=$creatorTables;
    if(count($statements)!==count($expected))throw new RuntimeException('Migração de assinaturas alterada inesperadamente.');
    foreach($statements as $i=>$statement){
        if(!preg_match('/^CREATE TABLE IF NOT EXISTS '.preg_quote($expected[$i],'/').'\s*\(/i',$statement))
            throw new RuntimeException('Migração de assinaturas inválida: '.$expected[$i]);
        $db->exec($statement);
    }
    $roleType=$db->query("SELECT COLUMN_TYPE FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='users' AND COLUMN_NAME='role'")->fetchColumn();
    if(!str_contains((string)$roleType,"'criador'"))throw new RuntimeException('Papel criador indisponível.');
    foreach([
       'creator_packages'=>['fulfillment_mode','monthly_clips','monthly_price_cents','status'],
       'creator_subscriptions'=>['creator_id','package_id','price_cents_snapshot','fulfillment_mode_snapshot'],
       'creator_subscription_cycles'=>['subscription_id','cycle_start','clips_included','clips_used','payment_status'],
       'creator_deliveries'=>['subscription_id','cycle_id','creator_id','status']
    ] as $table=>$columns){
       $actual=$db->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS
               WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=".$db->quote($table))->fetchAll(PDO::FETCH_COLUMN);
       if(array_diff($columns,$actual))throw new RuntimeException('Estrutura do pacote incompleta: '.$table);
    }
    // Additive hybrid/Meta migrations; require a consistent dump first.
    $hybridTables=['clip_verification_state','clip_verification_observations',
                   'social_oauth_connections','meta_deauthorization_inbox'];
    $missingHybrid=[];
    foreach($hybridTables as $table){
        if(!lc_one("SELECT 1 AS found FROM information_schema.TABLES
            WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?",[$table]))$missingHybrid[]=$table;
    }
    if($missingHybrid)lc_migration_backup();
    foreach([
        ['20261014_hybrid_verification.sql',[
            'clip_verification_state','clip_verification_observations','social_oauth_connections']],
        ['20261015_meta_deauthorization_inbox.sql',['meta_deauthorization_inbox']]
    ] as [$filename,$tables]){
        $sql=file_get_contents(dirname(__DIR__).'/database/migrations/'.$filename);
        if(!is_string($sql))throw new RuntimeException('Migração não encontrada: '.$filename);
        $sql=(string)preg_replace('/^\\s*--[^\\n]*\\n/m','',$sql);
        $statements=array_values(array_filter(array_map('trim',explode(';',$sql))));
        if(count($statements)!==count($tables))throw new RuntimeException('Migração inesperada: '.$filename);
        foreach($statements as $i=>$statement){
            if(!preg_match('/^CREATE TABLE IF NOT EXISTS '.preg_quote($tables[$i],'/').'\\s*\\(/i',$statement))
                throw new RuntimeException('SQL não autorizado: '.$filename);
            $db->exec($statement);
        }
    }
    foreach($hybridTables as $table){
        if(!lc_one("SELECT 1 AS found FROM information_schema.TABLES
            WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?",[$table]))
            throw new RuntimeException('Tabela híbrida ausente: '.$table);
    }
    echo "MIGRACAO_HIBRIDA_OK\\n";

    // Declarative automation tables: additive, idempotent, backed up before first use.
    $autoTables=['automation_rules','automation_events','automation_deliveries'];
    $autoMissing=false;
    foreach($autoTables as $table){
        if(!lc_one("SELECT 1 AS found FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?",[$table])){
            $autoMissing=true;
        }
    }
    if($autoMissing)lc_migration_backup();
    $autoSql=(string)file_get_contents(dirname(__DIR__).'/database/migrations/20261015_automation_engine.sql');
    $autoStatements=array_values(array_filter(array_map('trim',explode(';',$autoSql))));
    if(count($autoStatements)!==3)throw new RuntimeException('Migração de automações inválida.');
    foreach($autoStatements as $i=>$statement){
        if(!preg_match('/^CREATE TABLE IF NOT EXISTS '.preg_quote($autoTables[$i],'/').'\s*\(/i',$statement)){
            throw new RuntimeException('Tabela inesperada na migração de automações.');
        }
        $db->exec($statement);
    }
    foreach($autoTables as $table){
        if(!lc_one("SELECT 1 AS found FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?",[$table])){
            throw new RuntimeException('Tabela de automação ausente: '.$table);
        }
    }
    // Visual flow schema extension. Back up on first structural change.
    $flowCols=[
      'automation_rules'=>['graph_json'=>'JSON NULL'],
      'automation_deliveries'=>[
          'node_id'=>"VARCHAR(50) NOT NULL DEFAULT 'legacy'",
          'action_type'=>"ENUM('whatsapp','email','in_app') NULL",
          'message_template'=>'TEXT NULL'
      ]
    ];
    $missingFields=[];
    foreach($flowCols as $table=>$fields){
        foreach($fields as $column=>$definition){
            $found=lc_one("SELECT 1 AS ok FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?",[$table,$column]);
            if(!$found)$missingFields[]=[$table,$column,$definition];
        }
    }
    $index=lc_one("SELECT GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX SEPARATOR ',') AS cols
      FROM information_schema.STATISTICS
      WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='automation_deliveries'
        AND INDEX_NAME='uq_automation_delivery' GROUP BY INDEX_NAME");
    $indexUpgrade=($index['cols']??'')!=='event_id,rule_id,recipient_user_id,node_id';
    $switchExists=(bool)lc_one("SELECT 1 AS ok FROM information_schema.TABLES
       WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='automation_whatsapp_settings'");
    if($missingFields||$indexUpgrade||!$switchExists)lc_migration_backup();
    foreach($missingFields as [$table,$column,$definition]){
        $db->exec("ALTER TABLE $table ADD COLUMN $column $definition");
    }
    if($indexUpgrade){
        // MySQL uses the old unique index for fk_automation_event. Create a
        // dedicated FK index BEFORE dropping it, preserving all existing rows.
        $eventIndex=lc_one("SELECT 1 AS found FROM information_schema.STATISTICS
          WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='automation_deliveries'
            AND INDEX_NAME='idx_automation_event_fk' LIMIT 1");
        if(!$eventIndex)
            $db->exec('ALTER TABLE automation_deliveries ADD INDEX idx_automation_event_fk (event_id)');
        if($index) $db->exec('ALTER TABLE automation_deliveries DROP INDEX uq_automation_delivery');
        $db->exec('ALTER TABLE automation_deliveries ADD UNIQUE KEY uq_automation_delivery (event_id,rule_id,recipient_user_id,node_id)');
    }
    $switchSql=trim((string)file_get_contents(dirname(__DIR__).'/database/migrations/20261015_automation_visual.sql'));
    if(!preg_match('/^CREATE TABLE IF NOT EXISTS automation_whatsapp_settings\s*\(/i',$switchSql))
        throw new RuntimeException('Migração visual inválida.');
    $db->exec($switchSql);
    $db->exec('INSERT IGNORE INTO automation_whatsapp_settings(id,enabled) VALUES(1,0)');
    echo "MIGRACAO_AUTOMACOES_VISUAIS_OK\n";

    echo "MIGRACAO_AUTOMACOES_OK\n";

    // Administrative account activity log, before enabling the new admin screens.
    $auditExists=(bool)lc_one("SELECT 1 AS present FROM information_schema.TABLES
         WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='account_admin_audit'");
    if(!$auditExists){
        lc_migration_backup();
        $sql=trim((string)file_get_contents(dirname(__DIR__).'/database/migrations/20261016_account_admin_audit.sql'));
        if(!preg_match('/^CREATE TABLE IF NOT EXISTS account_admin_audit\s*\(/i',$sql))
            throw new RuntimeException('Arquivo da migração administrativa inválido.');
        $db->exec($sql);
    }
    echo "MIGRACAO_ADMIN_AUDIT_OK\n";

    echo "MIGRACAO_CRIADOR_ASSINATURAS_OK\n";


}catch(Throwable $e){
    fwrite(STDERR,'MIGRACAO_FALHOU: '.$e->getMessage()."\n");
    exit(1);
}
