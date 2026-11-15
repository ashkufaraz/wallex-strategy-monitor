<?php
declare(strict_types=1);
$name=$_GET['name']??'';
$paths=['strategy'=>dirname(__DIR__).'/strategy-json-guide-fa.md','indicators'=>dirname(__DIR__).'/docs/live-indicators.md'];
if(!is_string($name)||!isset($paths[$name])){http_response_code(404);exit;}
$path=$paths[$name];if(!is_file($path))$path=__DIR__.'/guides/'.$name.'.md';
if(!is_file($path)){http_response_code(404);exit;}
header('X-Content-Type-Options: nosniff');
if(isset($_GET['download'])){header('Content-Type: text/markdown; charset=utf-8');header('Content-Disposition: attachment; filename="'.$name.'-guide.md"');readfile($path);exit;}
header('Content-Type: text/html; charset=utf-8');
$title=$name==='strategy'?'راهنمای نوشتن استراتژی':'راهنمای اندیکاتورها';
echo '<!doctype html><html lang="fa" dir="rtl"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>'.$title.'</title><style>body{max-width:1000px;margin:auto;padding:24px;background:#0c1220;color:#e9edf7;font:16px/2 Tahoma,Arial}pre{white-space:pre-wrap;overflow-wrap:anywhere;font:inherit}a{color:#a7baff}</style><h1>'.$title.'</h1><a href="?name='.$name.'&download=1">دانلود فایل راهنما</a><pre>'.htmlspecialchars(file_get_contents($path),ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8').'</pre></html>';
