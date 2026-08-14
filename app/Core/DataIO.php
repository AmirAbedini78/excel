<?php
class AccountingDataIO
{
    private static function entityPage(string $entity): string
    {
        return [
            'companies'=>'companies',
            'daily_plans'=>'daily',
            'monthly_plans'=>'monthly',
            'portal_credentials'=>'systems',
            'custom_fields'=>'custom_fields',
        ][$entity] ?? 'dashboard';
    }

    public static function pageFor(string $entity): string
    {
        return self::entityPage($entity);
    }

    public static function allowed(string $entity): bool
    {
        return in_array($entity,['companies','daily_plans','monthly_plans','portal_credentials','custom_fields'],true);
    }

    private static function normalizeHeader(string $s): string
    {
        $s = preg_replace('/^\xEF\xBB\xBF/', '', $s);
        $s = str_replace(["\u{200c}","\u{200f}","\u{202a}","\u{202b}",'ي','ك'],['','','','','ی','ک'],$s);
        $s = trim(preg_replace('/\s+/u',' ', $s));
        return mb_strtolower($s);
    }

    private static function parseDate(string $v): ?string
    {
        $v=trim($v);
        if($v==='') return null;
        if(preg_match('/^\d{4}-\d{2}-\d{2}$/',$v)) return $v;
        if(($d=Jalali::parse($v))) return $d;
        if(($d=XlsxReader::excelSerialToDate($v))) return $d;
        return null;
    }

    private static function companyId(string $name): ?int
    {
        $name=trim($name);
        if($name==='') return null;
        $st=pdo()->prepare("SELECT id FROM companies WHERE workspace_id=? AND active=1 AND name=? LIMIT 1");
        $st->execute([Tenant::id(),$name]);
        $id=$st->fetchColumn();
        return $id ? (int)$id : null;
    }

    private static function idsWhere(array $ids, string $column='id'): array
    {
        $ids=array_values(array_unique(array_filter(array_map('intval',$ids),fn($x)=>$x>0)));
        if(!$ids) return ['',[]];
        return [" AND `$column` IN (".implode(',',array_fill(0,count($ids),'?')).")",$ids];
    }

    private static function customFields(string $entity): array
    {
        $st=pdo()->prepare("SELECT field_key,label FROM custom_fields WHERE workspace_id=? AND entity_key=? AND active=1 ORDER BY sort_order,id");
        $st->execute([Tenant::id(),$entity]);
        return $st->fetchAll();
    }

    private static function headers(string $entity): array
    {
        if($entity==='companies') {
            $h=['نام شرکت','نوع شرکت','شخصیت','شناسه ملی','کد اقتصادی','شماره ثبت','آدرس','کدپستی','شماره تلفن','مدیرعامل','کدملی مدیرعامل','شماره تماس مدیرعامل','نرم افزار'];
            foreach(self::customFields('companies') as $f) $h[]='اضافی: '.$f['label'];
            return $h;
        }
        if($entity==='daily_plans') {
            $h=['تاریخ','روز','شرکت','شرح کار','توضیحات'];
            foreach(self::customFields('daily_plans') as $f) $h[]='اضافی: '.$f['label'];
            return $h;
        }
        if($entity==='monthly_plans') {
            $h=['نام شرکت','سال','ماه','فصل','نوع کار','مهلت قانونی','وضعیت','روز انجام','تاریخ انجام','توضیحات'];
            foreach(self::customFields('monthly_plans') as $f) $h[]='اضافی: '.$f['label'];
            return $h;
        }
        if($entity==='custom_fields') {
            return ['بخش','کلید','عنوان','نوع','گزینه‌ها','ترتیب'];
        }
        if($entity==='portal_credentials') {
            $h=['نام شرکت'];
            foreach(self::portals() as $p){
                $h[]=$p['url'].' | نام کاربری';
                $h[]=$p['url'].' | کلمه عبور';
            }
            return $h;
        }
        return [];
    }

    private static function portals(): array
    {
        return pdo()->query("SELECT portal_key,title,url FROM portal_definitions WHERE active=1 ORDER BY sort_order,id")->fetchAll();
    }

