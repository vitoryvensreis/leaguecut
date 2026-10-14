<?php
declare(strict_types=1);

/** Uploads private files using the administrator-authorized Google Drive account.
 * No public share permission is created.
 * Google OAuth credentials and refresh tokens MUST remain outside the public directory.
 */
function lc_drive_ready(): bool {
    if(lc_env('GOOGLE_CLIENT_ID')==='' || lc_env('GOOGLE_CLIENT_SECRET')==='')return false;
    if(!class_exists(\Google\Client::class))return false;
    $connected=lc_drive_connection();
    return (bool)($connected && $connected['folder_id']) ||
      (lc_env('GOOGLE_REFRESH_TOKEN')!=='' && lc_env('GOOGLE_DRIVE_FOLDER_ID')!=='');
}
function lc_drive_upload(array $upload, int $userId): array {
    if (!lc_drive_ready()) throw new RuntimeException('O Google Drive ainda não foi habilitado pelo administrador. Envie apenas o link publicado por enquanto.');
    if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string)($upload['tmp_name'] ?? ''))) throw new RuntimeException('Arquivo inválido.');
    if ((int)$upload['size'] <= 0 || (int)$upload['size'] > 1024*1024*1024) throw new RuntimeException('O limite atual é 1 GB por vídeo.');
    $ext = strtolower(pathinfo((string)$upload['name'],PATHINFO_EXTENSION));
    $types = ['mp4'=>'video/mp4','mov'=>'video/quicktime','webm'=>'video/webm'];
    if (!isset($types[$ext])) throw new RuntimeException('Envie um vídeo MP4, MOV ou WebM.');
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file((string)$upload['tmp_name']);
    if (!in_array($mime,[$types[$ext],'application/octet-stream'],true)) throw new RuntimeException('Formato de vídeo não reconhecido.');

    $client=lc_drive_authenticated_client();
    $drive=new \Google\Service\Drive($client);
    $filename = sprintf('clip-%d-%s.%s',$userId,bin2hex(random_bytes(8)),$ext);
    $metadata = new \Google\Service\Drive\DriveFile(['name'=>$filename,'parents'=>[lc_drive_folder_id()]]);
    $client->setDefer(true);
    try {
        $request = $drive->files->create($metadata,['fields'=>'id']);
        $chunkSize = 4*1024*1024;
        $media = new \Google\Http\MediaFileUpload($client,$request,$types[$ext],null,true,$chunkSize);
        $media->setFileSize((int)$upload['size']);
        $handle = fopen((string)$upload['tmp_name'],'rb');
        if (!$handle) throw new RuntimeException('Não foi possível ler o vídeo recebido.');
        try {
            $status = false;
            while (!$status && !feof($handle)) {
                $chunk = fread($handle,$chunkSize);
                if ($chunk === false) throw new RuntimeException('Erro de leitura do vídeo.');
                $status = $media->nextChunk($chunk);
            }
        } finally { fclose($handle); }
    } finally { $client->setDefer(false); }
    $id = $status->id ?? null;
    if (!$id) throw new RuntimeException('O Drive não confirmou o armazenamento do arquivo.');
    return ['id'=>(string)$id,'filename'=>$filename];
}


