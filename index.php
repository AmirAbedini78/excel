<?php
require __DIR__ . '/app/bootstrap.php';
if (file_exists(__DIR__.'/app/config.php')) { try { Schema::migrate(pdo()); } catch (Throwable $e) { /* migration page can show exact error */ } }

function q(string $sql, array $params=[]): array { $st=pdo()->prepare($sql); $st->execute($params); return $st->fetchAll(); }
function one(string $sql, array $params=[]): ?array { $st=pdo()->prepare($sql); $st->execute($params); $r=$st->fetch(); return $r ?: null; }
function scalarv(string $sql, array $params=[]): int { $st=pdo()->prepare($sql); $st->execute($params); return (int)$st->fetchColumn(); }
function json_out(array $data): never { header('Content-Type: application/json; charset=utf-8'); echo json_encode($data, JSON_UNESCAPED_UNICODE); exit; }
function input_date_to_sql(string $j): ?string { $j=trim($j); if (!$j) return null; return Jalali::parse($j) ?: (preg_match('/^\d{4}-\d{2}-\d{2}$/', Jalali::enDigits($j)) ? Jalali::enDigits($j) : null); }
function fa_date(?string $d): string { return $d ? Jalali::fromGregorian($d) : ''; }
function day_name(?string $d): string { if(!$d) return ''; $days=['Saturday'=>'شنبه','Sunday'=>'یکشنبه','Monday'=>'دوشنبه','Tuesday'=>'سه‌شنبه','Wednesday'=>'چهارشنبه','Thursday'=>'پنجشنبه','Friday'=>'جمعه']; return $days[date('l', strtotime($d))] ?? ''; }
function companies(bool $all=false): array { return q("SELECT * FROM companies ".($all?'':'WHERE active=1')." ORDER BY id"); }
function company_options($selected=null, bool $all=false): string { $html=$all?'<option value="">همه شرکت‌ها</option>':''; foreach (companies() as $c) $html.='<option value="'.(int)$c['id'].'" '.((string)$selected===(string)$c['id']?'selected':'').'>'.h($c['name']).'</option>'; return $html; }
function status_options($selected='', bool $all=false): string { $items=['باز','در حال انجام','منتظر مدارک','انجام شده','معوق','لغو شده']; $html=$all?'<option value="">همه وضعیت‌ها</option>':''; foreach($items as $x) $html.='<option '.($selected===$x?'selected':'').'>'.h($x).'</option>'; return $html; }
function month_options($selected='', bool $all=false): string { $items=['فروردین','اردیبهشت','خرداد','تیر','مرداد','شهریور','مهر','آبان','آذر','دی','بهمن','اسفند','سالانه']; $html=$all?'<option value="">همه ماه‌ها</option>':''; foreach($items as $x) $html.='<option '.($selected===$x?'selected':'').'>'.h($x).'</option>'; return $html; }
function season_options($selected='', bool $all=false): string { $items=['بهار','تابستان','پاییز','زمستان','سالانه']; $html=$all?'<option value="">همه فصل‌ها</option>':''; foreach($items as $x) $html.='<option '.($selected===$x?'selected':'').'>'.h($x).'</option>'; return $html; }
function work_types(): array { return ['بانک‌ها','حقوق و دستمزد','بیمه تامین اجتماعی','مالیات حقوق','مودیان','دفاتر الکترونیکی','اجاره و حق امتیاز','اظهارنامه عملکرد','حق الزحمه']; }
function work_type_options($selected='', bool $all=false): string { $html=$all?'<option value="">همه نوع کارها</option>':''; foreach(work_types() as $x) $html.='<option '.($selected===$x?'selected':'').'>'.h($x).'</option>'; return $html; }
function module_defs(): array { return [
    'banks'=>['title'=>'بانک‌ها','icon'=>'🏦','work_type'=>'بانک‌ها'],
    'payroll'=>['title'=>'حقوق و دستمزد','icon'=>'👥','work_type'=>'حقوق و دستمزد'],
    'social_insurance'=>['title'=>'بیمه تامین اجتماعی','icon'=>'🛡️','work_type'=>'بیمه تامین اجتماعی'],
    'salary_tax'=>['title'=>'مالیات حقوق','icon'=>'🧾','work_type'=>'مالیات حقوق'],
    'tax_payers'=>['title'=>'مودیان','icon'=>'📨','work_type'=>'مودیان'],
    'electronic_books'=>['title'=>'دفاتر الکترونیکی','icon'=>'📚','work_type'=>'دفاتر الکترونیکی'],
    'rent_license'=>['title'=>'اجاره و حق امتیاز','icon'=>'🏷️','work_type'=>'اجاره و حق امتیاز'],
    'performance_statement'=>['title'=>'اظهارنامه عملکرد','icon'=>'📊','work_type'=>'اظهارنامه عملکرد'],
    'fees'=>['title'=>'حق الزحمه','icon'=>'💳','work_type'=>'حق الزحمه'],
]; }
function module_title(string $key): string { $defs=module_defs(); return $defs[$key]['title'] ?? $key; }
function extra_decode($json): array { if(!$json) return []; $a=json_decode((string)$json,true); return is_array($a)?$a:[]; }
function extra_encode(array $data): string { return json_encode($data, JSON_UNESCAPED_UNICODE); }
function custom_fields(string $entity): array { return q("SELECT * FROM custom_fields WHERE entity_key=? AND active=1 ORDER BY sort_order,id", [$entity]); }
function render_extra_inputs(string $entity, array $extra=[]): string { $html=''; foreach(custom_fields($entity) as $f){ $key=$f['field_key']; $label=$f['label']; $v=$extra[$key]??''; $html.='<label>'.h($label).'<input name="extra['.h($key).']" value="'.h($v).'" placeholder="'.h($label).'"></label>'; } return $html; }
function quick_filters(array $fields): string { $qs=$_GET; $html='<form class="filters compact" method="get"><input type="hidden" name="page" value="'.h($_GET['page'] ?? '').'">'; if(isset($_GET['module'])) $html.='<input type="hidden" name="module" value="'.h($_GET['module']).'">'; foreach($fields as $name=>$cfg){ $val=$_GET[$name] ?? ''; $label=$cfg['label']; $type=$cfg['type'] ?? 'text'; $html.='<label>'.h($label); if($type==='company') $html.='<select name="'.$name.'">'.company_options($val,true).'</select>'; elseif($type==='status') $html.='<select name="'.$name.'">'.status_options($val,true).'</select>'; elseif($type==='month') $html.='<select name="'.$name.'">'.month_options($val,true).'</select>'; elseif($type==='season') $html.='<select name="'.$name.'">'.season_options($val,true).'</select>'; elseif($type==='work_type') $html.='<select name="'.$name.'">'.work_type_options($val,true).'</select>'; else $html.='<input name="'.$name.'" value="'.h($val).'" placeholder="'.h($label).'">'; $html.='</label>'; } $html.='<button class="btn primary tiny" type="submit">فیلتر</button><a class="btn tiny" href="index.php?page='.h($_GET['page']??'dashboard').(isset($_GET['module'])?'&module='.h($_GET['module']):'').'">پاک کردن</a></form>'; return $html; }
function log_activity(string $entity, int $id, string $action, string $summary='', array $payload=[]): void { try{ $uid=Auth::check() ? (int)Auth::user()['id'] : null; $st=pdo()->prepare("INSERT INTO activity_logs (user_id,entity_key,record_id,action,summary,payload,ip,created_at) VALUES (?,?,?,?,?,?,?,NOW())"); $st->execute([$uid,$entity,$id,$action,$summary,extra_encode($payload),$_SERVER['REMOTE_ADDR']??'']); }catch(Throwable $e){} }

