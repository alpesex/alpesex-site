/* Compact project chrome and view continuity. No project data migration. */
(()=>{
 window.PCASM602={};
 const brand=document.querySelector('.shellbar .brand');
 brand.innerHTML='<span class="mark" aria-hidden="true"></span><div class="asm-header-copy"><div class="asm-header-eyebrow">ALPES\'EX · Anticiper · Sécuriser · Maîtriser</div><h1 id="asmProjectTitle">CPMP – ASM</h1><p id="asmProjectMeta"></p></div>';
 function header(p,dc=false){
  document.getElementById('asmProjectTitle').textContent=p?(dc?'DC ASM':'PC ASM')+' - '+(p.meta.nomProjet||'Nouveau projet'):'CPMP – ASM';
  document.getElementById('asmProjectMeta').textContent=p?(p.meta.nomProjet||'Nouveau projet')+' | Client : '+(p.meta.client||'Non renseigné')+' | Chef de projet : '+(p.meta.chefDeProjet||'Non renseigné')+' | Mise à jour : '+formatDate(p.meta.derniereMiseAJour):'';
 }
 window.PCASM602.updateHeader=header;
 const pcState=()=>{try{return (currentMode==='admin'?adminFrame:clientFrame).contentWindow.PCASMBridge?.captureView();}catch{return null;}};
 function wrapPC(original,frame){return function(p){
  const state=currentProjectId===p.meta.portfolioId&&['admin','client'].includes(currentMode)?pcState():null;
  frame.onload=()=>{frame.contentWindow.PCASMBridge?.restoreView(state);};
  original(p);header(p);
 };}
 showClient=wrapPC(showClient,clientFrame);showAdmin=wrapPC(showAdmin,adminFrame);
 const baseDC=openDCProject;
 openDCProject=function(id,edit){
  const preserve=currentProjectId===id&&currentMode.startsWith('dc-');
  const state=preserve?{tab:document.querySelector('[data-dc-tab].active')?.dataset.dcTab||'dash',scroll:dcFrame.scrollTop,search:dcSearch.value,family:dcFamilyFilter.value,status:dcStatusFilter.value}:null;
  baseDC(id,edit);header(projectById(id),true);
  if(state){dcSearch.value=state.search;dcFamilyFilter.value=state.family;dcStatusFilter.value=state.status;dcShowTab(state.tab);dcFrame.scrollTop=state.scroll;}
 };
 const basePortfolio=renderPortfolio;
 renderPortfolio=function(...args){const result=basePortfolio(...args);header(null);return result;};
 const dcNav=document.querySelector('.dc-admin-nav');dcNav.classList.add('asm-page-nav');dcFrame.insertBefore(dcNav,dcFrame.firstChild);
})();
