<?php
require __DIR__ . '/app/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
function out($d,$code=200){ http_response_code($code); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }
function has_table(PDO $pdo,string $t): bool { $st=$pdo->prepare('SHOW TABLES LIKE ?'); $st->execute([$t]); return (bool)$st->fetchColumn(); }
try { Schema::migrate(pdo()); } catch(Throwable $e) {}
if (setting('api_enabled','1') !== '1') out(['ok'=>false,'error'=>'API غیرفعال است.'],403);
$token = $_SERVER['HTTP_X_ACCOUNTING_TOKEN'] ?? ($_GET['token'] ?? '');
$expected = setting('edge_service_token','');
if ($expected && !hash_equals($expected, (string)$token)) out(['ok'=>false,'error'=>'توکن API نامعتبر است.'],401);
$resource = $_GET['resource'] ?? 'summary';
$allowed = ['companies','daily_plans','monthly_plans','module_records','custom_fields'];
if ($resource === 'summary') {
    $today=date('Y-m-d');
    out(['ok'=>true,'data'=>[
        'companies'=> has_table(pdo(),'companies') ? (int)pdo()->query("SELECT COUNT(*) FROM companies WHERE active=1")->fetchColumn() : 0,
        'monthly_open'=> has_table(pdo(),'monthly_plans') ? (int)pdo()->query("SELECT COUNT(*) FROM monthly_plans WHERE status<>'انجام شده'")->fetchColumn() : 0,
        'module_open'=> has_table(pdo(),'module_records') ? (int)pdo()->query("SELECT COUNT(*) FROM module_records WHERE status<>'انجام شده'")->fetchColumn() : 0,
        'today'=>$today,
    ]]);
}
if (!in_array($resource,$allowed,true)) out(['ok'=>false,'error'=>'resource نامعتبر است.'],400);
$limit=min(200, max(1, (int)($_GET['limit'] ?? 50))); $params=[]; $where='1=1';
if ($resource==='companies') $where='active=1';
if ($resource==='module_records' && !empty($_GET['module_key'])) { $where.=' AND module_key=?'; $params[]=$_GET['module_key']; }
if (!empty($_GET['company_id']) && in_array($resource,['daily_plans','monthly_plans','module_records'],true)) { $where.=' AND company_id=?'; $params[]=(int)$_GET['company_id']; }
$st=pdo()->prepare("SELECT * FROM `$resource` WHERE $where ORDER BY id DESC LIMIT $limit"); $st->execute($params); out(['ok'=>true,'resource'=>$resource,'data'=>$st->fetchAll()]);
