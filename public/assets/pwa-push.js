'use strict';
(() => {
  const panel = document.querySelector('#lc-push-panel');
  const csrf = panel?.dataset.csrf;
  const publicKey = panel?.dataset.vapid;
  const enable = panel?.querySelector('[data-push-on]');
  const disable = panel?.querySelector('[data-push-off]');
  const status = panel?.querySelector('[data-push-state]');
  const sound = document.querySelector('[data-push-sound]');
  const soundStatus = document.querySelector('[data-push-sound-state]');
  const supported = !!('serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window && window.isSecureContext);
  const update = message => {if(status)status.textContent=message;};
  const b64bytes = key => {
    const data=atob(key.replace(/-/g,'+').replace(/_/g,'/')+'='.repeat((4-key.length%4)%4));
    return Uint8Array.from(data, char=>char.charCodeAt(0));
  };
  async function post(url,fields) {
    const resp=await fetch(url,{
      method:'POST',credentials:'same-origin',cache:'no-store',
      headers:{'Content-Type':'application/x-www-form-urlencoded','Accept':'application/json'},
      body:new URLSearchParams({_csrf:csrf,...fields})
    });
    const reply=await resp.json();
    if(!resp.ok||!reply.ok)throw new Error(reply.message||'Falha ao salvar preferência.');
    return reply;
  }
  if(panel){
    if(!supported){if(enable)enable.disabled=true;update('Este navegador não suporta notificações push neste contexto.');}
    enable?.addEventListener('click',async()=>{
      if(!supported||!publicKey)return;
      enable.disabled=true;
      try{
        const permission=await Notification.requestPermission();
        if(permission!=='granted')throw new Error('Permissão não concedida. Confira as configurações do navegador.');
        const registration=await navigator.serviceWorker.ready;
        const subscription=await registration.pushManager.subscribe({
          userVisibleOnly:true,applicationServerKey:b64bytes(publicKey)
        });
        await post('/notificacoes/assinar',{subscription_json:JSON.stringify(subscription.toJSON())});
        update('Notificações push ativadas neste dispositivo.');
      }catch(err){
        update(err?.message||'Não foi possível ativar as notificações.');
      }finally{enable.disabled=false;}
    });
    disable?.addEventListener('click',async()=>{
      if(!supported)return;
      disable.disabled=true;
      try{
        const registration=await navigator.serviceWorker.ready;
        const subscription=await registration.pushManager.getSubscription();
        if(!subscription){update('Não há assinatura push neste dispositivo.');return;}
        await post('/notificacoes/desativar',{endpoint:subscription.endpoint});
        await subscription.unsubscribe();
        update('Notificações desativadas neste dispositivo.');
      }catch(err){update(err?.message||'Falha ao desativar este dispositivo.');}
      finally{disable.disabled=false;}
    });
  }
  let audioContext=null;
  let soundOn=false;
  try{soundOn=localStorage.getItem('leaguecut_notification_sound')==='1';}catch(_){}
  function renderSound(){
    if(sound)sound.setAttribute('aria-pressed',String(soundOn));
    if(sound)sound.textContent=soundOn?'Desativar som nesta aba':'Ativar som nesta aba';
    if(soundStatus)soundStatus.textContent=soundOn?'Avisos sonoros ativados com a aba aberta.':'Avisos sonoros desativados.';
  }
  function tone(){
    if(document.hidden||!soundOn)return;
    try{
      const AudioCtx=window.AudioContext||window.webkitAudioContext;
      if(!AudioCtx)return;
      audioContext=audioContext||new AudioCtx();
      if(audioContext.state==='suspended')audioContext.resume().catch(()=>{});
      const oscillator=audioContext.createOscillator();
      const gain=audioContext.createGain();
      oscillator.frequency.value=690;
      oscillator.type='sine';
      gain.gain.setValueAtTime(.0001,audioContext.currentTime);
      gain.gain.exponentialRampToValueAtTime(.065,audioContext.currentTime+.025);
      gain.gain.exponentialRampToValueAtTime(.0001,audioContext.currentTime+.20);
      oscillator.connect(gain).connect(audioContext.destination);
      oscillator.start();oscillator.stop(audioContext.currentTime+.22);
    }catch(_){}
  }
  sound?.addEventListener('click',()=>{
    soundOn=!soundOn;
    try{localStorage.setItem('leaguecut_notification_sound',soundOn?'1':'0');}catch(_){}
    renderSound();
    if(soundOn)tone();
  });
  renderSound();
  window.addEventListener('leaguecut:live-event',()=>tone());
})();
