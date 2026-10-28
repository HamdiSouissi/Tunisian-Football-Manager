document.addEventListener('DOMContentLoaded', () => {
    const canvas = document.querySelector('#player-radar');
    if (!canvas) return;
    const values = JSON.parse(canvas.dataset.radar || '{}');
    const labels = Object.keys(values), scores = Object.values(values).map(Number);
    const ctx = canvas.getContext('2d');
    const ratio = window.devicePixelRatio || 1;
    const width = canvas.clientWidth || 420, height = 360;
    canvas.width = width * ratio; canvas.height = height * ratio; ctx.scale(ratio, ratio);
    const cx = width / 2, cy = height / 2, radius = Math.min(width, height) * .31;
    const point = (index, scale = 1) => {
        const angle = -Math.PI / 2 + index * Math.PI * 2 / labels.length;
        return [cx + Math.cos(angle) * radius * scale, cy + Math.sin(angle) * radius * scale];
    };
    ctx.font = '12px system-ui'; ctx.textAlign = 'center'; ctx.textBaseline = 'middle';
    [.25,.5,.75,1].forEach(scale => {
        ctx.beginPath(); labels.forEach((_, i) => { const [x,y]=point(i,scale); i ? ctx.lineTo(x,y) : ctx.moveTo(x,y); });
        ctx.closePath(); ctx.strokeStyle = '#334155'; ctx.stroke();
    });
    labels.forEach((label,i) => {
        const [x,y]=point(i); ctx.beginPath(); ctx.moveTo(cx,cy); ctx.lineTo(x,y); ctx.strokeStyle='#293750'; ctx.stroke();
        const [tx,ty]=point(i,1.25); ctx.fillStyle='#94a3b8'; ctx.fillText(`${label} ${scores[i]}`,tx,ty);
    });
    ctx.beginPath(); scores.forEach((score,i) => { const [x,y]=point(i,Math.max(0,Math.min(100,score))/100); i ? ctx.lineTo(x,y) : ctx.moveTo(x,y); });
    ctx.closePath(); ctx.fillStyle='rgba(45,212,191,.22)'; ctx.fill(); ctx.strokeStyle='#2dd4bf'; ctx.lineWidth=2; ctx.stroke();
    scores.forEach((score,i) => { const [x,y]=point(i,score/100); ctx.beginPath(); ctx.arc(x,y,3,0,Math.PI*2); ctx.fillStyle='#5eead4'; ctx.fill(); });
});
