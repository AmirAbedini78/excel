(function(){
  const qsa=(s,r=document)=>Array.from(r.querySelectorAll(s));
  const post=(data)=>fetch('index.php',{
    method:'POST',
    headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},
    body:new URLSearchParams(Object.assign({csrf:window.CSRF||''},data))
  }).then(async r=>{
    let j={};
    try{j=await r.json()}catch(e){throw new Error('پاسخ نامعتبر از سرور');}
    if(!r.ok || j.ok===false) throw new Error(j.error||'خطا در ذخیره');
    return j;
  });

  function rowState(row,cls){
    row.classList.remove('saving','saved','error-save');
    if(cls) row.classList.add(cls);
    if(cls==='saved') setTimeout(()=>row.classList.remove('saved'),1400);
  }
  function fieldValue(el){
    if(el.tagName==='SELECT' || el.tagName==='INPUT' || el.tagName==='TEXTAREA') return el.value;
    return el.innerText.trim();
  }
  function setEditing(row,on){
    row.classList.toggle('row-editing',on);
    qsa('.editable-cell[data-field]',row).forEach(el=>{
      if(on){ el.setAttribute('contenteditable','true'); el.dataset.old=el.innerText; }
      else el.removeAttribute('contenteditable');
    });
    qsa('.row-edit-control[data-field]',row).forEach(el=>{
      if(on){ el.disabled=false; el.dataset.old=el.value; }
      else el.disabled=true;
    });
  }
  async function saveRow(row,btn){
    const table=row.closest('.data-table');
    if(!table) return;
    const entity=table.dataset.entity,id=row.dataset.id;
    const changed=qsa('[data-field]',row).filter(el=>{
      if(!el.closest('td')) return false;
      const old=el.dataset.old;
      return typeof old!=='undefined' && fieldValue(el)!==old;
    });
    if(!changed.length){setEditing(row,false);btn.textContent='ویرایش';return;}
    rowState(row,'saving'); btn.disabled=true;
    try{
      for(const el of changed){
        await post({action:'inline_update',entity,id,field:el.dataset.field,value:fieldValue(el)});
        el.dataset.old=fieldValue(el);
      }
      setEditing(row,false); btn.textContent='ویرایش'; rowState(row,'saved');
    }catch(e){rowState(row,'error-save');alert(e.message);}
    finally{btn.disabled=false;}
  }

  qsa('[data-edit-row]').forEach(btn=>btn.addEventListener('click',()=>{
    const row=btn.closest('tr'); if(!row)return;
    if(!row.classList.contains('row-editing')){
      setEditing(row,true); btn.textContent='ذخیره';
      const first=row.querySelector('.editable-cell,[data-field]:not([disabled])');
      if(first && first.focus) first.focus();
    }else saveRow(row,btn);
  }));

  qsa('[data-delete]').forEach(btn=>btn.addEventListener('click',async()=>{
    if(!confirm('این رکورد حذف شود؟'))return;
    try{
      await post({action:'delete_record',entity:btn.dataset.entity,id:btn.dataset.id});
      btn.closest('tr')?.remove();
    }catch(e){alert(e.message);}
  }));

  // Systems matrix: edit/save per company row.
  qsa('[data-edit-system]').forEach(btn=>btn.addEventListener('click',async()=>{
    const row=btn.closest('[data-system-row]'); if(!row)return;
    const editing=row.classList.contains('row-editing');
    if(!editing){
      row.classList.add('row-editing');
      qsa('.system-input',row).forEach(i=>{i.disabled=false;i.dataset.old=i.value;});
      btn.textContent='ذخیره';
      row.querySelector('.system-input')?.focus();
      return;
    }
    const credentials={};
    qsa('.system-input',row).forEach(i=>{
      const p=i.dataset.portal,k=i.dataset.kind;
      credentials[p]=credentials[p]||{};
      credentials[p][k]=i.value;
    });
    rowState(row,'saving');btn.disabled=true;
    try{
      await post({action:'save_system_credentials',company_id:row.dataset.companyId,credentials:JSON.stringify(credentials)});
      qsa('.system-input',row).forEach(i=>{i.disabled=true;i.dataset.old=i.value;});
      row.classList.remove('row-editing');btn.textContent='ویرایش';rowState(row,'saved');
    }catch(e){rowState(row,'error-save');alert(e.message);}
    finally{btn.disabled=false;}
  }));
  qsa('[data-delete-system]').forEach(btn=>btn.addEventListener('click',async()=>{
    const row=btn.closest('[data-system-row]'); if(!row)return;
    if(!confirm('تمام نام‌های کاربری و رمزهای این شرکت از بخش سامانه‌ها حذف شود؟'))return;
    try{
      await post({action:'delete_system_credentials',company_id:row.dataset.companyId});
      qsa('.system-input',row).forEach(i=>{i.value='';i.disabled=true;});
      row.classList.remove('row-editing'); rowState(row,'saved');
    }catch(e){alert(e.message);}
  }));
  qsa('[data-toggle-password]').forEach(btn=>btn.addEventListener('click',()=>{
    const input=btn.closest('.password-wrap')?.querySelector('input');
    if(input) input.type=input.type==='password'?'text':'password';
  }));

  // Autosave unfinished forms in localStorage.
  qsa('form.autosave').forEach(form=>{
    const key='acct_autosave_'+(form.dataset.formKey||location.pathname+location.search);
    try{
      const saved=JSON.parse(localStorage.getItem(key)||'{}');
      Object.entries(saved).forEach(([n,v])=>{
        const el=form.elements[n];
        if(!el || el.type==='password' || el.type==='hidden') return;
        if(el.type==='checkbox') el.checked=!!v;
        else if(!el.value) el.value=v;
      });
      if(Object.keys(saved).length){const dot=document.createElement('span');dot.className='unsaved-dot';form.prepend(dot);}
    }catch(e){}
    const save=()=>{
      const data={};
      qsa('input,select,textarea',form).forEach(el=>{
        if(!el.name||el.type==='password'||el.name==='csrf'||el.name==='action'||el.type==='hidden')return;
        data[el.name]=el.type==='checkbox'?el.checked:el.value;
      });
      localStorage.setItem(key,JSON.stringify(data));
    };
    form.addEventListener('input',save); form.addEventListener('change',save);
    form.addEventListener('submit',()=>localStorage.removeItem(key));
  });

  // Lightweight Jalali date picker.
  let picker=null;
  function closePicker(){if(picker){picker.remove();picker=null;}}
  function openPicker(input){
    if(input.disabled) return;
    closePicker();
    const base=(input.value||window.JALALI_TODAY||'1405/01/01').match(/(\d{4})\D(\d{1,2})\D(\d{1,2})/);
    let y=base?+base[1]:1405,m=base?+base[2]:1,d=base?+base[3]:1;
    picker=document.createElement('div');picker.className='jalali-picker';
    const ys=document.createElement('select');
    for(let i=y-2;i<=y+3;i++)ys.add(new Option(i,i,false,i===y));
    const ms=document.createElement('select');
    ['فروردین','اردیبهشت','خرداد','تیر','مرداد','شهریور','مهر','آبان','آذر','دی','بهمن','اسفند'].forEach((n,i)=>ms.add(new Option(n,i+1,false,i+1===m)));
    const ds=document.createElement('select');
    const fillDays=()=>{const mm=+ms.value,max=mm<=6?31:(mm<=11?30:30);ds.innerHTML='';for(let i=1;i<=max;i++)ds.add(new Option(i,i,false,i===Math.min(d,max)));};
    fillDays();ms.onchange=fillDays;
    const ok=document.createElement('button');ok.className='btn primary tiny';ok.type='button';ok.textContent='انتخاب';
    ok.onclick=()=>{input.value=`${ys.value}/${String(ms.value).padStart(2,'0')}/${String(ds.value).padStart(2,'0')}`;input.dispatchEvent(new Event('input',{bubbles:true}));closePicker();};
    picker.append(ys,ms,ds,ok);document.body.append(picker);
    const r=input.getBoundingClientRect(), pw=picker.offsetWidth||280;
    picker.style.top=(scrollY+r.bottom+4)+'px';
    picker.style.left=Math.max(8,Math.min(scrollX+r.left,scrollX+innerWidth-pw-8))+'px';
  }
  qsa('input.jalali-date').forEach(i=>i.addEventListener('focus',()=>openPicker(i)));
  document.addEventListener('click',e=>{if(picker&&!picker.contains(e.target)&&!e.target.classList.contains('jalali-date'))closePicker();});

  // Calendar day modal.
  const modal=document.getElementById('calendarModal');
  const eventScript=document.getElementById('calendarEvents');
  let calendarEvents={};
  if(eventScript){try{calendarEvents=JSON.parse(eventScript.textContent||'{}')}catch(e){}}
  function closeCalendar(){if(modal)modal.hidden=true;}
  qsa('[data-calendar-date]').forEach(day=>day.addEventListener('click',()=>{
    if(!modal)return;
    const key=day.dataset.calendarDate, events=calendarEvents[key]||[];
    const title=document.getElementById('calendarModalTitle');
    const sub=document.getElementById('calendarModalSubtitle');
    const body=document.getElementById('calendarModalBody');
    title.textContent='کارهای '+(day.dataset.jalali||'');
    sub.textContent=events.length?`${events.length} مورد ثبت شده`:'بدون کار ثبت‌شده';
    body.innerHTML='';
    if(!events.length){
      const empty=document.createElement('div');empty.className='calendar-empty';empty.textContent='برای این روز کاری در برنامه روزانه یا سررسید برنامه ماهانه ثبت نشده است.';body.append(empty);
    }else{
      events.forEach(ev=>{
        const card=document.createElement('article');card.className='modal-event';
        const h=document.createElement('h3');h.textContent=ev.title||'بدون عنوان';
        const meta=document.createElement('div');meta.className='modal-event-meta';
        const source=ev.source==='daily'?'برنامه روزانه':'برنامه ماهانه';
        meta.textContent=[source,ev.company,ev.detail,ev.status].filter(Boolean).join(' • ');
        card.append(h,meta);
        if(ev.notes){const p=document.createElement('p');p.textContent=ev.notes;card.append(p);}
        body.append(card);
      });
    }
    modal.hidden=false;
  }));
  qsa('[data-close-calendar]').forEach(b=>b.addEventListener('click',closeCalendar));
  modal?.addEventListener('click',e=>{if(e.target===modal)closeCalendar();});
  document.addEventListener('keydown',e=>{if(e.key==='Escape')closeCalendar();});
})();
