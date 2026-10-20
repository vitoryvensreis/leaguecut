(() => {
  'use strict';
  const localUrls=new Map();
  const setObjectPreview=(input,img,caption)=>{
    if(!input||!img)return;
    const old=localUrls.get(input);
    if(old){URL.revokeObjectURL(old);localUrls.delete(input);}
    const file=input.files?.[0];
    if(!file){
      if(img.dataset.originalSrc)img.src=img.dataset.originalSrc;
      if(caption)caption.textContent='Selecione um arquivo para visualizar.';
      return;
    }
    if(!file.type.startsWith('image/')){
      if(caption)caption.textContent='Selecione uma imagem válida.';
      return;
    }
    const url=URL.createObjectURL(file);
    localUrls.set(input,url);
    img.hidden=false;
    img.src=url;
    if(caption)caption.textContent='Prévia local: '+file.name+' (ainda não salva)';
  };
  window.addEventListener('pagehide',()=>{localUrls.forEach(url=>URL.revokeObjectURL(url));localUrls.clear();});

  // SEO sections: retain a single form and all original backend validations.
  const editor=document.querySelector('[data-editor-root]');
  if(editor){
    const tabs=[...editor.querySelectorAll('[data-editor-tab]')];
    const panels=[...editor.querySelectorAll('[data-editor-panel]')];
    const activate=id=>{
      if(!tabs.some(tab=>tab.dataset.editorTab===id))id='previa';
      tabs.forEach(tab=>{
        const current=tab.dataset.editorTab===id;
        tab.setAttribute('aria-selected',String(current));
        tab.tabIndex=current?0:-1;
        tab.classList.toggle('is-current',current);
      });
      panels.forEach(panel=>{panel.hidden=panel.dataset.editorPanel!==id;});
    };
    editor.dataset.editorReady='true';
    activate(decodeURIComponent(location.hash.slice(1))||'previa');
    tabs.forEach((tab,index)=>{
      tab.addEventListener('click',()=>{
        const id=tab.dataset.editorTab;
        activate(id);
        history.replaceState(history.state,'','#'+id);
      });
      tab.addEventListener('keydown',e=>{
        if(!['ArrowLeft','ArrowRight','Home','End'].includes(e.key))return;
        e.preventDefault();
        const next=e.key==='Home'?0:e.key==='End'?tabs.length-1:
          (index+(e.key==='ArrowRight'?1:-1)+tabs.length)%tabs.length;
        tabs[next].click();tabs[next].focus();
      });
    });
    window.addEventListener('hashchange',()=>activate(decodeURIComponent(location.hash.slice(1))));
    // Native invalid events do not bubble; reveal the section before the browser focuses a required input.
    editor.addEventListener('invalid',e=>{
      const section=e.target.closest('[data-editor-panel]');
      if(section && section.hidden)activate(section.dataset.editorPanel);
    },true);
    for(const name of ['seo_favicon_upload','seo_social_image_upload']){
      const input=editor.querySelector('[name="'+name+'"]');
      const img=editor.querySelector('[data-preview-image="'+name+'"]');
      const caption=editor.querySelector('[data-preview-caption="'+name+'"]');
      if(input&&img){img.dataset.originalSrc=img.getAttribute('src');input.addEventListener('change',()=>setObjectPreview(input,img,caption));}
    }
  }

  // Brand preview: name, primary accent and image respond before any POST.
  const brand=document.querySelector('[data-brand-editor]');
  if(brand){
    const box=brand.querySelector('[data-brand-preview]');
    const img=brand.querySelector('[data-brand-image]');
    const title=brand.querySelector('[data-brand-text]');
    const name=brand.querySelector('[name="brand_name"]');
    const color=brand.querySelector('[name="primary_color"]');
    const url=brand.querySelector('[name="logo_url"]');
    const file=brand.querySelector('[name="logo_upload"]');
    if(img)img.dataset.originalSrc=img.getAttribute('src');
    name?.addEventListener('input',()=>{if(title)title.textContent=name.value.trim()||'LeagueCut';});
    color?.addEventListener('input',()=>{if(box&&/^#[0-9a-f]{6}$/i.test(color.value))box.style.setProperty('--brand-preview-accent',color.value);});
    file?.addEventListener('change',()=>{if(img)setObjectPreview(file,img,null);});
    url?.addEventListener('change',()=>{
      if(!img||file?.files?.length)return;
      const v=url.value.trim();
      if(/^https:\/\/[^\s]+$/i.test(v)||/^\/media\/brand\/[a-f0-9]{36}$/i.test(v))img.src=v;
      else img.src=img.dataset.originalSrc||'/assets/leaguecut-icon.svg';
    });
  }

  // Each banner is an independent preview; file is never uploaded until the user saves.
  document.querySelectorAll('form:has([data-banner-preview])').forEach(form=>{
    const preview=form.querySelector('[data-banner-preview]');
    const img=preview.querySelector('[data-banner-image]');
    const file=form.querySelector('input[name="image"]');
    if(img)img.dataset.originalSrc=img.getAttribute('src');
    for(const [field,target] of Object.entries({title:'title',description:'description',cta_label:'cta'})){
      const input=form.querySelector('[name="'+field+'"]');
      const label=preview.querySelector('[data-banner-'+target+']');
      if(!input||!label)continue;
      const initial=label.textContent;
      input.addEventListener('input',()=>{label.textContent=input.value.trim()||initial;});
    }
    if(file&&img)file.addEventListener('change',()=>setObjectPreview(file,img,null));
    preview.querySelectorAll('[data-banner-mode]').forEach(btn=>btn.addEventListener('click',()=>{
      const mode=btn.dataset.bannerMode;
      if(mode!=='desktop'&&mode!=='mobile')return;
      preview.dataset.bannerView=mode;
      preview.querySelectorAll('[data-banner-mode]').forEach(button=>button.setAttribute('aria-pressed',String(button===btn)));
    }));
  });
})();
