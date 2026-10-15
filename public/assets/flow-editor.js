(() => {
'use strict';
const editor=document.querySelector('[data-flow-editor]');
const make=(tag,classes,text)=>{const el=document.createElement(tag);if(classes)el.className=classes;if(text!==undefined)el.textContent=text;return el;};
const csrf=()=>document.querySelector('meta[name="lc-csrf"]')?.content||'';
const parse=(id)=>JSON.parse(document.getElementById(id).textContent);
const opt=(select,options,value)=>{Object.entries(options).forEach(([k,label])=>{const o=make('option','',label);o.value=k;o.selected=k===value;select.append(o);});};
if(editor){
 let graph=parse('lc-flow-initial');const catalog=parse('lc-flow-catalog');
 const canvas=document.getElementById('lc-flow-canvas'), nodesEl=document.getElementById('lc-flow-nodes'),
 svg=document.getElementById('lc-flow-edges'), scroll=document.getElementById('lc-flow-scroller');
 const inspector=document.getElementById('lc-flow-inspector-body'), dirty=document.getElementById('lc-flow-dirty');
 let selected=graph.nodes[0]?.id??null,linkFrom=null,linkPort='next',seq=1;
 const types={trigger:'Gatilho',condition:'Condição',whatsapp:'WhatsApp',email:'E-mail',in_app:'Notificação interna',end:'Fim'};
 const labels={trigger:'GATILHO',condition:'CONDIÇÃO',whatsapp:'AÇÃO',email:'AÇÃO',in_app:'AÇÃO',end:'FIM'};
 const changed=()=>{dirty.textContent='Alterações não salvas';};
 const nodeById=(id)=>graph.nodes.find(n=>n.id===id);
 const incoming=(id)=>graph.edges.filter(e=>e.to===id);
 const nodeForm=(label,control)=>{const wrap=make('label','lc-flow-field');wrap.append(make('span','',label),control);inspector.append(wrap);return wrap;};
 const info=(text)=>document.getElementById('lc-flow-connect-hint').textContent=text;
 const pick=(id)=>{selected=id;render();};
 const setNode=(id,key,value)=>{const n=nodeById(id);if(n){n.data[key]=value;changed();render();}};
 const addNode=(type)=>{
   const id='bloco'+Date.now().toString(36)+seq++;
   const last=graph.nodes.at(-1);const x=Math.min(4250,Math.max(70,(last?.x||0)+280));
   const node={id,type,x,y:Math.min(4450,190+(seq%4)*115),
      data:type==='condition'?{field:'always',value:''}:
       ['whatsapp','email','in_app'].includes(type)?{recipient:'user',message:'Olá, {nome}! Uma novidade na LeagueCut. {link}'}:{}};
   graph.nodes.push(node);selected=id;changed();render();scroll.scrollLeft=Math.max(0,x-100);info('Novo bloco criado. Clique na saída de outro bloco e depois neste bloco para conectá-lo.');
 };
 const connect=(to)=>{
   if(!linkFrom)return;
   if(to===linkFrom||nodeById(to)?.type==='trigger'){info('Escolha um bloco de destino diferente do gatilho.');return;}
   graph.edges=graph.edges.filter(e=>!(e.from===linkFrom&&e.port===linkPort));
   graph.edges.push({from:linkFrom,to,port:linkPort});linkFrom=null;changed();render();info('Conexão criada. Salve o fluxo para validar todas as saídas.');
 };
 const drawEdges=()=>{
    svg.replaceChildren();
    const ns='http://www.w3.org/2000/svg';
    for(const e of graph.edges){
      const a=nodeById(e.from),b=nodeById(e.to);if(!a||!b)continue;
      const ax=a.x+245,ay=a.y+(e.port==='true'?112:e.port==='false'?146:103);
      const bx=b.x,by=b.y+68,half=Math.max(75,Math.abs(bx-ax)*.48);
      const path=document.createElementNS(ns,'path');
      path.setAttribute('d','M '+ax+' '+ay+' C '+(ax+half)+' '+ay+' '+(bx-half)+' '+by+' '+bx+' '+by);
      path.setAttribute('class','lc-flow-line lc-flow-line-'+e.port);
      svg.append(path);
    }
 };
 const portButton=(node,port,title)=>{
    const b=make('button','lc-flow-port','⊕ '+title);
    b.type='button';b.setAttribute('aria-label','Conectar saída '+title+' do bloco '+types[node.type]);
    if(linkFrom===node.id&&linkPort===port)b.classList.add('is-linking');
    b.addEventListener('click',(ev)=>{ev.stopPropagation();linkFrom=node.id;linkPort=port;render();
      info('Saída '+title+' selecionada. Clique no bloco de destino para criar a conexão.');});
    return b;
 };
 const renderNodes=()=>{
    nodesEl.replaceChildren();
    for(const n of graph.nodes){
      const card=make('div','lc-flow-node lc-flow-node-'+n.type);
      if(n.id===selected)card.classList.add('is-selected');
      card.style.left=n.x+'px';card.style.top=n.y+'px';
      card.dataset.nodeId=n.id;
      const head=make('div','lc-flow-node-head');
      head.append(make('span','lc-flow-node-icon',n.type==='trigger'?'◎':n.type==='condition'?'◇':n.type==='end'?'◉':'▣'),
        make('span','lc-flow-node-kicker',labels[n.type]));
      const title=make('strong','lc-flow-node-title',n.type==='trigger'?(catalog.triggers[n.data.trigger]||'Escolha o gatilho'):
         n.type==='condition'?((catalog.conditions[n.data.field]||'Condição')+(n.data.value?' = '+n.data.value:'')):
         types[n.type]);
      const body=make('div','lc-flow-node-body');body.append(title);
      if(['whatsapp','email','in_app'].includes(n.type))body.append(make('small','',n.data.recipient==='admins'?'Para administradores':'Para o usuário do evento'));
      const foot=make('div','lc-flow-node-ports');
      if(n.type==='condition'){foot.append(portButton(n,'true','Sim'),portButton(n,'false','Não'));}
      else if(n.type!=='end')foot.append(portButton(n,'next','Próximo'));
      card.append(head,body,foot);
      card.addEventListener('click',(e)=>{if(linkFrom){connect(n.id);}else if(selected!==n.id){pick(n.id);}});
      let drag=null;
      head.addEventListener('pointerdown',(ev)=>{
        if(ev.button!==0||linkFrom)return;
        ev.preventDefault();
        drag={x:ev.clientX,y:ev.clientY,nx:n.x,ny:n.y,moved:false};
        head.setPointerCapture(ev.pointerId);
        selected=n.id;renderInspector();
      });
      head.addEventListener('pointermove',(ev)=>{
        if(!drag)return;
        const dx=ev.clientX-drag.x,dy=ev.clientY-drag.y;
        if(Math.abs(dx)+Math.abs(dy)>2)drag.moved=true;
        n.x=Math.max(0,Math.min(4700,Math.round(drag.nx+dx)));
        n.y=Math.max(0,Math.min(4700,Math.round(drag.ny+dy)));
        card.style.left=n.x+'px';card.style.top=n.y+'px';drawEdges();
      });
      head.addEventListener('pointerup',()=>{const wasMoved=!!drag?.moved;if(wasMoved)changed();drag=null;if(wasMoved)render();});
      head.addEventListener('pointercancel',()=>{drag=null;render();});
      nodesEl.append(card);
    }
    drawEdges();
    document.getElementById('lc-flow-node-count').textContent=String(graph.nodes.length);
    document.getElementById('lc-flow-edge-count').textContent=String(graph.edges.length);
 };
 const renderInspector=()=>{
    inspector.replaceChildren();
    const n=nodeById(selected);const title=document.getElementById('lc-flow-inspector-title');
    if(!n){title.textContent='Selecione um bloco';return;}
    title.textContent=types[n.type];
    const tip=make('p','lc-flow-inspector-help');
    if(n.type==='trigger'){
      tip.textContent='Executa o fluxo quando um evento da LeagueCut acontecer.';
      const s=make('select');opt(s,catalog.triggers,n.data.trigger);s.addEventListener('change',()=>setNode(n.id,'trigger',s.value));nodeForm('Quando acontecer',s);
    }else if(n.type==='condition'){
      tip.textContent='Define qual caminho o fluxo segue: Sim ou Não.';
      const s=make('select');opt(s,catalog.conditions,n.data.field);
      s.addEventListener('change',()=>setNode(n.id,'field',s.value));nodeForm('Campo',s);
      const input=make('input');input.type='text';input.maxLength=160;input.value=n.data.value||'';
      input.placeholder='Ex.: approved, clipador, 3';
      input.addEventListener('input',()=>{n.data.value=input.value;changed();});
      nodeForm('Igual a (exceto Sempre)',input);
    }else if(['whatsapp','email','in_app'].includes(n.type)){
      tip.textContent='Personalize a mensagem e escolha quem recebe a ação.';
      const s=make('select');opt(s,catalog.actions,n.type);
      s.addEventListener('change',()=>{n.type=s.value;changed();render();});nodeForm('Tipo de ação',s);
      const who=make('select');opt(who,{user:'Usuário do evento',admins:'Administradores'},n.data.recipient);
      who.addEventListener('change',()=>setNode(n.id,'recipient',who.value));nodeForm('Destinatário',who);
      const area=make('textarea');area.rows=5;area.maxLength=1800;area.value=n.data.message||'';
      area.addEventListener('input',()=>{n.data.message=area.value;changed();});nodeForm('Mensagem',area);
      inspector.append(make('small','lc-flow-inspector-help','Variáveis: {nome}, {nome_completo}, {titulo}, {campanha}, {status}, {link}.'));
    }else tip.textContent='Encerra este caminho do fluxo. Nenhuma outra ação será executada.';
    inspector.prepend(tip);
    if(n.type!=='trigger'){
      const del=make('button','btn btn-ghost btn-sm','Excluir bloco');del.type='button';
      del.addEventListener('click',()=>{
        if(!window.confirm('Excluir bloco e suas conexões?'))return;
        graph.nodes=graph.nodes.filter(x=>x.id!==n.id);
        graph.edges=graph.edges.filter(e=>e.from!==n.id&&e.to!==n.id);
        selected=graph.nodes[0]?.id??null;changed();render();
      });inspector.append(del);
    }
    const edges=incoming(n.id);
    if(edges.length){
      const heading=make('h3','','Conexões de entrada');inspector.append(heading);
      for(const edge of edges){
        const b=make('button','btn btn-ghost btn-sm','Remover ligação de '+types[nodeById(edge.from)?.type]+' ('+edge.port+')');
        b.type='button';b.addEventListener('click',()=>{
          graph.edges=graph.edges.filter(e=>e!==edge);changed();render();
        });inspector.append(b);
      }
    }
 };
 function render(){renderNodes();renderInspector();}
 const form=document.getElementById('lc-flow-form');
 document.getElementById('lc-flow-save').addEventListener('click',()=>{
   const clean=document.getElementById('lc-flow-title').value.trim();if(clean.length<3||clean.length>140){alert('Nome deve ter de 3 a 140 caracteres.');return;}
   if(!graph.nodes.some(n=>n.type==='trigger')||!graph.nodes.some(n=>['whatsapp','email','in_app'].includes(n.type))){alert('É necessário um gatilho e ao menos uma ação.');return;}
   document.getElementById('lc-flow-form-name').value=clean;
   document.getElementById('lc-flow-form-json').value=JSON.stringify(graph);
   form.submit();
 });
 document.getElementById('lc-flow-add-condition').addEventListener('click',()=>addNode('condition'));
 document.getElementById('lc-flow-add-action').addEventListener('click',()=>addNode('whatsapp'));
 document.getElementById('lc-flow-add-end').addEventListener('click',()=>addNode('end'));
 document.getElementById('lc-flow-title').addEventListener('input',changed);
 document.getElementById('lc-flow-fit').addEventListener('click',()=>{
    scroll.scrollTo({left:Math.max(0,Math.min(...graph.nodes.map(n=>n.x))-45),top:Math.max(0,Math.min(...graph.nodes.map(n=>n.y))-65),behavior:'smooth'});
 });
 window.addEventListener('beforeunload',(e)=>{
   if(dirty.textContent==='Alterações não salvas'){e.preventDefault();}
 });
 form.addEventListener('submit',()=>{dirty.textContent='Salvando...';});
 render();
 scroll.scrollLeft=25;scroll.scrollTop=90;
}
const search=document.getElementById('lc-flow-search');
if(search)search.addEventListener('input',()=>{
 const query=search.value.trim().toLocaleLowerCase('pt-BR');
 document.querySelectorAll('[data-flow-name]').forEach(row=>row.hidden=!row.dataset.flowName.includes(query));
});
const wpp=document.querySelector('.lc-wpp-admin');
if(!wpp)return;
const statusEl=document.getElementById('wpp-state'),qrImage=document.getElementById('wpp-qr-image'),
qrInfo=document.getElementById('wpp-qr-info'),feedback=document.getElementById('wpp-feedback');
let connected=false,active=false,busy=false;
const req=async(path,post=false)=>{
 const args={credentials:'same-origin',cache:'no-store',headers:{Accept:'application/json'}};
 if(post){args.method='POST';args.headers['Content-Type']='application/x-www-form-urlencoded';
     args.body=new URLSearchParams({_csrf:csrf()}).toString();}
 const response=await fetch('/admin/whatsapp/api/'+path,args);
 const data=await response.json();
 if(!response.ok)throw new Error(data.error||'Falha na conexão');
 return data;
};
const message=(text)=>{feedback.textContent=text;};
const qr=async()=>{
 if(connected)return;
 try{
 const result=await req('qr');const value=result.qr||'';
 if(typeof value==='string'&&value.startsWith('data:image/png;base64,')&&value.length<2500000){
   qrImage.src=value;qrImage.hidden=false;qrInfo.hidden=true;
 }else if(qrImage.hidden){qrInfo.hidden=false;qrInfo.textContent='Aguardando geração do QR Code. Clique em “Conectar” se necessário.';}
 }catch(e){if(qrImage.hidden)qrInfo.textContent='Ainda não há QR Code. Clique em “Conectar” para iniciar.';}
};
const update=async()=>{
 try{
   const state=await req('status');
   connected=!!state.connected;active=!!state.enabled;
   statusEl.textContent=connected?'Conectado':'Não conectado · '+(state.status||'UNKNOWN');
   statusEl.classList.toggle('is-connected',connected);
   document.getElementById('wpp-enable').disabled=!connected||active;
   document.getElementById('wpp-pause').disabled=!active;
   if(connected){qrImage.hidden=true;qrImage.removeAttribute('src');qrInfo.hidden=false;
       qrInfo.textContent='WhatsApp conectado! '+(active?'Envios automáticos ativados.':'Ative os envios quando estiver pronto.');}
   else await qr();
 }catch(e){statusEl.textContent='API indisponível';message(e.message);}
};
const doAction=async(id,endpoint,question)=>{
 const btn=document.getElementById(id);
 btn.addEventListener('click',async()=>{
   if(busy)return;
   if(question&&!window.confirm(question))return;
   busy=true;btn.disabled=true;message('Processando solicitação...');
   try{
     await req(endpoint,true);
     message(endpoint==='start'?'Sessão iniciada. Aguarde o QR Code e escaneie pelo celular.':'Operação concluída.');
     if(endpoint==='start')await new Promise(resolve=>setTimeout(resolve,1600));
   }catch(e){message(e.message);}
   finally{busy=false;await update();}
 });
};
doAction('wpp-start','start');
doAction('wpp-close','close','Encerrar somente a sessão da LeagueCut? Os disparos serão pausados.');
doAction('wpp-enable','enable');
doAction('wpp-pause','pause');
document.getElementById('wpp-refresh').addEventListener('click',update);
update();
setInterval(()=>{if(!document.hidden&&!busy)update();},8000);
})();