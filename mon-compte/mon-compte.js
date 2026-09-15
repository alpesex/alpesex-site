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
    loading.hidden=true;dashboard.hidden=false;
  }catch(error){loading.textContent=error.message}
}
document.querySelector('#logout').addEventListener('click',async()=>{
  const button=document.querySelector('#logout');button.disabled=true;
  try{await fetch('../api/auth/logout/',{method:'POST',headers:{Accept:'application/json'},credentials:'same-origin'})}finally{location.replace('../compte/')}
});
document.querySelectorAll('aside nav a').forEach(link=>link.addEventListener('click',()=>{document.querySelectorAll('aside nav a').forEach(item=>item.classList.remove('active'));link.classList.add('active')}));
loadAccount();
