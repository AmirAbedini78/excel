<?php
class Schema
{
    public static function migrate(PDO $pdo): void
    {
        $pdo->exec("CREATE TABLE IF NOT EXISTS users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(150) NOT NULL,
            email VARCHAR(190) NOT NULL UNIQUE,
            password_hash VARCHAR(255) NULL,
            google_id VARCHAR(190) NULL UNIQUE,
            avatar VARCHAR(500) NULL,
            role ENUM('admin','accountant','viewer') NOT NULL DEFAULT 'accountant',
            status ENUM('active','disabled') NOT NULL DEFAULT 'active',
            created_at DATETIME NULL, updated_at DATETIME NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $pdo->exec("CREATE TABLE IF NOT EXISTS settings (
            `key` VARCHAR(120) PRIMARY KEY, `value` MEDIUMTEXT NULL, encrypted TINYINT(1) NOT NULL DEFAULT 0, updated_at DATETIME NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $pdo->exec("CREATE TABLE IF NOT EXISTS user_table_preferences (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            table_key VARCHAR(120) NOT NULL,
            prefs_json MEDIUMTEXT NOT NULL,
            updated_at DATETIME NULL,
            UNIQUE KEY uniq_user_table_pref (user_id,table_key),
            INDEX idx_table_pref_user (user_id),
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $pdo->exec("CREATE TABLE IF NOT EXISTS companies (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(190) NOT NULL UNIQUE,
            type VARCHAR(100) NULL,
            company_type VARCHAR(100) NULL,
            legal_personality VARCHAR(80) NULL,
            software VARCHAR(120) NULL,
            manager_name VARCHAR(150) NULL,
            financial_manager VARCHAR(150) NULL,
            phone VARCHAR(100) NULL,
            address TEXT NULL,
            registration_number VARCHAR(100) NULL,
            postal_code VARCHAR(30) NULL,
            ceo_name VARCHAR(150) NULL,
            ceo_national_id VARCHAR(30) NULL,
            ceo_mobile VARCHAR(40) NULL,
            national_id VARCHAR(80) NULL,
            economic_code VARCHAR(80) NULL,
            tax_username VARCHAR(190) NULL,
            insurance_code VARCHAR(190) NULL,
            notes TEXT NULL,
            extra_json JSON NULL,
            active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NULL, updated_at DATETIME NULL,
            INDEX idx_company_active (active), INDEX idx_company_type (company_type), INDEX idx_company_personality (legal_personality)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        self::addColumn($pdo,'companies','company_type',"VARCHAR(100) NULL");
        self::addColumn($pdo,'companies','legal_personality',"VARCHAR(80) NULL");
        self::addColumn($pdo,'companies','registration_number',"VARCHAR(100) NULL");
        self::addColumn($pdo,'companies','postal_code',"VARCHAR(30) NULL");
        self::addColumn($pdo,'companies','ceo_name',"VARCHAR(150) NULL");
        self::addColumn($pdo,'companies','ceo_national_id',"VARCHAR(30) NULL");
        self::addColumn($pdo,'companies','ceo_mobile',"VARCHAR(40) NULL");
        self::addColumn($pdo,'companies','national_id',"VARCHAR(80) NULL");
        self::addColumn($pdo,'companies','economic_code',"VARCHAR(80) NULL");
        self::addColumn($pdo,'companies','extra_json',"JSON NULL");

        // Old tables are kept for backward compatibility.
        $pdo->exec("CREATE TABLE IF NOT EXISTS weekly_schedules (
            id INT AUTO_INCREMENT PRIMARY KEY,
            weekday VARCHAR(40) NOT NULL, shift_label VARCHAR(100) NULL, company_id INT NULL,
            attendance_type VARCHAR(100) NULL, notes TEXT NULL,
            FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $pdo->exec("CREATE TABLE IF NOT EXISTS tasks (
            id INT AUTO_INCREMENT PRIMARY KEY,
            company_id INT NULL,
            code VARCHAR(50) NULL,
            category VARCHAR(120) NULL,
            title VARCHAR(255) NOT NULL,
            frequency VARCHAR(80) NULL,
            period_label VARCHAR(80) NULL,
            due_date DATE NULL,
            reminder_days INT NOT NULL DEFAULT 5,
            priority VARCHAR(30) NOT NULL DEFAULT 'متوسط',
            status VARCHAR(40) NOT NULL DEFAULT 'باز',
            assigned_to VARCHAR(150) NULL,
            description TEXT NULL,
            completed_at DATETIME NULL,
            created_by INT NULL,
            created_at DATETIME NULL, updated_at DATETIME NULL,
            INDEX idx_due_status (due_date,status), INDEX idx_company (company_id),
            FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE SET NULL,
            FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $pdo->exec("CREATE TABLE IF NOT EXISTS daily_plans (
            id INT AUTO_INCREMENT PRIMARY KEY,
            plan_date DATE NULL,
            day_name VARCHAR(40) NULL,
            company_id INT NULL,
            work_description VARCHAR(255) NOT NULL,
            location VARCHAR(120) NULL,
            status VARCHAR(50) NOT NULL DEFAULT 'باز',
            notes TEXT NULL,
            extra_json JSON NULL,
            created_by INT NULL,
            created_at DATETIME NULL, updated_at DATETIME NULL,
            INDEX idx_daily_date_status (plan_date,status), INDEX idx_daily_company (company_id),
            FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE SET NULL,
            FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $pdo->exec("CREATE TABLE IF NOT EXISTS monthly_plans (
            id INT AUTO_INCREMENT PRIMARY KEY,
            company_id INT NULL,
            jalali_year INT NOT NULL DEFAULT 1405,
            month_name VARCHAR(40) NULL,
            season VARCHAR(40) NULL,
            work_type VARCHAR(120) NOT NULL,
            legal_deadline DATE NULL,
            status VARCHAR(50) NOT NULL DEFAULT 'باز',
            work_day VARCHAR(80) NULL,
            completed_date DATE NULL,
            notes TEXT NULL,
            extra_json JSON NULL,
            created_by INT NULL,
            created_at DATETIME NULL, updated_at DATETIME NULL,
            INDEX idx_monthly_deadline (legal_deadline,status), INDEX idx_monthly_company (company_id), INDEX idx_monthly_type (work_type),
            FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE SET NULL,
            FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $pdo->exec("CREATE TABLE IF NOT EXISTS module_records (
            id INT AUTO_INCREMENT PRIMARY KEY,
            module_key VARCHAR(80) NOT NULL,
            company_id INT NULL,
            title VARCHAR(255) NOT NULL,
            category VARCHAR(120) NULL,
            period_label VARCHAR(120) NULL,
            due_date DATE NULL,
            status VARCHAR(50) NOT NULL DEFAULT 'باز',
            completed_date DATE NULL,
            amount DECIMAL(18,2) NULL,
            responsible VARCHAR(150) NULL,
            notes TEXT NULL,
            extra_json JSON NULL,
            created_by INT NULL,
            created_at DATETIME NULL, updated_at DATETIME NULL,
            INDEX idx_module_lookup (module_key, due_date, status), INDEX idx_module_company (module_key, company_id),
            FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE SET NULL,
            FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $pdo->exec("CREATE TABLE IF NOT EXISTS custom_fields (
            id INT AUTO_INCREMENT PRIMARY KEY,
            entity_key VARCHAR(80) NOT NULL,
            field_key VARCHAR(80) NOT NULL,
            label VARCHAR(120) NOT NULL,
            field_type VARCHAR(40) NOT NULL DEFAULT 'text',
            options TEXT NULL,
            sort_order INT NOT NULL DEFAULT 100,
            active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NULL, updated_at DATETIME NULL,
            UNIQUE KEY uniq_entity_field (entity_key, field_key), INDEX idx_entity_active (entity_key, active)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $pdo->exec("CREATE TABLE IF NOT EXISTS followups (
            id INT AUTO_INCREMENT PRIMARY KEY,
            company_id INT NULL, requester VARCHAR(150) NULL, subject VARCHAR(255) NOT NULL, next_action VARCHAR(255) NULL,
            followup_date DATE NULL, priority VARCHAR(30) NOT NULL DEFAULT 'متوسط', status VARCHAR(50) NOT NULL DEFAULT 'باز', notes TEXT NULL,
            created_at DATETIME NULL, updated_at DATETIME NULL,
            INDEX idx_follow_date (followup_date,status), FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $pdo->exec("CREATE TABLE IF NOT EXISTS bank_reconciliations (
            id INT AUTO_INCREMENT PRIMARY KEY,
            company_id INT NULL, bank_name VARCHAR(150) NULL, period_label VARCHAR(100) NULL, discovered_at DATE NULL,
            amount DECIMAL(18,2) NULL, mismatch_type VARCHAR(100) NULL, description TEXT NULL, correction_action TEXT NULL,
            status VARCHAR(50) NOT NULL DEFAULT 'باز', responsible VARCHAR(150) NULL, target_date DATE NULL, notes TEXT NULL,
            created_at DATETIME NULL, updated_at DATETIME NULL,
            FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $pdo->exec("CREATE TABLE IF NOT EXISTS systems (
            id INT AUTO_INCREMENT PRIMARY KEY,
            company_id INT NULL, service_name VARCHAR(150) NOT NULL, url VARCHAR(500) NULL, username VARCHAR(190) NULL,
            related_code VARCHAR(190) NULL, secret_note TEXT NULL, last_checked_at DATE NULL, notes TEXT NULL,
            created_at DATETIME NULL, updated_at DATETIME NULL,
            FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $pdo->exec("CREATE TABLE IF NOT EXISTS portal_definitions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            portal_key VARCHAR(80) NOT NULL UNIQUE,
            title VARCHAR(150) NOT NULL,
            url VARCHAR(500) NOT NULL,
            sort_order INT NOT NULL DEFAULT 100,
            active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NULL, updated_at DATETIME NULL,
            INDEX idx_portal_order (active,sort_order)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $pdo->exec("CREATE TABLE IF NOT EXISTS portal_credentials (
            id INT AUTO_INCREMENT PRIMARY KEY,
            company_id INT NOT NULL,
            portal_key VARCHAR(80) NOT NULL,
            username VARCHAR(255) NULL,
            password_enc MEDIUMTEXT NULL,
            notes TEXT NULL,
            created_at DATETIME NULL, updated_at DATETIME NULL,
            UNIQUE KEY uniq_company_portal (company_id,portal_key),
            INDEX idx_portal_company (portal_key,company_id),
            FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $pdo->exec("CREATE TABLE IF NOT EXISTS error_notes (
            id INT AUTO_INCREMENT PRIMARY KEY,
            company_id INT NULL, happened_at DATE NULL, process VARCHAR(150) NULL, risk TEXT NULL, root_cause TEXT NULL,
            solution TEXT NULL, document_no VARCHAR(120) NULL, prevention TEXT NULL, status VARCHAR(50) NULL,
            created_at DATETIME NULL, updated_at DATETIME NULL,
            FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $pdo->exec("CREATE TABLE IF NOT EXISTS remote_services (
            id INT AUTO_INCREMENT PRIMARY KEY,
            service_key VARCHAR(80) NOT NULL UNIQUE,
            title VARCHAR(150) NOT NULL,
            base_url VARCHAR(500) NULL,
            api_key TEXT NULL,
            enabled TINYINT(1) NOT NULL DEFAULT 0,
            notes TEXT NULL,
            updated_at DATETIME NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $pdo->exec("CREATE TABLE IF NOT EXISTS activity_logs (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NULL,
            entity_key VARCHAR(80) NULL,
            record_id INT NULL,
            action VARCHAR(80) NOT NULL,
            summary VARCHAR(255) NULL,
            payload JSON NULL,
            ip VARCHAR(80) NULL,
            created_at DATETIME NULL,
            INDEX idx_activity_entity (entity_key, record_id), INDEX idx_activity_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $pdo->exec("CREATE TABLE IF NOT EXISTS notification_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            task_id INT NULL, channel VARCHAR(20) NOT NULL, recipient VARCHAR(255) NULL, message TEXT NULL,
            status VARCHAR(30) NOT NULL, response MEDIUMTEXT NULL, created_at DATETIME NULL,
            INDEX idx_task_channel_date (task_id,channel,created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $pdo->exec("CREATE TABLE IF NOT EXISTS imports (
            id INT AUTO_INCREMENT PRIMARY KEY, filename VARCHAR(255) NULL, stats TEXT NULL, user_id INT NULL, created_at DATETIME NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        self::seedSettings($pdo);
        self::normalizeOldCompanies($pdo);
        self::seedPortalDefinitions($pdo);
        self::migrateLegacySystems($pdo);
        self::seedV2($pdo);
    }

    private static function addColumn(PDO $pdo, string $table, string $column, string $definition): void
    {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $table) || !preg_match('/^[A-Za-z0-9_]+$/', $column)) {
            throw new InvalidArgumentException('Invalid schema identifier');
        }
        $st = $pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=? LIMIT 1");
        $st->execute([$table,$column]);
        if (!$st->fetchColumn()) $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
    }

    public static function createAdmin(PDO $pdo, string $name, string $email, string $password): void
    {
        $st = $pdo->prepare("INSERT INTO users (name,email,password_hash,role,status,created_at,updated_at) VALUES (?,?,?,?,?,NOW(),NOW()) ON DUPLICATE KEY UPDATE name=VALUES(name), password_hash=VALUES(password_hash), role='admin', status='active', updated_at=NOW()");
        $st->execute([$name, mb_strtolower(trim($email)), password_hash($password, PASSWORD_DEFAULT), 'admin', 'active']);
    }

    public static function seed(PDO $pdo): void { self::seedV2($pdo); }

    private static function seedSettings(PDO $pdo): void
    {
        $defaults = [
            'schema_version'=>'3.1.0', 'smtp_host'=>'smtp.gmail.com','smtp_port'=>'587','smtp_encryption'=>'tls','mail_from_name'=>'Accounting Manager',
            'ghasedak_line_number'=>'','notifications_email_to'=>'','notifications_sms_to'=>'','allow_google_signup'=>'1',
            'cron_secret'=>bin2hex(random_bytes(16)), 'edge_service_url'=>'', 'edge_service_token'=>'', 'cache_ttl_seconds'=>'30', 'api_enabled'=>'1'
        ];
        $st=$pdo->prepare("INSERT INTO settings (`key`,`value`,`encrypted`,`updated_at`) VALUES (?,?,0,NOW()) ON DUPLICATE KEY UPDATE `value`=`value`");
        foreach ($defaults as $k=>$v) $st->execute([$k,$v]);
        // Schema version is controlled by the migration itself; unlike user settings it must advance on upgrade.
        $pdo->prepare("INSERT INTO settings (`key`,`value`,`encrypted`,`updated_at`) VALUES ('schema_version','3.1.0',0,NOW()) ON DUPLICATE KEY UPDATE `value`='3.1.0',updated_at=NOW()")->execute();
        $st=$pdo->prepare("INSERT INTO remote_services (service_key,title,base_url,api_key,enabled,notes,updated_at) VALUES (?,?,?,?,0,?,NOW()) ON DUPLICATE KEY UPDATE title=VALUES(title)");
        $st->execute(['hermes','Hermes / RAG / AI Agent','','','اتصال آینده به هرمس، RAG و عامل هوش مصنوعی']);
        $st->execute(['edge_worker','سرویس جانبی روی سیستم/سرور دیگر','','','برای کارهای سنگین، کش خارجی یا پردازش‌های آینده']);
    }

    private static function normalizeOldCompanies(PDO $pdo): void
    {
        try {
            $pdo->exec("UPDATE companies SET company_type = COALESCE(NULLIF(company_type,''), NULLIF(type,'')), ceo_name = COALESCE(NULLIF(ceo_name,''), NULLIF(manager_name,''))");
        } catch (Throwable $e) {}
    }

    private static function seedPortalDefinitions(PDO $pdo): void
    {
        $rows = [
            ['my_tax','سامانه مالیاتی','https://my.tax.gov.ir',10],
            ['tamin','تامین اجتماعی','https://es.tamin.ir',20],
            ['ntsw','سامانه جامع تجارت','https://www.ntsw.ir',30],
            ['epl','گمرک EPL','https://epl.irica.ir',40],
        ];
        $st=$pdo->prepare("INSERT INTO portal_definitions (portal_key,title,url,sort_order,active,created_at,updated_at) VALUES (?,?,?,?,1,NOW(),NOW()) ON DUPLICATE KEY UPDATE title=VALUES(title),url=VALUES(url),sort_order=VALUES(sort_order),active=1,updated_at=NOW()");
        foreach($rows as $r) $st->execute($r);
    }

    private static function migrateLegacySystems(PDO $pdo): void
    {
        // DATA_PERSISTENCE_GUARD_V6_0_3
        // Legacy credential migration is one-time and INSERT-ONLY.
        // It must never overwrite credentials entered by a real user.
        try {
            $st=$pdo->prepare("SELECT `value` FROM settings WHERE `key`='legacy_portal_credentials_imported_v3' LIMIT 1");
            $st->execute();
            if((string)($st->fetchColumn()?:'0')==='1') return;

            $hasWorkspace=false;
            $st=$pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                WHERE TABLE_SCHEMA=DATABASE()
                  AND TABLE_NAME IN ('companies','portal_credentials')
                  AND COLUMN_NAME='workspace_id'");
            $st->execute();
            $hasWorkspace=((int)$st->fetchColumn()>=2);

            $sql=$hasWorkspace
                ? "SELECT s.id,s.company_id,s.service_name,s.url,s.username,s.secret_note,c.workspace_id
                     FROM systems s
                     LEFT JOIN companies c ON c.id=s.company_id"
                : "SELECT id,company_id,service_name,url,username,secret_note,NULL workspace_id FROM systems";
            $rows=$pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

            if($rows){
                $defs=$pdo->query("SELECT portal_key,url FROM portal_definitions WHERE active=1")
                    ->fetchAll(PDO::FETCH_ASSOC);

                if($hasWorkspace){
                    $ins=$pdo->prepare("INSERT INTO portal_credentials
                        (workspace_id,company_id,portal_key,username,password_enc,created_at,updated_at)
                        VALUES (?,?,?,?,?,NOW(),NOW())
                        ON DUPLICATE KEY UPDATE id=id");
                }else{
                    $ins=$pdo->prepare("INSERT INTO portal_credentials
                        (company_id,portal_key,username,password_enc,created_at,updated_at)
                        VALUES (?,?,?,?,NOW(),NOW())
                        ON DUPLICATE KEY UPDATE id=id");
                }

                foreach($rows as $r){
                    if(empty($r['company_id'])) continue;
                    $hay=mb_strtolower(trim(($r['url']??'').' '.($r['service_name']??'')));
                    $key=null;
                    foreach($defs as $d){
                        $host=parse_url($d['url'],PHP_URL_HOST) ?: $d['url'];
                        if($host && str_contains($hay,mb_strtolower($host))){
                            $key=$d['portal_key']; break;
                        }
                    }
                    if(!$key) continue;

                    $pw=trim((string)($r['secret_note']??''));
                    // Old prose/placeholders are not passwords.
                    $placeholder=(bool)preg_match('/(به\s*عهده|کاربر|وارد\s*کنید|ثبت\s*شود|نامشخص|ندارد|بعدا|بعداً)/u',$pw);
                    $stored=(!$placeholder && $pw!=='') ? encrypt_value($pw) : '';

                    if($hasWorkspace){
                        $ins->execute([
                            (int)($r['workspace_id']??0)?:null,
                            (int)$r['company_id'],
                            $key,
                            trim((string)($r['username']??'')),
                            $stored
                        ]);
                    }else{
                        $ins->execute([
                            (int)$r['company_id'],
                            $key,
                            trim((string)($r['username']??'')),
                            $stored
                        ]);
                    }
                }
            }

            $pdo->prepare("INSERT INTO settings (`key`,`value`,`encrypted`,`updated_at`)
                VALUES ('legacy_portal_credentials_imported_v3','1',0,NOW())
                ON DUPLICATE KEY UPDATE `value`='1',updated_at=NOW()")->execute();
        } catch(Throwable $e) {
            // Legacy conversion must never take the application down.
        }
    }

    private static function seedV2(PDO $pdo): void
    {
        // DATA_PERSISTENCE_GUARD_SAMPLE_SEED_V6_0_3
        // Sample data may exist only during the very first empty installation.
        $done='0';
        try{
            $st=$pdo->prepare("SELECT `value` FROM settings WHERE `key`='initial_sample_seed_done_v3' LIMIT 1");
            $st->execute();$done=(string)($st->fetchColumn()?:'0');
        }catch(Throwable $e){}
        if($done==='1') return;

        $hasBusinessData=0;
        foreach(['companies','daily_plans','monthly_plans'] as $table){
            try{$hasBusinessData+=(int)$pdo->query("SELECT COUNT(*) FROM `$table`")->fetchColumn();}catch(Throwable $e){}
        }

        if($hasBusinessData===0){
            $companies = [
                ['موسسه شهر کتاب مرکزی','موسسه','حقوقی','سپیدار'],
                ['فروشگاه شهر کتاب مرکزی','فروشگاه','حقوقی','سپیدار'],
                ['رستگار صنعت پارس','شرکت','حقوقی','سپیدار'],
                ['کیوان الکترونیک ایرانیان','شرکت','حقوقی','سپیدار'],
                ['کیهان توسعه البرز','شرکت','حقوقی','سپیدار'],
            ];
            $st=$pdo->prepare("INSERT INTO companies
                (name,type,company_type,legal_personality,software,notes,active,created_at,updated_at)
                VALUES (?,?,?,?,?,'داده اولیه سامانه حسابداران',1,NOW(),NOW())");
            foreach($companies as $c)$st->execute([$c[0],$c[1],$c[1],$c[2],$c[3]]);
            self::seedMonthlyPlans($pdo);
            self::seedDailyPlans($pdo);
            self::seedCustomFields($pdo);
        }

        try{
            $pdo->prepare("INSERT INTO settings (`key`,`value`,`encrypted`,`updated_at`)
                VALUES ('initial_sample_seed_done_v3','1',0,NOW())
                ON DUPLICATE KEY UPDATE `value`='1',updated_at=NOW()")->execute();
        }catch(Throwable $e){}
    }

    private static function companyIds(PDO $pdo): array
    {
        $rows=$pdo->query("SELECT id,name FROM companies WHERE active=1 ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
        $out=[]; foreach($rows as $r) $out[$r['name']] = (int)$r['id']; return $out;
    }

    private static function seedDailyPlans(PDO $pdo): void
    {
        if ((int)$pdo->query("SELECT COUNT(*) FROM daily_plans")->fetchColumn() > 0) return;
        $ids = self::companyIds($pdo); $today = date('Y-m-d');
        $items = [
            [$today, 'امروز', $ids['موسسه شهر کتاب مرکزی'] ?? null, 'کنترل کارهای سررسید، بانک، صندوق و اسناد باز', 'دفتر / حضوری', 'باز'],
            [$today, 'امروز', $ids['رستگار صنعت پارس'] ?? null, 'مرور لیست کارهای ماهانه و پیگیری مغایرت‌ها', 'دفتر / حضوری', 'باز'],
        ];
        $st=$pdo->prepare("INSERT INTO daily_plans (plan_date,day_name,company_id,work_description,location,status,created_at,updated_at) VALUES (?,?,?,?,?,?,NOW(),NOW())");
        foreach($items as $i) $st->execute($i);
    }

    private static function seedMonthlyPlans(PDO $pdo): void
    {
        if ((int)$pdo->query("SELECT COUNT(*) FROM monthly_plans")->fetchColumn() > 0) return;
        $ids = self::companyIds($pdo);
        $months = ['فروردین','اردیبهشت','خرداد','تیر','مرداد','شهریور','مهر','آبان','آذر','دی','بهمن','اسفند'];
        $types = [
            ['بانک‌ها',25], ['حقوق و دستمزد',25], ['بیمه تامین اجتماعی',30], ['مالیات حقوق',30], ['مودیان',15], ['دفاتر الکترونیکی',20], ['اجاره و حق امتیاز',10], ['حق الزحمه',25]
        ];
        $st=$pdo->prepare("INSERT INTO monthly_plans (company_id,jalali_year,month_name,season,work_type,legal_deadline,status,work_day,created_at,updated_at) VALUES (?,?,?,?,?,?, 'باز', ?, NOW(),NOW())");
        foreach($ids as $cid){
            for($m=1;$m<=12;$m++){
                $season = $m<=3?'بهار':($m<=6?'تابستان':($m<=9?'پاییز':'زمستان'));
                foreach($types as $t){
                    $y=1405; $dueM=$m; $day=$t[1];
                    if (in_array($t[0], ['بیمه تامین اجتماعی','مالیات حقوق'])) { $dueM=$m+1; if($dueM>12){$dueM=1;$y=1406;} }
                    $day=min($day, Jalali::monthLength($y,$dueM));
                    $g=Jalali::parse(sprintf('%04d/%02d/%02d',$y,$dueM,$day));
                    $st->execute([$cid,1405,$months[$m-1],$season,$t[0],$g,'روز '.$day]);
                }
            }
            $annual = [['اظهارنامه عملکرد','تابستان','1406/04/31','آخر تیر/طبق بخشنامه'], ['دفاتر الکترونیکی','زمستان','1405/12/29','پایان سال/طبق بخشنامه']];
            foreach($annual as $a) $st->execute([$cid,1405,'سالانه',$a[1],$a[0],Jalali::parse($a[2]),$a[3]]);
        }
    }

    private static function seedModuleRecords(PDO $pdo): void
    {
        if ((int)$pdo->query("SELECT COUNT(*) FROM module_records")->fetchColumn() > 0) return;
        $ids = self::companyIds($pdo);
        $modules = ['banks'=>'کنترل حساب بانکی و مغایرت‌گیری','payroll'=>'تهیه حقوق و دستمزد','social_insurance'=>'ارسال لیست بیمه تامین اجتماعی','salary_tax'=>'ارسال مالیات حقوق','tax_payers'=>'کنترل کارپوشه مودیان','electronic_books'=>'کنترل دفاتر الکترونیکی','rent_license'=>'کنترل اجاره و حق امتیاز','performance_statement'=>'آماده‌سازی اظهارنامه عملکرد','fees'=>'حق‌الزحمه و صورتحساب خدمات'];
        $st=$pdo->prepare("INSERT INTO module_records (module_key,company_id,title,category,period_label,due_date,status,created_at,updated_at) VALUES (?,?,?,?,?,?, 'باز', NOW(),NOW())");
        foreach($ids as $cid) foreach($modules as $key=>$title) $st->execute([$key,$cid,$title,'کار حسابداری','نمونه اولیه',date('Y-m-d')]);
    }

    private static function seedCustomFields(PDO $pdo): void
    {
        // V4_SAAS_CUSTOM_FIELD_SEED_GUARD
        // SaaS mode: do not create workspace_id=NULL default fields.
        try {
            $st=$pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='custom_fields' AND COLUMN_NAME='workspace_id' LIMIT 1");
            $st->execute();
            if($st->fetchColumn()) return;
        } catch(Throwable $e) {}

        $fields = [
            ['companies','internal_code','کد داخلی','text'], ['monthly_plans','document_link','لینک مدرک','text'], ['module_records','tracking_no','شماره پیگیری','text']
        ];
        $st=$pdo->prepare("INSERT INTO custom_fields (entity_key,field_key,label,field_type,sort_order,active,created_at,updated_at) VALUES (?,?,?,?,100,1,NOW(),NOW()) ON DUPLICATE KEY UPDATE label=VALUES(label)");
        foreach($fields as $f) $st->execute($f);
    }
}
