'use strict';
const loading=document.querySelector('#loading'),dashboard=document.querySelector('#dashboard');
const value=v=>v===null||v===undefined||v===''?'Non renseigné':String(v);
function fillList(target,items){target.innerHTML=items.map(([label,item])=>'<div><dt>'+label+'</dt><dd>'+value(item)+'</dd></div>').join('')}
async function loadAccount(){
  try{
    const response=await fetch('../api/auth/session/',{headers:{Accept:'application/json'},credentials:'same-origin'});
    if(response.status===401){location.replace('../compte/?session=expired');return}
    const data=await response.json().catch(()=>({}));
    if(!response.ok)throw new Error(data.message||'Impossible de charger le compte.');
    const user=data.user,organization=data.organization,isManager=user.role==='manager';
    document.querySelectorAll('[data-role="manager"]').forEach(element=>element.hidden=!isManager);
    document.querySelector('#header-user').textContent=user.firstName+' '+user.lastName;
    document.querySelector('#welcome-name').textContent='Bonjour '+user.firstName;
    document.querySelector('#welcome-copy').textContent=isManager?'Pilotez votre organisation, vos accès et vos services ALPES’Ex.':'Retrouvez votre licence, vos appareils et vos ressources ALPES’Ex.';
    document.querySelector('#role-badge').textContent=isManager?'Gestionnaire':'Utilisateur';
    document.querySelector('#organization-name').textContent=organization?.tradeName||organization?.name||'—';
    document.querySelector('#organization-status').textContent=organization?.status==='active'?'Organisation active':'Statut à vérifier';
    fillList(document.querySelector('#profile-details'),[['Prénom',user.firstName],['Nom',user.lastName],['Adresse e-mail',user.email],['Profil',isManager?'Gestionnaire':'Utilisateur']]);
    if(organization)fillList(document.querySelector('#organization-details'),[['Raison sociale',organization.name],['Nom commercial',organization.tradeName],['Forme juridique',organization.legalForm],['SIREN',organization.siren],['SIRET',organization.siret],['TVA intracommunautaire',organization.vatNumber],['Adresse',organization.billingAddress1+(organization.billingAddress2?' — '+organization.billingAddress2:'')],['Ville',organization.postalCode+' '+organization.city],['E-mail de facturation',organization.billingEmail],['Plateforme agréée',organization.invoicePlatform]]);
    loading.hidden=true;dashboard.hidden=false;if(isManager)loadTeam().catch(error=>{document.querySelector('#members-list').innerHTML='<p>'+error.message+'</p>'});
  }catch(error){loading.textContent=error.message}
}
document.querySelector('#logout').addEventListener('click',async()=>{
  const button=document.querySelector('#logout');button.disabled=true;
  try{await fetch('../api/auth/logout/',{method:'POST',headers:{Accept:'application/json'},credentials:'same-origin'})}finally{location.replace('../compte/')}
});
document.querySelectorAll('aside nav a').forEach(link=>link.addEventListener('click',()=>{document.querySelectorAll('aside nav a').forEach(item=>item.classList.remove('active'));link.classList.add('active')}));
loadAccount();

const offers={trial:{name:'Essai',price:0,included:{master:1,user:1,manager:1,direction:1}},discovery:{name:'Découverte',price:166,included:{master:1,user:3,manager:1,direction:1}},pro:{name:'Pro',price:459,included:{master:1,user:20,manager:1,direction:1}}},addonPrices={user:19,userPack5:90,userPack20:342,manager:39,direction:29};
function currentOrder(){const selected=document.querySelector('input[name="offer"]:checked');if(!selected)return null;const extras={};document.querySelectorAll('[data-addon]').forEach(input=>{const quantity=Math.max(0,Math.min(999,Number.parseInt(input.value,10)||0));input.value=quantity;extras[input.dataset.addon]=quantity});const offer=offers[selected.value],extrasTotal=Object.entries(extras).reduce((total,[key,quantity])=>total+addonPrices[key]*quantity,0);return{offerKey:selected.value,offerName:offer.name,basePrice:offer.price,included:offer.included,extras,extraLicenses:{user:extras.user+(extras.userPack5*5)+(extras.userPack20*20),manager:extras.manager,direction:extras.direction},extrasTotal,total:offer.price+extrasTotal,currency:'EUR',billing:'monthly'}};
function updateOrder(){const order=currentOrder(),total=document.querySelector('#order-total'),button=document.querySelector('#continue-order');if(!total||!button)return;total.textContent=order?order.total+' € HT/mois':'Sélectionnez une offre';button.disabled=!order}
document.querySelectorAll('input[name="offer"],[data-addon]').forEach(input=>input.addEventListener('input',updateOrder));
document.querySelector('#continue-order')?.addEventListener('click',()=>{const order=currentOrder();if(!order)return;sessionStorage.setItem('alpesexOrder',JSON.stringify(order));location.href='commande.html'});
updateOrder();

const licenseLabels={user:'Utilisateur',manager:'Manager',direction:'Direction'};
function personRow(name,email,badge,detail){return '<div class="team-row"><div><strong>'+name+'</strong><small>'+email+'</small></div><div><span class="team-badge">'+badge+'</span><small>'+detail+'</small></div></div>'}
async function loadTeam(){const response=await fetch('../api/team/',{headers:{Accept:'application/json'},credentials:'same-origin'}),data=await response.json().catch(()=>({}));if(!response.ok)throw new Error(data.message||'Impossible de charger l’équipe.');document.querySelector('#members-list').innerHTML=data.members.length?data.members.map(member=>personRow(member.firstName+' '+member.lastName,member.email,member.role==='manager'?'Gestionnaire':'Membre',member.status==='active'?'Compte actif':'Compte en attente')).join(''):'<p>Aucun membre.</p>';document.querySelector('#invitations-list').innerHTML=data.invitations.length?data.invitations.map(invitation=>personRow((invitation.firstName||'Invitation')+' '+(invitation.lastName||''),invitation.email,licenseLabels[invitation.licenseType]||invitation.licenseType,'Valable jusqu’au '+new Date(invitation.expiresAt.replace(' ','T')+'Z').toLocaleDateString('fr-FR'))).join(''):'<p>Aucune invitation en attente.</p>'}
document.querySelector('#invite-member-form')?.addEventListener('submit',async event=>{event.preventDefault();const form=event.currentTarget,button=form.querySelector('button'),box=document.querySelector('#invite-message'),payload=Object.fromEntries(new FormData(form).entries());button.disabled=true;box.textContent='Envoi en cours…';box.className='success';try{const response=await fetch('../api/team/invitations/',{method:'POST',headers:{'Content-Type':'application/json',Accept:'application/json'},credentials:'same-origin',body:JSON.stringify(payload)}),data=await response.json().catch(()=>({}));if(!response.ok)throw new Error(data.message||'Invitation impossible.');box.textContent=data.message;form.reset();await loadTeam()}catch(error){box.textContent=error.message;box.className='error'}finally{button.disabled=false}});
const observedSections=[...document.querySelectorAll('.content>section[id],.content>div[id]')],navLinks=[...document.querySelectorAll('aside nav a[href^="#"]')];const sectionObserver=new IntersectionObserver(entries=>{const visible=entries.filter(entry=>entry.isIntersecting).sort((a,b)=>b.intersectionRatio-a.intersectionRatio)[0];if(!visible)return;navLinks.forEach(link=>link.classList.toggle('active',link.getAttribute('href')==='#'+visible.target.id))},{rootMargin:'-20% 0px -65% 0px',threshold:[0,.2,.5]});observedSections.forEach(section=>sectionObserver.observe(section));
