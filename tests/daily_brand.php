<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/core.php';
require dirname(__DIR__).'/app/brand.php';
require dirname(__DIR__).'/app/apify.php';

$clean='<svg xmlns="http://www.w3.org/2000/svg" width="200" height="100" viewBox="0 0 200 100"><title>Marca</title><rect width="200" height="100" fill="#e50914"/><path d="M10 10 L80 80" stroke="white"/></svg>';
$safe=lc_brand_sanitize_svg($clean);
if(!str_contains($safe,'<rect')||!str_contains($safe,'<path'))throw new RuntimeException('SVG esperado foi danificado.');

$dirty='<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"><rect width="1" height="1" onclick="javascript:alert(1)"/></svg>';
$washed=lc_brand_sanitize_svg($dirty);
if(stripos($washed,'onload')!==false||stripos($washed,'onclick')!==false||stripos($washed,'javascript')!==false)
 throw new RuntimeException('Atributo perigoso preservado.');

foreach([
 '<!DOCTYPE svg [ <!ENTITY xx SYSTEM "file:///etc/passwd"> ]><svg xmlns="http://www.w3.org/2000/svg">&xx;</svg>',
 '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>',
 '<svg xmlns="http://www.w3.org/2000/svg"><foreignObject><p>test</p></foreignObject></svg>',
 '<svg xmlns="http://www.w3.org/2000/svg"><use href="https://evil.example/icon.svg#x"/></svg>',
] as $unsafe){
    try{lc_brand_sanitize_svg($unsafe);throw new RuntimeException('SVG inseguro aceito');}
    catch(RuntimeException $e){if($e->getMessage()==='SVG inseguro aceito')throw $e;}
}
if(!lc_brand_logo_src('/media/brand/'.str_repeat('a',36))||
 lc_brand_logo_src('/media/brand/../.env')||
 lc_brand_logo_src('javascript:alert(1)'))throw new RuntimeException('Validação do link da logo falhou.');

$multi=lc_apify_official_batch_input([
  'https://www.instagram.com/reel/DA6aVUpytOB/?utm_source=test',
  'https://www.instagram.com/p/XYZ98765432/'
]);
if(count($multi['username'])!==2||$multi['resultsLimit']!==2 ||
 $multi['dataDetailLevel']!=='detailedData')throw new RuntimeException('Apify batch inválido.');
try{
    lc_apify_official_batch_input(['https://evil.example/reel/DA6aVUpytOB/']);
    throw new RuntimeException('URL de outro domínio aceita');
}catch(InvalidArgumentException $e){}

$approved=lc_apify_eligible_clips();
if(!$approved)throw new RuntimeException('Fixture de clipe aprovado não encontrada.');
$clip=(int)$approved[0]['id'];
$db=lc_db();$db->beginTransaction();
try{
  if(!lc_apify_observe($clip,123))throw new RuntimeException('Snapshot diário não criado.');
  $today=(new DateTimeImmutable('now',new DateTimeZone('America/Fortaleza')))->format('Y-m-d');
  $row=lc_one('SELECT views,source FROM clip_daily_views WHERE clip_id=? AND snapshot_date=?',[$clip,$today]);
  if(!$row||(int)$row['views']!==123||$row['source']!=='apify')throw new RuntimeException('Snapshot não persistiu.');
  if(lc_apify_observe($clip,123))throw new RuntimeException('Duplicação de snapshot');
  if(!lc_apify_observe($clip,125))throw new RuntimeException('Atualização do snapshot no mesmo dia falhou.');
  $count=(int)(lc_one('SELECT COUNT(*) AS n FROM clip_daily_views WHERE clip_id=? AND snapshot_date=?',[$clip,$today])['n']??0);
  if($count!==1)throw new RuntimeException('Mais de um registro por vídeo/dia.');
}finally{$db->rollBack();}
echo "BRAND_SVG_SAFE_AND_DAILY_METRICS_OK\n";
