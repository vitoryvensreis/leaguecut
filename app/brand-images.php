<?php
declare(strict_types=1);
require_once __DIR__ . '/brand-text-masks.php';

/**
 * Deterministic LeagueCut branding images with no GD, Imagick, Node or external
 * font dependency on the production host. SVG source remains in public/assets/.
 */
function lc_brand_png_chunk(string $name,string $bytes): string {
    return pack('N',strlen($bytes)).$name.$bytes.pack('N',crc32($name.$bytes));
}
function lc_brand_png(int $w,int $h,callable $paint): string {
    $raw='';
    for($y=0;$y<$h;$y++){
        $raw.="\x00";
        for($x=0;$x<$w;$x++){
            $rgb=$paint($x,$y);
            $raw.=chr($rgb[0]).chr($rgb[1]).chr($rgb[2])."\xff";
        }
    }
    return "\x89PNG\r\n\x1a\n".
        lc_brand_png_chunk('IHDR',pack('NNCCCCC',$w,$h,8,6,0,0,0)).
        lc_brand_png_chunk('IDAT',gzcompress($raw,8)).
        lc_brand_png_chunk('IEND','');
}
function lc_brand_polygon(float $x,float $y,array $path): bool {
    $inside=false;$j=count($path)-1;
    foreach($path as $i=>$p){
        $a=$path[$j];
        if(($p[1]>$y)!==($a[1]>$y) &&
            $x<($a[0]-$p[0])*($y-$p[1])/($a[1]-$p[1])+$p[0])
            $inside=!$inside;
        $j=$i;
    }
    return $inside;
}
/** Coordinates match the bundled red cinematic L/play SVG, normalized 0..1. */
function lc_brand_icon_pixel(float $u,float $v): array {
    static $mark=[
        [.303,.213],[.412,.162],[.357,.645],[.721,.449],
        [.684,.637],[.354,.809],[.264,.781],[.232,.727],[.235,.633]
    ];
    static $play=[[.470,.350],[.627,.449],[.626,.462],[.470,.555]];
    // Solid tile with softened crimson rim, safe for iOS and maskable icons.
    $base=[9,11,16];
    if($u<0||$v<0||$u>=1||$v>=1)return $base;
    $dx=min($u,1-$u);$dy=min($v,1-$v);
    if($dx<.026||$dy<.026)$base=[13,10,17];
    if(lc_brand_polygon($u,$v,$play))return [255,242,246];
    if(lc_brand_polygon($u,$v,$mark)){
        return [max(174,min(255,(int)(255-42*$v))),max(4,(int)(43-32*$v)),max(32,(int)(88-60*$v))];
    }
    return $base;
}
function lc_brand_icon_png(int $size,bool $maskable=false): string {
    if(!in_array($size,[32,48,180,192,512],true))
        throw new InvalidArgumentException('Tamanho do ícone não permitido.');
    return lc_brand_png($size,$size,static function(int $x,int $y)use($size,$maskable):array {
        $u=($x+.5)/$size;$v=($y+.5)/$size;
        if($maskable){$u=($u-.5)/.82+.5;$v=($v-.5)/.82+.5;}
        if($size>=192)return lc_brand_icon_pixel($u,$v);
        // Supersample small browser icons for edge readability.
        $samples=[[.25,.25],[.75,.25],[.25,.75],[.75,.75]];
        $sums=[0,0,0];
        foreach($samples as $s){
            $p=lc_brand_icon_pixel(($x+$s[0])/$size,($y+$s[1])/$size);
            for($k=0;$k<3;$k++)$sums[$k]+=$p[$k];
        }
        return [intdiv($sums[0],4),intdiv($sums[1],4),intdiv($sums[2],4)];
    });
}
function lc_brand_favicon_ico(): string {
    $png=lc_brand_icon_png(32);
    return pack('vvv',0,1,1).pack('CCCCvvVV',32,32,0,0,1,32,strlen($png),22).$png;
}
function lc_brand_blend(array $behind,array $front,int $alpha):array {
    if($alpha<1)return $behind;
    if($alpha>=255)return $front;
    return [
        intdiv($front[0]*$alpha+$behind[0]*(255-$alpha),255),
        intdiv($front[1]*$alpha+$behind[1]*(255-$alpha),255),
        intdiv($front[2]*$alpha+$behind[2]*(255-$alpha),255)
    ];
}
/** Font shapes are raster masks rendered offline; no font binaries are shipped. */
function lc_brand_mask_alpha(string $text,int $x,int $y): int {
    static $masks=null;
    if($masks===null)$masks=lc_brand_masks();
    $m=$masks[$text]??null;
    if(!$m||$x<0||$y<0||$x>=$m['w']||$y>=$m['h'])return 0;
    return ord($m['mask'][$y*$m['w']+$x]);
}
function lc_brand_social_png():string {
    return lc_brand_png(1200,630,static function(int $x,int $y):array {
        $fade=($x/1200+$y/630)*.5;
        $rgb=[max(5,(int)(16-7*$fade)),max(6,(int)(18-8*$fade)),max(9,(int)(27-18*$fade))];
        if($x>925-($y*.42))$rgb=[max(7,$rgb[0]+8),max(6,$rgb[1]-2),max(10,$rgb[2]+1)];
        if($x>=72&&$x<454&&$y>=125&&$y<507){
            $u=($x-72)/382;$v=($y-125)/382;
            $edge=min($u,1-$u,$v,1-$v);
            if($edge>.015)$rgb=lc_brand_icon_pixel($u,$v);
        }
        // Font masks preserve clean, antialiased branding without server fonts.
        foreach([['league',520,218,[244,247,252]],['cut',877,235,[255,37,73]],
                 ['tag',521,350,[172,179,196]]] as [$name,$left,$top,$ink]){
            $alpha=lc_brand_mask_alpha($name,$x-$left,$y-$top);
            if($alpha>0)$rgb=lc_brand_blend($rgb,$ink,$alpha);
        }
        if($x>=522&&$x<=670&&$y>=404&&$y<=409)$rgb=[248,21,64];
        return $rgb;
    });
}
