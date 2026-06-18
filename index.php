<?php
require __DIR__ . '/app/bootstrap.php';

function q(string $sql, array $params=[]): array { $st=pdo()->prepare($sql); $st->execute($params); return $st->fetchAll(); }
function one(string $sql, array $params=[]): ?array { $st=pdo()->prepare($sql); $st->execute($params); $r=$st->fetch(); return $r ?: null; }
function scalar(string $sql, array $params=[]): int { $st=pdo()->prepare($sql); $st->execute($params); return (int)$st->fetchColumn(); }
function companies(): array { return q("SELECT * FROM companies WHERE active=1 ORDER BY id"); }
function company_options($selected=null, bool $all=false): string { $html=$all?'<option value="">همه شرکت‌ها</option>':''; foreach (companies() as $c) $html.='<option value="'.(int)$c['id'].'" '.((string)$selected===(string)$c['id']?'selected':'').'>'.h($c['name']).'</option>'; return $html; }
function input_date_to_sql(string $j): ?string { $j=trim($j); if (!$j) return null; return Jalali::parse($j) ?: (preg_match('/^\d{4}-\d{2}-\d{2}$/',$j) ? $j : null); }

if (isset($_GET['action']) && $_GET['action']==='export_xlsx') { Auth::require(); XlsxWriter::stream(); }

$page = $_GET['page'] ?? 'dashboard';

