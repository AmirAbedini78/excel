<?php
require __DIR__.'/app/bootstrap.php';
require_once __DIR__.'/app/Core/ApiV1.php';

Auth::require();
header('Content-Type: application/json; charset=utf-8');

function adm_out(array $data,int $status=200): never
{
    http_response_code($status);
    echo json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    exit;
}
if(!Auth::isAdmin()) adm_out(['ok'=>false,'error'=>'فقط مدیر سامانه به مدیریت API دسترسی دارد.'],403);

ApiV1Auth::ensureSchema();
$action=$_GET['action']??$_POST['action']??'list';

try{
    if($action==='list'){
        adm_out([
            'ok'=>true,
            'tokens'=>ApiV1Auth::listTokens(),
            'settings'=>[
                'enabled'=>setting('api_enabled','1'),
                'cors_origins'=>setting('api_cors_origins',''),
                'rate_limit'=>setting('api_rate_limit_per_minute','120'),
                'base_url'=>rtrim(base_url('api/v1'),'/'),
            ]
        ]);
    }

    if($_SERVER['REQUEST_METHOD']!=='POST')throw new RuntimeException('درخواست نامعتبر است.');
    verify_csrf();

    if($action==='create'){
        $preset=$_POST['preset']??'readwrite';
        $scopes=match($preset){
            'read'=>['read'],
            'full'=>['read','write','secrets','settings'],
            default=>['read','write']
        };
        $days=max(0,min(3650,(int)($_POST['expires_days']??0)));
        $expires=$days?date('Y-m-d H:i:s',time()+$days*86400):null;
        $token=ApiV1Auth::createToken((int)Auth::user()['id'],trim((string)($_POST['name']??'')),$scopes,$expires);
        adm_out(['ok'=>true,'token'=>$token]);
    }

    if($action==='revoke'){
        $id=(int)($_POST['id']??0);if(!$id)throw new RuntimeException('شناسه توکن نامعتبر است.');
        ApiV1Auth::revoke($id);
        adm_out(['ok'=>true]);
    }

    if($action==='save_settings'){
        setting_set('api_enabled',isset($_POST['enabled'])&&$_POST['enabled']==='1'?'1':'0',0);
        setting_set('api_cors_origins',trim((string)($_POST['cors_origins']??'')),0);
        $rate=max(10,min(5000,(int)($_POST['rate_limit']??120)));
        setting_set('api_rate_limit_per_minute',(string)$rate,0);
        adm_out(['ok'=>true]);
    }

    throw new RuntimeException('عملیات ناشناخته است.');
}catch(Throwable $e){
    adm_out(['ok'=>false,'error'=>$e->getMessage()],400);
}
