'use strict';
window.ManagerPortal=(()=>{
  const api='../api/team/management/';
  let data=null,deviceMember=null,deviceTab='active',devices=[],requestGeneration=0;
  const dialog=document.querySelector('#manager-dialog'),body=document.querySelector('#manager-dialog-content'),title=document.querySelector('#manager-dialog-title'),message=document.querySelector('#manager-dialog-message');
  const esc=value=>String(value??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));
  const name=person=>[person.firstName,person.lastName].filter(Boolean).join(' ')||person.email;
  const type=license=>({user:'Utilisateur',manager:'Manager',direction:'Direction',master:'Master'}[license.role||license.type]||'Utilisateur');
  const button=(label,attrs,extra='')=>'<button type="button" '+attrs+' '+extra+'>'+label+'</button>';
  async function request(query='',payload){
    const response=await fetch(api+query,{method:payload?'POST':'GET',credentials:'same-origin',cache:'no-store',headers:{Accept:'application/json',...(payload?{'Content-Type':'application/json','X-CSRF-Token':data?.csrf||''}:{})},body:payload?JSON.stringify(payload):undefined});
    const result=await response.json().catch(()=>({}));
    if(!response.ok)throw new Error(result.message||'Opération impossible.');return result;
  }
  function open(label){requestGeneration++;title.textContent=label;body.innerHTML='';message.textContent='';if(!dialog.open)dialog.showModal();}
  function close(){requestGeneration++;dialog.close();body.replaceChildren();message.textContent='';deviceMember=null;devices=[];}
  document.querySelector('#manager-dialog-close').addEventListener('click',close);
  dialog.addEventListener('cancel',event=>{event.preventDefault();close()});
  const emailLicense=email=>data.licenses.find(l=>l.type==='user'&&l.email?.toLowerCase()===email.toLowerCase()&&['active','reserved','suspended'].includes(l.status));
  function recipientActions(person,kind){
    const license=emailLicense(person.email),attrs='data-recipient-kind="'+kind+'" data-recipient-id="'+person.id+'"';
    return '<div class="manager-actions">'+(kind==='member'?button('Voir les appareils','data-manager-devices="'+person.id+'"'):'')+
      (!license?button('Attribuer une licence','data-pick-license '+attrs):'')+(kind==='member'&&person.role!=='manager'?button('Supprimer le membre','data-team-action="remove" data-member-id="'+person.id+'"','class="danger"'):'')+'</div>';
  }
  function licenseActions(l){
    const id=esc(l.id);
    return '<div class="manager-actions">'+button('Afficher la clé complète','data-manager-key="'+id+'"')+button('Copier la clé','data-manager-copy="'+id+'"')+
      (['active','reserved'].includes(l.status)?button('Retirer la licence','data-release-license="'+id+'"','class="danger"'):'')+'</div>';
  }
  async function refresh(){
    data=await request();
    document.querySelector('#licensed-team-count').textContent=data.licensedTeamCount;
    const current=data.licenses.find(l=>l.type==='user'&&l.memberId===data.currentUserId&&l.status==='active');
    document.querySelector('#current-license-number').textContent=current?.id||'Non attribuée';
    document.querySelector('#current-license-status').textContent=current?'Licence personnelle attribuée':'Aucune licence personnelle active';
    document.querySelector('#current-license-key').innerHTML=current?button('Afficher ma clé complète','data-manager-key="'+esc(current.id)+'"'):'';
    document.querySelector('#licenses-heading').textContent='Licences attribuées';
    const assigned=data.licenses.filter(l=>l.type==='user'&&l.email);
    document.querySelector('#licenses-list').innerHTML=assigned.length?assigned.map(l=>{
      const person=[...data.members,...data.invitations].find(p=>p.email.toLowerCase()===l.email.toLowerCase());
      return '<article class="license-row"><div><strong>'+esc(person?name(person):l.email)+'</strong><small>'+esc(l.email)+'</small><small>Référence : '+esc(l.id)+'</small>'+licenseActions(l)+'</div><div><span class="team-badge">'+esc(type(l))+'</span><small>'+esc(l.status==='reserved'?'Invitation en attente':l.status==='active'?'Attribuée':l.status)+'</small></div></article>';
    }).join(''):'<p>Aucune licence attribuée.</p>';
    document.querySelector('#master-licenses').innerHTML=data.licenses.filter(l=>l.type==='master').map(l=>'<article class="license-row"><div><strong>Licence Master de l’entreprise</strong><small>'+esc(l.id)+'</small></div><small>'+esc(l.status)+'</small></article>').join('');
    const stock=data.licenses.filter(l=>l.inStock);
    document.querySelector('#license-stock').innerHTML=stock.length?['user','manager','direction'].map(role=>{
      const group=stock.filter(l=>(l.role||'user')===role);return group.length?'<div class="stock-group"><h4>'+esc(type({role}))+' · '+group.length+'</h4>'+group.map(l=>'<article class="license-row"><div><strong>'+esc(l.id)+'</strong><small>'+esc(type(l))+'</small></div>'+button('Attribuer une licence','data-pick-recipient="'+esc(l.id)+'"')+'</article>').join('')+'</div>':'';
    }).join(''):'<p>Aucune licence disponible en stock.</p>';
    const unavailable=data.licenses.filter(l=>l.type==='user'&&!l.email&&!l.inStock);
    if(unavailable.length)document.querySelector('#license-stock').innerHTML+='<p>'+unavailable.length+' licence(s) indisponible(s) : expiration, suspension ou Master inactive.</p>';
    document.querySelector('#members-list').innerHTML=data.members.map(p=>'<div class="team-row"><div><strong>'+esc(name(p))+'</strong><small>'+esc(p.email)+'</small>'+recipientActions(p,'member')+'</div><div><span class="team-badge">'+esc(emailLicense(p.email)?type(emailLicense(p.email)):'Sans licence')+'</span></div></div>').join('')||'<p>Aucun membre.</p>';
    document.querySelector('#invitations-list').innerHTML=data.invitations.map(p=>'<div class="team-row"><div><strong>'+esc(name(p))+'</strong><small>'+esc(p.email)+'</small>'+recipientActions(p,'invitation')+'</div><div><span class="team-badge">'+esc(emailLicense(p.email)?'Licence réservée':'Sans licence')+'</span><small>Invitation en attente</small></div></div>').join('')||'<p>Aucune invitation en attente.</p>';
  }
  function assignment(licenseId,kind,id){
    open('Attribuer une licence');
    const stock=data.licenses.filter(l=>l.inStock);
    const people=[...data.members.filter(p=>p.status==='active').map(p=>({...p,kind:'member'})),...data.invitations.map(p=>({...p,kind:'invitation'}))].filter(p=>!emailLicense(p.email));
    if(!stock.length||!people.length){body.textContent=!stock.length?'Aucune licence disponible en stock.':'Toutes les personnes éligibles ont déjà une licence.';return}
    body.innerHTML='<form id="manager-assign-form"><label for="assign-license">Licence</label><select id="assign-license" name="licenseId" required>'+stock.map(l=>'<option value="'+esc(l.id)+'" '+(l.id===licenseId?'selected':'')+'>'+esc(type(l)+' — '+l.id)+'</option>').join('')+'</select><label for="assign-person">Attribuer à</label><select id="assign-person" name="recipient" required>'+people.map(p=>'<option value="'+p.kind+':'+p.id+'" '+(p.kind===kind&&String(p.id)===String(id)?'selected':'')+'>'+esc(name(p)+' — '+p.email+(p.kind==='invitation'?' (invitation en attente)':''))+'</option>').join('')+'</select><button type="submit">Confirmer l’attribution</button></form>';
  }
  function renderDevices(){
    body.innerHTML='<div class="manager-dialog-tabs" role="tablist" aria-label="État des appareils">'+['active','revoked'].map(status=>button((status==='active'?'Appareils actifs':'Appareils révoqués')+' ('+devices.filter(d=>d.status===status).length+')','role="tab" id="manager-device-'+status+'" aria-controls="manager-device-panel" aria-selected="'+(deviceTab===status)+'" tabindex="'+(deviceTab===status?'0':'-1')+'" data-modal-device-tab="'+status+'"')).join('')+'</div><div id="manager-device-panel" role="tabpanel" tabindex="0" aria-labelledby="manager-device-'+deviceTab+'">'+(devices.filter(d=>d.status===deviceTab).map(d=>'<article class="license-row"><div><strong>'+esc(d.name)+'</strong><small>'+esc(d.platform)+'</small><small>Dernière connexion : '+esc(new Date(d.lastSeenAt.replace(' ','T')+'Z').toLocaleString('fr-FR'))+'</small></div>'+(deviceTab==='active'?button('Révoquer','data-manager-revoke="'+d.id+'"'):'')+'</article>').join('')||'<p>Aucun appareil dans cet onglet.</p>')+'</div><p class="section-copy" style="margin:16px 0 0">Les appareils actifs sont autorisés à utiliser la licence ; ils ne sont pas nécessairement connectés en ce moment.</p>';
  }
  async function showDevices(member){
    const person=data.members.find(p=>String(p.id)===String(member));if(!person)return;
    open('Appareils de '+name(person));deviceMember=Number(member);deviceTab='active';body.textContent='Chargement…';
    const generation=requestGeneration;
    const result=await request('?action=devices&memberId='+deviceMember);
    if(generation!==requestGeneration)return;devices=result.devices;renderDevices();
  }
  async function key(id,copyOnly){
    open(copyOnly?'Copier la clé complète':'Clé complète de licence');body.textContent='Chargement…';const generation=requestGeneration;
    const result=await request('?action=key&licenseId='+encodeURIComponent(id));if(generation!==requestGeneration)return;
    body.innerHTML='<label for="manager-key">Clé à coller dans CPMP-ASM</label><textarea id="manager-key" class="manager-key-text" readonly spellcheck="false"></textarea>'+button('Copier la clé complète','data-copy-visible-key');
    body.querySelector('textarea').value=result.licenseToken;if(copyOnly)await copyKey();
  }
  async function copyKey(){const field=body.querySelector('textarea');try{await navigator.clipboard.writeText(field.value);message.textContent='Clé complète copiée.'}catch{field.focus();field.select();message.textContent='Clé sélectionnée : utilisez Copier ou Ctrl + C.'}}
  document.querySelector('#dashboard').addEventListener('click',async event=>{
    if(!window.accountIsManager)return;
    const b=event.target.closest('[data-manager-devices],[data-pick-license],[data-pick-recipient],[data-manager-key],[data-manager-copy],[data-release-license]');if(!b)return;b.disabled=true;
    try{
      if(b.hasAttribute('data-manager-devices'))await showDevices(b.dataset.managerDevices);
      else if(b.hasAttribute('data-pick-license'))assignment(null,b.dataset.recipientKind,b.dataset.recipientId);
      else if(b.hasAttribute('data-pick-recipient'))assignment(b.dataset.pickRecipient);
      else if(b.hasAttribute('data-manager-key'))await key(b.dataset.managerKey,false);
      else if(b.hasAttribute('data-manager-copy'))await key(b.dataset.managerCopy,true);
      else if(confirm('Retirer cette licence ? Les appareils associés seront révoqués et la licence retournera dans le stock.')){
        await request('',{action:'release',licenseId:b.dataset.releaseLicense});await refresh();document.querySelector('#manager-feedback').textContent='Licence remise en stock.';
      }
    }catch(e){(dialog.open?message:document.querySelector('#manager-feedback')).textContent=e.message}finally{b.disabled=false}
  });
  dialog.addEventListener('submit',async event=>{
    if(event.target.id!=='manager-assign-form')return;event.preventDefault();const form=event.target,b=form.querySelector('button');b.disabled=true;
    try{const values=new FormData(form),[recipientKind,recipientId]=values.get('recipient').split(':');await request('',{action:'assign',licenseId:values.get('licenseId'),recipientKind,recipientId:Number(recipientId)});await refresh();close();document.querySelector('#manager-feedback').textContent='Licence attribuée.'}catch(e){message.textContent=e.message;b.disabled=false}
  });
  dialog.addEventListener('click',async event=>{
    const b=event.target.closest('[data-modal-device-tab],[data-manager-revoke],[data-copy-visible-key]');if(!b)return;
    if(b.hasAttribute('data-modal-device-tab')){deviceTab=b.dataset.modalDeviceTab;renderDevices();body.querySelector('[aria-selected="true"]').focus();return}
    b.disabled=true;
    try{if(b.hasAttribute('data-copy-visible-key'))await copyKey();else if(confirm('Révoquer cet appareil ?')){await request('',{action:'revoke',memberId:deviceMember,deviceId:Number(b.dataset.managerRevoke)});const result=await request('?action=devices&memberId='+deviceMember);devices=result.devices;renderDevices();message.textContent='Appareil révoqué.'}}catch(e){message.textContent=e.message}finally{b.disabled=false}
  });
  dialog.addEventListener('keydown',event=>{if(!event.target.matches('[role="tab"]')||!['ArrowLeft','ArrowRight','Home','End'].includes(event.key))return;event.preventDefault();deviceTab=event.key==='Home'?'active':event.key==='End'?'revoked':deviceTab==='active'?'revoked':'active';renderDevices();body.querySelector('[aria-selected="true"]').focus()});
  return {refresh};
})();
