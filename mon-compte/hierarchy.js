'use strict';
window.TeamHierarchyUI=(()=>{
  let state=null,dirty=false;
  const root=document.querySelector('#team-hierarchy');
  const esc=v=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));
  const label=p=>[p.firstName,p.lastName].filter(Boolean).join(' ')||p.email;
  const role=p=>({direction:'Direction',manager:'Manager',user:'Utilisateur'}[p.licenseRole]||'Sans licence active');
  const allowed=(p,parent)=>p.licenseRole==='user'?parent.licenseRole==='manager':p.licenseRole==='manager'&&parent.licenseRole==='direction';
  async function request(payload){
    const r=await fetch('../api/team/hierarchy/',{method:payload?'POST':'GET',credentials:'same-origin',cache:'no-store',headers:{Accept:'application/json',...(payload?{'Content-Type':'application/json','X-CSRF-Token':state.csrf}:{})},body:payload?JSON.stringify(payload):undefined});
    const value=await r.json().catch(()=>({}));if(!r.ok)throw new Error(value.message||'Impossible de charger l’organigramme.');return value;
  }
  function card(p){return '<article class="hierarchy-person hierarchy-'+esc(p.licenseRole||'unlicensed')+'"><strong>'+esc(label(p))+'</strong><small>'+esc(p.email)+'</small><span>'+role(p)+'</span>'+(p.licenseRole==='user'&&p.parentId?'<small class="hierarchy-permission">Manager : '+(p.accessMode==='editor'?'lecture et écriture':'lecture seule')+'</small>':'')+'</article>';}
  function chart(){
    const people=state.members,used=new Set();
    function branch(p){used.add(p.id);const children=people.filter(c=>c.parentId===p.id&&allowed(c,p));return '<li>'+card(p)+(children.length?'<ul>'+children.map(branch).join('')+'</ul>':'')+'</li>';}
    let html=people.filter(p=>p.licenseRole==='direction').map(branch).join('');
    const standalone=people.filter(p=>p.licenseRole==='manager'&&!used.has(p.id));
    html+=standalone.map(branch).join('');
    const rest=people.filter(p=>!used.has(p.id));
    root.querySelector('#hierarchy-chart').innerHTML=(html?'<ul class="hierarchy-tree" aria-label="Direction, managers et équipes">'+html+'</ul>':'')+(rest.length?'<div class="hierarchy-unassigned"><h4>Personnes à rattacher ou sans licence active</h4><div>'+rest.map(card).join('')+'</div></div>':'')||'<p>Invitez vos collaborateurs et attribuez leurs licences pour construire l’organigramme.</p>';
  }
  function render(){
    root.innerHTML='<h3>Organigramme et droits sur les projets</h3><p>Direction : vision globale en lecture. Manager : accès aux projets des utilisateurs de son équipe. Chaque utilisateur conserve ses propres projets.</p><div id="hierarchy-chart"></div><details class="hierarchy-editor"><summary>Organiser les équipes et les droits</summary><form id="hierarchy-form"><p>Choisissez une Direction pour chaque Manager, puis un Manager pour chaque utilisateur. Les rattachements sont possibles après activation du compte et attribution d’une licence.</p>'+state.members.filter(p=>['manager','user'].includes(p.licenseRole)).map(p=>{
      const candidates=state.members.filter(parent=>parent.id!==p.id&&allowed(p,parent));
      return '<div class="hierarchy-edit-row"><div><strong>'+esc(label(p))+'</strong><small>'+esc(p.email)+' · '+role(p)+'</small></div><label for="parent-'+p.id+'">'+(p.licenseRole==='user'?'Manager':'Direction')+'<select id="parent-'+p.id+'" data-parent="'+p.id+'"><option value="">Non rattaché</option>'+candidates.map(parent=>'<option value="'+parent.id+'" '+(p.parentId===parent.id?'selected':'')+'>'+esc(label(parent))+'</option>').join('')+'</select></label>'+(p.licenseRole==='user'?'<label for="permission-'+p.id+'">Droits du Manager<select id="permission-'+p.id+'" data-permission="'+p.id+'"><option value="viewer" '+(p.accessMode==='viewer'?'selected':'')+'>Lecture seule</option><option value="editor" '+(p.accessMode==='editor'?'selected':'')+'>Lecture et écriture</option></select></label>':'<span>Vision Direction : lecture seule</span>')+'</div>';
    }).join('')+'<p>Un utilisateur non rattaché reste seul à modifier ses projets. Un changement d’équipe retire à l’ancien Manager l’accès serveur aux projets concernés.</p><button type="submit">Enregistrer l’organigramme et les droits</button> <button type="button" data-hierarchy-reload>Recharger</button></form></details><p id="hierarchy-message" role="status" aria-live="polite"></p>';
    chart();
  }
  async function refresh(force=false){
    if(!window.accountIsManager||dirty&&!force)return;
    try{state=await request();dirty=false;
      // Do not retain obsolete links when licences or account status have changed.
      state.members.forEach(p=>{const parent=state.members.find(q=>q.id===p.parentId);if(!parent||!allowed(p,parent))p.parentId=null;if(p.licenseRole==='manager')p.accessMode='viewer';});
      render();
    }catch(e){root.replaceChildren();const p=document.createElement('p');p.setAttribute('role','alert');p.textContent=e.message;root.append(p);}
  }
  root.addEventListener('change',e=>{
    const p=state?.members.find(p=>String(p.id)===(e.target.dataset.parent||e.target.dataset.permission));if(!p)return;
    if(e.target.hasAttribute('data-parent'))p.parentId=e.target.value?Number(e.target.value):null;else p.accessMode=e.target.value;
    dirty=true;chart();root.querySelector('#hierarchy-message').textContent='Modifications non enregistrées.';
  });
  root.addEventListener('click',e=>{if(e.target.closest('[data-hierarchy-reload]'))refresh(true);});
  root.addEventListener('submit',async e=>{
    if(e.target.id!=='hierarchy-form')return;e.preventDefault();const form=e.target,controls=[...form.querySelectorAll('button,select')],feedback=root.querySelector('#hierarchy-message');controls.forEach(c=>c.disabled=true);
    try{await request({revision:state.revision,links:state.members.filter(p=>p.parentId).map(p=>({userId:p.id,parentId:p.parentId,accessMode:p.accessMode}))});dirty=false;await refresh(true);root.querySelector('#hierarchy-message').textContent='Organigramme enregistré. Les droits serveur sont appliqués aux prochaines requêtes.';}
    catch(error){feedback.textContent=error.message;}finally{controls.forEach(c=>c.disabled=false);}
  });
  return {refresh};
})();