if ($page === 'google_start') {
    $clientId = setting('google_client_id',''); $redirect = setting('google_redirect_uri', base_url('index.php?page=google_callback'));
    if (!$clientId) { flash('ابتدا Google Client ID را در تنظیمات وارد کنید.','danger'); redirect('index.php?page=login'); }
    $_SESSION['google_oauth_state'] = bin2hex(random_bytes(16));
    $url = 'https://accounts.google.com/o/oauth2/v2/auth?'.http_build_query([
        'client_id'=>$clientId,'redirect_uri'=>$redirect,'response_type'=>'code','scope'=>'openid email profile','state'=>$_SESSION['google_oauth_state'],'access_type'=>'online','prompt'=>'select_account'
    ]);
    redirect($url);
}
if ($page === 'google_callback') {
    try {
        if (($_GET['state'] ?? '') !== ($_SESSION['google_oauth_state'] ?? '')) throw new RuntimeException('درخواست گوگل معتبر نیست.');
        $code = $_GET['code'] ?? ''; if (!$code) throw new RuntimeException('کد ورود گوگل دریافت نشد.');
        $redirect = setting('google_redirect_uri', base_url('index.php?page=google_callback'));
        $post = http_build_query(['code'=>$code,'client_id'=>setting('google_client_id',''),'client_secret'=>setting('google_client_secret',''),'redirect_uri'=>$redirect,'grant_type'=>'authorization_code']);
        $ch=curl_init('https://oauth2.googleapis.com/token'); curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$post,CURLOPT_HTTPHEADER=>['Content-Type: application/x-www-form-urlencoded'],CURLOPT_TIMEOUT=>25]);
        $token=json_decode(curl_exec($ch),true); $err=curl_error($ch); curl_close($ch); if ($err || empty($token['access_token'])) throw new RuntimeException('خطا در دریافت توکن گوگل: '.($err ?: json_encode($token,JSON_UNESCAPED_UNICODE)));
        $ch=curl_init('https://openidconnect.googleapis.com/v1/userinfo'); curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$token['access_token']],CURLOPT_TIMEOUT=>25]);
        $info=json_decode(curl_exec($ch),true); curl_close($ch);
        $email=mb_strtolower($info['email'] ?? ''); if (!$email) throw new RuntimeException('ایمیل گوگل دریافت نشد.');
        $user = one("SELECT * FROM users WHERE email=? OR google_id=? LIMIT 1", [$email, $info['sub'] ?? '']);
        if (!$user) {
            if (setting('allow_google_signup','1') !== '1') throw new RuntimeException('ثبت‌نام با گوگل فعال نیست.');
            $st=pdo()->prepare("INSERT INTO users (name,email,google_id,avatar,role,status,created_at,updated_at) VALUES (?,?,?,?, 'accountant','active',NOW(),NOW())");
            $st->execute([$info['name'] ?? $email,$email,$info['sub'] ?? null,$info['picture'] ?? null]);
            $id=(int)pdo()->lastInsertId();
        } else {
            $id=(int)$user['id'];
            $st=pdo()->prepare("UPDATE users SET google_id=COALESCE(google_id,?), avatar=COALESCE(?,avatar), updated_at=NOW() WHERE id=?"); $st->execute([$info['sub'] ?? null,$info['picture'] ?? null,$id]);
        }
        Auth::login($id); flash('ورود با گوگل انجام شد.'); redirect('index.php');
    } catch (Throwable $e) { flash($e->getMessage(),'danger'); redirect('index.php?page=login'); }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'login') { verify_csrf(); if (Auth::attempt($_POST['email'] ?? '', $_POST['password'] ?? '')) redirect('index.php'); flash('ایمیل یا رمز عبور اشتباه است.','danger'); redirect('index.php?page=login'); }
    if ($action === 'logout') { verify_csrf(); Auth::logout(); redirect('index.php?page=login'); }
    Auth::require(); verify_csrf();
    try {
        if ($action === 'save_task') {
            $id=(int)($_POST['id'] ?? 0); $due=input_date_to_sql($_POST['due_jalali'] ?? '');
            $data=[(int)($_POST['company_id'] ?: 0) ?: null, trim($_POST['code'] ?? ''), trim($_POST['category'] ?? ''), trim($_POST['title'] ?? ''), trim($_POST['frequency'] ?? ''), trim($_POST['period_label'] ?? ''), $due, (int)($_POST['reminder_days'] ?? 5), trim($_POST['priority'] ?? 'متوسط'), trim($_POST['status'] ?? 'باز'), trim($_POST['assigned_to'] ?? ''), trim($_POST['description'] ?? '')];
            if (!$data[3]) throw new RuntimeException('شرح کار الزامی است.');
            if ($id) { $st=pdo()->prepare("UPDATE tasks SET company_id=?,code=?,category=?,title=?,frequency=?,period_label=?,due_date=?,reminder_days=?,priority=?,status=?,assigned_to=?,description=?,completed_at=IF(?='انجام شده' AND completed_at IS NULL,NOW(),completed_at),updated_at=NOW() WHERE id=?"); $st->execute([...$data,$data[9],$id]); }
            else { $st=pdo()->prepare("INSERT INTO tasks (company_id,code,category,title,frequency,period_label,due_date,reminder_days,priority,status,assigned_to,description,created_by,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW())"); $st->execute([...$data, Auth::user()['id']]); }
            flash('کار ذخیره شد.'); redirect('index.php?page=tasks');
        }
        if ($action === 'done_task') { $id=(int)$_POST['id']; pdo()->prepare("UPDATE tasks SET status='انجام شده', completed_at=NOW(), updated_at=NOW() WHERE id=?")->execute([$id]); flash('کار انجام‌شده شد.'); redirect($_SERVER['HTTP_REFERER'] ?? 'index.php?page=tasks'); }
        if ($action === 'delete_task') { $id=(int)$_POST['id']; pdo()->prepare("DELETE FROM tasks WHERE id=?")->execute([$id]); flash('کار حذف شد.'); redirect('index.php?page=tasks'); }
        if ($action === 'save_company') {
            $id=(int)($_POST['id'] ?? 0); $name=trim($_POST['name'] ?? ''); if (!$name) throw new RuntimeException('نام شرکت الزامی است.');
            $data=[$name,trim($_POST['type']??''),trim($_POST['attendance']??''),trim($_POST['software']??''),trim($_POST['manager_name']??''),trim($_POST['financial_manager']??''),trim($_POST['phone']??''),trim($_POST['address']??''),trim($_POST['tax_username']??''),trim($_POST['insurance_code']??''),trim($_POST['notes']??'')];
            if ($id) pdo()->prepare("UPDATE companies SET name=?,type=?,attendance=?,software=?,manager_name=?,financial_manager=?,phone=?,address=?,tax_username=?,insurance_code=?,notes=?,updated_at=NOW() WHERE id=?")->execute([...$data,$id]);
            else pdo()->prepare("INSERT INTO companies (name,type,attendance,software,manager_name,financial_manager,phone,address,tax_username,insurance_code,notes,active,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,1,NOW(),NOW())")->execute($data);
            flash('اطلاعات شرکت ذخیره شد.'); redirect('index.php?page=companies');
        }
        if ($action === 'save_followup') {
            $id=(int)($_POST['id']??0); $date=input_date_to_sql($_POST['followup_jalali']??''); $data=[(int)($_POST['company_id']?:0)?:null,trim($_POST['requester']??''),trim($_POST['subject']??''),trim($_POST['next_action']??''),$date,trim($_POST['priority']??'متوسط'),trim($_POST['status']??'باز'),trim($_POST['notes']??'')]; if(!$data[2]) throw new RuntimeException('موضوع پیگیری الزامی است.');
            if($id) pdo()->prepare("UPDATE followups SET company_id=?,requester=?,subject=?,next_action=?,followup_date=?,priority=?,status=?,notes=?,updated_at=NOW() WHERE id=?")->execute([...$data,$id]);
            else pdo()->prepare("INSERT INTO followups (company_id,requester,subject,next_action,followup_date,priority,status,notes,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,NOW(),NOW())")->execute($data);
            flash('پیگیری ذخیره شد.'); redirect('index.php?page=followups');
        }
        if ($action === 'save_bank') {
            $id=(int)($_POST['id']??0); $disc=input_date_to_sql($_POST['discovered_jalali']??''); $target=input_date_to_sql($_POST['target_jalali']??''); $data=[(int)($_POST['company_id']?:0)?:null,trim($_POST['bank_name']??''),trim($_POST['period_label']??''),$disc,(float)($_POST['amount']??0),trim($_POST['mismatch_type']??''),trim($_POST['description']??''),trim($_POST['correction_action']??''),trim($_POST['status']??'باز'),trim($_POST['responsible']??''),$target,trim($_POST['notes']??'')];
            if($id) pdo()->prepare("UPDATE bank_reconciliations SET company_id=?,bank_name=?,period_label=?,discovered_at=?,amount=?,mismatch_type=?,description=?,correction_action=?,status=?,responsible=?,target_date=?,notes=?,updated_at=NOW() WHERE id=?")->execute([...$data,$id]);
            else pdo()->prepare("INSERT INTO bank_reconciliations (company_id,bank_name,period_label,discovered_at,amount,mismatch_type,description,correction_action,status,responsible,target_date,notes,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW())")->execute($data);
            flash('مغایرت ذخیره شد.'); redirect('index.php?page=bank');
        }
        if ($action === 'save_system') {
            $id=(int)($_POST['id']??0); $date=input_date_to_sql($_POST['last_checked_jalali']??''); $data=[(int)($_POST['company_id']?:0)?:null,trim($_POST['service_name']??''),trim($_POST['url']??''),trim($_POST['username']??''),trim($_POST['related_code']??''),trim($_POST['secret_note']??''),$date,trim($_POST['notes']??'')]; if(!$data[1]) throw new RuntimeException('نام سامانه الزامی است.');
            if($id) pdo()->prepare("UPDATE systems SET company_id=?,service_name=?,url=?,username=?,related_code=?,secret_note=?,last_checked_at=?,notes=?,updated_at=NOW() WHERE id=?")->execute([...$data,$id]);
            else pdo()->prepare("INSERT INTO systems (company_id,service_name,url,username,related_code,secret_note,last_checked_at,notes,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,NOW(),NOW())")->execute($data);
            flash('اطلاعات سامانه ذخیره شد.'); redirect('index.php?page=systems');
        }
        if ($action === 'save_error') {
            $id=(int)($_POST['id']??0); $date=input_date_to_sql($_POST['happened_jalali']??''); $data=[(int)($_POST['company_id']?:0)?:null,$date,trim($_POST['process']??''),trim($_POST['risk']??''),trim($_POST['root_cause']??''),trim($_POST['solution']??''),trim($_POST['document_no']??''),trim($_POST['prevention']??''),trim($_POST['status']??'باز')];
            if($id) pdo()->prepare("UPDATE error_notes SET company_id=?,happened_at=?,process=?,risk=?,root_cause=?,solution=?,document_no=?,prevention=?,status=?,updated_at=NOW() WHERE id=?")->execute([...$data,$id]);
            else pdo()->prepare("INSERT INTO error_notes (company_id,happened_at,process,risk,root_cause,solution,document_no,prevention,status,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,NOW(),NOW())")->execute($data);
            flash('خطا/تجربه ذخیره شد.'); redirect('index.php?page=errors');
        }
        if ($action === 'save_settings') {
            $plain = ['smtp_host','smtp_port','smtp_encryption','smtp_username','mail_from_name','mail_from_email','notifications_email_to','ghasedak_line_number','notifications_sms_to','google_client_id','google_redirect_uri','allow_google_signup','cron_secret'];
            $secret = ['smtp_password','ghasedak_api_key','google_client_secret'];
            foreach ($plain as $k) setting_set($k, (string)($_POST[$k] ?? ''), 0);
            foreach ($secret as $k) if (($_POST[$k] ?? '') !== '') setting_set($k, (string)$_POST[$k], 1);
            flash('تنظیمات ذخیره شد.'); redirect('index.php?page=settings');
        }
        if ($action === 'test_email') { $res=Notify::sendEmail(trim($_POST['test_email_to']??setting('notifications_email_to','')), 'تست ایمیل سیستم حسابداری', 'این ایمیل تست از وب‌اپ مدیریت حسابداری ارسال شده است.'); flash($res['ok']?'ایمیل تست ارسال شد.':'خطا در ارسال ایمیل: '.$res['response'], $res['ok']?'success':'danger'); redirect('index.php?page=settings'); }
        if ($action === 'test_sms') { $res=Notify::sendSms(trim($_POST['test_sms_to']??setting('notifications_sms_to','')), 'تست پیامک سیستم مدیریت حسابداری'); flash($res['ok']?'پیامک تست ارسال شد.':'خطا در ارسال پیامک: '.$res['response'], $res['ok']?'success':'danger'); redirect('index.php?page=settings'); }
        if ($action === 'send_reminders_now') { $res=Notify::sendDueReminders(); flash('بررسی سررسید انجام شد. ایمیل: '.$res['email'].'، پیامک: '.$res['sms'].'، کارها: '.$res['tasks'].($res['errors']?('، خطا: '.implode(' | ',$res['errors'])):''), $res['errors']?'warn':'success'); redirect('index.php?page=notifications'); }
        if ($action === 'import_xlsx') {
            if (empty($_FILES['xlsx']['tmp_name'])) throw new RuntimeException('فایل اکسل انتخاب نشده است.');
            $name = basename($_FILES['xlsx']['name']); $dest=__DIR__.'/storage/imports/'.date('YmdHis').'-'.preg_replace('/[^A-Za-z0-9_.-]+/','_',$name); move_uploaded_file($_FILES['xlsx']['tmp_name'],$dest);
            $stats=XlsxImporter::import($dest); pdo()->prepare("INSERT INTO imports (filename,stats,user_id,created_at) VALUES (?,?,?,NOW())")->execute([$name,json_encode($stats,JSON_UNESCAPED_UNICODE),Auth::user()['id']]);
            flash('ایمپورت انجام شد: شرکت‌ها '.$stats['companies'].'، کارها '.$stats['tasks'].'، برنامه‌ها '.$stats['schedules'].'، پیگیری‌ها '.$stats['followups']); redirect('index.php?page=import');
        }
        if ($action === 'save_user' && Auth::isAdmin()) {
            $id=(int)($_POST['id']??0); $name=trim($_POST['name']??''); $email=mb_strtolower(trim($_POST['email']??'')); $role=$_POST['role']??'accountant'; $status=$_POST['status']??'active'; $pass=$_POST['password']??''; if(!$name||!$email) throw new RuntimeException('نام و ایمیل الزامی است.');
            if($id){ if($pass) pdo()->prepare("UPDATE users SET name=?,email=?,role=?,status=?,password_hash=?,updated_at=NOW() WHERE id=?")->execute([$name,$email,$role,$status,password_hash($pass,PASSWORD_DEFAULT),$id]); else pdo()->prepare("UPDATE users SET name=?,email=?,role=?,status=?,updated_at=NOW() WHERE id=?")->execute([$name,$email,$role,$status,$id]); }
            else pdo()->prepare("INSERT INTO users (name,email,role,status,password_hash,created_at,updated_at) VALUES (?,?,?,?,?,NOW(),NOW())")->execute([$name,$email,$role,$status,$pass?password_hash($pass,PASSWORD_DEFAULT):null]);
            flash('کاربر ذخیره شد.'); redirect('index.php?page=users');
        }
    } catch (Throwable $e) { flash($e->getMessage(),'danger'); redirect($_SERVER['HTTP_REFERER'] ?? 'index.php'); }
}

