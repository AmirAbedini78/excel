<?php
class Schema
{
    public static function migrate(PDO $pdo): void
    {
        $sql = [];
        $sql[] = "CREATE TABLE IF NOT EXISTS users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(150) NOT NULL,
            email VARCHAR(190) NOT NULL UNIQUE,
            password_hash VARCHAR(255) NULL,
            google_id VARCHAR(190) NULL UNIQUE,
            avatar VARCHAR(500) NULL,
            role ENUM('admin','accountant','viewer') NOT NULL DEFAULT 'accountant',
            status ENUM('active','disabled') NOT NULL DEFAULT 'active',
            created_at DATETIME NULL, updated_at DATETIME NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        $sql[] = "CREATE TABLE IF NOT EXISTS companies (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(190) NOT NULL UNIQUE,
            type VARCHAR(100) NULL, attendance VARCHAR(255) NULL, software VARCHAR(120) NULL,
            manager_name VARCHAR(150) NULL, financial_manager VARCHAR(150) NULL, phone VARCHAR(100) NULL,
            address TEXT NULL, tax_username VARCHAR(190) NULL, insurance_code VARCHAR(190) NULL, notes TEXT NULL,
            active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NULL, updated_at DATETIME NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        $sql[] = "CREATE TABLE IF NOT EXISTS weekly_schedules (
            id INT AUTO_INCREMENT PRIMARY KEY,
            weekday VARCHAR(40) NOT NULL, shift_label VARCHAR(100) NULL, company_id INT NULL,
            attendance_type VARCHAR(100) NULL, notes TEXT NULL,
            FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        $sql[] = "CREATE TABLE IF NOT EXISTS tasks (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        $sql[] = "CREATE TABLE IF NOT EXISTS followups (
            id INT AUTO_INCREMENT PRIMARY KEY,
            company_id INT NULL, requester VARCHAR(150) NULL, subject VARCHAR(255) NOT NULL, next_action VARCHAR(255) NULL,
            followup_date DATE NULL, priority VARCHAR(30) NOT NULL DEFAULT 'متوسط', status VARCHAR(50) NOT NULL DEFAULT 'باز', notes TEXT NULL,
            created_at DATETIME NULL, updated_at DATETIME NULL,
            INDEX idx_follow_date (followup_date,status), FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        $sql[] = "CREATE TABLE IF NOT EXISTS bank_reconciliations (
            id INT AUTO_INCREMENT PRIMARY KEY,
            company_id INT NULL, bank_name VARCHAR(150) NULL, period_label VARCHAR(100) NULL, discovered_at DATE NULL,
            amount DECIMAL(18,2) NULL, mismatch_type VARCHAR(100) NULL, description TEXT NULL, correction_action TEXT NULL,
            status VARCHAR(50) NOT NULL DEFAULT 'باز', responsible VARCHAR(150) NULL, target_date DATE NULL, notes TEXT NULL,
            created_at DATETIME NULL, updated_at DATETIME NULL,
            FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        $sql[] = "CREATE TABLE IF NOT EXISTS systems (
            id INT AUTO_INCREMENT PRIMARY KEY,
            company_id INT NULL, service_name VARCHAR(150) NOT NULL, url VARCHAR(500) NULL, username VARCHAR(190) NULL,
            related_code VARCHAR(190) NULL, secret_note TEXT NULL, last_checked_at DATE NULL, notes TEXT NULL,
            created_at DATETIME NULL, updated_at DATETIME NULL,
            FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        $sql[] = "CREATE TABLE IF NOT EXISTS error_notes (
            id INT AUTO_INCREMENT PRIMARY KEY,
            company_id INT NULL, happened_at DATE NULL, process VARCHAR(150) NULL, risk TEXT NULL, root_cause TEXT NULL,
            solution TEXT NULL, document_no VARCHAR(120) NULL, prevention TEXT NULL, status VARCHAR(50) NULL,
            created_at DATETIME NULL, updated_at DATETIME NULL,
            FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        $sql[] = "CREATE TABLE IF NOT EXISTS settings (
            `key` VARCHAR(120) PRIMARY KEY, `value` MEDIUMTEXT NULL, encrypted TINYINT(1) NOT NULL DEFAULT 0, updated_at DATETIME NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        $sql[] = "CREATE TABLE IF NOT EXISTS notification_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            task_id INT NULL, channel VARCHAR(20) NOT NULL, recipient VARCHAR(255) NULL, message TEXT NULL,
            status VARCHAR(30) NOT NULL, response MEDIUMTEXT NULL, created_at DATETIME NULL,
            INDEX idx_task_channel_date (task_id,channel,created_at), FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        $sql[] = "CREATE TABLE IF NOT EXISTS imports (
            id INT AUTO_INCREMENT PRIMARY KEY, filename VARCHAR(255) NULL, stats TEXT NULL, user_id INT NULL, created_at DATETIME NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        foreach ($sql as $q) $pdo->exec($q);
    }

    public static function createAdmin(PDO $pdo, string $name, string $email, string $password): void
    {
        $st = $pdo->prepare("INSERT INTO users (name,email,password_hash,role,status,created_at,updated_at) VALUES (?,?,?,?,?,NOW(),NOW()) ON DUPLICATE KEY UPDATE name=VALUES(name), password_hash=VALUES(password_hash), role='admin', status='active', updated_at=NOW()");
        $st->execute([$name, mb_strtolower(trim($email)), password_hash($password, PASSWORD_DEFAULT), 'admin', 'active']);
    }

    public static function seed(PDO $pdo): void
    {
        $count = (int)$pdo->query("SELECT COUNT(*) FROM companies")->fetchColumn();
        if ($count > 0) return;
        $companies = [
            ['موسسه شهر کتاب مرکزی','موسسه','شنبه کامل، دوشنبه نصف‌روز'],
            ['فروشگاه شهر کتاب مرکزی','فروشگاه','چهارشنبه، دوشنبه نصف‌روز'],
            ['رستگار صنعت پارس','شرکت','یکشنبه و سه‌شنبه'],
            ['کیوان الکترونیک ایرانیان','شرکت','پنجشنبه'],
            ['کیهان توسعه البرز','شرکت','قابل تنظیم'],
        ];
        $ids=[];
        $st=$pdo->prepare("INSERT INTO companies (name,type,attendance,notes,active,created_at,updated_at) VALUES (?,?,?,?,1,NOW(),NOW())");
        foreach ($companies as $c) { $st->execute([$c[0],$c[1],$c[2],'برنامه اولیه بر اساس فایل اکسل/متنی']); $ids[$c[0]]=(int)$pdo->lastInsertId(); }
        $schedules = [
            ['شنبه','کامل','موسسه شهر کتاب مرکزی','حضور ثابت','بانک، صندوق، اسناد، پیگیری‌های باز و کارهای سررسید'],
            ['یکشنبه','کامل','رستگار صنعت پارس','حضور ثابت','ثبت اسناد، خرید/فروش، مغایرت بانکی'],
            ['دوشنبه','صبح / نصف‌روز','موسسه شهر کتاب مرکزی','حضور ثابت','کارهای فوری و سررسیدهای قانونی'],
            ['دوشنبه','عصر / نصف‌روز','فروشگاه شهر کتاب مرکزی','حضور ثابت','کنترل فروش، صندوق، بانک، چک‌ها'],
            ['سه‌شنبه','کامل','رستگار صنعت پارس','حضور ثابت','پیگیری گزارش‌ها و بستن موارد باز'],
            ['چهارشنبه','کامل','فروشگاه شهر کتاب مرکزی','حضور ثابت','کنترل فروشگاه، صندوق، اسناد و بایگانی'],
            ['پنجشنبه','کامل','کیوان الکترونیک ایرانیان','حضور ثابت','کنترل هفته، سررسیدها و گزارش مدیریت'],
        ];
        $st=$pdo->prepare("INSERT INTO weekly_schedules (weekday,shift_label,company_id,attendance_type,notes) VALUES (?,?,?,?,?)");
        foreach ($schedules as $s) $st->execute([$s[0],$s[1],$ids[$s[2]] ?? null,$s[3],$s[4]]);
        self::seedTasks($pdo,$ids);
        self::seedSystems($pdo,$ids);
        $defaults = [
            'smtp_host'=>'smtp.gmail.com','smtp_port'=>'587','smtp_encryption'=>'tls','mail_from_name'=>'Accounting Manager 1405',
            'ghasedak_line_number'=>'','notifications_email_to'=>'','notifications_sms_to'=>'','allow_google_signup'=>'1',
            'cron_secret'=>bin2hex(random_bytes(16))
        ];
        $st=$pdo->prepare("INSERT INTO settings (`key`,`value`,`encrypted`,`updated_at`) VALUES (?,?,0,NOW()) ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)");
        foreach ($defaults as $k=>$v) $st->execute([$k,$v]);
    }

    private static function seedTasks(PDO $pdo, array $ids): void
    {
        $templates = [
            ['پایان ماه','مغایرت‌گیری بانکی ماه {month}','ماهانه','{next}/05',5,'بالا'],
            ['پایان ماه','کنترل نهایی اسناد ماه {month}','ماهانه','{next}/07',5,'بالا'],
            ['پایان ماه','بستن حساب‌های ماه {month} و کنترل مانده‌ها','ماهانه','{next}/10',7,'بالا'],
            ['گزارش','تهیه گزارش مدیریت ماه {month}','ماهانه','{next}/10',5,'متوسط'],
            ['حقوق و دستمزد','جمع‌آوری اطلاعات حقوق و دستمزد ماه {month}','ماهانه','{cur}/25',3,'بالا'],
            ['حقوق و دستمزد','ارسال مالیات حقوق ماه {month}','ماهانه','{next}/30',7,'خیلی بالا'],
            ['بیمه','ارسال لیست بیمه ماه {month}','ماهانه','{next}/30',7,'خیلی بالا'],
            ['کنترل','کنترل چک‌ها، دریافتنی‌ها و پرداختنی‌های ماه {month}','ماهانه','{cur}/28',5,'بالا'],
        ];
        $quarterTemplates = [
            ['مالیات/ارزش افزوده','کنترل و آماده‌سازی ارزش افزوده فصل {q}','فصلی','{due}',15,'خیلی بالا'],
            ['معاملات فصلی','کنترل و ارسال صورت معاملات فصلی فصل {q}','فصلی','{due2}',15,'خیلی بالا'],
            ['گزارش','گزارش فصلی مدیریت و مرور ریسک‌ها فصل {q}','فصلی','{due}',10,'بالا'],
        ];
        $months = ['فروردین','اردیبهشت','خرداد','تیر','مرداد','شهریور','مهر','آبان','آذر','دی','بهمن','اسفند'];
        $st=$pdo->prepare("INSERT INTO tasks (company_id,code,category,title,frequency,period_label,due_date,reminder_days,priority,status,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,'باز',NOW(),NOW())");
        $counter=1;
        foreach ($ids as $company=>$cid) {
            for ($m=1;$m<=12;$m++) {
                foreach ($templates as $t) {
                    $dateStr = self::resolveJalaliDate($t[3], $m);
                    $g = self::jalaliToSql($dateStr);
                    $title = str_replace('{month}', $months[$m-1], $t[1]);
                    $st->execute([$cid, sprintf('1405-%04d',$counter++), $t[0], $title, $t[2], $months[$m-1], $g, $t[4], $t[5]]);
                }
            }
            $quarters = [1=>['بهار','1405/04/20','1405/05/15'],2=>['تابستان','1405/07/20','1405/08/15'],3=>['پاییز','1405/10/20','1405/11/15'],4=>['زمستان','1406/01/25','1406/02/15']];
            foreach ($quarters as $q=>$dts) foreach ($quarterTemplates as $t) {
                $dateStr = str_replace(['{due}','{due2}'],[$dts[1],$dts[2]],$t[3]);
                $title = str_replace('{q}', $dts[0], $t[1]);
                $st->execute([$cid, sprintf('1405-%04d',$counter++), $t[0], $title, $t[2], $dts[0], self::jalaliToSql($dateStr), $t[4], $t[5]]);
            }
            $yearly = [
                ['اظهارنامه عملکرد','آماده‌سازی اظهارنامه عملکرد سال ۱۴۰۵','سالانه','1406/04/15',30,'خیلی بالا'],
                ['بستن سال','بستن حساب‌ها و کنترل نهایی سال مالی ۱۴۰۵','سالانه','1406/01/20',20,'خیلی بالا'],
                ['بایگانی','بایگانی کامل اسناد و مدارک سال ۱۴۰۵','سالانه','1406/02/01',10,'متوسط'],
            ];
            foreach ($yearly as $t) $st->execute([$cid, sprintf('1405-%04d',$counter++),$t[0],$t[1],$t[2],'سال ۱۴۰۵',self::jalaliToSql($t[3]),$t[4],$t[5]]);
        }
    }
    private static function resolveJalaliDate(string $pattern, int $m): string
    {
        $curM = $m; $curY=1405; $nextM=$m+1; $nextY=1405; if ($nextM>12) { $nextM=1; $nextY=1406; }
        $s = str_replace('{cur}', sprintf('%04d/%02d',$curY,$curM), $pattern);
        $s = str_replace('{next}', sprintf('%04d/%02d',$nextY,$nextM), $s);
        [$y,$mo,$d] = array_map('intval', explode('/', $s));
        $d = min($d, Jalali::monthLength($y,$mo));
        return sprintf('%04d/%02d/%02d',$y,$mo,$d);
    }
    private static function jalaliToSql(string $j): ?string { return Jalali::parse($j); }
    private static function seedSystems(PDO $pdo, array $ids): void
    {
        $services = [
            ['سامانه مؤدیان/مالیات','https://my.tax.gov.ir'],
            ['مالیات حقوق','https://salary.tax.gov.ir'],
            ['تأمین اجتماعی','https://eservices.tamin.ir'],
            ['ارزش افزوده','https://my.tax.gov.ir'],
        ];
        $st=$pdo->prepare("INSERT INTO systems (company_id,service_name,url,secret_note,notes,created_at,updated_at) VALUES (?,?,?,?,?,NOW(),NOW())");
        foreach ($ids as $cid) foreach ($services as $srv) $st->execute([$cid,$srv[0],$srv[1],'ترجیحاً رمز واقعی را بدون رمزنگاری سازمانی ذخیره نکنید.','']);
    }
}
