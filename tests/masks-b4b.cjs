'use strict';
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const assert = require('node:assert/strict');

class FakeInput {
  constructor(name, value='', dataset={}) {
    this.name=name;this.value=value;this.dataset=dataset;
  }
}
class FakeForm {
  constructor(inputs){this.inputs=inputs;}
  querySelectorAll(){return this.inputs;}
}
const input=(name,value='',dataset={})=>new FakeInput(name,value,dataset);
const fields=[
  input('monthly_price','299,90'),
  input('fixed_value','1.234,56'),
  input('payment_per_video','15,00'),
  input('bonus_amount','100,00',{lcMask:'money'}),
  input('amount','35,00'),
  input('percent_value','12,50'),
  input('whatsapp','(85) 99999-9999'),
  input('cpf','123.456.789-09'),
  input('password','123456789')
];
const handlers={};
const fakeDocument={
  addEventListener(name,handler){handlers[name]=handler;},
  querySelectorAll(){return fields;}
};
const source=fs.readFileSync(path.join(__dirname,'../public/assets/input-masks.js'),'utf8');
vm.runInNewContext(source,{document:fakeDocument,HTMLInputElement:FakeInput,HTMLFormElement:FakeForm,Intl,Number,String});
for(let i=0;i<5;i++){
  assert.match(fields[i].value.replace(/\s/g,' '),/^R\$\s/,fields[i].name+' should show BRL before submit');
}
assert.equal(fields[1].value.replace(/\s/g,' '),'R$ 1.234,56','Brazilian thousands and decimal formatting');
assert.equal(fields[5].value,'12,50','percentage must not become BRL');
assert.equal(fields[8].value,'123456789','password unchanged');
handlers.submit({target:new FakeForm(fields)});
assert.deepEqual(fields.slice(0,5).map(x=>x.value),['299,90','1234,56','15,00','100,00','35,00']);
assert.equal(fields[5].value,'12,50','percentage remains formatted as percentage number');
assert.equal(fields[6].value,'85999999999','whatsapp server receives only digits');
assert.equal(fields[7].value,'12345678909','CPF server receives only digits');
const field=input('fixed_value','',{lcMask:'money'});
field.value='100500';handlers.input({target:field});
assert.equal(field.value.replace(/\s/g,' '),'R$ 1.005,00','money typed into dynamic modal is masked');
const perc=input('percent_value','12,50');
handlers.input({target:perc});
assert.equal(perc.value,'12,50');
const newForm=new FakeForm([field,perc]);
handlers.submit({target:newForm});
assert.equal(field.value,'1005,00','dynamic form sends BRL cents');
assert.equal(perc.value,'12,50','dynamic percentage remains unchanged');
console.log('B4B_MASKS_OK');