function render_header(string $title): void {
    $u=Auth::user();
    echo '<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>'.h($title).'</title><link rel="stylesheet" href="assets/style.css"></head><body><div class="app">';
    if ($u) echo '<aside class="sidebar"><div class="brand">حسابیار ۱۴۰۵<span>Accounting Manager</span></div><nav>'.nav_link('dashboard','داشبورد').nav_link('tasks','کارها').nav_link('calendar','تقویم').nav_link('companies','شرکت‌ها').nav_link('followups','پیگیری‌ها').nav_link('bank','مغایرت بانکی').nav_link('systems','سامانه‌ها').nav_link('errors','خطاها').nav_link('import','اکسل/پشتیبان').nav_link('notifications','اعلان‌ها').nav_link('settings','تنظیمات').(Auth::isAdmin()?nav_link('users','کاربران'):'').'</nav></aside>';
    echo '<main class="main"><header class="topbar"><div><h1>'.h($title).'</h1><p>امروز: '.h(Jalali::today()).' / '.h(date('Y-m-d')).'</p></div>';
    if ($u) echo '<form method="post">'.csrf_field().'<input type="hidden" name="action" value="logout"><span class="user">'.h($u['name']).'</span><button class="btn ghost">خروج</button></form>';
    echo '</header>'; foreach (flashes() as $f) echo '<div class="alert '.h($f['type']).'">'.h($f['msg']).'</div>';
}
function render_footer(): void { echo '</main></div><script src="assets/app.js"></script></body></html>'; }
function nav_link(string $p,string $t): string { $active=(($_GET['page']??'dashboard')===$p)?'active':''; return '<a class="'.$active.'" href="index.php?page='.$p.'">'.$t.'</a>'; }

if ($page === 'login') { render_login(); exit; }
Auth::require();
match($page) {
    'tasks'=>render_tasks(), 'companies'=>render_companies(), 'calendar'=>render_calendar(), 'followups'=>render_followups(), 'bank'=>render_bank(), 'systems'=>render_systems(), 'errors'=>render_errors(), 'settings'=>render_settings(), 'import'=>render_import(), 'notifications'=>render_notifications(), 'users'=>render_users(), default=>render_dashboard()
};

function render_login(): void {
    $hasGoogle = file_exists(__DIR__.'/app/config.php') && setting('google_client_id','');
    echo '<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>ورود</title><link rel="stylesheet" href="assets/style.css"></head><body class="login-page"><main class="login-card"><h1>ورود به حسابیار ۱۴۰۵</h1><p>مدیریت سررسیدها، شرکت‌ها، پیگیری‌ها و گزارش‌های حسابداری.</p>';
    foreach(flashes() as $f) echo '<div class="alert '.h($f['type']).'">'.h($f['msg']).'</div>';
    echo '<form method="post" class="grid-form"><input type="hidden" name="action" value="login">'.csrf_field().'<label>ایمیل<input name="email" type="email" required autofocus></label><label>رمز عبور<input name="password" type="password" required></label><button class="btn primary">ورود</button></form>';
    echo '<a class="btn google" href="index.php?page=google_start">ورود / ثبت‌نام با گوگل</a><p class="muted">برای فعال شدن ورود گوگل، Client ID و Secret را از تنظیمات وارد کنید.</p></main></body></html>';
}

