<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/core.php';
require dirname(__DIR__).'/app/ui.php';

function trophy_check(bool $ok,string $what):void {
    if(!$ok)throw new RuntimeException('RANK_TROPHY_TEST_FAILED: '.$what);
}
$colors=[1=>'gold',2=>'silver',3=>'bronze'];
foreach($colors as $rank=>$color){
    $html=lc_rank_trophy($rank);
    trophy_check(str_contains($html,'lc-rank-trophy--'.$color),'top '.$rank.' gets '.$color);
    trophy_check(str_contains($html,'data-rank-medal="'.$rank.'"'),'position data for '.$rank);
    trophy_check(str_contains($html,'aria-label='),'accessible position '.$rank);
    trophy_check(str_contains($html,'#trophy'),'bundled SVG used for '.$rank);
    trophy_check(substr_count($html,'lc-rank-trophy-icon')===1,'one icon in '.$rank);
}
foreach([-3,0,4,5,50,999] as $rank){
    trophy_check(lc_rank_trophy($rank)==='','no trophy below podium at rank '.$rank);
}
$css=file_get_contents(dirname(__DIR__).'/public/assets/rank-trophies.css');
trophy_check(is_string($css),'trophy stylesheet exists');
foreach(['prefers-reduced-motion:reduce','hover:hover','max-width:600px','infinite'] as $feature){
    trophy_check(str_contains($css,$feature),'responsive animation supports '.$feature);
}
echo "LEAGUECUT_RANK_TROPHIES_OK\n";
