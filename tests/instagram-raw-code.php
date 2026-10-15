<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/social-oauth-callback.php';
$cases=[
 'state=s123&code=abc+def%2Bghi'=>'abc+def+ghi',
 'code=A%2FB%3D%3D%23_&state=s'=>'A/B==#_',
 'code=A.B-C_9'=>'A.B-C_9',
 'state=x'=>''
];
foreach($cases as $query=>$expect){
 if(lc_instagram_oauth_code_from_query($query)!==$expect)throw new RuntimeException('OAuth code corrupted');
}
try{
 lc_instagram_oauth_code_from_query('code=one&code=two');
 throw new LogicException('Duplicate accepted');
}catch(InvalidArgumentException $e){}
if(lc_instagram_oauth_clean_code(lc_instagram_oauth_code_from_query('code=ab%2Bcd%23_'))!=='ab+cd')
 throw new RuntimeException('Combined normalization incorrect');
echo "INSTAGRAM_RAW_CODE_OK\n";