function render_dashboard(): void {
    render_header('داشبورد مدیریتی');
    $companyFilter=(int)($_GET['company_id']??0); $where=$companyFilter?' AND t.company_id='.$companyFilter:'';
    $today=date('Y-m-d');
    $kpis=[
        ['کل کارها', scalar("SELECT COUNT(*) FROM tasks t WHERE 1=1 $where")],
        ['کارهای باز', scalar("SELECT COUNT(*) FROM tasks t WHERE t.status NOT IN ('انجام شده','بسته شده') $where")],
        ['عقب‌افتاده', scalar("SELECT COUNT(*) FROM tasks t WHERE t.status NOT IN ('انجام شده','بسته شده') AND t.due_date < ? $where",[$today])],
        ['سررسید امروز', scalar("SELECT COUNT(*) FROM tasks t WHERE t.status NOT IN ('انجام شده','بسته شده') AND t.due_date = ? $where",[$today])],
        ['تا ۷ روز آینده', scalar("SELECT COUNT(*) FROM tasks t WHERE t.status NOT IN ('انجام شده','بسته شده') AND t.due_date BETWEEN ? AND DATE_ADD(?, INTERVAL 7 DAY) $where",[$today,$today])],
        ['انجام شده این ماه', scalar("SELECT COUNT(*) FROM tasks t WHERE t.status='انجام شده' AND MONTH(t.completed_at)=MONTH(CURDATE()) AND YEAR(t.completed_at)=YEAR(CURDATE()) $where")],
    ];
    echo '<section class="card"><form class="filters"><input type="hidden" name="page" value="dashboard"><label>فیلتر شرکت<select name="company_id">'.company_options($companyFilter,true).'</select></label><button class="btn">اعمال</button></form></section><section class="kpis">';
    foreach($kpis as $k) echo '<div class="kpi"><span>'.h($k[0]).'</span><strong>'.h($k[1]).'</strong></div>';
    echo '</section>';
    $rows=q("SELECT t.*, c.name company_name FROM tasks t LEFT JOIN companies c ON c.id=t.company_id WHERE t.status NOT IN ('انجام شده','بسته شده') $where ORDER BY CASE WHEN t.due_date IS NULL THEN 1 ELSE 0 END, t.due_date ASC LIMIT 20");
    echo '<section class="card"><div class="section-title"><h2>کارهای مهم و نزدیک</h2><a class="btn small" href="index.php?page=tasks">مدیریت همه کارها</a></div><div class="table-wrap"><table><thead><tr><th>شرکت</th><th>شرح کار</th><th>سررسید</th><th>وضعیت موعد</th><th>اولویت</th><th>وضعیت</th><th></th></tr></thead><tbody>';
    foreach($rows as $r) echo '<tr><td>'.h($r['company_name']).'</td><td>'.h($r['title']).'</td><td>'.h(Jalali::fromGregorian($r['due_date'])).'</td><td><span class="due '.h(due_status($r['due_date'],$r['status'])).'">'.h(due_status($r['due_date'],$r['status'])).'</span></td><td>'.h($r['priority']).'</td><td>'.status_badge($r['status']).'</td><td><form method="post">'.csrf_field().'<input type="hidden" name="action" value="done_task"><input type="hidden" name="id" value="'.(int)$r['id'].'"><button class="btn tiny">انجام شد</button></form></td></tr>';
    if(!$rows) echo '<tr><td colspan="7">فعلاً کاری برای نمایش نیست.</td></tr>';
    echo '</tbody></table></div></section>';
    $report=q("SELECT c.name, COUNT(t.id) total, SUM(t.status='انجام شده') done, SUM(t.status NOT IN ('انجام شده','بسته شده')) open_count FROM companies c LEFT JOIN tasks t ON t.company_id=c.id GROUP BY c.id ORDER BY c.id");
    echo '<section class="card"><h2>پیشرفت به تفکیک شرکت</h2><div class="progress-list">';
    foreach($report as $r){ $rate=$r['total']?round(($r['done']/$r['total'])*100):0; echo '<div><b>'.h($r['name']).'</b><span>'.$rate.'%</span><div class="bar"><i style="width:'.$rate.'%"></i></div></div>'; }
    echo '</div></section>'; render_footer();
}

