<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/core.php';
require dirname(__DIR__).'/app/mfa.php';
require dirname(__DIR__).'/app/request-audit.php';
require dirname(__DIR__).'/vendor/autoload.php';

$assert=static function(bool $ok,string $message):void {
  if(!$ok)throw new RuntimeException('B2B_SECURITY_TEST_FAILED: '.$message);
};
$assert(lc_audit_route('/admin/contas/321/editar?password=secret')==='/admin/contas/{item}/editar','admin account id stripped');
$assert(lc_audit_route('/campanhas/cliente-top-123?token=token-sensivel')==='/campanhas/{item}','campaign slug and token stripped');
$assert(lc_audit_route('/seguranca/2fa')==='/seguranca/2fa','static security routes retained');
$assert(lc_audit_route('/desconhecido/CPF1234/123')==='/outros','unknown route redacted');
$assert(lc_audit_route('/')==='/','root path');
$assert(!str_contains(lc_audit_route('/admin/contas/17/editar?pix_key=123'),'123'),'query stripped');

$uri=lc_mfa_setup_qr('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ','user@example.com');
$assert(str_starts_with($uri,'data:image/svg+xml;base64,'),'QR must be locally generated SVG');
$svg=base64_decode(substr($uri,strlen('data:image/svg+xml;base64,')),true);
$assert(is_string($svg)&&str_contains($svg,'<svg')&&strlen($svg)>200,'SVG data URI valid');
$assert(!str_contains($svg,'https://')&&!str_contains($svg,'javascript:'),'no external network target in QR SVG');

$db=lc_db();$db->beginTransaction();
try{
  lc_query('INSERT INTO request_audit(user_id,actor_role,method,route_family,status_code,ip_hash)
    VALUES(NULL,?,?,?,?,?)',['guest','POST','/financeiro/saques',303,str_repeat('a',64)]);
  $row=lc_one('SELECT method,route_family,status_code FROM request_audit WHERE id=?',[(int)$db->lastInsertId()]);
  $assert($row && $row['method']==='POST' && (int)$row['status_code']===303,'audit transaction storage');
  $db->rollBack();
}catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
echo "B2B_QR_AUDIT_OK\n";
