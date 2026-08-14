<?php
require __DIR__.'/app/bootstrap.php';
require_once __DIR__.'/app/Core/Tenant.php';
require_once __DIR__.'/app/Core/Audit.php';
require_once __DIR__.'/app/Core/FileLibrary.php';
Auth::require();Tenant::ensureSchema();Tenant::boot();Tenant::requirePermission('files.view');
$f=FileLibrary::get((int)($_GET['id']??0));if(!$f){http_response_code(404);exit('File not found');}
$path=APP_ROOT.'/'.ltrim($f['storage_path'],'/\\');if(!is_file($path)){http_response_code(404);exit('File missing');}
Audit::log('file.download','library_files',(int)$f['id'],'دانلود فایل');
header('Content-Type: '.($f['mime_type']?:'application/octet-stream'));
header('Content-Length: '.filesize($path));
header("Content-Disposition: attachment; filename*=UTF-8''".rawurlencode($f['original_name']));
header('X-Content-Type-Options: nosniff');
readfile($path);exit;
