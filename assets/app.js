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
