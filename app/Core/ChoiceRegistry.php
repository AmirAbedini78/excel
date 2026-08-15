<?php
final class ChoiceRegistry
{
    private static array $defs = [
        'company_type'=>[
            'title'=>'نوع شرکت','description'=>'نوع فعالیت/ماهیت شرکت در اطلاعات شرکت‌ها',
            'table'=>'companies','column'=>'company_type',
            'defaults'=>['موسسه','شرکت','فروشگاه','شخص حقیقی'],
        ],
        'legal_personality'=>[
            'title'=>'شخصیت','description'=>'شخصیت حقوقی یا حقیقی شرکت',
            'table'=>'companies','column'=>'legal_personality',
            'defaults'=>['حقوقی','حقیقی'],
        ],
        'accounting_software'=>[
            'title'=>'نرم‌افزار حسابداری','description'=>'نرم‌افزار مورد استفاده شرکت',
            'table'=>'companies','column'=>'software',
            'defaults'=>['سپیدار','هلو','راهکاران'],
        ],
        'monthly_month'=>[
            'title'=>'ماه برنامه ماهانه','description'=>'مقادیر قابل انتخاب برای ستون ماه',
            'table'=>'monthly_plans','column'=>'month_name',
            'defaults'=>['فروردین','اردیبهشت','خرداد','تیر','مرداد','شهریور','مهر','آبان','آذر','دی','بهمن','اسفند','سالانه'],
        ],
        'monthly_season'=>[
            'title'=>'فصل برنامه ماهانه','description'=>'مقادیر قابل انتخاب برای ستون فصل',
            'table'=>'monthly_plans','column'=>'season',
            'defaults'=>['بهار','تابستان','پاییز','زمستان','سالانه'],
        ],
        'monthly_work_type'=>[
            'title'=>'نوع کار برنامه ماهانه','description'=>'نوع فعالیت حسابداری/مالی در برنامه ماهانه و کانبان',
            'table'=>'monthly_plans','column'=>'work_type',
            'defaults'=>['بانک‌ها','حقوق و دستمزد','بیمه تامین اجتماعی','مالیات حقوق','مودیان','دفاتر الکترونیکی','اجاره و حق امتیاز','اظهارنامه عملکرد','حق الزحمه'],
        ],
        'monthly_status'=>[
            'title'=>'وضعیت برنامه ماهانه','description'=>'وضعیت کار؛ ستون‌های کانبان نیز از همین لیست ساخته می‌شوند',
            'table'=>'monthly_plans','column'=>'status',
            'defaults'=>['باز','در حال انجام','منتظر مدارک','معوق','انجام شده','لغو شده'],
        ],
    ];

