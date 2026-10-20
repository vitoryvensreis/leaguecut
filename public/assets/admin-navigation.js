(() => {
  'use strict';
  const nav=document.querySelector('[data-admin-nav]');
  if (!nav) return;
  const user=nav.getAttribute('data-user') || '0';
  const key='leaguecut:admin-group:'+user;
  const groups=[...nav.querySelectorAll('details[data-admin-group]')];
  // Active route wins over a stored preference.
  const active=groups.find(g=>g.classList.contains('is-active'));
  let stored='';
  try {stored=sessionStorage.getItem(key)||'';}catch(_){}
  const initial=active||groups.find(g=>g.dataset.adminGroup===stored);
  if(initial) groups.forEach(g=>{g.open=g===initial;});
  groups.forEach(group=>{
    group.addEventListener('toggle',()=>{
      if(!group.open)return;
      groups.forEach(other=>{if(other!==group)other.open=false;});
      try{sessionStorage.setItem(key,group.dataset.adminGroup);}catch(_){}
    });
  });
})();
