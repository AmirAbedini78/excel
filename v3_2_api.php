<?php
require __DIR__ . '/app/bootstrap.php';
Auth::require(); Tenant::boot();

function v32_json(array $data,int $status=200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data,JSON_UNESCAPED_UNICODE);
    exit;
}
function v32_day_name(string $d): string
{
    $days=['Saturday'=>'شنبه','Sunday'=>'یکشنبه','Monday'=>'دوشنبه','Tuesday'=>'سه‌شنبه','Wednesday'=>'چهارشنبه','Thursday'=>'پنجشنبه','Friday'=>'جمعه'];
    return $days[date('l',strtotime($d))]??'';
}
function v32_log(string $entity,int $id,string $action,string $summary=''): void
{
    Audit::log($action,$entity,$id,$summary);
    try{
        pdo()->prepare("INSERT INTO activity_logs (workspace_id,user_id,entity_key,record_id,action,summary,ip,created_at) VALUES (?,?,?,?,?,?,?,NOW())")
            ->execute([Tenant::id(),(int)Auth::user()['id'],$entity,$id,$action,$summary,$_SERVER['REMOTE_ADDR']??'']);
    }catch(Throwable $e){}
}
function v32_ids(): array
{
    $raw=$_POST['ids']??'[]';
    $ids=is_array($raw)?$raw:json_decode((string)$raw,true);
    if(!is_array($ids))throw new RuntimeException('شناسه‌ها نامعتبر هستند.');
    $ids=array_values(array_unique(array_filter(array_map('intval',$ids),fn($x)=>$x>0)));
    if(!$ids)throw new RuntimeException('هیچ رکوردی انتخاب نشده است.');
    if(count($ids)>1000)throw new RuntimeException('حداکثر ۱۰۰۰ رکورد در هر عملیات مجاز است.');
    return $ids;
}