$page = $_GET['page'] ?? 'dashboard';

if ($page === 'google_start') {
    $clientId = setting('google_client_id',''); $redirect = setting('google_redirect_uri', base_url('index.php?page=google_callback'));
    if (!$clientId) { flash('ابتدا Google Client ID را در تنظیمات وارد کنید.','danger'); redirect('index.php?page=login'); }
    $_SESSION['google_oauth_state'] = bin2hex(random_bytes(16));
    $url = 'https://accounts.google.com/o/oauth2/v2/auth?'.http_build_query(['client_id'=>$clientId,'redirect_uri'=>$redirect,'response_type'=>'code','scope'=>'openid email profile','state'=>$_SESSION['google_oauth_state'],'access_type'=>'online','prompt'=>'select_account']);
    redirect($url);
}
if ($page === 'google_callback') {
    try {
        if (($_GET['state'] ?? '') !== ($_SESSION['google_oauth_state'] ?? '')) throw new RuntimeException('درخواست گوگل معتبر نیست.');
        $code = $_GET['code'] ?? ''; if (!$code) throw new RuntimeException('کد ورود گوگل دریافت نشد.');
        $redirect = setting('google_redirect_uri', base_url('index.php?page=google_callback'));
        $post = http_build_query(['code'=>$code,'client_id'=>setting('google_client_id',''),'client_secret'=>setting('google_client_secret',''),'redirect_uri'=>$redirect,'grant_type'=>'authorization_code']);
        $ch=curl_init('https://oauth2.googleapis.com/token'); curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$post,CURLOPT_HTTPHEADER=>['Content-Type: application/x-www-form-urlencoded'],CURLOPT_TIMEOUT=>25]);
        $token=json_decode(curl_exec($ch),true); $err=curl_error($ch); curl_close($ch); if ($err || empty($token['access_token'])) throw new RuntimeException('خطا در دریافت توکن گوگل.');
        $ch=curl_init('https://openidconnect.googleapis.com/v1/userinfo'); curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$token['access_token']],CURLOPT_TIMEOUT=>25]);
        $info=json_decode(curl_exec($ch),true); curl_close($ch); $email=mb_strtolower($info['email'] ?? ''); if (!$email) throw new RuntimeException('ایمیل گوگل دریافت نشد.');
        $user = one("SELECT * FROM users WHERE email=? OR google_id=? LIMIT 1", [$email, $info['sub'] ?? '']);
        if (!$user) { if (setting('allow_google_signup','1') !== '1') throw new RuntimeException('ثبت‌نام با گوگل فعال نیست.'); $st=pdo()->prepare("INSERT INTO users (name,email,google_id,avatar,role,status,created_at,updated_at) VALUES (?,?,?,?, 'accountant','active',NOW(),NOW())"); $st->execute([$info['name'] ?? $email,$email,$info['sub'] ?? null,$info['picture'] ?? null]); $id=(int)pdo()->lastInsertId(); }
        else { $id=(int)$user['id']; pdo()->prepare("UPDATE users SET google_id=COALESCE(google_id,?), avatar=COALESCE(?,avatar), updated_at=NOW() WHERE id=?")->execute([$info['sub'] ?? null,$info['picture'] ?? null,$id]); }
        Auth::login($id); flash('ورود با گوگل انجام شد.'); redirect('index.php');
    } catch (Throwable $e) { flash($e->getMessage(),'danger'); redirect('index.php?page=login'); }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'login') { verify_csrf(); if (Auth::attempt($_POST['email'] ?? '', $_POST['password'] ?? '')) redirect('index.php'); flash('ایمیل یا رمز عبور اشتباه است.','danger'); redirect('index.php?page=login'); }
    if ($action === 'logout') { verify_csrf(); Auth::logout(); redirect('index.php?page=login'); }
    Auth::require(); verify_csrf();
    try {
        if ($action === 'inline_update') handle_inline_update();
        if ($action === 'delete_record') handle_delete_record();
        if ($action === 'save_company') handle_save_company();
        if ($action === 'save_daily_plan') handle_save_daily_plan();
        if ($action === 'save_monthly_plan') handle_save_monthly_plan();
        if ($action === 'save_module_record') handle_save_module_record();
        if ($action === 'save_custom_field') handle_save_custom_field();
        if ($action === 'delete_custom_field') { $id=(int)$_POST['id']; pdo()->prepare("UPDATE custom_fields SET active=0, updated_at=NOW() WHERE id=?")->execute([$id]); flash('فیلد اضافی حذف شد.'); redirect('index.php?page=custom_fields'); }
        if ($action === 'save_settings') handle_save_settings();
        if ($action === 'run_migration') { Schema::migrate(pdo()); flash('مایگریشن دیتابیس با موفقیت اجرا شد.'); redirect('index.php?page=settings'); }
    } catch (Throwable $e) {
        if (str_starts_with($action,'inline') || $action==='delete_record') json_out(['ok'=>false,'error'=>$e->getMessage()]);
        flash($e->getMessage(),'danger'); redirect($_SERVER['HTTP_REFERER'] ?? 'index.php');
    }
}

