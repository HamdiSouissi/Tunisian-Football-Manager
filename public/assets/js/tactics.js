document.addEventListener('DOMContentLoaded', () => {
    const root = document.querySelector('#tactics-data');
    if (!root) return;
    const tactic = JSON.parse(root.dataset.tactic), players = JSON.parse(root.dataset.players), formations = JSON.parse(root.dataset.formations);
    const playerMap = new Map(players.map(p => [Number(p.id), p]));
    const coords = {
      '4-4-2': [[50,88],[15,66],[38,68],[62,68],[85,66],[14,42],[38,46],[62,46],[86,42],[35,18],[65,18]],
      '4-3-3': [[50,88],[15,66],[38,68],[62,68],[85,66],[50,54],[32,40],[68,40],[16,20],[84,20],[50,14]],
      '4-2-3-1': [[50,88],[15,66],[38,68],[62,68],[85,66],[36,52],[64,52],[16,31],[50,34],[84,31],[50,13]],
      '3-5-2': [[50,88],[27,67],[50,70],[73,67],[10,42],[32,47],[50,40],[68,47],[90,42],[35,16],[65,16]],
      '5-3-2': [[50,88],[10,52],[27,68],[50,71],[73,68],[90,52],[30,42],[50,47],[70,42],[35,16],[65,16]],
      '4-1-4-1': [[50,88],[15,66],[38,68],[62,68],[85,66],[50,53],[14,35],[36,39],[64,39],[86,35],[50,13]]
    };
    let lineup = tactic.lineup.slice(0,11).map((entry,i) => ({...entry, player_id:Number(entry.player_id), slot:i, role:entry.role || 'support'}));
    let substitutes = (tactic.substitutes || []).map(e => Number(e.player_id ?? e)).filter(id => playerMap.has(id));
    let selectedPlayer = null;
    const pitch = document.querySelector('#tactics-pitch'), formationSelect = document.querySelector('#formation-select');
    const available = document.querySelector('#available-players'), subs = document.querySelector('#substitutes-list');
    const card = (entry, index) => {
        const p = playerMap.get(Number(entry.player_id));
        if (!p) return '';
        return `<div class="pitch-player" draggable="true" data-player-id="${p.id}" data-slot="${index}" title="${p.name}"><span class="position-badge">${entry.position}</span><strong>${p.name.split(' ').pop()}</strong><b>${p.ovr}</b><select data-instruction="${index}" aria-label="Consigne"><option value="normal">Normal</option><option value="attack" ${entry.instruction==='attack'?'selected':''}>Monte</option><option value="stay_back" ${entry.instruction==='stay_back'?'selected':''}>Reste</option></select><select data-role="${index}" aria-label="Rôle"><option value="support">Soutien</option><option value="attack" ${entry.role==='attack'?'selected':''}>Attaque</option><option value="defend" ${entry.role==='defend'?'selected':''}>Défense</option><option value="playmaker" ${entry.role==='playmaker'?'selected':''}>Meneur</option><option value="target" ${entry.role==='target'?'selected':''}>Pivot</option></select></div>`;
    };
    const listCard = (p, zone) => `<div class="draggable-player" draggable="true" data-player-id="${p.id}" data-zone="${zone}" data-player-name="${p.name.toLowerCase()}"><span class="position-badge">${p.position}</span><span><strong>${p.name}</strong><small>${p.injury ? 'Blessé' : `Fatigue ${p.fatigue}%`}</small></span><b>${p.ovr}</b></div>`;
    const bind = () => {
        document.querySelectorAll('[draggable="true"]').forEach(el => {
            el.addEventListener('dragstart', e => { e.dataTransfer.setData('text/plain', el.dataset.playerId); selectedPlayer=Number(el.dataset.playerId); });
            el.addEventListener('click', e => { e.stopPropagation(); if (e.target.tagName !== 'SELECT') { selectedPlayer=Number(el.dataset.playerId); document.querySelectorAll('.is-selected').forEach(x=>x.classList.remove('is-selected')); el.classList.add('is-selected'); } });
        });
        document.querySelectorAll('[data-instruction]').forEach(select => select.addEventListener('change', () => { lineup[Number(select.dataset.instruction)].instruction=select.value; update(); }));
        document.querySelectorAll('[data-role]').forEach(select => select.addEventListener('change', () => { lineup[Number(select.dataset.role)].role=select.value; update(); }));
    };
    const render = () => {
        const positions = formations[formationSelect.value], points = coords[formationSelect.value];
        lineup.forEach((entry,i) => { entry.slot=i; entry.position=positions[i]; [entry.x,entry.y]=points[i]; });
        pitch.querySelectorAll('.pitch-slot').forEach(x=>x.remove());
        lineup.forEach((entry,i) => {
            const slot=document.createElement('div'); slot.className='pitch-slot'; slot.dataset.slot=i; slot.style.left=`${entry.x}%`; slot.style.top=`${entry.y}%`;
            slot.innerHTML=card(entry,i); slot.addEventListener('dragover',e=>e.preventDefault()); slot.addEventListener('drop',e=>{e.preventDefault();place(Number(e.dataTransfer.getData('text/plain')),i);});
            slot.addEventListener('click',()=>{if(selectedPlayer) place(selectedPlayer,i);}); pitch.appendChild(slot);
        });
        const used = new Set(lineup.map(e=>Number(e.player_id)));
        subs.innerHTML = substitutes.map(id=>playerMap.has(id)?listCard(playerMap.get(id),'substitutes'):'').join('') || '<p class="muted">Déposez ici vos remplaçants.</p>';
        available.innerHTML = players.filter(p=>!used.has(Number(p.id))&&!substitutes.includes(Number(p.id))).map(p=>listCard(p,'available')).join('');
        bind(); update();
    };
    const place = (playerId, targetSlot) => {
        if (!playerMap.has(playerId)) return;
        const source = lineup.findIndex(e=>Number(e.player_id)===playerId);
        if (source === targetSlot) return;
        if (source >= 0) [lineup[source].player_id,lineup[targetSlot].player_id]=[lineup[targetSlot].player_id,lineup[source].player_id];
        else {
            const fromBench=substitutes.includes(playerId), outgoing=Number(lineup[targetSlot].player_id);
            substitutes=substitutes.filter(id=>id!==playerId);
            if(fromBench && !substitutes.includes(outgoing) && substitutes.length<7) substitutes.push(outgoing);
            lineup[targetSlot].player_id=playerId;
        }
        selectedPlayer=null; render();
    };
    const dropTo = (id,zone) => {
        const inLineup=lineup.some(e=>Number(e.player_id)===id);
        if(zone==='substitutes') { if(!inLineup&&!substitutes.includes(id)&&substitutes.length<7) substitutes.push(id); }
        else { substitutes=substitutes.filter(x=>x!==id); }
        selectedPlayer=null; render();
    };
    [subs,available].forEach(zone=>{zone.addEventListener('dragover',e=>e.preventDefault());zone.addEventListener('drop',e=>{e.preventDefault();dropTo(Number(e.dataTransfer.getData('text/plain')),zone.dataset.dropZone);});zone.addEventListener('click',()=>{if(selectedPlayer)dropTo(selectedPlayer,zone.dataset.dropZone);});});
    const update = () => { document.querySelector('#lineup-input').value=JSON.stringify(lineup); document.querySelector('#substitutes-input').value=JSON.stringify(substitutes); };
    formationSelect.addEventListener('change',render);
    document.querySelector('[data-player-filter]')?.addEventListener('input',e=>available.querySelectorAll('[data-player-name]').forEach(el=>el.hidden=!el.dataset.playerName.includes(e.target.value.toLowerCase())));
    render();
});