function render_tasks(): void {
    render_header('مدیریت کارها و سررسیدها');
    $company=(int)($_GET['company_id']??0); $status=trim($_GET['status']??''); $search=trim($_GET['q']??'');
    $cond=[];$params=[]; if($company){$cond[]='t.company_id=?';$params[]=$company;} if($status){$cond[]='t.status=?';$params[]=$status;} if($search){$cond[]='(t.title LIKE ? OR t.category LIKE ? OR c.name LIKE ?)';$params[]="%$search%";$params[]="%$search%";$params[]="%$search%";} $where=$cond?'WHERE '.implode(' AND ',$cond):'';
    $edit = isset($_GET['edit']) ? one("SELECT * FROM tasks WHERE id=?",[(int)$_GET['edit']]) : null;
    echo '<section class="card"><h2>'.($edit?'ویرایش کار':'ثبت کار جدید').'</h2><form method="post" class="grid-form wide"><input type="hidden" name="action" value="save_task">'.csrf_field().'<input type="hidden" name="id" value="'.h($edit['id']??'').'">';
    echo '<label>شرکت<select name="company_id">'.company_options($edit['company_id']??null,false).'</select></label><label>کد کار<input name="code" value="'.h($edit['code']??'').'"></label><label>گروه کار<input name="category" value="'.h($edit['category']??'').'"></label><label class="span2">شرح کار<input name="title" required value="'.h($edit['title']??'').'"></label><label>تناوب<input name="frequency" value="'.h($edit['frequency']??'').'"></label><label>دوره/ماه<input name="period_label" value="'.h($edit['period_label']??'').'"></label><label>سررسید شمسی<input name="due_jalali" placeholder="1405/02/05" value="'.h(isset($edit['due_date'])?Jalali::fromGregorian($edit['due_date']):'').'"></label><label>روز یادآوری<input name="reminder_days" type="number" value="'.h($edit['reminder_days']??5).'"></label><label>اولویت<select name="priority"><option>خیلی بالا</option><option '.(($edit['priority']??'')==='بالا'?'selected':'').'>بالا</option><option '.(($edit['priority']??'متوسط')==='متوسط'?'selected':'').'>متوسط</option><option>پایین</option></select></label><label>وضعیت<select name="status">';
    foreach(['باز','در حال انجام','انجام شده','معوق','بسته شده'] as $s) echo '<option '.(($edit['status']??'باز')===$s?'selected':'').'>'.$s.'</option>'; echo '</select></label><label>مسئول<input name="assigned_to" value="'.h($edit['assigned_to']??'').'"></label><label class="span3">توضیحات<textarea name="description">'.h($edit['description']??'').'</textarea></label><button class="btn primary">ذخیره کار</button>'.($edit?'<a class="btn" href="index.php?page=tasks">لغو ویرایش</a>':'').'</form></section>';
    echo '<section class="card"><form class="filters"><input type="hidden" name="page" value="tasks"><label>شرکت<select name="company_id">'.company_options($company,true).'</select></label><label>وضعیت<select name="status"><option value="">همه</option>'; foreach(['باز','در حال انجام','انجام شده','معوق','بسته شده'] as $s) echo '<option '.($status===$s?'selected':'').'>'.$s.'</option>'; echo '</select></label><label>جستجو<input name="q" value="'.h($search).'" placeholder="شرح کار یا شرکت"></label><button class="btn">فیلتر</button></form><div class="table-wrap"><table><thead><tr><th>کد</th><th>شرکت</th><th>شرح کار</th><th>سررسید</th><th>موعد</th><th>یادآوری</th><th>اولویت</th><th>وضعیت</th><th>عملیات</th></tr></thead><tbody>';
    $rows=q("SELECT t.*,c.name company_name FROM tasks t LEFT JOIN companies c ON c.id=t.company_id $where ORDER BY CASE WHEN t.due_date IS NULL THEN 1 ELSE 0 END,t.due_date ASC,t.id DESC LIMIT 300",$params);
    foreach($rows as $r){ echo '<tr><td>'.h($r['code']).'</td><td>'.h($r['company_name']).'</td><td>'.h($r['title']).'</td><td>'.h(Jalali::fromGregorian($r['due_date'])).'</td><td><span class="due '.h(due_status($r['due_date'],$r['status'])).'">'.h(due_status($r['due_date'],$r['status'])).'</span></td><td>'.h($r['reminder_days']).' روز قبل</td><td>'.h($r['priority']).'</td><td>'.status_badge($r['status']).'</td><td class="actions"><a class="btn tiny" href="index.php?page=tasks&edit='.(int)$r['id'].'">ویرایش</a><form method="post">'.csrf_field().'<input type="hidden" name="action" value="done_task"><input type="hidden" name="id" value="'.(int)$r['id'].'"><button class="btn tiny">انجام</button></form></td></tr>'; }
    if(!$rows) echo '<tr><td colspan="9">موردی پیدا نشد.</td></tr>';
    echo '</tbody></table></div></section>'; render_footer();
}

function render_companies(): void {
    render_header('بانک اطلاعات شرکت‌ها'); $edit=isset($_GET['edit'])?one("SELECT * FROM companies WHERE id=?",[(int)$_GET['edit']]):null;
    echo '<section class="card"><h2>'.($edit?'ویرایش شرکت':'ثبت شرکت').'</h2><form method="post" class="grid-form wide"><input type="hidden" name="action" value="save_company">'.csrf_field().'<input type="hidden" name="id" value="'.h($edit['id']??'').'"><label>نام شرکت<input name="name" required value="'.h($edit['name']??'').'"></label><label>نوع مجموعه<input name="type" value="'.h($edit['type']??'').'"></label><label>روز/ساعت حضور<input name="attendance" value="'.h($edit['attendance']??'').'"></label><label>نرم‌افزار<input name="software" value="'.h($edit['software']??'').'"></label><label>مدیرعامل<input name="manager_name" value="'.h($edit['manager_name']??'').'"></label><label>مدیر مالی<input name="financial_manager" value="'.h($edit['financial_manager']??'').'"></label><label>تلفن<input name="phone" value="'.h($edit['phone']??'').'"></label><label>نام کاربری مالیات<input name="tax_username" value="'.h($edit['tax_username']??'').'"></label><label>کد بیمه/کارگاه<input name="insurance_code" value="'.h($edit['insurance_code']??'').'"></label><label class="span2">آدرس<textarea name="address">'.h($edit['address']??'').'</textarea></label><label class="span3">توضیحات<textarea name="notes">'.h($edit['notes']??'').'</textarea></label><button class="btn primary">ذخیره شرکت</button></form></section>';
    echo '<section class="card"><div class="table-wrap"><table><thead><tr><th>نام</th><th>نوع</th><th>حضور</th><th>نرم‌افزار</th><th>مدیر</th><th>تماس</th><th>کد بیمه</th><th></th></tr></thead><tbody>'; foreach(companies() as $c) echo '<tr><td>'.h($c['name']).'</td><td>'.h($c['type']).'</td><td>'.h($c['attendance']).'</td><td>'.h($c['software']).'</td><td>'.h($c['manager_name']).'</td><td>'.h($c['phone']).'</td><td>'.h($c['insurance_code']).'</td><td><a class="btn tiny" href="index.php?page=companies&edit='.(int)$c['id'].'">ویرایش</a></td></tr>'; echo '</tbody></table></div></section>'; render_footer();
}

function render_calendar(): void {
    render_header('تقویم سررسیدها'); $rows=q("SELECT t.*,c.name company_name FROM tasks t LEFT JOIN companies c ON c.id=t.company_id WHERE t.due_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 90 DAY) ORDER BY t.due_date ASC,c.name LIMIT 500");
    echo '<section class="card"><h2>سررسیدهای ۹۰ روز آینده</h2><div class="timeline">'; $last=''; foreach($rows as $r){ $d=$r['due_date']; if($d!==$last){ if($last) echo '</div>'; echo '<div class="day"><h3>'.h(Jalali::fromGregorian($d)).' <small>'.h($d).'</small></h3>'; $last=$d; } echo '<div class="tl-item"><b>'.h($r['company_name']).'</b><span>'.h($r['title']).'</span>'.status_badge($r['status']).'</div>'; } if($last) echo '</div>'; if(!$rows) echo '<p>سررسیدی در ۹۰ روز آینده ثبت نشده است.</p>'; echo '</div></section>'; render_footer();
}

