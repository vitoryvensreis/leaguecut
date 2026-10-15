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
    // A Meta deauthorization user_id may be app-scoped and is not necessarily
    // the same identifier as an Instagram Login user_id. Never guess ownership.
    // Fail closed rather than pretend revocation succeeded if account matching
    // is unavailable or the OAuth migration was not deployed.
    http_response_code(503);
    echo '{"error":"deauthorization_requires_identity_mapping"}';
    return true;
}