$action=$_GET['action']??$_POST['action']??'';
$wid=Tenant::id();
try{
    if($action==='calendar_day'){
        Tenant::requirePermission('dashboard.view');
        $date=trim((string)($_GET['date']??''));
        if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$date))throw new RuntimeException('تاریخ نامعتبر است.');
        $st=pdo()->prepare("SELECT d.id,d.work_description title,d.notes,d.status,c.name company_name FROM daily_plans d LEFT JOIN companies c ON c.id=d.company_id WHERE d.workspace_id=? AND d.plan_date=? ORDER BY d.id");
        $st->execute([$wid,$date]);$daily=[];
        foreach($st->fetchAll() as $r)$daily[]=['id'=>(int)$r['id'],'source'=>'daily','source_label'=>'برنامه روزانه','title'=>$r['title'],'company'=>$r['company_name']??'','status'=>$r['status']??'','notes'=>$r['notes']??'','url'=>'index.php?page=daily&focus_id='.(int)$r['id']];
        $st=pdo()->prepare("SELECT m.id,m.work_type title,m.month_name,m.season,m.status,m.notes,c.name company_name FROM monthly_plans m LEFT JOIN companies c ON c.id=m.company_id WHERE m.workspace_id=? AND m.legal_deadline=? ORDER BY m.id");
        $st->execute([$wid,$date]);$monthly=[];
        foreach($st->fetchAll() as $r)$monthly[]=['id'=>(int)$r['id'],'source'=>'monthly','source_label'=>'برنامه ماهانه','title'=>$r['title'],'company'=>$r['company_name']??'','status'=>$r['status']??'','notes'=>$r['notes']??'','detail'=>trim(($r['month_name']??'').' '.($r['season']??'')),'url'=>'index.php?page=monthly&focus_id='.(int)$r['id']];
        v32_json(['ok'=>true,'events'=>array_merge($daily,$monthly)]);
    }
    if($_SERVER['REQUEST_METHOD']!=='POST')throw new RuntimeException('درخواست نامعتبر است.');
    verify_csrf();
    if($action==='bulk_delete'){
        $entity=trim((string)($_POST['entity']??''));$ids=v32_ids();$ph=implode(',',array_fill(0,count($ids),'?'));$params=[$wid,...$ids];
        $pdo=pdo();$pdo->beginTransaction();
        try{
            if($entity==='companies'){Tenant::requirePermission('companies.delete');$pdo->prepare("UPDATE companies SET active=0,updated_at=NOW() WHERE workspace_id=? AND id IN ($ph)")->execute($params);}
            elseif($entity==='custom_fields'){Tenant::requirePermission('custom_fields.manage');$pdo->prepare("UPDATE custom_fields SET active=0,updated_at=NOW() WHERE workspace_id=? AND id IN ($ph)")->execute($params);}
            elseif($entity==='daily_plans'){Tenant::requirePermission('daily.delete');$pdo->prepare("DELETE FROM daily_plans WHERE workspace_id=? AND id IN ($ph)")->execute($params);}
            elseif($entity==='monthly_plans'){Tenant::requirePermission('monthly.delete');$pdo->prepare("DELETE FROM monthly_plans WHERE workspace_id=? AND id IN ($ph)")->execute($params);}
            elseif($entity==='portal_credentials'){Tenant::requirePermission('systems.update');$pdo->prepare("DELETE FROM portal_credentials WHERE workspace_id=? AND company_id IN ($ph)")->execute($params);}
            else throw new RuntimeException('مولتی‌دیلیت برای این بخش مجاز نیست.');
            $pdo->commit();
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
        foreach($ids as $id)v32_log($entity,$id,'bulk_delete','حذف گروهی');
        v32_json(['ok'=>true,'deleted'=>count($ids)]);
    }
    if($action==='quick_daily'){
        Tenant::requirePermission('daily.create');$date=trim((string)($_POST['date']??''));if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$date))throw new RuntimeException('تاریخ نامعتبر است.');
        $desc=trim((string)($_POST['work_description']??''));if($desc==='')throw new RuntimeException('شرح کار الزامی است.');$cid=(int)($_POST['company_id']??0);$cid=$cid?:null;$notes=trim((string)($_POST['notes']??''));
        $st=pdo()->prepare("INSERT INTO daily_plans (workspace_id,plan_date,day_name,company_id,work_description,status,notes,created_by,created_at,updated_at) VALUES (?,?,?,?,?,'باز',?,?,NOW(),NOW())");
        $st->execute([$wid,$date,v32_day_name($date),$cid,$desc,$notes,(int)Auth::user()['id']]);$id=(int)pdo()->lastInsertId();v32_log('daily_plans',$id,'quick_create','ثبت سریع از تقویم');v32_json(['ok'=>true,'id'=>$id]);
    }
    if($action==='quick_monthly'){
        Tenant::requirePermission('monthly.create');$jalali=trim((string)($_POST['jalali']??''));$date=Jalali::parse($jalali);if(!$date)throw new RuntimeException('تاریخ شمسی نامعتبر است.');$parts=preg_split('/\D+/',$jalali);$jy=(int)($parts[0]??1405);$jm=(int)($parts[1]??1);$months=['فروردین','اردیبهشت','خرداد','تیر','مرداد','شهریور','مهر','آبان','آذر','دی','بهمن','اسفند'];$season=$jm<=3?'بهار':($jm<=6?'تابستان':($jm<=9?'پاییز':'زمستان'));$work=trim((string)($_POST['work_type']??''));if($work==='')throw new RuntimeException('نوع کار الزامی است.');$cid=(int)($_POST['company_id']??0);$cid=$cid?:null;$notes=trim((string)($_POST['notes']??''));
        $st=pdo()->prepare("INSERT INTO monthly_plans (workspace_id,company_id,jalali_year,month_name,season,work_type,legal_deadline,status,notes,created_by,created_at,updated_at) VALUES (?,?,?,?,?,?,?,'باز',?,?,NOW(),NOW())");$st->execute([$wid,$cid,$jy,$months[max(0,min(11,$jm-1))],$season,$work,$date,$notes,(int)Auth::user()['id']]);$id=(int)pdo()->lastInsertId();v32_log('monthly_plans',$id,'quick_create','ثبت سریع از تقویم');v32_json(['ok'=>true,'id'=>$id]);
    }
    throw new RuntimeException('عملیات ناشناخته است.');
}catch(Throwable $e){v32_json(['ok'=>false,'error'=>$e->getMessage()],400);}
