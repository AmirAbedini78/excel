/* Accounting CRM V5 - AJAX UX, Notes Tasks, Phonebook, Sharing, Cache */
(function(){
  if(window.__ACCOUNTING_V5__)return;
  window.__ACCOUNTING_V5__=true;

  const q=(s,r=document)=>r.querySelector(s);
  const qa=(s,r=document)=>Array.from(r.querySelectorAll(s));

  function toast(message,type='success'){
    let host=q('.v5-toast-host');
    if(!host){host=document.createElement('div');host.className='v5-toast-host';document.body.append(host);}
    const t=document.createElement('div');t.className='v5-toast '+type;t.textContent=message;
    host.append(t);requestAnimationFrame(()=>t.classList.add('show'));
    setTimeout(()=>{t.classList.remove('show');setTimeout(()=>t.remove(),220)},2600);
  }
  async function postForm(url,fd){
    if(!fd.has('csrf'))fd.append('csrf',window.CSRF||'');
    const r=await fetch(url,{method:'POST',body:fd,headers:{'X-Requested-With':'XMLHttpRequest'}});
    let j={};try{j=await r.json()}catch(e){throw new Error('پاسخ نامعتبر از سرور');}
    if(!r.ok||j.ok===false)throw new Error(j.error||'عملیات انجام نشد');
    return j;
  }
  async function postData(url,data){
    const fd=new FormData();Object.entries(data).forEach(([k,v])=>fd.append(k,v));
    return postForm(url,fd);
  }
  function htmlOne(html,selector){
    const tpl=document.createElement('template');tpl.innerHTML=String(html||'').trim();
    return selector?tpl.content.querySelector(selector):tpl.content.firstElementChild;
  }

  function syncInsertedRow(row,table){
    if(!row||!table)return;
    const header=table.tHead?.rows?.[table.tHead.rows.length-1];if(!header)return;
    const headerKeys=Array.from(header.cells).map(x=>x.dataset.colKey).filter(Boolean);
    const byKey=new Map(Array.from(row.cells).filter(c=>c.dataset.colKey).map(c=>[c.dataset.colKey,c]));
    headerKeys.forEach(k=>{const c=byKey.get(k);if(c)row.appendChild(c);});
    byKey.forEach((c,k)=>{
      const th=Array.from(header.cells).find(x=>x.dataset.colKey===k);if(!th)return;
      c.classList.toggle('col-hidden',th.classList.contains('col-hidden'));
      ['width','minWidth','maxWidth'].forEach(p=>{c.style[p]=th.style[p]||'';});
    });
    const bulk=header.querySelector('.v32-select-all');
    if(bulk&&!row.querySelector('.v32-row-select')){
      const td=document.createElement('td');td.className='v32-select-cell';
      const cb=document.createElement('input');cb.type='checkbox';cb.className='v32-row-select';cb.value=row.dataset.id||'';
      td.append(cb);row.insertBefore(td,row.firstChild);
    }
  }

  function clearAttachmentSelection(form){
    qa('input[name="attachment_file_ids[]"]',form).forEach(x=>x.remove());
    const area=q('.v4-selected-files',form);if(area)area.innerHTML='';
  }

  // Core create forms: AJAX + insert server-rendered row, no full page reload.
  document.addEventListener('submit',async e=>{
    const form=e.target.closest('form');if(!form)return;
    const action=form.querySelector('input[name="action"]')?.value||'';
    if(!['save_company','save_daily_plan','save_monthly_plan'].includes(action))return;
    if(new URLSearchParams(location.search).get('page')==='login')return;
    e.preventDefault();
    const btn=form.querySelector('button[type="submit"],button:not([type])');if(btn)btn.disabled=true;
    const fd=new FormData(form);fd.append('_ajax','1');
    try{
      const r=await postForm('index.php',fd);
      const entity=action==='save_company'?'companies':action==='save_daily_plan'?'daily_plans':'monthly_plans';
      const table=q(`table.data-table[data-entity="${entity}"]`);
      if(table&&r.row_html){
        const row=htmlOne(r.row_html,'tr');
        const old=table.tBodies[0]?.querySelector(`tr[data-id="${CSS.escape(String(r.id))}"]`);
        if(old)old.replaceWith(row);else table.tBodies[0]?.prepend(row);
        syncInsertedRow(row,table);
      }
      const year=form.querySelector('[name="jalali_year"]')?.value;
      form.reset();if(year&&form.querySelector('[name="jalali_year"]'))form.querySelector('[name="jalali_year"]').value=year;
      clearAttachmentSelection(form);
      toast(r.message||'ذخیره شد.');
      form.closest('details')?.removeAttribute('open');
    }catch(err){toast(err.message,'error');}
    finally{if(btn)btn.disabled=false;}
  });

  // Notes / To-do.
  const noteEditor=()=>q('[data-v5-note-editor]');
  function resetNoteEditor(){
    const ed=noteEditor();if(!ed)return;const form=q('[data-v5-note-form]',ed);form?.reset();
    if(form)form.querySelector('[name="id"]').value='';
    clearAttachmentSelection(form||ed);ed.hidden=false;
    form?.querySelector('[name="title"]')?.focus();
  }
  document.addEventListener('click',e=>{
    const n=e.target.closest('[data-v5-new-note]');if(n){resetNoteEditor();return;}
    const c=e.target.closest('[data-v5-cancel-note]');if(c){const ed=noteEditor();if(ed)ed.hidden=true;return;}
    const edit=e.target.closest('[data-v5-edit-note]');
    if(edit){
      const card=edit.closest('[data-note-id]');let data={};try{data=JSON.parse(card?.dataset.noteJson||'{}')}catch(_){}
      const ed=noteEditor();const form=ed&&q('[data-v5-note-form]',ed);if(!form)return;
      ed.hidden=false;form.querySelector('[name="id"]').value=data.id||'';
      form.querySelector('[name="title"]').value=data.title||'';
      form.querySelector('[name="body"]').value=data.body||'';
      form.querySelector('[name="priority"]').value=data.priority||'normal';
      form.querySelector('[name="due_date"]').value=data.due_date||'';
      form.querySelector('[name="pinned"]').checked=!!Number(data.pinned);
      ed.scrollIntoView({behavior:'smooth',block:'start'});return;
    }
    const del=e.target.closest('[data-v5-delete-note]');
    if(del){
      const card=del.closest('[data-note-id]');if(!card||!confirm('این نوت / کار حذف شود؟'))return;
      postData('v5_api.php',{action:'v5_note_delete',id:card.dataset.noteId,csrf:window.CSRF||''})
        .then(r=>{card.remove();toast(r.message||'حذف شد.');}).catch(err=>toast(err.message,'error'));
      return;
    }
  });
  document.addEventListener('change',e=>{
    const toggle=e.target.closest('[data-v5-note-toggle]');if(!toggle)return;
    const card=toggle.closest('[data-note-id]');if(!card)return;
    toggle.disabled=true;
    postData('v5_api.php',{action:'v5_note_toggle',id:card.dataset.noteId,completed:toggle.checked?'1':'0',csrf:window.CSRF||''})
      .then(r=>{
        if(r.card_html){const next=htmlOne(r.card_html);card.replaceWith(next);}
        toast(r.completed?'کار انجام شد ✓':'کار دوباره باز شد.');
      }).catch(err=>{toggle.checked=!toggle.checked;toast(err.message,'error')}).finally(()=>{toggle.disabled=false});
  });
  document.addEventListener('submit',async e=>{
    const form=e.target.closest('[data-v5-note-form]');if(!form)return;
    e.preventDefault();const btn=form.querySelector('button[type="submit"]');if(btn)btn.disabled=true;
    try{
      const r=await postForm('v5_api.php',new FormData(form));
      const grid=q('[data-v5-notes-grid]');const card=htmlOne(r.card_html);
      const old=grid?.querySelector(`[data-note-id="${CSS.escape(String(r.id))}"]`);
      if(old)old.replaceWith(card);else{q('[data-v5-notes-empty]')?.remove();grid?.prepend(card);}
      form.reset();form.querySelector('[name="id"]').value='';clearAttachmentSelection(form);
      const ed=noteEditor();if(ed)ed.hidden=true;toast(r.message||'نوت ذخیره شد.');
    }catch(err){toast(err.message,'error')}
    finally{if(btn)btn.disabled=false}
  });

  // Phonebook.
  const phoneEditor=()=>q('[data-v5-phone-editor]');
  function resetPhone(){
    const ed=phoneEditor();const form=ed&&q('[data-v5-phone-form]',ed);if(!form)return;
    form.reset();form.querySelector('[name="id"]').value='';clearAttachmentSelection(form);ed.hidden=false;
    form.querySelector('[name="client_company_id"]')?.focus();
  }
  document.addEventListener('click',e=>{
    if(e.target.closest('[data-v5-new-phone]')){resetPhone();return;}
    if(e.target.closest('[data-v5-cancel-phone]')){const ed=phoneEditor();if(ed)ed.hidden=true;return;}
    const edit=e.target.closest('[data-v5-phone-edit]');
    if(edit){
      const row=edit.closest('tr[data-phone-json]');let d={};try{d=JSON.parse(row?.dataset.phoneJson||'{}')}catch(_){}
      const ed=phoneEditor(),form=ed&&q('[data-v5-phone-form]',ed);if(!form)return;
      ed.hidden=false;Object.entries(d).forEach(([k,v])=>{
        const el=form.elements[k];if(!el)return;if(el.type==='checkbox')el.checked=!!Number(v);else el.value=v??'';
      });
      ed.scrollIntoView({behavior:'smooth',block:'start'});return;
    }
    const followToggle=e.target.closest('[data-v5-phone-toggle]');
    if(followToggle){
      const row=followToggle.closest('tr[data-phone-json]');if(!row)return;
      let d={};try{d=JSON.parse(row.dataset.phoneJson||'{}')}catch(_){}
      followToggle.disabled=true;
      postData('v5_api.php',{action:'v5_phonebook_toggle',id:row.dataset.id,done:Number(d.followup_done)===1?'0':'1',csrf:window.CSRF||''})
        .then(r=>{if(r.row_html){const next=htmlOne(r.row_html,'tr');row.replaceWith(next);syncInsertedRow(next,next.closest('table'));}toast(r.message||'وضعیت پیگیری تغییر کرد.');})
        .catch(err=>toast(err.message,'error')).finally(()=>{followToggle.disabled=false});
      return;
    }
    const del=e.target.closest('[data-v5-phone-delete]');
    if(del){
      const row=del.closest('tr[data-id]');if(!row||!confirm('این سابقه تماس حذف شود؟'))return;
      postData('v5_api.php',{action:'v5_phonebook_delete',id:row.dataset.id,csrf:window.CSRF||''})
        .then(r=>{row.remove();toast(r.message||'حذف شد.');}).catch(err=>toast(err.message,'error'));return;
    }
    if(e.target.closest('[data-v5-copy-share-code]')){
      const code=q('[data-v5-share-code]')?.textContent?.trim()||'';
      navigator.clipboard?.writeText(code).then(()=>toast('کد Workspace کپی شد.')).catch(()=>{});
      return;
    }
    const revoke=e.target.closest('[data-v5-share-revoke]');
    if(revoke){
      if(!confirm('این اشتراک لغو شود؟'))return;
      postData('v5_api.php',{action:'v5_share_revoke',id:revoke.dataset.id,csrf:window.CSRF||''})
        .then(r=>{toast(r.message||'لغو شد.');setTimeout(()=>location.reload(),350)}).catch(err=>toast(err.message,'error'));
      return;
    }
    const reject=e.target.closest('[data-v5-share-reject]');
    if(reject){
      if(!confirm('دریافت این داده اشتراکی متوقف شود؟'))return;
      postData('v5_api.php',{action:'v5_share_reject',id:reject.dataset.id,csrf:window.CSRF||''})
        .then(r=>{toast(r.message||'دریافت متوقف شد.');setTimeout(()=>location.reload(),350)}).catch(err=>toast(err.message,'error'));
      return;
    }
    const cacheBtn=e.target.closest('[data-v5-cache-action]');
    if(cacheBtn){
      cacheBtn.disabled=true;
      postData('v5_api.php',{action:'v5_cache',mode:cacheBtn.dataset.v5CacheAction,csrf:window.CSRF||''})
        .then(r=>{toast(r.message||'انجام شد.');setTimeout(()=>location.reload(),450)})
        .catch(err=>toast(err.message,'error')).finally(()=>cacheBtn.disabled=false);
    }
  });
  document.addEventListener('submit',async e=>{
    const form=e.target.closest('[data-v5-phone-form]');if(!form)return;
    e.preventDefault();const btn=form.querySelector('button[type="submit"],button:not([type])');if(btn)btn.disabled=true;
    try{
      const r=await postForm('v5_api.php',new FormData(form));const row=htmlOne(r.row_html,'tr');
      const tbody=q('[data-v5-phonebook-rows]');const old=tbody?.querySelector(`tr[data-id="${CSS.escape(String(r.id))}"]`);
      if(old)old.replaceWith(row);else tbody?.prepend(row);
      const table=tbody?.closest('table');syncInsertedRow(row,table);
      form.reset();form.querySelector('[name="id"]').value='';clearAttachmentSelection(form);
      const ed=phoneEditor();if(ed)ed.hidden=true;toast(r.message||'ذخیره شد.');
    }catch(err){toast(err.message,'error')}
    finally{if(btn)btn.disabled=false}
  });
  document.addEventListener('submit',async e=>{
    const form=e.target.closest('[data-v5-share-form]');if(!form)return;
    e.preventDefault();const btn=form.querySelector('button[type="submit"],button:not([type])');if(btn)btn.disabled=true;
    try{const r=await postForm('v5_api.php',new FormData(form));toast(r.message||'اشتراک ایجاد شد.');setTimeout(()=>location.reload(),450)}
    catch(err){toast(err.message,'error')}finally{if(btn)btn.disabled=false}
  });

  // V5 intentionally avoids hover-prefetch on shared hosting to prevent unnecessary PHP/MySQL requests.

  window.AccountingV5={toast,syncInsertedRow};
})();
