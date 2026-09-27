/* Shared by embedded PC views and standalone exports. */
(()=>{
 const admin=!!document.querySelector('.admin-tabs');
 const nav=document.querySelector(admin?'.admin-tabs':'header nav');
 const embedded=window.parent!==window && !!window.parent.PCASM602;
 const dashboardOnly=window.name==='asm-dashboard';
 const style=document.createElement('style');
 style.textContent=`
 html{scroll-padding-top:52px}body{margin:0}header{position:static!important}
 .asm-page-nav{position:sticky!important;top:0;z-index:50;display:flex!important;flex-wrap:nowrap!important;gap:5px!important;padding:8px 16px!important;margin:0!important;max-width:none!important;width:100%!important;box-sizing:border-box;background:#f3f6f8!important;border:0!important;border-bottom:1px solid #d6e0e6!important;border-radius:0!important;overflow-x:auto;box-shadow:none!important}
 .asm-page-nav button{flex:0 0 auto!important;font-size:12px!important;line-height:1.3!important;padding:7px 11px!important;min-height:30px!important;border-radius:7px!important;white-space:nowrap!important;background:white!important;color:#244655!important;border:1px solid #cbd8df!important}
 .asm-page-nav button.active{background:#244655!important;color:white!important;border-color:#244655!important}
 .asm-dashboard-frame{position:static!important;display:block;width:100%;height:1000px;border:0;min-height:400px}
 body.asm-embedded>header{display:none!important}body.asm-dashboard-only>header,body.asm-dashboard-only>.asm-page-nav{display:none!important}
 body.asm-dashboard-only main{margin:0 auto!important;width:100%!important;padding:0!important}body.asm-dashboard-only main>section:not(#dashboard){display:none!important}
 @media(max-width:600px){.asm-page-nav{padding:6px 8px!important}.asm-page-nav button{font-size:11px!important;padding:7px 9px!important}}
 @media print{.asm-page-nav{display:none!important}}
 `;
 document.head.append(style);
 const photonTheme=document.getElementById('alpesex-industrial');if(photonTheme)document.head.append(photonTheme);
 if(nav){nav.classList.add('asm-page-nav');document.body.insertBefore(nav,document.querySelector('main'));}
 document.body.classList.toggle('asm-embedded',embedded);
 document.body.classList.toggle('asm-dashboard-only',dashboardOnly);
 const fieldPairs=[['crSearch','adminCrSearch'],['crPhaseFilter','adminCrPhase'],['crCritFilter','adminCrCrit'],['crDateFilter','adminCrDate'],['crSort','adminCrSort'],['clientElementSearch','adminElementSearch'],['clientElementType','adminElementType'],['clientElementCrit','adminElementCrit'],['clientElementState','adminElementState'],['clientElementDate','adminElementDate'],['clientElementCr','adminElementCr']];
 function capture(){
  return {tab:admin?document.querySelector('[data-admin-target].active')?.dataset.adminTarget.replace('admin-',''):document.querySelector('[data-target].active')?.dataset.target,
   phase:document.querySelector((admin?'#adminPhaseMenu':'#phaseMenu')+' [data-phase].active')?.dataset.phase,
   trans:admin?document.querySelector('[data-trans].active')?.dataset.trans:document.querySelector('[data-transverse].active')?.dataset.transverse.toUpperCase().replace('ASM','ASM-'),
   cr:document.querySelector(admin?'[data-admin-cr-view].active':'[data-client-cr-view].active')?.getAttribute(admin?'data-admin-cr-view':'data-client-cr-view'),
   filters:fieldPairs.map(pair=>document.getElementById(pair[admin?1:0])?.value||''),scroll:window.scrollY};
 }
 function restore(s){
  if(!s)return;
  document.querySelector(admin?`[data-admin-target="admin-${s.tab||'dashboard'}"]`:`[data-target="${s.tab||'dashboard'}"]`)?.click();
  if(s.phase)document.querySelector(`[data-phase="${s.phase}"]`)?.click();
  if(s.trans)document.querySelector(admin?`[data-trans="${s.trans}"]`:`[data-transverse="${s.trans.toLowerCase().replace('-','')}"]`)?.click();
  if(s.cr)document.querySelector(admin?`[data-admin-cr-view="${s.cr}"]`:`[data-client-cr-view="${s.cr}"]`)?.click();
  fieldPairs.forEach((pair,i)=>{const el=document.getElementById(pair[admin?1:0]);if(el&&s.filters?.[i]!==undefined){el.value=s.filters[i];el.dispatchEvent(new Event('input',{bubbles:true}));el.dispatchEvent(new Event('change',{bubbles:true}));}});
  requestAnimationFrame(()=>requestAnimationFrame(()=>window.scrollTo(0,s.scroll||0)));
 }
 Object.assign(window.PCASMBridge,{captureView:capture,restoreView:restore});
 if(admin){
  let dashboardFrame;
  const section=document.getElementById('admin-dashboard');
  document.querySelector('[data-admin-target="admin-dashboard"]')?.addEventListener('click',()=>{
   dashboardFrame=document.createElement('iframe');dashboardFrame.className='asm-dashboard-frame';dashboardFrame.name='asm-dashboard';dashboardFrame.title='Tableau de bord du projet';
   section.replaceChildren(dashboardFrame);
   dashboardFrame.srcdoc=new TextDecoder().decode(Uint8Array.from(atob(CLIENT_TEMPLATE_B64),c=>c.charCodeAt(0))).replace('__PCASM_DATA__',JSON.stringify(window.PCASMBridge.getData()));
   dashboardFrame.addEventListener('load',()=>{const w=dashboardFrame.contentWindow;const resize=()=>{dashboardFrame.style.height=Math.ceil(w.document.querySelector('main').getBoundingClientRect().height+30)+'px';};new w.ResizeObserver(resize).observe(w.document.querySelector('main'));resize();});
  });
  window.PCASM602={navigate:(tab,detail)=>{restore({tab,phase:tab==='phases'?detail:undefined,trans:tab==='transverse'?detail:undefined});if(tab==='cr'&&detail)goAdminCr(detail);},exportPDF:()=>window.parent.PCASM_exportDashboardPDF?.()};
 }
 if(dashboardOnly){
  goToPhase=pid=>window.parent.PCASM602.navigate('phases',pid);
  goToSatisfaction=()=>window.parent.PCASM602.navigate('transverse','ASM-206');
  jumpToCr=ref=>window.parent.PCASM602.navigate('cr',ref);
  jumpToTransverse=ref=>window.parent.PCASM602.navigate('transverse',moduleForRef(ref).toUpperCase().replace('ASM','ASM-'));
  window.PCASM_exportDashboardPDF=()=>window.parent.PCASM602.exportPDF();
  document.querySelectorAll('[onclick*="PCASM_exportDashboardPDF"]').forEach(b=>b.onclick=window.PCASM_exportDashboardPDF);
 }
})();
