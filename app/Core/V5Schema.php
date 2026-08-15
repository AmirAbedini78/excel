<?php
final class V5Schema
{
    public static function migrate(PDO $pdo): void
    {
        self::ensureNotes($pdo);
        self::ensurePhonebook($pdo);
        self::ensureSharing($pdo);
        self::ensurePermissions($pdo);
        self::ensurePerformanceIndexes($pdo);
        self::ensureDefaults($pdo);
    }

    private static function columnExists(PDO $pdo,string $table,string $column): bool
    {
        $st=$pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=? LIMIT 1");
        $st->execute([$table,$column]);return(bool)$st->fetchColumn();
    }

    private static function indexExists(PDO $pdo,string $table,string $index): bool
    {
        $st=$pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND INDEX_NAME=? LIMIT 1");
        $st->execute([$table,$index]);return(bool)$st->fetchColumn();
    }

    private static function addColumn(PDO $pdo,string $table,string $column,string $definition): void
    {
        if(!self::columnExists($pdo,$table,$column))$pdo->exec("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
    }

    private static function addIndex(PDO $pdo,string $table,string $index,string $columns): void
    {
        if(!self::indexExists($pdo,$table,$index)){
            try{$pdo->exec("ALTER TABLE `$table` ADD INDEX `$index` ($columns)");}catch(Throwable $e){}
        }
    }

    private static function ensureNotes(PDO $pdo): void
    {
        $pdo->exec("CREATE TABLE IF NOT EXISTS notes (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            workspace_id INT NOT NULL,
            user_id INT NULL,
            title VARCHAR(255) NULL,
            body MEDIUMTEXT NOT NULL,
            pinned TINYINT(1) NOT NULL DEFAULT 0,
            color_key VARCHAR(40) NULL,
            is_completed TINYINT(1) NOT NULL DEFAULT 0,
            completed_at DATETIME NULL,
            priority VARCHAR(20) NOT NULL DEFAULT 'normal',
            due_date DATE NULL,
            ai_status VARCHAR(40) NOT NULL DEFAULT 'idle',
            ai_result_json JSON NULL,
            created_at DATETIME NULL, updated_at DATETIME NULL,
            INDEX idx_notes_workspace (workspace_id,pinned,updated_at),
            INDEX idx_notes_user (workspace_id,user_id),
            INDEX idx_notes_task (workspace_id,is_completed,due_date,updated_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        self::addColumn($pdo,'notes','is_completed',"TINYINT(1) NOT NULL DEFAULT 0");
        self::addColumn($pdo,'notes','completed_at',"DATETIME NULL");
        self::addColumn($pdo,'notes','priority',"VARCHAR(20) NOT NULL DEFAULT 'normal'");
        self::addColumn($pdo,'notes','due_date',"DATE NULL");
        self::addIndex($pdo,'notes','idx_notes_task','workspace_id,is_completed,due_date,updated_at');
    }

    private static function ensurePhonebook(PDO $pdo): void
    {
        $pdo->exec("CREATE TABLE IF NOT EXISTS phonebook_entries (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            workspace_id INT NOT NULL,
            client_company_id INT NOT NULL,
            person_name VARCHAR(190) NULL,
            person_title VARCHAR(190) NULL,
            organization_name VARCHAR(190) NULL,
            contact_type VARCHAR(30) NOT NULL DEFAULT 'mobile',
            phone_number VARCHAR(80) NOT NULL,
            extension_no VARCHAR(40) NULL,
            contacted_date DATE NULL,
            followup_date DATE NULL,
            followup_done TINYINT(1) NOT NULL DEFAULT 0,
            notes TEXT NULL,
            created_by INT NULL,
            created_at DATETIME NULL,
            updated_at DATETIME NULL,
            INDEX idx_phonebook_workspace (workspace_id,client_company_id,followup_date),
            INDEX idx_phonebook_phone (workspace_id,phone_number),
            INDEX idx_phonebook_followup (workspace_id,followup_done,followup_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    private static function ensureSharing(PDO $pdo): void
    {
        self::addColumn($pdo,'workspaces','share_code',"VARCHAR(32) NULL");
        if(!self::indexExists($pdo,'workspaces','uniq_workspace_share_code')){
            try{$pdo->exec("ALTER TABLE workspaces ADD UNIQUE KEY uniq_workspace_share_code (share_code)");}catch(Throwable $e){}
        }

        $pdo->exec("CREATE TABLE IF NOT EXISTS workspace_company_shares (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            source_workspace_id INT NOT NULL,
            target_workspace_id INT NOT NULL,
            company_id INT NOT NULL,
            share_daily TINYINT(1) NOT NULL DEFAULT 1,
            share_monthly TINYINT(1) NOT NULL DEFAULT 1,
            share_phonebook TINYINT(1) NOT NULL DEFAULT 1,
            access_level VARCHAR(20) NOT NULL DEFAULT 'view',
            status VARCHAR(20) NOT NULL DEFAULT 'active',
            created_by INT NULL,
            created_at DATETIME NULL,
            updated_at DATETIME NULL,
            UNIQUE KEY uniq_workspace_company_share (source_workspace_id,target_workspace_id,company_id),
            INDEX idx_share_target (target_workspace_id,status,source_workspace_id),
            INDEX idx_share_source (source_workspace_id,status,target_workspace_id),
            INDEX idx_share_company (company_id,status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $rows=$pdo->query("SELECT id,share_code FROM workspaces")->fetchAll();
        $upd=$pdo->prepare("UPDATE workspaces SET share_code=? WHERE id=? AND (share_code IS NULL OR share_code='')");
        foreach($rows as $w){
            if(trim((string)($w['share_code']??''))!=='')continue;
            for($i=0;$i<8;$i++){
                $code=strtoupper(substr(bin2hex(random_bytes(8)),0,16));
                try{$upd->execute([$code,(int)$w['id']]);break;}catch(Throwable $e){}
            }
        }
    }

    private static function ensurePermissions(PDO $pdo): void
    {
        $defs=[
            ['phonebook.view','مشاهده دفترچه تلفن','phonebook',160],
            ['phonebook.create','ثبت تماس و پیگیری','phonebook',161],
            ['phonebook.update','ویرایش تماس و پیگیری','phonebook',162],
            ['phonebook.delete','حذف تماس و پیگیری','phonebook',163],
            ['shares.view','مشاهده داده‌های اشتراکی','shares',170],
            ['shares.manage','اشتراک‌گذاری داده با محیط دیگر','shares',171],
            ['cache.manage','مدیریت کش و عملکرد','performance',180],
        ];
        $ins=$pdo->prepare("INSERT INTO workspace_permissions (permission_key,title,group_key,sort_order)
            VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE title=VALUES(title),group_key=VALUES(group_key),sort_order=VALUES(sort_order)");
        foreach($defs as $d)$ins->execute($d);

        $roleSets=[
            'owner'=>['phonebook.view','phonebook.create','phonebook.update','phonebook.delete','shares.view','shares.manage','cache.manage'],
            'workspace_admin'=>['phonebook.view','phonebook.create','phonebook.update','phonebook.delete','shares.view','shares.manage','cache.manage'],
            'manager'=>['phonebook.view','phonebook.create','phonebook.update','phonebook.delete','shares.view'],
            'accountant'=>['phonebook.view','phonebook.create','phonebook.update','shares.view'],
            'viewer'=>['phonebook.view','shares.view'],
        ];
        $pid=$pdo->prepare("SELECT id FROM workspace_permissions WHERE permission_key=? LIMIT 1");
        $roles=$pdo->prepare("SELECT id,role_key FROM workspace_roles WHERE workspace_id=?");
        $rp=$pdo->prepare("INSERT IGNORE INTO workspace_role_permissions (role_id,permission_id) VALUES (?,?)");
        foreach($pdo->query("SELECT id FROM workspaces")->fetchAll() as $w){
            $roles->execute([(int)$w['id']]);
            foreach($roles->fetchAll() as $r){
                foreach($roleSets[$r['role_key']]??[] as $key){
                    $pid->execute([$key]);$permissionId=(int)$pid->fetchColumn();
                    if($permissionId)$rp->execute([(int)$r['id'],$permissionId]);
                }
            }
        }
    }

    private static function ensurePerformanceIndexes(PDO $pdo): void
    {
        $indexes=[
            ['companies','idx_v5_company_ws_active_name','workspace_id,active,name'],
            ['daily_plans','idx_v5_daily_ws_date_company','workspace_id,plan_date,company_id,status'],
            ['monthly_plans','idx_v5_monthly_ws_company_status_due','workspace_id,company_id,status,legal_deadline'],
            ['custom_fields','idx_v5_custom_ws_entity_active','workspace_id,entity_key,active,sort_order'],
            ['portal_credentials','idx_v5_portal_ws_company','workspace_id,company_id,portal_key'],
            ['activity_logs','idx_v5_activity_ws_created','workspace_id,created_at'],
            ['user_table_preferences','idx_v5_pref_ws_user','workspace_id,user_id,table_key'],
            ['choice_sets','idx_v5_choiceset_ws_key','workspace_id,set_key,active'],
            ['choice_values','idx_v5_choicevalue_ws_set','workspace_id,set_id,active,sort_order'],
        ];
        foreach($indexes as [$t,$i,$c]){
            try{
                $st=$pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? LIMIT 1");
                $st->execute([$t]);if($st->fetchColumn())self::addIndex($pdo,$t,$i,$c);
            }catch(Throwable $e){}
        }
    }

    private static function ensureDefaults(PDO $pdo): void
    {
        $defaults=[
            ['audit_page_views','0'],
            ['cache_ttl_seconds','60'],
            ['v5_ui_ajax','1'],
            ['saas_schema_version','5.0.0'],
        ];
        $st=$pdo->prepare("INSERT INTO settings (`key`,`value`,`encrypted`,`updated_at)
            VALUES (?,?,0,NOW()) ON DUPLICATE KEY UPDATE `value`=CASE WHEN `key`='saas_schema_version' THEN VALUES(`value`) ELSE `value` END,updated_at=NOW()");
        foreach($defaults as $d)$st->execute($d);
    }
}
