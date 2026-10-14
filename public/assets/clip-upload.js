(() => {
 'use strict';
 const form=document.querySelector('#lc-clip-form');
 const input=document.querySelector('#lc-clip-file');
 const button=document.querySelector('#lc-clip-submit');
 const panel=document.querySelector('#lc-upload-progress');
 const error=document.querySelector('#lc-upload-error');
 const meter=document.querySelector('#lc-upload-meter');
 const percent=document.querySelector('#lc-upload-percent');
 const stage=document.querySelector('#lc-upload-stage');
 const detail=document.querySelector('#lc-upload-detail');
 if(!form||!input||!button||!panel||!meter)return;
 let sending=false;
 const fail=message=>{
    sending=false;button.disabled=false;input.disabled=false;panel.hidden=true;
    error.hidden=false;error.textContent=message;
 };
 form.addEventListener('submit',event=>{
    event.preventDefault();
    if(sending||!form.reportValidity())return;
    const file=input.files?.[0];
    if(!file)return fail('Anexe um vídeo original antes de enviar.');
    if(file.size<1||file.size>1024*1024*1024)return fail('O vídeo deve ter até 1 GB e não pode estar vazio.');
    if(!/\.(mp4|mov|webm)$/i.test(file.name))return fail('Use MP4, MOV ou WebM.');
    error.hidden=true;panel.hidden=false;meter.value=0;percent.textContent='0%';
    stage.textContent='Enviando vídeo ao servidor…';
    detail.textContent='Progresso da transferência para a VPS, não do processamento no Google Drive.';
    sending=true;button.disabled=true;
    const request=new XMLHttpRequest();
    request.open('POST',form.action,true);
    request.setRequestHeader('X-Requested-With','XMLHttpRequest');
    request.setRequestHeader('Accept','application/json');
    request.timeout=15*60*1000;
    request.upload.onprogress=e=>{
        if(!e.lengthComputable)return;
        const value=Math.min(100,Math.floor((e.loaded/e.total)*100));
        meter.value=value;percent.textContent=value+'%';
        if(value===100){
            stage.textContent='Aguardando confirmação do Google Drive…';
            detail.textContent='Não feche a página. Os 100% indicam apenas que o navegador enviou o arquivo à VPS.';
            meter.removeAttribute('value');percent.textContent='Processando';
        }
    };
    request.onload=()=>{
      let data=null;try{data=JSON.parse(request.responseText);}catch(_){}
      if(request.status>=200&&request.status<300&&data?.ok&&/^\/[a-z0-9/_-]+$/.test(data.redirect||'')){
          stage.textContent='Armazenamento confirmado!';
          location.assign(data.redirect);return;
      }
      fail(data?.error||(request.status===413?'Arquivo maior que o limite do servidor. Tente um vídeo menor.':'O envio não foi confirmado. Confira sua biblioteca antes de reenviar.'));
    };
    request.onerror=()=>fail('Falha de rede. Confira sua biblioteca antes de tentar novamente.');
    request.ontimeout=()=>fail('O processamento demorou demais. Confira sua biblioteca antes de reenviar.');
    request.onabort=()=>fail('Envio cancelado.');
    request.send(new FormData(form));
 });
})();
