<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/legal-entity.php';
$legal=lc_legal_controller();
function ensureLegal(bool $ok,string $message):void {
    if(!$ok)throw new RuntimeException('LEGAL_COMPANY_FAILED: '.$message);
}
ensureLegal(($legal['product']??'')==='LeagueCut','product');
ensureLegal(($legal['business_name']??'')==='ESCOTILHA PRODUTORA LTDA','business name');
ensureLegal(($legal['cnpj']??'')==='64.069.224/0001-32','document CNPJ');
ensureLegal(str_contains((string)$legal['address'],'Fortaleza/CE'),'company address');
ensureLegal(filter_var($legal['privacy_email'],FILTER_VALIDATE_EMAIL)!==false,'contact');
$digits=preg_replace('/\\D/','',(string)$legal['cnpj']);
ensureLegal(strlen($digits)===14,'CNPJ length');
function cnpjDigit(string $digits,int $length):int {
    $weights=$length===12?[5,4,3,2,9,8,7,6,5,4,3,2]:[6,5,4,3,2,9,8,7,6,5,4,3,2];
    $sum=0;
    foreach($weights as $i=>$factor)$sum+=(int)$digits[$i]*$factor;
    $rem=$sum%11;
    return $rem<2?0:11-$rem;
}
ensureLegal(cnpjDigit($digits,12)===(int)$digits[12]&&cnpjDigit($digits,13)===(int)$digits[13],'CNPJ check digits');
echo "LEGAL_CONTROLLER_ESCOTILHA_OK\\n";
