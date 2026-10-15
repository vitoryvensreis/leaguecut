<?php
declare(strict_types=1);
/**
 * Meta sends a signed_request to this unauthenticated server-to-server endpoint.
 * Reject unsigned requests; never echo identifiers or credentials.
 */
function lc_meta_verify_signed_request(string $request,string $secret): ?array {
    if($secret===''||strlen($request)>8192||substr_count($request,'.')!==1)return null;
    [$signature,$payload]=explode('.',$request,2);
    $decode=static function(string $v): string|false {
        if($v===''||preg_match('/[^A-Za-z0-9_-]/',$v))return false;
        return base64_decode(strtr($v,'-_','+/').str_repeat('=',(4-strlen($v)%4)%4),true);
    };
    $sig=$decode($signature);$raw=$decode($payload);
    if($sig===false||$raw===false||strlen($sig)!==32||strlen($raw)>4096)return null;
    if(!hash_equals(hash_hmac('sha256',$payload,$secret,true),$sig))return null;
    $data=json_decode($raw,true);
    if(!is_array($data)||strtoupper((string)($data['algorithm']??''))!=='HMAC-SHA256')return null;
    return $data;
}
function lc_meta_deauthorization_route(string $method,string $path):bool {
    if($path!=='/redes/oauth/instagram/desautorizacao')return false;
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store');
    if($method!=='POST'){http_response_code(405);header('Allow: POST');echo '{"error":"method_not_allowed"}';return true;}
    $signed=$_POST['signed_request']??null;
    $secret=lc_env('META_APP_SECRET');
    if(!is_string($signed)||($payload=lc_meta_verify_signed_request($signed,$secret))===null){
        http_response_code(403);echo '{"error":"invalid_signature"}';return true;
    }
    // Never equate Meta app-scoped identity with Instagram Login user ID.
    // Persist the signed request for manual reconciliation after a verified mapping exists.
    $userId=$payload['user_id']??null;
    if(!is_scalar($userId)||!preg_match('/^[0-9]{1,40}$/',(string)$userId)){
        http_response_code(422);echo '{"error":"invalid_user_id"}';return true;
    }
    $eventHash=hash_hmac('sha256',$signed,$secret);
    $userHash=hash_hmac('sha256',(string)$userId,$secret);
    try {
        lc_query("INSERT IGNORE INTO meta_deauthorization_inbox
          (event_hash,app_scoped_user_hash) VALUES (?,?)",[$eventHash,$userHash]);
    }catch(Throwable $e){
        error_log('LeagueCut signed Meta callback persistence unavailable');
        http_response_code(503);echo '{"error":"temporarily_unavailable"}';return true;
    }
    http_response_code(202);
    echo '{"status":"received_pending_identity_reconciliation"}';
    return true;
}
