<?php
final class V5Module
{
    private static function escJsonAttr(array $data): string
    {
        return h(json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
    }

    private static function faDate(?string $date): string
    {
        return $date?Jalali::fromGregorian($date):'';
    }

    private static function companyRows(): array
    {
        $wid=Tenant::id();$ttl=max(10,min(3600,(int)setting('cache_ttl_seconds','60')));
        return RuntimeCache::remember('companies:active:minimal',$ttl,function()use($wid){
            $st=pdo()->prepare("SELECT id,name FROM companies WHERE workspace_id=? AND active=1 ORDER BY name");
            $st->execute([$wid]);return$st->fetchAll();
        },$wid);
    }

    private static function companyOptions($selected=null,bool $blank=false): string
    {
        $html=$blank?'<option value="">— انتخاب شرکت —</option>':'';
        foreach(self::companyRows() as $r){
            $html.='<option value="'.(int)$r['id'].'" '.((string)$selected===(string)$r['id']?'selected':'').'>'.h($r['name']).'</option>';
        }
        return$html;
    }

    public static function renderNotes(): void
    {
        Tenant::requirePermission('notes.view');
        $status=$_GET['task_status']??'open';
        if(!in_array($status,['open','done','all','pinned'],true))$status='open';
        $q=trim((string)($_GET['q']??''));

        $where=['n.workspace_id=?'];$params=[Tenant::id()];
        if($status==='open')$where[]='n.is_completed=0';
        elseif($status==='done')$where[]='n.is_completed=1';
        elseif($status==='pinned')$where[]='n.pinned=1';
        if($q!==''){
            $where[]='(n.title LIKE ? OR n.body LIKE ?)';
            $l='%'.$q.'%';array_push($params,$l,$l);
        }

        $st=pdo()->prepare("SELECT n.*,u.name user_name FROM notes n LEFT JOIN users u ON u.id=n.user_id
            WHERE ".implode(' AND ',$where)." ORDER BY n.is_completed ASC,n.pinned DESC,n.due_date IS NULL,n.due_date,n.updated_at DESC LIMIT 400");
        $st->execute($params);$rows=$st->fetchAll();

        $statsSt=pdo()->prepare("SELECT COUNT(*) total,SUM(is_completed=0) open_count,SUM(is_completed=1) done_count,SUM(pinned=1) pinned_count
            FROM notes WHERE workspace_id=?");
        $statsSt->execute([Tenant::id()]);$stats=$statsSt->fetch()?:[];

        render_header('نوت‌ها و کارها','نوت، To‑Do، سررسید، اولویت، فایل پیوست و وضعیت انجام در یک فضای کاری.');
        ?>
        <section class="v5-note-hero card">
          <div class="v5-note-stats">
            <div><strong><?=(int)($stats['open_count']??0)?></strong><span>باز</span></div>
            <div><strong><?=(int)($stats['done_count']??0)?></strong><span>انجام‌شده</span></div>
            <div><strong><?=(int)($stats['pinned_count']??0)?></strong><span>سنجاق‌شده</span></div>
            <div><strong><?=(int)($stats['total']??0)?></strong><span>کل نوت‌ها</span></div>
          </div>
          <div class="v5-note-toolbar">
            <?php if(Tenant::can('notes.create')):?><button type="button" class="btn primary" data-v5-new-note>+ نوت / کار جدید</button><?php endif;?>
            <form method="get" class="v5-note-filter">
              <input type="hidden" name="page" value="notes">
              <select name="task_status">
                <option value="open" <?=$status==='open'?'selected':''?>>کارهای باز</option>
                <option value="done" <?=$status==='done'?'selected':''?>>انجام‌شده‌ها</option>
                <option value="pinned" <?=$status==='pinned'?'selected':''?>>سنجاق‌شده</option>
                <option value="all" <?=$status==='all'?'selected':''?>>همه</option>
              </select>
              <input name="q" value="<?=h($q)?>" placeholder="جستجو در نوت‌ها...">
              <button class="btn tiny">اعمال</button>
            </form>
          </div>
        </section>

        <?php if(Tenant::can('notes.create')||Tenant::can('notes.update')):?>
        <section class="v5-note-editor card" data-v5-note-editor hidden>
          <form method="post" data-v5-note-form class="autosave" data-form-key="v5-note">
            <?=csrf_field()?>
            <input type="hidden" name="action" value="v5_note_save">
            <input type="hidden" name="id" value="">
            <div class="v5-note-editor-grid">
              <label class="span2">عنوان<input name="title" placeholder="مثلاً پیگیری اظهارنامه شرکت..." maxlength="255"></label>
              <label>اولویت
                <select name="priority">
                  <option value="normal">عادی</option>
                  <option value="important">مهم</option>
                  <option value="urgent">فوری</option>
                </select>
              </label>
              <label>سررسید<input class="jalali-date" name="due_date" placeholder="1405/06/01"></label>
              <label class="check"><input type="checkbox" name="pinned" value="1"> سنجاق شود</label>
              <label class="span4">متن / شرح کار<textarea name="body" rows="7" placeholder="یادداشت، اقدام بعدی، نکته تماس یا هر چیزی که باید انجام شود..."></textarea></label>
            </div>
            <div class="v5-editor-actions">
              <button class="btn primary" type="submit">ذخیره</button>
              <button class="btn" type="button" data-v5-cancel-note>بستن</button>
              <button type="button" class="btn ai-note-btn" disabled>✦ تحلیل AI <small>رزرو شده</small></button>
            </div>
          </form>
        </section>
        <?php endif;?>

        <section class="v5-notes-grid" data-v5-notes-grid>
          <?php foreach($rows as $r) echo self::noteCardHtml($r); ?>
          <?php if(!$rows):?><div class="card calendar-empty" data-v5-notes-empty>نوتی با این فیلتر پیدا نشد.</div><?php endif;?>
        </section>
        <?php
        render_footer();
    }

    public static function noteCardHtml(array $r): string
    {
        $done=(int)($r['is_completed']??0)===1;
        $priority=$r['priority']??'normal';
        $priorityLabel=['normal'=>'عادی','important'=>'مهم','urgent'=>'فوری'][$priority]??'عادی';
        $data=[
            'id'=>(int)$r['id'],
            'title'=>(string)($r['title']??''),
            'body'=>(string)($r['body']??''),
            'pinned'=>(int)($r['pinned']??0),
            'priority'=>$priority,
            'due_date'=>self::faDate($r['due_date']??null),
        ];
        ob_start();?>
        <article class="v5-note-card <?=$done?'is-done':''?> <?=$r['pinned']?'is-pinned':''?> priority-<?=h($priority)?>"
                 data-note-id="<?=(int)$r['id']?>" data-note-json="<?=self::escJsonAttr($data)?>">
          <div class="v5-note-taskline">
            <?php if(Tenant::can('notes.update')):?>
            <label class="v5-task-check" title="<?=$done?'برگرداندن به باز':'علامت‌گذاری به عنوان انجام‌شده'?>">
              <input type="checkbox" data-v5-note-toggle <?=$done?'checked':''?>>
              <span></span>
            </label>
            <?php else:?><span class="v5-task-check readonly"><span></span></span><?php endif;?>
            <div class="v5-note-title-wrap">
              <h3><?=h($r['title']?:'بدون عنوان')?></h3>
              <div class="v5-note-badges">
                <span class="v5-priority-badge"><?=h($priorityLabel)?></span>
                <?php if($r['pinned']):?><span>📌 سنجاق</span><?php endif;?>
                <?php if(!empty($r['due_date'])):?><span>⏱ <?=h(self::faDate($r['due_date']))?></span><?php endif;?>
              </div>
            </div>
            <?php if($done):?><div class="v5-done-stamp">انجام شد ✓</div><?php endif;?>
          </div>
          <div class="v5-note-body"><?=nl2br(h(mb_strimwidth((string)$r['body'],0,1200,'…','UTF-8')))?></div>
          <div class="v5-note-meta">
            <span><?=h($r['user_name']??'')?></span>
            <span><?=h($r['updated_at']??'')?></span>
            <?php if($done&&!empty($r['completed_at'])):?><span>تکمیل: <?=h($r['completed_at'])?></span><?php endif;?>
          </div>
          <div class="v5-note-actions">
            <?php if(Tenant::can('notes.update')):?><button class="btn tiny" type="button" data-v5-edit-note>ویرایش</button><?php endif;?>
            <button class="btn tiny" type="button" data-attachments data-entity="notes" data-id="<?=(int)$r['id']?>">فایل‌ها</button>
            <button class="btn tiny ai-note-btn" type="button" disabled>✦ AI</button>
            <?php if(Tenant::can('notes.delete')):?><button class="btn tiny danger" type="button" data-v5-delete-note>حذف</button><?php endif;?>
          </div>
        </article>
        <?php return trim((string)ob_get_clean());
    }

    public static function renderPhonebook(): void
    {
        Tenant::requirePermission('phonebook.view');
        $q=trim((string)($_GET['q']??''));$company=(int)($_GET['company_id']??0);$follow=$_GET['followup']??'';
        $where=['p.workspace_id=?'];$params=[Tenant::id()];
        if($q!==''){
            $where[]='(p.person_name LIKE ? OR p.person_title LIKE ? OR p.organization_name LIKE ? OR p.phone_number LIKE ? OR p.notes LIKE ?)';
            $l='%'.$q.'%';array_push($params,$l,$l,$l,$l,$l);
        }
        if($company){$where[]='p.client_company_id=?';$params[]=$company;}
        if($follow==='open')$where[]='p.followup_done=0 AND p.followup_date IS NOT NULL';
        elseif($follow==='done')$where[]='p.followup_done=1';

        $st=pdo()->prepare("SELECT p.*,c.name client_company_name,u.name created_by_name
            FROM phonebook_entries p
            JOIN companies c ON c.id=p.client_company_id AND c.workspace_id=p.workspace_id
            LEFT JOIN users u ON u.id=p.created_by
            WHERE ".implode(' AND ',$where)." ORDER BY p.followup_done ASC,p.followup_date IS NULL,p.followup_date,p.contacted_date DESC,p.id DESC LIMIT 600");
        $st->execute($params);$rows=$st->fetchAll();

        render_header('دفترچه تلفن و پیگیری تماس','ثبت تماس برای هر شرکت حسابداری: شخص، سمت، مجموعه، شماره، داخلی، تاریخ تماس، پیگیری و فایل.');
        ?>
        <section class="card v5-phonebook-tools">
          <?php if(Tenant::can('phonebook.create')):?><button class="btn primary" type="button" data-v5-new-phone>+ ثبت تماس</button><?php endif;?>
          <form method="get" class="filters compact">
            <input type="hidden" name="page" value="phonebook">
            <label>شرکت<select name="company_id"><option value="">همه شرکت‌ها</option><?=self::companyOptions($company)?></select></label>
            <label>جستجو<input name="q" value="<?=h($q)?>" placeholder="شخص، مجموعه، شماره..."></label>
            <label>پیگیری<select name="followup"><option value="">همه</option><option value="open" <?=$follow==='open'?'selected':''?>>باز</option><option value="done" <?=$follow==='done'?'selected':''?>>انجام‌شده</option></select></label>
            <button class="btn tiny">فیلتر</button>
          </form>
        </section>

        <?php if(Tenant::can('phonebook.create')||Tenant::can('phonebook.update')):?>
        <section class="card v5-phonebook-editor" data-v5-phone-editor hidden>
          <form method="post" data-v5-phone-form class="grid-form compact autosave" data-form-key="v5-phonebook">
            <?=csrf_field()?><input type="hidden" name="action" value="v5_phonebook_save"><input type="hidden" name="id" value="">
            <label>شرکت مرتبط<select name="client_company_id" required><option value="">انتخاب کنید</option><?=self::companyOptions()?></select></label>
            <label>نام شخص<input name="person_name" placeholder="آقای / خانم ..."></label>
            <label>سمت<input name="person_title" placeholder="کارشناس، مدیر مالی..."></label>
            <label>نام مجموعه<input name="organization_name" placeholder="سازمان / اداره / شرکت مقصد"></label>
            <label>نوع تماس<select name="contact_type"><option value="mobile">موبایل</option><option value="phone">تلفن ثابت</option><option value="other">سایر</option></select></label>
            <label>شماره تماس<input name="phone_number" dir="ltr" required placeholder="0912... / 021..."></label>
            <label>داخلی<input name="extension_no" dir="ltr" placeholder="مثلاً 125"></label>
            <label>تاریخ تماس<input class="jalali-date" name="contacted_date" value="<?=h(Jalali::today())?>" placeholder="1405/05/24"></label>
            <label>تاریخ پیگیری<input class="jalali-date" name="followup_date" placeholder="1405/05/28"></label>
            <label class="check"><input type="checkbox" name="followup_done" value="1"> پیگیری انجام شده</label>
            <label class="span4">توضیحات<textarea name="notes" rows="4" placeholder="نتیجه تماس، اقدام بعدی، مدارک موردنیاز..."></textarea></label>
            <div class="span4 v5-editor-actions"><button class="btn primary">ذخیره تماس</button><button class="btn" type="button" data-v5-cancel-phone>بستن</button></div>
          </form>
        </section>
        <?php endif;?>

        <section class="card table-card">
          <div class="section-title"><h2>سوابق تماس و پیگیری</h2><span class="muted"><?=count($rows)?> رکورد</span></div>
          <div class="table-wrap">
            <table class="smart-table v5-phonebook-table"<?=function_exists('smart_table_attrs')?smart_table_attrs('phonebook_entries'):''?>>
              <thead><tr>
                <th data-col-key="actions">عملیات</th>
                <th data-col-key="client_company">شرکت حسابداری</th>
                <th data-col-key="person_name">شخص</th>
                <th data-col-key="person_title">سمت</th>
                <th data-col-key="organization_name">مجموعه مقصد</th>
                <th data-col-key="phone_number">شماره تماس</th>
                <th data-col-key="contacted_date">تاریخ تماس</th>
                <th data-col-key="followup_date">تاریخ پیگیری</th>
                <th data-col-key="notes">توضیحات</th>
              </tr></thead>
              <tbody data-v5-phonebook-rows><?php foreach($rows as $r)echo self::phonebookRowHtml($r);?></tbody>
            </table>
          </div>
        </section>
        <?php render_footer();
    }

    public static function phonebookRowHtml(array $r): string
    {
        $json=[
            'id'=>(int)$r['id'],
            'client_company_id'=>(int)$r['client_company_id'],
            'person_name'=>(string)($r['person_name']??''),
            'person_title'=>(string)($r['person_title']??''),
            'organization_name'=>(string)($r['organization_name']??''),
            'contact_type'=>(string)($r['contact_type']??'mobile'),
            'phone_number'=>(string)($r['phone_number']??''),
            'extension_no'=>(string)($r['extension_no']??''),
            'contacted_date'=>self::faDate($r['contacted_date']??null),
            'followup_date'=>self::faDate($r['followup_date']??null),
            'followup_done'=>(int)($r['followup_done']??0),
            'notes'=>(string)($r['notes']??''),
        ];
        $phone=trim((string)($r['phone_number']??''));
        $ext=trim((string)($r['extension_no']??''));
        ob_start();?>
        <tr data-id="<?=(int)$r['id']?>" data-phone-json="<?=self::escJsonAttr($json)?>" class="<?=(int)$r['followup_done']===1?'is-followup-done':''?>">
          <td data-col-key="actions"><div class="row-actions">
            <?php if(Tenant::can('phonebook.update')):?><button class="btn tiny" type="button" data-v5-phone-edit>ویرایش</button><?php endif;?>
            <button class="btn tiny" type="button" data-attachments data-entity="phonebook_entries" data-id="<?=(int)$r['id']?>">فایل‌ها</button>
            <?php if(Tenant::can('phonebook.delete')):?><button class="btn tiny danger" type="button" data-v5-phone-delete>حذف</button><?php endif;?>
          </div></td>
          <td data-col-key="client_company"><b><?=h($r['client_company_name']??'')?></b></td>
          <td data-col-key="person_name"><?=h($r['person_name']??'—')?></td>
          <td data-col-key="person_title"><?=h($r['person_title']??'—')?></td>
          <td data-col-key="organization_name"><?=h($r['organization_name']??'—')?></td>
          <td data-col-key="phone_number"><a class="v5-phone-link" dir="ltr" href="tel:<?=h(preg_replace('/[^\d+]/','',$phone))?>"><?=h($phone)?></a><?php if($ext!==''):?><small>داخلی <?=h($ext)?></small><?php endif;?></td>
          <td data-col-key="contacted_date"><?=h(self::faDate($r['contacted_date']??null))?></td>
          <td data-col-key="followup_date"><?php if(!empty($r['followup_date'])):?><span class="v5-followup-pill"><?=(int)$r['followup_done']===1?'✓ ':''?><?=h(self::faDate($r['followup_date']))?></span><?php if(Tenant::can('phonebook.update')):?><button class="v5-followup-toggle" type="button" data-v5-phone-toggle title="تغییر وضعیت پیگیری"><?=(int)$r['followup_done']===1?'↩ بازگشایی':'✓ انجام شد'?></button><?php endif;?><?php else:?>—<?php endif;?></td>
          <td data-col-key="notes" class="wide-cell"><?=h($r['notes']??'')?></td>
        </tr>
        <?php return trim((string)ob_get_clean());
    }

    public static function renderSharing(): void
    {
        Tenant::requirePermission('shares.view');
        $code=Sharing::workspaceCode();
        $incoming=Sharing::incomingData();
        $outgoing=Tenant::can('shares.manage')?Sharing::outgoing():[];
        $companies=[];$targets=[];
        if(Tenant::can('shares.manage')){$companies=self::companyRows();$targets=Sharing::availableTargets();}

        render_header('اشتراک امن داده‌ها','اشتراک انتخابی شرکت و کارهای مرتبط بین Workspaceها بدون دادن دسترسی به خود محیط کاری.');
        ?>
        <section class="v5-share-intro card">
          <div>
            <span class="v5-eyebrow">کد دریافت این Workspace</span>
            <div class="v5-share-code"><code data-v5-share-code><?=h($code)?></code><button class="btn tiny" type="button" data-v5-copy-share-code>کپی</button></div>
            <p>برای دریافت داده از Workspace دیگر فقط این کد را به ادمین آن محیط بدهید. این کد هیچ عضویت یا امکان ورود به Workspace شما ایجاد نمی‌کند.</p>
          </div>
          <div class="v5-security-note">🔒 اشتراک V5 فقط «مشاهده» است؛ رمز سامانه‌ها، تنظیمات، کاربران و داده‌های محیط‌های دیگر هرگز منتقل نمی‌شوند.</div>
        </section>

        <?php if(Tenant::can('shares.manage')):?>
        <section class="card">
          <details open><summary>اشتراک شرکت با Workspace دیگر</summary>
            <form method="post" data-v5-share-form class="v5-share-form">
              <?=csrf_field()?><input type="hidden" name="action" value="v5_share_create">
              <?php if($targets):?><label>Workspace مقصد از محیط‌های مجاز شما<select name="target_workspace_id"><option value="">— انتخاب با کد —</option><?php foreach($targets as $t):?><option value="<?=(int)$t['id']?>"><?=h($t['name'])?></option><?php endforeach;?></select></label><?php endif;?>
              <label>یا کد Workspace مقصد<input name="target_code" maxlength="32" placeholder="کد ۱۶ کاراکتری مقصد" dir="ltr"><small>برای محیطی که عضو آن نیستید، ادمین مقصد این کد را به شما می‌دهد.</small></label>
              <div class="v5-share-options">
                <label class="check"><input type="checkbox" name="share_daily" value="1" checked> برنامه روزانه</label>
                <label class="check"><input type="checkbox" name="share_monthly" value="1" checked> برنامه ماهانه</label>
                <label class="check"><input type="checkbox" name="share_phonebook" value="1" checked> دفترچه تلفن</label>
              </div>
              <div class="v5-company-check-grid">
                <?php foreach($companies as $c):?><label><input type="checkbox" name="company_ids[]" value="<?=(int)$c['id']?>"> <span><?=h($c['name'])?></span></label><?php endforeach;?>
              </div>
              <button class="btn primary">ایجاد / بروزرسانی اشتراک</button>
            </form>
          </details>
        </section>

        <section class="card">
          <div class="section-title"><h2>اشتراک‌های خروجی</h2><span class="muted"><?=count($outgoing)?> مورد فعال</span></div>
          <div class="v5-share-list">
            <?php foreach($outgoing as $s):?>
              <article class="v5-share-row">
                <div><b><?=h($s['company_name'])?></b><span>← <?=h($s['target_workspace_name'])?></span></div>
                <div class="v5-share-flags">
                  <?php if($s['share_daily']):?><span>روزانه</span><?php endif;?>
                  <?php if($s['share_monthly']):?><span>ماهانه</span><?php endif;?>
                  <?php if($s['share_phonebook']):?><span>تماس‌ها</span><?php endif;?>
                </div>
                <button class="btn tiny danger" type="button" data-v5-share-revoke data-id="<?=(int)$s['id']?>">لغو</button>
              </article>
            <?php endforeach;?>
            <?php if(!$outgoing):?><div class="calendar-empty">اشتراک خروجی فعالی ندارید.</div><?php endif;?>
          </div>
        </section>
        <?php endif;?>

        <section class="card">
          <div class="section-title"><h2>داده‌های دریافتی از Workspaceهای دیگر</h2><span class="muted"><?=count($incoming)?> شرکت اشتراکی</span></div>
          <div class="v5-incoming-grid">
          <?php foreach($incoming as $item):$s=$item['share'];?>
            <article class="v5-incoming-card">
              <div class="v5-incoming-head"><div><span class="v5-eyebrow"><?=h($s['source_workspace_name'])?></span><h3><?=h($s['company_name'])?></h3></div><div class="row-actions"><span class="v5-readonly-badge">فقط مشاهده</span><?php if(Tenant::can('shares.manage')):?><button class="btn tiny danger-soft" type="button" data-v5-share-reject data-id="<?=(int)$s['id']?>">توقف دریافت</button><?php endif;?></div></div>
              <div class="v5-shared-company-meta">
                <?php if(!empty($s['company_type'])):?><span>نوع: <?=h($s['company_type'])?></span><?php endif;?>
                <?php if(!empty($s['national_id'])):?><span>شناسه ملی: <?=h($s['national_id'])?></span><?php endif;?>
                <?php if(!empty($s['economic_code'])):?><span>کد اقتصادی: <?=h($s['economic_code'])?></span><?php endif;?>
                <?php if(!empty($s['company_phone'])):?><span>تلفن: <?=h($s['company_phone'])?></span><?php endif;?>
                <?php if(!empty($s['software'])):?><span>نرم‌افزار: <?=h($s['software'])?></span><?php endif;?>
              </div>
              <?php if($s['share_daily']):?>
              <details><summary>برنامه روزانه (<?=count($item['daily'])?>)</summary><div class="v5-shared-table"><?php foreach($item['daily'] as $r):?><div><time><?=h(self::faDate($r['plan_date']??null))?></time><b><?=h($r['work_description'])?></b><span><?=h($r['notes']??'')?></span></div><?php endforeach;?><?php if(!$item['daily']):?><p>موردی ثبت نشده است.</p><?php endif;?></div></details>
              <?php endif;?>
              <?php if($s['share_monthly']):?>
              <details><summary>برنامه ماهانه (<?=count($item['monthly'])?>)</summary><div class="v5-shared-table"><?php foreach($item['monthly'] as $r):?><div><time><?=h(self::faDate($r['legal_deadline']??null))?></time><b><?=h($r['work_type'])?></b><span><?=h(($r['month_name']??'').' • '.($r['status']??''))?></span></div><?php endforeach;?><?php if(!$item['monthly']):?><p>موردی ثبت نشده است.</p><?php endif;?></div></details>
              <?php endif;?>
              <?php if($s['share_phonebook']):?>
              <details><summary>تماس و پیگیری (<?=count($item['phonebook'])?>)</summary><div class="v5-shared-table"><?php foreach($item['phonebook'] as $r):?><div><time><?=h(self::faDate($r['contacted_date']??null))?></time><b><?=h(($r['person_name']?:'—').' — '.($r['organization_name']?:''))?></b><span dir="ltr"><?=h($r['phone_number'])?></span></div><?php endforeach;?><?php if(!$item['phonebook']):?><p>موردی ثبت نشده است.</p><?php endif;?></div></details>
              <?php endif;?>
            </article>
          <?php endforeach;?>
          <?php if(!$incoming):?><div class="calendar-empty">هنوز داده‌ای با این Workspace به اشتراک گذاشته نشده است.</div><?php endif;?>
          </div>
        </section>
        <?php render_footer();
    }

    public static function renderPerformance(): void
    {
        Tenant::requirePermission('cache.manage');
        $stats=RuntimeCache::stats(Tenant::id());
        render_header('عملکرد و کش','کش فایل داخلی سازگار با cPanel، کنترل دستی، وضعیت OPcache و معماری Fast Schema.');
        ?>
        <section class="v5-performance-grid">
          <div class="metric card"><strong><?=h($stats['backend'])?></strong><span>Backend کش</span></div>
          <div class="metric card"><strong><?=(int)$stats['entries']?></strong><span>آیتم کش Workspace</span></div>
          <div class="metric card"><strong><?=h(self::formatBytes((int)$stats['bytes']))?></strong><span>حجم کش</span></div>
          <div class="metric card"><strong><?=h($stats['schema']['version']??'—')?></strong><span>Fast Schema Marker</span></div>
          <div class="metric card"><strong><?=$stats['opcache']?'فعال':'غیرفعال'?></strong><span>PHP OPcache</span></div>
          <div class="metric card"><strong><?=h($stats['php'])?></strong><span>PHP</span></div>
          <div class="metric card"><strong><?=h(setting('cache_ttl_seconds','60'))?>s</strong><span>TTL کش داده</span></div>
        </section>
        <section class="card v5-cache-actions">
          <div><h2>مدیریت کش</h2><p class="muted">پاک‌سازی کش باعث حذف داده اصلی نمی‌شود. در درخواست بعدی داده لازم دوباره از MySQL خوانده و Cache می‌شود.</p></div>
          <div class="row-actions">
            <button class="btn primary" type="button" data-v5-cache-action="warm">گرم‌سازی کش</button>
            <button class="btn" type="button" data-v5-cache-action="workspace">پاک‌سازی کش این Workspace</button>
            <?php if(Tenant::isPlatformAdmin()):?><button class="btn danger" type="button" data-v5-cache-action="all">پاک‌سازی کل کش پلتفرم</button><?php endif;?>
          </div>
        </section>
        <section class="card">
          <h2>بهینه‌سازی‌های فعال V5</h2>
          <div class="v5-optimization-list">
            <span>✓ حذف Migration سنگین از هر Page Request</span>
            <span>✓ Cache فایل مستقل برای هر Workspace</span>
            <span>✓ Composite Index برای Queryهای Tenant</span>
            <span>✓ ویرایش ردیفی Batch در یک Request</span>
            <span>✓ ثبت فرم‌های اصلی با AJAX بدون Refresh کامل</span>
            <span>✓ Audit page-view غیرفعال به‌صورت پیش‌فرض</span>
          </div>
        </section>
        <?php render_footer();
    }

    public static function coreRowHtml(string $entity,int $id): string
    {
        return match($entity){
            'companies'=>self::companyRow($id),
            'daily_plans'=>self::dailyRow($id),
            'monthly_plans'=>self::monthlyRow($id),
            default=>'',
        };
    }

    private static function selectHtml(string $field,$selected,array $items): string
    {
        $html='<select data-field="'.h($field).'" class="inline-select row-edit-control" disabled>';
        foreach($items as $i)$html.='<option '.((string)$selected===(string)$i?'selected':'').'>'.h($i).'</option>';
        return$html.'</select>';
    }

    private static function actionHtml(string $entity,int $id): string
    {
        return '<div class="row-actions"><button type="button" class="btn icon" data-edit-row>ویرایش</button>'.
            '<button type="button" class="btn icon" data-attachments data-entity="'.h($entity).'" data-id="'.$id.'">فایل‌ها</button>'.
            '<button type="button" class="btn icon danger" data-delete data-entity="'.h($entity).'" data-id="'.$id.'">حذف</button></div>';
    }

    private static function extraFields(string $entity): array
    {
        $wid=Tenant::id();$ttl=max(10,min(3600,(int)setting('cache_ttl_seconds','60')));
        return RuntimeCache::remember('custom_fields:'.$entity,$ttl,function()use($wid,$entity){
            $st=pdo()->prepare("SELECT * FROM custom_fields WHERE workspace_id=? AND entity_key=? AND active=1 ORDER BY sort_order,id");
            $st->execute([$wid,$entity]);return$st->fetchAll();
        },$wid);
    }

    private static function companyRow(int $id): string
    {
        $st=pdo()->prepare("SELECT * FROM companies WHERE id=? AND workspace_id=? LIMIT 1");$st->execute([$id,Tenant::id()]);$r=$st->fetch();if(!$r)return'';
        $extra=json_decode((string)($r['extra_json']??'{}'),true)?:[];
        $cells=[
            'actions'=>self::actionHtml('companies',$id),
            'name'=>h($r['name']),
            'company_type'=>self::selectHtml('company_type',$r['company_type']?:$r['type'],ChoiceRegistry::labels('company_type',(string)($r['company_type']?:$r['type']),true)),
            'legal_personality'=>self::selectHtml('legal_personality',$r['legal_personality'],ChoiceRegistry::labels('legal_personality',(string)$r['legal_personality'],true)),
            'national_id'=>h($r['national_id']),
            'economic_code'=>h($r['economic_code']),
            'registration_number'=>h($r['registration_number']),
            'address'=>h($r['address']),
            'postal_code'=>h($r['postal_code']),
            'phone'=>h($r['phone']),
            'ceo_name'=>h($r['ceo_name']?:$r['manager_name']),
            'ceo_national_id'=>h($r['ceo_national_id']),
            'ceo_mobile'=>h($r['ceo_mobile']),
            'software'=>self::selectHtml('software',$r['software'],ChoiceRegistry::labels('accounting_software',(string)$r['software'],true)),
        ];
        foreach(self::extraFields('companies') as $f)$cells['extra.'.$f['field_key']]=h($extra[$f['field_key']]??'');
        return self::rowFromCells($id,$cells);
    }

    private static function companySelectHtml(int $selected): string
    {
        $html='<select data-field="company_id" class="inline-select row-edit-control" disabled>';
        foreach(self::companyRows() as $c)$html.='<option value="'.(int)$c['id'].'" '.($selected===(int)$c['id']?'selected':'').'>'.h($c['name']).'</option>';
        return$html.'</select>';
    }

    private static function dailyRow(int $id): string
    {
        $st=pdo()->prepare("SELECT d.*,c.name company_name FROM daily_plans d LEFT JOIN companies c ON c.id=d.company_id WHERE d.id=? AND d.workspace_id=? LIMIT 1");
        $st->execute([$id,Tenant::id()]);$r=$st->fetch();if(!$r)return'';
        $extra=json_decode((string)($r['extra_json']??'{}'),true)?:[];
        $cells=[
            'actions'=>self::actionHtml('daily_plans',$id),
            'plan_date'=>'<input class="inline-date jalali-date row-edit-control" data-field="plan_date" value="'.h(self::faDate($r['plan_date'])).'" disabled autocomplete="off">',
            'day_name'=>h($r['day_name']),
            'company_id'=>self::companySelectHtml((int)$r['company_id']),
            'work_description'=>h($r['work_description']),
            'notes'=>h($r['notes']),
        ];
        foreach(self::extraFields('daily_plans') as $f)$cells['extra.'.$f['field_key']]=h($extra[$f['field_key']]??'');
        return self::rowFromCells($id,$cells);
    }

    private static function monthlyRow(int $id): string
    {
        $st=pdo()->prepare("SELECT m.*,c.name company_name FROM monthly_plans m LEFT JOIN companies c ON c.id=m.company_id WHERE m.id=? AND m.workspace_id=? LIMIT 1");
        $st->execute([$id,Tenant::id()]);$r=$st->fetch();if(!$r)return'';
        $extra=json_decode((string)($r['extra_json']??'{}'),true)?:[];
        $cells=[
            'actions'=>self::actionHtml('monthly_plans',$id),
            'company_id'=>self::companySelectHtml((int)$r['company_id']),
            'month_name'=>self::selectHtml('month_name',$r['month_name'],ChoiceRegistry::labels('monthly_month',(string)$r['month_name'],true)),
            'season'=>self::selectHtml('season',$r['season'],ChoiceRegistry::labels('monthly_season',(string)$r['season'],true)),
            'work_type'=>self::selectHtml('work_type',$r['work_type'],ChoiceRegistry::labels('monthly_work_type',(string)$r['work_type'],true)),
            'legal_deadline'=>'<input class="inline-date jalali-date row-edit-control" data-field="legal_deadline" value="'.h(self::faDate($r['legal_deadline'])).'" disabled autocomplete="off">',
            'status'=>self::selectHtml('status',$r['status'],ChoiceRegistry::labels('monthly_status',(string)$r['status'],true)),
            'work_day'=>h($r['work_day']),
            'completed_date'=>'<input class="inline-date jalali-date row-edit-control" data-field="completed_date" value="'.h(self::faDate($r['completed_date'])).'" disabled autocomplete="off">',
        ];
        foreach(self::extraFields('monthly_plans') as $f)$cells['extra.'.$f['field_key']]=h($extra[$f['field_key']]??'');
        return self::rowFromCells($id,$cells);
    }

    private static function rowFromCells(int $id,array $cells): string
    {
        $html='<tr data-id="'.$id.'">';
        foreach($cells as $key=>$content){
            $cls=in_array($key,['address','work_description','notes'],true)?' class="wide-cell"':'';
            $editable=!in_array($key,['actions','company_id','company_type','legal_personality','software','month_name','season','work_type','legal_deadline','status','completed_date','plan_date'],true);
            $attr=$editable?' data-field="'.h($key).'" class="editable-cell'.($cls?' wide-cell':'').'"':'';
            if($editable)$html.='<td data-col-key="'.h($key).'"'.$attr.'>'.$content.'</td>';
            else $html.='<td data-col-key="'.h($key).'"'.$cls.'>'.$content.'</td>';
        }
        return$html.'</tr>';
    }

    private static function formatBytes(int $bytes): string
    {
        if($bytes<1024)return$bytes.' B';
        if($bytes<1048576)return round($bytes/1024,1).' KB';
        if($bytes<1073741824)return round($bytes/1048576,1).' MB';
        return round($bytes/1073741824,1).' GB';
    }
}