function render_followups(): void {
    render_header('پیگیری‌ها'); $edit=isset($_GET['edit'])?one("SELECT * FROM followups WHERE id=?",[(int)$_GET['edit']]):null;
    echo '<section class="card"><h2>ثبت/ویرایش پیگیری</h2><form method="post" class="grid-form wide"><input type="hidden" name="action" value="save_followup">'.csrf_field().'<input type="hidden" name="id" value="'.h($edit['id']??'').'"><label>شرکت<select name="company_id">'.company_options($edit['company_id']??null).'</select></label><label>درخواست‌دهنده<input name="requester" value="'.h($edit['requester']??'').'"></label><label class="span2">موضوع/درخواست<input name="subject" required value="'.h($edit['subject']??'').'"></label><label class="span2">اقدام بعدی<input name="next_action" value="'.h($edit['next_action']??'').'"></label><label>تاریخ پیگیری شمسی<input name="followup_jalali" placeholder="1405/03/10" value="'.h(isset($edit['followup_date'])?Jalali::fromGregorian($edit['followup_date']):'').'"></label><label>اولویت<select name="priority"><option>خیلی بالا</option><option>بالا</option><option selected>متوسط</option><option>پایین</option></select></label><label>وضعیت<select name="status"><option>باز</option><option>در حال پیگیری</option><option>انجام شده</option><option>بسته شده</option></select></label><label class="span3">توضیحات<textarea name="notes">'.h($edit['notes']??'').'</textarea></label><button class="btn primary">ذخیره پیگیری</button></form></section>';
    $rows=q("SELECT f.*,c.name company_name FROM followups f LEFT JOIN companies c ON c.id=f.company_id ORDER BY CASE WHEN f.followup_date IS NULL THEN 1 ELSE 0 END,f.followup_date ASC LIMIT 300");
    echo '<section class="card"><div class="table-wrap"><table><thead><tr><th>شرکت</th><th>موضوع</th><th>اقدام بعدی</th><th>تاریخ پیگیری</th><th>اولویت</th><th>وضعیت</th><th></th></tr></thead><tbody>'; foreach($rows as $r) echo '<tr><td>'.h($r['company_name']).'</td><td>'.h($r['subject']).'</td><td>'.h($r['next_action']).'</td><td>'.h(Jalali::fromGregorian($r['followup_date'])).'</td><td>'.h($r['priority']).'</td><td>'.status_badge($r['status']).'</td><td><a class="btn tiny" href="index.php?page=followups&edit='.(int)$r['id'].'">ویرایش</a></td></tr>'; echo '</tbody></table></div></section>'; render_footer();
}

function render_bank(): void {
    render_header('مغایرت‌گیری بانکی'); $edit=isset($_GET['edit'])?one("SELECT * FROM bank_reconciliations WHERE id=?",[(int)$_GET['edit']]):null;
    echo '<section class="card"><h2>ثبت مغایرت</h2><form method="post" class="grid-form wide"><input type="hidden" name="action" value="save_bank">'.csrf_field().'<input type="hidden" name="id" value="'.h($edit['id']??'').'"><label>شرکت<select name="company_id">'.company_options($edit['company_id']??null).'</select></label><label>بانک/حساب<input name="bank_name" value="'.h($edit['bank_name']??'').'"></label><label>دوره/ماه<input name="period_label" value="'.h($edit['period_label']??'').'"></label><label>تاریخ کشف شمسی<input name="discovered_jalali" value="'.h(isset($edit['discovered_at'])?Jalali::fromGregorian($edit['discovered_at']):'').'"></label><label>مبلغ<input name="amount" type="number" step="0.01" value="'.h($edit['amount']??'').'"></label><label>نوع مغایرت<input name="mismatch_type" value="'.h($edit['mismatch_type']??'').'"></label><label class="span2">شرح مغایرت<textarea name="description">'.h($edit['description']??'').'</textarea></label><label class="span2">اقدام اصلاحی<textarea name="correction_action">'.h($edit['correction_action']??'').'</textarea></label><label>وضعیت<input name="status" value="'.h($edit['status']??'باز').'" ></label><label>مسئول<input name="responsible" value="'.h($edit['responsible']??'').'"></label><label>تاریخ هدف شمسی<input name="target_jalali" value="'.h(isset($edit['target_date'])?Jalali::fromGregorian($edit['target_date']):'').'"></label><label class="span3">توضیحات<textarea name="notes">'.h($edit['notes']??'').'</textarea></label><button class="btn primary">ذخیره مغایرت</button></form></section>';
    $rows=q("SELECT b.*,c.name company_name FROM bank_reconciliations b LEFT JOIN companies c ON c.id=b.company_id ORDER BY b.id DESC LIMIT 300"); echo '<section class="card"><div class="table-wrap"><table><thead><tr><th>شرکت</th><th>بانک</th><th>ماه</th><th>مبلغ</th><th>نوع</th><th>وضعیت</th><th>هدف</th><th></th></tr></thead><tbody>'; foreach($rows as $r) echo '<tr><td>'.h($r['company_name']).'</td><td>'.h($r['bank_name']).'</td><td>'.h($r['period_label']).'</td><td>'.number_format((float)$r['amount']).'</td><td>'.h($r['mismatch_type']).'</td><td>'.status_badge($r['status']).'</td><td>'.h(Jalali::fromGregorian($r['target_date'])).'</td><td><a class="btn tiny" href="index.php?page=bank&edit='.(int)$r['id'].'">ویرایش</a></td></tr>'; echo '</tbody></table></div></section>'; render_footer();
}

