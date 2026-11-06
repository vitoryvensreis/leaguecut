<?php
declare(strict_types=1);
/** Pure validation rules shared by signup, account, admin and platform withdrawals. No database. */
require dirname(__DIR__).'/app/core.php';
require dirname(__DIR__).'/app/input-validation.php';
function iv(bool $ok,string $m): void { if(!$ok)throw new RuntimeException('INPUT_VALIDATION_FAILED: '.$m); }
iv(lc_cpf_valid('529.982.247-25') && lc_cpf_valid('52998224725'),'valid CPF');
iv(!lc_cpf_valid('111.111.111-11') && !lc_cpf_valid('529.982.247-24') && !lc_cpf_valid('123'),'invalid CPF');
iv(lc_cnpj_valid('11.222.333/0001-81') && !lc_cnpj_valid('11.222.333/0001-80') && !lc_cnpj_valid('00000000000000'),'CNPJ check digits');
iv(lc_cpf_cnpj_valid('11222333000181') && lc_cpf_cnpj_valid('52998224725') && !lc_cpf_cnpj_valid('5299822472'),'CPF or CNPJ');
iv(lc_phone_br_normalize('(85) 99216-2766')==='85992162766','mobile with mask');
iv(lc_phone_br_normalize('+55 85 99216-2766')==='85992162766','mobile with +55');
iv(lc_phone_br_normalize('5585921627669')==='85921627669','approver number format');
iv(lc_phone_br_normalize('(85) 3222-1234')==='8532221234','landline');
iv(lc_phone_br_normalize('(85) 3222-1234',true)===null,'landline rejected for mobile-only');
iv(lc_phone_br_normalize('(20) 99999-9999')===null,'invalid DDD');
iv(lc_phone_br_normalize('(85) 89999-9999')===null,'mobile must start with 9');
iv(lc_phone_br_normalize('999')===null,'too short');
iv(lc_email_normalize(' Joao@Gmail.com ')==='joao@gmail.com' && lc_email_normalize('joao@')===null,'email');
iv(lc_instagram_normalize('@leaguecut')==='leaguecut' && lc_instagram_normalize('https://www.instagram.com/leaguecut/')==='leaguecut','instagram');
iv(lc_instagram_normalize('a b')===null && lc_instagram_normalize('a..b')===null,'instagram invalid');
iv(lc_https_normalize('leaguecut.com')==='https://leaguecut.com' && lc_https_normalize('http://x.com')===null && lc_https_normalize('')===null,'https');
iv(lc_pix_key_normalize('CPF','529.982.247-25')==='52998224725','pix CPF digits');
iv(lc_pix_key_normalize('CNPJ','11.222.333/0001-81')==='11222333000181','pix CNPJ digits');
iv(lc_pix_key_normalize('PHONE','(85) 99216-2766')==='85992162766','pix phone 11 digits (Asaas format)');
iv(lc_pix_key_normalize('EMAIL','Fin@Example.com')==='fin@example.com','pix email lowercase');
iv(lc_pix_key_normalize('EVP','123E4567-E89B-12D3-A456-426614174000')==='123e4567-e89b-12d3-a456-426614174000','pix EVP');
foreach([['CPF','111.111.111-11'],['PHONE','(85) 3222-1234'],['EMAIL','x@'],['EVP','abc'],['XYZ','1']] as [$t,$k]){
    try{lc_pix_key_normalize($t,$k);iv(false,"accepted $t $k");}catch(InvalidArgumentException){}
}
iv(lc_pix_key_detect('fin@example.com')==='EMAIL' && lc_pix_key_detect('11.222.333/0001-81')==='CNPJ' && lc_pix_key_detect('+55 85 99216-2766')==='PHONE','detect');
iv(lc_pix_key_from_form('529.982.247-25')===['type'=>'CPF','key'=>'52998224725'],'free text detection');
try{lc_pix_key_from_form('qualquer coisa');iv(false,'garbage pix accepted');}catch(InvalidArgumentException){}
iv(lc_pix_key_hint('CPF','52998224725')==='CPF ***.982.247-**' && !str_contains(lc_pix_key_hint('EMAIL','financeiro@example.com'),'financeiro'),'masked hints');
iv(lc_pix_key_format('PHONE','85992162766')==='(85) 99216-2766' && lc_pix_key_format('CPF','52998224725')==='529.982.247-25','display format');
echo "INPUT_VALIDATION_OK\n";
