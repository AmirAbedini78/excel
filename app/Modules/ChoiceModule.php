<?php
final class ChoiceModule
{
    public static function handle(string $action): void
    {
        Tenant::requirePermission('choices.manage');
        ChoiceRegistry::ensureSchema();

        if($action==='choice_add'){
            $setKey=trim((string)($_POST['set_key']??''));
            $value=trim((string)($_POST['value']??''));
            $r=ChoiceRegistry::addValue($setKey,$value);
            Audit::log('choice.add','choice_values',(int)($r['id']??0),'افزودن مقدار انتخابی',null,['set_key'=>$setKey,'value'=>$value]);
            flash('مقدار جدید اضافه شد.');redirect('index.php?page=choices');
        }
        if($action==='choice_toggle'){
            $id=(int)($_POST['id']??0);$active=(int)($_POST['active']??0)===1;
            $before=ChoiceRegistry::valueById($id);$r=ChoiceRegistry::toggle($id,$active);
            Audit::log('choice.toggle','choice_values',$id,$active?'فعال‌سازی مقدار':'غیرفعال‌سازی مقدار',$before,$r);
            flash($active?'مقدار فعال شد.':'مقدار از انتخاب‌های جدید حذف شد؛ داده‌های قبلی حفظ شدند.');redirect('index.php?page=choices');
        }
        if($action==='choice_move'){
            $id=(int)($_POST['id']??0);$dir=($_POST['direction']??'down')==='up'?'up':'down';
            ChoiceRegistry::move($id,$dir);Audit::log('choice.move','choice_values',$id,'تغییر ترتیب مقدار',null,['direction'=>$dir]);
            redirect('index.php?page=choices');
        }
        if($action==='choice_restore'){
            $setKey=trim((string)($_POST['set_key']??''));ChoiceRegistry::restoreDefaults($setKey);
            Audit::log('choice.restore','choice_sets',0,'بازگردانی مقادیر پیش‌فرض',null,['set_key'=>$setKey]);
            flash('مقادیر پیش‌فرض این گروه بازگردانی شدند.');redirect('index.php?page=choices');
        }
    }

    public static function render(): void
    {
        Tenant::requirePermission('choices.manage');
        ChoiceRegistry::ensureSchema();
        render_header('مقادیر انتخابی','مقادیر Combo Boxهای کاری این Workspace را بدون تغییر کد مدیریت کنید.');
        echo '<section class="card choice-help"><div><b>مدیریت مرکزی Combo Boxها</b><p>غیرفعال‌کردن یک مقدار، داده‌های قبلی را حذف نمی‌کند؛ فقط آن مقدار از انتخاب‌های جدید کنار گذاشته می‌شود. مقادیر استفاده‌شده در رکوردهای قبلی همچنان حفظ می‌شوند.</p></div></section>';
        echo '<section class="choice-set-grid">';
        foreach(ChoiceRegistry::sets() as $set){
            $key=$set['set_key'];$values=ChoiceRegistry::valuesDetailed($key,true);
            echo '<article class="card choice-set-card"><div class="choice-set-head"><div><h2>'.h($set['title']).'</h2><p>'.h($set['description']).'</p></div><span class="choice-count">'.(int)$set['active_count'].' فعال</span></div>';
            echo '<form method="post" class="choice-add-form">'.csrf_field().'<input type="hidden" name="action" value="choice_add"><input type="hidden" name="set_key" value="'.h($key).'"><input name="value" placeholder="مقدار جدید..." required><button class="btn primary tiny">+ افزودن</button></form>';
            echo '<div class="choice-value-list">';
            foreach($values as $v){
                $inactive=!(int)$v['active'];$usage=(int)$v['usage_count'];
                echo '<div class="choice-value-row '.($inactive?'inactive':'').'"><div class="choice-value-main"><b>'.h($v['value']).'</b><small>'.($usage?$usage.' رکورد در حال استفاده':'بدون استفاده').'</small></div><div class="choice-actions">';
                echo '<form method="post">'.csrf_field().'<input type="hidden" name="action" value="choice_move"><input type="hidden" name="id" value="'.(int)$v['id'].'"><input type="hidden" name="direction" value="up"><button class="btn tiny" title="بالاتر">↑</button></form>';
                echo '<form method="post">'.csrf_field().'<input type="hidden" name="action" value="choice_move"><input type="hidden" name="id" value="'.(int)$v['id'].'"><input type="hidden" name="direction" value="down"><button class="btn tiny" title="پایین‌تر">↓</button></form>';
                echo '<form method="post">'.csrf_field().'<input type="hidden" name="action" value="choice_toggle"><input type="hidden" name="id" value="'.(int)$v['id'].'"><input type="hidden" name="active" value="'.($inactive?'1':'0').'"><button class="btn tiny '.($inactive?'primary':'danger-soft').'">'.($inactive?'فعال‌سازی':'حذف از لیست').'</button></form>';
                echo '</div></div>';
            }
            echo '</div><form method="post" class="choice-restore" onsubmit="return confirm(\'مقادیر پیش‌فرض این گروه فعال/بازگردانی شوند؟\')">'.csrf_field().'<input type="hidden" name="action" value="choice_restore"><input type="hidden" name="set_key" value="'.h($key).'"><button class="btn tiny">بازگردانی پیش‌فرض‌ها</button></form>';
            echo '</article>';
        }
        echo '</section>';
        render_footer();
    }
}
