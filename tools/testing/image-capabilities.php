<?php
try {
    require __DIR__.'/guard.php';
    if (!extension_loaded('gd')) throw new RuntimeException('GD is required');
    $info=gd_info();
    foreach (['JPEG Support','PNG Support','WebP Support'] as $format) {
        if (($info[$format] ?? false) !== true) throw new RuntimeException('Missing '.$format);
    }
    $source=imagecreatetruecolor(16,12);
    imagefill($source,0,0,imagecolorallocate($source,20,40,80));
    $derivative=imagecreatetruecolor(8,6);
    if (!imagecopyresampled($derivative,$source,0,0,0,0,8,6,16,12)) throw new RuntimeException('Resize failed');
    $verified=[];
    foreach (['jpeg'=>'image/jpeg','png'=>'image/png','webp'=>'image/webp'] as $extension=>$mime) {
        $file=getenv('TMPDIR').'/gd-capability.'.$extension;
        try {
            $write='image'.$extension;
            $read='imagecreatefrom'.$extension;
            if (!$write($derivative,$file)) throw new RuntimeException('Encode failed: '.$extension);
            $size=getimagesize($file);
            $decoded=$read($file);
            if (!$decoded || imagesx($decoded)!==8 || imagesy($decoded)!==6 || $size['mime']!==$mime) throw new RuntimeException('Decode failed: '.$extension);
            $verified[$extension]=['mime'=>$size['mime'],'width'=>imagesx($decoded),'height'=>imagesy($decoded),'bytes'=>filesize($file)];
        } finally { if (is_file($file)) unlink($file); }
    }
    echo json_encode(['gd'=>$info,'resampledRoundTrips'=>$verified],JSON_PRETTY_PRINT).PHP_EOL;
} catch (Throwable $error) { fwrite(STDERR,$error->getMessage().PHP_EOL);exit(1); }