function handle_inline_update(): never
{
    $entity=$_POST['entity']??''; $id=(int)($_POST['id']??0); $field=$_POST['field']??''; $value=trim((string)($_POST['value']??'')); if(!$id) throw new RuntimeException('شناسه نامعتبر است.');
    $map = [
        'companies'=>['table'=>'companies','fields'=>['name','company_type','legal_personality','software','address','registration_number','postal_code','ceo_name','ceo_national_id','ceo_mobile','phone','national_id','economic_code','notes']],
        'daily_plans'=>['table'=>'daily_plans','fields'=>['plan_date','day_name','company_id','work_description','location','status','notes']],
        'monthly_plans'=>['table'=>'monthly_plans','fields'=>['company_id','month_name','season','work_type','legal_deadline','status','work_day','completed_date','notes']],
        'module_records'=>['table'=>'module_records','fields'=>['company_id','title','category','period_label','due_date','status','completed_date','amount','responsible','notes']],
    ];
    if(!isset($map[$entity])) throw new RuntimeException('بخش قابل ویرایش نیست.');
    $table=$map[$entity]['table'];
    if (str_starts_with($field,'extra.')) {
        $key=substr($field,6); $row=one("SELECT extra_json FROM `$table` WHERE id=?",[$id]); $extra=extra_decode($row['extra_json']??''); $extra[$key]=$value; pdo()->prepare("UPDATE `$table` SET extra_json=?, updated_at=NOW() WHERE id=?")->execute([extra_encode($extra),$id]);
    } else {
        if(!in_array($field,$map[$entity]['fields'],true)) throw new RuntimeException('فیلد مجاز نیست.');
        if(in_array($field,['plan_date','legal_deadline','completed_date'],true)) $value=input_date_to_sql($value);
        if($field==='company_id') $value=$value ? (int)$value : null;
        pdo()->prepare("UPDATE `$table` SET `$field`=?, updated_at=NOW() WHERE id=?")->execute([$value,$id]);
    }
    log_activity($entity,$id,'inline_update',$field,['value'=>$value]); json_out(['ok'=>true,'value'=>$value]);
}
function handle_delete_record(): never
{
    $entity=$_POST['entity']??''; $id=(int)($_POST['id']??0); if(!$id) throw new RuntimeException('شناسه نامعتبر است.');
    $map=['companies'=>'companies','daily_plans'=>'daily_plans','monthly_plans'=>'monthly_plans','module_records'=>'module_records']; if(!isset($map[$entity])) throw new RuntimeException('حذف این بخش مجاز نیست.');
    if($entity==='companies') pdo()->prepare("UPDATE companies SET active=0, updated_at=NOW() WHERE id=?")->execute([$id]); else pdo()->prepare("DELETE FROM `{$map[$entity]}` WHERE id=?")->execute([$id]);
    log_activity($entity,$id,'delete','حذف رکورد'); json_out(['ok'=>true]);
}
function handle_save_company(): void
{
    $id=(int)($_POST['id']??0); $name=trim($_POST['name']??''); if(!$name) throw new RuntimeException('نام شرکت الزامی است.');
    $data=[$name,trim($_POST['company_type']??''),trim($_POST['legal_personality']??''),trim($_POST['software']??''),trim($_POST['address']??''),trim($_POST['registration_number']??''),trim($_POST['postal_code']??''),trim($_POST['ceo_name']??''),trim($_POST['ceo_national_id']??''),trim($_POST['ceo_mobile']??''),trim($_POST['phone']??''),trim($_POST['national_id']??''),trim($_POST['economic_code']??''),trim($_POST['notes']??''),extra_encode($_POST['extra']??[])];
    if($id) pdo()->prepare("UPDATE companies SET name=?, company_type=?, legal_personality=?, software=?, address=?, registration_number=?, postal_code=?, ceo_name=?, ceo_national_id=?, ceo_mobile=?, phone=?, national_id=?, economic_code=?, notes=?, extra_json=?, updated_at=NOW() WHERE id=?")->execute([...$data,$id]);
    else { pdo()->prepare("INSERT INTO companies (name,company_type,legal_personality,software,address,registration_number,postal_code,ceo_name,ceo_national_id,ceo_mobile,phone,national_id,economic_code,notes,extra_json,active,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,1,NOW(),NOW())")->execute($data); $id=(int)pdo()->lastInsertId(); }
    log_activity('companies',$id,'save','ذخیره شرکت'); flash('شرکت ذخیره شد.'); redirect('index.php?page=companies');
}
function handle_save_daily_plan(): void
{
    $id=(int)($_POST['id']??0); $date=input_date_to_sql($_POST['plan_date']??''); $day=trim($_POST['day_name']??'') ?: day_name($date); $desc=trim($_POST['work_description']??''); if(!$desc) throw new RuntimeException('شرح کار الزامی است.');
    $data=[$date,$day,(int)($_POST['company_id']?:0)?:null,$desc,trim($_POST['location']??''),trim($_POST['status']??'باز'),trim($_POST['notes']??''),extra_encode($_POST['extra']??[])];
    if($id) pdo()->prepare("UPDATE daily_plans SET plan_date=?, day_name=?, company_id=?, work_description=?, location=?, status=?, notes=?, extra_json=?, updated_at=NOW() WHERE id=?")->execute([...$data,$id]);
    else { pdo()->prepare("INSERT INTO daily_plans (plan_date,day_name,company_id,work_description,location,status,notes,extra_json,created_by,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,NOW(),NOW())")->execute([...$data,Auth::user()['id']]); $id=(int)pdo()->lastInsertId(); }
    log_activity('daily_plans',$id,'save','ذخیره برنامه روزانه'); flash('برنامه روزانه ذخیره شد.'); redirect('index.php?page=daily');
}
function handle_save_monthly_plan(): void
{
    $id=(int)($_POST['id']??0); $work=trim($_POST['work_type']??''); if(!$work) throw new RuntimeException('نوع کار الزامی است.');
    $data=[(int)($_POST['company_id']?:0)?:null,(int)($_POST['jalali_year']??1405),trim($_POST['month_name']??''),trim($_POST['season']??''),$work,input_date_to_sql($_POST['legal_deadline']??''),trim($_POST['status']??'باز'),trim($_POST['work_day']??''),input_date_to_sql($_POST['completed_date']??''),trim($_POST['notes']??''),extra_encode($_POST['extra']??[])];
    if($id) pdo()->prepare("UPDATE monthly_plans SET company_id=?, jalali_year=?, month_name=?, season=?, work_type=?, legal_deadline=?, status=?, work_day=?, completed_date=?, notes=?, extra_json=?, updated_at=NOW() WHERE id=?")->execute([...$data,$id]);
    else { pdo()->prepare("INSERT INTO monthly_plans (company_id,jalali_year,month_name,season,work_type,legal_deadline,status,work_day,completed_date,notes,extra_json,created_by,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW())")->execute([...$data,Auth::user()['id']]); $id=(int)pdo()->lastInsertId(); }
    log_activity('monthly_plans',$id,'save','ذخیره برنامه ماهانه'); flash('برنامه ماهانه ذخیره شد.'); redirect('index.php?page=monthly');
}
function handle_save_module_record(): void
{
    $id=(int)($_POST['id']??0); $module=$_POST['module_key']??''; if(!isset(module_defs()[$module])) throw new RuntimeException('ماژول نامعتبر است.'); $title=trim($_POST['title']??''); if(!$title) throw new RuntimeException('عنوان الزامی است.');
    $data=[$module,(int)($_POST['company_id']?:0)?:null,$title,trim($_POST['category']??''),trim($_POST['period_label']??''),input_date_to_sql($_POST['due_date']??''),trim($_POST['status']??'باز'),input_date_to_sql($_POST['completed_date']??''),(float)($_POST['amount']??0),trim($_POST['responsible']??''),trim($_POST['notes']??''),extra_encode($_POST['extra']??[])];
    if($id) pdo()->prepare("UPDATE module_records SET module_key=?, company_id=?, title=?, category=?, period_label=?, due_date=?, status=?, completed_date=?, amount=?, responsible=?, notes=?, extra_json=?, updated_at=NOW() WHERE id=?")->execute([...$data,$id]);
    else { pdo()->prepare("INSERT INTO module_records (module_key,company_id,title,category,period_label,due_date,status,completed_date,amount,responsible,notes,extra_json,created_by,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW())")->execute([...$data,Auth::user()['id']]); $id=(int)pdo()->lastInsertId(); }
    log_activity('module_records',$id,'save','ذخیره '.module_title($module)); flash('رکورد ذخیره شد.'); redirect('index.php?page=module&module='.$module);
}
function handle_save_custom_field(): void
{
    $label=trim($_POST['label']??''); $entity=trim($_POST['entity_key']??''); if(!$label || !$entity) throw new RuntimeException('عنوان و بخش الزامی است.'); $key=trim($_POST['field_key']??'') ?: preg_replace('/[^a-zA-Z0-9_]+/','_',strtolower($label)); if(!$key) $key='field_'.time();
    pdo()->prepare("INSERT INTO custom_fields (entity_key,field_key,label,field_type,options,sort_order,active,created_at,updated_at) VALUES (?,?,?,?,?,?,1,NOW(),NOW()) ON DUPLICATE KEY UPDATE label=VALUES(label), field_type=VALUES(field_type), options=VALUES(options), sort_order=VALUES(sort_order), active=1, updated_at=NOW()")->execute([$entity,$key,$label,trim($_POST['field_type']??'text'),trim($_POST['options']??''),(int)($_POST['sort_order']??100)]);
    flash('فیلد اضافی ذخیره شد.'); redirect('index.php?page=custom_fields');
}
function handle_save_settings(): void
{
    $plain=['notifications_email_to','notifications_sms_to','ghasedak_line_number','google_client_id','google_redirect_uri','allow_google_signup','smtp_host','smtp_port','smtp_encryption','smtp_username','mail_from_name','edge_service_url','cache_ttl_seconds','api_enabled'];
    $secret=['smtp_password','ghasedak_api_key','google_client_secret','edge_service_token'];
    foreach($plain as $k) setting_set($k, trim((string)($_POST[$k]??'')),0);
    foreach($secret as $k) if(isset($_POST[$k]) && $_POST[$k] !== '') setting_set($k, (string)$_POST[$k],1);
    pdo()->prepare("INSERT INTO remote_services (service_key,title,base_url,api_key,enabled,notes,updated_at) VALUES ('edge_worker','سرویس جانبی/خانگی',?,?,?, 'آی‌پی/دامنه سرویس بیرونی برای کش، RAG یا پردازش سنگین', NOW()) ON DUPLICATE KEY UPDATE base_url=VALUES(base_url), api_key=VALUES(api_key), enabled=VALUES(enabled), updated_at=NOW()")->execute([trim($_POST['edge_service_url']??''), trim($_POST['edge_service_token']??''), isset($_POST['edge_enabled'])?1:0]);
    flash('تنظیمات ذخیره شد.'); redirect('index.php?page=settings');
}

