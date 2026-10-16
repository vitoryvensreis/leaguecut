'use strict';
(() => {
  const panel=document.querySelector('#lc-notification-settings');
  const enable=panel?.querySelector('[data-notify-enable]');
  const disable=panel?.querySelector('[data-notify-disable]');
  const status=panel?.querySelector('[data-notify-status]');
  const soundBtn=document.querySelector('[data-notify-sound]');
  const soundStatus=document.querySelector('[data-notify-sound-status]');
  const supported='Notification' in window && window.isSecureContext;
  const storageKey='leaguecut_foreground_notifications';
  const soundKey='leaguecut_notification_sound';
  const read=(key)=>{try{return localStorage.getItem(key)==='1';}catch(_){return false;}};
  const save=(key,value)=>{try{localStorage.setItem(key,value?'1':'0');}catch(_){}};
  let notifications=read(storageKey);
  let sound=read(soundKey);
  let ctx=null,received=0;
  function render(){
    if(enable)enable.disabled=!supported||Notification.permission==='denied';
    if(status)status.textContent=!supported?'Este navegador não permite notificações neste contexto.':
      Notification.permission==='denied'?'Permissão bloqueada pelo navegador. Modifique nas configurações do site.':
      notifications&&Notification.permission==='granted'?'Avisos ativados neste navegador, enquanto a aba estiver aberta.':'Avisos desativados neste navegador.';
    if(soundBtn){soundBtn.textContent=sound?'Desativar som':'Ativar som';soundBtn.setAttribute('aria-pressed',String(sound));}
    if(soundStatus)soundStatus.textContent=sound?'Som ativado nesta aba.':'Som desativado.';
  }
  async function primeSound(){
    const AudioCtx=window.AudioContext||window.webkitAudioContext;
    if(!AudioCtx)return;
    ctx=ctx||new AudioCtx();
    if(ctx.state==='suspended')await ctx.resume();
  }
  function ding(){
    if(!sound||document.hidden||!ctx||ctx.state!=='running')return;
    try{
      const osc=ctx.createOscillator(),gain=ctx.createGain();
      const now=ctx.currentTime;osc.type='sine';osc.frequency.value=740;
      gain.gain.setValueAtTime(.0001,now);
      gain.gain.exponentialRampToValueAtTime(.05,now+.025);
      gain.gain.exponentialRampToValueAtTime(.0001,now+.19);
      osc.connect(gain).connect(ctx.destination);osc.start(now);osc.stop(now+.2);
    }catch(_){}
  }
  enable?.addEventListener('click',async()=>{
    if(!supported)return;
    try{
      const permission=await Notification.requestPermission();
      notifications=permission==='granted';
      save(storageKey,notifications);render();
    }catch(_){if(status)status.textContent='Não foi possível solicitar a permissão.';}
  });
  disable?.addEventListener('click',()=>{notifications=false;save(storageKey,false);render();});
  soundBtn?.addEventListener('click',async()=>{
    sound=!sound;save(soundKey,sound);
    if(sound){try{await primeSound();ding();}catch(_){sound=false;save(soundKey,false);}}
    render();
  });
  window.addEventListener('leaguecut:live-event',(event)=>{
    if(typeof event.detail?.type!=='string')return;
    received+=1;
    if(received>40)return; // avoid notification floods in a long-lived tab
    ding();
    if(!notifications||!supported||Notification.permission!=='granted')return;
    try{const item=new Notification('LeagueCut',{body:'Uma informação da sua conta foi atualizada.',icon:'/app-icon/192.png',tag:'leaguecut-live'});item.onclick=()=>{window.focus();item.close();};}catch(_){}
  });
  render();
})();
