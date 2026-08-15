<?php
require __DIR__.'/app/bootstrap.php';
require_once __DIR__.'/app/Modules/V4Module.php';
require_once __DIR__.'/app/Modules/V5Module.php';

function v5_out(array $data,int $status=200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    exit;
}
function v5_date($value): ?string
{
    $v=trim((string)$value);if($v==='')return null;
    if(preg_match('/^\d{4}-\d{2}-\d{2}$/',$v))return$v;
    return Jalali::parse($v)?:null;
}
function v5_note(int $id): ?array
{
    $st=pdo()->prepare("SELECT n.*,u.name user_name FROM notes n LEFT JOIN users u ON u.id=n.user_id WHERE n.id=? AND n.workspace_id=? LIMIT 1");
    $st->execute([$id,Tenant::id()]);$r=$st->fetch();return$r?:null;
}
function v5_phone(int $id): ?array
{
    $st=pdo()->prepare("SELECT p.*,c.name client_company_name,u.name created_by_name
        FROM phonebook_entries p JOIN companies c ON c.id=p.client_company_id AND c.workspace_id=p.workspace_id
        LEFT JOIN users u ON u.id=p.created_by WHERE p.id=? AND p.workspace_id=? LIMIT 1");
    $st->execute([$id,Tenant::id()]);$r=$st->fetch();return$r?:null;
}

Auth::require();
Tenant::boot();
if($_SERVER['REQUEST_METHOD']!=='POST')v5_out(['ok'=>false,'error'=>'Method not allowed'],405);
verify_csrf();

$action=(string)($_POST['action']??'');
try{
    if($action==='v5_note_save'){
        $id=(int)($_POST['id']??0);
        $id?Tenant::requirePermission('notes.update'):Tenant::requirePermission('notes.create');
        $title=trim((string)($_POST['title']??''));
        $body=trim((string)($_POST['body']??''));
        if($title===''&&$body==='')throw new RuntimeException('عنوان یا متن نوت را وارد کنید.');
        $priority=in_array($_POST['priority']??'normal',['normal','important','urgent'],true)?$_POST['priority']:'normal';
        $due=v5_date($_POST['due_date']??'');
        $pinned=isset($_POST['pinned'])?1:0;

        if($id){
            $old=v5_note($id);if(!$old)throw new RuntimeException('نوت پیدا نشد.');
            pdo()->prepare("UPDATE notes SET title=?,body=?,pinned=?,priority=?,due_date=?,updated_at=NOW() WHERE id=? AND workspace_id=?")
                ->execute([$title,$body,$pinned,$priority,$due,$id,Tenant::id()]);
            Audit::log('note.update','notes',$id,'ویرایش نوت',$old,['title'=>$title,'priority'=>$priority,'due_date'=>$due]);
        }else{
            pdo()->prepare("INSERT INTO notes (workspace_id,user_id,title,body,pinned,priority,due_date,is_completed,created_at,updated_at)
                VALUES (?,?,?,?,?,?,?,0,NOW(),NOW())")
                ->execute([Tenant::id(),(int)Auth::user()['id'],$title,$body,$pinned,$priority,$due]);
            $id=(int)pdo()->lastInsertId();
            Audit::log('note.create','notes',$id,'ایجاد نوت / کار');
        }
        FileLibrary::syncFromPost('notes',$id);
        RuntimeCache::clearWorkspace(Tenant::id());
        $r=v5_note($id);
        v5_out(['ok'=>true,'id'=>$id,'card_html'=>V5Module::noteCardHtml($r),'message'=>'نوت ذخیره شد.']);
    }

    if($action==='v5_note_toggle'){
        Tenant::requirePermission('notes.update');
        $id=(int)($_POST['id']??0);$done=(int)($_POST['completed']??0)===1;
        $old=v5_note($id);if(!$old)throw new RuntimeException('نوت پیدا نشد.');
        pdo()->prepare("UPDATE notes SET is_completed=?,completed_at=".($done?'NOW()':'NULL').",updated_at=NOW() WHERE id=? AND workspace_id=?")
            ->execute([$done?1:0,$id,Tenant::id()]);
        Audit::log('note.complete','notes',$id,$done?'انجام نوت':'بازگشایی نوت',$old,['is_completed'=>$done?1:0]);
        RuntimeCache::clearWorkspace(Tenant::id());
        $r=v5_note($id);
        v5_out(['ok'=>true,'id'=>$id,'completed'=>$done,'card_html'=>V5Module::noteCardHtml($r)]);
    }

    if($action==='v5_note_delete'){
        Tenant::requirePermission('notes.delete');
        $id=(int)($_POST['id']??0);$old=v5_note($id);if(!$old)throw new RuntimeException('نوت پیدا نشد.');
        pdo()->prepare("DELETE FROM notes WHERE id=? AND workspace_id=?")->execute([$id,Tenant::id()]);
        pdo()->prepare("DELETE FROM file_attachments WHERE workspace_id=? AND entity_key='notes' AND record_id=?")->execute([Tenant::id(),$id]);
        Audit::log('note.delete','notes',$id,'حذف نوت',$old,null);
        RuntimeCache::clearWorkspace(Tenant::id());
        v5_out(['ok'=>true,'id'=>$id,'message'=>'نوت حذف شد.']);
    }

    if($action==='v5_phonebook_save'){
        $id=(int)($_POST['id']??0);
        $id?Tenant::requirePermission('phonebook.update'):Tenant::requirePermission('phonebook.create');
        $company=(int)($_POST['client_company_id']??0);
        if(!$company)throw new RuntimeException('شرکت مرتبط را انتخاب کنید.');
        $st=pdo()->prepare("SELECT id FROM companies WHERE id=? AND workspace_id=? AND active=1 LIMIT 1");
        $st->execute([$company,Tenant::id()]);if(!$st->fetchColumn())throw new RuntimeException('شرکت انتخاب‌شده متعلق به این Workspace نیست.');

        $phone=trim((string)($_POST['phone_number']??''));if($phone==='')throw new RuntimeException('شماره تماس الزامی است.');
        $type=in_array($_POST['contact_type']??'mobile',['mobile','phone','other'],true)?$_POST['contact_type']:'mobile';
        $data=[
            $company,
            trim((string)($_POST['person_name']??'')),
            trim((string)($_POST['person_title']??'')),
            trim((string)($_POST['organization_name']??'')),
            $type,$phone,trim((string)($_POST['extension_no']??'')),
            v5_date($_POST['contacted_date']??'')?:date('Y-m-d'),
            v5_date($_POST['followup_date']??''),
            isset($_POST['followup_done'])?1:0,
            trim((string)($_POST['notes']??'')),
        ];
        if($id){
            $old=v5_phone($id);if(!$old)throw new RuntimeException('رکورد تماس پیدا نشد.');
            pdo()->prepare("UPDATE phonebook_entries SET client_company_id=?,person_name=?,person_title=?,organization_name=?,contact_type=?,phone_number=?,extension_no=?,contacted_date=?,followup_date=?,followup_done=?,notes=?,updated_at=NOW()
                WHERE id=? AND workspace_id=?")->execute([...$data,$id,Tenant::id()]);
            Audit::log('phonebook.update','phonebook_entries',$id,'ویرایش تماس',$old,['phone'=>$phone]);
        }else{
            pdo()->prepare("INSERT INTO phonebook_entries
                (workspace_id,client_company_id,person_name,person_title,organization_name,contact_type,phone_number,extension_no,contacted_date,followup_date,followup_done,notes,created_by,created_at,updated_at)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW())")
                ->execute([Tenant::id(),...$data,(int)Auth::user()['id']]);
            $id=(int)pdo()->lastInsertId();
            Audit::log('phonebook.create','phonebook_entries',$id,'ثبت تماس / پیگیری');
        }
        FileLibrary::syncFromPost('phonebook_entries',$id);
        RuntimeCache::clearWorkspace(Tenant::id());
        $r=v5_phone($id);
        v5_out(['ok'=>true,'id'=>$id,'row_html'=>V5Module::phonebookRowHtml($r),'message'=>'تماس ذخیره شد.']);
    }

    if($action==='v5_phonebook_toggle'){
        Tenant::requirePermission('phonebook.update');
        $id=(int)($_POST['id']??0);$done=(int)($_POST['done']??0)===1;
        $old=v5_phone($id);if(!$old)throw new RuntimeException('رکورد تماس پیدا نشد.');
        pdo()->prepare("UPDATE phonebook_entries SET followup_done=?,updated_at=NOW() WHERE id=? AND workspace_id=?")
            ->execute([$done?1:0,$id,Tenant::id()]);
        Audit::log('phonebook.followup','phonebook_entries',$id,$done?'پیگیری انجام شد':'پیگیری بازگشایی شد',$old,['followup_done'=>$done?1:0]);
        RuntimeCache::clearWorkspace(Tenant::id());
        $r=v5_phone($id);
        v5_out(['ok'=>true,'id'=>$id,'done'=>$done,'row_html'=>V5Module::phonebookRowHtml($r),'message'=>$done?'پیگیری انجام شد ✓':'پیگیری دوباره باز شد.']);
    }

    if($action==='v5_phonebook_delete'){
        Tenant::requirePermission('phonebook.delete');
        $id=(int)($_POST['id']??0);$old=v5_phone($id);if(!$old)throw new RuntimeException('رکورد تماس پیدا نشد.');
        pdo()->prepare("DELETE FROM phonebook_entries WHERE id=? AND workspace_id=?")->execute([$id,Tenant::id()]);
        pdo()->prepare("DELETE FROM file_attachments WHERE workspace_id=? AND entity_key='phonebook_entries' AND record_id=?")->execute([Tenant::id(),$id]);
        Audit::log('phonebook.delete','phonebook_entries',$id,'حذف تماس',$old,null);
        RuntimeCache::clearWorkspace(Tenant::id());
        v5_out(['ok'=>true,'id'=>$id,'message'=>'رکورد تماس حذف شد.']);
    }

    if($action==='v5_share_create'){
        Tenant::requirePermission('shares.manage');
        $ids=$_POST['company_ids']??[];if(!is_array($ids))$ids=[$ids];
        $created=Sharing::create(
            (string)($_POST['target_code']??''),
            $ids,
            isset($_POST['share_daily']),
            isset($_POST['share_monthly']),
            isset($_POST['share_phonebook']),
            (int)($_POST['target_workspace_id']??0)
        );
        v5_out(['ok'=>true,'created'=>$created,'message'=>count($created).' شرکت به اشتراک گذاشته شد.','reload'=>true]);
    }

    if($action==='v5_share_revoke'){
        Sharing::revoke((int)($_POST['id']??0));
        v5_out(['ok'=>true,'message'=>'اشتراک لغو شد.','reload'=>true]);
    }

    if($action==='v5_share_reject'){
        Sharing::revokeIncoming((int)($_POST['id']??0));
        v5_out(['ok'=>true,'message'=>'دریافت این اشتراک متوقف شد.','reload'=>true]);
    }

    if($action==='v5_cache'){
        Tenant::requirePermission('cache.manage');
        $mode=(string)($_POST['mode']??'workspace');
        if($mode==='all'){
            if(!Tenant::isPlatformAdmin())throw new RuntimeException('پاک‌سازی کل کش فقط برای مدیر کل پلتفرم مجاز است.');
            RuntimeCache::clearAll();
            $message='کل کش Runtime پاک شد.';
        }elseif($mode==='warm'){
            RuntimeCache::clearWorkspace(Tenant::id());
            $warm=RuntimeCache::warmWorkspace(Tenant::id());
            $message='کش Workspace گرم شد؛ '.(int)($warm['companies']??0).' شرکت Cache شد.';
        }else{
            RuntimeCache::clearWorkspace(Tenant::id());
            $message='کش این Workspace پاک شد.';
        }
        v5_out(['ok'=>true,'message'=>$message,'stats'=>RuntimeCache::stats(Tenant::id())]);
    }

    v5_out(['ok'=>false,'error'=>'Action not found'],404);
}catch(Throwable $e){
    v5_out(['ok'=>false,'error'=>$e->getMessage()],400);
}
