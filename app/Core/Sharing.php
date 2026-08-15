<?php
final class Sharing
{
    public static function workspaceCode(?int $workspaceId=null): string
    {
        $wid=$workspaceId??Tenant::id();
        if($wid<=0)return'';
        $st=pdo()->prepare("SELECT share_code FROM workspaces WHERE id=? LIMIT 1");
        $st->execute([$wid]);$code=trim((string)$st->fetchColumn());
        if($code!=='')return$code;

        for($i=0;$i<10;$i++){
            $code=strtoupper(substr(bin2hex(random_bytes(8)),0,16));
            try{
                pdo()->prepare("UPDATE workspaces SET share_code=?,updated_at=NOW() WHERE id=? AND (share_code IS NULL OR share_code='')")
                    ->execute([$code,$wid]);
                $st->execute([$wid]);$saved=trim((string)$st->fetchColumn());
                if($saved!=='')return$saved;
            }catch(Throwable $e){}
        }
        throw new RuntimeException('ساخت کد اشتراک محیط کاری انجام نشد.');
    }

    public static function availableTargets(): array
    {
        $source=Tenant::id();$uid=(int)(Auth::user()['id']??0);
        if(Tenant::isPlatformAdmin()){
            $st=pdo()->prepare("SELECT id,name,share_code FROM workspaces WHERE id<>? AND status='active' ORDER BY name");
            $st->execute([$source]);return$st->fetchAll();
        }
        if(!$uid)return[];
        $st=pdo()->prepare("SELECT w.id,w.name,w.share_code FROM workspace_members wm
            JOIN workspaces w ON w.id=wm.workspace_id
            WHERE wm.user_id=? AND wm.status='active' AND w.status='active' AND w.id<>?
            ORDER BY w.name");
        $st->execute([$uid,$source]);return$st->fetchAll();
    }

    private static function resolveTarget(string $targetCode,int $targetWorkspaceId): array
    {
        $source=Tenant::id();$uid=(int)(Auth::user()['id']??0);
        if($targetWorkspaceId>0){
            $allowed=Tenant::isPlatformAdmin();
            if(!$allowed&&$uid){
                $st=pdo()->prepare("SELECT 1 FROM workspace_members WHERE workspace_id=? AND user_id=? AND status='active' LIMIT 1");
                $st->execute([$targetWorkspaceId,$uid]);$allowed=(bool)$st->fetchColumn();
            }
            if(!$allowed)throw new RuntimeException('این Workspace مقصد در دسترس شما نیست؛ از کد اشتراک مقصد استفاده کنید.');
            $st=pdo()->prepare("SELECT id,name,status FROM workspaces WHERE id=? LIMIT 1");
            $st->execute([$targetWorkspaceId]);$target=$st->fetch();
        }else{
            $targetCode=strtoupper(trim($targetCode));
            if($targetCode==='')throw new RuntimeException('یک Workspace مقصد انتخاب کنید یا کد اشتراک مقصد را وارد کنید.');
            $st=pdo()->prepare("SELECT id,name,status FROM workspaces WHERE share_code=? LIMIT 1");
            $st->execute([$targetCode]);$target=$st->fetch();
        }
        if(!$target||$target['status']!=='active')throw new RuntimeException('محیط کاری مقصد پیدا نشد یا فعال نیست.');
        if((int)$target['id']===$source)throw new RuntimeException('نمی‌توانید یک محیط کاری را با خودش به اشتراک بگذارید.');
        return$target;
    }

    public static function create(string $targetCode,array $companyIds,bool $daily=true,bool $monthly=true,bool $phonebook=true,int $targetWorkspaceId=0): array
    {
        Tenant::requirePermission('shares.manage');
        $source=Tenant::id();
        $target=self::resolveTarget($targetCode,$targetWorkspaceId);
        $targetId=(int)$target['id'];

        $ids=array_values(array_unique(array_filter(array_map('intval',$companyIds))));
        if(!$ids)throw new RuntimeException('حداقل یک شرکت را برای اشتراک انتخاب کنید.');

        $check=pdo()->prepare("SELECT id,name FROM companies WHERE id=? AND workspace_id=? AND active=1 LIMIT 1");
        $ins=pdo()->prepare("INSERT INTO workspace_company_shares
            (source_workspace_id,target_workspace_id,company_id,share_daily,share_monthly,share_phonebook,access_level,status,created_by,created_at,updated_at)
            VALUES (?,?,?,?,?,?,'view','active',?,NOW(),NOW())
            ON DUPLICATE KEY UPDATE share_daily=VALUES(share_daily),share_monthly=VALUES(share_monthly),
                share_phonebook=VALUES(share_phonebook),access_level='view',status='active',created_by=VALUES(created_by),updated_at=NOW()");
        $created=[];
        foreach($ids as $companyId){
            $check->execute([$companyId,$source]);$company=$check->fetch();
            if(!$company)continue;
            $ins->execute([$source,$targetId,$companyId,$daily?1:0,$monthly?1:0,$phonebook?1:0,(int)Auth::user()['id']]);
            $created[]=['company_id'=>$companyId,'company_name'=>$company['name'],'target_workspace'=>$target['name']];
        }
        if(!$created)throw new RuntimeException('هیچ شرکت معتبری برای اشتراک پیدا نشد.');

        RuntimeCache::clearWorkspace($source);
        RuntimeCache::clearWorkspace($targetId);
        Audit::log('share.create','workspace_company_shares',0,'اشتراک داده با محیط دیگر',null,[
            'target_workspace_id'=>$targetId,'companies'=>array_column($created,'company_id')
        ]);
        return$created;
    }

    public static function revoke(int $shareId): void
    {
        Tenant::requirePermission('shares.manage');
        $st=pdo()->prepare("SELECT * FROM workspace_company_shares WHERE id=? AND source_workspace_id=? LIMIT 1");
        $st->execute([$shareId,Tenant::id()]);$row=$st->fetch();
        if(!$row)throw new RuntimeException('اشتراک پیدا نشد.');
        pdo()->prepare("UPDATE workspace_company_shares SET status='revoked',updated_at=NOW() WHERE id=? AND source_workspace_id=?")
            ->execute([$shareId,Tenant::id()]);
        RuntimeCache::clearWorkspace(Tenant::id());
        RuntimeCache::clearWorkspace((int)$row['target_workspace_id']);
        Audit::log('share.revoke','workspace_company_shares',$shareId,'لغو اشتراک داده',$row,null);
    }

    public static function revokeIncoming(int $shareId): void
    {
        Tenant::requirePermission('shares.manage');
        $st=pdo()->prepare("SELECT * FROM workspace_company_shares WHERE id=? AND target_workspace_id=? AND status='active' LIMIT 1");
        $st->execute([$shareId,Tenant::id()]);$row=$st->fetch();
        if(!$row)throw new RuntimeException('اشتراک دریافتی پیدا نشد.');
        pdo()->prepare("UPDATE workspace_company_shares SET status='revoked',updated_at=NOW() WHERE id=? AND target_workspace_id=?")
            ->execute([$shareId,Tenant::id()]);
        RuntimeCache::clearWorkspace(Tenant::id());
        RuntimeCache::clearWorkspace((int)$row['source_workspace_id']);
        Audit::log('share.reject','workspace_company_shares',$shareId,'توقف دریافت داده اشتراکی',$row,null);
    }

    public static function outgoing(): array
    {
        Tenant::requirePermission('shares.view');
        $st=pdo()->prepare("SELECT s.*,c.name company_name,w.name target_workspace_name,w.share_code target_share_code,u.name created_by_name
            FROM workspace_company_shares s
            JOIN companies c ON c.id=s.company_id
            JOIN workspaces w ON w.id=s.target_workspace_id
            LEFT JOIN users u ON u.id=s.created_by
            WHERE s.source_workspace_id=? AND s.status='active'
            ORDER BY w.name,c.name,s.id DESC");
        $st->execute([Tenant::id()]);return$st->fetchAll();
    }

    public static function incoming(): array
    {
        Tenant::requirePermission('shares.view');
        $st=pdo()->prepare("SELECT s.*,c.name company_name,c.company_type,c.legal_personality,c.national_id,c.economic_code,c.registration_number,c.phone company_phone,c.ceo_name,c.ceo_mobile,c.software,
            w.name source_workspace_name,w.share_code source_share_code
            FROM workspace_company_shares s
            JOIN companies c ON c.id=s.company_id
            JOIN workspaces w ON w.id=s.source_workspace_id
            WHERE s.target_workspace_id=? AND s.status='active' AND c.active=1
            ORDER BY w.name,c.name,s.id DESC");
        $st->execute([Tenant::id()]);return$st->fetchAll();
    }

    public static function incomingData(): array
    {
        Tenant::requirePermission('shares.view');
        $shares=self::incoming();
        if(!$shares)return[];

        // V5 performance: fetch shared datasets in three bulk queries instead of 3 queries per share.
        $companyIds=array_values(array_unique(array_map(fn($s)=>(int)$s['company_id'],$shares)));
        $sourceIds=array_values(array_unique(array_map(fn($s)=>(int)$s['source_workspace_id'],$shares)));
        $dailyBy=[];$monthlyBy=[];$phoneBy=[];
        $marks=implode(',',array_fill(0,count($companyIds),'?'));
        $sourceMarks=implode(',',array_fill(0,count($sourceIds),'?'));
        $params=[...$sourceIds,...$companyIds];

        $needsDaily=(bool)array_filter($shares,fn($s)=>(int)$s['share_daily']===1);
        $needsMonthly=(bool)array_filter($shares,fn($s)=>(int)$s['share_monthly']===1);
        $needsPhone=(bool)array_filter($shares,fn($s)=>(int)$s['share_phonebook']===1);

        if($needsDaily){
            $st=pdo()->prepare("SELECT d.*,u.name created_by_name FROM daily_plans d LEFT JOIN users u ON u.id=d.created_by
                WHERE d.workspace_id IN ($sourceMarks) AND d.company_id IN ($marks)
                ORDER BY d.plan_date DESC,d.id DESC");
            $st->execute($params);
            foreach($st->fetchAll() as $r)$dailyBy[(int)$r['workspace_id'].':'.(int)$r['company_id']][]=$r;
        }
        if($needsMonthly){
            $st=pdo()->prepare("SELECT m.*,u.name created_by_name FROM monthly_plans m LEFT JOIN users u ON u.id=m.created_by
                WHERE m.workspace_id IN ($sourceMarks) AND m.company_id IN ($marks)
                ORDER BY m.legal_deadline IS NULL,m.legal_deadline,m.id DESC");
            $st->execute($params);
            foreach($st->fetchAll() as $r)$monthlyBy[(int)$r['workspace_id'].':'.(int)$r['company_id']][]=$r;
        }
        if($needsPhone){
            $st=pdo()->prepare("SELECT p.*,u.name created_by_name FROM phonebook_entries p LEFT JOIN users u ON u.id=p.created_by
                WHERE p.workspace_id IN ($sourceMarks) AND p.client_company_id IN ($marks)
                ORDER BY p.contacted_date DESC,p.id DESC");
            $st->execute($params);
            foreach($st->fetchAll() as $r)$phoneBy[(int)$r['workspace_id'].':'.(int)$r['client_company_id']][]=$r;
        }

        $out=[];
        foreach($shares as $s){
            $key=(int)$s['source_workspace_id'].':'.(int)$s['company_id'];
            $out[]=[
                'share'=>$s,
                'daily'=>(int)$s['share_daily']===1?array_slice($dailyBy[$key]??[],0,150):[],
                'monthly'=>(int)$s['share_monthly']===1?array_slice($monthlyBy[$key]??[],0,200):[],
                'phonebook'=>(int)$s['share_phonebook']===1?array_slice($phoneBy[$key]??[],0,150):[],
            ];
        }
        return$out;
    }

    public static function targetCanViewCompany(int $targetWorkspaceId,int $companyId): bool
    {
        $st=pdo()->prepare("SELECT 1 FROM workspace_company_shares
            WHERE target_workspace_id=? AND company_id=? AND status='active' LIMIT 1");
        $st->execute([$targetWorkspaceId,$companyId]);return(bool)$st->fetchColumn();
    }
}
