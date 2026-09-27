/* PHOTON+ ALPES'Ex — presentation effects only. */
(() => {
  'use strict';
  const embeddedTheme=document.getElementById('alpesex-industrial');if(embeddedTheme)document.head.append(embeddedTheme);
  const reduced = matchMedia('(prefers-reduced-motion: reduce)');
  function classify(scope=document) {
    for (const button of scope.querySelectorAll('button:not([data-fx])')) {
      const label=(button.textContent||'').trim();let fx='secondary';
      if(button.matches('[data-target],[data-admin-target],[data-phase],[data-transverse],[data-trans],[data-dc-tab],.asm-role-nav button')||button.closest('.asm-page-nav'))fx='nav';
      else if(/supprimer|effacer/i.test(label))fx='danger';
      else if(/enregistrer|sauvegarder|publier/i.test(label))fx='save';
      else if(/annuler|retour|fermer|déconnexion|mot de passe oublié/i.test(label))fx='quiet';
      else if(button.matches('.primary,.asm-summary-switch')||/nouveau projet|nouveau compte rendu|créer le|^connexion$|^se connecter$/i.test(label))fx='primary';
      button.dataset.fx=fx;
    }
  }
  classify();
  new MutationObserver(records=>{for(const record of records)for(const item of record.addedNodes)if(item.nodeType===1)classify(item.matches?.('button')?item.parentElement:item);}).observe(document.documentElement,{childList:true,subtree:true});
  document.addEventListener('pointermove',event=>{
    if(reduced.matches)return;const item=event.target.closest('button,.card,.panel,.asm-group,.dc-family,.asm-metric,.finance-kpi,.dc-kpi,.module-card,.phase-sidebar,.phase-planning-panel,.phase-timeline,.modal-panel,.dialog,.object-section,.cr-element-card,.entry-card,.asm-dialog');
    if(!item)return;const box=item.getBoundingClientRect();item.style.setProperty('--px',event.clientX-box.left+'px');item.style.setProperty('--py',event.clientY-box.top+'px');
  },{passive:true});
  document.addEventListener('click',event=>{
    const button=event.target.closest('button[data-fx]');if(!button||reduced.matches)return;
    if(button.dataset.fx==='primary'){const box=button.getBoundingClientRect(),flash=document.createElement('span');flash.className='photon-click';flash.style.left=(event.clientX?event.clientX-box.left:box.width/2)+'px';flash.style.top=(event.clientY?event.clientY-box.top:box.height/2)+'px';button.append(flash);flash.addEventListener('animationend',()=>flash.remove(),{once:true});}
    if(['save','secondary'].includes(button.dataset.fx)){button.classList.remove('ambient-tap');void button.offsetWidth;button.classList.add('ambient-tap');button.addEventListener('animationend',()=>button.classList.remove('ambient-tap'),{once:true});}
  });
})();
