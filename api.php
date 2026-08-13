<?php
require __DIR__ . '/app/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');

function out($d,$code=200){ http_response_code($code); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }

try { Schema::migrate(pdo()); } catch(Throwable $e) {}

if (setting('api_enabled','1') !== '1') out(['ok'=>false,'error'=>'API غیرفعال است.'],403);

$token = $_SERVER['HTTP_X_ACCOUNTING_TOKEN'] ?? ($_GET['token'] ?? '');
$expected = setting('edge_service_token','');
if ($expected && !hash_equals($expected, (string)$token)) out(['ok'=>false,'error'=>'توکن API نامعتبر است.'],401);

$resource = $_GET['resource'] ?? 'summary';
$allowed = ['companies','daily_plans','monthly_plans','custom_fields','portal_definitions'];

if ($resource === 'summary') {
    out(['ok'=>true,'data'=>[
        'companies'=> table_exists(pdo(),'companies') ? (int)pdo()->query("SELECT COUNT(*) FROM companies WHERE active=1")->fetchColumn() : 0,
        'daily_total'=> table_exists(pdo(),'daily_plans') ? (int)pdo()->query("SELECT COUNT(*) FROM daily_plans")->fetchColumn() : 0,
        'monthly_open'=> table_exists(pdo(),'monthly_plans') ? (int)pdo()->query("SELECT COUNT(*) FROM monthly_plans WHERE status<>'انجام شده'")->fetchColumn() : 0,
        'today'=>date('Y-m-d'),
        'jalali_today'=>Jalali::today(),
        'schema_version'=>setting('schema_version','3.0.0'),
    ]]);
}

if (!in_array($resource,$allowed,true)) out(['ok'=>false,'error'=>'resource نامعتبر است.'],400);

$limit=min(500, max(1, (int)($_GET['limit'] ?? 100)));
$params=[]; $where='1=1';

if ($resource==='companies') $where='active=1';
if (!empty($_GET['company_id']) && in_array($resource,['daily_plans','monthly_plans'],true)) {
    $where.=' AND company_id=?'; $params[]=(int)$_GET['company_id'];
}

$order = $resource==='portal_definitions' ? 'sort_order ASC,id ASC' : 'id DESC';
$st=pdo()->prepare("SELECT * FROM `$resource` WHERE $where ORDER BY $order LIMIT $limit");
$st->execute($params);
out(['ok'=>true,'resource'=>$resource,'data'=>$st->fetchAll()]);