    public static function exportRows(string $entity, array $ids=[]): array
    {
        if(!self::allowed($entity)) throw new RuntimeException('بخش خروجی معتبر نیست.');
        $headers=self::headers($entity);
        $rows=[];

        if($entity==='companies'){
            [$extra,$params]=self::idsWhere($ids);
            $st=pdo()->prepare("SELECT * FROM companies WHERE workspace_id=? AND active=1 $extra ORDER BY name");
            $st->execute([Tenant::id(),...$params]);
            $custom=self::customFields('companies');
            foreach($st->fetchAll() as $r){
                $x=json_decode((string)($r['extra_json']??''),true); if(!is_array($x))$x=[];
                $row=[
                    $r['name'],$r['company_type']??'',$r['legal_personality']??'',$r['national_id']??'',$r['economic_code']??'',
                    $r['registration_number']??'',$r['address']??'',$r['postal_code']??'',$r['phone']??'',$r['ceo_name']??'',
                    $r['ceo_national_id']??'',$r['ceo_mobile']??'',$r['software']??''
                ];
                foreach($custom as $f)$row[]=$x[$f['field_key']]??'';
                $rows[]=$row;
            }
        } elseif($entity==='daily_plans'){
            [$extra,$params]=self::idsWhere($ids,'d.id');
            $st=pdo()->prepare("SELECT d.*,c.name company_name FROM daily_plans d LEFT JOIN companies c ON c.id=d.company_id WHERE d.workspace_id=? $extra ORDER BY d.plan_date,d.id");
            $st->execute([Tenant::id(),...$params]);
            $custom=self::customFields('daily_plans');
            foreach($st->fetchAll() as $r){
                $x=json_decode((string)($r['extra_json']??''),true); if(!is_array($x))$x=[];
                $row=[self::faDate($r['plan_date']??null),$r['day_name']??'',$r['company_name']??'',$r['work_description']??'',$r['notes']??''];
                foreach($custom as $f)$row[]=$x[$f['field_key']]??'';
                $rows[]=$row;
            }
        } elseif($entity==='monthly_plans'){
            [$extra,$params]=self::idsWhere($ids,'m.id');
            $st=pdo()->prepare("SELECT m.*,c.name company_name FROM monthly_plans m LEFT JOIN companies c ON c.id=m.company_id WHERE m.workspace_id=? $extra ORDER BY m.legal_deadline,m.id");
            $st->execute([Tenant::id(),...$params]);
            $custom=self::customFields('monthly_plans');
            foreach($st->fetchAll() as $r){
                $x=json_decode((string)($r['extra_json']??''),true); if(!is_array($x))$x=[];
                $row=[
                    $r['company_name']??'',$r['jalali_year']??'',$r['month_name']??'',$r['season']??'',$r['work_type']??'',
                    self::faDate($r['legal_deadline']??null),$r['status']??'',$r['work_day']??'',self::faDate($r['completed_date']??null),$r['notes']??''
                ];
                foreach($custom as $f)$row[]=$x[$f['field_key']]??'';
                $rows[]=$row;
            }
        } elseif($entity==='custom_fields'){
            [$extra,$params]=self::idsWhere($ids);
            $st=pdo()->prepare("SELECT * FROM custom_fields WHERE workspace_id=? AND active=1 $extra ORDER BY entity_key,sort_order,id");
            $st->execute([Tenant::id(),...$params]);
            foreach($st->fetchAll() as $r)$rows[]=[$r['entity_key'],$r['field_key'],$r['label'],$r['field_type'],$r['options'],$r['sort_order']];
        } elseif($entity==='portal_credentials'){
            [$extra,$params]=self::idsWhere($ids,'c.id');
            $st=pdo()->prepare("SELECT c.id,c.name FROM companies c WHERE c.workspace_id=? AND c.active=1 $extra ORDER BY c.name");
            $st->execute([Tenant::id(),...$params]);
            $companies=$st->fetchAll();
            $portals=self::portals();
            $stCred=pdo()->prepare("SELECT * FROM portal_credentials WHERE workspace_id=?");$stCred->execute([Tenant::id()]);$credRows=$stCred->fetchAll();
            $map=[]; foreach($credRows as $cr)$map[(int)$cr['company_id']][$cr['portal_key']]=$cr;
            foreach($companies as $c){
                $row=[$c['name']];
                foreach($portals as $p){
                    $cr=$map[(int)$c['id']][$p['portal_key']]??null;
                    $row[]=$cr['username']??'';
                    $row[]=$cr ? decrypt_value((string)$cr['password_enc']) : '';
                }
                $rows[]=$row;
            }
        }

        return [$headers,$rows];
    }

