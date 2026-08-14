<?php
require __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/Core/DataIO.php';
Auth::require();

$action=$_GET['action']??$_POST['action']??'';
$entity=trim((string)($_GET['entity']??$_POST['entity']??''));
if(!AccountingDataIO::allowed($entity)){
    http_response_code(400);exit('Invalid entity');
}

try{
    if($action==='export' || $action==='template'){
        $format=mb_strtolower((string)($_GET['format']??'xlsx'));
        $ids=array_values(array_filter(array_map('intval',explode(',',(string)($_GET['ids']??'')))));
        AccountingDataIO::stream($entity,$format,$ids,$action==='template');
    }

    if($action==='import'){
        verify_csrf();
        if(empty($_FILES['file']) || !is_uploaded_file($_FILES['file']['tmp_name']))throw new RuntimeException('فایل ورودی دریافت نشد.');
        if(($_FILES['file']['size']??0)>15*1024*1024)throw new RuntimeException('حجم فایل بیشتر از ۱۵ مگابایت است.');
        $stats=AccountingDataIO::import($entity,$_FILES['file']['tmp_name'],$_FILES['file']['name']);
        try{
            pdo()->prepare("INSERT INTO imports (filename,stats,user_id,created_at) VALUES (?,?,?,NOW())")
                ->execute([$_FILES['file']['name'],json_encode(['entity'=>$entity]+$stats,JSON_UNESCAPED_UNICODE),(int)Auth::user()['id']]);
        }catch(Throwable $e){}
        $msg='ورود فایل انجام شد: '.$stats['inserted'].' جدید، '.$stats['updated'].' به‌روزرسانی، '.$stats['skipped'].' رد شده.';
        if($stats['errors'])$msg.=' خطاها: '.implode(' | ',array_slice($stats['errors'],0,5));
        flash($msg,$stats['errors']?'warning':'success');
        redirect('index.php?page='.AccountingDataIO::pageFor($entity));
    }

    throw new RuntimeException('عملیات ورودی/خروجی نامعتبر است.');
}catch(Throwable $e){
    if($action==='import'){
        flash('خطا در ورود فایل: '.$e->getMessage(),'danger');
        redirect('index.php?page='.AccountingDataIO::pageFor($entity));
    }
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo $e->getMessage();
}
