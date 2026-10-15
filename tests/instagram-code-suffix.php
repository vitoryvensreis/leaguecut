<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/social-oauth-callback.php';
$cases=['ABC#_'=>'ABC','ABC'=>'ABC','ABC#x'=>'ABC#x','#_'=>'','ABC#_#_'=>'ABC#_'];
foreach($cases as $input=>$expected){
 if(lc_instagram_oauth_clean_code($input)!==$expected)throw new RuntimeException('Unexpected Instagram code normalization');
}
echo "INSTAGRAM_CODE_SUFFIX_OK\n";