    private static function faDate(?string $d): string
    {
        return $d ? Jalali::fromGregorian($d) : '';
    }

    public static function stream(string $entity, string $format, array $ids=[], bool $template=false): never
    {
        [$headers,$rows]=self::exportRows($entity,$template?[]:$ids);
        if($template)$rows=[];
        $base='accounting-'.$entity.'-'.date('Ymd-His');
        if($format==='csv'){
            header('Content-Type: text/csv; charset=UTF-8');
            header('Content-Disposition: attachment; filename="'.$base.'.csv"');
            echo "\xEF\xBB\xBF";
            $out=fopen('php://output','w');
            fputcsv($out,$headers);
            foreach($rows as $r)fputcsv($out,array_map([self::class,'csvSafe'],$r));
            fclose($out); exit;
        }
        if($format!=='xlsx')throw new RuntimeException('فرمت خروجی پشتیبانی نمی‌شود.');
        GenericXlsxWriter::stream($headers,$rows,$base.'.xlsx');
    }

    public static function csvSafe($v): string
    {
        $s=(string)$v;
        if(preg_match('/^[=+\-@]/u',$s)) return "'".$s;
        return $s;
    }

    private static function readUpload(string $file, string $name): array
    {
        $ext=mb_strtolower(pathinfo($name,PATHINFO_EXTENSION));
        if($ext==='xlsx'){
            $sheets=XlsxReader::read($file);
            if(!$sheets)return [];
            return array_values($sheets)[0] ?? [];
        }
        if($ext!=='csv')throw new RuntimeException('فقط فایل CSV یا XLSX مجاز است.');
        $fh=fopen($file,'r'); if(!$fh)throw new RuntimeException('فایل CSV قابل خواندن نیست.');
        $first=fgets($fh); rewind($fh);
        $delims=[','=>substr_count($first,','),';'=>substr_count($first,';'),"\t"=>substr_count($first,"\t")];
        arsort($delims); $delimiter=array_key_first($delims);
        $rows=[];
        while(($row=fgetcsv($fh,0,$delimiter))!==false){
            $rows[]=array_map(function($v){
                $v=(string)$v;
                if(str_starts_with($v,"'") && preg_match('/^\'[=+\-@]/u',$v))$v=substr($v,1);
                return trim($v);
            },$row);
        }
        fclose($fh);
        return $rows;
    }

    private static function indexHeaders(array $headers): array
    {
        $idx=[];
        foreach($headers as $i=>$h)$idx[self::normalizeHeader((string)$h)]=$i;
        return $idx;
    }

    private static function value(array $row, array $idx, array $names, string $default=''): string
    {
        foreach($names as $n){
            $k=self::normalizeHeader($n);
            if(array_key_exists($k,$idx))return trim((string)($row[$idx[$k]]??''));
        }
        return $default;
    }

    private static function extrasFromRow(string $entity, array $row, array $idx): array
    {
        $out=[];
        foreach(self::customFields($entity) as $f){
            $k=self::normalizeHeader('اضافی: '.$f['label']);
            if(isset($idx[$k]))$out[$f['field_key']]=trim((string)($row[$idx[$k]]??''));
        }
        return $out;
    }

    public static function import(string $entity, string $file, string $originalName): array
    {
        if(!self::allowed($entity))throw new RuntimeException('بخش ورودی معتبر نیست.');
        $rows=self::readUpload($file,$originalName);
        if(count($rows)<1)throw new RuntimeException('فایل خالی است.');
        $idx=self::indexHeaders($rows[0]);
        $stats=['inserted'=>0,'updated'=>0,'skipped'=>0,'errors'=>[]];
        $pdo=pdo(); $pdo->beginTransaction();
        try{
            for($i=1;$i<count($rows);$i++){
                $r=$rows[$i];
                if(!array_filter($r,fn($x)=>trim((string)$x)!==''))continue;
                try{
                    if($entity==='companies')self::importCompany($r,$idx,$stats);
                    elseif($entity==='daily_plans')self::importDaily($r,$idx,$stats);
                    elseif($entity==='monthly_plans')self::importMonthly($r,$idx,$stats);
                    elseif($entity==='portal_credentials')self::importSystems($r,$idx,$stats);
                    elseif($entity==='custom_fields')self::importCustomField($r,$idx,$stats);
                }catch(Throwable $e){
                    $stats['skipped']++;
                    if(count($stats['errors'])<20)$stats['errors'][]='ردیف '.($i+1).': '.$e->getMessage();
                }
            }
            $pdo->commit();
        }catch(Throwable $e){
            if($pdo->inTransaction())$pdo->rollBack();
            throw $e;
        }
        return $stats;
    }

