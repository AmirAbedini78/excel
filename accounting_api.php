<?php
require __DIR__.'/app/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
function acc_json(array $data,int $code=200): never{http_response_code($code);echo json_encode($data,JSON_UNESCAPED_UNICODE);exit;}
try{
    Auth::require();Tenant::requirePermission('accounting.view');
    $action=(string)($_GET['action']??'meta');
    if($action==='meta')acc_json(['ok'=>true,'version'=>AccountingSchema::VERSION,'company'=>AccountingRepository::company(),'workspace'=>Tenant::current()]);
    if($action==='items'){
        $q=trim((string)($_GET['q']??''));$p=[Tenant::id(),AccountingRepository::companyId()];$w="workspace_id=? AND company_id=? AND active=1";
        if($q!==''){$w.=" AND (code LIKE ? OR name LIKE ?)";$like="%$q%";$p[]=$like;$p[]=$like;}
        $st=pdo()->prepare("SELECT id,code,name,item_type,purchase_price_1,base_unit_id FROM acc_items WHERE $w ORDER BY name LIMIT 50");$st->execute($p);acc_json(['ok'=>true,'items'=>$st->fetchAll()]);
    }
    if($action==='parties'){
        $q=trim((string)($_GET['q']??''));$p=[Tenant::id(),AccountingRepository::companyId()];$w="workspace_id=? AND company_id=? AND active=1";
        if($q!==''){$w.=" AND (name LIKE ? OR national_id LIKE ? OR mobile LIKE ?)";$like="%$q%";array_push($p,$like,$like,$like);}
        $st=pdo()->prepare("SELECT id,code,name,party_type,national_id,mobile FROM acc_parties WHERE $w ORDER BY name LIMIT 50");$st->execute($p);acc_json(['ok'=>true,'items'=>$st->fetchAll()]);
    }
    acc_json(['ok'=>false,'error'=>'عملیات API شناخته نشد'],404);
}catch(Throwable $e){acc_json(['ok'=>false,'error'=>$e->getMessage()],500);}
