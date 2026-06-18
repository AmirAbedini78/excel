<?php
class XlsxReader
{
    public static function read(string $file): array
    {
        if (!class_exists('ZipArchive')) throw new RuntimeException('افزونه PHP ZipArchive روی هاست فعال نیست.');
        $zip = new ZipArchive();
        if ($zip->open($file) !== true) throw new RuntimeException('فایل اکسل قابل خواندن نیست.');
        $ns = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
        $relsNs = 'http://schemas.openxmlformats.org/package/2006/relationships';
        $officeNs = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
        $shared = [];
        if (($sxml = $zip->getFromName('xl/sharedStrings.xml')) !== false) {
            $root = simplexml_load_string($sxml);
            foreach ($root->si as $si) {
                $txt = '';
                if (isset($si->t)) $txt .= (string)$si->t;
                foreach ($si->r as $r) $txt .= (string)$r->t;
                $shared[] = $txt;
            }
        }
        $workbook = simplexml_load_string($zip->getFromName('xl/workbook.xml'));
        $rels = simplexml_load_string($zip->getFromName('xl/_rels/workbook.xml.rels'));
        $relMap = [];
        foreach ($rels->Relationship as $rel) $relMap[(string)$rel['Id']] = (string)$rel['Target'];
        $sheets = [];
        foreach ($workbook->sheets->sheet as $sheet) {
            $attrs = $sheet->attributes($officeNs);
            $rid = (string)$attrs['id'];
            $target = $relMap[$rid] ?? '';
            $path = 'xl/' . ltrim($target, '/');
            if (!str_starts_with($path, 'xl/worksheets')) $path = 'xl/worksheets/' . basename($target);
            $sheets[(string)$sheet['name']] = $path;
        }
        $out = [];
        foreach ($sheets as $name=>$path) {
            $xml = $zip->getFromName($path); if ($xml === false) continue;
            $root = simplexml_load_string($xml);
            $rows = [];
            foreach ($root->sheetData->row as $row) {
                $arr = [];
                foreach ($row->c as $c) {
                    $ref = (string)$c['r']; $col = self::colIndex(preg_replace('/\d+/', '', $ref));
                    $type = (string)$c['t']; $val = '';
                    if ($type === 's') $val = $shared[(int)$c->v] ?? '';
                    elseif ($type === 'inlineStr') $val = (string)$c->is->t;
                    else $val = isset($c->v) ? (string)$c->v : '';
                    $arr[$col] = trim($val);
                }
                if ($arr) {
                    $max = max(array_keys($arr)); $line=[];
                    for ($i=1; $i<=$max; $i++) $line[] = $arr[$i] ?? '';
                    $rows[] = $line;
                }
            }
            $out[$name] = $rows;
        }
        $zip->close();
        return $out;
    }
    private static function colIndex(string $letters): int
    {
        $n=0; foreach (str_split($letters) as $ch) $n = $n*26 + (ord($ch)-64); return $n;
    }
    public static function excelSerialToDate($v): ?string
    {
        if (!is_numeric($v)) return null;
        $serial = (float)$v; if ($serial < 20000 || $serial > 90000) return null;
        return gmdate('Y-m-d', (int)(($serial - 25569) * 86400));
    }
}

