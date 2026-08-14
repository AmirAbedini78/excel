/* Accounting CRM V3.2: bulk delete, import/export, calendar quick actions */
(function(){
  if(window.__ACCOUNTING_V32__) return;
  window.__ACCOUNTING_V32__=true;
  const qsa=(s,r=document)=>Array.from(r.querySelectorAll(s));
  const esc=s=>window.CSS&&CSS.escape?CSS.escape(s):String(s).replace(/"/g,'\\"');
  const csrf=()=>window.CSRF||'';
  const postApi=async data=>{
    const r=await fetch('v3_2_api.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},body:new URLSearchParams(Object.assign({csrf:csrf()},data))});
    let j={};try{j=await r.json()}catch(e){throw new Error('پاسخ نامعتبر از سرور');}
    if(!r.ok||j.ok===false)throw new Error(j.error||'خطا در عملیات');
    return j;
  };

  // Daily plan: remove location from the active UI/model surface.
  if(new URLSearchParams(location.search).get('page')==='daily'){
    qsa('input[name="location"]').forEach(i=>i.closest('label')?.remove());
    qsa('.filters label').forEach(l=>{if(l.querySelector('input[name="location"]'))l.remove();});
    qsa('[data-col-key="location"]').forEach(c=>c.remove());
    const sub=document.querySelector('.topbar p');
    if(sub)sub.textContent='تاریخ، روز، شرکت، شرح کار و توضیحات';
  }

  function entityFor(table){
    if(table.dataset.entity)return table.dataset.entity;
    if(table.classList.contains('systems-table'))return 'portal_credentials';
    return '';
  }
  function rowId(row,entity){
    return entity==='portal_credentials' ? row.dataset.companyId : row.dataset.id;
  }
  function selectedIds(table){
    return qsa('tbody .v32-row-select:checked',table).map(x=>+x.value).filter(Boolean);
  }
  function addToolbarActions(table){
    const entity=entityFor(table);if(!entity)return;
    const card=table.closest('.table-card');if(!card)return;
    let toolbar=card.querySelector('.list-toolbar');
    if(!toolbar){toolbar=document.createElement('div');toolbar.className='list-toolbar';card.prepend(toolbar);}
    if(toolbar.querySelector('.v32-io-actions'))return;
    const box=document.createElement('div');box.className='v32-io-actions';
    const count=document.createElement('span');count.className='v32-selected-count';count.textContent='0 انتخاب';
    const bulk=document.createElement('button');bulk.type='button';bulk.className='btn tiny danger-soft';bulk.textContent='حذف انتخاب‌شده';bulk.disabled=true;
    const xlsx=document.createElement('button');xlsx.type='button';xlsx.className='btn tiny';xlsx.textContent='Excel';
    const csv=document.createElement('button');csv.type='button';csv.className='btn tiny';csv.textContent='CSV';
    const imp=document.createElement('button');imp.type='button';imp.className='btn tiny primary';imp.textContent='ورود فایل';
    box.append(count,bulk,xlsx,csv,imp);toolbar.append(box);

    const refresh=()=>{const n=selectedIds(table).length;count.textContent=n+' انتخاب';bulk.disabled=!n;};
    table.addEventListener('change',e=>{if(e.target.matches('.v32-row-select,.v32-select-all'))refresh();});
    const exportGo=format=>{
      const ids=selectedIds(table);
      const u=new URL('io.php',location.href);u.searchParams.set('action','export');u.searchParams.set('entity',entity);u.searchParams.set('format',format);
      if(ids.length)u.searchParams.set('ids',ids.join(','));
      location.href=u.pathname+u.search;
    };
    xlsx.onclick=()=>exportGo('xlsx');csv.onclick=()=>exportGo('csv');
    bulk.onclick=async()=>{
      const ids=selectedIds(table);if(!ids.length)return;
      if(!confirm(`${ids.length} رکورد انتخاب‌شده حذف شود؟`))return;
      bulk.disabled=true;
      try{
        await postApi({action:'bulk_delete',entity,ids:JSON.stringify(ids)});
        ids.forEach(id=>{
          const row=qsa('tbody tr',table).find(r=>+rowId(r,entity)===+id);row?.remove();
        });
        const all=table.querySelector('.v32-select-all');if(all)all.checked=false;refresh();
      }catch(e){alert(e.message);refresh();}
    };
    imp.onclick=()=>openImportModal(entity);
  }

  function enhanceTable(table){
    const entity=entityFor(table);if(!entity||table.dataset.v32Bulk==='1')return;
    table.dataset.v32Bulk='1';
    const hr=table.tHead?.rows?.[table.tHead.rows.length-1];if(!hr)return;
    const th=document.createElement('th');th.className='v32-select-col';th.innerHTML='<input type="checkbox" class="v32-select-all" aria-label="انتخاب همه">';
    hr.insertBefore(th,hr.firstChild);
    qsa('tbody tr',table).forEach(row=>{
      const id=rowId(row,entity);if(!id)return;
      const td=document.createElement('td');td.className='v32-select-col';td.innerHTML=`<input type="checkbox" class="v32-row-select" value="${id}" aria-label="انتخاب ردیف">`;
      row.insertBefore(td,row.firstChild);
    });
    const all=th.querySelector('.v32-select-all');
    all.addEventListener('change',()=>qsa('tbody .v32-row-select',table).forEach(c=>c.checked=all.checked));
    addToolbarActions(table);
  }
  qsa('table.smart-table').forEach(enhanceTable);

  // Import modal.
  let importModal=null;
  function openImportModal(entity){
    importModal?.remove();
    importModal=document.createElement('div');importModal.className='modal-backdrop v32-import-backdrop';
    importModal.innerHTML=`<section class="v32-import-modal" role="dialog" aria-modal="true">
      <div class="modal-head"><div><h2>ورود اطلاعات</h2><p>CSV یا XLSX — داده‌های همسان به‌روزرسانی می‌شوند.</p></div><button type="button" class="btn tiny" data-close>بستن</button></div>
      <div class="v32-import-note">قبل از ورود انبوه، یک خروجی یا قالب نمونه بگیرید. در بخش سامانه‌ها فایل خروجی می‌تواند حاوی رمز عبور باشد؛ آن را امن نگه دارید.</div>
      <form method="post" action="io.php" enctype="multipart/form-data" class="grid-form compact">
        <input type="hidden" name="csrf" value="${String(csrf()).replace(/"/g,'&quot;')}">
        <input type="hidden" name="action" value="import"><input type="hidden" name="entity" value="${entity}">
        <label class="span2">فایل<input type="file" name="file" accept=".csv,.xlsx" required></label>
        <div class="v32-template-links"><a class="btn tiny" href="io.php?action=template&entity=${encodeURIComponent(entity)}&format=xlsx">قالب Excel</a><a class="btn tiny" href="io.php?action=template&entity=${encodeURIComponent(entity)}&format=csv">قالب CSV</a></div>
        <button class="btn primary" type="submit">شروع ورود اطلاعات</button>
      </form></section>`;
    document.body.append(importModal);
    importModal.querySelector('[data-close]').onclick=()=>importModal.remove();
    importModal.addEventListener('click',e=>{if(e.target===importModal)importModal.remove();});
  }

  // Calendar: fetch real records, open each event, quick create.
  const calModal=document.getElementById('calendarModal');
  let activeDay=null;
  async function refreshCalendarModal(day){
    if(!calModal||!day)return;
    activeDay=day;
    const date=day.dataset.calendarDate,jalali=day.dataset.jalali;
    let data={events:[]};
    try{
      const r=await fetch('v3_2_api.php?action=calendar_day&date='+encodeURIComponent(date));
      data=await r.json();if(!r.ok||data.ok===false)throw new Error(data.error||'خطا');
    }catch(e){console.error(e);return;}
    const body=document.getElementById('calendarModalBody');if(!body)return;
    body.innerHTML='';
    if(!data.events.length){
      const empty=document.createElement('div');empty.className='calendar-empty';empty.textContent='برای این روز کاری ثبت نشده است.';body.append(empty);
    }else{
      const bulk=document.createElement('div');bulk.className='v32-calendar-bulk';
      bulk.innerHTML='<span data-count>0 انتخاب</span><button type="button" class="btn tiny danger-soft" disabled>حذف انتخاب‌شده</button>';
      body.append(bulk);
      const refreshBulk=()=>{const n=qsa('.v32-cal-select:checked',body).length;bulk.querySelector('[data-count]').textContent=n+' انتخاب';bulk.querySelector('button').disabled=!n;};
      data.events.forEach(ev=>{
        const card=document.createElement('article');card.className='modal-event v32-modal-event';
        const choose=document.createElement('input');choose.type='checkbox';choose.className='v32-cal-select';choose.dataset.id=String(ev.id||'');choose.dataset.entity=ev.source==='daily'?'daily_plans':'monthly_plans';choose.addEventListener('change',refreshBulk);
        const h=document.createElement('h3');h.textContent=ev.title||'بدون عنوان';
        const meta=document.createElement('div');meta.className='modal-event-meta';meta.textContent=[ev.source_label,ev.company,ev.detail,ev.status].filter(Boolean).join(' • ');
        const actions=document.createElement('div');actions.className='v32-event-actions';
        const open=document.createElement('a');open.className='btn tiny primary';open.href=ev.url;open.textContent='باز کردن';
        actions.append(open);card.append(choose,h,meta);
        if(ev.notes){const p=document.createElement('p');p.textContent=ev.notes;card.append(p);}
        card.append(actions);body.append(card);
      });
      bulk.querySelector('button').onclick=async()=>{
        const selected=qsa('.v32-cal-select:checked',body);if(!selected.length)return;
        if(!confirm(`${selected.length} مورد از این روز حذف شود؟`))return;
        const groups={daily_plans:[],monthly_plans:[]};
        selected.forEach(c=>groups[c.dataset.entity]?.push(+c.dataset.id));
        try{
          for(const [entity,ids] of Object.entries(groups))if(ids.length)await postApi({action:'bulk_delete',entity,ids:JSON.stringify(ids)});
          location.reload();
        }catch(e){alert(e.message);}
      };
    }
    ensureQuickPanel(jalali,date);
  }
  qsa('[data-calendar-date]').forEach(day=>day.addEventListener('click',()=>setTimeout(()=>refreshCalendarModal(day),0)));

  function companyOptionsHtml(){
    const src=document.querySelector('.calendar-filter select[name="company_id"]');if(!src)return '<option value="">بدون شرکت</option>';
    return Array.from(src.options).map(o=>`<option value="${o.value}">${o.value?o.textContent:'بدون شرکت'}</option>`).join('');
  }
  function ensureQuickPanel(jalali,date){
    if(!calModal)return;
    let panel=calModal.querySelector('.v32-quick-panel');
    if(!panel){
      panel=document.createElement('div');panel.className='v32-quick-panel';
      panel.innerHTML=`<div class="v32-quick-buttons"><button type="button" class="btn tiny primary" data-quick="daily">+ برنامه روزانه</button><button type="button" class="btn tiny" data-quick="monthly">+ برنامه ماهانه</button></div>
      <form class="v32-quick-form" data-form="daily" hidden>
        <div class="v32-quick-date"></div><select name="company_id">${companyOptionsHtml()}</select><input name="work_description" placeholder="شرح کار" required><input name="notes" placeholder="توضیحات"><button class="btn primary tiny">ثبت سریع</button>
      </form>
      <form class="v32-quick-form" data-form="monthly" hidden>
        <div class="v32-quick-date"></div><select name="company_id">${companyOptionsHtml()}</select>
        <select name="work_type"><option>بانک‌ها</option><option>حقوق و دستمزد</option><option>بیمه تامین اجتماعی</option><option>مالیات حقوق</option><option>مودیان</option><option>دفاتر الکترونیکی</option><option>اجاره و حق امتیاز</option><option>اظهارنامه عملکرد</option><option>حق الزحمه</option></select>
        <input name="notes" placeholder="توضیحات"><button class="btn primary tiny">ثبت سریع</button>
      </form>`;
      calModal.querySelector('.calendar-modal')?.append(panel);
      qsa('[data-quick]',panel).forEach(b=>b.onclick=()=>{
        qsa('.v32-quick-form',panel).forEach(f=>f.hidden=f.dataset.form!==b.dataset.quick);
      });
      qsa('.v32-quick-form',panel).forEach(form=>form.addEventListener('submit',async e=>{
        e.preventDefault();const fd=new FormData(form);const type=form.dataset.form;
        const data=Object.fromEntries(fd.entries());
        data.action=type==='daily'?'quick_daily':'quick_monthly';
        if(type==='daily')data.date=panel.dataset.gregorian;else data.jalali=panel.dataset.jalali;
        const btn=form.querySelector('button[type="submit"],button');btn.disabled=true;
        try{await postApi(data);location.reload();}catch(err){alert(err.message);btn.disabled=false;}
      }));
    }
    panel.dataset.jalali=jalali;panel.dataset.gregorian=date;
    qsa('.v32-quick-date',panel).forEach(x=>x.textContent='تاریخ: '+jalali);
    qsa('.v32-quick-form',panel).forEach(f=>f.hidden=true);
  }

  // Focus a record opened from calendar.
  const focusId=+(new URLSearchParams(location.search).get('focus_id')||0);
  if(focusId){
    const row=qsa('table tbody tr').find(r=>+(r.dataset.id||0)===focusId);
    if(row){row.classList.add('v32-focus-row');setTimeout(()=>row.scrollIntoView({behavior:'smooth',block:'center'}),150);setTimeout(()=>row.classList.remove('v32-focus-row'),3500);}
  }

  // Kanban multi-delete.
  qsa('[data-kanban-board]').forEach(board=>{
    if(board.dataset.v32Bulk==='1')return;board.dataset.v32Bulk='1';
    const toolbar=document.createElement('div');toolbar.className='v32-kanban-bulk';
    toolbar.innerHTML='<span data-count>0 انتخاب</span><button type="button" class="btn tiny danger-soft" disabled>حذف انتخاب‌شده</button>';
    board.insertBefore(toolbar,board.firstChild);
    const btn=toolbar.querySelector('button'),count=toolbar.querySelector('[data-count]');
    const refresh=()=>{const n=qsa('.v32-kanban-select:checked',board).length;count.textContent=n+' انتخاب';btn.disabled=!n;};
    qsa('.kanban-card',board).forEach(card=>{
      const c=document.createElement('input');c.type='checkbox';c.className='v32-kanban-select';c.value=card.dataset.kanbanId||'';
      c.addEventListener('mousedown',e=>e.stopPropagation());c.addEventListener('dragstart',e=>e.preventDefault());c.addEventListener('change',refresh);
      card.prepend(c);
    });
    btn.onclick=async()=>{
      const ids=qsa('.v32-kanban-select:checked',board).map(c=>+c.value).filter(Boolean);if(!ids.length)return;
      if(!confirm(`${ids.length} کارت حذف شود؟`))return;
      try{await postApi({action:'bulk_delete',entity:'monthly_plans',ids:JSON.stringify(ids)});ids.forEach(id=>board.querySelector(`[data-kanban-id="${id}"]`)?.remove());refresh();}
      catch(e){alert(e.message);}
    };
  });
})();