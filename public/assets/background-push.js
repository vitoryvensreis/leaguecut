'use strict';
(() => {
  const panel=document.querySelector('#lc-push-background');
  if(!panel)return;
  const on=panel.querySelector('[data-background-on]');
  const off=panel.querySelector('[data-background-off]');
  const status=panel.querySelector('[data-background-status]');
  const key=panel.dataset.vapid||'';
  const csrf=panel.dataset.csrf||'';
  const supported=Boolean(window.isSecureContext && 'serviceWorker' in navigator &&
      'PushManager' in window && 'Notification' in window);
  const ready=supported && Boolean(key) && Boolean(csrf);
  const message=text=>{if(status)status.textContent=text;};
  if(!ready){
    if(on)on.disabled=true;if(off)off.disabled=true;
    if(key && !supported)message('Este navegador não permite Web Push. No iPhone, instale a LeagueCut na Tela de Início.');
    return;
  }
  const bytes=text=>{
    const base64=(text.replace(/-/g,'+').replace(/_/g,'/')+'='.repeat((4-text.length%4)%4));
    return Uint8Array.from(atob(base64),c=>c.charCodeAt(0));
  };
  async function submit(path,params) {
    const result=await fetch(path,{
      method:'POST',credentials:'same-origin',cache:'no-store',
      headers:{'Accept':'application/json','Content-Type':'application/x-www-form-urlencoded'},
      body:new URLSearchParams({_csrf:csrf,...params})
    });
    const data=await result.json();
    if(!result.ok || !data.ok)throw Error(data.message||'O servidor não confirmou a operação.');
  }
  on?.addEventListener('click',async()=>{
    on.disabled=true;
    try{
      const permission=await Notification.requestPermission();
      if(permission!=='granted')throw Error('Permissão não concedida. Ajuste nas configurações do navegador.');
      const registration=await navigator.serviceWorker.ready;
      let subscription=await registration.pushManager.getSubscription();
      const fresh=!subscription;
      if(!subscription)subscription=await registration.pushManager.subscribe({
        userVisibleOnly:true,applicationServerKey:bytes(key)
      });
      try{
        await submit('/api/push/subscribe',{subscription_json:JSON.stringify(subscription.toJSON())});
      }catch(error){
        if(fresh)await subscription.unsubscribe().catch(()=>{});
        throw error;
      }
      message('Web Push ativado neste dispositivo. Você pode desativar quando quiser.');
    }catch(error){message(error?.message||'Não foi possível ativar as notificações.');}
    finally{on.disabled=false;}
  });
  off?.addEventListener('click',async()=>{
    off.disabled=true;
    try{
      const registration=await navigator.serviceWorker.ready;
      const subscription=await registration.pushManager.getSubscription();
      if(!subscription){message('Este navegador não possui uma assinatura ativa.');return;}
      await submit('/api/push/unsubscribe',{endpoint:subscription.endpoint});
      await subscription.unsubscribe();
      message('Web Push desativado neste dispositivo.');
    }catch(error){message(error?.message||'Não foi possível desativar.');}
    finally{off.disabled=false;}
  });
})();