class XlsxImporter
{
    public static function import(string $file): array
    {
        $sheets = XlsxReader::read($file);
        $stats = ['companies'=>0,'tasks'=>0,'schedules'=>0,'followups'=>0,'messages'=>[]];
        foreach ($sheets as $sheetName=>$rows) {
            $cleanName = str_replace(["\u{200c}", ' '], '', $sheetName);
            if (!$rows || count($rows) < 2) continue;
            $headers = array_map(fn($h)=>str_replace(["\u{200c}",' '],'', trim($h)), $rows[0]);
            $idx = array_flip($headers);
            if (str_contains($cleanName, 'شرکت')) $stats['companies'] += self::importCompanies($rows, $idx);
            elseif (str_contains($cleanName, 'کارهای1405') || str_contains($cleanName, 'MasterTaskList') || str_contains($cleanName, 'کارها')) $stats['tasks'] += self::importTasks($rows, $idx);
            elseif (str_contains($cleanName, 'برنامهحضور') || str_contains($cleanName, 'برنامههفتگی')) $stats['schedules'] += self::importSchedules($rows, $idx);
            elseif (str_contains($cleanName, 'پیگیری')) $stats['followups'] += self::importFollowups($rows, $idx);
        }
        return $stats;
    }
    private static function cell(array $row, array $idx, array $names, int $fallback=-1): string
    {
        foreach ($names as $n) { $key = str_replace(["\u{200c}",' '],'',$n); if (isset($idx[$key])) return trim($row[$idx[$key]] ?? ''); }
        return $fallback >=0 ? trim($row[$fallback] ?? '') : '';
    }
    private static function findCompanyId(string $name): ?int
    {
        if (!$name) return null;
        $st = pdo()->prepare("SELECT id FROM companies WHERE name=? LIMIT 1"); $st->execute([$name]); $id = $st->fetchColumn();
        if ($id) return (int)$id;
        $st = pdo()->prepare("INSERT INTO companies (name,active,created_at,updated_at) VALUES (?,?,NOW(),NOW())"); $st->execute([$name,1]);
        return (int)pdo()->lastInsertId();
    }
    private static function importCompanies(array $rows, array $idx): int
    {
        $n=0;
        for ($i=1; $i<count($rows); $i++) {
            $r=$rows[$i]; $name = self::cell($r,$idx,['نامشرکت','شرکت'],0); if (!$name) continue;
            $st = pdo()->prepare("INSERT INTO companies (name,type,attendance,software,manager_name,financial_manager,phone,address,tax_username,insurance_code,notes,active,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW()) ON DUPLICATE KEY UPDATE type=VALUES(type), attendance=VALUES(attendance), software=VALUES(software), manager_name=VALUES(manager_name), financial_manager=VALUES(financial_manager), phone=VALUES(phone), address=VALUES(address), tax_username=VALUES(tax_username), insurance_code=VALUES(insurance_code), notes=VALUES(notes), active=1, updated_at=NOW()");
            $st->execute([$name, self::cell($r,$idx,['نوعمجموعه']), self::cell($r,$idx,['روز/ساعتحضور','حضورهفتگی']), self::cell($r,$idx,['نرمافزارحسابداری','نرمافزار']), self::cell($r,$idx,['مدیرعامل']), self::cell($r,$idx,['مدیرمالی']), self::cell($r,$idx,['شمارهتماس','تلفن']), self::cell($r,$idx,['آدرس']), self::cell($r,$idx,['نامکاربریسامانهمالیات']), self::cell($r,$idx,['کدکارگاه/بیمه','کدکارگاه']), self::cell($r,$idx,['توضیحات']), 1]);
            $n++;
        }
        return $n;
    }
    private static function importTasks(array $rows, array $idx): int
    {
        $n=0;
        for ($i=1; $i<count($rows); $i++) {
            $r=$rows[$i]; $company = self::cell($r,$idx,['شرکت'],1); $title = self::cell($r,$idx,['شرحکار','کار','عنوان'],3); if (!$title) continue;
            $cid = self::findCompanyId($company ?: 'همه شرکت‌ها');
            $dueJ = self::cell($r,$idx,['تاریخسررسیدشمسی','سررسیدشمسی']);
            $due = self::cell($r,$idx,['تاریخسررسیدمیلادی','سررسیدمیلادی']);
            if ($dueJ && ($d=Jalali::parse($dueJ))) $due=$d; else if (($d=XlsxReader::excelSerialToDate($due))) $due=$d;
            if (!$due || !preg_match('/^\d{4}-\d{2}-\d{2}$/',$due)) $due=null;
            $st = pdo()->prepare("INSERT INTO tasks (company_id,code,category,title,frequency,period_label,due_date,reminder_days,priority,status,description,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW())");
            $st->execute([$cid, self::cell($r,$idx,['کدکار'],0), self::cell($r,$idx,['گروهکار','دسته'],2), $title, self::cell($r,$idx,['تناوب'],4), self::cell($r,$idx,['دوره/ماه','ماه'],5), $due, (int)(self::cell($r,$idx,['روزیادآوری'],8) ?: 5), self::cell($r,$idx,['اولویت'],10) ?: 'متوسط', self::cell($r,$idx,['وضعیت'],11) ?: 'باز', self::cell($r,$idx,['توضیحات'])]);
            $n++;
        }
        return $n;
    }
    private static function importSchedules(array $rows, array $idx): int
    {
        $n=0; pdo()->exec("TRUNCATE TABLE weekly_schedules");
        for ($i=1; $i<count($rows); $i++) {
            $r=$rows[$i]; $day=self::cell($r,$idx,['روز'],1); $company=self::cell($r,$idx,['شرکت'],3); if (!$day || !$company) continue;
            $cid=self::findCompanyId($company); $st=pdo()->prepare("INSERT INTO weekly_schedules (weekday,shift_label,company_id,attendance_type,notes) VALUES (?,?,?,?,?)");
            $st->execute([$day,self::cell($r,$idx,['بازهحضور'],2),$cid,self::cell($r,$idx,['نوعحضور'],4),self::cell($r,$idx,['کارهایپیشنهادیهامنروز','کارهایپیشنهادیهمانروز'],5)]); $n++;
        }
        return $n;
    }
    private static function importFollowups(array $rows, array $idx): int
    {
        $n=0;
        for ($i=1; $i<count($rows); $i++) {
            $r=$rows[$i]; $subject=self::cell($r,$idx,['موضوع/درخواست','موضوع'],3); if (!$subject) continue;
            $cid=self::findCompanyId(self::cell($r,$idx,['شرکت'],1)); $jalali=self::cell($r,$idx,['تاریخپیگیریشمسی']); $date=Jalali::parse($jalali) ?: XlsxReader::excelSerialToDate(self::cell($r,$idx,['تاریخپیگیریمیلادی']));
            $st=pdo()->prepare("INSERT INTO followups (company_id,requester,subject,next_action,followup_date,priority,status,notes,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,NOW(),NOW())");
            $st->execute([$cid,self::cell($r,$idx,['درخواستدهنده'],2),$subject,self::cell($r,$idx,['اقدامبعدی'],4),$date,self::cell($r,$idx,['اولویت'],7) ?: 'متوسط',self::cell($r,$idx,['وضعیت'],8) ?: 'باز',self::cell($r,$idx,['نتیجه/توضیحات','توضیحات'],10)]); $n++;
        }
        return $n;
    }
}