/** OAuth tokens are encrypted at rest and excluded from public settings tables. */
function lc_drive_connection(): ?array {
    return lc_one('SELECT id,refresh_token_enc,folder_id,connected_at FROM google_drive_connections WHERE id=1');
}
function lc_drive_key(): string {
    $key=base64_decode(lc_env('APP_KEY'),true);
    if($key===false || strlen($key)!==32)throw new RuntimeException('APP_KEY inválida; não é possível guardar autorização Google com segurança.');
    return $key;
}
function lc_drive_encrypt_token(string $plain): string {
    if($plain==='' || strlen($plain)>4096)throw new InvalidArgumentException('Token OAuth inválido.');
    $iv=random_bytes(12);
    $tag='';
    $cipher=openssl_encrypt($plain,'aes-256-gcm',lc_drive_key(),OPENSSL_RAW_DATA,$iv,$tag,'leaguecut.drive.oauth.v1');
    if($cipher===false || strlen($tag)!==16)throw new RuntimeException('Falha na criptografia OAuth.');
    return 'v1:'.base64_encode($iv.$tag.$cipher);
}
function lc_drive_decrypt_token(string $stored): string {
    if(!str_starts_with($stored,'v1:'))throw new RuntimeException('Formato de credencial inválido.');
    $data=base64_decode(substr($stored,3),true);
    if($data===false||strlen($data)<29)throw new RuntimeException('Credencial criptografada inválida.');
    $plain=openssl_decrypt(substr($data,28),'aes-256-gcm',lc_drive_key(),OPENSSL_RAW_DATA,substr($data,0,12),substr($data,12,16),'leaguecut.drive.oauth.v1');
    if($plain===false || $plain==='')throw new RuntimeException('Credencial não pôde ser descriptografada.');
    return $plain;
}
function lc_drive_folder_id(): string {
    $connection=lc_drive_connection();
    return (string)($connection['folder_id']??lc_env('GOOGLE_DRIVE_FOLDER_ID'));
}
function lc_drive_refresh_token(): string {
    $connection=lc_drive_connection();
    if($connection)return lc_drive_decrypt_token((string)$connection['refresh_token_enc']);
    return lc_env('GOOGLE_REFRESH_TOKEN');
}
function lc_drive_client(): \Google\Client {
    if(lc_env('GOOGLE_CLIENT_ID')===''||lc_env('GOOGLE_CLIENT_SECRET')===''){
        throw new RuntimeException('Configure GOOGLE_CLIENT_ID e GOOGLE_CLIENT_SECRET no .env privado.');
    }
    if(!class_exists(\Google\Client::class))throw new RuntimeException('Dependência Google API indisponível.');
    $client=new \Google\Client();
    $client->setClientId(lc_env('GOOGLE_CLIENT_ID'));
    $client->setClientSecret(lc_env('GOOGLE_CLIENT_SECRET'));
    $client->setScopes(['https://www.googleapis.com/auth/drive.file']);
    $client->setRedirectUri(rtrim(lc_env('APP_URL','https://leaguecut.com'),'/').'/admin/integracoes/google/callback');
    return $client;
}
function lc_drive_authenticated_client(): \Google\Client {
    $client=lc_drive_client();
    $refresh=lc_drive_refresh_token();
    if($refresh==='')throw new RuntimeException('Drive ainda não conectado pelo administrador.');
    try {
        $access=$client->fetchAccessTokenWithRefreshToken($refresh);
        if(!is_array($access)||!empty($access['error'])||empty($access['access_token'])){
            throw new RuntimeException('Token OAuth não disponível.');
        }
    }catch(Throwable $e){
        throw new RuntimeException('Não foi possível autenticar no Google Drive. Reconecte a conta pelo painel ADM.',0,$e);
    }
    return $client;
}
function lc_drive_url(string $id): ?string {
    if(!preg_match('/^[A-Za-z0-9_-]{10,180}$/D',$id))return null;
    return 'https://drive.google.com/file/d/'.rawurlencode($id).'/view';
}
function lc_drive_start_oauth(int $adminId): string {
    if($adminId<1)throw new InvalidArgumentException('Administrador inválido.');
    $client=lc_drive_client();
    $client->setAccessType('offline');
    $client->setPrompt('consent');
    $state=bin2hex(random_bytes(24));
    $_SESSION['drive_oauth']=['state'=>$state,'admin_id'=>$adminId,'created'=>time()];
    $client->setState($state);
    return $client->createAuthUrl();
}
/** Only run from a verified OAuth callback with an authenticated admin session. */
function lc_drive_finish_oauth(int $adminId,string $code,string $state): void {
    $saved=$_SESSION['drive_oauth']??null;
    unset($_SESSION['drive_oauth']);
    if(!is_array($saved)||!is_string($saved['state']??null)||!hash_equals($saved['state'],$state)||
       ($saved['admin_id']??null)!==$adminId||time()-(int)($saved['created']??0)>600||
       time()<(int)($saved['created']??0)){
        throw new RuntimeException('Autorização Google expirada ou inválida. Inicie novamente pelo painel.');
    }
    if($code===''||strlen($code)>3000)throw new RuntimeException('Código OAuth inválido.');
    $client=lc_drive_client();
    try {
        $result=$client->fetchAccessTokenWithAuthCode($code);
        if(!is_array($result)||!empty($result['error'])||empty($result['refresh_token'])){
            throw new RuntimeException('Google não forneceu refresh token.');
        }
        $client->setAccessToken($result);
        $service=new \Google\Service\Drive($client);
        // A newly created application-owned folder works with least-privilege drive.file.
        $folder=$service->files->create(
           new \Google\Service\Drive\DriveFile(['name'=>'LeagueCut - Clipes privados','mimeType'=>'application/vnd.google-apps.folder']),
           ['fields'=>'id,name,mimeType']
        );
        $folderId=(string)($folder->id??'');
        if(!preg_match('/^[A-Za-z0-9_-]{10,180}$/D',$folderId)){
            throw new RuntimeException('Pasta não confirmada pelo Google.');
        }
        $encrypted=lc_drive_encrypt_token((string)$result['refresh_token']);
        lc_query('INSERT INTO google_drive_connections(id,refresh_token_enc,folder_id,connected_by) VALUES(1,?,?,?)
          ON DUPLICATE KEY UPDATE refresh_token_enc=VALUES(refresh_token_enc),folder_id=VALUES(folder_id),connected_by=VALUES(connected_by),connected_at=NOW()',
          [$encrypted,$folderId,$adminId]);
    }catch(Throwable $e){
        error_log('LeagueCut Google Drive OAuth: falha ao concluir conexão (detalhes reservados ao servidor).');
        throw new RuntimeException('Não foi possível conectar o Google Drive. Verifique o consentimento OAuth, a API Drive e o URI de redirecionamento.',0,$e);
    }
}
function lc_drive_check_folder(): bool {
    if(!lc_drive_ready())return false;
    $drive=new \Google\Service\Drive(lc_drive_authenticated_client());
    $folder=$drive->files->get(lc_drive_folder_id(),['fields'=>'id,mimeType,trashed']);
    return (string)($folder->mimeType??'')==='application/vnd.google-apps.folder' && !$folder->trashed;
}