    private static function importCompany(array $r,array $idx,array &$s): void
    {
        $name=self::value($r,$idx,['نام شرکت']); if($name==='')throw new RuntimeException('نام شرکت خالی است.');
        $existing=pdo()->prepare("SELECT id,extra_json FROM companies WHERE workspace_id=? AND name=? LIMIT 1");$existing->execute([Tenant::id(),$name]);$old=$existing->fetch();
        $extra=$old?json_decode((string)($old['extra_json']??''),true):[];if(!is_array($extra))$extra=[];
        $extra=array_merge($extra,self::extrasFromRow('companies',$r,$idx));
        $data=[
            self::value($r,$idx,['نوع شرکت']),self::value($r,$idx,['شخصیت']),self::value($r,$idx,['شناسه ملی']),
            self::value($r,$idx,['کد اقتصادی']),self::value($r,$idx,['شماره ثبت']),self::value($r,$idx,['آدرس']),
            self::value($r,$idx,['کدپستی']),self::value($r,$idx,['شماره تلفن']),self::value($r,$idx,['مدیرعامل']),
            self::value($r,$idx,['کدملی مدیرعامل']),self::value($r,$idx,['شماره تماس مدیرعامل']),self::value($r,$idx,['نرم افزار','نرم‌افزار']),
            json_encode($extra,JSON_UNESCAPED_UNICODE)
        ];
        if($old){
            pdo()->prepare("UPDATE companies SET company_type=?,legal_personality=?,national_id=?,economic_code=?,registration_number=?,address=?,postal_code=?,phone=?,ceo_name=?,ceo_national_id=?,ceo_mobile=?,software=?,extra_json=?,active=1,updated_at=NOW() WHERE id=? AND workspace_id=?")->execute([...$data,(int)$old['id'],Tenant::id()]);
            $s['updated']++;
        }else{
            pdo()->prepare("INSERT INTO companies (workspace_id,name,company_type,legal_personality,national_id,economic_code,registration_number,address,postal_code,phone,ceo_name,ceo_national_id,ceo_mobile,software,extra_json,active,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,1,NOW(),NOW())")->execute([Tenant::id(),$name,...$data]);
            $s['inserted']++;
        }
    }

    private static function importDaily(array $r,array $idx,array &$s): void
    {
        $date=self::parseDate(self::value($r,$idx,['تاریخ'])); if(!$date)throw new RuntimeException('تاریخ معتبر نیست.');
        $company=self::value($r,$idx,['شرکت']); $cid=self::companyId($company); if($company!==''&&!$cid)throw new RuntimeException('شرکت پیدا نشد: '.$company);
        $desc=self::value($r,$idx,['شرح کار']); if($desc==='')throw new RuntimeException('شرح کار خالی است.');
        $day=self::value($r,$idx,['روز']); if($day==='')$day=self::dayName($date);
        $notes=self::value($r,$idx,['توضیحات']);
        $extra=self::extrasFromRow('daily_plans',$r,$idx);
        $st=pdo()->prepare("SELECT id,extra_json FROM daily_plans WHERE workspace_id=? AND plan_date=? AND company_id <=> ? AND work_description=? LIMIT 1");$st->execute([Tenant::id(),$date,$cid,$desc]);$old=$st->fetch();
        if($old){
            $oldExtra=json_decode((string)($old['extra_json']??''),true);if(!is_array($oldExtra))$oldExtra=[];
            $extra=array_merge($oldExtra,$extra);
            pdo()->prepare("UPDATE daily_plans SET day_name=?,notes=?,extra_json=?,updated_at=NOW() WHERE id=? AND workspace_id=?")->execute([$day,$notes,json_encode($extra,JSON_UNESCAPED_UNICODE),(int)$old['id'],Tenant::id()]);
            $s['updated']++;
        }else{
            pdo()->prepare("INSERT INTO daily_plans (workspace_id,plan_date,day_name,company_id,work_description,status,notes,extra_json,created_by,created_at,updated_at) VALUES (?,?,?,?,?,'باز',?,?,?,NOW(),NOW())")->execute([Tenant::id(),$date,$day,$cid,$desc,$notes,json_encode($extra,JSON_UNESCAPED_UNICODE),(int)Auth::user()['id']]);
            $s['inserted']++;
        }
    }

