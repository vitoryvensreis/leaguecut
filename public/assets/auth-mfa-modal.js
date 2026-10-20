/* Same-origin progressive authentication; the PHP session is never granted before MFA. */
(() => {
  'use strict';
  const login=document.querySelector('#lc-auth-login form[data-auth-async="login"]');
  const mfa=document.querySelector('#lc-auth-mfa form[data-auth-async="mfa"]');
  if(!login || !mfa || !window.fetch || !window.FormData) return;
  const loginDialog=document.getElementById('lc-auth-login');
  const mfaDialog=document.getElementById('lc-auth-mfa');
  let inFlight=false;
  const display=(form,message)=>{
    const el=form.closest('dialog')?.querySelector('[data-auth-error]');
    if(!el)return;
    el.hidden=!message;
    el.textContent=message||'';
  };
  const open=path=>document.dispatchEvent(new CustomEvent('lc:auth:open',{detail:{path,push:true}}));
  const reset=()=>{
    display(login,'');display(mfa,'');
    mfa.reset();
    open('/entrar');
  };
  mfaDialog.querySelector('[data-auth-back]')?.addEventListener('click',reset);
  async function send(form){
    if(inFlight || !form.reportValidity())return;
    inFlight=true;
    display(form,'');
    const button=form.querySelector('button[type="submit"]');
    if(button)button.disabled=true;
    try{
      const response=await fetch(form.action,{
        method:'POST',body:new FormData(form),credentials:'same-origin',
        redirect:'follow',cache:'no-store',
        headers:{'X-LC-AUTH':'1','Accept':'application/json'}
      });
      const type=response.headers.get('Content-Type')||'';
      if(!type.includes('application/json')){
        display(form,'Não foi possível confirmar a sessão. Atualize a página e tente novamente.');
        return;
      }
      const result=await response.json();
      if(result.ok && result.step==='mfa' && form===login){
        // The first modal closes before opening verification; no document navigation occurs.
        display(login,'');
        mfa.reset();
        open('/seguranca/verificar');
        return;
      }
      if(result.ok && result.step==='redirect'){
        const target=result.redirect||'';
        if(/^\/(?!\/)[a-z0-9/_-]+$/i.test(target)){
          window.location.assign(target);
          return;
        }
      }
      if(result.step==='login' && form===mfa){
        reset();
        display(login,result.message||'Sua sessão de verificação expirou. Faça login novamente.');
        return;
      }
      display(form,result.message||'Não foi possível validar os dados. Tente novamente.');
    }catch(_){
      display(form,'Não foi possível conectar com segurança. Confira sua conexão e tente novamente.');
    }finally{
      inFlight=false;
      if(button)button.disabled=false;
    }
  }
  [login,mfa].forEach(form=>form.addEventListener('submit',e=>{e.preventDefault();send(form);}));
})();
