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
}catch(Throwable $e){
    fwrite(STDERR,'MIGRACAO_FALHOU: '.$e->getMessage()."\n");
    exit(1);
}
