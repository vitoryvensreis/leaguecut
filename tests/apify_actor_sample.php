<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/core.php';
require dirname(__DIR__).'/app/apify.php';

$sample=[
  'id'=>'3475205876964053889',
  'post_url'=>'https://www.instagram.com/p/DA6aVUpytOB',
  'author'=>['id'=>'25025320','username'=>'instagram','full_name'=>'Instagram'],
  'is_video'=>true
];
if(lc_apify_data_shortcode($sample)!=='DA6aVUpytOB')throw new RuntimeException('Snake-case post_url not parsed');
if(lc_apify_data_author($sample)!=='instagram')throw new RuntimeException('Nested author.username not parsed');
if(lc_apify_post_views($sample,'instagram','DA6aVUpytOB')!==null)throw new RuntimeException('Views were fabricated when Actor did not return them');

$sample['video_view_count']=4321;
if(lc_apify_post_views($sample,'instagram','DA6aVUpytOB')!==4321)throw new RuntimeException('Reported views not parsed');
if(lc_apify_post_views($sample,'another.user','DA6aVUpytOB')!==null)throw new RuntimeException('Cross-user views leaked');
if(lc_apify_post_views($sample,'instagram','OTHER01234')!==null)throw new RuntimeException('Wrong shortcode matched');

if(lc_apify_shortcode('https://www.instagram.com/reel/DA6aVUpytOB/')!=='DA6aVUpytOB')throw new RuntimeException('Reel link not parsed');
if(lc_apify_shortcode('https://evil.com/p/DA6aVUpytOB')!==null)throw new RuntimeException('Untrusted host was accepted');
if(lc_apify_check_connection()!==false)throw new RuntimeException('Expected disabled when no token configured');
$example=json_encode([$sample],JSON_THROW_ON_ERROR);
if(count(lc_apify_parse_dataset_response(201,$example))!==1)throw new RuntimeException('Apify HTTP 201 success rejected');
if(count(lc_apify_parse_dataset_response(200,$example))!==1)throw new RuntimeException('Apify HTTP 200 success rejected');
try {lc_apify_parse_dataset_response(403,$example);throw new RuntimeException('Apify 403 mistakenly accepted');}
catch(RuntimeException $e){if(str_contains($e->getMessage(),'mistakenly'))throw $e;}
try {lc_apify_parse_dataset_response(201,'{"error":"bad"}');throw new RuntimeException('Error object mistakenly accepted');}
catch(RuntimeException $e){if(str_contains($e->getMessage(),'mistakenly'))throw $e;}

$official=lc_apify_official_input('https://www.instagram.com/reel/DA6aVUpytOB/?igsh=abcd');
if($official!==[
 'username'=>['https://www.instagram.com/reel/DA6aVUpytOB/'],
 'resultsLimit'=>1,'dataDetailLevel'=>'detailedData'
])throw new RuntimeException('Official URL input invalid');
if(lc_apify_official_input('https://www.instagram.com/p/DA6aVUpytOB/')!==[
 'username'=>['https://www.instagram.com/p/DA6aVUpytOB/'],
 'resultsLimit'=>1,'dataDetailLevel'=>'detailedData'
])throw new RuntimeException('Photo/post input invalid');
try{lc_apify_official_input('https://malicious.example/reel/DA6aVUpytOB/');throw new RuntimeException('Untrusted domain accepted');}
catch(InvalidArgumentException $e){}
$officialRow=[
 'shortCode'=>'DA6aVUpytOB','url'=>'https://www.instagram.com/reel/DA6aVUpytOB/',
 'ownerUsername'=>'creator.test','videoViewCount'=>1234,'videoPlayCount'=>4321
];
if(lc_apify_post_views($officialRow,'creator.test','DA6aVUpytOB')!==1234)
  throw new RuntimeException('Video views should be preferred over play count');
$officialRow['videoViewCount']=null;
if(lc_apify_post_views($officialRow,'creator.test','DA6aVUpytOB')!==4321)
  throw new RuntimeException('Video play count fallback absent');
unset($officialRow['videoPlayCount']);
if(lc_apify_post_views($officialRow,'creator.test','DA6aVUpytOB')!==null)
  throw new RuntimeException('Missing views improperly treated as real');

echo "APIFY_ACTOR_SAMPLE_OK\n";
