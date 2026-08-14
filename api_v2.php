<?php
require __DIR__.'/app/bootstrap.php';
require_once __DIR__.'/app/Core/Tenant.php';
require_once __DIR__.'/app/Core/Audit.php';
require_once __DIR__.'/app/Core/FileLibrary.php';
require_once __DIR__.'/app/Modules/V4Module.php';
require_once __DIR__.'/app/Core/DataIO.php';

Tenant::ensureSchema();Audit::ensureSchema();FileLibrary::ensureSchema();V4Module::ensureSchema();

function av2($data,int $status=200,array $meta=[]): never{
    http_response_code($status);header('Content-Type: application/json; charset=utf-8');header('X-API-Version: 2');
    $out=['ok'=>$status<400,'data'=>$data];if($meta)$out['meta']=$meta;echo json_encode($out,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;
}
function av2err(string $code,string $message,int $status=400,array $details=[]): never{
    http_response_code($status);header('Content-Type: application/json; charset=utf-8');header('X-API-Version: 2');echo json_encode(['ok'=>false,'error'=>['code'=>$code,'message'=>$message,'details'=>$details]],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;
}
function api2_schema(): void{
    pdo()->exec("CREATE TABLE IF NOT EXISTS workspace_api_tokens (
        id INT AUTO_INCREMENT PRIMARY KEY,workspace_id INT NOT NULL,user_id INT NULL,name VARCHAR(120) NOT NULL,token_hash CHAR(64) NOT NULL UNIQUE,token_prefix VARCHAR(32) NOT NULL,
        scopes VARCHAR(500) NOT NULL,expires_at DATETIME NULL,last_used_at DATETIME NULL,last_ip VARCHAR(80) NULL,revoked_at DATETIME NULL,created_at DATETIME NULL,
        INDEX idx_wapi_workspace(workspace_id,revoked_at),INDEX idx_wapi_user(user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    pdo()->exec("CREATE TABLE IF NOT EXISTS workspace_api_rate (
        token_id INT NOT NULL,bucket_minute DATETIME NOT NULL,request_count INT NOT NULL DEFAULT 0,PRIMARY KEY(token_id,bucket_minute),INDEX idx_wapi_rate(bucket_minute)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}
function api2_auth(): array{
    api2_schema();$h=$_SERVER['HTTP_AUTHORIZATION']??$_SERVER['REDIRECT_HTTP_AUTHORIZATION']??'';
    if(!preg_match('/^Bearer\s+(.+)$/i',trim($h),$m))av2err('unauthorized','Bearer token required',401);
    $plain=trim($m[1]);$st=pdo()->prepare("SELECT t.*,u.status user_status FROM workspace_api_tokens t LEFT JOIN users u ON u.id=t.user_id WHERE t.token_hash=? LIMIT 1");$st->execute([hash('sha256',$plain)]);$t=$st->fetch();
    if(!$t||$t['revoked_at']||($t['expires_at']&&strtotime($t['expires_at'])<time())||($t['user_id']&&$t['user_status']!=='active'))av2err('unauthorized','Invalid, expired or revoked token',401);
    $limit=max(10,min(5000,(int)setting('api_rate_limit_per_minute','120')));$bucket=date('Y-m-d H:i:00');
    pdo()->prepare("INSERT INTO workspace_api_rate(token_id,bucket_minute,request_count) VALUES (?,?,1) ON DUPLICATE KEY UPDATE request_count=request_count+1")->execute([(int)$t['id'],$bucket]);
    $st=pdo()->prepare("SELECT request_count FROM workspace_api_rate WHERE token_id=? AND bucket_minute=?");$st->execute([(int)$t['id'],$bucket]);$count=(int)$st->fetchColumn();header('X-RateLimit-Limit: '.$limit);header('X-RateLimit-Remaining: '.max(0,$limit-$count));if($count>$limit)av2err('rate_limited','Rate limit exceeded',429);
    pdo()->prepare("UPDATE workspace_api_tokens SET last_used_at=NOW(),last_ip=? WHERE id=?")->execute([$_SERVER['REMOTE_ADDR']??'',(int)$t['id']]);
    $_SESSION['workspace_id']=(int)$t['workspace_id'];if($t['user_id'])$_SESSION['user_id']=(int)$t['user_id'];Tenant::boot();
    if(Tenant::id()!==(int)$t['workspace_id'])av2err('workspace','Token workspace is not available for this user',403);
    $t['scopes']=json_decode((string)$t['scopes'],true)?:[];return$t;
}
function api2_scope(array $t,string $scope):void{if(!in_array($scope,$t['scopes'],true))av2err('insufficient_scope','Missing scope: '.$scope,403);}
function api2_body():array{$raw=file_get_contents('php://input');if(trim($raw)==='')return[];$d=json_decode($raw,true);if(!is_array($d))av2err('invalid_json','JSON body is invalid',400);return$d;}
function api2_date($v):?string{$v=trim((string)$v);if($v==='')return null;if(preg_match('/^\d{4}-\d{2}-\d{2}$/',$v))return$v;return Jalali::parse($v)?:null;}
function api2_one(string $sql,array $p=[]):?array{$st=pdo()->prepare($sql);$st->execute($p);$r=$st->fetch();return$r?:null;}
function api2_page():array{$page=max(1,(int)($_GET['page']??1));$per=max(1,min(200,(int)($_GET['per_page']??50)));return[$page,$per,($page-1)*$per];}
function api2_patch(string $table,int $id,int $wid,array $body,array $allowed):array{
    $sets=[];$vals=[];foreach($allowed as $f){if(!array_key_exists($f,$body))continue;$sets[]="`$f`=?";$vals[]=$body[$f];}if($sets){$sets[]='updated_at=NOW()';$vals[]=$id;$vals[]=$wid;pdo()->prepare("UPDATE `$table` SET ".implode(',',$sets)." WHERE id=? AND workspace_id=?")->execute($vals);}return api2_one("SELECT * FROM `$table` WHERE id=? AND workspace_id=?",[$id,$wid])??[];
}
function api2_log(string $action,string $entity,int $id=0,array $meta=[]):void{Audit::log('api.'.$action,$entity,$id,'API V2',null,null,$meta);}
function api2_member_permission(string $p):void{try{Tenant::requirePermission($p);}catch(Throwable $e){av2err('permission',$e->getMessage(),403);}}

// CORS is intentionally opt-in.
$origin=$_SERVER['HTTP_ORIGIN']??'';$allowed=array_filter(array_map('trim',preg_split('/[\r\n,]+/',(string)setting('api_cors_origins',''))));
if($origin&&$allowed&&(in_array('*',$allowed,true)||in_array($origin,$allowed,true))){header('Access-Control-Allow-Origin: '.(in_array('*',$allowed,true)?'*':$origin));header('Vary: Origin');header('Access-Control-Allow-Headers: Authorization, Content-Type, Accept');header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');}
if(($_SERVER['REQUEST_METHOD']??'GET')==='OPTIONS'){http_response_code(204);exit;}

$t=api2_auth();$method=strtoupper($_SERVER['REQUEST_METHOD']??'GET');$res=str_replace('-','_',trim((string)($_GET['resource']??'meta')));$id=max(0,(int)($_GET['id']??0));$wid=Tenant::id();
try{
    if($method==='GET')api2_scope($t,'read');else api2_scope($t,'write');

    if($res==='meta'){
        if($method!=='GET')av2err('method','Method not allowed',405);
        av2(['name'=>'Accounting CRM SaaS API','version'=>'2.0.0','workspace'=>Tenant::current(),'resources'=>['companies','daily-plans','monthly-plans','calendar','kanban','systems','portals','custom-fields','notes','files','attachments','members','roles','workspace','audit-logs','table-preferences','imports','exports']]);
    }

    if($res==='companies'){
        if($method==='GET'){
            if($id){$r=api2_one("SELECT * FROM companies WHERE id=? AND workspace_id=? AND active=1",[$id,$wid]);if(!$r)av2err('not_found','Company not found',404);av2($r);}
            [$page,$per,$off]=api2_page();$w=['workspace_id=?','active=1'];$p=[$wid];if($q=trim((string)($_GET['q']??''))){$w[]='(name LIKE ? OR national_id LIKE ? OR economic_code LIKE ?)';$l="%$q%";array_push($p,$l,$l,$l);}if($v=trim((string)($_GET['company_type']??''))){$w[]='company_type=?';$p[]=$v;}
            $st=pdo()->prepare("SELECT * FROM companies WHERE ".implode(' AND ',$w)." ORDER BY name LIMIT $per OFFSET $off");$st->execute($p);av2($st->fetchAll(),200,['page'=>$page,'per_page'=>$per]);
        }
        if($method==='POST'){api2_member_permission('companies.create');$b=api2_body();$name=trim((string)($b['name']??''));if(!$name)throw new RuntimeException('name is required');pdo()->prepare("INSERT INTO companies (workspace_id,name,company_type,legal_personality,national_id,economic_code,registration_number,address,postal_code,phone,ceo_name,ceo_national_id,ceo_mobile,software,extra_json,active,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,1,NOW(),NOW())")->execute([$wid,$name,$b['company_type']??'',$b['legal_personality']??'',$b['national_id']??'',$b['economic_code']??'',$b['registration_number']??'',$b['address']??'',$b['postal_code']??'',$b['phone']??'',$b['ceo_name']??'',$b['ceo_national_id']??'',$b['ceo_mobile']??'',$b['software']??'',json_encode((array)($b['extra']??[]),JSON_UNESCAPED_UNICODE)]);$nid=(int)pdo()->lastInsertId();api2_log('create','companies',$nid);av2(api2_one("SELECT * FROM companies WHERE id=? AND workspace_id=?",[$nid,$wid]),201);}
        if(in_array($method,['PATCH','PUT'],true)){api2_member_permission('companies.update');if(!$id)throw new RuntimeException('id required');$b=api2_body();$r=api2_patch('companies',$id,$wid,$b,['name','company_type','legal_personality','national_id','economic_code','registration_number','address','postal_code','phone','ceo_name','ceo_national_id','ceo_mobile','software']);if(array_key_exists('extra',$b)){pdo()->prepare("UPDATE companies SET extra_json=?,updated_at=NOW() WHERE id=? AND workspace_id=?")->execute([json_encode((array)$b['extra'],JSON_UNESCAPED_UNICODE),$id,$wid]);$r=api2_one("SELECT * FROM companies WHERE id=? AND workspace_id=?",[$id,$wid]);}api2_log('update','companies',$id);av2($r);}
        if($method==='DELETE'){api2_member_permission('companies.delete');pdo()->prepare("UPDATE companies SET active=0,updated_at=NOW() WHERE id=? AND workspace_id=?")->execute([$id,$wid]);api2_log('delete','companies',$id);av2(['id'=>$id,'deleted'=>true]);}
    }

    if($res==='daily_plans'){
        if($method==='GET'){if($id){$r=api2_one("SELECT d.*,c.name company_name FROM daily_plans d LEFT JOIN companies c ON c.id=d.company_id WHERE d.id=? AND d.workspace_id=?",[$id,$wid]);if(!$r)av2err('not_found','Daily plan not found',404);av2($r);}[$page,$per,$off]=api2_page();$w=['d.workspace_id=?'];$p=[$wid];if($v=(int)($_GET['company_id']??0)){$w[]='d.company_id=?';$p[]=$v;}if($v=api2_date($_GET['from']??'')){$w[]='d.plan_date>=?';$p[]=$v;}if($v=api2_date($_GET['to']??'')){$w[]='d.plan_date<=?';$p[]=$v;}$st=pdo()->prepare("SELECT d.*,c.name company_name FROM daily_plans d LEFT JOIN companies c ON c.id=d.company_id WHERE ".implode(' AND ',$w)." ORDER BY d.plan_date DESC,d.id DESC LIMIT $per OFFSET $off");$st->execute($p);av2($st->fetchAll(),200,['page'=>$page,'per_page'=>$per]);}
        if($method==='POST'){api2_member_permission('daily.create');$b=api2_body();$date=api2_date($b['plan_date']??'');$desc=trim((string)($b['work_description']??''));if(!$date||!$desc)throw new RuntimeException('plan_date and work_description are required');pdo()->prepare("INSERT INTO daily_plans (workspace_id,plan_date,day_name,company_id,work_description,status,notes,extra_json,created_by,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,NOW(),NOW())")->execute([$wid,$date,'',$b['company_id']??null,$desc,$b['status']??'باز',$b['notes']??'',json_encode((array)($b['extra']??[]),JSON_UNESCAPED_UNICODE),$t['user_id']?:null]);$nid=(int)pdo()->lastInsertId();api2_log('create','daily_plans',$nid);av2(api2_one("SELECT * FROM daily_plans WHERE id=? AND workspace_id=?",[$nid,$wid]),201);}
        if(in_array($method,['PATCH','PUT'],true)){api2_member_permission('daily.update');$b=api2_body();if(isset($b['plan_date']))$b['plan_date']=api2_date($b['plan_date']);$r=api2_patch('daily_plans',$id,$wid,$b,['plan_date','company_id','work_description','status','notes']);if(array_key_exists('extra',$b))pdo()->prepare("UPDATE daily_plans SET extra_json=?,updated_at=NOW() WHERE id=? AND workspace_id=?")->execute([json_encode((array)$b['extra'],JSON_UNESCAPED_UNICODE),$id,$wid]);api2_log('update','daily_plans',$id);av2(api2_one("SELECT * FROM daily_plans WHERE id=? AND workspace_id=?",[$id,$wid]));}
        if($method==='DELETE'){api2_member_permission('daily.delete');pdo()->prepare("DELETE FROM daily_plans WHERE id=? AND workspace_id=?")->execute([$id,$wid]);api2_log('delete','daily_plans',$id);av2(['id'=>$id,'deleted'=>true]);}
    }

    if($res==='monthly_plans'){
        if($method==='GET'){if($id){$r=api2_one("SELECT m.*,c.name company_name FROM monthly_plans m LEFT JOIN companies c ON c.id=m.company_id WHERE m.id=? AND m.workspace_id=?",[$id,$wid]);if(!$r)av2err('not_found','Monthly plan not found',404);av2($r);}[$page,$per,$off]=api2_page();$w=['m.workspace_id=?'];$p=[$wid];foreach(['month_name','season','work_type','status'] as $f)if(($v=trim((string)($_GET[$f]??'')))!==''){$w[]="m.$f=?";$p[]=$v;}if($v=(int)($_GET['company_id']??0)){$w[]='m.company_id=?';$p[]=$v;}$st=pdo()->prepare("SELECT m.*,c.name company_name FROM monthly_plans m LEFT JOIN companies c ON c.id=m.company_id WHERE ".implode(' AND ',$w)." ORDER BY m.legal_deadline IS NULL,m.legal_deadline,m.id DESC LIMIT $per OFFSET $off");$st->execute($p);av2($st->fetchAll(),200,['page'=>$page,'per_page'=>$per]);}
        if($method==='POST'){api2_member_permission('monthly.create');$b=api2_body();$work=trim((string)($b['work_type']??''));if(!$work)throw new RuntimeException('work_type is required');pdo()->prepare("INSERT INTO monthly_plans (workspace_id,company_id,jalali_year,month_name,season,work_type,legal_deadline,status,work_day,completed_date,notes,extra_json,created_by,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW())")->execute([$wid,$b['company_id']??null,(int)($b['jalali_year']??1405),$b['month_name']??'',$b['season']??'',$work,api2_date($b['legal_deadline']??''),$b['status']??'باز',$b['work_day']??'',api2_date($b['completed_date']??''),$b['notes']??'',json_encode((array)($b['extra']??[]),JSON_UNESCAPED_UNICODE),$t['user_id']?:null]);$nid=(int)pdo()->lastInsertId();api2_log('create','monthly_plans',$nid);av2(api2_one("SELECT * FROM monthly_plans WHERE id=? AND workspace_id=?",[$nid,$wid]),201);}
        if(in_array($method,['PATCH','PUT'],true)){api2_member_permission('monthly.update');$b=api2_body();if(isset($b['legal_deadline']))$b['legal_deadline']=api2_date($b['legal_deadline']);if(isset($b['completed_date']))$b['completed_date']=api2_date($b['completed_date']);$r=api2_patch('monthly_plans',$id,$wid,$b,['company_id','jalali_year','month_name','season','work_type','legal_deadline','status','work_day','completed_date','notes']);if(array_key_exists('extra',$b))pdo()->prepare("UPDATE monthly_plans SET extra_json=?,updated_at=NOW() WHERE id=? AND workspace_id=?")->execute([json_encode((array)$b['extra'],JSON_UNESCAPED_UNICODE),$id,$wid]);api2_log('update','monthly_plans',$id);av2(api2_one("SELECT * FROM monthly_plans WHERE id=? AND workspace_id=?",[$id,$wid]));}
        if($method==='DELETE'){api2_member_permission('monthly.delete');pdo()->prepare("DELETE FROM monthly_plans WHERE id=? AND workspace_id=?")->execute([$id,$wid]);api2_log('delete','monthly_plans',$id);av2(['id'=>$id,'deleted'=>true]);}
    }

    if($res==='calendar'){
        if($method!=='GET')av2err('method','Method not allowed',405);$from=api2_date($_GET['from']??'')?:date('Y-m-01');$to=api2_date($_GET['to']??'')?:date('Y-m-t');$st=pdo()->prepare("SELECT id,'daily' source,plan_date event_date,company_id,work_description title,status,notes FROM daily_plans WHERE workspace_id=? AND plan_date BETWEEN ? AND ? UNION ALL SELECT id,'monthly',legal_deadline,company_id,work_type,status,notes FROM monthly_plans WHERE workspace_id=? AND legal_deadline BETWEEN ? AND ? ORDER BY event_date");$st->execute([$wid,$from,$to,$wid,$from,$to]);av2($st->fetchAll(),200,['from'=>$from,'to'=>$to]);
    }

    if($res==='kanban'){
        if($method==='GET'){api2_member_permission('kanban.view');$st=pdo()->prepare("SELECT m.*,c.name company_name FROM monthly_plans m LEFT JOIN companies c ON c.id=m.company_id WHERE m.workspace_id=? ORDER BY m.status,m.legal_deadline");$st->execute([$wid]);$out=[];foreach($st->fetchAll() as $r)$out[$r['status']][]=$r;av2($out);}
        if(in_array($method,['PATCH','PUT'],true)){api2_member_permission('kanban.update');$b=api2_body();$status=trim((string)($b['status']??''));if(!$status)throw new RuntimeException('status required');pdo()->prepare("UPDATE monthly_plans SET status=?,updated_at=NOW() WHERE id=? AND workspace_id=?")->execute([$status,$id,$wid]);api2_log('kanban','monthly_plans',$id);av2(['id'=>$id,'status'=>$status]);}
    }

    if($res==='systems'){
        if($method==='GET'){api2_member_permission('systems.view');$with=(string)($_GET['include_secrets']??'0')==='1';if($with){api2_scope($t,'secrets');api2_member_permission('systems.secrets');}$w=['pc.workspace_id=?'];$p=[$wid];if($id){$w[]='pc.id=?';$p[]=$id;}if($v=(int)($_GET['company_id']??0)){$w[]='pc.company_id=?';$p[]=$v;}$st=pdo()->prepare("SELECT pc.*,c.name company_name,p.title portal_title,p.url portal_url FROM portal_credentials pc LEFT JOIN companies c ON c.id=pc.company_id LEFT JOIN portal_definitions p ON p.portal_key=pc.portal_key WHERE ".implode(' AND ',$w)." ORDER BY c.name,p.sort_order");$st->execute($p);$rows=$st->fetchAll();foreach($rows as &$r){if($with)$r['password']=decrypt_value((string)$r['password_enc']);unset($r['password_enc']);}av2($id?($rows[0]??null):$rows);}
        if($method==='POST'){api2_member_permission('systems.update');$b=api2_body();$cid=(int)($b['company_id']??0);$key=trim((string)($b['portal_key']??''));if(!$cid||!$key)throw new RuntimeException('company_id and portal_key required');$pw=(string)($b['password']??'');$enc=$pw!==''?encrypt_value($pw):'';pdo()->prepare("INSERT INTO portal_credentials (workspace_id,company_id,portal_key,username,password_enc,notes,created_at,updated_at) VALUES (?,?,?,?,?,?,NOW(),NOW()) ON DUPLICATE KEY UPDATE workspace_id=VALUES(workspace_id),username=VALUES(username),password_enc=IF(VALUES(password_enc)='',password_enc,VALUES(password_enc)),notes=VALUES(notes),updated_at=NOW()")->execute([$wid,$cid,$key,$b['username']??'',$enc,$b['notes']??'']);$r=api2_one("SELECT id FROM portal_credentials WHERE workspace_id=? AND company_id=? AND portal_key=?",[$wid,$cid,$key]);api2_log('save','portal_credentials',(int)($r['id']??0));av2($r,201);}
        if(in_array($method,['PATCH','PUT'],true)){api2_member_permission('systems.update');$b=api2_body();$r=api2_patch('portal_credentials',$id,$wid,$b,['username','notes']);if(array_key_exists('password',$b)&&$b['password']!==''){pdo()->prepare("UPDATE portal_credentials SET password_enc=?,updated_at=NOW() WHERE id=? AND workspace_id=?")->execute([encrypt_value((string)$b['password']),$id,$wid]);}api2_log('update','portal_credentials',$id);av2(['id'=>$id,'updated'=>true]);}
        if($method==='DELETE'){api2_member_permission('systems.update');pdo()->prepare("DELETE FROM portal_credentials WHERE id=? AND workspace_id=?")->execute([$id,$wid]);api2_log('delete','portal_credentials',$id);av2(['id'=>$id,'deleted'=>true]);}
    }

    if($res==='portals'){if($method!=='GET')av2err('method','Method not allowed',405);$st=pdo()->query("SELECT id,portal_key,title,url,sort_order FROM portal_definitions WHERE active=1 ORDER BY sort_order,id");av2($st->fetchAll());}

    if($res==='custom_fields'){
        if($method==='GET'){api2_member_permission('custom_fields.manage');$w=['workspace_id=?','active=1'];$p=[$wid];if($id){$w[]='id=?';$p[]=$id;}if($v=trim((string)($_GET['entity_key']??''))){$w[]='entity_key=?';$p[]=$v;}$st=pdo()->prepare("SELECT * FROM custom_fields WHERE ".implode(' AND ',$w)." ORDER BY entity_key,sort_order,id");$st->execute($p);$rows=$st->fetchAll();av2($id?($rows[0]??null):$rows);}
        if($method==='POST'){api2_member_permission('custom_fields.manage');$b=api2_body();$entity=trim((string)($b['entity_key']??''));$key=trim((string)($b['field_key']??''));$label=trim((string)($b['label']??''));if(!$entity||!$key||!$label)throw new RuntimeException('entity_key, field_key and label required');pdo()->prepare("INSERT INTO custom_fields (workspace_id,entity_key,field_key,label,field_type,options,sort_order,active,created_at,updated_at) VALUES (?,?,?,?,?,?,?,1,NOW(),NOW()) ON DUPLICATE KEY UPDATE label=VALUES(label),field_type=VALUES(field_type),options=VALUES(options),sort_order=VALUES(sort_order),active=1,updated_at=NOW()")->execute([$wid,$entity,$key,$label,$b['field_type']??'text',$b['options']??'',(int)($b['sort_order']??100)]);$r=api2_one("SELECT id FROM custom_fields WHERE workspace_id=? AND entity_key=? AND field_key=?",[$wid,$entity,$key]);api2_log('save','custom_fields',(int)($r['id']??0));av2($r,201);}
        if(in_array($method,['PATCH','PUT'],true)){api2_member_permission('custom_fields.manage');$r=api2_patch('custom_fields',$id,$wid,api2_body(),['label','field_type','options','sort_order']);api2_log('update','custom_fields',$id);av2($r);}
        if($method==='DELETE'){api2_member_permission('custom_fields.manage');pdo()->prepare("UPDATE custom_fields SET active=0,updated_at=NOW() WHERE id=? AND workspace_id=?")->execute([$id,$wid]);api2_log('delete','custom_fields',$id);av2(['id'=>$id,'deleted'=>true]);}
    }

    if($res==='notes'){
        if($method==='GET'){api2_member_permission('notes.view');$w=['workspace_id=?'];$p=[$wid];if($id){$w[]='id=?';$p[]=$id;}if($q=trim((string)($_GET['q']??''))){$w[]='(title LIKE ? OR body LIKE ?)';$l="%$q%";array_push($p,$l,$l);}$st=pdo()->prepare("SELECT * FROM notes WHERE ".implode(' AND ',$w)." ORDER BY pinned DESC,updated_at DESC");$st->execute($p);$rows=$st->fetchAll();av2($id?($rows[0]??null):$rows);}
        if($method==='POST'){api2_member_permission('notes.create');$b=api2_body();$body=trim((string)($b['body']??''));if(!$body)throw new RuntimeException('body required');pdo()->prepare("INSERT INTO notes (workspace_id,user_id,title,body,pinned,ai_status,created_at,updated_at) VALUES (?,?,?,?,?,'idle',NOW(),NOW())")->execute([$wid,$t['user_id']?:null,$b['title']??'',$body,!empty($b['pinned'])?1:0]);$nid=(int)pdo()->lastInsertId();api2_log('create','notes',$nid);av2(api2_one("SELECT * FROM notes WHERE id=? AND workspace_id=?",[$nid,$wid]),201);}
        if(in_array($method,['PATCH','PUT'],true)){api2_member_permission('notes.update');$r=api2_patch('notes',$id,$wid,api2_body(),['title','body','pinned','ai_status','ai_result_json']);api2_log('update','notes',$id);av2($r);}
        if($method==='DELETE'){api2_member_permission('notes.delete');pdo()->prepare("DELETE FROM notes WHERE id=? AND workspace_id=?")->execute([$id,$wid]);api2_log('delete','notes',$id);av2(['id'=>$id,'deleted'=>true]);}
    }

    if($res==='files'){
        if($method==='GET'){api2_member_permission('files.view');av2(FileLibrary::list(trim((string)($_GET['q']??'')),200));}
        if($method==='POST'){api2_member_permission('files.upload');if(empty($_FILES['file']))throw new RuntimeException('multipart file is required');av2(FileLibrary::upload($_FILES['file']),201);}
        if($method==='DELETE'){api2_member_permission('files.delete');FileLibrary::delete($id);av2(['id'=>$id,'deleted'=>true]);}
    }

    if($res==='attachments'){
        if($method==='GET'){api2_member_permission('files.view');av2(FileLibrary::attachments((string)($_GET['entity']??''),(int)($_GET['record_id']??0)));}
        if($method==='POST'){api2_member_permission('files.attach');$b=api2_body();FileLibrary::attach((string)($b['entity']??''),(int)($b['record_id']??0),(array)($b['file_ids']??[]));av2(['attached'=>true]);}
        if($method==='DELETE'){api2_member_permission('files.attach');$b=api2_body();FileLibrary::detach((string)($b['entity']??$_GET['entity']??''),(int)($b['record_id']??$_GET['record_id']??0),(int)($b['file_id']??$_GET['file_id']??0));av2(['detached'=>true]);}
    }

    if($res==='members'){
        if($method==='GET'){api2_member_permission('members.view');$st=pdo()->prepare("SELECT wm.id,wm.user_id,wm.role_id,wm.status,wm.joined_at,u.name,u.email,wr.name role_name,wr.role_key FROM workspace_members wm JOIN users u ON u.id=wm.user_id LEFT JOIN workspace_roles wr ON wr.id=wm.role_id WHERE wm.workspace_id=? AND wm.status='active' ORDER BY wm.id");$st->execute([$wid]);av2($st->fetchAll());}
        if($method==='POST'){api2_member_permission('members.manage');$b=api2_body();$email=mb_strtolower(trim((string)($b['email']??'')));$name=trim((string)($b['name']??''));$role=(int)($b['role_id']??0);if(!$email||!$role)throw new RuntimeException('email and role_id required');$st=pdo()->prepare("SELECT id FROM users WHERE email=?");$st->execute([$email]);$uid=(int)$st->fetchColumn();if(!$uid){$pw=(string)($b['password']??'');if(strlen($pw)<8)throw new RuntimeException('password min 8 chars for new user');pdo()->prepare("INSERT INTO users(name,email,password_hash,role,status,created_at,updated_at) VALUES (?,?,?,'accountant','active',NOW(),NOW())")->execute([$name?:$email,$email,password_hash($pw,PASSWORD_DEFAULT)]);$uid=(int)pdo()->lastInsertId();}pdo()->prepare("INSERT INTO workspace_members(workspace_id,user_id,role_id,status,joined_at,created_at,updated_at) VALUES (?,?,?,'active',NOW(),NOW(),NOW()) ON DUPLICATE KEY UPDATE role_id=VALUES(role_id),status='active',updated_at=NOW()")->execute([$wid,$uid,$role]);api2_log('add','workspace_members',$uid);av2(['user_id'=>$uid],201);}
        if(in_array($method,['PATCH','PUT'],true)){api2_member_permission('members.manage');$b=api2_body();pdo()->prepare("UPDATE workspace_members SET role_id=COALESCE(?,role_id),status=COALESCE(?,status),updated_at=NOW() WHERE id=? AND workspace_id=?")->execute([$b['role_id']??null,$b['status']??null,$id,$wid]);api2_log('update','workspace_members',$id);av2(['id'=>$id,'updated'=>true]);}
        if($method==='DELETE'){api2_member_permission('members.manage');pdo()->prepare("UPDATE workspace_members SET status='removed',updated_at=NOW() WHERE id=? AND workspace_id=?")->execute([$id,$wid]);api2_log('remove','workspace_members',$id);av2(['id'=>$id,'removed'=>true]);}
    }

    if($res==='roles'){
        if($method==='GET'){api2_member_permission('members.view');$st=pdo()->prepare("SELECT * FROM workspace_roles WHERE workspace_id=? ORDER BY is_system DESC,id");$st->execute([$wid]);$roles=$st->fetchAll();foreach($roles as &$r){$sp=pdo()->prepare("SELECT p.permission_key FROM workspace_role_permissions rp JOIN workspace_permissions p ON p.id=rp.permission_id WHERE rp.role_id=?");$sp->execute([$r['id']]);$r['permissions']=array_column($sp->fetchAll(),'permission_key');}av2($roles);}
        if($method==='POST'){api2_member_permission('members.manage');$b=api2_body();$name=trim((string)($b['name']??''));$key=preg_replace('/[^a-zA-Z0-9_]+/','_',strtolower(trim((string)($b['role_key']??''))));if(!$name||!$key)throw new RuntimeException('name and role_key required');pdo()->prepare("INSERT INTO workspace_roles(workspace_id,name,role_key,is_system,created_at,updated_at) VALUES (?,?,?,0,NOW(),NOW())")->execute([$wid,$name,$key]);$nid=(int)pdo()->lastInsertId();api2_log('create','workspace_roles',$nid);av2(['id'=>$nid],201);}
        if(in_array($method,['PATCH','PUT'],true)){api2_member_permission('members.manage');$b=api2_body();$role=api2_one("SELECT * FROM workspace_roles WHERE id=? AND workspace_id=?",[$id,$wid]);if(!$role)av2err('not_found','Role not found',404);if(isset($b['name']))pdo()->prepare("UPDATE workspace_roles SET name=?,updated_at=NOW() WHERE id=? AND workspace_id=?")->execute([$b['name'],$id,$wid]);if(array_key_exists('permissions',$b)){if($role['role_key']==='owner')$keys=array_column(pdo()->query("SELECT permission_key FROM workspace_permissions")->fetchAll(),'permission_key');else$keys=(array)$b['permissions'];$pdo=pdo();$pdo->beginTransaction();try{$pdo->prepare("DELETE FROM workspace_role_permissions WHERE role_id=?")->execute([$id]);$ins=$pdo->prepare("INSERT IGNORE INTO workspace_role_permissions(role_id,permission_id) SELECT ?,id FROM workspace_permissions WHERE permission_key=?");foreach(array_unique($keys) as $k)$ins->execute([$id,$k]);$pdo->commit();}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw$e;}}api2_log('update','workspace_roles',$id);av2(['id'=>$id,'updated'=>true]);}
    }

    if($res==='workspace'){
        if($method==='GET')av2(Tenant::current());
        if(in_array($method,['PATCH','PUT'],true)){api2_member_permission('workspace.manage');$b=api2_body();if(isset($b['name']))pdo()->prepare("UPDATE workspaces SET name=?,updated_at=NOW() WHERE id=?")->execute([trim((string)$b['name']),$wid]);api2_log('update','workspaces',$wid);av2(Tenant::current());}
    }

    if($res==='audit_logs'){
        if($method!=='GET')av2err('method','Method not allowed',405);api2_member_permission('audit.view');[$page,$per,$off]=api2_page();$st=pdo()->prepare("SELECT a.*,u.name user_name,u.email user_email FROM audit_logs a LEFT JOIN users u ON u.id=a.user_id WHERE a.workspace_id=? ORDER BY a.id DESC LIMIT $per OFFSET $off");$st->execute([$wid]);av2($st->fetchAll(),200,['page'=>$page,'per_page'=>$per]);
    }

    if($res==='table_preferences'){
        $uid=(int)($t['user_id']??0);if(!$uid)av2err('user_required','Token is not linked to a user',403);$key=trim((string)($_GET['table_key']??''));
        if($method==='GET'){$p=[$wid,$uid];$w='workspace_id=? AND user_id=?';if($key){$w.=' AND table_key=?';$p[]=$key;}$st=pdo()->prepare("SELECT table_key,prefs_json,updated_at FROM user_table_preferences WHERE $w");$st->execute($p);$rows=$st->fetchAll();foreach($rows as &$r){$r['prefs']=json_decode($r['prefs_json'],true)?:[];unset($r['prefs_json']);}av2($key?($rows[0]??null):$rows);}
        if(in_array($method,['POST','PUT','PATCH'],true)){$b=api2_body();$key=trim((string)($b['table_key']??$key));if(!$key)throw new RuntimeException('table_key required');pdo()->prepare("INSERT INTO user_table_preferences(workspace_id,user_id,table_key,prefs_json,updated_at) VALUES (?,?,?,?,NOW()) ON DUPLICATE KEY UPDATE prefs_json=VALUES(prefs_json),updated_at=NOW()")->execute([$wid,$uid,$key,json_encode((array)($b['prefs']??[]),JSON_UNESCAPED_UNICODE)]);av2(['table_key'=>$key,'saved'=>true]);}
        if($method==='DELETE'){pdo()->prepare("DELETE FROM user_table_preferences WHERE workspace_id=? AND user_id=? AND table_key=?")->execute([$wid,$uid,$key]);av2(['table_key'=>$key,'deleted'=>true]);}
    }

    if($res==='imports'){
        if($method==='GET'){[$page,$per,$off]=api2_page();$st=pdo()->prepare("SELECT * FROM imports WHERE workspace_id=? ORDER BY id DESC LIMIT $per OFFSET $off");$st->execute([$wid]);av2($st->fetchAll(),200,['page'=>$page,'per_page'=>$per]);}
        if($method==='POST'){$entity=trim((string)($_POST['entity']??''));if(!AccountingDataIO::allowed($entity))throw new RuntimeException('invalid entity');if(empty($_FILES['file'])||!is_uploaded_file($_FILES['file']['tmp_name']))throw new RuntimeException('multipart file required');$stats=AccountingDataIO::import($entity,$_FILES['file']['tmp_name'],$_FILES['file']['name']);pdo()->prepare("INSERT INTO imports(workspace_id,filename,stats,user_id,created_at) VALUES (?,?,?,?,NOW())")->execute([$wid,$_FILES['file']['name'],json_encode(['entity'=>$entity]+$stats,JSON_UNESCAPED_UNICODE),$t['user_id']?:null]);api2_log('import',$entity,0,['filename'=>$_FILES['file']['name']]);av2($stats,201);}
    }

    if($res==='exports'){
        if($method!=='GET')av2err('method','Method not allowed',405);$entity=trim((string)($_GET['entity']??''));if(!AccountingDataIO::allowed($entity))throw new RuntimeException('invalid entity');if($entity==='portal_credentials')api2_scope($t,'secrets');$format=strtolower(trim((string)($_GET['format']??'json')));$ids=array_values(array_filter(array_map('intval',explode(',',(string)($_GET['ids']??'')))));api2_log('export',$entity,0,['format'=>$format]);if(in_array($format,['csv','xlsx'],true))AccountingDataIO::stream($entity,$format,$ids,false);[$headers,$rows]=AccountingDataIO::exportRows($entity,$ids);av2(['headers'=>$headers,'rows'=>$rows]);
    }

    av2err('not_found','Resource or method not available',404);
}catch(Throwable $e){av2err('request_failed',$e->getMessage(),400);}