function render_systems(): void {
    render_header('اطلاعات سامانه‌ها'); $edit=isset($_GET['edit'])?one("SELECT * FROM systems WHERE id=?",[(int)$_GET['edit']]):null;
    echo '<section class="card"><h2>ثبت سامانه</h2><form method="post" class="grid-form wide"><input type="hidden" name="action" value="save_system">'.csrf_field().'<input type="hidden" name="id" value="'.h($edit['id']??'').'"><label>شرکت<select name="company_id">'.company_options($edit['company_id']??null).'</select></label><label>سامانه/موضوع<input name="service_name" required value="'.h($edit['service_name']??'').'"></label><label class="span2">آدرس سایت<input name="url" value="'.h($edit['url']??'').'"></label><label>نام کاربری<input name="username" value="'.h($edit['username']??'').'"></label><label>شناسه/کد مرتبط<input name="related_code" value="'.h($edit['related_code']??'').'"></label><label>آخرین کنترل شمسی<input name="last_checked_jalali" value="'.h(isset($edit['last_checked_at'])?Jalali::fromGregorian($edit['last_checked_at']):'').'"></label><label class="span2">یادداشت محرمانه/رمز<textarea name="secret_note">'.h($edit['secret_note']??'').'</textarea></label><label class="span3">توضیحات<textarea name="notes">'.h($edit['notes']??'').'</textarea></label><button class="btn primary">ذخیره سامانه</button></form></section>';
    $rows=q("SELECT s.*,c.name company_name FROM systems s LEFT JOIN companies c ON c.id=s.company_id ORDER BY c.name,s.service_name LIMIT 500"); echo '<section class="card"><div class="table-wrap"><table><thead><tr><th>شرکت</th><th>سامانه</th><th>آدرس</th><th>نام کاربری</th><th>کد</th><th>آخرین کنترل</th><th></th></tr></thead><tbody>'; foreach($rows as $r) echo '<tr><td>'.h($r['company_name']).'</td><td>'.h($r['service_name']).'</td><td><a href="'.h($r['url']).'" target="_blank">'.h($r['url']).'</a></td><td>'.h($r['username']).'</td><td>'.h($r['related_code']).'</td><td>'.h(Jalali::fromGregorian($r['last_checked_at'])).'</td><td><a class="btn tiny" href="index.php?page=systems&edit='.(int)$r['id'].'">ویرایش</a></td></tr>'; echo '</tbody></table></div><p class="muted">برای رمزهای واقعی، بهتر است از Password Manager استفاده شود؛ این بخش بیشتر برای یادداشت کنترلی و شناسه‌هاست.</p></section>'; render_footer();
}

function render_errors(): void {
    render_header('خطاها و تجربیات حسابداری'); $edit=isset($_GET['edit'])?one("SELECT * FROM error_notes WHERE id=?",[(int)$_GET['edit']]):null;
    echo '<section class="card"><h2>ثبت تجربه/خطا</h2><form method="post" class="grid-form wide"><input type="hidden" name="action" value="save_error">'.csrf_field().'<input type="hidden" name="id" value="'.h($edit['id']??'').'"><label>شرکت<select name="company_id">'.company_options($edit['company_id']??null).'</select></label><label>تاریخ شمسی<input name="happened_jalali" value="'.h(isset($edit['happened_at'])?Jalali::fromGregorian($edit['happened_at']):'').'"></label><label>فرآیند<input name="process" value="'.h($edit['process']??'').'"></label><label>شماره سند/مدرک<input name="document_no" value="'.h($edit['document_no']??'').'"></label><label class="span2">شرح خطا/ریسک<textarea name="risk">'.h($edit['risk']??'').'</textarea></label><label class="span2">علت ریشه‌ای<textarea name="root_cause">'.h($edit['root_cause']??'').'</textarea></label><label class="span2">راه‌حل/اصلاح<textarea name="solution">'.h($edit['solution']??'').'</textarea></label><label class="span2">اقدام پیشگیرانه<textarea name="prevention">'.h($edit['prevention']??'').'</textarea></label><label>وضعیت<input name="status" value="'.h($edit['status']??'باز').'"></label><button class="btn primary">ذخیره</button></form></section>';
    $rows=q("SELECT e.*,c.name company_name FROM error_notes e LEFT JOIN companies c ON c.id=e.company_id ORDER BY e.id DESC LIMIT 300"); echo '<section class="card"><div class="table-wrap"><table><thead><tr><th>شرکت</th><th>تاریخ</th><th>فرآیند</th><th>ریسک/خطا</th><th>راه‌حل</th><th>وضعیت</th><th></th></tr></thead><tbody>'; foreach($rows as $r) echo '<tr><td>'.h($r['company_name']).'</td><td>'.h(Jalali::fromGregorian($r['happened_at'])).'</td><td>'.h($r['process']).'</td><td>'.h($r['risk']).'</td><td>'.h($r['solution']).'</td><td>'.h($r['status']).'</td><td><a class="btn tiny" href="index.php?page=errors&edit='.(int)$r['id'].'">ویرایش</a></td></tr>'; echo '</tbody></table></div></section>'; render_footer();
}

function render_import(): void {
    render_header('ورود/خروجی اکسل و پشتیبان');
    echo '<section class="card grid-2"><div><h2>ایمپورت از اکسل</h2><p>فایل اکسل ساخته‌شده قبلی یا فایل‌های مشابه را آپلود کنید. شیت‌های «شرکت‌ها»، «کارهای 1405»، «برنامه حضور» و «پیگیری‌ها» خوانده می‌شوند و داخل دیتابیس ثبت می‌شوند.</p><form method="post" enctype="multipart/form-data"><input type="hidden" name="action" value="import_xlsx">'.csrf_field().'<input type="file" name="xlsx" accept=".xlsx" required><button class="btn primary">ایمپورت</button></form></div><div><h2>خروجی اکسل</h2><p>اطلاعات فعلی دیتابیس را به فایل xlsx برای آرشیو، ارسال یا کار آفلاین خروجی بگیرید.</p><a class="btn primary" href="index.php?action=export_xlsx">دانلود خروجی اکسل</a></div></section>';
    $rows=q("SELECT * FROM imports ORDER BY id DESC LIMIT 20"); echo '<section class="card"><h2>آخرین ایمپورت‌ها</h2><div class="table-wrap"><table><thead><tr><th>فایل</th><th>نتیجه</th><th>تاریخ</th></tr></thead><tbody>'; foreach($rows as $r) echo '<tr><td>'.h($r['filename']).'</td><td><code>'.h($r['stats']).'</code></td><td>'.h($r['created_at']).'</td></tr>'; if(!$rows) echo '<tr><td colspan="3">هنوز ایمپورتی انجام نشده است.</td></tr>'; echo '</tbody></table></div></section>'; render_footer();
}

function render_notifications(): void {
    render_header('اعلان‌ها و لاگ ارسال');
    echo '<section class="card"><h2>ارسال یادآوری دستی</h2><p>این دکمه همان کاری را انجام می‌دهد که Cron Job هاست باید روزانه انجام دهد: کارهای نزدیک به سررسید را بررسی می‌کند و ایمیل/پیامک می‌فرستد.</p><form method="post"><input type="hidden" name="action" value="send_reminders_now">'.csrf_field().'<button class="btn primary">بررسی و ارسال الآن</button></form></section>';
    $rows=q("SELECT l.*,t.title,c.name company_name FROM notification_logs l LEFT JOIN tasks t ON t.id=l.task_id LEFT JOIN companies c ON c.id=t.company_id ORDER BY l.id DESC LIMIT 100");
    echo '<section class="card"><h2>لاگ اعلان‌ها</h2><div class="table-wrap"><table><thead><tr><th>تاریخ</th><th>کانال</th><th>گیرنده</th><th>شرکت</th><th>کار</th><th>وضعیت</th><th>پاسخ</th></tr></thead><tbody>'; foreach($rows as $r) echo '<tr><td>'.h($r['created_at']).'</td><td>'.h($r['channel']).'</td><td>'.h($r['recipient']).'</td><td>'.h($r['company_name']).'</td><td>'.h($r['title']).'</td><td>'.h($r['status']).'</td><td><small>'.h(mb_substr($r['response']??'',0,180)).'</small></td></tr>'; if(!$rows) echo '<tr><td colspan="7">هنوز اعلانی ارسال نشده است.</td></tr>'; echo '</tbody></table></div></section>'; render_footer();
}

