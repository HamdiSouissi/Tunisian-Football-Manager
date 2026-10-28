document.addEventListener('DOMContentLoaded', () => {
    const root=document.querySelector('#match-root'); if(!root)return;
    const stepButton=document.querySelector('#match-step'),autoButton=document.querySelector('#match-auto');
    let timer=null,busy=false;
    const token=document.querySelector('meta[name="csrf-token"]')?.content;
    const post=async(url,body={})=>{const response=await fetch(url,{method:'POST',headers:{'Content-Type':'application/json','Accept':'application/json','X-CSRF-Token':token},body:JSON.stringify(body)});const data=await response.json();if(!response.ok)throw new Error(data.message||'Erreur du match');return data;};
    const addEvents=events=>{const list=document.querySelector('#match-events');events.forEach(event=>{const row=document.createElement('div');row.className=`commentary__item commentary__item--${event.type}`;const minute=document.createElement('span');minute.textContent=`${event.minute}′`;const text=document.createElement('p');text.textContent=event.description;row.append(minute,text);list.appendChild(row);});list.scrollTop=list.scrollHeight;};
    const update=data=>{
        document.querySelector('#home-score').textContent=data.home_score;document.querySelector('#away-score').textContent=data.away_score;
        document.querySelector('#match-minute').textContent=data.status==='finished'?'Terminé':`${data.minute}′`;
        document.querySelector('#match-status-badge').textContent=data.status;
        const h=data.stats.home,a=data.stats.away,total=Math.max(1,h.possession+a.possession);
        document.querySelector('#home-possession').textContent=`${Math.round(h.possession*100/total)}%`;document.querySelector('#away-possession').textContent=`${Math.round(a.possession*100/total)}%`;
        ['shots','on_target','fouls','yellow'].forEach(key=>{document.querySelector(`#home-${key.replace('_','-')}`).textContent=h[key];document.querySelector(`#away-${key.replace('_','-')}`).textContent=a[key];});
        addEvents(data.events||[]);
        if(data.status==='halftime'){stop();stepButton.disabled=true;autoButton.disabled=true;document.querySelector('#resume-form')?.classList.remove('is-hidden');}
        if(data.status==='finished'){stop();stepButton.disabled=true;autoButton.disabled=true;document.querySelector('#match-minute').textContent='Terminé';}
    };
    const step=async()=>{if(busy)return;busy=true;try{update(await post(root.dataset.stepUrl));}catch(error){alert(error.message);stop();}finally{busy=false;}};
    const stop=()=>{if(timer)clearInterval(timer);timer=null;if(autoButton)autoButton.textContent='▶ Lecture auto';};
    stepButton?.addEventListener('click',step);
    autoButton?.addEventListener('click',()=>{if(timer){stop();}else{autoButton.textContent='⏸ Pause';step();timer=setInterval(step,900);}});
    document.querySelector('#resume-form')?.addEventListener('submit',async e=>{e.preventDefault();try{const data=await post(root.dataset.resumeUrl);stepButton.disabled=false;autoButton.disabled=false;e.currentTarget.classList.add('is-hidden');update(data);}catch(error){alert(error.message);}});
    document.querySelector('#substitution-form')?.addEventListener('submit',async e=>{e.preventDefault();const form=e.currentTarget;try{await post(form.dataset.url,Object.fromEntries(new FormData(form)));window.location.reload();}catch(error){alert(error.message);}});
    document.querySelector('#live-tactic-form')?.addEventListener('submit',async e=>{e.preventDefault();const form=e.currentTarget;try{await post(form.dataset.url,Object.fromEntries(new FormData(form)));alert('Consignes appliquées.');}catch(error){alert(error.message);}});
});