class XlsxWriter
{
    public static function stream(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
        self::write($tmp);
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="accounting-manager-export-'.date('Ymd-His').'.xlsx"');
        header('Content-Length: '.filesize($tmp));
        readfile($tmp); unlink($tmp); exit;
    }
    public static function write(string $path): void
    {
        $zip = new ZipArchive(); if ($zip->open($path, ZipArchive::CREATE|ZipArchive::OVERWRITE)!==true) throw new RuntimeException('Cannot create xlsx');
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/><Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/></Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
        $zip->addFromString('docProps/core.xml', '<?xml version="1.0"?><cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/"><dc:creator>Accounting Manager</dc:creator></cp:coreProperties>');
        $sheets = self::data(); $sheetXmls=[]; $rels=[]; $sheetTags=[]; $i=1;
        foreach ($sheets as $name=>$rows) { $sheetXmls[$i] = self::sheetXml($rows); $rels[]='<Relationship Id="rId'.$i.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet'.$i.'.xml"/>'; $sheetTags[]='<sheet name="'.self::x($name).'" sheetId="'.$i.'" r:id="rId'.$i.'"/>'; $i++; }
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>'.implode('',$sheetTags).'</sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'.implode('',$rels).'<Relationship Id="rId'.($i+1).'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>');
        $zip->addFromString('xl/styles.xml', '<?xml version="1.0"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><fonts count="1"><font><sz val="11"/><name val="Tahoma"/></font></fonts><fills count="1"><fill><patternFill patternType="none"/></fill></fills><borders count="1"><border/></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/></cellXfs></styleSheet>');
        foreach ($sheetXmls as $n=>$xml) $zip->addFromString('xl/worksheets/sheet'.$n.'.xml',$xml);
        $zip->close();
    }
    private static function data(): array
    {
        $companies = pdo()->query("SELECT name,type,attendance,software,manager_name,financial_manager,phone,address,tax_username,insurance_code,notes FROM companies ORDER BY id")->fetchAll(PDO::FETCH_NUM);
        array_unshift($companies, ['نام شرکت','نوع مجموعه','روز/ساعت حضور','نرم‌افزار حسابداری','مدیرعامل','مدیر مالی','شماره تماس','آدرس','نام کاربری سامانه مالیات','کد کارگاه/بیمه','توضیحات']);
        $tasks = pdo()->query("SELECT t.code,c.name,t.category,t.title,t.frequency,t.period_label,t.due_date,t.reminder_days,t.priority,t.status,t.description FROM tasks t LEFT JOIN companies c ON c.id=t.company_id ORDER BY t.due_date,t.id")->fetchAll(PDO::FETCH_NUM);
        $tasks = array_map(function($r){ $r[6] = $r[6] ? Jalali::fromGregorian($r[6]) : ''; return $r; }, $tasks);
        array_unshift($tasks, ['کد کار','شرکت','گروه کار','شرح کار','تناوب','دوره/ماه','تاریخ سررسید شمسی','روز یادآوری','اولویت','وضعیت','توضیحات']);
        $follow = pdo()->query("SELECT c.name,f.requester,f.subject,f.next_action,f.followup_date,f.priority,f.status,f.notes FROM followups f LEFT JOIN companies c ON c.id=f.company_id ORDER BY f.followup_date,f.id")->fetchAll(PDO::FETCH_NUM);
        $follow = array_map(function($r){ $r[4] = $r[4] ? Jalali::fromGregorian($r[4]) : ''; return $r; }, $follow);
        array_unshift($follow, ['شرکت','درخواست‌دهنده','موضوع/درخواست','اقدام بعدی','تاریخ پیگیری شمسی','اولویت','وضعیت','توضیحات']);
        return ['شرکت‌ها'=>$companies,'کارها'=>$tasks,'پیگیری‌ها'=>$follow];
    }
    private static function sheetXml(array $rows): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" rightToLeft="1"><sheetData>';
        foreach ($rows as $ri=>$row) { $r=$ri+1; $xml.='<row r="'.$r.'">'; foreach ($row as $ci=>$v) { $col=self::col($ci+1); $xml.='<c r="'.$col.$r.'" t="inlineStr"><is><t>'.self::x($v).'</t></is></c>'; } $xml.='</row>'; }
        return $xml.'</sheetData></worksheet>';
    }
    private static function col(int $n): string { $s=''; while($n>0){$m=($n-1)%26; $s=chr(65+$m).$s; $n=intdiv($n-$m,26);} return $s; }
    private static function x($s): string { return htmlspecialchars((string)$s, ENT_XML1|ENT_COMPAT, 'UTF-8'); }
}
