<?php
final class FileLibrary
{
    public static function ensureSchema(): void
    {
        $pdo=pdo();
        $pdo->exec("CREATE TABLE IF NOT EXISTS library_files (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            workspace_id INT NOT NULL,
            uploaded_by INT NULL,
            original_name VARCHAR(255) NOT NULL,
            storage_path VARCHAR(700) NOT NULL,
            mime_type VARCHAR(150) NULL,
            extension VARCHAR(30) NULL,
            size_bytes BIGINT NOT NULL DEFAULT 0,
            sha256 CHAR(64) NULL,
            title VARCHAR(255) NULL,
            description TEXT NULL,
            folder VARCHAR(190) NULL,
            status VARCHAR(30) NOT NULL DEFAULT 'active',
            created_at DATETIME NULL, updated_at DATETIME NULL,
            INDEX idx_library_workspace (workspace_id,status,created_at),
            INDEX idx_library_hash (workspace_id,sha256)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $pdo->exec("CREATE TABLE IF NOT EXISTS file_attachments (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            workspace_id INT NOT NULL,
            file_id BIGINT NOT NULL,
            entity_key VARCHAR(100) NOT NULL,
            record_id BIGINT NOT NULL,
            attached_by INT NULL,
            created_at DATETIME NULL,
            UNIQUE KEY uniq_file_attachment (workspace_id,file_id,entity_key,record_id),
            INDEX idx_attachment_entity (workspace_id,entity_key,record_id),
            INDEX idx_attachment_file (workspace_id,file_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    public static function upload(array $file): array
    {
        Tenant::requirePermission('files.upload');self::ensureSchema();
        if(empty($file['tmp_name'])||!is_uploaded_file($file['tmp_name']))throw new RuntimeException('فایل دریافت نشد.');
        $max=max(1,min(100,(int)setting('library_max_upload_mb','20')))*1024*1024;
        if((int)$file['size']>$max)throw new RuntimeException('حجم فایل بیشتر از حد مجاز است.');
        $name=basename((string)$file['name']);$ext=mb_strtolower(pathinfo($name,PATHINFO_EXTENSION));
        $blocked=['php','phtml','phar','cgi','pl','py','sh','exe','dll','js','html','htm','svg'];
        if(in_array($ext,$blocked,true))throw new RuntimeException('این نوع فایل برای آپلود مجاز نیست.');
        $mime=(new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name'])?:'application/octet-stream';
        $wid=Tenant::id();$dir=APP_ROOT.'/storage/uploads/'.$wid.'/'.date('Y/m');
        if(!is_dir($dir)&&!mkdir($dir,0750,true)&&!is_dir($dir))throw new RuntimeException('ساخت پوشه آپلود ممکن نشد.');
        $safe=bin2hex(random_bytes(18)).($ext?'.'.$ext:'');$dest=$dir.'/'.$safe;
        if(!move_uploaded_file($file['tmp_name'],$dest))throw new RuntimeException('ذخیره فایل انجام نشد.');
        $relative=ltrim(str_replace(APP_ROOT,'',$dest),'/\\');$sha=hash_file('sha256',$dest);
        $st=pdo()->prepare("INSERT INTO library_files (workspace_id,uploaded_by,original_name,storage_path,mime_type,extension,size_bytes,sha256,title,status,created_at,updated_at)
            VALUES (?,?,?,?,?,?,?,?,?,'active',NOW(),NOW())");
        $st->execute([$wid,(int)Auth::user()['id'],$name,$relative,$mime,$ext,(int)$file['size'],$sha,pathinfo($name,PATHINFO_FILENAME)]);
        $id=(int)pdo()->lastInsertId();Audit::log('file.upload','library_files',$id,'آپلود فایل',null,['name'=>$name,'size'=>(int)$file['size']]);
        return self::get($id)??[];
    }

    public static function get(int $id): ?array
    {
        self::ensureSchema();$st=pdo()->prepare("SELECT f.*,u.name uploaded_by_name,(SELECT COUNT(*) FROM file_attachments a WHERE a.workspace_id=f.workspace_id AND a.file_id=f.id) attachments_count
            FROM library_files f LEFT JOIN users u ON u.id=f.uploaded_by WHERE f.id=? AND f.workspace_id=? AND f.status='active' LIMIT 1");
        $st->execute([$id,Tenant::id()]);$r=$st->fetch();return $r?:null;
    }

    public static function list(string $q='',int $limit=200): array
    {
        Tenant::requirePermission('files.view');self::ensureSchema();$p=[Tenant::id()];$w="f.workspace_id=? AND f.status='active'";
        if($q!==''){$w.=" AND (f.original_name LIKE ? OR f.title LIKE ? OR f.description LIKE ? OR f.folder LIKE ?)";$l="%$q%";array_push($p,$l,$l,$l,$l);}
        $limit=max(1,min(500,$limit));$st=pdo()->prepare("SELECT f.*,u.name uploaded_by_name,(SELECT COUNT(*) FROM file_attachments a WHERE a.workspace_id=f.workspace_id AND a.file_id=f.id) attachments_count
            FROM library_files f LEFT JOIN users u ON u.id=f.uploaded_by WHERE $w ORDER BY f.id DESC LIMIT $limit");$st->execute($p);return $st->fetchAll();
    }

    public static function delete(int $id): void
    {
        Tenant::requirePermission('files.delete');$f=self::get($id);if(!$f)throw new RuntimeException('فایل پیدا نشد.');
        pdo()->prepare("UPDATE library_files SET status='deleted',updated_at=NOW() WHERE id=? AND workspace_id=?")->execute([$id,Tenant::id()]);
        pdo()->prepare("DELETE FROM file_attachments WHERE workspace_id=? AND file_id=?")->execute([Tenant::id(),$id]);
        Audit::log('file.delete','library_files',$id,'حذف فایل از لایبرری',['name'=>$f['original_name']],null);
    }

    public static function attachments(string $entity,int $recordId): array
    {
        self::ensureSchema();$st=pdo()->prepare("SELECT f.*,a.created_at attached_at FROM file_attachments a JOIN library_files f ON f.id=a.file_id
            WHERE a.workspace_id=? AND a.entity_key=? AND a.record_id=? AND f.status='active' ORDER BY a.id DESC");
        $st->execute([Tenant::id(),$entity,$recordId]);return $st->fetchAll();
    }

    public static function attach(string $entity,int $recordId,array $fileIds): void
    {
        Tenant::requirePermission('files.attach');self::ensureSchema();if(!$recordId)return;
        $allowed=['companies','daily_plans','monthly_plans','custom_fields','systems_company','notes'];
        if(!in_array($entity,$allowed,true))throw new RuntimeException('اتچ برای این بخش مجاز نیست.');
        $ins=pdo()->prepare("INSERT IGNORE INTO file_attachments (workspace_id,file_id,entity_key,record_id,attached_by,created_at) VALUES (?,?,?,?,?,NOW())");
        foreach(array_unique(array_filter(array_map('intval',$fileIds))) as $fid){
            if(!self::get($fid))continue;$ins->execute([Tenant::id(),$fid,$entity,$recordId,(int)Auth::user()['id']]);
        }
        Audit::log('file.attach',$entity,$recordId,'اتچ فایل',null,['file_ids'=>$fileIds]);
    }

    public static function detach(string $entity,int $recordId,int $fileId): void
    {
        Tenant::requirePermission('files.attach');pdo()->prepare("DELETE FROM file_attachments WHERE workspace_id=? AND entity_key=? AND record_id=? AND file_id=?")->execute([Tenant::id(),$entity,$recordId,$fileId]);
        Audit::log('file.detach',$entity,$recordId,'حذف اتچ',null,['file_id'=>$fileId]);
    }

    public static function syncFromPost(string $entity,int $recordId): void
    {
        $ids=$_POST['attachment_file_ids']??[];if(!is_array($ids))$ids=[$ids];if($ids)self::attach($entity,$recordId,$ids);
    }
}
