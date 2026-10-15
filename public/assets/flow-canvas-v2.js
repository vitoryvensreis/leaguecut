/* LeagueCut canvas v2: visual graph editing with pan/zoom and fan-out wiring. */
(() => {
'use strict';
const root=document.querySelector('[data-flow-editor]');
if(!root)return;
const graph=JSON.parse(document.getElementById('lc-flow-initial').textContent);
const catalog=JSON.parse(document.getElementById('lc-flow-catalog').textContent);
const scroller=document.getElementById('lc-flow-scroller');
const canvas=document.getElementById('lc-flow-canvas');
const wires=document.getElementById('lc-flow-edges');
wires.setAttribute('width','10000');
wires.setAttribute('height','10000');
const nodesLayer=document.getElementById('lc-flow-nodes');
const inspector=document.getElementById('lc-flow-inspector-body');
const inspectorTitle=document.getElementById('lc-flow-inspector-title');
const dirty=document.getElementById('lc-flow-dirty');
const info=document.getElementById('lc-flow-connect-hint');
const form=document.getElementById('lc-flow-form');
const maxNodes=60,maxEdges=160;
const types={trigger:'Gatilho',condition:'Condição',whatsapp:'WhatsApp',email:'E-mail',in_app:'Notificação interna',end:'Fim'};
const actionTypes=['whatsapp','email','in_app'];
const CARD_W=245;
const NODE_OUT_Y={next:104,true:102,false:139};
const NS='http://www.w3.org/2000/svg';
let view=graph.viewport&&typeof graph.viewport==='object'?graph.viewport:{x:10,y:20,zoom:1};
view={x:Number(view.x)||0,y:Number(view.y)||0,zoom:Math.max(.3,Math.min(2.5,Number(view.zoom)||1))};
graph.viewport=view;
let selected=graph.nodes[0]?.id||null,mode=null,armed=null,menu=null,hoveredNode=null;
let seq=0,dragged=false;
root.setAttribute('data-flow-v2','');
const el=(tag,cls='',txt)=>{const x=document.createElement(tag);x.className=cls;if(txt!==undefined)x.textContent=txt;return x;};
const svgEl=(tag,cls)=>{const x=document.createElementNS(NS,tag);if(cls)x.setAttribute('class',cls);return x;};
const node=id=>graph.nodes.find(n=>n.id===id);
const note=t=>{info.textContent=t;};
const mark=()=>{dirty.textContent='Alterações não salvas';};
const options=(select,entries,value)=>{for(const [k,label] of Object.entries(entries)){const o=el('option','',label);o.value=k;o.selected=k===value;select.append(o);}};
const clamp=(v,min,max)=>Math.max(min,Math.min(max,v));
const local=e=>{const r=scroller.getBoundingClientRect();return{x:e.clientX-r.left,y:e.clientY-r.top};};
const world=p=>({x:(p.x-view.x)/view.zoom,y:(p.y-view.y)/view.zoom});
const onCanvas=p=>p.x>=0&&p.y>=0&&p.x<=scroller.clientWidth&&p.y<=scroller.clientHeight;
const updateView=()=>{canvas.style.transform='translate('+view.x+'px,'+view.y+'px) scale('+view.zoom+')';graph.viewport={...view};const z=document.getElementById('lc-flow-zoom-level');if(z)z.textContent=Math.round(view.zoom*100)+'%';};
const pathFor=(x1,y1,x2,y2)=>{
 const k=Math.max(58,Math.min(230,Math.abs(x2-x1)*.47));
 return 'M'+x1+' '+y1+' C'+(x1+k)+' '+y1+' '+(x2-k)+' '+y2+' '+x2+' '+y2;
};
function reachable(start,goal){
 const todo=[start],visited=new Set();
 while(todo.length){const n=todo.pop();if(n===goal)return true;if(visited.has(n))continue;visited.add(n);
 for(const e of graph.edges)if(e.from===n)todo.push(e.to);}
 return false;
}
function wire(from,to,port){
 const src=node(from),dst=node(to);
 if(!src||!dst||src.id===dst.id||src.type==='end'||dst.type==='trigger'){
   note('Escolha um destino compatível.');return false;
 }
 if(graph.edges.some(e=>e.from===from&&e.to===to&&e.port===port)){
   note('Esses cards já estão conectados nessa saída.');return false;
 }
 if(graph.edges.length>=maxEdges){note('Limite de '+maxEdges+' conexões atingido.');return false;}
 if(graph.edges.filter(e=>e.from===from&&e.port===port).length>=20){
   note('Esta saída já possui 20 destinos.');return false;
 }
 if(reachable(to,from)){note('Não é possível criar ciclos nas automações.');return false;}
 graph.edges.push({from,to,port});
 mark();drawWires();updateCounts();
 note('Conexão salva no editor. A mesma saída pode alimentar outros cards.');
 return true;
}
function updateCounts(){
 document.getElementById('lc-flow-node-count').textContent=graph.nodes.length;
 document.getElementById('lc-flow-edge-count').textContent=graph.edges.length;
 const first=graph.nodes.find(t=>t.type==='trigger');
 // Only for visual state; real execution validates reachability on the server.
 for(const card of nodesLayer.querySelectorAll('[data-node-id]')){
   const n=node(card.dataset.nodeId);
   card.classList.toggle('lc-v2-unconnected',!!n&&n.type!=='trigger'&&!reachable(first?.id,n.id));
 }
}
function drawWires(preview=null){
 wires.replaceChildren();
 for(const edge of graph.edges){
   const a=node(edge.from),b=node(edge.to);if(!a||!b)continue;
   const color='lc-flow-line-'+edge.port;
   const p=svgEl('path','lc-flow-line '+color);
   p.setAttribute('d',pathFor(a.x+CARD_W,a.y+(NODE_OUT_Y[edge.port]||104),b.x,b.y+64));
   wires.append(p);
   // Clicking the generous invisible hit area removes an existing connection.
   const hit=svgEl('path','lc-v2-wire-hit');
   hit.setAttribute('d',p.getAttribute('d'));
   hit.addEventListener('click',e=>{
     e.stopPropagation();
     if(window.confirm('Remover esta conexão?')){
       graph.edges=graph.edges.filter(other=>other!==edge);
       mark();drawWires();updateCounts();renderInspector();
     }
   });
   wires.append(hit);
 }
 if(preview){
   const a=node(preview.from);
   if(a){
     const p=svgEl('path','lc-flow-line lc-flow-line-'+preview.port+' lc-v2-wire-preview');
     p.setAttribute('d',pathFor(a.x+CARD_W,a.y+(NODE_OUT_Y[preview.port]||104),preview.x,preview.y));
     wires.append(p);
   }
 }
}
function menuClose(){if(menu){menu.remove();menu=null;}}
function addNode(type,location){
 if(graph.nodes.length>=maxNodes){note('Limite de '+maxNodes+' cards atingido.');return null;}
 const at=location||world({x:scroller.clientWidth*.5,y:scroller.clientHeight*.45});
 const id='b'+Date.now().toString(36)+(++seq).toString(36);
 const data=type==='condition'?{field:'always',value:''}:
  actionTypes.includes(type)?{recipient:'user',message:'Olá, {nome}! Novidades da LeagueCut: {link}'}:{};
 const n={id,type,x:Math.round(clamp(at.x,-4900,4700)),y:Math.round(clamp(at.y,-4900,4700)),data};
 graph.nodes.push(n);selected=id;mark();render();
 return n;
}
function createMenu(localPoint,from,port){
 menuClose();
 menu=el('div','lc-v2-add-menu');
 menu.setAttribute('role','menu');
 menu.append(el('strong','','Criar card '+(from?'conectado':'livre')));
 const items=[['condition','Condição Sim / Não'],['whatsapp','Enviar WhatsApp'],['email','Enviar e-mail'],['in_app','Notificação interna'],['end','Fim do caminho']];
 for(const [type,label] of items){
   const b=el('button','lc-v2-add-choice',label);b.type='button';
   b.addEventListener('click',()=>{
     const created=addNode(type,world(localPoint));
     if(created&&from)wire(from,created.id,port);
     menuClose();render();
     note(created?(from?'Card criado e conectado.':'Card livre criado: somente será executado após conectar ao gatilho.'):'Não foi possível criar card.');
   });
   menu.append(b);
 }
 const cancel=el('button','lc-v2-add-choice lc-v2-cancel','Cancelar');
 cancel.type='button';cancel.addEventListener('click',menuClose);menu.append(cancel);
 scroller.append(menu);
 menu.style.left=clamp(localPoint.x,8,Math.max(8,scroller.clientWidth-238))+'px';
 menu.style.top=clamp(localPoint.y,8,Math.max(8,scroller.clientHeight-290))+'px';
 menu.querySelector('button')?.focus();
}
function choose(id){selected=id;for(const card of nodesLayer.children)card.classList.toggle('is-selected',card.dataset.nodeId===id);renderInspector();}
function makePort(n,port,name){
 const b=el('button','lc-v2-output lc-v2-output-'+port,'');
 b.type='button';b.dataset.port=port;
 b.title='Arraste para conectar '+name+' a vários cards; solte no vazio para criar outro';
 b.setAttribute('aria-label','Arrastar saída '+name+' do bloco '+types[n.type]);
 b.addEventListener('pointerdown',e=>{
   if(e.button!==0)return;
   e.preventDefault();e.stopPropagation();menuClose();
   const p=local(e);
   mode={kind:'link',id:n.id,port,start:p,point:p,moved:false};
   armed=null;selected=n.id;choose(n.id);
   note('Arraste o fio até outro card, ou solte no vazio para criar um card conectado.');
 });
 return b;
}
function renderNodes(){
 nodesLayer.replaceChildren();
 for(const n of graph.nodes){
   const card=el('div','lc-flow-node lc-flow-node-'+n.type+' lc-v2-node');
   card.style.left=n.x+'px';card.style.top=n.y+'px';
   card.dataset.nodeId=n.id;
   if(n.id===selected)card.classList.add('is-selected');
   const inlet=el('span','lc-v2-inlet');
   inlet.title='Entrada de conexão';
   const head=el('div','lc-flow-node-head');
   head.append(el('span','lc-flow-node-icon',n.type==='trigger'?'◎':n.type==='condition'?'◇':n.type==='end'?'◉':'▣'),
     el('span','lc-flow-node-kicker',n.type==='trigger'?'GATILHO':n.type==='condition'?'CONDIÇÃO':n.type==='end'?'FIM':'AÇÃO'));
   const body=el('div','lc-flow-node-body');
   const caption=n.type==='trigger'?(catalog.triggers[n.data.trigger]||'Gatilho'):
     n.type==='condition'?((catalog.conditions[n.data.field]||'Condição')+(n.data.value?' = '+n.data.value:'')):types[n.type];
   body.append(el('strong','lc-flow-node-title',caption));
   if(actionTypes.includes(n.type)){
     const small=el('small','',n.data.recipient==='admins'?'Para administradores':'Para o usuário');
     body.append(small);
   }
   const ports=el('div','lc-v2-ports');
   if(n.type==='condition'){
     ports.append(makePort(n,'true','Sim'),makePort(n,'false','Não'));
   }else if(n.type!=='end')ports.append(makePort(n,'next','Próximo'));
   card.append(inlet,head,body,ports);
   head.addEventListener('pointerdown',e=>{
     if(e.button!==0||armed)return;
     e.stopPropagation();e.preventDefault();menuClose();
     const p=local(e);
     mode={kind:'move',id:n.id,start:p,x:n.x,y:n.y,moved:false};
     choose(n.id);
   });
   card.addEventListener('click',e=>{
     if(e.target.closest('button'))return;
     if(dragged){dragged=false;return;}
     if(armed){
       const copy=armed;armed=null;
       if(wire(copy.id,n.id,copy.port))render();
       return;
     }
     choose(n.id);
   });
   nodesLayer.append(card);
 }
 updateCounts();
}
function field(label,control){
 const w=el('label','lc-flow-field');w.append(el('span','',label),control);inspector.append(w);return w;
}
function renderInspector(){
 inspector.replaceChildren();const n=node(selected);
 if(!n){inspectorTitle.textContent='Selecione um card';return;}
 inspectorTitle.textContent=types[n.type];
 const hint=el('p','lc-flow-inspector-help');
 if(n.type==='trigger'){
   hint.textContent='Gatilho inicial. Pode conectar várias ações ou condições ao mesmo tempo.';
   const select=el('select');options(select,catalog.triggers,n.data.trigger);
   select.addEventListener('change',()=>{n.data.trigger=select.value;mark();render();});
   field('Quando acontecer',select);
 }else if(n.type==='condition'){
   hint.textContent='Cada saída (Sim / Não) pode alimentar múltiplos cards.';
   const select=el('select');options(select,catalog.conditions,n.data.field);
   select.addEventListener('change',()=>{n.data.field=select.value;mark();render();});
   field('Campo',select);
   const input=el('input');input.value=n.data.value||'';input.maxLength=160;
   input.placeholder='Ex.: approved, clipador, 3';
   input.addEventListener('input',()=>{n.data.value=input.value;mark();});
   input.addEventListener('change',renderNodes);
   field('Igual a (exceto Sempre)',input);
 }else if(actionTypes.includes(n.type)){
   hint.textContent='Uma ação pode disparar outras ações em paralelo.';
   const select=el('select');options(select,catalog.actions,n.type);
   select.addEventListener('change',()=>{n.type=select.value;mark();render();});
   field('Tipo de ação',select);
   const who=el('select');options(who,{user:'Usuário do evento',admins:'Administradores'},n.data.recipient);
   who.addEventListener('change',()=>{n.data.recipient=who.value;mark();renderNodes();});
   field('Destinatário',who);
   const message=el('textarea');message.rows=5;message.maxLength=1800;message.value=n.data.message||'';
   message.addEventListener('input',()=>{n.data.message=message.value;mark();});
   field('Mensagem',message);
   inspector.append(el('small','lc-flow-inspector-help','Variáveis: {nome}, {nome_completo}, {titulo}, {campanha}, {status}, {link}.'));
 }else hint.textContent='Este bloco finaliza o caminho correspondente.';
 inspector.prepend(hint);
 if(n.type!=='trigger'){
   const del=el('button','btn btn-ghost btn-sm','Excluir card');
   del.type='button';
   del.addEventListener('click',()=>{
     if(!window.confirm('Excluir card e todas as conexões ligadas a ele?'))return;
     graph.nodes=graph.nodes.filter(x=>x.id!==n.id);
     graph.edges=graph.edges.filter(e=>e.from!==n.id&&e.to!==n.id);
     selected=graph.nodes[0]?.id||null;mark();render();
   });
   inspector.append(del);
 }
 const incoming=graph.edges.filter(e=>e.to===n.id);
 if(incoming.length){
   inspector.append(el('h3','','Conexões recebidas ('+incoming.length+')'));
   for(const e of incoming){
     const parent=node(e.from);
     const button=el('button','btn btn-ghost btn-sm','Remover ligação: '+types[parent?.type]+' / '+(e.port==='next'?'Próximo':e.port==='true'?'Sim':'Não'));
     button.type='button';
     button.addEventListener('click',()=>{graph.edges=graph.edges.filter(x=>x!==e);mark();render();});
     inspector.append(button);
   }
 }
 const outgoing=graph.edges.filter(e=>e.from===n.id);
 if(outgoing.length)inspector.append(el('small','lc-flow-inspector-help',outgoing.length+' conexões de saída. Cada destino alcançado executa independentemente.'));
}
function render(){renderNodes();renderInspector();drawWires();updateView();}
function zoomAt(next,point){
 next=clamp(next,.3,2.5);
 const anchored=world(point);
 view.zoom=next;view.x=point.x-anchored.x*next;view.y=point.y-anchored.y*next;
 mark();updateView();
}
function fit(){
 if(!graph.nodes.length)return;
 const minX=Math.min(...graph.nodes.map(n=>n.x));
 const maxX=Math.max(...graph.nodes.map(n=>n.x+CARD_W));
 const minY=Math.min(...graph.nodes.map(n=>n.y));
 const maxY=Math.max(...graph.nodes.map(n=>n.y+164));
 const width=maxX-minX+100,height=maxY-minY+110;
 const zoom=clamp(Math.min(scroller.clientWidth/width,scroller.clientHeight/height),.3,1.35);
 view.zoom=zoom;
 view.x=(scroller.clientWidth-(maxX+minX)*zoom)/2;
 view.y=(scroller.clientHeight-(maxY+minY+164)*zoom)/2;
 mark();updateView();
}
const controls=el('div','lc-v2-zoom');
for(const [id,label,action] of [
  ['lc-v2-zoom-in','+ Zoom',()=>zoomAt(view.zoom*1.15,{x:scroller.clientWidth/2,y:scroller.clientHeight/2})],
  ['lc-v2-zoom-out','− Zoom',()=>zoomAt(view.zoom/1.15,{x:scroller.clientWidth/2,y:scroller.clientHeight/2})],
  ['lc-v2-zoom-level','100%',()=>fit()] ]){
 const b=el('button','lc-v2-zoom-button',label);b.id=id;b.type='button';b.addEventListener('click',action);controls.append(b);
}
scroller.append(controls);
scroller.addEventListener('wheel',e=>{
 if(menu)return;
 e.preventDefault();
 zoomAt(view.zoom*(e.deltaY<0?1.11:1/1.11),local(e));
},{passive:false});
scroller.addEventListener('pointerdown',e=>{
 if(e.button!==0||mode||e.target.closest('.lc-v2-node,.lc-v2-add-menu,.lc-v2-zoom'))return;
 menuClose();armed=null;const p=local(e);
 mode={kind:'pan',start:p,x:view.x,y:view.y,moved:false};scroller.classList.add('lc-v2-panning');
});
window.addEventListener('pointermove',e=>{
 if(!mode)return;
 const p=local(e),dx=p.x-mode.start.x,dy=p.y-mode.start.y;
 if(Math.abs(dx)+Math.abs(dy)>4)mode.moved=true;
 if(mode.kind==='pan'){
   view.x=mode.x+dx;view.y=mode.y+dy;updateView();
 }else if(mode.kind==='move'){
   const n=node(mode.id);
   if(n){n.x=Math.round(clamp(mode.x+dx/view.zoom,-4900,4700));
     n.y=Math.round(clamp(mode.y+dy/view.zoom,-4900,4700));
     const card=nodesLayer.querySelector('[data-node-id="'+n.id+'"]');
     if(card){card.style.left=n.x+'px';card.style.top=n.y+'px';}
     drawWires();
   }
 }else if(mode.kind==='link'){
   mode.point=p;
   const pos=world(p);
   drawWires({from:mode.id,port:mode.port,x:pos.x,y:pos.y});
   const target=document.elementFromPoint(e.clientX,e.clientY)?.closest('.lc-v2-node');
   const targetId=target?.dataset.nodeId;
   if(hoveredNode!==targetId){
      for(const c of nodesLayer.children)c.classList.toggle('lc-v2-drop-target',c.dataset.nodeId===targetId);
      hoveredNode=targetId;
   }
 }
});
window.addEventListener('pointerup',e=>{
 if(!mode)return;
 const finished=mode;mode=null;
 const p=local(e);
 if(finished.kind==='pan'){
   scroller.classList.remove('lc-v2-panning');
   if(finished.moved)mark();
   else{selected=null;renderInspector();}
 }else if(finished.kind==='move'){
   if(finished.moved){mark();dragged=true;setTimeout(()=>{dragged=false;},0);}
 }else if(finished.kind==='link'){
   const target=document.elementFromPoint(e.clientX,e.clientY)?.closest('.lc-v2-node');
   for(const c of nodesLayer.children)c.classList.remove('lc-v2-drop-target');hoveredNode=null;
   if(target){
     armed=null;wire(finished.id,target.dataset.nodeId,finished.port);
   }else if(finished.moved&&onCanvas(p)){
     armed=null;createMenu(p,finished.id,finished.port);
   }else if(!finished.moved){
     armed={id:finished.id,port:finished.port};
     note('Saída selecionada. Clique em um card de destino ou arraste a saída para criar outro.');
   }else armed=null;
 }
 drawWires();updateCounts();
});
window.addEventListener('pointercancel',()=>{
 mode=null;scroller.classList.remove('lc-v2-panning');drawWires();
});
document.addEventListener('keydown',e=>{
 if(e.key==='Escape'){armed=null;menuClose();note('Operação cancelada.');}
});
document.getElementById('lc-flow-add-condition').addEventListener('click',()=>addNode('condition'));
document.getElementById('lc-flow-add-action').addEventListener('click',()=>addNode('whatsapp'));
document.getElementById('lc-flow-add-end').addEventListener('click',()=>addNode('end'));
document.getElementById('lc-flow-fit').addEventListener('click',fit);
const titleInput=document.getElementById('lc-flow-title');
titleInput.addEventListener('input',mark);
document.getElementById('lc-flow-save').addEventListener('click',()=>{
 const name=titleInput.value.trim();
 if(name.length<3||name.length>140){alert('Informe um nome com 3 a 140 caracteres.');return;}
 if(!graph.nodes.some(n=>n.type==='trigger')){alert('O fluxo precisa de um gatilho.');return;}
 const nodes=new Set(graph.nodes.map(n=>n.id));
 if(graph.edges.some(e=>!nodes.has(e.from)||!nodes.has(e.to))){alert('Existem conexões inválidas.');return;}
 document.getElementById('lc-flow-form-name').value=name;
 document.getElementById('lc-flow-form-json').value=JSON.stringify(graph);
 dirty.textContent='Salvando...';
 form.submit();
});
window.addEventListener('beforeunload',e=>{
 if(dirty.textContent==='Alterações não salvas')e.preventDefault();
});
form.addEventListener('submit',()=>{dirty.textContent='Salvando...';});
render();
note('Arraste a área vazia para mover o canvas. Use a roda do mouse para zoom; arraste uma saída para vários cards ou solte no vazio para criar.');
})();