    private static function importMonthly(array $r,array $idx,array &$s): void
    {
        $company=self::value($r,$idx,['نام شرکت','شرکت']); $cid=self::companyId($company); if($company!==''&&!$cid)throw new RuntimeException('شرکت پیدا نشد: '.$company);
        $year=(int)(self::value($r,$idx,['سال'],'1405')?:1405);
        $month=self::value($r,$idx,['ماه']);$season=self::value($r,$idx,['فصل']);$work=self::value($r,$idx,['نوع کار']);if($work==='')throw new RuntimeException('نوع کار خالی است.');
        $deadline=self::parseDate(self::value($r,$idx,['مهلت قانونی']));
        $status=self::value($r,$idx,['وضعیت'],'باز')?:'باز';$workDay=self::value($r,$idx,['روز انجام']);
        $completed=self::parseDate(self::value($r,$idx,['تاریخ انجام']));$notes=self::value($r,$idx,['توضیحات']);
        $extra=self::extrasFromRow('monthly_plans',$r,$idx);
        $st=pdo()->prepare("SELECT id,extra_json FROM monthly_plans WHERE workspace_id=? AND company_id <=> ? AND jalali_year=? AND month_name=? AND work_type=? AND legal_deadline <=> ? LIMIT 1");
        $st->execute([Tenant::id(),$cid,$year,$month,$work,$deadline]);$old=$st->fetch();
        if($old){
            $oldExtra=json_decode((string)($old['extra_json']??''),true);if(!is_array($oldExtra))$oldExtra=[];
            $extra=array_merge($oldExtra,$extra);
            pdo()->prepare("UPDATE monthly_plans SET season=?,status=?,work_day=?,completed_date=?,notes=?,extra_json=?,updated_at=NOW() WHERE id=? AND workspace_id=?")->execute([$season,$status,$workDay,$completed,$notes,json_encode($extra,JSON_UNESCAPED_UNICODE),(int)$old['id'],Tenant::id()]);
            $s['updated']++;
        }else{
            pdo()->prepare("INSERT INTO monthly_plans (workspace_id,company_id,jalali_year,month_name,season,work_type,legal_deadline,status,work_day,completed_date,notes,extra_json,created_by,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW())")->execute([Tenant::id(),$cid,$year,$month,$season,$work,$deadline,$status,$workDay,$completed,$notes,json_encode($extra,JSON_UNESCAPED_UNICODE),(int)Auth::user()['id']]);
            $s['inserted']++;
        }
    }

    private static function importSystems(array $r,array $idx,array &$s): void
    {
        $company=self::value($r,$idx,['نام شرکت','شرکت']);$cid=self::companyId($company);if(!$cid)throw new RuntimeException('شرکت پیدا نشد: '.$company);
        $changed=false;
        foreach(self::portals() as $p){
            $u=self::value($r,$idx,[$p['url'].' | نام کاربری']);
            $pw=self::value($r,$idx,[$p['url'].' | کلمه عبور']);
            if($u===''&&$pw==='')continue;
            $old=pdo()->prepare("SELECT username,password_enc FROM portal_credentials WHERE workspace_id=? AND company_id=? AND portal_key=?");$old->execute([Tenant::id(),$cid,$p['portal_key']]);$current=$old->fetch();
            if($pw===''&&$current)$enc=$current['password_enc']; else $enc=encrypt_value($pw);
            pdo()->prepare("INSERT INTO portal_credentials (workspace_id,company_id,portal_key,username,password_enc,updated_at) VALUES (?,?,?,?,?,NOW()) ON DUPLICATE KEY UPDATE username=VALUES(username),password_enc=VALUES(password_enc),updated_at=NOW()")->execute([Tenant::id(),$cid,$p['portal_key'],$u,$enc]);
            $changed=true;
        }
        if($changed)$s['updated']++;else$s['skipped']++;
    }

