<?php
declare(strict_types=1);

/** Brand assets live in shared private storage and survive GitHub releases. */
function lc_brand_logo_dir(): string {
    return lc_env('BRAND_MEDIA_DIR','/home/leaguecut/shared/storage/brand');
}
function lc_brand_logo_src(string $url): bool {
    return lc_valid_https($url) || (bool)preg_match('~^/media/brand/[a-f0-9]{36}$~D',$url);
}
/**
 * SVG allowlist sanitizer, without external references, scripts, embedded CSS,
 * DTDs, events or foreignObject. Fail closed when PHP DOM extension is absent.
 */
function lc_brand_sanitize_svg(string $source): string {
    if(strlen($source)>3*1024*1024 || stripos($source,'<!DOCTYPE')!==false ||
       stripos($source,'<!ENTITY')!==false || !class_exists(DOMDocument::class)) {
        throw new RuntimeException('SVG não suportado ou contém recursos não permitidos.');
    }
    $dom=new DOMDocument('1.0','UTF-8');
    $before=libxml_use_internal_errors(true);
    try {
        $ok=$dom->loadXML($source,LIBXML_NONET|LIBXML_COMPACT);
    }finally{
        libxml_clear_errors();
        libxml_use_internal_errors($before);
    }
    if(!$ok || !$dom->documentElement || $dom->doctype ||
       $dom->documentElement->localName!=='svg' ||
       $dom->documentElement->namespaceURI!=='http://www.w3.org/2000/svg')
        throw new RuntimeException('SVG inválido: envie um SVG vetorial padrão, sem DTD.');
    $tags=array_fill_keys(explode(' ','svg g defs title desc path rect circle ellipse polygon polyline line text tspan linearGradient radialGradient stop clipPath mask pattern symbol use'),true);
    $attrs=array_fill_keys(explode(' ','xmlns version id viewBox width height x y x1 y1 x2 y2 cx cy r rx ry d points transform fill fill-opacity fill-rule stroke stroke-width stroke-opacity stroke-linecap stroke-linejoin stroke-dasharray stroke-dashoffset opacity stop-color stop-opacity offset gradientUnits gradientTransform spreadMethod preserveAspectRatio clip-path clip-rule mask stroke-miterlimit text-anchor font-family font-size font-weight letter-spacing dominant-baseline'),true);
    $walk=function(DOMNode $node) use (&$walk,$tags,$attrs): void {
        if($node->nodeType===XML_COMMENT_NODE){$node->parentNode?->removeChild($node);return;}
        if($node->nodeType!==XML_ELEMENT_NODE){
            if(!in_array($node->nodeType,[XML_TEXT_NODE,XML_CDATA_SECTION_NODE],true))
                throw new RuntimeException('SVG com conteúdo não permitido.');
            return;
        }
        $element=$node;
        if($element->namespaceURI!=='http://www.w3.org/2000/svg' || !isset($tags[$element->localName]))
            throw new RuntimeException('SVG com elementos não permitidos. Remova scripts, estilos embutidos ou imagens externas.');
        foreach(iterator_to_array($element->attributes) as $attr){
            $name=$attr->name;$value=trim($attr->value);
            if($name==='xmlns')continue;
            if($name==='href'||$name==='xlink:href'){
                if($element->localName!=='use'||!preg_match('/^#[A-Za-z_][A-Za-z0-9_.-]{0,120}$/D',$value))
                   throw new RuntimeException('SVG com referência externa não permitida.');
                continue;
            }
            if(!isset($attrs[$name]) ||
               preg_match('/(?:javascript|data:|https?:|@import|expression|[<>])/', $value) ||
               (str_contains(strtolower($value),'url(') &&
                 !preg_match('/^url\(\s*#[a-zA-Z_][\w.-]{0,120}\s*\)$/D',$value))){
                $element->removeAttributeNode($attr);
            }
        }
        foreach(iterator_to_array($element->childNodes) as $child)$walk($child);
    };
    $walk($dom->documentElement);
    $result=$dom->saveXML($dom->documentElement);
    if(!is_string($result)||strlen($result)<10)throw new RuntimeException('Falha ao limpar SVG.');
    return $result;
}
function lc_brand_store_logo(array $upload): string {
    if(($upload['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK ||
       !is_uploaded_file((string)($upload['tmp_name']??'')))
       throw new RuntimeException('Não foi possível receber a logo.');
    $size=(int)($upload['size']??0);
    if($size<1||$size>5*1024*1024)throw new RuntimeException('A logo deve ter no máximo 5 MB.');
    $mime=(new finfo(FILEINFO_MIME_TYPE))->file((string)$upload['tmp_name']);
    $types=['image/png'=>'png','image/jpeg'=>'jpg','image/webp'=>'webp','image/gif'=>'gif',
            'image/avif'=>'avif','image/svg+xml'=>'svg'];
    $ext=$types[$mime]??null;
    // Some PHP fileinfo versions identify SVG as text/xml.
    $raw=$ext===null?file_get_contents((string)$upload['tmp_name']):null;
    if(!$ext && in_array($mime,['text/xml','application/xml','text/plain'],true)
       && is_string($raw) && str_contains($raw,'<svg')) {
        $ext='svg';$mime='image/svg+xml';
    }
    if($ext===null)throw new RuntimeException('Formato não aceito. Envie PNG, JPG, WebP, GIF, AVIF ou SVG.');
    if($ext==='svg'){
        $safe=lc_brand_sanitize_svg((string)file_get_contents((string)$upload['tmp_name']));
    }else{
        $info=@getimagesize((string)$upload['tmp_name']);
        if(!$info||($info['mime']??'')!==$mime || $info[0]<1||$info[1]<1||$info[0]>12000||$info[1]>12000)
          throw new RuntimeException('Imagem inválida ou grande demais.');
    }
    $dir=lc_brand_logo_dir();
    if(!is_dir($dir) && !mkdir($dir,0700,true) && !is_dir($dir))
        throw new RuntimeException('Não foi possível criar a pasta privada da marca.');
    $token=bin2hex(random_bytes(18));
    $filename=$token.'.'.$ext;
    $target=$dir.'/'.$filename;
    if($ext==='svg'){
        if(file_put_contents($target,$safe,LOCK_EX)===false)throw new RuntimeException('Falha ao salvar SVG.');
    }elseif(!move_uploaded_file((string)$upload['tmp_name'],$target)){
        throw new RuntimeException('Falha ao armazenar a logo.');
    }
    chmod($target,0600);
    // Store only an opaque path in public settings, never a filesystem path.
    return '/media/brand/'.$token;
}
function lc_brand_logo_response(string $token): never {
    $selected=(string)(lc_one("SELECT setting_value FROM platform_settings WHERE setting_key='logo_url'")['setting_value']??'');
    if($selected!=='/media/brand/'.$token){http_response_code(404);exit;}
    $dir=lc_brand_logo_dir();
    $found=glob($dir.'/'.$token.'.*')?:[];
    if(count($found)!==1){http_response_code(404);exit;}
    $path=$found[0];$ext=strtolower(pathinfo($path,PATHINFO_EXTENSION));
    $types=['png'=>'image/png','jpg'=>'image/jpeg','webp'=>'image/webp','gif'=>'image/gif',
            'avif'=>'image/avif','svg'=>'image/svg+xml'];
    if(!isset($types[$ext])||!is_file($path)||!is_readable($path)){http_response_code(404);exit;}
    header('Content-Type: '.$types[$ext]);
    header('X-Content-Type-Options: nosniff');
    header("Content-Security-Policy: default-src 'none'; sandbox");
    header('Cache-Control: public, max-age=600');
    header('Content-Length: '.filesize($path));
    readfile($path);exit;
}
