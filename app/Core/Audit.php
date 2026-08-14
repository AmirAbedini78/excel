<?php
final class Audit
{
    public static function ensureSchema(): void
    {
        pdo()->exec("CREATE TABLE IF NOT EXISTS audit_logs (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            workspace_id INT NOT NULL,
            user_id INT NULL,
            action VARCHAR(100) NOT NULL,
            entity_key VARCHAR(100) NULL,
            record_id BIGINT NULL,
            summary VARCHAR(255) NULL,
            before_json JSON NULL,
            after_json JSON NULL,
            meta_json JSON NULL,
            ip VARCHAR(80) NULL,
            user_agent VARCHAR(500) NULL,
            request_id VARCHAR(64) NULL,
            created_at DATETIME NOT NULL,
            INDEX idx_audit_workspace_date (workspace_id,created_at),
            INDEX idx_audit_user_date (user_id,created_at),
            INDEX idx_audit_entity (workspace_id,entity_key,record_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    public static function log(string $action,string $entity='',int $recordId=0,string $summary='',?array $before=null,?array $after=null,array $meta=[]): void
    {
        $wid=Tenant::id();if(!$wid)return;
        self::logForWorkspace($wid,$action,$entity,$recordId,$summary,$before,$after,$meta);
    }

    public static function logForWorkspace(int $workspaceId,string $action,string $entity='',int $recordId=0,string $summary='',?array $before=null,?array $after=null,array $meta=[]): void
    {
        try{
            self::ensureSchema();if($workspaceId<=0)return;
            $uid=Auth::check()?(int)Auth::user()['id']:null;
            $req=$_SERVER['HTTP_X_REQUEST_ID']??substr(hash('sha256',uniqid('',true)),0,24);
            pdo()->prepare("INSERT INTO audit_logs (workspace_id,user_id,action,entity_key,record_id,summary,before_json,after_json,meta_json,ip,user_agent,request_id,created_at)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,NOW())")->execute([
                    $workspaceId,$uid,$action,$entity?:null,$recordId?:null,$summary,
                    $before?json_encode($before,JSON_UNESCAPED_UNICODE):null,
                    $after?json_encode($after,JSON_UNESCAPED_UNICODE):null,
                    $meta?json_encode($meta,JSON_UNESCAPED_UNICODE):null,
                    $_SERVER['REMOTE_ADDR']??'',substr($_SERVER['HTTP_USER_AGENT']??'',0,500),$req
                ]);
        }catch(Throwable $e){}
    }
}
