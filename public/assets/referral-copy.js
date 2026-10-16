(() => {
'use strict';
document.addEventListener('click',async event=>{
 const button=event.target.closest('[data-copy-ref]');
 if(!button)return;
 const input=button.closest('.lc-referral-copy')?.querySelector('input[data-copy-field]');
 if(!input)return;
 try{
   if(navigator.clipboard?.writeText)await navigator.clipboard.writeText(input.value);
   else {input.focus();input.select();return;}
   const prev=button.textContent;button.textContent='Copiado!';
   window.setTimeout(()=>{button.textContent=prev},1800);
 }catch(e){input.focus();input.select();}
});
})();
