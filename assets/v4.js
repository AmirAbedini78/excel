/* Accounting CRM V4: workspace, notes and universal attachments */
(function(){
  if(window.__ACCOUNTING_V4__)return;window.__ACCOUNTING_V4__=true;
  const qsa=(s,r=document)=>Array.from(r.querySelectorAll(s));
  const postFileApi=async(data)=>{
    const r=await fetch('v4_files.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},body:new URLSearchParams(Object.assign({csrf:window.CSRF||''},data))});
    const j=await r.json();if(!r.ok||j.ok===false)throw new Error(j.error||'خطا');return j;
  };

  // Notes editor
  const editor=document.querySelector('[data-note-editor]');
  document.querySelector('[data-new-note]')?.addEventListener('click',()=>{if(!editor)return;editor.hidden=false;editor.querySelector('[name=id]').value='';editor.querySelector('[name=title]').value='';editor.querySelector('[name=body]').value='';editor.querySelector('[name=pinned]').checked=false;editor.querySelector('[name=title]').focus();});
  document.querySelector('[data-cancel-note]')?.addEventListener('click',()=>{if(editor)editor.hidden=true});
  qsa('[data-edit-note]').forEach(b=>b.addEventListener('click',()=>{if(!editor)return;editor.hidden=false;editor.querySelector('[name=id]').value=b.closest('[data-note-id]').dataset.noteId;editor.querySelector('[name=title]').value=b.dataset.title||'';editor.querySelector('[name=body]').value=b.dataset.body||'';editor.querySelector('[name=pinned]').checked=b.dataset.pinned==='1';editor.scrollIntoView({behavior:'smooth',block:'start'});}));

  // Workspace switcher injected into top bar
  if(window.V4_WORKSPACES&&Array.isArray(window.V4_WORKSPACES)&&window.V4_WORKSPACES.length){
    const actions=document.querySelector('.top-actions');if(actions&&!actions.querySelector('.workspace-switcher')){
      const form=document.createElement('form');form.method='post';form.className='workspace-switcher';
      form.innerHTML=`<input type="hidden" name="csrf" value="${window.CSRF||''}"><input type="hidden" name="action" value="v4_switch_workspace"><select name="workspace_id" aria-label="محیط کاری">${window.V4_WORKSPACES.map(w=>`<option value="${w.workspace_id}" ${String(w.workspace_id)===String(window.V4_WORKSPACE_ID)?'selected':''}>${String(w.workspace_name).replace(/[&<>"]/g,'')}${w.role_name?` — ${String(w.role_name).replace(/[&<>"]/g,'')}`:``}</option>`).join('')}</select>`;
      form.querySelector('select').addEventListener('change',()=>form.submit());actions.prepend(form);
    }
  }

  // Universal attachment component for create forms
  const supported={save_company:'companies',save_daily_plan:'daily_plans',save_monthly_plan:'monthly_plans',save_custom_field:'custom_fields',v4_save_note:'notes'};
  qsa('form').forEach(form=>{
    const a=form.querySelector('input[name=action]')?.value,entity=supported[a];if(!entity||form.querySelector('.v4-form-files'))return;
    const box=document.createElement('div');box.className='v4-form-files span2';box.innerHTML='<div class="v4-form-files-head"><strong>فایل‌های پیوست</strong><div><button type="button" class="btn tiny" data-pick-library>انتخاب از لایبرری</button><label class="btn tiny primary v4-upload-label">آپلود مستقیم<input type="file" hidden data-direct-upload></label></div></div><div class="v4-selected-files"></div>';
    const submit=form.querySelector('button[type=submit],button:not([type])');if(submit)form.insertBefore(box,submit);else form.append(box);
    const selected=new Map();
    const render=()=>{const area=box.querySelector('.v4-selected-files');area.innerHTML='';selected.forEach((f,id)=>{const chip=document.createElement('span');chip.className='v4-file-chip';chip.textContent=f.original_name||f.title||('File '+id);const x=document.createElement('button');x.type='button';x.textContent='×';x.onclick=()=>{selected.delete(id);render()};chip.append(x);area.append(chip)});qsa('input[name="attachment_file_ids[]"]',form).forEach(x=>x.remove());selected.forEach((f,id)=>{const h=document.createElement('input');h.type='hidden';h.name='attachment_file_ids[]';h.value=id;form.append(h)});};
    box.querySelector('[data-pick-library]').onclick=()=>openLibraryPicker(files=>{files.forEach(f=>selected.set(String(f.id),f));render()});
    box.querySelector('[data-direct-upload]').onchange=async e=>{const file=e.target.files?.[0];if(!file)return;const fd=new FormData();fd.append('csrf',window.CSRF||'');fd.append('action','upload');fd.append('file',file);const r=await fetch('v4_files.php',{method:'POST',body:fd});const j=await r.json();if(!r.ok||j.ok===false){alert(j.error||'آپلود ناموفق');return}selected.set(String(j.file.id),j.file);render();e.target.value='';};
  });

  // Attachments button on existing rows/cards
  qsa('[data-delete]').forEach(del=>{
    const actions=del.closest('.row-actions');if(!actions||actions.querySelector('[data-attachments]'))return;
    const b=document.createElement('button');b.type='button';b.className='btn icon';b.textContent='فایل‌ها';b.dataset.attachments='1';b.dataset.entity=del.dataset.entity;b.dataset.id=del.dataset.id;actions.insertBefore(b,del);
  });
  qsa('[data-system-row]').forEach(row=>{
    const actions=row.querySelector('.row-actions');if(!actions||actions.querySelector('[data-attachments]'))return;
    const b=document.createElement('button');b.type='button';b.className='btn icon';b.textContent='فایل‌ها';b.dataset.attachments='1';b.dataset.entity='systems_company';b.dataset.id=row.dataset.companyId;actions.append(b);
  });
  document.addEventListener('click',e=>{const b=e.target.closest('[data-attachments]');if(!b)return;openAttachmentManager(b.dataset.entity,+b.dataset.id);});

  async function openLibraryPicker(done){
    let j;try{const r=await fetch('v4_files.php?action=list');j=await r.json()}catch(e){alert('لایبرری قابل دریافت نیست');return}
    const modal=makeModal('انتخاب از لایبرری');const body=modal.querySelector('.v4-modal-body');const chosen=new Map();
    const grid=document.createElement('div');grid.className='v4-picker-grid';
    (j.files||[]).forEach(f=>{const item=document.createElement('label');item.className='v4-picker-item';item.innerHTML=`<input type="checkbox"><span>${escapeHtml(f.original_name)}</span><small>${formatBytes(+f.size_bytes||0)}</small>`;item.querySelector('input').onchange=ev=>ev.target.checked?chosen.set(String(f.id),f):chosen.delete(String(f.id));grid.append(item)});body.append(grid);
    const foot=document.createElement('div');foot.className='v4-modal-foot';const use=document.createElement('button');use.type='button';use.className='btn primary';use.textContent='اتچ فایل‌های انتخابی';use.onclick=()=>{done(Array.from(chosen.values()));modal.remove()};foot.append(use);body.append(foot);
  }

  async function openAttachmentManager(entity,id){
    const modal=makeModal('فایل‌های پیوست');const body=modal.querySelector('.v4-modal-body');
    const reload=async()=>{body.innerHTML='<div class="muted">در حال بارگذاری...</div>';try{
      const j=await postFileApi({action:'attachments',entity,record_id:id});body.innerHTML='';
      const toolbar=document.createElement('div');toolbar.className='v4-attach-toolbar';
      const pick=document.createElement('button');pick.type='button';pick.className='btn tiny';pick.textContent='انتخاب از لایبرری';pick.onclick=()=>openLibraryPicker(async files=>{await postFileApi({action:'attach',entity,record_id:id,file_ids:JSON.stringify(files.map(f=>+f.id))});reload()});
      const upLabel=document.createElement('label');upLabel.className='btn tiny primary v4-upload-label';upLabel.innerHTML='آپلود و اتچ<input type="file" hidden>';upLabel.querySelector('input').onchange=async ev=>{const file=ev.target.files?.[0];if(!file)return;const fd=new FormData();fd.append('csrf',window.CSRF||'');fd.append('action','upload');fd.append('file',file);const r=await fetch('v4_files.php',{method:'POST',body:fd});const u=await r.json();if(!r.ok||u.ok===false){alert(u.error||'خطا');return}await postFileApi({action:'attach',entity,record_id:id,file_ids:JSON.stringify([+u.file.id])});reload()};
      toolbar.append(pick,upLabel);body.append(toolbar);
      const list=document.createElement('div');list.className='v4-attachment-list';
      (j.files||[]).forEach(f=>{const row=document.createElement('div');row.className='v4-attachment-row';row.innerHTML=`<div><b>${escapeHtml(f.original_name)}</b><small>${formatBytes(+f.size_bytes||0)}</small></div><div><a class="btn tiny" href="file_download.php?id=${f.id}">دانلود</a><button type="button" class="btn tiny danger">حذف اتچ</button></div>`;row.querySelector('button').onclick=async()=>{await postFileApi({action:'detach',entity,record_id:id,file_id:f.id});reload()};list.append(row)});body.append(list);
      if(!(j.files||[]).length){const x=document.createElement('div');x.className='calendar-empty';x.textContent='فایلی اتچ نشده است.';body.append(x)}
    }catch(e){body.textContent=e.message}};reload();
  }

  function makeModal(title){
    const back=document.createElement('div');back.className='modal-backdrop v4-modal-backdrop';back.innerHTML=`<section class="v4-modal"><div class="modal-head"><h2>${escapeHtml(title)}</h2><button type="button" class="btn tiny" data-close>بستن</button></div><div class="v4-modal-body"></div></section>`;document.body.append(back);back.querySelector('[data-close]').onclick=()=>back.remove();back.onclick=e=>{if(e.target===back)back.remove()};return back;
  }
  function escapeHtml(s){return String(s??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]))}
  function formatBytes(b){if(b<1024)return b+' B';if(b<1048576)return(b/1024).toFixed(1)+' KB';return(b/1048576).toFixed(1)+' MB'}

  // Library deletion
  qsa('[data-delete-library-file]').forEach(b=>b.onclick=async()=>{if(!confirm('فایل از لایبرری حذف شود؟'))return;try{await postFileApi({action:'delete',id:b.closest('[data-file-id]').dataset.fileId});b.closest('[data-file-id]').remove()}catch(e){alert(e.message)}});
  // SaaS API V2 shortcut in Settings.
  if(new URLSearchParams(location.search).get('page')==='settings'){
    const main=document.querySelector('.main');
    if(main&&!main.querySelector('.v4-api-card')){
      const card=document.createElement('section');card.className='card v4-api-card';
      card.innerHTML='<div class="section-title"><div><h2>SaaS API V2</h2><div class="muted">توکن‌های Workspace-scoped برای Hermes، RAG و AI Agent</div></div><div class="row-actions"><a class="btn tiny primary" href="api_tokens.php">مدیریت Tokenها</a><a class="btn tiny" href="API_V2_SAAS_FA.md" target="_blank">مستندات</a></div></div><code class="code">/api/v2</code>';
      main.append(card);
    }
  }

})();

/* V4.1: the V3.3 global API manager is deprecated in SaaS mode. */
if(new URLSearchParams(location.search).get('page')==='settings'){
  setTimeout(()=>document.querySelector('.api-manager-card')?.remove(),0);
}
