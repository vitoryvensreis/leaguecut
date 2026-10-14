<?php
declare(strict_types=1);

/**
 * Authenticated playback of a clip's original, privately streamed from Google
 * Drive. Never expose Drive OAuth tokens, signed public links, or file IDs in
 * user-entered URLs. Only the owner and administrators can watch.
 */
function lc_clip_video_route(string $method, string $path): bool {
    if(!preg_match('~^/clipes/([0-9]+)/video$~D',$path,$matches))return false;
    if($method!=='GET'){http_response_code(405);header('Allow: GET');return true;}
    $user=lc_login_required();
    $clip=lc_one('SELECT id,user_id,drive_file_id,original_filename FROM clips WHERE id=?',[(int)$matches[1]]);
    if(!$clip){http_response_code(404);return true;}
    if($user['role']!=='admin' && ((int)$user['id']!==(int)$clip['user_id'] || $user['status']!=='approved')){
        http_response_code(403);return true;
    }
    $id=(string)($clip['drive_file_id']??'');
    if(!lc_drive_url($id)){http_response_code(404);return true;}

    // An already-authenticated session is sufficient. Release PHP session lock
    // during video playback so navigation and other requests are not blocked.
    if(session_status()===PHP_SESSION_ACTIVE)session_write_close();
    try{
        $client=lc_drive_authenticated_client();
        $drive=new \Google\Service\Drive($client);
        $file=$drive->files->get($id,['fields'=>'id,mimeType,size,trashed']);
        $size=(int)($file->size??0);
        $mime=(string)($file->mimeType??'');
        if($size<1 || $size>1024*1024*1024 || !in_array($mime,['video/mp4','video/webm','video/quicktime'],true) || $file->trashed){
            http_response_code(415);return true;
        }
        $start=0;$end=$size-1;$partial=false;
        $range=(string)($_SERVER['HTTP_RANGE']??'');
        if($range!==''){
            // Only one explicit byte range supported; prevents resource abuse.
            if(!preg_match('/^bytes=(\d*)-(\d*)$/D',$range,$m) || ($m[1]==='' && $m[2]==='')){
                header('Content-Range: bytes */'.$size);http_response_code(416);return true;
            }
            if($m[1]===''){
                $suffix=(int)$m[2];
                if($suffix<1){header('Content-Range: bytes */'.$size);http_response_code(416);return true;}
                $start=max(0,$size-$suffix);
            }else{
                $start=(int)$m[1];
                $end=$m[2]===''?$size-1:min($size-1,(int)$m[2]);
            }
            if($start >= $size || $end < $start){header('Content-Range: bytes */'.$size);http_response_code(416);return true;}
            $partial=true;
        }
        // Bound each upstream request to 8 MB so streaming doesn't reserve a
        // PHP process indefinitely for a single open video.
        $end=min($end,$start+(8*1024*1024)-1);
        $bytes=$end-$start+1;
        $token=(string)($client->getAccessToken()['access_token']??'');
        if($token==='')throw new RuntimeException('Google Drive indisponível.');

        header('Content-Type: '.$mime);
        header('Content-Disposition: inline; filename="clip-'.(int)$clip['id'].'.'.($mime==='video/webm'?'webm':($mime==='video/quicktime'?'mov':'mp4')).'"');
        header('Accept-Ranges: bytes');
        header('Cache-Control: private, no-store, max-age=0');
        header('X-Content-Type-Options: nosniff');
        header('X-Accel-Buffering: no');
        header('Content-Length: '.$bytes);
        // Return 206 for requested ranges, or for internally chunked responses.
        if($partial||$bytes!==$size){
            http_response_code(206);
            header('Content-Range: bytes '.$start.'-'.$end.'/'.$size);
        }
        $handle=curl_init('https://www.googleapis.com/drive/v3/files/'.rawurlencode($id).'?alt=media');
        if(!$handle)throw new RuntimeException('Falha ao iniciar proxy do vídeo.');
        curl_setopt_array($handle,[
            CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$token,'Range: bytes='.$start.'-'.$end],
            CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT=>10,
            CURLOPT_TIMEOUT=>80,CURLOPT_BUFFERSIZE=>65536,
            CURLOPT_WRITEFUNCTION=>static function($ch,string $chunk): int{
                echo $chunk;
                if(function_exists('flush'))flush();
                return strlen($chunk);
            }
        ]);
        $ok=curl_exec($handle);$status=curl_getinfo($handle,CURLINFO_HTTP_CODE);
        curl_close($handle);
        if($ok===false || !in_array($status,[200,206],true)){
            error_log('LeagueCut streaming: falha de origem Google Drive');
        }
    }catch(Throwable $e){
        error_log('LeagueCut streaming: origem não disponível');
        if(!headers_sent()){http_response_code(502);header('Content-Type: text/plain; charset=UTF-8');echo 'Prévia temporariamente indisponível.';}
    }
    return true;
}
