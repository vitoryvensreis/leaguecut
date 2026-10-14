<?php
declare(strict_types=1);

/** Uploads private files using the administrator-authorized Google Drive account.
 * No public share permission is created.
 * Google OAuth credentials and refresh tokens MUST remain outside the public directory.
 */
function lc_drive_ready(): bool {
    foreach (['GOOGLE_CLIENT_ID','GOOGLE_CLIENT_SECRET','GOOGLE_REFRESH_TOKEN','GOOGLE_DRIVE_FOLDER_ID'] as $key) {
        if (lc_env($key)==='') return false;
    }
    return class_exists(\Google\Client::class);
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

    $client = new \Google\Client();
    $client->setClientId(lc_env('GOOGLE_CLIENT_ID'));
    $client->setClientSecret(lc_env('GOOGLE_CLIENT_SECRET'));
    $client->setScopes(['https://www.googleapis.com/auth/drive.file']);
    $token = $client->fetchAccessTokenWithRefreshToken(lc_env('GOOGLE_REFRESH_TOKEN'));
    if (!empty($token['error'])) throw new RuntimeException('Autorização do Drive expirou. Contate o administrador.');

    $drive = new \Google\Service\Drive($client);
    $filename = sprintf('clip-%d-%s.%s',$userId,bin2hex(random_bytes(8)),$ext);
    $metadata = new \Google\Service\Drive\DriveFile(['name'=>$filename,'parents'=>[lc_env('GOOGLE_DRIVE_FOLDER_ID')]]);
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
