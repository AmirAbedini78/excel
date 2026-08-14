<?php
final class V4Module
{
    public static function ensureSchema(): void
    {
        Tenant::ensureSchema();Audit::ensureSchema();FileLibrary::ensureSchema();
        pdo()->exec("CREATE TABLE IF NOT EXISTS notes (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            workspace_id INT NOT NULL,
            user_id INT NULL,
            title VARCHAR(255) NULL,
            body MEDIUMTEXT NOT NULL,
            pinned TINYINT(1) NOT NULL DEFAULT 0,
            color_key VARCHAR(40) NULL,
            ai_status VARCHAR(40) NOT NULL DEFAULT 'idle',
            ai_result_json JSON NULL,
            created_at DATETIME NULL, updated_at DATETIME NULL,
            INDEX idx_notes_workspace (workspace_id,pinned,updated_at),
            INDEX idx_notes_user (workspace_id,user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    public static function handle(string $action): void
    {
        self::ensureSchema();
        if($action==='v4_switch_workspace'){Tenant::switch((int)($_POST['workspace_id']??0));Audit::log('workspace.switch','workspaces',Tenant::id(),'تغییر محیط کاری');redirect($_SERVER['HTTP_REFERER']??'index.php');}
        if($action==='v4_platform_create_workspace'){self::platformCreateWorkspace();return;}
        if($action==='v4_platform_update_workspace'){self::platformUpdateWorkspace();return;}
        if($action==='v4_platform_enter_workspace'){self::platformEnterWorkspace();return;}
        if($action==='v4_save_note'){self::saveNote();return;}
        if($action==='v4_delete_note'){self::deleteNote();return;}
        if($action==='v4_create_workspace'){self::createWorkspace();return;}
        if($action==='v4_add_member'){self::addMember();return;}
        if($action==='v4_update_member'){self::updateMember();return;}
        if($action==='v4_remove_member'){self::removeMember();return;}
        if($action==='v4_save_role'){self::saveRole();return;}
    }

    public static function renderNotes(): void
    {
        Tenant::requirePermission('notes.view');self::ensureSchema();
        render_header('نوت‌ها','یادداشت‌های کاری؛ آماده برای تحلیل و تبدیل به داده توسط AI Agent در نسخه‌های بعدی.');
        $qv=trim((string)($_GET['q']??''));$p=[Tenant::id()];$w='workspace_id=?';
        if($qv!==''){$w.=' AND (title LIKE ? OR body LIKE ?)';$l="%$qv%";array_push($p,$l,$l);}
        $st=pdo()->prepare("SELECT n.*,u.name user_name FROM notes n LEFT JOIN users u ON u.id=n.user_id WHERE $w ORDER BY pinned DESC,updated_at DESC,id DESC LIMIT 300");$st->execute($p);$rows=$st->fetchAll();
        ?>
        <section class="notes-toolbar card">
          <?php if(Tenant::can('notes.create')): ?><button class="btn primary" type="button" data-new-note>+ نوت جدید</button><?php endif; ?>
          <form method="get" class="notes-search"><input type="hidden" name="page" value="notes"><input name="q" value="<?=h($qv)?>" placeholder="جستجو در نوت‌ها..."><button class="btn tiny">جستجو</button></form>
        </section>
        <?php if(Tenant::can('notes.create')): ?>
        <section class="note-editor card" data-note-editor hidden>
          <form method="post" class="autosave" data-form-key="note-new">
            <?=csrf_field()?><input type="hidden" name="action" value="v4_save_note"><input type="hidden" name="id" value="">
            <div class="note-editor-head"><input class="note-title-input" name="title" placeholder="عنوان نوت"><label class="check"><input type="checkbox" name="pinned" value="1"> سنجاق شود</label></div>
            <textarea name="body" class="note-body-input" placeholder="اینجا بنویسید..." required></textarea>
            <div class="note-editor-actions"><button class="btn primary">ذخیره نوت</button><button type="button" class="btn" data-cancel-note>بستن</button><button type="button" class="btn ai-note-btn" disabled title="بعداً به AI Agent متصل می‌شود">✦ تحلیل با AI <small>به‌زودی</small></button></div>
          </form>
        </section>
        <?php endif; ?>
        <section class="notes-grid">
        <?php foreach($rows as $r): ?>
          <article class="note-card <?=$r['pinned']?'pinned':''?>" data-note-id="<?=(int)$r['id']?>">
            <div class="note-card-head"><h3><?=h($r['title']?:'بدون عنوان')?></h3><?php if($r['pinned']):?><span>📌</span><?php endif;?></div>
            <div class="note-body"><?=nl2br(h(mb_strimwidth($r['body'],0,900,'…','UTF-8')))?></div>
            <div class="note-meta"><?=h($r['user_name']??'')?> • <?=h($r['updated_at']??'')?></div>
            <div class="note-actions">
              <?php if(Tenant::can('notes.update')):?><button class="btn tiny" type="button" data-edit-note data-title="<?=h($r['title'])?>" data-body="<?=h($r['body'])?>" data-pinned="<?=$r['pinned']?'1':'0'?>">ویرایش</button><?php endif;?>
              <button class="btn tiny" type="button" data-attachments data-entity="notes" data-id="<?=(int)$r['id']?>">فایل‌ها</button>
              <button class="btn tiny ai-note-btn" disabled>✦ AI</button>
              <?php if(Tenant::can('notes.delete')):?><form method="post" class="inline-form" onsubmit="return confirm('این نوت حذف شود؟')"><?=csrf_field()?><input type="hidden" name="action" value="v4_delete_note"><input type="hidden" name="id" value="<?=(int)$r['id']?>"><button class="btn tiny danger">حذف</button></form><?php endif;?>
            </div>
          </article>
        <?php endforeach; ?>
        <?php if(!$rows):?><div class="card calendar-empty">هنوز نوتی ثبت نشده است.</div><?php endif;?>
        </section>
        <?php render_footer();
    }

    public static function renderLibrary(): void
    {
        Tenant::requirePermission('files.view');self::ensureSchema();$qv=trim((string)($_GET['q']??''));$files=FileLibrary::list($qv,300);
        render_header('لایبرری فایل‌ها','مرکز فایل‌های محیط کاری؛ فایل را یک‌بار آپلود کنید و در بخش‌های مختلف اتچ کنید.');
        ?>
        <section class="library-toolbar card">
          <?php if(Tenant::can('files.upload')):?><form class="library-upload" action="v4_files.php" method="post" enctype="multipart/form-data"><?=csrf_field()?><input type="hidden" name="action" value="upload_redirect"><input type="file" name="file" required><button class="btn primary">آپلود فایل</button></form><?php endif;?>
          <form class="library-search" method="get"><input type="hidden" name="page" value="library"><input name="q" value="<?=h($qv)?>" placeholder="نام فایل، پوشه، توضیح..."><button class="btn tiny">جستجو</button></form>
        </section>
        <section class="library-grid">
          <?php foreach($files as $f):?>
          <article class="file-card" data-file-id="<?=(int)$f['id']?>">
            <div class="file-icon"><?=h(strtoupper($f['extension']?:'FILE'))?></div>
            <div class="file-info"><h3 title="<?=h($f['original_name'])?>"><?=h($f['original_name'])?></h3><p><?=h(self::bytes((int)$f['size_bytes']))?> • <?=h($f['mime_type'])?></p><small><?=(int)$f['attachments_count']?> اتچ • <?=h($f['created_at'])?></small></div>
            <div class="file-actions"><a class="btn tiny" href="file_download.php?id=<?=(int)$f['id']?>">دانلود</a><?php if(Tenant::can('files.delete')):?><button class="btn tiny danger" type="button" data-delete-library-file>حذف</button><?php endif;?></div>
          </article>
          <?php endforeach;?>
          <?php if(!$files):?><div class="card calendar-empty">فایلی در لایبرری نیست.</div><?php endif;?>
        </section>
        <?php render_footer();
    }

    public static function renderAccess(): void
    {
        Tenant::requirePermission('members.view');self::ensureSchema();$wid=Tenant::id();
        $st=pdo()->prepare("SELECT wm.*,u.name,u.email,u.status user_status,wr.name role_name,wr.role_key FROM workspace_members wm JOIN users u ON u.id=wm.user_id LEFT JOIN workspace_roles wr ON wr.id=wm.role_id WHERE wm.workspace_id=? ORDER BY wm.id");$st->execute([$wid]);$members=$st->fetchAll();
        $st=pdo()->prepare("SELECT * FROM workspace_roles WHERE workspace_id=? ORDER BY is_system DESC,id");$st->execute([$wid]);$roles=$st->fetchAll();
        $perms=pdo()->query("SELECT * FROM workspace_permissions ORDER BY group_key,sort_order")->fetchAll();
        $rolePerm=[];$rp=pdo()->prepare("SELECT p.permission_key FROM workspace_role_permissions rp JOIN workspace_permissions p ON p.id=rp.permission_id WHERE rp.role_id=?");
        foreach($roles as $r){$rp->execute([$r['id']]);$rolePerm[(int)$r['id']]=array_column($rp->fetchAll(),'permission_key');}
        render_header('کاربران و دسترسی‌ها','مدیریت اعضای محیط کاری، Role و Permission؛ هر Workspace داده و دسترسی مستقل دارد.');
        ?>
        <section class="access-summary">
          <div class="metric card"><strong><?=count($members)?></strong><span>کاربر این محیط</span></div>
          <div class="metric card"><strong><?=count($roles)?></strong><span>نقش دسترسی</span></div>
          <div class="metric card"><strong><?=h(Tenant::current()['plan_key']??'')?></strong><span>پلن Workspace</span></div>
          <div class="metric card"><strong><?=h(Tenant::current()['subscription_status']??'')?></strong><span>وضعیت اشتراک</span></div>
          <div class="metric card"><strong><?=h(Tenant::membership()['role_name']??'—')?></strong><span>نقش فعلی شما</span></div>
        </section>
        <?php if(Tenant::can('api.manage')):?><section class="card"><div class="section-title"><div><h2>API محیط کاری</h2><div class="muted">Tokenهای مستقل همین Workspace برای Agent، Hermes و سرویس‌های بیرونی.</div></div><div class="row-actions"><a class="btn tiny primary" href="api_tokens.php">مدیریت Token</a><a class="btn tiny" href="API_V2_SAAS_FA.md" target="_blank">راهنمای API</a><a class="btn tiny" href="openapi-v2.json" target="_blank">OpenAPI</a></div></div></section><?php endif;?>
        <?php if(Tenant::can('members.manage')):?>
        <section class="card"><details><summary>افزودن کاربر به این محیط کاری</summary><form method="post" class="grid-form compact"><?=csrf_field()?><input type="hidden" name="action" value="v4_add_member">
          <label>نام<input name="name" required></label><label>ایمیل<input type="email" name="email" required></label><label>رمز اولیه<input name="password" type="password" minlength="8" placeholder="برای کاربر جدید"></label>
          <label>نقش<select name="role_id"><?php foreach($roles as $r):?><option value="<?=$r['id']?>"><?=h($r['name'])?></option><?php endforeach;?></select></label><button class="btn primary">افزودن/دعوت کاربر</button>
        </form></details></section>
        <?php endif;?>
        <section class="card table-card"><div class="section-title"><h2>اعضای محیط کاری</h2></div><div class="table-wrap"><table><thead><tr><th>نام</th><th>ایمیل</th><th>نقش</th><th>وضعیت</th><th>عضویت</th><th>عملیات</th></tr></thead><tbody>
        <?php foreach($members as $m):?><tr><td><?=h($m['name'])?></td><td dir="ltr"><?=h($m['email'])?></td><td><?=h($m['role_name'])?></td><td><?=h($m['status'])?></td><td><?=h($m['joined_at'])?></td><td>
          <?php if(Tenant::can('members.manage')):?><form method="post" class="row-actions"><?=csrf_field()?><input type="hidden" name="action" value="v4_update_member"><input type="hidden" name="member_id" value="<?=$m['id']?>">
            <select name="role_id"><?php foreach($roles as $r):?><option value="<?=$r['id']?>" <?=$r['id']==$m['role_id']?'selected':''?>><?=h($r['name'])?></option><?php endforeach;?></select><button class="btn tiny">ذخیره</button></form><form method="post" class="inline-form" onsubmit="return confirm('عضویت این کاربر از محیط کاری حذف شود؟')"><?=csrf_field()?><input type="hidden" name="action" value="v4_remove_member"><input type="hidden" name="member_id" value="<?=$m['id']?>"><button class="btn tiny danger">حذف عضویت</button></form><?php endif;?>
        </td></tr><?php endforeach;?>
        </tbody></table></div></section>
        <?php if(Tenant::can('members.manage')):?>
        <section class="card"><div class="section-title"><div><h2>Role و Permission</h2><div class="muted">دسترسی هر نقش را دقیقاً در سطح عملیات تعیین کنید.</div></div></div>
        <details class="role-create"><summary>ساخت نقش سفارشی</summary><form method="post" class="grid-form"><?=csrf_field()?><input type="hidden" name="action" value="v4_save_role"><label>نام نقش<input name="name" required placeholder="مثلاً حسابدار ارشد"></label><label>کلید نقش<input name="role_key" required placeholder="senior_accountant"></label><button class="btn primary">ساخت نقش</button></form></details>
        <div class="roles-grid role-editor-grid"><?php foreach($roles as $role):?><article class="role-card"><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="v4_save_role"><input type="hidden" name="role_id" value="<?=$role['id']?>"><div class="role-card-head"><h3><?=h($role['name'])?> <?=$role['is_system']?'<small>سیستمی</small>':''?></h3><button class="btn tiny primary">ذخیره دسترسی‌ها</button></div><div class="permission-matrix"><?php $lastGroup=''; foreach($perms as $perm): if($lastGroup!==$perm['group_key']){$lastGroup=$perm['group_key'];echo '<div class="permission-group-title">'.h($lastGroup).'</div>';} ?><label class="permission-check"><input type="checkbox" name="permissions[]" value="<?=h($perm['permission_key'])?>" <?=in_array($perm['permission_key'],$rolePerm[$role['id']]??[],true)?'checked':''?>> <span><?=h($perm['title'])?></span></label><?php endforeach;?></div></form></article><?php endforeach;?></div></section>
        <?php endif;?>
        <?php if(Tenant::isPlatformAdmin()):?>
        <section class="card platform-admin-callout"><div class="section-title"><div><h2>مدیریت کل SaaS</h2><div class="muted">ساخت مشتری، Workspace، مالک محیط و مدیریت اشتراک از پنل مدیر کل انجام می‌شود.</div></div><a class="btn primary tiny" href="index.php?page=platform">باز کردن پنل SaaS</a></div></section>
        <?php endif;?>
        <section class="card"><h2>لاگ این محیط کاری</h2><?php if(Tenant::can('audit.view')){ $st=pdo()->prepare("SELECT a.*,u.name user_name FROM audit_logs a LEFT JOIN users u ON u.id=a.user_id WHERE a.workspace_id=? ORDER BY a.id DESC LIMIT 100");$st->execute([$wid]);$logs=$st->fetchAll();?><div class="table-wrap"><table><thead><tr><th>زمان</th><th>کاربر</th><th>عملیات</th><th>بخش</th><th>رکورد</th><th>توضیح</th><th>IP</th></tr></thead><tbody><?php foreach($logs as $l):?><tr><td><?=h($l['created_at'])?></td><td><?=h($l['user_name'])?></td><td><?=h($l['action'])?></td><td><?=h($l['entity_key'])?></td><td><?=h($l['record_id'])?></td><td><?=h($l['summary'])?></td><td dir="ltr"><?=h($l['ip'])?></td></tr><?php endforeach;?></tbody></table></div><?php }else echo '<p class="muted">مجوز مشاهده لاگ را ندارید.</p>';?></section>
        <?php render_footer();
    }

    public static function renderPlatform(): void
    {
        if(!Tenant::isPlatformAdmin()){http_response_code(403);throw new RuntimeException('این صفحه فقط برای مدیر کل پلتفرم است.');}
        self::ensureSchema();$rows=Tenant::allWorkspaces();
        $totalUsers=(int)pdo()->query("SELECT COUNT(*) FROM users WHERE status='active'")->fetchColumn();
        $active=0;$trial=0;foreach($rows as $w){if($w['status']==='active')$active++;if($w['subscription_status']==='trial')$trial++;}
        render_header('مدیریت SaaS','پنل مدیر کل پلتفرم؛ ساخت مشتری و Workspace، مدیریت ادمین محیط، پلن و وضعیت اشتراک.');
        ?>
        <section class="platform-metrics">
          <div class="metric card"><strong><?=count($rows)?></strong><span>کل Workspaceها</span></div>
          <div class="metric card"><strong><?=$active?></strong><span>Workspace فعال</span></div>
          <div class="metric card"><strong><?=$trial?></strong><span>Trial</span></div>
          <div class="metric card"><strong><?=$totalUsers?></strong><span>کل کاربران پلتفرم</span></div>
        </section>

        <section class="card platform-create-card">
          <details open><summary>ساخت مشتری / Workspace جدید</summary>
          <form method="post" class="grid-form compact platform-create-form">
            <?=csrf_field()?><input type="hidden" name="action" value="v4_platform_create_workspace">
            <label>نام محیط کاری<input name="workspace_name" required placeholder="مثلاً حسابداری شرکت آلفا"></label>
            <label>نام ادمین Workspace<input name="owner_name" required></label>
            <label>ایمیل ادمین<input type="email" name="owner_email" required></label>
            <label>رمز اولیه<input type="password" name="owner_password" minlength="8" placeholder="برای کاربر جدید الزامی"></label>
            <label>پلن<select name="plan_key"><option value="starter">Starter</option><option value="pro">Pro</option><option value="business">Business</option><option value="enterprise">Enterprise</option></select></label>
            <label>وضعیت اشتراک<select name="subscription_status"><option value="trial">Trial</option><option value="active">Active</option></select></label>
            <label>مدت Trial (روز)<input type="number" name="trial_days" value="14" min="0" max="3650"></label>
            <button class="btn primary">ساخت Workspace و ادمین</button>
          </form></details>
          <p class="muted">ادمین ساخته‌شده «Platform Admin» نیست؛ فقط Owner همان Workspace است و می‌تواند کاربران و Roleهای همان محیط را مدیریت کند.</p>
        </section>

        <section class="card table-card platform-workspaces">
          <div class="section-title"><h2>Workspaceها و اشتراک‌ها</h2><span class="muted">ورود مدیریتی شما به هر Workspace در Audit همان محیط ثبت می‌شود.</span></div>
          <div class="table-wrap"><table><thead><tr><th>ID</th><th>Workspace</th><th>ادمین/مالک</th><th>کاربران</th><th>پلن</th><th>اشتراک</th><th>وضعیت محیط</th><th>Trial تا</th><th>عملیات</th></tr></thead><tbody>
          <?php foreach($rows as $w):?>
            <tr>
              <td><?=(int)$w['id']?></td>
              <td><b><?=h($w['name'])?></b><small class="platform-slug"><?=h($w['slug'])?></small></td>
              <td>
                <?=h($w['owner_name']??'—')?><small><?=h($w['owner_email']??'')?></small>
                <small>Role: <?=h($w['owner_role_name']??'مالک محیط')?></small>
                <small>Platform Admin: <?=((int)($w['owner_is_platform_admin']??0)===1)?'بله':'خیر'?></small>
              </td>
              <td><?=(int)$w['member_count']?></td>
              <td colspan="4">
                <form method="post" class="platform-row-form"><?=csrf_field()?><input type="hidden" name="action" value="v4_platform_update_workspace"><input type="hidden" name="workspace_id" value="<?=(int)$w['id']?>">
                  <select name="plan_key"><option <?=$w['plan_key']==='starter'?'selected':''?> value="starter">Starter</option><option <?=$w['plan_key']==='pro'?'selected':''?> value="pro">Pro</option><option <?=$w['plan_key']==='business'?'selected':''?> value="business">Business</option><option <?=$w['plan_key']==='enterprise'?'selected':''?> value="enterprise">Enterprise</option></select>
                  <select name="subscription_status"><option <?=$w['subscription_status']==='trial'?'selected':''?> value="trial">Trial</option><option <?=$w['subscription_status']==='active'?'selected':''?> value="active">Active</option><option <?=$w['subscription_status']==='past_due'?'selected':''?> value="past_due">Past Due</option><option <?=$w['subscription_status']==='canceled'?'selected':''?> value="canceled">Canceled</option></select>
                  <select name="workspace_status"><option <?=$w['status']==='active'?'selected':''?> value="active">Active</option><option <?=$w['status']==='suspended'?'selected':''?> value="suspended">Suspended</option></select>
                  <input name="trial_ends_at" value="<?=h($w['trial_ends_at']??'')?>" placeholder="YYYY-MM-DD HH:MM:SS">
                  <button class="btn tiny">ذخیره</button>
                </form>
              </td>
              <td>
                <form method="post" class="inline-form"><?=csrf_field()?><input type="hidden" name="action" value="v4_platform_enter_workspace"><input type="hidden" name="workspace_id" value="<?=(int)$w['id']?>"><button class="btn tiny primary">ورود مدیریتی</button></form>
              </td>
            </tr>
          <?php endforeach;?>
          </tbody></table></div>
        </section>
        <?php render_footer();
    }

    private static function saveNote(): void
    {
        $id=(int)($_POST['id']??0);$body=trim((string)($_POST['body']??''));if($body==='')throw new RuntimeException('متن نوت خالی است.');
        $data=[trim((string)($_POST['title']??'')),$body,isset($_POST['pinned'])?1:0];
        if($id){Tenant::requirePermission('notes.update');$old=self::note($id);pdo()->prepare("UPDATE notes SET title=?,body=?,pinned=?,updated_at=NOW() WHERE id=? AND workspace_id=?")->execute([...$data,$id,Tenant::id()]);Audit::log('note.update','notes',$id,'ویرایش نوت',$old,['title'=>$data[0]]);}
        else{Tenant::requirePermission('notes.create');pdo()->prepare("INSERT INTO notes (workspace_id,user_id,title,body,pinned,created_at,updated_at) VALUES (?,?,?,?,?,NOW(),NOW())")->execute([Tenant::id(),(int)Auth::user()['id'],...$data]);$id=(int)pdo()->lastInsertId();Audit::log('note.create','notes',$id,'ایجاد نوت');}
        FileLibrary::syncFromPost('notes',$id);redirect('index.php?page=notes');
    }

    private static function deleteNote(): void
    {
        Tenant::requirePermission('notes.delete');$id=(int)($_POST['id']??0);$old=self::note($id);pdo()->prepare("DELETE FROM notes WHERE id=? AND workspace_id=?")->execute([$id,Tenant::id()]);Audit::log('note.delete','notes',$id,'حذف نوت',$old,null);redirect('index.php?page=notes');
    }

    private static function note(int $id): ?array {$st=pdo()->prepare("SELECT * FROM notes WHERE id=? AND workspace_id=?");$st->execute([$id,Tenant::id()]);$r=$st->fetch();return$r?:null;}

    private static function createWorkspace(): void
    {
        if(!Tenant::isPlatformAdmin())throw new RuntimeException('فقط مدیر کل پلتفرم می‌تواند Workspace جدید بسازد.');
        $email=mb_strtolower(trim((string)($_POST['owner_email']??'')));$name=trim((string)($_POST['name']??''));
        if(!$name||!$email)throw new RuntimeException('نام و ایمیل مالک الزامی است.');
        $st=pdo()->prepare("SELECT id FROM users WHERE email=? LIMIT 1");$st->execute([$email]);$uid=(int)$st->fetchColumn();
        if(!$uid)throw new RuntimeException('کاربر مالک وجود ندارد؛ از پنل مدیریت SaaS مشتری و Workspace را همزمان بسازید.');
        $wid=Tenant::addWorkspace($name,$uid);Audit::logForWorkspace($wid,'workspace.create','workspaces',$wid,'ساخت Workspace توسط مدیر کل');
        flash('محیط کاری جدید ساخته شد.');redirect('index.php?page=platform');
    }

    private static function platformCreateWorkspace(): void
    {
        if(!Tenant::isPlatformAdmin())throw new RuntimeException('دسترسی مدیر کل لازم است.');
        $workspace=trim((string)($_POST['workspace_name']??''));$ownerName=trim((string)($_POST['owner_name']??''));
        $email=mb_strtolower(trim((string)($_POST['owner_email']??'')));$password=(string)($_POST['owner_password']??'');
        if($workspace===''||$ownerName===''||$email==='')throw new RuntimeException('نام Workspace، نام ادمین و ایمیل الزامی است.');

        $st=pdo()->prepare("SELECT id FROM users WHERE email=? LIMIT 1");$st->execute([$email]);$uid=(int)$st->fetchColumn();
        if(!$uid){
            if(strlen($password)<8)throw new RuntimeException('برای ادمین جدید رمز حداقل ۸ کاراکتر وارد کنید.');
            pdo()->prepare("INSERT INTO users (name,email,password_hash,role,status,is_platform_admin,created_at,updated_at) VALUES (?,?,?,'accountant','active',0,NOW(),NOW())")
                ->execute([$ownerName,$email,password_hash($password,PASSWORD_DEFAULT)]);
            $uid=(int)pdo()->lastInsertId();
        }else{
            pdo()->prepare("UPDATE users SET name=?,status='active',updated_at=NOW() WHERE id=?")->execute([$ownerName,$uid]);
            if($password!==''){
                if(strlen($password)<8)throw new RuntimeException('رمز باید حداقل ۸ کاراکتر باشد.');
                pdo()->prepare("UPDATE users SET password_hash=?,updated_at=NOW() WHERE id=?")->execute([password_hash($password,PASSWORD_DEFAULT),$uid]);
            }
        }

        $wid=Tenant::addWorkspace($workspace,$uid);
        $plan=in_array($_POST['plan_key']??'starter',['starter','pro','business','enterprise'],true)?$_POST['plan_key']:'starter';
        $sub=in_array($_POST['subscription_status']??'trial',['trial','active'],true)?$_POST['subscription_status']:'trial';
        $days=max(0,min(3650,(int)($_POST['trial_days']??14)));
        $trial=$days?date('Y-m-d H:i:s',time()+$days*86400):null;
        pdo()->prepare("UPDATE workspaces SET plan_key=?,subscription_status=?,trial_ends_at=?,updated_at=NOW() WHERE id=?")->execute([$plan,$sub,$trial,$wid]);
        pdo()->prepare("INSERT INTO workspace_subscriptions (workspace_id,plan_key,status,current_period_start,current_period_end,updated_at) VALUES (?,?,?,NOW(),?,NOW()) ON DUPLICATE KEY UPDATE plan_key=VALUES(plan_key),status=VALUES(status),current_period_end=VALUES(current_period_end),updated_at=NOW()")
            ->execute([$wid,$plan,$sub,$trial]);
        Audit::logForWorkspace($wid,'platform.workspace.create','workspaces',$wid,'ساخت مشتری و ادمین Workspace',null,['owner_user_id'=>$uid,'owner_email'=>$email,'plan'=>$plan,'subscription_status'=>$sub]);
        flash('Workspace و ادمین آن ساخته شد.');redirect('index.php?page=platform');
    }

    private static function platformUpdateWorkspace(): void
    {
        if(!Tenant::isPlatformAdmin())throw new RuntimeException('دسترسی مدیر کل لازم است.');
        $wid=(int)($_POST['workspace_id']??0);if(!$wid)throw new RuntimeException('Workspace نامعتبر است.');
        $old=self::dbOne("SELECT * FROM workspaces WHERE id=?",[$wid]);if(!$old)throw new RuntimeException('Workspace پیدا نشد.');
        $plan=in_array($_POST['plan_key']??'', ['starter','pro','business','enterprise'],true)?$_POST['plan_key']:$old['plan_key'];
        $sub=in_array($_POST['subscription_status']??'', ['trial','active','past_due','canceled'],true)?$_POST['subscription_status']:$old['subscription_status'];
        $status=in_array($_POST['workspace_status']??'', ['active','suspended'],true)?$_POST['workspace_status']:$old['status'];
        $trial=trim((string)($_POST['trial_ends_at']??''));$trial=$trial!==''?$trial:null;
        pdo()->prepare("UPDATE workspaces SET plan_key=?,subscription_status=?,status=?,trial_ends_at=?,updated_at=NOW() WHERE id=?")->execute([$plan,$sub,$status,$trial,$wid]);
        pdo()->prepare("INSERT INTO workspace_subscriptions (workspace_id,plan_key,status,current_period_start,current_period_end,updated_at) VALUES (?,?,?,NOW(),?,NOW()) ON DUPLICATE KEY UPDATE plan_key=VALUES(plan_key),status=VALUES(status),current_period_end=VALUES(current_period_end),updated_at=NOW()")
            ->execute([$wid,$plan,$sub,$trial]);
        Audit::logForWorkspace($wid,'platform.workspace.update','workspaces',$wid,'ویرایش پلن/اشتراک Workspace',$old,['plan_key'=>$plan,'subscription_status'=>$sub,'status'=>$status,'trial_ends_at'=>$trial]);
        flash('Workspace به‌روزرسانی شد.');redirect('index.php?page=platform');
    }

    private static function platformEnterWorkspace(): void
    {
        if(!Tenant::isPlatformAdmin())throw new RuntimeException('دسترسی مدیر کل لازم است.');
        $wid=(int)($_POST['workspace_id']??0);Tenant::switch($wid);
        Audit::logForWorkspace($wid,'platform.workspace.enter','workspaces',$wid,'ورود مدیریتی مدیر کل به Workspace');
        redirect('index.php?page=dashboard');
    }

    private static function addMember(): void
    {
        Tenant::requirePermission('members.manage');
        $wid=Tenant::id();

        if(Tenant::isMainWorkspace($wid)){
            throw new RuntimeException('محیط کاری اصلی اختصاصی مالک پلتفرم است و کاربر دیگری نمی‌تواند به آن اضافه شود.');
        }

        $email=mb_strtolower(trim((string)($_POST['email']??'')));
        $name=trim((string)($_POST['name']??''));
        $role=(int)($_POST['role_id']??0);
        if(!$email||!$name||!$role)throw new RuntimeException('اطلاعات کاربر کامل نیست.');

        $rst=pdo()->prepare("SELECT id,role_key,name FROM workspace_roles WHERE id=? AND workspace_id=? LIMIT 1");
        $rst->execute([$role,$wid]);$targetRole=$rst->fetch();
        if(!$targetRole)throw new RuntimeException('نقش انتخاب‌شده متعلق به این محیط کاری نیست.');
        if($targetRole['role_key']==='owner' && !Tenant::isWorkspaceOwner()){
            throw new RuntimeException('فقط مالک Workspace می‌تواند کاربر دیگری را Owner کند.');
        }

        $st=pdo()->prepare("SELECT id FROM users WHERE email=? LIMIT 1");
        $st->execute([$email]);$uid=(int)$st->fetchColumn();
        if(!$uid){
            $pw=(string)($_POST['password']??'');
            if(strlen($pw)<8)throw new RuntimeException('برای کاربر جدید رمز حداقل ۸ کاراکتر وارد کنید.');
            pdo()->prepare("INSERT INTO users (name,email,password_hash,role,status,is_platform_admin,created_at,updated_at)
                VALUES (?,?,?,'accountant','active',0,NOW(),NOW())")
                ->execute([$name,$email,password_hash($pw,PASSWORD_DEFAULT)]);
            $uid=(int)pdo()->lastInsertId();
        }

        pdo()->prepare("INSERT INTO workspace_members (workspace_id,user_id,role_id,status,joined_at,created_at,updated_at)
            VALUES (?,?,?,'active',NOW(),NOW(),NOW())
            ON DUPLICATE KEY UPDATE role_id=VALUES(role_id),status='active',updated_at=NOW()")
            ->execute([$wid,$uid,$role]);

        Audit::log('member.add','workspace_members',$uid,'افزودن کاربر',null,['email'=>$email,'role_key'=>$targetRole['role_key']]);
        flash('کاربر فقط به همین محیط کاری اضافه شد.');
        redirect('index.php?page=access');
    }

    private static function updateMember(): void
    {
        Tenant::requirePermission('members.manage');
        $wid=Tenant::id();
        $id=(int)($_POST['member_id']??0);
        $role=(int)($_POST['role_id']??0);
        if(!$id||!$role)throw new RuntimeException('عضویت یا نقش نامعتبر است.');

        $mst=pdo()->prepare("SELECT wm.*,wr.role_key current_role_key,u.email
            FROM workspace_members wm
            LEFT JOIN workspace_roles wr ON wr.id=wm.role_id
            LEFT JOIN users u ON u.id=wm.user_id
            WHERE wm.id=? AND wm.workspace_id=? LIMIT 1");
        $mst->execute([$id,$wid]);$member=$mst->fetch();
        if(!$member)throw new RuntimeException('عضویت در این Workspace پیدا نشد.');

        $rst=pdo()->prepare("SELECT id,role_key,name FROM workspace_roles WHERE id=? AND workspace_id=? LIMIT 1");
        $rst->execute([$role,$wid]);$targetRole=$rst->fetch();
        if(!$targetRole)throw new RuntimeException('نقش انتخاب‌شده متعلق به این Workspace نیست.');

        if(($member['current_role_key']==='owner' || $targetRole['role_key']==='owner') && !Tenant::isWorkspaceOwner()){
            throw new RuntimeException('فقط مالک Workspace می‌تواند نقش Owner را واگذار یا تغییر دهد.');
        }

        if(Tenant::isMainWorkspace($wid) && (int)$member['user_id']!==(int)(Auth::user()['id']??0)){
            throw new RuntimeException('عضویت محیط کاری اصلی قابل واگذاری به کاربران دیگر نیست.');
        }

        pdo()->prepare("UPDATE workspace_members SET role_id=?,updated_at=NOW() WHERE id=? AND workspace_id=?")
            ->execute([$role,$id,$wid]);

        Audit::log('member.role','workspace_members',$id,'تغییر نقش کاربر',null,[
            'email'=>$member['email'],
            'from'=>$member['current_role_key'],
            'to'=>$targetRole['role_key']
        ]);
        flash('نقش کاربر به «'.$targetRole['name'].'» تغییر کرد.');
        redirect('index.php?page=access');
    }

    private static function removeMember(): void
    {
        Tenant::requirePermission('members.manage');
        $wid=Tenant::id();
        $id=(int)($_POST['member_id']??0);
        if(!$id)throw new RuntimeException('عضویت نامعتبر است.');

        if(Tenant::isMainWorkspace($wid)){
            throw new RuntimeException('عضویت محیط کاری اصلی از این صفحه قابل تغییر نیست.');
        }

        $st=pdo()->prepare("SELECT wm.*,wr.role_key,u.email
            FROM workspace_members wm
            LEFT JOIN workspace_roles wr ON wr.id=wm.role_id
            LEFT JOIN users u ON u.id=wm.user_id
            WHERE wm.id=? AND wm.workspace_id=?");
        $st->execute([$id,$wid]);$m=$st->fetch();
        if(!$m)throw new RuntimeException('عضویت پیدا نشد.');

        if((int)$m['user_id']===(int)Auth::user()['id']){
            throw new RuntimeException('برای جلوگیری از قفل شدن حساب، عضویت خودتان را حذف نکنید.');
        }
        if($m['role_key']==='owner' && !Tenant::isWorkspaceOwner()){
            throw new RuntimeException('فقط مالک Workspace می‌تواند عضویت Owner دیگری را مدیریت کند.');
        }

        pdo()->prepare("UPDATE workspace_members SET status='removed',updated_at=NOW() WHERE id=? AND workspace_id=?")
            ->execute([$id,$wid]);
        Audit::log('member.remove','workspace_members',$id,'حذف عضویت',['email'=>$m['email'],'role_key'=>$m['role_key']],null);
        flash('عضویت کاربر از همین Workspace حذف شد.');
        redirect('index.php?page=access');
    }

    private static function saveRole(): void
    {
        Tenant::requirePermission('members.manage');$wid=Tenant::id();$roleId=(int)($_POST['role_id']??0);
        if(!$roleId){
            $name=trim((string)($_POST['name']??''));$key=trim((string)($_POST['role_key']??''));$key=preg_replace('/[^a-zA-Z0-9_]+/','_',strtolower($key));if(!$name||!$key)throw new RuntimeException('نام و کلید نقش الزامی است.');
            pdo()->prepare("INSERT INTO workspace_roles (workspace_id,name,role_key,is_system,created_at,updated_at) VALUES (?,?,?,0,NOW(),NOW())")->execute([$wid,$name,$key]);$roleId=(int)pdo()->lastInsertId();Audit::log('role.create','workspace_roles',$roleId,'ساخت نقش سفارشی');flash('نقش ساخته شد؛ حالا دسترسی‌های آن را انتخاب کنید.');redirect('index.php?page=access');
        }
        $st=pdo()->prepare("SELECT * FROM workspace_roles WHERE id=? AND workspace_id=?");$st->execute([$roleId,$wid]);$role=$st->fetch();if(!$role)throw new RuntimeException('نقش معتبر نیست.');
        $keys=$_POST['permissions']??[];if(!is_array($keys))$keys=[];
        if($role['role_key']==='owner'){
            $keys=array_column(pdo()->query("SELECT permission_key FROM workspace_permissions")->fetchAll(),'permission_key');
        }
        $pdo=pdo();$pdo->beginTransaction();try{$pdo->prepare("DELETE FROM workspace_role_permissions WHERE role_id=?")->execute([$roleId]);$ins=$pdo->prepare("INSERT IGNORE INTO workspace_role_permissions (role_id,permission_id) SELECT ?,id FROM workspace_permissions WHERE permission_key=?");foreach(array_unique($keys) as $k)$ins->execute([$roleId,$k]);$pdo->commit();}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
        Audit::log('role.permissions','workspace_roles',$roleId,'تغییر دسترسی نقش',null,['permissions'=>$keys]);flash('دسترسی‌های نقش ذخیره شد.');redirect('index.php?page=access');
    }
    private static function dbOne(string $sql,array $params=[]): ?array
    {
        $st=pdo()->prepare($sql);$st->execute($params);$r=$st->fetch();return $r?:null;
    }

    private static function bytes(int $b): string { if($b<1024)return$b.' B';if($b<1048576)return round($b/1024,1).' KB';if($b<1073741824)return round($b/1048576,1).' MB';return round($b/1073741824,1).' GB';}
}
