<?php
require __DIR__.'/app/bootstrap.php';
require_once __DIR__.'/app/Core/Tenant.php';
require_once __DIR__.'/app/Core/Audit.php';
require_once __DIR__.'/app/Core/FileLibrary.php';
Auth::require();Tenant::ensureSchema();Tenant::boot();Audit::ensureSchema();FileLibrary::ensureSchema();

function fj(array $d,int $s=200): never{http_response_code($s);header('Content-Type: application/json; charset=utf-8');echo json_encode($d,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;}
$action=$_GET['action']??$_POST['action']??'list';
try{
    if($action==='list'){fj(['ok'=>true,'files'=>FileLibrary::list(trim((string)($_GET['q']??'')),300)]);}
    if($_SERVER['REQUEST_METHOD']!=='POST')throw new RuntimeException('درخواست نامعتبر است.');
    verify_csrf();
    if($action==='upload'||$action==='upload_redirect'){
        $f=FileLibrary::upload($_FILES['file']??[]);
        if($action==='upload_redirect'){flash('فایل به لایبرری اضافه شد.');redirect('index.php?page=library');}
        fj(['ok'=>true,'file'=>$f]);
    }
    if($action==='delete'){FileLibrary::delete((int)($_POST['id']??0));fj(['ok'=>true]);}
    if($action==='attachments')fj(['ok'=>true,'files'=>FileLibrary::attachments((string)($_POST['entity']??''),(int)($_POST['record_id']??0))]);
    if($action==='attach'){FileLibrary::attach((string)($_POST['entity']??''),(int)($_POST['record_id']??0),json_decode((string)($_POST['file_ids']??'[]'),true)?:[]);fj(['ok'=>true]);}
    if($action==='detach'){FileLibrary::detach((string)($_POST['entity']??''),(int)($_POST['record_id']??0),(int)($_POST['file_id']??0));fj(['ok'=>true]);}
    throw new RuntimeException('عملیات ناشناخته است.');
}catch(Throwable $e){fj(['ok'=>false,'error'=>$e->getMessage()],400);}
