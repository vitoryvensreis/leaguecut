(()=>{
'use strict';
const phone=d=>{
  if(d.startsWith('55')&&d.length>11){
    const local=d.slice(2,13),ddd=local.slice(0,2),num=local.slice(2);
    return '+55 ('+ddd+') '+(num.length>8?num.slice(0,5)+(num.length>5?'-'+num.slice(5):''):num.slice(0,4)+(num.length>4?'-'+num.slice(4):''));
  }
  d=d.slice(0,11);
  if(d.length<3)return d;
  const n=d.slice(2);
  return '('+d.slice(0,2)+') '+(n.length>8?n.slice(0,5)+(n.length>5?'-'+n.slice(5):''):n.slice(0,4)+(n.length>4?'-'+n.slice(4):''));
};
const cpf=d=>{
 d=d.slice(0,11);
 if(d.length<=3)return d;
 if(d.length<=6)return d.slice(0,3)+'.'+d.slice(3);
 if(d.length<=9)return d.slice(0,3)+'.'+d.slice(3,6)+'.'+d.slice(6);
 return d.slice(0,3)+'.'+d.slice(3,6)+'.'+d.slice(6,9)+'-'+d.slice(9);
};
function typeOf(input){
 if(!(input instanceof HTMLInputElement)||!input.name)return null;
 const kind=input.dataset.lcMask;
 if(kind==='phone'||kind==='cpf')return kind;
 if(/^(whatsapp|telefone|phone|cellphone|mobile)$/i.test(input.name))return 'phone';
 if(/^(cpf|document_cpf)$/i.test(input.name))return 'cpf';
 return null;
}
function decorate(input){
 const type=typeOf(input);
 if(!type)return;
 input.inputMode='numeric';
 const digits=(input.value||'').replace(/\D/g,'');
 const formatted=type==='cpf'?cpf(digits):phone(digits);
 if(input.value!==formatted)input.value=formatted;
}
// Event delegation supports dynamically opened signup/login forms.
document.addEventListener('input',event=>{
 const target=event.target;
 if(!(target instanceof HTMLInputElement))return;
 const type=typeOf(target);
 if(type==='phone'||type==='cpf')decorate(target);
});
document.querySelectorAll('input[name]').forEach(decorate);
// Server-side CPF/phone validation receives digits. Visual masks are display only.
document.addEventListener('submit',event=>{
 const form=event.target;
 if(!(form instanceof HTMLFormElement))return;
 form.querySelectorAll('input[name]').forEach(input=>{
   if(typeOf(input))input.value=input.value.replace(/\D/g,'');
 });
},true);
})();