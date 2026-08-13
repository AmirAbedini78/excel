(function(){
  const qs=(s,r=document)=>Array.from(r.querySelectorAll(s));
  const post=(data)=>fetch('index.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams(Object.assign({csrf:window.CSRF||''},data))}).then(r=>r.json());
  function mark(el, cls){const tr=el.closest('tr'); if(!tr)return; tr.classList.remove('saving','saved','error-save'); tr.classList.add(cls); setTimeout(()=>tr.classList.remove(cls),1600)}
  function saveCell(el){const tr=el.closest('tr'), table=el.closest('.data-table'); if(!tr||!table)return; const entity=table.dataset.entity, id=tr.dataset.id, field=el.dataset.field; const value=el.tagName==='SELECT'?el.value:el.innerText.trim(); mark(el,'saving'); post({action:'inline_update',entity,id,field,value}).then(j=>{ if(!j.ok)throw new Error(j.error||'خطا'); mark(el,'saved')}).catch(e=>{alert(e.message);mark(el,'error-save')})}
  qs('[contenteditable][data-field]').forEach(el=>{el.dataset.old=el.innerText; el.addEventListener('blur',()=>{if(el.innerText!==el.dataset.old){el.dataset.old=el.innerText; saveCell(el)}}); el.addEventListener('keydown',e=>{if(e.key==='Enter'){e.preventDefault();el.blur()}})});
  qs('select.inline-select[data-field]').forEach(el=>el.addEventListener('change',()=>saveCell(el)));
  qs('[data-delete]').forEach(btn=>btn.addEventListener('click',()=>{ if(!confirm('این رکورد حذف شود؟'))return; post({action:'delete_record',entity:btn.dataset.entity,id:btn.dataset.id}).then(j=>{if(!j.ok)throw new Error(j.error||'خطا'); btn.closest('tr')?.remove();}).catch(e=>alert(e.message)); }));

  // Autosave forms in browser storage, so unfinished forms survive tab close.
  qs('form.autosave').forEach(form=>{
    const key='acct_autosave_'+(form.dataset.formKey||location.pathname+location.search);
    try{const saved=JSON.parse(localStorage.getItem(key)||'{}'); Object.entries(saved).forEach(([n,v])=>{const el=form.elements[n]; if(el && !el.value && el.type!=='password') el.value=v;}); if(Object.keys(saved).length){const dot=document.createElement('span');dot.className='unsaved-dot';form.prepend(dot)}}catch(e){}
    const save=()=>{const data={}; qs('input,select,textarea',form).forEach(el=>{if(!el.name||el.type==='password'||el.name==='csrf'||el.name==='action')return; data[el.name]=el.value}); localStorage.setItem(key,JSON.stringify(data));};
    form.addEventListener('input',save); form.addEventListener('change',save); form.addEventListener('submit',()=>localStorage.removeItem(key));
  });

  // Lightweight Jalali picker. It writes YYYY/MM/DD; PHP converts it to SQL date.
  let picker=null;
  function closePicker(){ if(picker){picker.remove();picker=null} }
  function openPicker(input){ closePicker(); const now=(input.value||'1405/01/01').match(/(\d{4})\D(\d{1,2})\D(\d{1,2})/); let y=now?+now[1]:1405,m=now?+now[2]:1,d=now?+now[3]:1; picker=document.createElement('div'); picker.className='jalali-picker';
    const ys=document.createElement('select'); for(let i=1404;i<=1408;i++){ys.add(new Option(i,i,false,i===y));}
    const ms=document.createElement('select'); ['فروردین','اردیبهشت','خرداد','تیر','مرداد','شهریور','مهر','آبان','آذر','دی','بهمن','اسفند'].forEach((n,i)=>ms.add(new Option(n,i+1,false,i+1===m)));
    const ds=document.createElement('select'); const fillDays=()=>{const mm=+ms.value, max=mm<=6?31:(mm<=11?30:29); ds.innerHTML=''; for(let i=1;i<=max;i++)ds.add(new Option(i,i,false,i===d));}; fillDays(); ms.onchange=fillDays;
    const ok=document.createElement('button'); ok.className='btn primary tiny'; ok.type='button'; ok.textContent='انتخاب'; ok.onclick=()=>{input.value=`${ys.value}/${String(ms.value).padStart(2,'0')}/${String(ds.value).padStart(2,'0')}`; input.dispatchEvent(new Event('input',{bubbles:true})); closePicker();};
    picker.append(ys,ms,ds,ok); document.body.append(picker); const r=input.getBoundingClientRect(); picker.style.top=(scrollY+r.bottom+4)+'px'; picker.style.left=(scrollX+r.left)+'px';
  }
  qs('input.jalali-date').forEach(i=>{i.addEventListener('focus',()=>openPicker(i));}); document.addEventListener('click',e=>{if(picker && !picker.contains(e.target) && !e.target.classList.contains('jalali-date')) closePicker();});
})();
