<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/core.php';
require dirname(__DIR__).'/app/ui.php';
$unsafe='<script>alert("hack")</script>';
$expected=lc_private($unsafe);
if(!str_contains($expected,'data-lc-private')||
   !str_contains($expected,'&lt;script&gt;')||
   str_contains($expected,'<script>')||
   !str_contains($expected,'class="lc-private-value"'))
    throw new RuntimeException('PRIVACY_ESCAPE_FAILED');
if(!str_contains(lc_private('R$ 12,00'),'R$ 12,00'))
    throw new RuntimeException('PRIVACY_AMOUNT_MISSING');
echo "B3_PRIVACY_OK\n";