    public static function ensureSchema(): void
    {
        static $done=false;
        if($done)return;

        // V5_FAST_CHOICE_SCHEMA
        if(class_exists('RuntimeCache') && RuntimeCache::schemaReady(RuntimeCache::SCHEMA_VERSION)){
            if(Auth::check()){
                $wid=Tenant::id();
                if($wid>0){
                    try{
                        $st=pdo()->prepare("SELECT 1 FROM choice_sets WHERE workspace_id=? LIMIT 1");
                        $st->execute([$wid]);
                        if(!$st->fetchColumn())self::seedWorkspace($wid);
                    }catch(Throwable $e){}
                }
            }
            $done=true;
            return;
        }

        $pdo=pdo();
        $pdo->exec("CREATE TABLE IF NOT EXISTS choice_sets (
            id INT AUTO_INCREMENT PRIMARY KEY,
            workspace_id INT NOT NULL,
            set_key VARCHAR(100) NOT NULL,
            title VARCHAR(190) NOT NULL,
            description VARCHAR(500) NULL,
            active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NULL,
            updated_at DATETIME NULL,
            UNIQUE KEY uniq_choice_set_workspace (workspace_id,set_key),
            INDEX idx_choice_sets_workspace (workspace_id,active)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $pdo->exec("CREATE TABLE IF NOT EXISTS choice_values (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            workspace_id INT NOT NULL,
            set_id INT NOT NULL,
            value VARCHAR(255) NOT NULL,
            sort_order INT NOT NULL DEFAULT 100,
            active TINYINT(1) NOT NULL DEFAULT 1,
            created_by INT NULL,
            created_at DATETIME NULL,
            updated_at DATETIME NULL,
            UNIQUE KEY uniq_choice_value (set_id,value),
            INDEX idx_choice_value_set (workspace_id,set_id,active,sort_order)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $pdo->prepare("INSERT INTO workspace_permissions (permission_key,title,group_key,sort_order)
            VALUES ('choices.manage','مدیریت مقادیر انتخابی','choices',95)
            ON DUPLICATE KEY UPDATE title=VALUES(title),group_key=VALUES(group_key),sort_order=VALUES(sort_order)")->execute();

        foreach($pdo->query("SELECT id FROM workspaces WHERE status='active' ORDER BY id")->fetchAll() as $w){
            self::seedWorkspace((int)$w['id']);
        }
        $done=true;
    }

    private static function seedWorkspace(int $wid): void
    {
        $pdo=pdo();
        $st=$pdo->prepare("SELECT COUNT(*) FROM choice_sets WHERE workspace_id=?");
        $st->execute([$wid]);$firstSeed=((int)$st->fetchColumn()===0);

        $setIns=$pdo->prepare("INSERT INTO choice_sets (workspace_id,set_key,title,description,active,created_at,updated_at)
            VALUES (?,?,?,?,1,NOW(),NOW())
            ON DUPLICATE KEY UPDATE title=VALUES(title),description=VALUES(description),updated_at=NOW()");
        $setId=$pdo->prepare("SELECT id FROM choice_sets WHERE workspace_id=? AND set_key=? LIMIT 1");
        $valueIns=$pdo->prepare("INSERT IGNORE INTO choice_values (workspace_id,set_id,value,sort_order,active,created_at,updated_at)
            VALUES (?,?,?,?,1,NOW(),NOW())");

        foreach(self::$defs as $key=>$def){
            $setIns->execute([$wid,$key,$def['title'],$def['description']]);
            $setId->execute([$wid,$key]);$sid=(int)$setId->fetchColumn();
            $sort=10;
            foreach($def['defaults'] as $value){
                $valueIns->execute([$wid,$sid,$value,$sort]);
                $sort+=10;
            }
        }

        if($firstSeed){
            $st=$pdo->prepare("SELECT id FROM workspace_permissions WHERE permission_key='choices.manage' LIMIT 1");
            $st->execute();$pid=(int)$st->fetchColumn();
            if($pid){
                $roles=$pdo->prepare("SELECT id FROM workspace_roles WHERE workspace_id=? AND role_key IN ('owner','workspace_admin')");
                $roles->execute([$wid]);
                $ins=$pdo->prepare("INSERT IGNORE INTO workspace_role_permissions (role_id,permission_id) VALUES (?,?)");
                foreach($roles->fetchAll() as $r)$ins->execute([(int)$r['id'],$pid]);
            }
        }
    }

    public static function definitions(): array { return self::$defs; }

    public static function sets(): array
    {
        self::ensureSchema();
        $st=pdo()->prepare("SELECT s.*,
            (SELECT COUNT(*) FROM choice_values v WHERE v.set_id=s.id AND v.active=1) active_count,
            (SELECT COUNT(*) FROM choice_values v WHERE v.set_id=s.id) total_count
            FROM choice_sets s WHERE s.workspace_id=? AND s.active=1 ORDER BY s.id");
        $st->execute([Tenant::id()]);return $st->fetchAll();
    }

    public static function set(string $key): ?array
    {
        self::ensureSchema();
        $st=pdo()->prepare("SELECT * FROM choice_sets WHERE workspace_id=? AND set_key=? AND active=1 LIMIT 1");
        $st->execute([Tenant::id(),$key]);$r=$st->fetch();return $r?:null;
    }

    public static function valuesDetailed(string $key,bool $includeInactive=true): array
    {
        $set=self::set($key);if(!$set)return[];
        $sql="SELECT * FROM choice_values WHERE workspace_id=? AND set_id=?";
        if(!$includeInactive)$sql.=" AND active=1";
        $sql.=" ORDER BY sort_order,id";
        $st=pdo()->prepare($sql);$st->execute([Tenant::id(),(int)$set['id']]);$rows=$st->fetchAll();

        $usage=[];
        if(isset(self::$defs[$key])){
            $def=self::$defs[$key];$table=$def['table'];$column=$def['column'];
            try{
                $st=pdo()->prepare("SELECT `$column` v,COUNT(*) c FROM `$table` WHERE workspace_id=? AND `$column` IS NOT NULL GROUP BY `$column`");
                $st->execute([Tenant::id()]);
                foreach($st->fetchAll() as $u)$usage[(string)$u['v']]=(int)$u['c'];
            }catch(Throwable $e){}
        }
        foreach($rows as &$r)$r['usage_count']=$usage[(string)$r['value']]??0;
        return $rows;
    }

    public static function labels(string $key,string $selected='',bool $includeStored=false): array
    {
        static $local=[];
        $wid=Tenant::id();$localKey=$wid.'|'.$key.'|'.($includeStored?'1':'0');
        if(!array_key_exists($localKey,$local)){
            self::ensureSchema();$out=[];
            $st=pdo()->prepare("SELECT v.value FROM choice_values v JOIN choice_sets s ON s.id=v.set_id
                WHERE v.workspace_id=? AND s.workspace_id=? AND s.set_key=? AND s.active=1 AND v.active=1
                ORDER BY v.sort_order,v.id");
            $st->execute([$wid,$wid,$key]);
            foreach($st->fetchAll() as $r)$out[]=(string)$r['value'];

            if($includeStored && isset(self::$defs[$key])){
                $def=self::$defs[$key];$table=$def['table'];$column=$def['column'];
                try{
                    $st=pdo()->prepare("SELECT DISTINCT `$column` v FROM `$table`
                        WHERE workspace_id=? AND `$column` IS NOT NULL AND TRIM(`$column`)<>'' ORDER BY `$column`");
                    $st->execute([$wid]);
                    foreach($st->fetchAll() as $r)if(!in_array((string)$r['v'],$out,true))$out[]=(string)$r['v'];
                }catch(Throwable $e){}
            }
            $local[$localKey]=$out;
        }
        $out=$local[$localKey];
        $selected=trim($selected);
        if($selected!==''&&!in_array($selected,$out,true))$out[]=$selected;
        return $out;
    }

    public static function htmlOptions(string $key,string $selected='',bool $all=false,string $allLabel='',bool $includeStored=false): string
    {
        $html=$all?'<option value="">'.h($allLabel?:'همه').'</option>':'';
        foreach(self::labels($key,$selected,$includeStored) as $v){
            $html.='<option value="'.h($v).'" '.((string)$selected===(string)$v?'selected':'').'>'.h($v).'</option>';
        }
        return $html;
    }

    public static function workflowStatuses(): array
    {
        return self::labels('monthly_status','',true);
    }

    public static function addValue(string $setKey,string $value,int $sortOrder=0): array
    {
        $value=trim($value);
        if($value==='')throw new RuntimeException('مقدار نمی‌تواند خالی باشد.');
        if(mb_strlen($value)>255)throw new RuntimeException('مقدار بیش از حد طولانی است.');
        $set=self::set($setKey);if(!$set)throw new RuntimeException('گروه مقادیر معتبر نیست.');

        if($sortOrder<=0){
            $st=pdo()->prepare("SELECT COALESCE(MAX(sort_order),0)+10 FROM choice_values WHERE workspace_id=? AND set_id=?");
            $st->execute([Tenant::id(),(int)$set['id']]);$sortOrder=(int)$st->fetchColumn();
        }

        $st=pdo()->prepare("SELECT id FROM choice_values WHERE set_id=? AND value=? LIMIT 1");
        $st->execute([(int)$set['id'],$value]);$existing=(int)$st->fetchColumn();
        if($existing){
            pdo()->prepare("UPDATE choice_values SET active=1,sort_order=?,updated_at=NOW() WHERE id=? AND workspace_id=?")
                ->execute([$sortOrder,$existing,Tenant::id()]);
            return self::valueById($existing)??[];
        }

        pdo()->prepare("INSERT INTO choice_values (workspace_id,set_id,value,sort_order,active,created_by,created_at,updated_at)
            VALUES (?,?,?,?,1,?,NOW(),NOW())")
            ->execute([Tenant::id(),(int)$set['id'],$value,$sortOrder,(int)(Auth::user()['id']??0)?:null]);
        return self::valueById((int)pdo()->lastInsertId())??[];
    }

    public static function toggle(int $id,bool $active): array
    {
        $r=self::valueById($id);if(!$r)throw new RuntimeException('مقدار پیدا نشد.');
        if(!$active){
            $st=pdo()->prepare("SELECT COUNT(*) FROM choice_values WHERE workspace_id=? AND set_id=? AND active=1");
            $st->execute([Tenant::id(),(int)$r['set_id']]);
            if((int)$st->fetchColumn()<=1)throw new RuntimeException('حداقل یک مقدار فعال باید باقی بماند.');
        }
        pdo()->prepare("UPDATE choice_values SET active=?,updated_at=NOW() WHERE id=? AND workspace_id=?")
            ->execute([$active?1:0,$id,Tenant::id()]);
        return self::valueById($id)??[];
    }

    public static function move(int $id,string $direction): array
    {
        $r=self::valueById($id);if(!$r)throw new RuntimeException('مقدار پیدا نشد.');
        $delta=$direction==='up'?-15:15;
        pdo()->prepare("UPDATE choice_values SET sort_order=GREATEST(0,sort_order+?),updated_at=NOW() WHERE id=? AND workspace_id=?")
            ->execute([$delta,$id,Tenant::id()]);
        return self::valueById($id)??[];
    }

    public static function restoreDefaults(string $setKey): void
    {
        $set=self::set($setKey);if(!$set||!isset(self::$defs[$setKey]))throw new RuntimeException('گروه معتبر نیست.');
        $sort=10;
        foreach(self::$defs[$setKey]['defaults'] as $v){
            $st=pdo()->prepare("SELECT id FROM choice_values WHERE set_id=? AND value=? LIMIT 1");
            $st->execute([(int)$set['id'],$v]);$id=(int)$st->fetchColumn();
            if($id){
                pdo()->prepare("UPDATE choice_values SET active=1,sort_order=?,updated_at=NOW() WHERE id=? AND workspace_id=?")
                    ->execute([$sort,$id,Tenant::id()]);
            }else{
                pdo()->prepare("INSERT INTO choice_values (workspace_id,set_id,value,sort_order,active,created_at,updated_at)
                    VALUES (?,?,?,?,1,NOW(),NOW())")->execute([Tenant::id(),(int)$set['id'],$v,$sort]);
            }
            $sort+=10;
        }
    }

    public static function valueById(int $id): ?array
    {
        $st=pdo()->prepare("SELECT v.*,s.set_key,s.title set_title FROM choice_values v
            JOIN choice_sets s ON s.id=v.set_id
            WHERE v.id=? AND v.workspace_id=? AND s.workspace_id=? LIMIT 1");
        $st->execute([$id,Tenant::id(),Tenant::id()]);$r=$st->fetch();return$r?:null;
    }

    public static function usageCount(string $setKey,string $value): int
    {
        if(!isset(self::$defs[$setKey]))return 0;
        $def=self::$defs[$setKey];$table=$def['table'];$column=$def['column'];
        try{
            $st=pdo()->prepare("SELECT COUNT(*) FROM `$table` WHERE workspace_id=? AND `$column`=?");
            $st->execute([Tenant::id(),$value]);return (int)$st->fetchColumn();
        }catch(Throwable $e){return 0;}
    }
}