function render_settings(): void {
    render_header('تنظیمات وب‌اپ، ایمیل، پیامک و گوگل');
    $cron = base_url('cron.php?secret='.setting('cron_secret',''));
    echo '<section class="card"><h2>تنظیمات سرویس‌ها</h2><form method="post" class="grid-form wide"><input type="hidden" name="action" value="save_settings">'.csrf_field();
    echo '<h3 class="span3">ایمیل SMTP</h3><label>SMTP Host<input name="smtp_host" value="'.h(setting('smtp_host','smtp.gmail.com')).'"></label><label>Port<input name="smtp_port" value="'.h(setting('smtp_port','587')).'"></label><label>Encryption<select name="smtp_encryption"><option value="tls" '.(setting('smtp_encryption','tls')==='tls'?'selected':'').'>TLS / STARTTLS</option><option value="ssl" '.(setting('smtp_encryption')==='ssl'?'selected':'').'>SSL</option></select></label><label>SMTP Username<input name="smtp_username" value="'.h(setting('smtp_username','')).'"></label><label>SMTP Password / App Password<input name="smtp_password" type="password" placeholder="برای تغییر، مقدار جدید وارد کنید"></label><label>From Email<input name="mail_from_email" value="'.h(setting('mail_from_email','')).'"></label><label>From Name<input name="mail_from_name" value="'.h(setting('mail_from_name','Accounting Manager 1405')).'"></label><label class="span2">گیرنده‌های ایمیل اعلان<input name="notifications_email_to" value="'.h(setting('notifications_email_to','')).'" placeholder="email@example.com"></label>';
    echo '<h3 class="span3">پیامک قاصدک</h3><label>Ghasedak API Key<input name="ghasedak_api_key" type="password" placeholder="برای تغییر، مقدار جدید وارد کنید"></label><label>Line Number<input name="ghasedak_line_number" value="'.h(setting('ghasedak_line_number','')).'"></label><label class="span2">شماره گیرنده‌های SMS<input name="notifications_sms_to" value="'.h(setting('notifications_sms_to','')).'" placeholder="0912..., 0935..."></label>';
    echo '<h3 class="span3">ورود با گوگل</h3><label class="span2">Google Client ID<input name="google_client_id" value="'.h(setting('google_client_id','')).'"></label><label>Google Client Secret<input name="google_client_secret" type="password" placeholder="برای تغییر، مقدار جدید وارد کنید"></label><label class="span2">Redirect URI<input name="google_redirect_uri" value="'.h(setting('google_redirect_uri',base_url('index.php?page=google_callback'))).'" readonly></label><label>ثبت‌نام با گوگل<select name="allow_google_signup"><option value="1" '.(setting('allow_google_signup','1')==='1'?'selected':'').'>فعال</option><option value="0" '.(setting('allow_google_signup')==='0'?'selected':'').'>غیرفعال</option></select></label>';
    echo '<h3 class="span3">Cron Job</h3><label class="span2">Cron Secret<input name="cron_secret" value="'.h(setting('cron_secret','')).'"></label><div class="span3 helpbox"><b>دستور پیشنهادی Cron در cPanel:</b><code>curl -s "'.h($cron).'" >/dev/null 2>&1</code><small>پیشنهاد: روزی یک‌بار صبح، مثلاً ساعت ۸.</small></div><button class="btn primary">ذخیره تنظیمات</button></form></section>';
    echo '<section class="card grid-2"><div><h2>تست ایمیل</h2><form method="post"><input type="hidden" name="action" value="test_email">'.csrf_field().'<input name="test_email_to" placeholder="گیرنده تست" value="'.h(setting('notifications_email_to','')).'"><button class="btn">ارسال تست ایمیل</button></form></div><div><h2>تست پیامک</h2><form method="post"><input type="hidden" name="action" value="test_sms">'.csrf_field().'<input name="test_sms_to" placeholder="شماره تست" value="'.h(setting('notifications_sms_to','')).'"><button class="btn">ارسال تست پیامک</button></form></div></section>';
    render_footer();
}

function render_users(): void {
    if(!Auth::isAdmin()){ flash('دسترسی ندارید.','danger'); redirect('index.php'); }
    render_header('مدیریت کاربران'); $edit=isset($_GET['edit'])?one("SELECT * FROM users WHERE id=?",[(int)$_GET['edit']]):null;
    echo '<section class="card"><h2>ثبت/ویرایش کاربر</h2><form method="post" class="grid-form wide"><input type="hidden" name="action" value="save_user">'.csrf_field().'<input type="hidden" name="id" value="'.h($edit['id']??'').'"><label>نام<input name="name" required value="'.h($edit['name']??'').'"></label><label>ایمیل<input name="email" type="email" required value="'.h($edit['email']??'').'"></label><label>نقش<select name="role">'; foreach(['admin'=>'مدیر','accountant'=>'حسابدار','viewer'=>'مشاهده‌گر'] as $v=>$t) echo '<option value="'.$v.'" '.(($edit['role']??'accountant')===$v?'selected':'').'>'.$t.'</option>'; echo '</select></label><label>وضعیت<select name="status"><option value="active">فعال</option><option value="disabled" '.(($edit['status']??'')==='disabled'?'selected':'').'>غیرفعال</option></select></label><label>رمز عبور<input name="password" type="password" placeholder="در ویرایش، خالی بماند تغییر نمی‌کند"></label><button class="btn primary">ذخیره کاربر</button></form></section>';
    $rows=q("SELECT * FROM users ORDER BY id"); echo '<section class="card"><div class="table-wrap"><table><thead><tr><th>نام</th><th>ایمیل</th><th>نقش</th><th>ورود گوگل</th><th>وضعیت</th><th></th></tr></thead><tbody>'; foreach($rows as $r) echo '<tr><td>'.h($r['name']).'</td><td>'.h($r['email']).'</td><td>'.h($r['role']).'</td><td>'.($r['google_id']?'وصل':'-').'</td><td>'.h($r['status']).'</td><td><a class="btn tiny" href="index.php?page=users&edit='.(int)$r['id'].'">ویرایش</a></td></tr>'; echo '</tbody></table></div></section>'; render_footer();
}
