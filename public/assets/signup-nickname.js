/* Live public nickname availability with the database as final authority. */
(() => {
    'use strict';
    const input=document.querySelector('#lc-auth-signup [data-nickname-check]');
    const form=input?.closest('form');
    const feedback=document.querySelector('#lc-auth-signup [data-nickname-feedback]');
    if(!input||!form||!feedback)return;
    let timeout=null;
    let controller=null;
    let lastChecked='';
    let lastStatus='';
    const reserved=new Set(['admin','leaguecut','suporte','support','moderador','system','null','root','api','security']);
    const normalize=value=>value.trim().toLowerCase();
    function message(status,text){
        lastStatus=status;
        feedback.dataset.status=status;
        feedback.textContent=text;
        if(status==='invalid'||status==='taken')input.setAttribute('aria-invalid','true');
        else input.removeAttribute('aria-invalid');
    }
    function valid(value){
        return /^[a-z][a-z0-9_]{2,23}$/.test(value)&&!value.startsWith('lc_')&&!reserved.has(value);
    }
    async function check(value){
        if(!valid(value))return;
        controller=new AbortController();
        try{
            const response=await fetch('/api/nickname/disponibilidade?nickname='+encodeURIComponent(value),{
                method:'GET',credentials:'same-origin',cache:'no-store',
                headers:{'Accept':'application/json'},signal:controller.signal
            });
            if(!response.ok&&response.status!==429)throw new Error('network');
            const body=await response.json();
            // Ignore late responses for older input values, including after tab navigation.
            if(normalize(input.value)!==value)return;
            if(['available','taken','invalid','limit'].includes(body.status)){
                lastChecked=value;
                message(body.status,String(body.message||''));
            }else throw new Error('response');
        }catch(err){
            if(err.name==='AbortError'||normalize(input.value)!==value)return;
            lastChecked='';
            message('unknown','Não foi possível consultar agora. Vamos conferir ao enviar o cadastro.');
        }
    }
    function changed(){
        if(timeout!==null)clearTimeout(timeout);
        timeout=null;
        if(controller)controller.abort();
        controller=null;
        lastChecked='';
        const value=normalize(input.value);
        if(value===''){
            message('empty','Nickname obrigatório. Escolha um nome público.');
            return;
        }
        if(!valid(value)){
            message('invalid','Use 3 a 24 caracteres, comece com uma letra; sem espaços ou nomes reservados.');
            return;
        }
        message('checking','Consultando disponibilidade...');
        timeout=setTimeout(()=>{timeout=null;check(value);},400);
    }
    input.addEventListener('input',changed);
    input.addEventListener('blur',()=>{
        const val=normalize(input.value);
        if(input.value!==val && valid(val))input.value=val;
    });
    form.addEventListener('submit',e=>{
        const value=normalize(input.value);
        if(!value||!valid(value)){
            e.preventDefault();
            changed();
            input.focus();
            return;
        }
        if(value===lastChecked && (lastStatus==='taken'||lastStatus==='invalid'||lastStatus==='limit')){
            e.preventDefault();
            input.focus();
            return;
        }
        // Availability is advisory only. On submit, the PHP validation and
        // UNIQUE index enforce global uniqueness, including any race conditions.
    });
})();