function render_header(string $title, string $subtitle=''): void
{
    $nav = [
        'dashboard'=>'داشبورد','companies'=>'شرکت‌ها','daily'=>'برنامه روزانه','monthly'=>'برنامه ماهانه','kanban'=>'کانبان کارها',
        'custom_fields'=>'فیلدهای اضافه','settings'=>'تنظیمات'
    ];
    $mods=module_defs();
    ?><!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?=h($title)?> - Accounting CRM</title><link rel="stylesheet" href="assets/style.css?v=2.0"></head><body><div class="app"><aside class="sidebar compact"><div class="brand">Accounting CRM<span>سامانه سبک حسابداران</span></div><nav><?php foreach($nav as $k=>$v): ?><a class="<?=($_GET['page']??'dashboard')===$k?'active':''?>" href="index.php?page=<?=$k?>"><?=h($v)?></a><?php endforeach; ?><div class="nav-title">پرونده‌های کاری</div><?php foreach($mods as $k=>$m): ?><a class="<?=($_GET['module']??'')===$k?'active':''?>" href="index.php?page=module&module=<?=$k?>"><span><?=h($m['icon'])?></span><?=h($m['title'])?></a><?php endforeach; ?></nav></aside><main class="main"><header class="topbar"><div><h1><?=h($title)?></h1><?php if($subtitle): ?><p><?=h($subtitle)?></p><?php endif; ?></div><div class="top-actions"><a class="btn tiny" href="index.php?page=dashboard">امروز: <?=h(Jalali::today())?></a><form method="post" class="inline-form"><?=csrf_field()?><input type="hidden" name="action" value="logout"><button class="btn tiny" type="submit">خروج</button></form></div></header><?php foreach(flashes() as $f): ?><div class="alert <?=h($f['type'])?>"><?=h($f['msg'])?></div><?php endforeach; ?><?php
}
function render_footer(): void { ?></main></div><script>window.CSRF='<?=h(csrf_token())?>';</script><script src="assets/app.js?v=2.0"></script></body></html><?php }
function require_page(): void { Auth::require(); }

if ($page === 'login') { render_login(); exit; }
require_page();
if ($page === 'dashboard') render_dashboard();
elseif($page === 'companies') render_companies();
elseif($page === 'daily') render_daily();
elseif($page === 'monthly') render_monthly();
elseif($page === 'kanban') render_kanban();
elseif($page === 'module') render_module($_GET['module'] ?? 'banks');
elseif($page === 'custom_fields') render_custom_fields();
elseif($page === 'settings') render_settings();
else render_dashboard();