    private static function importCustomField(array $r,array $idx,array &$s): void
    {
        $entity=self::value($r,$idx,['بخش']);$key=self::value($r,$idx,['کلید']);$label=self::value($r,$idx,['عنوان']);
        if(!in_array($entity,['companies','daily_plans','monthly_plans'],true))throw new RuntimeException('بخش فیلد اضافی معتبر نیست.');
        if($key===''||$label==='')throw new RuntimeException('کلید یا عنوان خالی است.');
        $type=self::value($r,$idx,['نوع'],'text')?:'text';$options=self::value($r,$idx,['گزینه‌ها']);$sort=(int)(self::value($r,$idx,['ترتیب'],'100')?:100);
        $exists=pdo()->prepare("SELECT id FROM custom_fields WHERE workspace_id=? AND entity_key=? AND field_key=?");$exists->execute([Tenant::id(),$entity,$key]);$id=$exists->fetchColumn();
        pdo()->prepare("INSERT INTO custom_fields (workspace_id,entity_key,field_key,label,field_type,options,sort_order,active,created_at,updated_at) VALUES (?,?,?,?,?,?,?,1,NOW(),NOW()) ON DUPLICATE KEY UPDATE label=VALUES(label),field_type=VALUES(field_type),options=VALUES(options),sort_order=VALUES(sort_order),active=1,updated_at=NOW()")->execute([Tenant::id(),$entity,$key,$label,$type,$options,$sort]);
        $id?$s['updated']++:$s['inserted']++;
    }

    private static function dayName(string $d): string
    {
        $days=['Saturday'=>'شنبه','Sunday'=>'یکشنبه','Monday'=>'دوشنبه','Tuesday'=>'سه‌شنبه','Wednesday'=>'چهارشنبه','Thursday'=>'پنجشنبه','Friday'=>'جمعه'];
        return $days[date('l',strtotime($d))]??'';
    }
}

class GenericXlsxWriter
{
    public static function stream(array $headers,array $rows,string $filename): never
    {
        if(!class_exists('ZipArchive'))throw new RuntimeException('افزونه ZipArchive روی هاست فعال نیست؛ از CSV استفاده کنید.');
        $tmp=tempnam(sys_get_temp_dir(),'acct-xlsx-');
        self::write($tmp,$headers,$rows);
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="'.$filename.'"');
        header('Content-Length: '.filesize($tmp));
        readfile($tmp);@unlink($tmp);exit;
    }

    private static function x(string $s): string
    {
        return htmlspecialchars($s,ENT_XML1|ENT_QUOTES,'UTF-8');
    }

    private static function col(int $n): string
    {
        $s='';while($n>0){$n--; $s=chr(65+($n%26)).$s;$n=intdiv($n,26);}return $s;
    }

    public static function write(string $path,array $headers,array $rows): void
    {
        $z=new ZipArchive();if($z->open($path,ZipArchive::CREATE|ZipArchive::OVERWRITE)!==true)throw new RuntimeException('ساخت فایل اکسل ممکن نشد.');
        $z->addFromString('[Content_Types].xml','<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>');
        $z->addFromString('_rels/.rels','<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
        $z->addFromString('xl/workbook.xml','<?xml version="1.0" encoding="UTF-8"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Data" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $z->addFromString('xl/_rels/workbook.xml.rels','<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>');
        $z->addFromString('xl/styles.xml','<?xml version="1.0" encoding="UTF-8"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><fonts count="2"><font><sz val="11"/><name val="Arial"/></font><font><b/><sz val="11"/><name val="Arial"/></font></fonts><fills count="1"><fill><patternFill patternType="none"/></fill></fills><borders count="1"><border/></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="2"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0"/></cellXfs></styleSheet>');
        $all=array_merge([$headers],$rows);$xml='<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetViews><sheetView workbookViewId="0" rightToLeft="1"/></sheetViews><sheetData>';
        foreach($all as $ri=>$row){
            $r=$ri+1;$xml.='<row r="'.$r.'">';
            foreach(array_values($row) as $ci=>$v){
                $ref=self::col($ci+1).$r;$style=$ri===0?' s="1"':'';
                $xml.='<c r="'.$ref.'" t="inlineStr"'.$style.'><is><t xml:space="preserve">'.self::x((string)$v).'</t></is></c>';
            }
            $xml.='</row>';
        }
        $xml.='</sheetData><autoFilter ref="A1:'.self::col(max(1,count($headers))).max(1,count($all)).'"/><sheetProtection sheet="0"/></worksheet>';
        $z->addFromString('xl/worksheets/sheet1.xml',$xml);$z->close();
    }
}
