<?php
// Reproducible PNG app icons, using the existing MKM logo and native GD drawing.
$root=dirname(__DIR__);$directory=$root.'/public/pwa/icons';if(!is_dir($directory))mkdir($directory,0755,true);
$font='C:/Windows/Fonts/segoeuib.ttf';
foreach(['general'=>[0,103,127],'security'=>[23,54,75]] as $kind=>$rgb){
 $im=imagecreatetruecolor(512,512);imageantialias($im,true);$bg=imagecolorallocate($im,...$rgb);$white=imagecolorallocate($im,255,255,255);$accent=imagecolorallocate($im,64,208,195);imagefill($im,0,0,$bg);
 imagefilledellipse($im,256,254,386,386,imagecolorallocate($im,...array_map(fn($v)=>min(255,$v+9),$rgb)));
 imagefilledrectangle($im,150,109,362,195,$white);$logo=imagecreatefrompng($root.'/public/assets/img/mms.png');$scale=min(188/imagesx($logo),65/imagesy($logo));$w=(int)(imagesx($logo)*$scale);$h=(int)(imagesy($logo)*$scale);imagecopyresampled($im,$logo,(512-$w)/2,152-$h/2,0,0,$w,$h,imagesx($logo),imagesy($logo));imagedestroy($logo);
 if($kind==='general'){
  imagefilledrectangle($im,141,246,290,324,$white);imagefilledpolygon($im,[302,265,344,265,373,300,373,324,302,324],$white);imagefilledrectangle($im,314,277,337,295,$bg);imagefilledrectangle($im,133,325,380,337,$accent);
  foreach([182,335] as $x){imagefilledellipse($im,$x,339,46,46,$bg);imagefilledellipse($im,$x,339,27,27,$white);}
 }else{
  imagefilledpolygon($im,[256,223,329,249,320,310,291,345,256,367,221,345,192,310,183,249],$white);imagefilledpolygon($im,[256,237,315,258,307,305,282,335,256,351,230,335,205,305,197,258],$bg);imagefilledpolygon($im,[215,287,226,276,246,296,285,256,297,268,246,320],$accent);
 }
 $text=$kind==='general'?'SCRAP':'SECURITY';$box=imagettfbbox(24,0,$font,$text);imagettftext($im,24,0,(int)((512-($box[2]-$box[0]))/2),412,$white,$font,$text);
 foreach([180,192,512] as $size){$out=imagecreatetruecolor($size,$size);imagecopyresampled($out,$im,0,0,0,0,$size,$size,512,512);imagepng($out,$directory.'/'.$kind.'-'.$size.'.png');imagedestroy($out);}imagedestroy($im);
}
echo "Generated six app icons.\n";
