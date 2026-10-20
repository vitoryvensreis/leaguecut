(() => {
  const track=document.getElementById('lc-spotlight-track');
  if(!track)return;
  const items=[...track.querySelectorAll('.lc-spotlight-card')];
  const text=document.getElementById('lc-spotlight-index');
  let active=0;
  const update=()=>{
    if(!text)return;
    active=items.reduce((best,item,index)=>{
      const target=Math.abs(item.offsetLeft-track.offsetLeft-track.scrollLeft);
      const old=Math.abs(items[best].offsetLeft-track.offsetLeft-track.scrollLeft);
      return target<old?index:best;
    },0);
    text.textContent=(active+1)+' de '+items.length;
  };
  let queued=false;track.addEventListener('scroll',()=>{
    if(queued)return;queued=true;requestAnimationFrame(()=>{queued=false;update();});
  },{passive:true});
  function move(delta){active=Math.max(0,Math.min(items.length-1,active+delta));items[active]?.scrollIntoView({behavior:'smooth',block:'nearest',inline:'start'});if(text)text.textContent=(active+1)+' de '+items.length;}
  document.querySelector('[data-spotlight-prev]')?.addEventListener('click',()=>move(-1));
  document.querySelector('[data-spotlight-next]')?.addEventListener('click',()=>move(1));
})();