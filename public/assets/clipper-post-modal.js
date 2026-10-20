(() => {
 'use strict';
 const dialog=document.getElementById('lc-quick-post');
 if(!dialog)return;
 const form=document.getElementById('lc-quick-form');
 const file=document.getElementById('lc-quick-file');
 const next=document.getElementById('lc-quick-next');
 const step1=document.getElementById('lc-quick-step-file');
 const step2=document.getElementById('lc-quick-step-details');
 const filename=document.getElementById('lc-quick-filename');
 let busy=false;
 function open(){
   dialog.showModal();
   document.querySelector('.ws-mobile-menu details[open]')?.removeAttribute('open');
 }
 document.querySelectorAll('[data-clipper-post-open]').forEach(b=>b.addEventListener('click',open));
 dialog.querySelector('[data-quick-close]')?.addEventListener('click',()=>{if(!busy)dialog.close();});
 dialog.addEventListener('click',event=>{if(event.target===dialog&&!busy)dialog.close();});
 dialog.addEventListener('cancel',event=>{if(busy)event.preventDefault();});
 if(!form||!file)return;
 const drop=document.getElementById('lc-quick-drop');
 const msg=document.getElementById('lc-quick-error');
 const btn=document.getElementById('lc-quick-submit');
 const progress=document.getElementById('lc-quick-progress');
 const bar=document.getElementById('lc-quick-meter');
 const stage=document.getElementById('lc-quick-stage');
 const pct=document.getElementById('lc-quick-percent');
 function check() {
   const f=file.files?.[0];
   let error='';
   if(f && (f.size<1||f.size>1024*1024*1024)) error='Arquivo deve ter no máximo 1 GB.';
   else if(f && !/\.(mp4|mov|webm)$/i.test(f.name))error='Use MP4, MOV ou WebM.';
   next.disabled=!f||!!error;
   filename.textContent=error||(f ? f.name+' · '+(f.size/1024/1024).toFixed(1)+' MB' : '');
   file.setCustomValidity(error);
 }
 file.addEventListener('change',check);
 ['dragenter','dragover'].forEach(name=>drop?.addEventListener(name,e=>{e.preventDefault();drop.classList.add('is-dragging');}));
 ['dragleave','drop'].forEach(name=>drop?.addEventListener(name,e=>{e.preventDefault();drop.classList.remove('is-dragging');}));
 drop?.addEventListener('drop',e=>{if(e.dataTransfer?.files?.length){file.files=e.dataTransfer.files;check();}});
 next.addEventListener('click',()=>{
   check();if(next.disabled)return;
   step1.hidden=true;step2.hidden=false;
   document.getElementById('lc-quick-selected').textContent='Vídeo selecionado: '+file.files[0].name;
   step2.querySelector('select')?.focus();
 });
 document.getElementById('lc-quick-back').addEventListener('click',()=>{if(!busy){step1.hidden=false;step2.hidden=true;}});
 form.addEventListener('submit',e=>{
   e.preventDefault(); if(busy||!form.reportValidity())return;
   check(); if(next.disabled){step1.hidden=false;step2.hidden=true;return;}
   msg.hidden=true;busy=true;btn.disabled=true;progress.hidden=false;
   bar.value=0;pct.textContent='0%';stage.textContent='Enviando vídeo…';
   const request=new XMLHttpRequest();
   request.open('POST',form.action);
   request.setRequestHeader('X-Requested-With','XMLHttpRequest');
   request.setRequestHeader('Accept','application/json');
   request.timeout=15*60*1000;
   request.upload.onprogress=evt=>{
     if(!evt.lengthComputable)return;
     const val=Math.min(100,Math.floor(evt.loaded/evt.total*100));
     bar.value=val;pct.textContent=val+'%';
     if(val===100)stage.textContent='Aguardando confirmação do Drive…';
   };
   const fail=message=>{
     busy=false;btn.disabled=false;progress.hidden=true;
     msg.textContent=message;msg.hidden=false;
   };
   request.onload=()=>{
     let data={};try{data=JSON.parse(request.responseText);}catch(_){}
     if(request.status===200&&data.ok&&/^\/[a-z0-9/_-]+$/.test(data.redirect||'')){
       location.assign(data.redirect);return;
     }
     fail(data.error||'Envio não confirmado. Confira sua biblioteca antes de tentar novamente.');
   };
   request.onerror=()=>fail('Falha na conexão. Verifique sua biblioteca antes de reenviar.');
   request.ontimeout=()=>fail('Tempo limite. Confira sua biblioteca antes de reenviar.');
   request.send(new FormData(form));
 });
})();