function render_login(): void
{
    ?><!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>ورود</title><link rel="stylesheet" href="assets/style.css?v=2.0"></head><body class="login-page"><main class="login-card"><h1>ورود به سامانه حسابداران</h1><p>مدیریت سررسیدها، شرکت‌ها و کارهای حسابداری</p><?php foreach(flashes() as $f): ?><div class="alert <?=h($f['type'])?>"><?=h($f['msg'])?></div><?php endforeach; ?><form method="post" class="grid-form autosave" data-form-key="login"><?=csrf_field()?><input type="hidden" name="action" value="login"><label>ایمیل<input type="email" name="email" required></label><label>رمز عبور<input type="password" name="password" required></label><button class="btn primary" type="submit">ورود</button><a class="btn google" href="index.php?page=google_start">ورود یا ثبت‌نام با گوگل</a></form></main><script src="assets/app.js?v=2.0"></script></body></html><?php
}
function render_dashboard(): void
{
    $today=date('Y-m-d'); $next7=date('Y-m-d', strtotime('+7 days')); $next15=date('Y-m-d', strtotime('+15 days'));
    $kpis=[
        'کارهای امروز'=>scalarv("SELECT COUNT(*) FROM monthly_plans WHERE legal_deadline=? AND status<>'انجام شده'",[$today]) + scalarv("SELECT COUNT(*) FROM module_records WHERE due_date=? AND status<>'انجام شده'",[$today]),
        '۷ روز آینده'=>scalarv("SELECT COUNT(*) FROM monthly_plans WHERE legal_deadline BETWEEN ? AND ? AND status<>'انجام شده'",[$today,$next7]) + scalarv("SELECT COUNT(*) FROM module_records WHERE due_date BETWEEN ? AND ? AND status<>'انجام شده'",[$today,$next7]),
        'عقب‌افتاده'=>scalarv("SELECT COUNT(*) FROM monthly_plans WHERE legal_deadline < ? AND status<>'انجام شده'",[$today]) + scalarv("SELECT COUNT(*) FROM module_records WHERE due_date < ? AND status<>'انجام شده'",[$today]),
        'باز'=>scalarv("SELECT COUNT(*) FROM monthly_plans WHERE status IN ('باز','در حال انجام','منتظر مدارک','معوق')") + scalarv("SELECT COUNT(*) FROM module_records WHERE status IN ('باز','در حال انجام','منتظر مدارک','معوق')"),
        'انجام‌شده'=>scalarv("SELECT COUNT(*) FROM monthly_plans WHERE status='انجام شده'") + scalarv("SELECT COUNT(*) FROM module_records WHERE status='انجام شده'"),
        'شرکت فعال'=>scalarv("SELECT COUNT(*) FROM companies WHERE active=1"),
    ];
    render_header('داشبورد مدیریتی','نمای سریع سررسیدها و بخش‌های اصلی حسابداری ایران');
    echo '<section class="module-grid">'; foreach(module_defs() as $key=>$m){ $cnt=scalarv("SELECT COUNT(*) FROM module_records WHERE module_key=? AND status<>'انجام شده'",[$key]); echo '<a class="module-tile" href="index.php?page=module&module='.h($key).'"><b>'.h($m['icon']).' '.h($m['title']).'</b><span>'.$cnt.' مورد باز</span></a>'; } echo '</section>';
    echo '<section class="kpis">'; foreach($kpis as $k=>$v) echo '<div class="kpi"><span>'.h($k).'</span><strong>'.h($v).'</strong></div>'; echo '</section>';
    $due=q("SELECT 'monthly_plans' entity,id,company_id,work_type title,legal_deadline d,status FROM monthly_plans WHERE legal_deadline<=? AND status<>'انجام شده' UNION ALL SELECT 'module_records',id,company_id,title,due_date,status FROM module_records WHERE due_date<=? AND status<>'انجام شده' ORDER BY d LIMIT 14",[$next15,$next15]);
    echo '<div class="grid-2"><section class="card"><div class="section-title"><h2>سررسیدهای فوری</h2><a class="btn tiny" href="index.php?page=monthly">همه برنامه ماهانه</a></div><div class="table-wrap"><table class="mini"><thead><tr><th>شرکت</th><th>کار</th><th>مهلت</th><th>وضعیت</th></tr></thead><tbody>';
    foreach($due as $r){ $c=one("SELECT name FROM companies WHERE id=?",[$r['company_id']]); echo '<tr><td>'.h($c['name']??'').'</td><td>'.h($r['title']).'</td><td>'.h(fa_date($r['d'])).'</td><td>'.status_badge($r['status']).'</td></tr>'; }
    echo '</tbody></table></div></section>';
    $daily=q("SELECT d.*, c.name company_name FROM daily_plans d LEFT JOIN companies c ON c.id=d.company_id WHERE d.plan_date IS NULL OR d.plan_date>=? ORDER BY d.plan_date IS NULL, d.plan_date LIMIT 10",[$today]);
    echo '<section class="card"><div class="section-title"><h2>برنامه روزانه</h2><a class="btn tiny" href="index.php?page=daily">مدیریت</a></div><div class="daily-list">'; foreach($daily as $d){ echo '<div><b>'.h(fa_date($d['plan_date'])).' - '.h($d['day_name']).'</b><span>'.h($d['company_name']).' — '.h($d['work_description']).'</span></div>'; } echo '</div></section></div>';
    render_footer();
}
function render_companies(): void
{
    render_header('شرکت‌ها','ستون روزهای حضور حذف شده؛ اطلاعات ثبتی و مدیریتی اضافه شده است.');
    echo '<section class="card"><details><summary>افزودن سریع شرکت</summary><form method="post" class="grid-form compact autosave" data-form-key="company">'.csrf_field().'<input type="hidden" name="action" value="save_company"><label>نام شرکت<input name="name" required></label><label>نوع شرکت<input name="company_type" list="companyTypes"></label><label>شخصیت<select name="legal_personality"><option>حقوقی</option><option>حقیقی</option></select></label><label>نرم‌افزار<input name="software" placeholder="سپیدار، هلو، راهکاران..."></label><label class="span2">آدرس<input name="address"></label><label>شماره ثبت<input name="registration_number"></label><label>کدپستی<input name="postal_code"></label><label>مدیرعامل<input name="ceo_name"></label><label>کدملی مدیرعامل<input name="ceo_national_id"></label><label>موبایل مدیرعامل<input name="ceo_mobile"></label><label>شماره تلفن<input name="phone"></label><label>شناسه ملی<input name="national_id"></label><label>کد اقتصادی<input name="economic_code"></label>'.render_extra_inputs('companies').'<button class="btn primary">ذخیره</button></form></details><datalist id="companyTypes"><option value="موسسه"><option value="شرکت"><option value="فروشگاه"><option value="شخص حقیقی"></datalist></section>';
    echo quick_filters(['q'=>['label'=>'جستجو'], 'type'=>['label'=>'نوع/شخصیت']]);
    $where=['active=1'];$params=[]; if($qv=trim($_GET['q']??'')){ $where[]="(name LIKE ? OR national_id LIKE ? OR economic_code LIKE ? OR ceo_name LIKE ?)"; $like="%$qv%"; array_push($params,$like,$like,$like,$like);} if($tv=trim($_GET['type']??'')){ $where[]="(company_type LIKE ? OR legal_personality LIKE ?)"; $like="%$tv%"; array_push($params,$like,$like);}
    $rows=q("SELECT * FROM companies WHERE ".implode(' AND ',$where)." ORDER BY id",$params); $fields=custom_fields('companies');
    echo '<section class="card table-card"><div class="section-title"><h2>لیست شرکت‌ها</h2><span class="muted">ویرایش مستقیم: روی خانه‌ها کلیک کنید یا کومبو را تغییر دهید.</span></div><div class="table-wrap"><table class="data-table compact-table" data-entity="companies"><thead><tr><th>عملیات</th><th>نام شرکت</th><th>نوع شرکت</th><th>شخصیت</th><th>نرم‌افزار</th><th>آدرس</th><th>شماره ثبت</th><th>کدپستی</th><th>مدیرعامل</th><th>کدملی مدیرعامل</th><th>تماس مدیرعامل</th><th>تلفن</th><th>شناسه ملی</th><th>کد اقتصادی</th>'; foreach($fields as $f) echo '<th>'.h($f['label']).'</th>'; echo '</tr></thead><tbody>';
    foreach($rows as $r){ $extra=extra_decode($r['extra_json']??''); echo '<tr data-id="'.(int)$r['id'].'"><td>'.row_actions('companies',$r['id']).'</td><td contenteditable data-field="name">'.h($r['name']).'</td><td>'.select_inline('company_type',$r['company_type']?:$r['type'],['موسسه','شرکت','فروشگاه','شخص حقیقی']).'</td><td>'.select_inline('legal_personality',$r['legal_personality'],['حقوقی','حقیقی']).'</td><td contenteditable data-field="software">'.h($r['software']).'</td><td contenteditable data-field="address">'.h($r['address']).'</td><td contenteditable data-field="registration_number">'.h($r['registration_number']).'</td><td contenteditable data-field="postal_code">'.h($r['postal_code']).'</td><td contenteditable data-field="ceo_name">'.h($r['ceo_name']?:$r['manager_name']).'</td><td contenteditable data-field="ceo_national_id">'.h($r['ceo_national_id']).'</td><td contenteditable data-field="ceo_mobile">'.h($r['ceo_mobile']).'</td><td contenteditable data-field="phone">'.h($r['phone']).'</td><td contenteditable data-field="national_id">'.h($r['national_id']).'</td><td contenteditable data-field="economic_code">'.h($r['economic_code']).'</td>'; foreach($fields as $f) echo '<td contenteditable data-field="extra.'.h($f['field_key']).'">'.h($extra[$f['field_key']]??'').'</td>'; echo '</tr>'; }
    echo '</tbody></table></div></section>'; render_footer();
}
function select_inline(string $field, $selected, array $items): string { $html='<select data-field="'.h($field).'" class="inline-select">'; foreach($items as $i) $html.='<option '.((string)$selected===(string)$i?'selected':'').'>'.h($i).'</option>'; return $html.'</select>'; }
function company_select_inline($selected): string { return '<select data-field="company_id" class="inline-select">'.company_options($selected,false).'</select>'; }
function status_select_inline($selected): string { return '<select data-field="status" class="inline-select">'.status_options($selected,false).'</select>'; }
function row_actions(string $entity, int $id): string { return '<div class="row-actions"><button type="button" class="btn icon danger" data-delete data-entity="'.h($entity).'" data-id="'.$id.'">حذف</button></div>'; }
function render_daily(): void
{
    render_header('برنامه روزانه','تاریخ، روز، شرکت، شرح کار، موقعیت و توضیحات');
    echo '<section class="card"><details open><summary>افزودن برنامه روزانه</summary><form method="post" class="grid-form compact autosave" data-form-key="daily">'.csrf_field().'<input type="hidden" name="action" value="save_daily_plan"><label>تاریخ<input class="jalali-date" name="plan_date" placeholder="1405/01/01"></label><label>روز<input name="day_name"></label><label>شرکت<select name="company_id">'.company_options().'</select></label><label class="span2">شرح کار<input name="work_description" required></label><label>موقعیت<input name="location" placeholder="حضوری، دورکاری، بانک، اداره مالیات..."></label><label>وضعیت<select name="status">'.status_options('باز').'</select></label><label class="span2">توضیحات<input name="notes"></label>'.render_extra_inputs('daily_plans').'<button class="btn primary">ذخیره</button></form></details></section>'.quick_filters(['q'=>['label'=>'جستجو'], 'company_id'=>['label'=>'شرکت','type'=>'company'], 'status'=>['label'=>'وضعیت','type'=>'status'], 'from'=>['label'=>'از تاریخ'], 'to'=>['label'=>'تا تاریخ']]);
    $where=['1=1'];$params=[]; if($v=trim($_GET['q']??'')){ $where[]="(work_description LIKE ? OR location LIKE ? OR notes LIKE ?)";$l="%$v%";array_push($params,$l,$l,$l);} if($v=$_GET['company_id']??''){ $where[]='company_id=?';$params[]=$v;} if($v=$_GET['status']??''){ $where[]='status=?';$params[]=$v;} if($v=trim($_GET['from']??'')){ $where[]='plan_date>=?';$params[]=input_date_to_sql($v);} if($v=trim($_GET['to']??'')){ $where[]='plan_date<=?';$params[]=input_date_to_sql($v);} $rows=q("SELECT d.*,c.name company_name FROM daily_plans d LEFT JOIN companies c ON c.id=d.company_id WHERE ".implode(' AND ',$where)." ORDER BY plan_date DESC,id DESC LIMIT 300",$params); $fields=custom_fields('daily_plans');
    echo '<section class="card table-card"><div class="table-wrap"><table class="data-table compact-table" data-entity="daily_plans"><thead><tr><th>عملیات</th><th>تاریخ</th><th>روز</th><th>شرکت</th><th>شرح کار</th><th>موقعیت</th><th>وضعیت</th><th>توضیحات</th>'; foreach($fields as $f) echo '<th>'.h($f['label']).'</th>'; echo '</tr></thead><tbody>';
    foreach($rows as $r){ $extra=extra_decode($r['extra_json']??''); echo '<tr data-id="'.(int)$r['id'].'"><td>'.row_actions('daily_plans',$r['id']).'</td><td contenteditable data-field="plan_date">'.h(fa_date($r['plan_date'])).'</td><td contenteditable data-field="day_name">'.h($r['day_name']).'</td><td>'.company_select_inline($r['company_id']).'</td><td contenteditable data-field="work_description">'.h($r['work_description']).'</td><td contenteditable data-field="location">'.h($r['location']).'</td><td>'.status_select_inline($r['status']).'</td><td contenteditable data-field="notes">'.h($r['notes']).'</td>'; foreach($fields as $f) echo '<td contenteditable data-field="extra.'.h($f['field_key']).'">'.h($extra[$f['field_key']]??'').'</td>'; echo '</tr>'; }
    echo '</tbody></table></div></section>'; render_footer();
}
function render_monthly(): void
{
    render_header('برنامه ماهانه','نام شرکت، ماه، فصل، نوع کار، مهلت قانونی، وضعیت، روز انجام، تاریخ انجام');
    echo '<section class="card"><details><summary>افزودن برنامه ماهانه</summary><form method="post" class="grid-form compact autosave" data-form-key="monthly">'.csrf_field().'<input type="hidden" name="action" value="save_monthly_plan"><label>شرکت<select name="company_id">'.company_options().'</select></label><label>سال<input name="jalali_year" value="1405"></label><label>ماه<select name="month_name">'.month_options().'</select></label><label>فصل<select name="season">'.season_options().'</select></label><label>نوع کار<select name="work_type">'.work_type_options().'</select></label><label>مهلت قانونی<input class="jalali-date" name="legal_deadline"></label><label>وضعیت<select name="status">'.status_options('باز').'</select></label><label>روز انجام<input name="work_day"></label><label>تاریخ انجام<input class="jalali-date" name="completed_date"></label><label class="span2">توضیحات<input name="notes"></label>'.render_extra_inputs('monthly_plans').'<button class="btn primary">ذخیره</button></form></details></section>'.quick_filters(['q'=>['label'=>'جستجو'], 'company_id'=>['label'=>'شرکت','type'=>'company'], 'status'=>['label'=>'وضعیت','type'=>'status'], 'month'=>['label'=>'ماه','type'=>'month'], 'season'=>['label'=>'فصل','type'=>'season'], 'work_type'=>['label'=>'نوع کار','type'=>'work_type']]);
    $where=['1=1'];$params=[]; if($v=trim($_GET['q']??'')){ $where[]="(notes LIKE ? OR work_day LIKE ?)";$l="%$v%";array_push($params,$l,$l);} if($v=$_GET['company_id']??''){ $where[]='m.company_id=?';$params[]=$v;} if($v=$_GET['status']??''){ $where[]='m.status=?';$params[]=$v;} if($v=$_GET['month']??''){ $where[]='m.month_name=?';$params[]=$v;} if($v=$_GET['season']??''){ $where[]='m.season=?';$params[]=$v;} if($v=$_GET['work_type']??''){ $where[]='m.work_type=?';$params[]=$v;} $rows=q("SELECT m.*,c.name company_name FROM monthly_plans m LEFT JOIN companies c ON c.id=m.company_id WHERE ".implode(' AND ',$where)." ORDER BY legal_deadline IS NULL, legal_deadline ASC,id DESC LIMIT 500",$params); $fields=custom_fields('monthly_plans');
    echo '<section class="card table-card"><div class="table-wrap"><table class="data-table compact-table" data-entity="monthly_plans"><thead><tr><th>عملیات</th><th>شرکت</th><th>ماه</th><th>فصل</th><th>نوع کار</th><th>مهلت قانونی</th><th>وضعیت</th><th>روز انجام</th><th>تاریخ انجام</th><th>توضیحات</th>'; foreach($fields as $f) echo '<th>'.h($f['label']).'</th>'; echo '</tr></thead><tbody>';
    foreach($rows as $r){ $extra=extra_decode($r['extra_json']??''); echo '<tr data-id="'.(int)$r['id'].'"><td>'.row_actions('monthly_plans',$r['id']).'</td><td>'.company_select_inline($r['company_id']).'</td><td>'.select_inline('month_name',$r['month_name'],['فروردین','اردیبهشت','خرداد','تیر','مرداد','شهریور','مهر','آبان','آذر','دی','بهمن','اسفند','سالانه']).'</td><td>'.select_inline('season',$r['season'],['بهار','تابستان','پاییز','زمستان','سالانه']).'</td><td>'.select_inline('work_type',$r['work_type'],work_types()).'</td><td contenteditable data-field="legal_deadline">'.h(fa_date($r['legal_deadline'])).'</td><td>'.status_select_inline($r['status']).'</td><td contenteditable data-field="work_day">'.h($r['work_day']).'</td><td contenteditable data-field="completed_date">'.h(fa_date($r['completed_date'])).'</td><td contenteditable data-field="notes">'.h($r['notes']).'</td>'; foreach($fields as $f) echo '<td contenteditable data-field="extra.'.h($f['field_key']).'">'.h($extra[$f['field_key']]??'').'</td>'; echo '</tr>'; }
    echo '</tbody></table></div></section>'; render_footer();
}
function render_module(string $module): void
{
    $defs=module_defs(); if(!isset($defs[$module])) $module='banks'; $m=$defs[$module]; render_header($m['icon'].' '.$m['title'],'لیست تخصصی با افزودن، ویرایش مستقیم، حذف، فیلتر کامل و فیلد اضافه');
    echo '<section class="card"><details><summary>افزودن سریع</summary><form method="post" class="grid-form compact autosave" data-form-key="module-'.$module.'">'.csrf_field().'<input type="hidden" name="action" value="save_module_record"><input type="hidden" name="module_key" value="'.h($module).'"><label>شرکت<select name="company_id">'.company_options().'</select></label><label class="span2">عنوان<input name="title" required value="'.h($m['title']).'"></label><label>دسته<input name="category" value="'.h($m['work_type']).'"></label><label>دوره<input name="period_label" placeholder="مثلاً مرداد ۱۴۰۵"></label><label>سررسید<input class="jalali-date" name="due_date"></label><label>وضعیت<select name="status">'.status_options('باز').'</select></label><label>تاریخ انجام<input class="jalali-date" name="completed_date"></label><label>مبلغ<input name="amount" type="number" step="0.01"></label><label>مسئول<input name="responsible"></label><label class="span2">توضیحات<input name="notes"></label>'.render_extra_inputs('module_records').'<button class="btn primary">ذخیره</button></form></details></section>'.quick_filters(['q'=>['label'=>'جستجو'], 'company_id'=>['label'=>'شرکت','type'=>'company'], 'status'=>['label'=>'وضعیت','type'=>'status'], 'from'=>['label'=>'از سررسید'], 'to'=>['label'=>'تا سررسید']]);
    $where=['r.module_key=?'];$params=[$module]; if($v=trim($_GET['q']??'')){ $where[]="(r.title LIKE ? OR r.category LIKE ? OR r.notes LIKE ? OR r.responsible LIKE ?)";$l="%$v%";array_push($params,$l,$l,$l,$l);} if($v=$_GET['company_id']??''){ $where[]='r.company_id=?';$params[]=$v;} if($v=$_GET['status']??''){ $where[]='r.status=?';$params[]=$v;} if($v=trim($_GET['from']??'')){ $where[]='r.due_date>=?';$params[]=input_date_to_sql($v);} if($v=trim($_GET['to']??'')){ $where[]='r.due_date<=?';$params[]=input_date_to_sql($v);} $rows=q("SELECT r.*,c.name company_name FROM module_records r LEFT JOIN companies c ON c.id=r.company_id WHERE ".implode(' AND ',$where)." ORDER BY r.due_date IS NULL, r.due_date ASC, r.id DESC LIMIT 500",$params); $fields=custom_fields('module_records');
    echo '<section class="card table-card"><div class="table-wrap"><table class="data-table compact-table" data-entity="module_records"><thead><tr><th>عملیات</th><th>شرکت</th><th>عنوان</th><th>دسته</th><th>دوره</th><th>سررسید</th><th>وضعیت</th><th>تاریخ انجام</th><th>مبلغ</th><th>مسئول</th><th>یادداشت</th>'; foreach($fields as $f) echo '<th>'.h($f['label']).'</th>'; echo '</tr></thead><tbody>';
    foreach($rows as $r){ $extra=extra_decode($r['extra_json']??''); echo '<tr data-id="'.(int)$r['id'].'"><td>'.row_actions('module_records',$r['id']).'</td><td>'.company_select_inline($r['company_id']).'</td><td contenteditable data-field="title">'.h($r['title']).'</td><td contenteditable data-field="category">'.h($r['category']).'</td><td contenteditable data-field="period_label">'.h($r['period_label']).'</td><td contenteditable data-field="due_date">'.h(fa_date($r['due_date'])).'</td><td>'.status_select_inline($r['status']).'</td><td contenteditable data-field="completed_date">'.h(fa_date($r['completed_date'])).'</td><td contenteditable data-field="amount">'.h($r['amount']).'</td><td contenteditable data-field="responsible">'.h($r['responsible']).'</td><td contenteditable data-field="notes">'.h($r['notes']).'</td>'; foreach($fields as $f) echo '<td contenteditable data-field="extra.'.h($f['field_key']).'">'.h($extra[$f['field_key']]??'').'</td>'; echo '</tr>'; }
    echo '</tbody></table></div></section>'; render_footer();
}
function render_kanban(): void
{
    render_header('کانبان کارها','نمای بصری تسک‌ها و سررسیدها برای کنترل سریع'); $statuses=['باز','در حال انجام','منتظر مدارک','معوق','انجام شده']; echo quick_filters(['company_id'=>['label'=>'شرکت','type'=>'company'], 'work_type'=>['label'=>'نوع کار','type'=>'work_type']]); $company=$_GET['company_id']??''; $type=$_GET['work_type']??'';
    echo '<section class="kanban">'; foreach($statuses as $s){ $params=[$s]; $where='status=?'; if($company){$where.=' AND company_id=?';$params[]=$company;} if($type){$where.=' AND work_type=?';$params[]=$type;} $rows=q("SELECT m.*,c.name company_name FROM monthly_plans m LEFT JOIN companies c ON c.id=m.company_id WHERE $where ORDER BY legal_deadline IS NULL, legal_deadline LIMIT 40",$params); echo '<div class="kanban-col"><h3>'.h($s).' <span>'.count($rows).'</span></h3>'; foreach($rows as $r){ echo '<article class="kanban-card"><b>'.h($r['work_type']).'</b><small>'.h($r['company_name']).' — '.h($r['month_name']).'</small><time>'.h(fa_date($r['legal_deadline'])).'</time></article>'; } echo '</div>'; } echo '</section>'; render_footer();
}
function render_custom_fields(): void
{
    render_header('فیلدهای اضافه','برای هر بخش ستون دلخواه تعریف کنید؛ مقدارش در JSON همان رکورد ذخیره می‌شود.');
    $entities=['companies'=>'شرکت‌ها','daily_plans'=>'برنامه روزانه','monthly_plans'=>'برنامه ماهانه','module_records'=>'لیست‌های تخصصی'];
    echo '<section class="card"><form method="post" class="grid-form compact">'.csrf_field().'<input type="hidden" name="action" value="save_custom_field"><label>بخش<select name="entity_key">'; foreach($entities as $k=>$v) echo '<option value="'.h($k).'">'.h($v).'</option>'; echo '</select></label><label>عنوان ستون<input name="label" required></label><label>کلید انگلیسی اختیاری<input name="field_key" placeholder="tracking_no"></label><label>نوع فیلد<select name="field_type"><option value="text">متن</option><option value="number">عدد</option><option value="date">تاریخ</option><option value="select">لیست</option></select></label><label>گزینه‌ها<input name="options" placeholder="برای لیست با ، جدا شود"></label><label>ترتیب<input name="sort_order" value="100"></label><button class="btn primary">افزودن ستون</button></form></section>';
    $rows=q("SELECT * FROM custom_fields WHERE active=1 ORDER BY entity_key,sort_order,id"); echo '<section class="card"><table class="compact-table"><thead><tr><th>بخش</th><th>کلید</th><th>عنوان</th><th>نوع</th><th>حذف</th></tr></thead><tbody>'; foreach($rows as $r){ echo '<tr><td>'.h($entities[$r['entity_key']]??$r['entity_key']).'</td><td>'.h($r['field_key']).'</td><td>'.h($r['label']).'</td><td>'.h($r['field_type']).'</td><td><form method="post" onsubmit="return confirm(\'حذف شود؟\')">'.csrf_field().'<input type="hidden" name="action" value="delete_custom_field"><input type="hidden" name="id" value="'.(int)$r['id'].'"><button class="btn danger tiny">حذف</button></form></td></tr>'; } echo '</tbody></table></section>'; render_footer();
}
function render_settings(): void
{
    render_header('تنظیمات','SMTP، پیامک، گوگل، کش سبک و سرویس جانبی/AI آینده');
    $base=base_url('cron.php?secret='.setting('cron_secret','')); echo '<section class="card"><form method="post" class="grid-form compact autosave" data-form-key="settings">'.csrf_field().'<input type="hidden" name="action" value="save_settings"><h2 class="span3">ایمیل Gmail SMTP</h2><label>SMTP Host<input name="smtp_host" value="'.h(setting('smtp_host','smtp.gmail.com')).'"></label><label>SMTP Port<input name="smtp_port" value="'.h(setting('smtp_port','587')).'"></label><label>Encryption<select name="smtp_encryption"><option value="tls">TLS</option><option value="ssl">SSL</option></select></label><label>SMTP Username<input name="smtp_username" value="'.h(setting('smtp_username','')).'"></label><label>SMTP Password جدید<input name="smtp_password" type="password" placeholder="خالی بماند تغییر نمی‌کند"></label><label>نام فرستنده<input name="mail_from_name" value="'.h(setting('mail_from_name','Accounting CRM')).'"></label><h2 class="span3">پیامک قاصدک</h2><label>Ghasedak API Key<input name="ghasedak_api_key" type="password" placeholder="خالی بماند تغییر نمی‌کند"></label><label>Line Number<input name="ghasedak_line_number" value="'.h(setting('ghasedak_line_number','')).'"></label><label>شماره‌های پیامک<input name="notifications_sms_to" value="'.h(setting('notifications_sms_to','')).'"></label><h2 class="span3">Google OAuth</h2><label>Client ID<input name="google_client_id" value="'.h(setting('google_client_id','')).'"></label><label>Client Secret جدید<input name="google_client_secret" type="password" placeholder="خالی بماند تغییر نمی‌کند"></label><label>Redirect URI<input name="google_redirect_uri" value="'.h(setting('google_redirect_uri',base_url('index.php?page=google_callback'))).' "></label><h2 class="span3">کش سبک و سرویس جانبی</h2><label>Cache TTL ثانیه<input name="cache_ttl_seconds" value="'.h(setting('cache_ttl_seconds','30')).'"></label><label>آدرس سرویس خانگی/Edge<input name="edge_service_url" placeholder="https://your-public-ip:8443" value="'.h(setting('edge_service_url','')).'"></label><label>توکن سرویس جانبی<input name="edge_service_token" type="password" placeholder="خالی بماند تغییر نمی‌کند"></label><label class="check"><input type="checkbox" name="edge_enabled"> فعال‌سازی سرویس جانبی</label><label class="check"><input type="checkbox" name="api_enabled" value="1" '.(setting('api_enabled','1')==='1'?'checked':'').'> API داخلی فعال باشد</label><label>ایمیل‌های اعلان<input name="notifications_email_to" value="'.h(setting('notifications_email_to','')).'"></label><button class="btn primary">ذخیره تنظیمات</button></form></section><section class="card"><h2>مایگریشن و کران</h2><form method="post">'.csrf_field().'<input type="hidden" name="action" value="run_migration"><button class="btn">اجرای مایگریشن دیتابیس</button></form><p class="muted">Cron Job پیشنهادی در cPanel:</p><code class="code">/usr/local/bin/php -q '.h(APP_ROOT).'/cron.php</code><p class="muted">یا URL:</p><code class="code">'.h($base).'</code></section>'; render_footer();
}
