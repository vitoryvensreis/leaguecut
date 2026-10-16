'use strict';
(() => {
 const form=document.querySelector('form[action="/admin/notificacoes"][method="post"]');
 const input=form?.querySelector('[name="user_ids"]');
 const audience=form?.querySelector('[name="audience"]');
 const output=document.querySelector('[data-notice-selection]');
 if(!input||!audience)return;
 function selected(){
    return [...new Set(input.value.split(/[,\s;]+/).map(x=>x.trim()).filter(x=>/^[1-9][0-9]{0,12}$/.test(x)))].slice(0,30);
 }
 function render(){
    const ids=selected();
    if(output)output.textContent=ids.length ? ids.length+' usuário(s) selecionado(s): #'+ids.join(', #') : 'Nenhuma conta selecionada.';
 }
 document.querySelectorAll('[data-add-notice-user]').forEach(btn=>{
    btn.addEventListener('click',()=>{
       const id=btn.getAttribute('data-add-notice-user')||'';
       if(!/^[1-9][0-9]{0,12}$/.test(id))return;
       const ids=selected();
       if(!ids.includes(id)){
          if(ids.length>=30){if(output)output.textContent='Limite de 30 usuários por envio.';return;}
          ids.push(id);
       }
       input.value=ids.join(', ');
       audience.value='selected';
       render();
       input.focus({preventScroll:true});
    });
 });
 input.addEventListener('input',render);
 render();
})();
