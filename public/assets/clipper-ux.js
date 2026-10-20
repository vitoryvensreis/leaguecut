(() => {
  'use strict';
  const shell=document.querySelector('.workspace-page');
  const menu=document.querySelector('[data-sidebar-toggle]');
  const backdrop=document.querySelector('[data-sidebar-close]');
  function drawer(open){
    if(!shell || !menu || !backdrop)return;
    shell.classList.toggle('ws-sidebar-open',open);
    menu.setAttribute('aria-expanded',String(open));
    backdrop.hidden=!open;
  }
  menu?.addEventListener('click',()=>drawer(!shell.classList.contains('ws-sidebar-open')));
  backdrop?.addEventListener('click',()=>drawer(false));
  document.addEventListener('keydown',e=>{if(e.key==='Escape')drawer(false);});
  document.querySelectorAll('.workspace-sidebar a').forEach(a=>a.addEventListener('click',()=>drawer(false)));

  const modal=document.querySelector('#lc-quick-clip');
  if(!modal)return;
  const form=modal.querySelector('#lc-quick-form');
  const input=modal.querySelector('#lc-quick-file');
  const first=modal.querySelector('#lc-quick-step-file');
  const second=modal.querySelector('#lc-quick-step-info');
  const drop=modal.querySelector('.lc-quick-drop');
  const errors=modal.querySelectorAll('[data-clip-error]');
  let sending=false;
  let activeRequest=null;
  function fail(message){
    sending=false; activeRequest=null;
    errors.forEach(el=>{el.hidden=false;el.textContent=message;});
    const submit=modal.querySelector('.lc-quick-submit');
    if(submit)submit.disabled=false;
    const progress=modal.querySelector('[data-clip-progress]');
    if(progress)progress.hidden=true;
  }
  function clearErrors(){errors.forEach(el=>{el.hidden=true;el.textContent='';});}
  function selected(){
    const file=input?.files?.[0];
    if(!file)return;
    clearErrors();
    if(!/\.(mp4|mov|webm)$/i.test(file.name)||file.size<1||file.size>1024*1024*1024){
      input.value='';
      return fail('Selecione um arquivo MP4, MOV ou WebM de até 1 GB.');
    }
    modal.querySelector('[data-clip-file-name]').textContent=file.name;
    modal.querySelector('[data-clip-file-size]').textContent=(file.size/(1024*1024)).toFixed(1)+' MB';
    first.hidden=true;second.hidden=false;
    second.querySelector('select')?.focus();
  }
  input?.addEventListener('change',selected);
  drop?.addEventListener('keydown',e=>{if((e.key==='Enter'||e.key===' ')&&e.target===drop){e.preventDefault();input?.click();}});
  for(const type of ['dragenter','dragover'])drop?.addEventListener(type,e=>{e.preventDefault();drop.classList.add('is-dragover');});
  for(const type of ['dragleave','drop'])drop?.addEventListener(type,e=>{e.preventDefault();drop.classList.remove('is-dragover');});
  drop?.addEventListener('drop',e=>{
    const files=e.dataTransfer?.files;
    if(!files?.length)return;
    try {const dt=new DataTransfer();dt.items.add(files[0]);input.files=dt.files;selected();}catch(_){fail('Clique na área para selecionar o arquivo.');}
  });
  modal.querySelector('[data-clip-back]')?.addEventListener('click',()=>{
    if(sending)return;
    second.hidden=true;first.hidden=false;input.value='';input.focus();
  });
  const open=()=>{
    drawer(false);
    if(!modal.open)modal.showModal();
  };
  document.querySelectorAll('[data-clip-open]').forEach(b=>b.addEventListener('click',open));
  modal.querySelector('[data-clip-close]')?.addEventListener('click',()=>{if(!sending)modal.close();});
  modal.addEventListener('cancel',e=>{if(sending)e.preventDefault();});
  modal.addEventListener('click',e=>{if(e.target===modal && !sending)modal.close();});
  form?.addEventListener('submit',e=>{
    e.preventDefault();
    if(sending)return;
    if(!input?.files?.length){second.hidden=true;first.hidden=false;return fail('Escolha um vídeo para continuar.');}
    if(!form.reportValidity())return;
    clearErrors();
    const progress=modal.querySelector('[data-clip-progress]');
    const label=modal.querySelector('[data-clip-progress-label]');
    const help=modal.querySelector('[data-clip-progress-help]');
    const percent=modal.querySelector('[data-clip-percent]');
    const meter=modal.querySelector('[data-clip-meter]');
    const submit=modal.querySelector('.lc-quick-submit');
    progress.hidden=false;meter.value=0;percent.textContent='0%';submit.disabled=true;
    label.textContent='Enviando vídeo…';
    help.textContent='O progresso corresponde à transferência para a VPS.';
    sending=true;
    const xhr=new XMLHttpRequest();
    activeRequest=xhr;
    xhr.open('POST',form.action,true);
    xhr.setRequestHeader('X-Requested-With','XMLHttpRequest');
    xhr.setRequestHeader('Accept','application/json');
    xhr.timeout=15*60*1000;
    xhr.upload.onprogress=ev=>{
      if(!ev.lengthComputable)return;
      const value=Math.floor(ev.loaded/ev.total*100);
      percent.textContent=value+'%';meter.value=value;
      if(value>=100){
        label.textContent='Salvando no Google Drive…';
        help.textContent='Não feche esta janela. O arquivo ainda está sendo processado.';
        percent.textContent='Processando';meter.removeAttribute('value');
      }
    };
    xhr.onload=()=>{
      let data=null;try{data=JSON.parse(xhr.responseText)}catch(_){}
      if(xhr.status>=200 && xhr.status<300 && data?.ok && /^\/[a-z0-9/_-]+$/.test(data.redirect||'')){
        location.assign(data.redirect);return;
      }
      fail(data?.error||(xhr.status===413?'Arquivo excede o limite do servidor.':'Envio não confirmado. Confira a biblioteca antes de tentar novamente.'));
    };
    xhr.onerror=()=>fail('A conexão caiu. Confira a biblioteca antes de reenviar.');
    xhr.ontimeout=()=>fail('Tempo de envio esgotado. Confira a biblioteca antes de reenviar.');
    xhr.onabort=()=>fail('Envio interrompido.');
    xhr.send(new FormData(form));
  });
})();
