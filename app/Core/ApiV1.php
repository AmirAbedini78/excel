<?php
final class ApiV1Auth
{
    public static function ensureSchema(): void
    {
        static $done=false;
        if($done) return;
        $pdo=pdo();
        $pdo->exec("CREATE TABLE IF NOT EXISTS api_access_tokens (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NULL,
            name VARCHAR(120) NOT NULL,
            token_prefix VARCHAR(32) NOT NULL,
            token_hash CHAR(64) NOT NULL UNIQUE,
            scopes VARCHAR(500) NOT NULL,
            expires_at DATETIME NULL,
            last_used_at DATETIME NULL,
            last_ip VARCHAR(80) NULL,
            created_at DATETIME NOT NULL,
            revoked_at DATETIME NULL,
            INDEX idx_api_token_user (user_id),
            INDEX idx_api_token_active (revoked_at,expires_at),
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $pdo->exec("CREATE TABLE IF NOT EXISTS api_rate_buckets (
            token_id INT NOT NULL,
            bucket_minute DATETIME NOT NULL,
            request_count INT NOT NULL DEFAULT 0,
            PRIMARY KEY (token_id,bucket_minute),
            INDEX idx_api_rate_minute (bucket_minute),
            FOREIGN KEY (token_id) REFERENCES api_access_tokens(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $done=true;
    }

    public static function createToken(int $userId,string $name,array $scopes,?string $expiresAt=null): array
    {
        self::ensureSchema();
        $name=trim($name);
        if($name==='') throw new RuntimeException('نام توکن الزامی است.');
        $allowed=['read','write','secrets','settings'];
        $scopes=array_values(array_unique(array_values(array_intersect($allowed,$scopes))));
        if(!$scopes) $scopes=['read'];
        $plain='acct_live_'.self::b64url(random_bytes(32));
        $hash=hash('sha256',$plain);
        $prefix=substr($plain,0,20);
        $st=pdo()->prepare("INSERT INTO api_access_tokens (user_id,name,token_prefix,token_hash,scopes,expires_at,created_at) VALUES (?,?,?,?,?,?,NOW())");
        $st->execute([$userId,$name,$prefix,$hash,json_encode($scopes,JSON_UNESCAPED_UNICODE),$expiresAt]);
        return ['id'=>(int)pdo()->lastInsertId(),'token'=>$plain,'prefix'=>$prefix,'scopes'=>$scopes,'expires_at'=>$expiresAt];
    }

    public static function listTokens(): array
    {
        self::ensureSchema();
        $rows=pdo()->query("SELECT t.id,t.name,t.token_prefix,t.scopes,t.expires_at,t.last_used_at,t.last_ip,t.created_at,t.revoked_at,u.name user_name,u.email user_email
            FROM api_access_tokens t LEFT JOIN users u ON u.id=t.user_id ORDER BY t.id DESC")->fetchAll();
        foreach($rows as &$r){
            $d=json_decode((string)$r['scopes'],true);
            $r['scopes']=is_array($d)?$d:[];
        }
        return $rows;
    }

    public static function revoke(int $id): void
    {
        self::ensureSchema();
        pdo()->prepare("UPDATE api_access_tokens SET revoked_at=COALESCE(revoked_at,NOW()) WHERE id=?")->execute([$id]);
    }

    public static function authenticate(): array
    {
        self::ensureSchema();
        if(setting('api_enabled','1')!=='1') self::error('API is disabled',503,'api_disabled');

        $auth=$_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        if(!preg_match('/^Bearer\s+(.+)$/i',trim($auth),$m)) self::error('Bearer token required',401,'unauthorized');
        $plain=trim($m[1]);
        if(!str_starts_with($plain,'acct_live_')) self::error('Invalid API token',401,'unauthorized');

        $hash=hash('sha256',$plain);
        $st=pdo()->prepare("SELECT t.*,u.name user_name,u.email user_email,u.role user_role,u.status user_status
            FROM api_access_tokens t LEFT JOIN users u ON u.id=t.user_id
            WHERE t.token_hash=? LIMIT 1");
        $st->execute([$hash]);
        $token=$st->fetch();
        if(!$token || $token['revoked_at']) self::error('Invalid or revoked API token',401,'unauthorized');
        if($token['expires_at'] && strtotime($token['expires_at'])<time()) self::error('API token expired',401,'token_expired');
        if($token['user_id'] && $token['user_status']!=='active') self::error('Token owner is disabled',401,'user_disabled');

        $scopes=json_decode((string)$token['scopes'],true);
        $token['scopes']=is_array($scopes)?$scopes:[];
        self::rateLimit((int)$token['id']);

        pdo()->prepare("UPDATE api_access_tokens SET last_used_at=NOW(),last_ip=? WHERE id=?")
            ->execute([$_SERVER['REMOTE_ADDR']??'',(int)$token['id']]);
        return $token;
    }

    public static function requireScope(array $token,string $scope): void
    {
        if(!in_array($scope,$token['scopes'],true)) self::error('Missing scope: '.$scope,403,'insufficient_scope');
    }

    public static function requireAdmin(array $token): void
    {
        if(($token['user_role']??'')!=='admin') self::error('Admin role required',403,'admin_required');
    }

    public static function cors(): void
    {
        $origin=$_SERVER['HTTP_ORIGIN']??'';
        $allowed=array_values(array_filter(array_map('trim',preg_split('/[\r\n,]+/',(string)setting('api_cors_origins','')))));
        if($origin && ($allowed && (in_array('*',$allowed,true)||in_array($origin,$allowed,true)))){
            header('Access-Control-Allow-Origin: '.(in_array('*',$allowed,true)?'*':$origin));
            header('Vary: Origin');
            header('Access-Control-Allow-Headers: Authorization, Content-Type, Accept');
            header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
            header('Access-Control-Max-Age: 600');
        }
        if($_SERVER['REQUEST_METHOD']==='OPTIONS'){
            http_response_code(204);
            exit;
        }
    }

    public static function jsonBody(): array
    {
        $raw=file_get_contents('php://input');
        if($raw==='') return [];
        $d=json_decode($raw,true);
        if(!is_array($d)) self::error('JSON body is invalid',400,'invalid_json');
        return $d;
    }

    public static function respond($data,int $status=200,array $meta=[]): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('X-API-Version: 1');
        $out=['ok'=>$status<400,'data'=>$data];
        if($meta) $out['meta']=$meta;
        echo json_encode($out,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        exit;
    }

    public static function error(string $message,int $status=400,string $code='bad_request',array $details=[]): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('X-API-Version: 1');
        echo json_encode(['ok'=>false,'error'=>['code'=>$code,'message'=>$message,'details'=>$details]],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        exit;
    }

    private static function rateLimit(int $tokenId): void
    {
        $limit=max(10,min(5000,(int)setting('api_rate_limit_per_minute','120')));
        $bucket=date('Y-m-d H:i:00');
        pdo()->prepare("INSERT INTO api_rate_buckets (token_id,bucket_minute,request_count) VALUES (?,?,1)
            ON DUPLICATE KEY UPDATE request_count=request_count+1")->execute([$tokenId,$bucket]);
        $st=pdo()->prepare("SELECT request_count FROM api_rate_buckets WHERE token_id=? AND bucket_minute=?");
        $st->execute([$tokenId,$bucket]);
        $count=(int)$st->fetchColumn();
        header('X-RateLimit-Limit: '.$limit);
        header('X-RateLimit-Remaining: '.max(0,$limit-$count));
        if($count>$limit) self::error('Rate limit exceeded',429,'rate_limited');
        if(random_int(1,100)===1){
            pdo()->exec("DELETE FROM api_rate_buckets WHERE bucket_minute < DATE_SUB(NOW(),INTERVAL 2 DAY)");
        }
    }

    private static function b64url(string $bin): string
    {
        return rtrim(strtr(base64_encode($bin),'+/','-_'),'=');
    }
}

final class ApiV1
{
    private array $token;
    private string $method;
    private string $resource;
    private int $id;

    public function __construct(array $token)
    {
        $this->token=$token;
        $this->method=strtoupper($_SERVER['REQUEST_METHOD']??'GET');
        $this->resource=$this->normalizeResource((string)($_GET['resource']??'meta'));
        $this->id=max(0,(int)($_GET['id']??0));
    }

    public function run(): never
    {
        try{
            if($this->method==='GET') ApiV1Auth::requireScope($this->token,'read');
            else ApiV1Auth::requireScope($this->token,'write');

            switch($this->resource){
                case 'meta': $this->meta(); break;
                case 'companies': $this->companies(); break;
                case 'daily_plans': $this->dailyPlans(); break;
                case 'monthly_plans': $this->monthlyPlans(); break;
                case 'calendar': $this->calendar(); break;
                case 'kanban': $this->kanban(); break;
                case 'systems': $this->systems(); break;
                case 'portals': $this->portals(); break;
                case 'custom_fields': $this->customFields(); break;
                case 'settings': $this->settings(); break;
                case 'table_preferences': $this->tablePreferences(); break;
                case 'imports': $this->imports(); break;
                case 'exports': $this->exports(); break;
                default: ApiV1Auth::error('Unknown resource',404,'resource_not_found');
            }
        }catch(PDOException $e){
            ApiV1Auth::error('Database error',500,'database_error',['sqlstate'=>$e->getCode()]);
        }catch(Throwable $e){
            ApiV1Auth::error($e->getMessage(),400,'request_failed');
        }
    }

    private function meta(): never
    {
        if($this->method!=='GET') ApiV1Auth::error('Method not allowed',405,'method_not_allowed');
        ApiV1Auth::respond([
            'name'=>'Accounting CRM API',
            'version'=>'1.0.0',
            'schema_version'=>setting('schema_version',''),
            'time'=>date(DATE_ATOM),
            'resources'=>['calendar','companies','systems','portals','daily-plans','monthly-plans','custom-fields','kanban','settings','table-preferences','imports','exports']
        ]);
    }

    private function companies(): never
    {
        if($this->method==='GET'){
            if($this->id){
                $r=$this->one("SELECT * FROM companies WHERE id=? AND active=1",[$this->id]);
                if(!$r) ApiV1Auth::error('Company not found',404,'not_found');
                ApiV1Auth::respond($this->decodeExtra($r));
            }
            [$page,$per,$off]=$this->paging();
            $w=['active=1'];$p=[];
            if($q=trim((string)($_GET['q']??''))){$w[]="(name LIKE ? OR national_id LIKE ? OR economic_code LIKE ? OR registration_number LIKE ?)";$like="%$q%";array_push($p,$like,$like,$like,$like);}
            foreach(['company_type','legal_personality','software'] as $f)if(($v=trim((string)($_GET[$f]??'')))!==''){$w[]="$f=?";$p[]=$v;}
            $count=$this->count("companies",implode(' AND ',$w),$p);
            $st=pdo()->prepare("SELECT * FROM companies WHERE ".implode(' AND ',$w)." ORDER BY name LIMIT $per OFFSET $off");$st->execute($p);
            $rows=array_map(fn($r)=>$this->decodeExtra($r),$st->fetchAll());
            $this->listResponse($rows,$count,$page,$per);
        }
        if($this->method==='POST'){
            $b=ApiV1Auth::jsonBody();$name=trim((string)($b['name']??''));if($name==='')throw new RuntimeException('name is required');
            $data=$this->companyData($b);
            pdo()->prepare("INSERT INTO companies (name,company_type,legal_personality,national_id,economic_code,registration_number,address,postal_code,phone,ceo_name,ceo_national_id,ceo_mobile,software,extra_json,active,created_at,updated_at)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,1,NOW(),NOW())")->execute([$name,...$data]);
            $id=(int)pdo()->lastInsertId();$this->log('companies',$id,'api_create');
            ApiV1Auth::respond($this->one("SELECT * FROM companies WHERE id=?",[$id]),201);
        }
        if(in_array($this->method,['PATCH','PUT'],true)){
            $this->needId();$b=ApiV1Auth::jsonBody();$allowed=['name','company_type','legal_personality','national_id','economic_code','registration_number','address','postal_code','phone','ceo_name','ceo_national_id','ceo_mobile','software'];
            $this->patchRow('companies',$this->id,$b,$allowed,true);
            if(array_key_exists('extra',$b)) pdo()->prepare("UPDATE companies SET extra_json=?,updated_at=NOW() WHERE id=?")->execute([json_encode((array)$b['extra'],JSON_UNESCAPED_UNICODE),$this->id]);
            $this->log('companies',$this->id,'api_update');ApiV1Auth::respond($this->decodeExtra($this->one("SELECT * FROM companies WHERE id=?",[$this->id])?:[]));
        }
        if($this->method==='DELETE'){
            $this->needId();pdo()->prepare("UPDATE companies SET active=0,updated_at=NOW() WHERE id=?")->execute([$this->id]);$this->log('companies',$this->id,'api_delete');ApiV1Auth::respond(['id'=>$this->id,'deleted'=>true]);
        }
        ApiV1Auth::error('Method not allowed',405,'method_not_allowed');
    }

    private function dailyPlans(): never
    {
        if($this->method==='GET'){
            if($this->id){$r=$this->one("SELECT d.*,c.name company_name FROM daily_plans d LEFT JOIN companies c ON c.id=d.company_id WHERE d.id=?",[$this->id]);if(!$r)ApiV1Auth::error('Daily plan not found',404,'not_found');ApiV1Auth::respond($this->planOut($r));}
            [$page,$per,$off]=$this->paging();$w=['1=1'];$p=[];
            if($v=trim((string)($_GET['company_id']??''))){$w[]='d.company_id=?';$p[]=(int)$v;}
            if($v=trim((string)($_GET['status']??''))){$w[]='d.status=?';$p[]=$v;}
            if($v=$this->dateParam('from')){$w[]='d.plan_date>=?';$p[]=$v;}
            if($v=$this->dateParam('to')){$w[]='d.plan_date<=?';$p[]=$v;}
            if($q=trim((string)($_GET['q']??''))){$w[]='(d.work_description LIKE ? OR d.notes LIKE ?)';$l="%$q%";array_push($p,$l,$l);}
            $count=$this->countJoin("daily_plans d",implode(' AND ',$w),$p);
            $st=pdo()->prepare("SELECT d.*,c.name company_name FROM daily_plans d LEFT JOIN companies c ON c.id=d.company_id WHERE ".implode(' AND ',$w)." ORDER BY d.plan_date DESC,d.id DESC LIMIT $per OFFSET $off");$st->execute($p);
            $this->listResponse(array_map(fn($r)=>$this->planOut($r),$st->fetchAll()),$count,$page,$per);
        }
        if($this->method==='POST'){
            $b=ApiV1Auth::jsonBody();$date=$this->parseDate((string)($b['plan_date']??''));$desc=trim((string)($b['work_description']??''));if(!$date||$desc==='')throw new RuntimeException('plan_date and work_description are required');
            $cid=$this->nullableInt($b['company_id']??null);$notes=(string)($b['notes']??'');$status=(string)($b['status']??'باز');$extra=(array)($b['extra']??[]);
            pdo()->prepare("INSERT INTO daily_plans (plan_date,day_name,company_id,work_description,status,notes,extra_json,created_by,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,NOW(),NOW())")
                ->execute([$date,$this->dayName($date),$cid,$desc,$status,$notes,json_encode($extra,JSON_UNESCAPED_UNICODE),$this->token['user_id']?:null]);
            $id=(int)pdo()->lastInsertId();$this->log('daily_plans',$id,'api_create');ApiV1Auth::respond($this->one("SELECT * FROM daily_plans WHERE id=?",[$id]),201);
        }
        if(in_array($this->method,['PATCH','PUT'],true)){
            $this->needId();$b=ApiV1Auth::jsonBody();
            foreach(['plan_date'] as $f)if(isset($b[$f]))$b[$f]=$this->parseDate((string)$b[$f]);
            $allowed=['plan_date','company_id','work_description','status','notes'];
            $this->patchRow('daily_plans',$this->id,$b,$allowed,true);
            if(isset($b['plan_date']))pdo()->prepare("UPDATE daily_plans SET day_name=? WHERE id=?")->execute([$this->dayName($b['plan_date']),$this->id]);
            if(array_key_exists('extra',$b))pdo()->prepare("UPDATE daily_plans SET extra_json=?,updated_at=NOW() WHERE id=?")->execute([json_encode((array)$b['extra'],JSON_UNESCAPED_UNICODE),$this->id]);
            $this->log('daily_plans',$this->id,'api_update');ApiV1Auth::respond($this->planOut($this->one("SELECT * FROM daily_plans WHERE id=?",[$this->id])?:[]));
        }
        if($this->method==='DELETE'){$this->needId();pdo()->prepare("DELETE FROM daily_plans WHERE id=?")->execute([$this->id]);$this->log('daily_plans',$this->id,'api_delete');ApiV1Auth::respond(['id'=>$this->id,'deleted'=>true]);}
        ApiV1Auth::error('Method not allowed',405,'method_not_allowed');
    }

    private function monthlyPlans(): never
    {
        if($this->method==='GET'){
            if($this->id){$r=$this->one("SELECT m.*,c.name company_name FROM monthly_plans m LEFT JOIN companies c ON c.id=m.company_id WHERE m.id=?",[$this->id]);if(!$r)ApiV1Auth::error('Monthly plan not found',404,'not_found');ApiV1Auth::respond($this->planOut($r));}
            [$page,$per,$off]=$this->paging();$w=['1=1'];$p=[];
            foreach(['company_id','jalali_year'] as $f)if(($v=trim((string)($_GET[$f]??'')))!==''){$w[]="m.$f=?";$p[]=(int)$v;}
            foreach(['month_name','season','work_type','status'] as $f)if(($v=trim((string)($_GET[$f]??'')))!==''){$w[]="m.$f=?";$p[]=$v;}
            if($v=$this->dateParam('from')){$w[]='m.legal_deadline>=?';$p[]=$v;}
            if($v=$this->dateParam('to')){$w[]='m.legal_deadline<=?';$p[]=$v;}
            $count=$this->countJoin("monthly_plans m",implode(' AND ',$w),$p);
            $st=pdo()->prepare("SELECT m.*,c.name company_name FROM monthly_plans m LEFT JOIN companies c ON c.id=m.company_id WHERE ".implode(' AND ',$w)." ORDER BY m.legal_deadline IS NULL,m.legal_deadline,m.id DESC LIMIT $per OFFSET $off");$st->execute($p);
            $this->listResponse(array_map(fn($r)=>$this->planOut($r),$st->fetchAll()),$count,$page,$per);
        }
        if($this->method==='POST'){
            $b=ApiV1Auth::jsonBody();$work=trim((string)($b['work_type']??''));if($work==='')throw new RuntimeException('work_type is required');
            $deadline=$this->parseDate((string)($b['legal_deadline']??''));$completed=$this->parseDate((string)($b['completed_date']??''));
            pdo()->prepare("INSERT INTO monthly_plans (company_id,jalali_year,month_name,season,work_type,legal_deadline,status,work_day,completed_date,notes,extra_json,created_by,created_at,updated_at)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW())")->execute([
                    $this->nullableInt($b['company_id']??null),(int)($b['jalali_year']??1405),(string)($b['month_name']??''),(string)($b['season']??''),$work,$deadline,
                    (string)($b['status']??'باز'),(string)($b['work_day']??''),$completed,(string)($b['notes']??''),json_encode((array)($b['extra']??[]),JSON_UNESCAPED_UNICODE),$this->token['user_id']?:null
                ]);
            $id=(int)pdo()->lastInsertId();$this->log('monthly_plans',$id,'api_create');ApiV1Auth::respond($this->one("SELECT * FROM monthly_plans WHERE id=?",[$id]),201);
        }
        if(in_array($this->method,['PATCH','PUT'],true)){
            $this->needId();$b=ApiV1Auth::jsonBody();
            foreach(['legal_deadline','completed_date'] as $f)if(array_key_exists($f,$b))$b[$f]=$this->parseDate((string)$b[$f]);
            $allowed=['company_id','jalali_year','month_name','season','work_type','legal_deadline','status','work_day','completed_date','notes'];
            $this->patchRow('monthly_plans',$this->id,$b,$allowed,true);
            if(array_key_exists('extra',$b))pdo()->prepare("UPDATE monthly_plans SET extra_json=?,updated_at=NOW() WHERE id=?")->execute([json_encode((array)$b['extra'],JSON_UNESCAPED_UNICODE),$this->id]);
            $this->log('monthly_plans',$this->id,'api_update');ApiV1Auth::respond($this->planOut($this->one("SELECT * FROM monthly_plans WHERE id=?",[$this->id])?:[]));
        }
        if($this->method==='DELETE'){$this->needId();pdo()->prepare("DELETE FROM monthly_plans WHERE id=?")->execute([$this->id]);$this->log('monthly_plans',$this->id,'api_delete');ApiV1Auth::respond(['id'=>$this->id,'deleted'=>true]);}
        ApiV1Auth::error('Method not allowed',405,'method_not_allowed');
    }

    private function calendar(): never
    {
        if($this->method==='GET'){
            $date=$this->dateParam('date');$from=$date?:($this->dateParam('from')?:date('Y-m-01'));$to=$date?:($this->dateParam('to')?:date('Y-m-t'));
            $company=(int)($_GET['company_id']??0);$p=[$from,$to];$wc='';
            if($company){$wc=' AND x.company_id=?';$p[]=$company;}
            $sql="SELECT * FROM (
                SELECT d.id,'daily' source,d.plan_date event_date,d.company_id,d.work_description title,d.status,d.notes,c.name company_name
                FROM daily_plans d LEFT JOIN companies c ON c.id=d.company_id
                UNION ALL
                SELECT m.id,'monthly' source,m.legal_deadline event_date,m.company_id,m.work_type title,m.status,m.notes,c.name company_name
                FROM monthly_plans m LEFT JOIN companies c ON c.id=m.company_id
            ) x WHERE x.event_date BETWEEN ? AND ? $wc ORDER BY x.event_date,x.source,x.id";
            $st=pdo()->prepare($sql);$st->execute($p);ApiV1Auth::respond($st->fetchAll(),200,['from'=>$from,'to'=>$to]);
        }
        if($this->method==='POST'){
            $b=ApiV1Auth::jsonBody();$type=(string)($b['type']??'daily');
            $_SERVER['REQUEST_METHOD']='POST';
            if($type==='daily'){$this->resource='daily_plans';$this->dailyPlans();}
            if($type==='monthly'){$this->resource='monthly_plans';$this->monthlyPlans();}
            throw new RuntimeException('type must be daily or monthly');
        }
        ApiV1Auth::error('Method not allowed',405,'method_not_allowed');
    }

    private function kanban(): never
    {
        if($this->method==='GET'){
            $statuses=['باز','در حال انجام','منتظر مدارک','معوق','انجام شده'];$out=[];
            $company=(int)($_GET['company_id']??0);$type=trim((string)($_GET['work_type']??''));
            foreach($statuses as $s){
                $w=['m.status=?'];$p=[$s];
                if($company){$w[]='m.company_id=?';$p[]=$company;}
                if($type!==''){$w[]='m.work_type=?';$p[]=$type;}
                $st=pdo()->prepare("SELECT m.*,c.name company_name FROM monthly_plans m LEFT JOIN companies c ON c.id=m.company_id WHERE ".implode(' AND ',$w)." ORDER BY m.legal_deadline IS NULL,m.legal_deadline LIMIT 200");
                $st->execute($p);$out[$s]=$st->fetchAll();
            }
            ApiV1Auth::respond($out);
        }
        if(in_array($this->method,['PATCH','PUT'],true)){
            $this->needId();$b=ApiV1Auth::jsonBody();$status=trim((string)($b['status']??''));if($status==='')throw new RuntimeException('status is required');
            pdo()->prepare("UPDATE monthly_plans SET status=?,updated_at=NOW() WHERE id=?")->execute([$status,$this->id]);
            $this->log('monthly_plans',$this->id,'api_kanban_move');ApiV1Auth::respond(['id'=>$this->id,'status'=>$status]);
        }
        ApiV1Auth::error('Method not allowed',405,'method_not_allowed');
    }

    private function systems(): never
    {
        if($this->method==='GET'){
            $includeSecrets=(string)($_GET['include_secrets']??'0')==='1';
            if($includeSecrets) ApiV1Auth::requireScope($this->token,'secrets');
            $w=['1=1'];$p=[];
            if($this->id){$w[]='pc.id=?';$p[]=$this->id;}
            if($v=(int)($_GET['company_id']??0)){$w[]='pc.company_id=?';$p[]=$v;}
            if($v=trim((string)($_GET['portal_key']??''))){$w[]='pc.portal_key=?';$p[]=$v;}
            $st=pdo()->prepare("SELECT pc.*,c.name company_name,p.title portal_title,p.url portal_url FROM portal_credentials pc
                LEFT JOIN companies c ON c.id=pc.company_id LEFT JOIN portal_definitions p ON p.portal_key=pc.portal_key
                WHERE ".implode(' AND ',$w)." ORDER BY c.name,p.sort_order,pc.id");$st->execute($p);
            $rows=$st->fetchAll();
            foreach($rows as &$r){
                if($includeSecrets)$r['password']=decrypt_value((string)$r['password_enc']);
                unset($r['password_enc']);
            }
            if($this->id){if(!$rows)ApiV1Auth::error('Credential not found',404,'not_found');ApiV1Auth::respond($rows[0]);}
            ApiV1Auth::respond($rows);
        }
        if($this->method==='POST'){
            $b=ApiV1Auth::jsonBody();$cid=(int)($b['company_id']??0);$key=trim((string)($b['portal_key']??''));if(!$cid||$key==='')throw new RuntimeException('company_id and portal_key are required');
            $password=(string)($b['password']??'');$enc=$password!==''?encrypt_value($password):'';
            pdo()->prepare("INSERT INTO portal_credentials (company_id,portal_key,username,password_enc,notes,created_at,updated_at) VALUES (?,?,?,?,?,NOW(),NOW())
                ON DUPLICATE KEY UPDATE username=VALUES(username),password_enc=IF(VALUES(password_enc)='',password_enc,VALUES(password_enc)),notes=VALUES(notes),updated_at=NOW()")
                ->execute([$cid,$key,(string)($b['username']??''),$enc,(string)($b['notes']??'')]);
            $id=(int)(pdo()->lastInsertId() ?: ($this->one("SELECT id FROM portal_credentials WHERE company_id=? AND portal_key=?",[$cid,$key])['id']??0));
            $this->log('portal_credentials',$id,'api_save');ApiV1Auth::respond(['id'=>$id],201);
        }
        if(in_array($this->method,['PATCH','PUT'],true)){
            $this->needId();$b=ApiV1Auth::jsonBody();$allowed=['company_id','portal_key','username','notes'];$this->patchRow('portal_credentials',$this->id,$b,$allowed,true);
            if(array_key_exists('password',$b)&&$b['password']!=='')pdo()->prepare("UPDATE portal_credentials SET password_enc=?,updated_at=NOW() WHERE id=?")->execute([encrypt_value((string)$b['password']),$this->id]);
            $this->log('portal_credentials',$this->id,'api_update');ApiV1Auth::respond(['id'=>$this->id,'updated'=>true]);
        }
        if($this->method==='DELETE'){$this->needId();pdo()->prepare("DELETE FROM portal_credentials WHERE id=?")->execute([$this->id]);$this->log('portal_credentials',$this->id,'api_delete');ApiV1Auth::respond(['id'=>$this->id,'deleted'=>true]);}
        ApiV1Auth::error('Method not allowed',405,'method_not_allowed');
    }

    private function portals(): never
    {
        if($this->method!=='GET')ApiV1Auth::error('Method not allowed',405,'method_not_allowed');
        ApiV1Auth::respond(pdo()->query("SELECT id,portal_key,title,url,sort_order,active FROM portal_definitions WHERE active=1 ORDER BY sort_order,id")->fetchAll());
    }

    private function customFields(): never
    {
        if($this->method==='GET'){
            $w=['active=1'];$p=[];if($this->id){$w[]='id=?';$p[]=$this->id;}if($v=trim((string)($_GET['entity_key']??''))){$w[]='entity_key=?';$p[]=$v;}
            $st=pdo()->prepare("SELECT * FROM custom_fields WHERE ".implode(' AND ',$w)." ORDER BY entity_key,sort_order,id");$st->execute($p);$rows=$st->fetchAll();
            if($this->id){if(!$rows)ApiV1Auth::error('Custom field not found',404,'not_found');ApiV1Auth::respond($rows[0]);}ApiV1Auth::respond($rows);
        }
        if($this->method==='POST'){
            $b=ApiV1Auth::jsonBody();$entity=trim((string)($b['entity_key']??''));$key=trim((string)($b['field_key']??''));$label=trim((string)($b['label']??''));
            if(!in_array($entity,['companies','daily_plans','monthly_plans'],true)||$key===''||$label==='')throw new RuntimeException('entity_key, field_key and label are required');
            pdo()->prepare("INSERT INTO custom_fields (entity_key,field_key,label,field_type,options,sort_order,active,created_at,updated_at) VALUES (?,?,?,?,?,?,1,NOW(),NOW())")
                ->execute([$entity,$key,$label,(string)($b['field_type']??'text'),(string)($b['options']??''),(int)($b['sort_order']??100)]);
            $id=(int)pdo()->lastInsertId();$this->log('custom_fields',$id,'api_create');ApiV1Auth::respond(['id'=>$id],201);
        }
        if(in_array($this->method,['PATCH','PUT'],true)){$this->needId();$b=ApiV1Auth::jsonBody();$this->patchRow('custom_fields',$this->id,$b,['entity_key','label','field_type','options','sort_order'],true);$this->log('custom_fields',$this->id,'api_update');ApiV1Auth::respond(['id'=>$this->id,'updated'=>true]);}
        if($this->method==='DELETE'){$this->needId();pdo()->prepare("UPDATE custom_fields SET active=0,updated_at=NOW() WHERE id=?")->execute([$this->id]);$this->log('custom_fields',$this->id,'api_delete');ApiV1Auth::respond(['id'=>$this->id,'deleted'=>true]);}
        ApiV1Auth::error('Method not allowed',405,'method_not_allowed');
    }

    private function settings(): never
    {
        ApiV1Auth::requireScope($this->token,'settings');ApiV1Auth::requireAdmin($this->token);
        $plain=['notifications_email_to','notifications_sms_to','ghasedak_line_number','google_client_id','google_redirect_uri','allow_google_signup','smtp_host','smtp_port','smtp_encryption','smtp_username','mail_from_name','edge_service_url','cache_ttl_seconds','api_enabled','api_cors_origins','api_rate_limit_per_minute'];
        $secrets=['smtp_password','ghasedak_api_key','google_client_secret','edge_service_token'];
        if($this->method==='GET'){
            $out=[];foreach($plain as $k)$out[$k]=setting($k,'');
            foreach($secrets as $k)$out[$k.'_configured']=setting($k,'')!=='';ApiV1Auth::respond($out);
        }
        if(in_array($this->method,['PATCH','PUT'],true)){
            $b=ApiV1Auth::jsonBody();
            foreach($plain as $k)if(array_key_exists($k,$b))setting_set($k,(string)$b[$k],0);
            foreach($secrets as $k)if(array_key_exists($k,$b)&&$b[$k]!=='')setting_set($k,(string)$b[$k],1);
            $this->log('settings',0,'api_update');ApiV1Auth::respond(['updated'=>true]);
        }
        ApiV1Auth::error('Method not allowed',405,'method_not_allowed');
    }

    private function tablePreferences(): never
    {
        $uid=(int)($this->token['user_id']??0);if(!$uid)ApiV1Auth::error('Token is not linked to a user',403,'user_required');
        $key=trim((string)($_GET['table_key']??''));
        if($this->method==='GET'){
            $p=[];$where='user_id=?';$p[]=$uid;if($key!==''){$where.=' AND table_key=?';$p[]=$key;}
            $st=pdo()->prepare("SELECT table_key,prefs_json,updated_at FROM user_table_preferences WHERE $where ORDER BY table_key");$st->execute($p);$rows=$st->fetchAll();
            foreach($rows as &$r){$d=json_decode($r['prefs_json'],true);$r['prefs']=is_array($d)?$d:[];unset($r['prefs_json']);}
            ApiV1Auth::respond($key?($rows[0]??null):$rows);
        }
        if(in_array($this->method,['POST','PUT','PATCH'],true)){
            $b=ApiV1Auth::jsonBody();$key=trim((string)($b['table_key']??$key));if($key==='')throw new RuntimeException('table_key is required');$prefs=(array)($b['prefs']??[]);
            pdo()->prepare("INSERT INTO user_table_preferences (user_id,table_key,prefs_json,updated_at) VALUES (?,?,?,NOW()) ON DUPLICATE KEY UPDATE prefs_json=VALUES(prefs_json),updated_at=NOW()")
                ->execute([$uid,$key,json_encode($prefs,JSON_UNESCAPED_UNICODE)]);ApiV1Auth::respond(['table_key'=>$key,'saved'=>true]);
        }
        if($this->method==='DELETE'){if($key==='')throw new RuntimeException('table_key is required');pdo()->prepare("DELETE FROM user_table_preferences WHERE user_id=? AND table_key=?")->execute([$uid,$key]);ApiV1Auth::respond(['table_key'=>$key,'deleted'=>true]);}
        ApiV1Auth::error('Method not allowed',405,'method_not_allowed');
    }

    private function imports(): never
    {
        if($this->method==='GET'){
            [$page,$per,$off]=$this->paging();$count=(int)pdo()->query("SELECT COUNT(*) FROM imports")->fetchColumn();
            $rows=pdo()->query("SELECT i.*,u.name user_name FROM imports i LEFT JOIN users u ON u.id=i.user_id ORDER BY i.id DESC LIMIT $per OFFSET $off")->fetchAll();
            $this->listResponse($rows,$count,$page,$per);
        }
        if($this->method==='POST'){
            if(!class_exists('AccountingDataIO')) require_once APP_ROOT.'/app/Core/DataIO.php';
            $entity=trim((string)($_POST['entity']??''));if(!AccountingDataIO::allowed($entity))throw new RuntimeException('Invalid import entity');
            if(empty($_FILES['file'])||!is_uploaded_file($_FILES['file']['tmp_name']))throw new RuntimeException('file is required');
            if(($_FILES['file']['size']??0)>15*1024*1024)throw new RuntimeException('file is too large');
            $stats=AccountingDataIO::import($entity,$_FILES['file']['tmp_name'],$_FILES['file']['name']);
            pdo()->prepare("INSERT INTO imports (filename,stats,user_id,created_at) VALUES (?,?,?,NOW())")->execute([$_FILES['file']['name'],json_encode(['entity'=>$entity]+$stats,JSON_UNESCAPED_UNICODE),$this->token['user_id']?:null]);
            ApiV1Auth::respond($stats,201);
        }
        ApiV1Auth::error('Method not allowed',405,'method_not_allowed');
    }

    private function exports(): never
    {
        if($this->method!=='GET')ApiV1Auth::error('Method not allowed',405,'method_not_allowed');
        if(!class_exists('AccountingDataIO')) require_once APP_ROOT.'/app/Core/DataIO.php';
        $entity=trim((string)($_GET['entity']??''));if(!AccountingDataIO::allowed($entity))throw new RuntimeException('Invalid export entity');
        if($entity==='portal_credentials')ApiV1Auth::requireScope($this->token,'secrets');
        $format=strtolower(trim((string)($_GET['format']??'json')));$ids=array_values(array_filter(array_map('intval',explode(',',(string)($_GET['ids']??'')))));
        if($format==='csv'||$format==='xlsx')AccountingDataIO::stream($entity,$format,$ids,false);
        [$headers,$rows]=AccountingDataIO::exportRows($entity,$ids);ApiV1Auth::respond(['headers'=>$headers,'rows'=>$rows]);
    }

    private function companyData(array $b): array
    {
        return [
            (string)($b['company_type']??''),(string)($b['legal_personality']??''),(string)($b['national_id']??''),(string)($b['economic_code']??''),
            (string)($b['registration_number']??''),(string)($b['address']??''),(string)($b['postal_code']??''),(string)($b['phone']??''),
            (string)($b['ceo_name']??''),(string)($b['ceo_national_id']??''),(string)($b['ceo_mobile']??''),(string)($b['software']??''),
            json_encode((array)($b['extra']??[]),JSON_UNESCAPED_UNICODE)
        ];
    }

    private function patchRow(string $table,int $id,array $b,array $allowed,bool $updatedAt=false): void
    {
        $sets=[];$vals=[];
        foreach($allowed as $f){
            if(!array_key_exists($f,$b))continue;
            $v=$b[$f];
            if(in_array($f,['company_id','jalali_year','sort_order'],true))$v=$v===''||$v===null?null:(int)$v;
            $sets[]="`$f`=?";$vals[]=$v;
        }
        if(!$sets)return;
        if($updatedAt)$sets[]='updated_at=NOW()';
        $vals[]=$id;
        pdo()->prepare("UPDATE `$table` SET ".implode(',',$sets)." WHERE id=?")->execute($vals);
    }

    private function planOut(array $r): array
    {
        if(isset($r['extra_json'])){$d=json_decode((string)$r['extra_json'],true);$r['extra']=is_array($d)?$d:[];unset($r['extra_json']);}
        foreach(['plan_date','legal_deadline','completed_date'] as $f)if(!empty($r[$f]))$r[$f.'_jalali']=Jalali::fromGregorian($r[$f]);
        unset($r['location']);
        return $r;
    }

    private function decodeExtra(array $r): array
    {
        if(isset($r['extra_json'])){$d=json_decode((string)$r['extra_json'],true);$r['extra']=is_array($d)?$d:[];unset($r['extra_json']);}
        return $r;
    }

    private function normalizeResource(string $r): string
    {
        $r=strtolower(trim($r));
        return match($r){
            'daily','daily-plan','daily-plans'=>'daily_plans',
            'monthly','monthly-plan','monthly-plans'=>'monthly_plans',
            'custom-field','custom-fields'=>'custom_fields',
            'table-preference','table-preferences'=>'table_preferences',
            default=>str_replace('-','_',$r)
        };
    }

    private function paging(): array
    {
        $page=max(1,(int)($_GET['page']??1));$per=max(1,min(200,(int)($_GET['per_page']??50)));return [$page,$per,($page-1)*$per];
    }

    private function listResponse(array $rows,int $count,int $page,int $per): never
    {
        ApiV1Auth::respond($rows,200,['page'=>$page,'per_page'=>$per,'total'=>$count,'pages'=>(int)ceil($count/max(1,$per))]);
    }

    private function count(string $table,string $where,array $params): int
    {
        $st=pdo()->prepare("SELECT COUNT(*) FROM `$table` WHERE $where");$st->execute($params);return (int)$st->fetchColumn();
    }

    private function countJoin(string $from,string $where,array $params): int
    {
        $st=pdo()->prepare("SELECT COUNT(*) FROM $from WHERE $where");$st->execute($params);return (int)$st->fetchColumn();
    }

    private function one(string $sql,array $params=[]): ?array
    {
        $st=pdo()->prepare($sql);$st->execute($params);$r=$st->fetch();return $r?:null;
    }

    private function needId(): void
    {
        if(!$this->id)ApiV1Auth::error('Resource id is required',400,'id_required');
    }

    private function nullableInt($v): ?int
    {
        if($v===null||$v==='')return null;return (int)$v;
    }

    private function dateParam(string $key): ?string
    {
        return $this->parseDate((string)($_GET[$key]??''));
    }

    private function parseDate(string $v): ?string
    {
        $v=trim($v);if($v==='')return null;
        if(preg_match('/^\d{4}-\d{2}-\d{2}$/',$v))return $v;
        return Jalali::parse($v)?:null;
    }

    private function dayName(string $d): string
    {
        $days=['Saturday'=>'شنبه','Sunday'=>'یکشنبه','Monday'=>'دوشنبه','Tuesday'=>'سه‌شنبه','Wednesday'=>'چهارشنبه','Thursday'=>'پنجشنبه','Friday'=>'جمعه'];
        return $days[date('l',strtotime($d))]??'';
    }

    private function log(string $entity,int $id,string $action): void
    {
        try{
            pdo()->prepare("INSERT INTO activity_logs (user_id,entity_key,record_id,action,summary,ip,created_at) VALUES (?,?,?,?,?,?,NOW())")
                ->execute([$this->token['user_id']?:null,$entity,$id,$action,'API v1',$_SERVER['REMOTE_ADDR']??'']);
        }catch(Throwable $e){}
    }
}
