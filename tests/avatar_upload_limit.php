<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/clipper-media.php';
$mb=1024*1024;
$sizeLimit=LC_AVATAR_MAX_BYTES;
$default=(new ReflectionFunction('lc_clipper_image_store'))->getParameters()[2]->getDefaultValue();
if($sizeLimit!==10*$mb || $default!==4*$mb ||
   !lc_clipper_image_upload_size_valid(10*$mb,$sizeLimit) ||
   lc_clipper_image_upload_size_valid(10*$mb+1,$sizeLimit) ||
   lc_clipper_image_upload_size_valid(0,$sizeLimit) ||
   !lc_clipper_image_upload_size_valid(4*$mb,$default) ||
   lc_clipper_image_upload_size_valid(4*$mb+1,$default)) {
    fwrite(STDERR,"Avatar/banner image size limit contract failed.\n");exit(1);
}
echo "AVATAR_UPLOAD_10MB_OK\n";
