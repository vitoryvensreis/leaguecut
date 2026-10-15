<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/meta-deauthorization.php';
$secret='test-only-secret';
$payload=['algorithm'=>'HMAC-SHA256','user_id'=>'123456'];
$b64=static fn(string $s):string=>rtrim(strtr(base64_encode($s),'+/','-_'),'=');
$part=$b64(json_encode($payload,JSON_THROW_ON_ERROR));
$signed=$b64(hash_hmac('sha256',$part,$secret,true)).'.'.$part;
if(lc_meta_verify_signed_request($signed,$secret)!==$payload)throw new RuntimeException('Valid signed request rejected');
foreach([$signed.'x','invalid',$signed.'.more','',$signed] as $i=>$invalid) {
 $key=$i===4?'wrong-secret':$secret;
 if(lc_meta_verify_signed_request($invalid,$key)!==null)throw new RuntimeException('Invalid signed request accepted');
}
if(lc_meta_verify_signed_request($signed,$secret)['user_id']!=='123456')throw new RuntimeException('User ID mismatch');
echo "Meta signed_request validation OK\\n";
