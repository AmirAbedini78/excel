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


  // Professional smart lists: persistent column order, visibility and width.
  function initSmartTable(table){
    const tableKey=table.dataset.tableKey;
    if(!tableKey) return;
    const headRow=table.tHead?.rows?.[table.tHead.rows.length-1];
    if(!headRow) return;

    const initialHeaders=Array.from(headRow.cells);
    const defaultOrder=initialHeaders.map((th,i)=>{
      const key=th.dataset.colKey||`col_${i}`;
      th.dataset.colKey=key;
      return key;
    });
    Array.from(table.tBodies||[]).forEach(tb=>Array.from(tb.rows).forEach(row=>{
      Array.from(row.cells).forEach((cell,i)=>{ if(defaultOrder[i]) cell.dataset.colKey=defaultOrder[i]; });
    }));

    let loaded={};
    try{ loaded=JSON.parse(table.dataset.tablePrefs||'{}')||{}; }catch(e){}
    let state={
      order:Array.isArray(loaded.order)?loaded.order.slice():[],
      hidden:Array.isArray(loaded.hidden)?loaded.hidden.slice():[],
      widths:(loaded.widths&&typeof loaded.widths==='object')?Object.assign({},loaded.widths):{}
    };
    const allKeys=()=>Array.from(headRow.cells).map(th=>th.dataset.colKey);
    const normalizeOrder=()=>{
      const existing=new Set(allKeys());
      state.order=state.order.filter(k=>existing.has(k));
      defaultOrder.forEach(k=>{if(existing.has(k)&&!state.order.includes(k))state.order.push(k);});
      allKeys().forEach(k=>{if(!state.order.includes(k))state.order.push(k);});
    };

    let saveTimer=null;
    const savePrefs=()=>{
      clearTimeout(saveTimer);
      saveTimer=setTimeout(()=>{
        post({action:'save_table_preferences',table_key:tableKey,prefs:JSON.stringify(state)})
          .then(()=>toolbar?.classList.add('prefs-saved'))
          .then(()=>setTimeout(()=>toolbar?.classList.remove('prefs-saved'),900))
          .catch(e=>console.error('table prefs',e));
      },220);
    };

    const cellsByKey=(key)=>qsa(`[data-col-key="${CSS.escape(key)}"]`,table);
    const syncTableWidth=()=>{
      const visible=Array.from(headRow.cells).filter(th=>!th.classList.contains('col-hidden'));
      let sum=0;
      visible.forEach(th=>{
        const key=th.dataset.colKey;
        const w=Number(state.widths[key])||Math.max(70,Math.round(th.getBoundingClientRect().width||100));
        sum+=w;
      });
      const wrap=table.closest('.table-wrap');
      const min=wrap?.clientWidth||0;
      table.style.width=Math.max(min,sum)+'px';
    };
    const applyWidths=()=>{
      Array.from(headRow.cells).forEach(th=>{
        const key=th.dataset.colKey,w=Number(state.widths[key]||0);
        cellsByKey(key).forEach(cell=>{
          if(w>=56){cell.style.width=w+'px';cell.style.minWidth=w+'px';cell.style.maxWidth=w+'px';}
          else {cell.style.removeProperty('width');cell.style.removeProperty('min-width');cell.style.removeProperty('max-width');}
        });
      });
      requestAnimationFrame(syncTableWidth);
    };
    const applyHidden=()=>{
      const hidden=new Set(state.hidden);
      allKeys().forEach(key=>cellsByKey(key).forEach(cell=>cell.classList.toggle('col-hidden',hidden.has(key))));
      requestAnimationFrame(syncTableWidth);
    };
    const applyOrder=()=>{
      normalizeOrder();
      const maps=[headRow,...Array.from(table.tBodies||[]).flatMap(tb=>Array.from(tb.rows))];
      maps.forEach(row=>{
        const byKey=new Map(Array.from(row.children).map(c=>[c.dataset.colKey,c]));
        state.order.forEach(k=>{const c=byKey.get(k);if(c)row.appendChild(c);});
      });
    };

    applyOrder();
    applyHidden();

    // Use measured defaults only after the persisted order/visibility is applied.
    Array.from(headRow.cells).forEach(th=>{
      const key=th.dataset.colKey;
      if(!state.widths[key]){
        const measured=Math.round(th.getBoundingClientRect().width||0);
        if(measured>=56) th.dataset.defaultWidth=String(measured);
      }
    });
    applyWidths();

    const card=table.closest('.table-card');
    const wrap=table.closest('.table-wrap');
    let toolbar=card?.querySelector(`.list-toolbar[data-for="${CSS.escape(tableKey)}"]`);
    if(!toolbar && card){
      toolbar=document.createElement('div');
      toolbar.className='list-toolbar';
      toolbar.dataset.for=tableKey;
      const gear=document.createElement('button');
      gear.type='button';gear.className='btn icon table-gear';gear.title='تنظیم ستون‌ها';gear.setAttribute('aria-label','تنظیم ستون‌ها');gear.textContent='⚙';
      const hint=document.createElement('span');hint.className='list-toolbar-hint';hint.textContent='ستون‌ها قابل جابه‌جایی و تغییر اندازه‌اند';
      const panel=document.createElement('div');panel.className='table-settings-panel';panel.hidden=true;
      toolbar.append(hint,gear,panel);
      if(wrap) card.insertBefore(toolbar,wrap); else card.prepend(toolbar);
      gear.addEventListener('click',()=>{panel.hidden=!panel.hidden;if(!panel.hidden)renderPanel(panel);});
      document.addEventListener('click',e=>{
        if(!panel.hidden && !toolbar.contains(e.target)) panel.hidden=true;
      });
    }

    function currentLabel(key){
      const th=Array.from(headRow.cells).find(x=>x.dataset.colKey===key);
      return th ? (th.innerText||key).replace(/\s+/g,' ').trim() : key;
    }
    function renderPanel(panel){
      panel.innerHTML='';
      const top=document.createElement('div');top.className='table-settings-head';
      const title=document.createElement('strong');title.textContent='تنظیم ستون‌ها';
      const showAll=document.createElement('button');showAll.type='button';showAll.className='btn tiny';showAll.textContent='نمایش همه';
      const reset=document.createElement('button');reset.type='button';reset.className='btn tiny danger-soft';reset.textContent='بازگشت به پیش‌فرض';
      top.append(title,showAll,reset);panel.append(top);
      const list=document.createElement('div');list.className='column-config-list';panel.append(list);
      state.order.forEach(key=>{
        const item=document.createElement('div');item.className='column-config-item';item.draggable=true;item.dataset.key=key;
        const handle=document.createElement('span');handle.className='drag-handle';handle.textContent='⋮⋮';handle.title='برای جابه‌جایی بکشید';
        const check=document.createElement('input');check.type='checkbox';check.checked=!state.hidden.includes(key);
        const label=document.createElement('span');label.className='column-config-label';label.textContent=currentLabel(key);
        const width=document.createElement('input');width.type='number';width.className='column-width-input';width.min='56';width.max='900';width.step='10';
        width.value=String(Number(state.widths[key])||Math.round(Array.from(headRow.cells).find(x=>x.dataset.colKey===key)?.getBoundingClientRect().width||100));
        width.title='عرض ستون (پیکسل)';
        check.addEventListener('change',()=>{
          state.hidden=check.checked?state.hidden.filter(x=>x!==key):Array.from(new Set([...state.hidden,key]));
          applyHidden();savePrefs();
        });
        width.addEventListener('change',()=>{
          const v=Math.max(56,Math.min(900,Number(width.value)||100));state.widths[key]=v;applyWidths();savePrefs();
        });
        item.addEventListener('dragstart',e=>{e.dataTransfer.setData('text/column-key',key);item.classList.add('dragging');});
        item.addEventListener('dragend',()=>item.classList.remove('dragging'));
        item.addEventListener('dragover',e=>{e.preventDefault();item.classList.add('drag-over-item');});
        item.addEventListener('dragleave',()=>item.classList.remove('drag-over-item'));
        item.addEventListener('drop',e=>{
          e.preventDefault();item.classList.remove('drag-over-item');
          const source=e.dataTransfer.getData('text/column-key');if(!source||source===key)return;
          const a=state.order.indexOf(source),b=state.order.indexOf(key);if(a<0||b<0)return;
          state.order.splice(a,1);state.order.splice(b,0,source);applyOrder();applyHidden();applyWidths();savePrefs();renderPanel(panel);
        });
        item.append(handle,check,label,width);list.append(item);
      });
      showAll.addEventListener('click',()=>{state.hidden=[];applyHidden();savePrefs();renderPanel(panel);});
      reset.addEventListener('click',async()=>{
        if(!confirm('چیدمان، نمایش و عرض ستون‌ها به حالت پیش‌فرض برگردد؟'))return;
        try{await post({action:'reset_table_preferences',table_key:tableKey});location.reload();}
        catch(e){alert(e.message);}
      });
    }

    // Header drag/drop reordering.
    Array.from(headRow.cells).forEach(th=>{
      th.draggable=true;th.classList.add('draggable-col');
      th.addEventListener('dragstart',e=>{
        if(e.target.closest('.col-resizer')){e.preventDefault();return;}
        e.dataTransfer.effectAllowed='move';
        e.dataTransfer.setData('text/table-col',th.dataset.colKey);
        th.classList.add('column-dragging');
      });
      th.addEventListener('dragend',()=>{qsa('.column-dragging,.column-drag-over',headRow).forEach(x=>x.classList.remove('column-dragging','column-drag-over'));});
      th.addEventListener('dragover',e=>{e.preventDefault();th.classList.add('column-drag-over');});
      th.addEventListener('dragleave',()=>th.classList.remove('column-drag-over'));
      th.addEventListener('drop',e=>{
        e.preventDefault();th.classList.remove('column-drag-over');
        const source=e.dataTransfer.getData('text/table-col'),target=th.dataset.colKey;
        if(!source||source===target)return;
        const order=Array.from(headRow.cells).map(x=>x.dataset.colKey);
        const from=order.indexOf(source),to=order.indexOf(target);if(from<0||to<0)return;
        order.splice(from,1);
        const r=th.getBoundingClientRect(),rtl=getComputedStyle(table).direction==='rtl';
        const before=rtl ? e.clientX>r.left+r.width/2 : e.clientX<r.left+r.width/2;
        let insertAt=order.indexOf(target)+(before?0:1);
        order.splice(insertAt,0,source);
        state.order=order;applyOrder();applyHidden();applyWidths();savePrefs();
        if(toolbar?.querySelector('.table-settings-panel:not([hidden])'))renderPanel(toolbar.querySelector('.table-settings-panel'));
      });

      const resizer=document.createElement('span');resizer.className='col-resizer';resizer.title='تغییر عرض ستون';
      th.appendChild(resizer);
      resizer.addEventListener('pointerdown',e=>{
        e.preventDefault();e.stopPropagation();resizer.setPointerCapture?.(e.pointerId);
        const key=th.dataset.colKey,startX=e.clientX,startW=th.getBoundingClientRect().width;
        table.classList.add('column-resizing');
        const move=ev=>{
          const rtl=getComputedStyle(table).direction==='rtl';
          const delta=rtl?(startX-ev.clientX):(ev.clientX-startX);
          const w=Math.max(56,Math.min(900,Math.round(startW+delta)));
          state.widths[key]=w;applyWidths();
        };
        const up=()=>{
          document.removeEventListener('pointermove',move);document.removeEventListener('pointerup',up);
          table.classList.remove('column-resizing');savePrefs();
        };
        document.addEventListener('pointermove',move);document.addEventListener('pointerup',up,{once:true});
      });
    });
  }
  qsa('table.smart-table[data-table-key]').forEach(initSmartTable);

  // Kanban drag & drop with database persistence.
  qsa('[data-kanban-board]').forEach(board=>{
    let dragged=null;
    const updateCount=()=>{
      qsa('.kanban-col',board).forEach(col=>{
        const count=col.querySelector('[data-kanban-count]');
        if(count)count.textContent=String(qsa('.kanban-card',col).length);
      });
    };
    qsa('.kanban-card',board).forEach(card=>{
      card.addEventListener('dragstart',e=>{
        dragged=card;card.classList.add('dragging');
        e.dataTransfer.effectAllowed='move';
        e.dataTransfer.setData('text/kanban-id',card.dataset.kanbanId||'');
      });
      card.addEventListener('dragend',()=>{
        card.classList.remove('dragging');dragged=null;
        qsa('.kanban-col.drag-over',board).forEach(c=>c.classList.remove('drag-over'));
      });
    });
    qsa('.kanban-col',board).forEach(col=>{
      col.addEventListener('dragenter',e=>{if(dragged){e.preventDefault();col.classList.add('drag-over');}});
      col.addEventListener('dragover',e=>{if(dragged){e.preventDefault();e.dataTransfer.dropEffect='move';col.classList.add('drag-over');}});
      col.addEventListener('dragleave',e=>{if(!col.contains(e.relatedTarget))col.classList.remove('drag-over');});
      col.addEventListener('drop',async e=>{
        e.preventDefault();col.classList.remove('drag-over');
        if(!dragged)return;
        const id=dragged.dataset.kanbanId,status=col.dataset.kanbanStatus;
        const oldCol=dragged.closest('.kanban-col');
        if(!id||!status||oldCol===col)return;
        dragged.classList.add('saving');
        try{
          await post({action:'inline_update',entity:'monthly_plans',id,field:'status',value:status});
          col.querySelector('.kanban-dropzone')?.appendChild(dragged);
          const open=dragged.querySelector('.kanban-open');
          if(open){
            const u=new URL(open.href,location.href);u.searchParams.set('status',status);open.href=u.pathname+u.search;
          }
          updateCount();dragged.classList.remove('saving');dragged.classList.add('saved');
          setTimeout(()=>dragged?.classList.remove('saved'),900);
        }catch(err){dragged.classList.remove('saving');alert(err.message);}
      });
    });
  });

})();


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

