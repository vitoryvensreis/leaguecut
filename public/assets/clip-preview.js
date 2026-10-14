(() => {
 'use strict';
 const dialog=document.querySelector('#lc-clip-preview');
 const player=document.querySelector('#lc-preview-player');
 const title=document.querySelector('#lc-preview-title');
 const closeButton=document.querySelector('#lc-preview-close');
 if(!dialog||!player||!title||!closeButton)return;
 const close=()=>{
   player.pause();player.removeAttribute('src');player.load();
   if(dialog.open)dialog.close();
 };
 document.querySelectorAll('.clip-preview-trigger').forEach(button=>{
  button.addEventListener('click',()=>{
   const url=button.dataset.previewUrl||'';
   if(!/^\/clipes\/[0-9]+\/video$/.test(url))return;
   title.textContent=button.dataset.previewName||'Vídeo original';
   player.src=url;player.load();dialog.showModal();
  });
 });
 closeButton.addEventListener('click',close);
 dialog.addEventListener('close',close);
 dialog.addEventListener('cancel',()=>{player.pause();player.removeAttribute('src');});
})();
