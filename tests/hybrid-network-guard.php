<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/hybrid-persistence.php';
$valid=[['instagram','instagram_graph'],['tiktok','tiktok_display'],['youtube','youtube_data']];
foreach($valid as [$network,$source])if(!lc_hybrid_source_matches_network($network,$source))throw new RuntimeException('Valid pair rejected');
foreach(['instagram','tiktok','youtube'] as $network)
 foreach(['instagram_graph','tiktok_display','youtube_data'] as $source)
  if(lc_hybrid_source_matches_network($network,$source)!==in_array([$network,$source],$valid,true))
   throw new RuntimeException('Cross-network metric allowed');
if(lc_hybrid_source_matches_network('instagram','untrusted_unknown'))throw new RuntimeException('Unknown source');
echo "Hybrid provider/network isolation OK\n";