/* Accounting CRM V3.3 responsive UX + API manager */
(function(){
  if(window.__ACCOUNTING_V33__)return;
  window.__ACCOUNTING_V33__=true;
  const qsa=(s,r=document)=>Array.from(r.querySelectorAll(s));

  /* Mobile/off-canvas sidebar */
  const sidebar=document.querySelector('.sidebar');
  const topbar=document.querySelector('.topbar');
  if(sidebar&&topbar){
    const toggle=document.createElement('button');
    toggle.type='button';toggle.className='mobile-menu-toggle';toggle.setAttribute('aria-label','باز کردن منو');toggle.setAttribute('aria-expanded','false');
    toggle.innerHTML='<span></span><span></span><span></span>';
    topbar.prepend(toggle);
    const overlay=document.createElement('button');
    overlay.type='button';overlay.className='sidebar-overlay';overlay.setAttribute('aria-label','بستن منو');
    document.body.append(overlay);
    const setOpen=open=>{
      sidebar.classList.toggle('is-open',open);overlay.classList.toggle('is-open',open);document.body.classList.toggle('sidebar-open',open);
      toggle.setAttribute('aria-expanded',open?'true':'false');
    };
    toggle.addEventListener('click',()=>setOpen(!sidebar.classList.contains('is-open')));
    overlay.addEventListener('click',()=>setOpen(false));
    qsa('a',sidebar).forEach(a=>a.addEventListener('click',()=>{if(innerWidth<=900)setOpen(false)}));
    document.addEventListener('keydown',e=>{if(e.key==='Escape')setOpen(false)});
    addEventListener('resize',()=>{if(innerWidth>900)setOpen(false)});
  }

  /* Smart-list settings panel: mark gear and add mobile backdrop */
  let settingsBackdrop=null;
  function closeSettingsPanel(){
    qsa('.table-settings-panel').forEach(p=>p.hidden=true);
    settingsBackdrop?.classList.remove('is-open');
    document.body.classList.remove('table-settings-open');
  }
  function ensureSettingsBackdrop(){
    if(settingsBackdrop)return settingsBackdrop;
    settingsBackdrop=document.createElement('button');settingsBackdrop.type='button';settingsBackdrop.className='table-settings-backdrop';settingsBackdrop.setAttribute('aria-label','بستن تنظیم ستون‌ها');
    settingsBackdrop.addEventListener('click',closeSettingsPanel);document.body.append(settingsBackdrop);return settingsBackdrop;
  }
  const markPanels=()=>{
    qsa('.table-settings-panel').forEach(panel=>{
      const toolbar=panel.closest('.list-toolbar');if(!toolbar)return;
      qsa('button',toolbar).forEach(b=>{if((b.textContent||'').includes('⚙'))b.classList.add('v33-gear-button')});
    });
  };
  markPanels();
  new MutationObserver(markPanels).observe(document.body,{childList:true,subtree:true});
  document.addEventListener('click',e=>{
    const button=e.target.closest('.v33-gear-button');
    if(!button)return;
    setTimeout(()=>{
      const panel=button.closest('.list-toolbar')?.querySelector('.table-settings-panel');
      if(panel&&!panel.hidden&&innerWidth<=760){
        ensureSettingsBackdrop().classList.add('is-open');document.body.classList.add('table-settings-open');
      }else{
        settingsBackdrop?.classList.remove('is-open');document.body.classList.remove('table-settings-open');
      }
    },20);
  },true);

  /* Calendar modal: accessible responsive dialog / bottom sheet */
  const cal=document.getElementById('calendarModal');
  if(cal){
    cal.classList.add('v33-calendar-backdrop');
    const dialog=cal.querySelector('.calendar-modal');dialog?.classList.add('v33-calendar-modal');
    let previousFocus=null;
    const sync=()=>{
      const open=!cal.hidden;
      document.body.classList.toggle('calendar-modal-open',open);
      if(open){
        previousFocus=document.activeElement;
        setTimeout(()=>cal.querySelector('[data-close-calendar],button,a,input,select')?.focus(),10);
      }else if(previousFocus&&previousFocus.focus){try{previousFocus.focus()}catch(e){}}
    };
    new MutationObserver(sync).observe(cal,{attributes:true,attributeFilter:['hidden']});sync();
    cal.addEventListener('keydown',e=>{
      if(e.key!=='Tab'||cal.hidden)return;
      const items=qsa('button:not([disabled]),a[href],input:not([disabled]),select:not([disabled]),textarea:not([disabled])',cal).filter(x=>x.offsetParent!==null);
      if(items.length<2)return;
      const first=items[0],last=items[items.length-1];
      if(e.shiftKey&&document.activeElement===first){e.preventDefault();last.focus();}
      else if(!e.shiftKey&&document.activeElement===last){e.preventDefault();first.focus();}
    });
  }

  /* API management card in Settings */
  const page=new URLSearchParams(location.search).get('page');
  if(page==='settings'){
    const main=document.querySelector('.main');
    const card=document.createElement('section');card.className='card api-manager-card';
    card.innerHTML='<div class="section-title"><div><h2>API و اتصال سرویس‌ها</h2><div class="muted">Bearer Token، CORS و محدودیت درخواست برای Hermes / RAG / Agent</div></div><a class="btn tiny" href="api_docs.php">مستندات API</a></div><div class="api-manager-loading">در حال بارگذاری...</div>';
    main?.append(card);
    const apiPost=async data=>{
      const r=await fetch('api_token_admin.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},body:new URLSearchParams(Object.assign({csrf:window.CSRF||''},data))});
      const j=await r.json();if(!r.ok||j.ok===false)throw new Error(j.error||'خطا');return j;
    };
    const esc=s=>String(s??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    const load=async()=>{
      const r=await fetch('api_token_admin.php?action=list');const j=await r.json();
      if(!r.ok||j.ok===false){card.innerHTML='<div class="muted">مدیریت API فقط برای کاربر مدیر در دسترس است.</div>';return;}
      render(j);
    };
    function render(j){
      const s=j.settings||{},tokens=j.tokens||[];
      card.innerHTML=`<div class="section-title"><div><h2>API و اتصال سرویس‌ها</h2><div class="muted">توکن‌ها در دیتابیس Hash می‌شوند و متن کامل فقط هنگام ساخت نمایش داده می‌شود.</div></div><div class="api-doc-actions"><a class="btn tiny" href="api_docs.php">راهنمای API</a><a class="btn tiny" href="openapi.json" target="_blank">OpenAPI</a></div></div>
      <div class="api-settings-grid">
        <label class="check api-switch"><input type="checkbox" data-api-enabled ${s.enabled==='1'?'checked':''}> API فعال باشد</label>
        <label>Rate limit / دقیقه<input type="number" min="10" max="5000" data-api-rate value="${esc(s.rate_limit||120)}"></label>
        <label class="api-cors-field">CORS Origins<textarea data-api-cors placeholder="https://agent.example.com&#10;https://hermes.example.com">${esc(s.cors_origins||'')}</textarea></label>
        <button type="button" class="btn primary tiny" data-save-api-settings>ذخیره تنظیمات API</button>
      </div>
      <div class="api-base"><span>Base URL</span><code>${esc(s.base_url||'')}</code><button type="button" class="btn tiny" data-copy-base>کپی</button></div>
      <div class="api-token-create">
        <input data-token-name placeholder="نام توکن؛ مثلا Hermes Production">
        <select data-token-preset><option value="read">فقط خواندن</option><option value="readwrite" selected>خواندن + نوشتن</option><option value="full">کامل + secrets + settings</option></select>
        <input type="number" data-token-days min="0" max="3650" value="365" title="روز اعتبار؛ صفر یعنی بدون انقضا">
        <button type="button" class="btn primary" data-create-token>ساخت توکن</button>
      </div>
      <div class="api-new-token" hidden></div>
      <div class="table-wrap"><table class="api-token-table"><thead><tr><th>نام</th><th>Prefix</th><th>Scope</th><th>آخرین استفاده</th><th>انقضا</th><th>وضعیت</th><th>عملیات</th></tr></thead><tbody>
      ${tokens.map(t=>`<tr><td>${esc(t.name)}</td><td dir="ltr">${esc(t.token_prefix)}...</td><td>${(t.scopes||[]).map(x=>`<span class="api-scope">${esc(x)}</span>`).join(' ')}</td><td>${esc(t.last_used_at||'—')}</td><td>${esc(t.expires_at||'بدون انقضا')}</td><td>${t.revoked_at?'<span class="api-revoked">لغوشده</span>':'فعال'}</td><td>${t.revoked_at?'—':`<button class="btn tiny danger-soft" data-revoke-token="${t.id}">لغو</button>`}</td></tr>`).join('')}
      </tbody></table></div>`;

      card.querySelector('[data-copy-base]')?.addEventListener('click',()=>navigator.clipboard?.writeText(s.base_url||''));
      card.querySelector('[data-save-api-settings]')?.addEventListener('click',async b=>{
        b.disabled=true;try{await apiPost({action:'save_settings',enabled:card.querySelector('[data-api-enabled]').checked?'1':'0',rate_limit:card.querySelector('[data-api-rate]').value,cors_origins:card.querySelector('[data-api-cors]').value});b.textContent='ذخیره شد';setTimeout(()=>b.textContent='ذخیره تنظیمات API',1100);}catch(e){alert(e.message)}finally{b.disabled=false}
      });
      card.querySelector('[data-create-token]')?.addEventListener('click',async b=>{
        const name=card.querySelector('[data-token-name]').value.trim();if(!name){alert('نام توکن را وارد کنید.');return;}
        b.disabled=true;try{
          const out=await apiPost({action:'create',name,preset:card.querySelector('[data-token-preset]').value,expires_days:card.querySelector('[data-token-days]').value});
          await load();
          const n=card.querySelector('.api-new-token');n.hidden=false;n.innerHTML=`<b>توکن جدید — فقط همین یک بار نمایش داده می‌شود:</b><div><code>${esc(out.token.token)}</code><button type="button" class="btn tiny" data-copy-token>کپی</button></div><small>بعد از کپی، این مقدار را در Secret Manager سرویس مقصد ذخیره کنید.</small>`;
          n.querySelector('[data-copy-token]').onclick=()=>navigator.clipboard?.writeText(out.token.token);
        }catch(e){alert(e.message)}finally{b.disabled=false}
      });
      qsa('[data-revoke-token]',card).forEach(b=>b.addEventListener('click',async()=>{
        if(!confirm('این توکن فوراً لغو شود؟'))return;b.disabled=true;try{await apiPost({action:'revoke',id:b.dataset.revokeToken});await load()}catch(e){alert(e.message);b.disabled=false}
      }));
    }
    load().catch(()=>{card.innerHTML='<div class="muted">خطا در بارگذاری مدیریت API.</div>'});
  }
})();