'use strict';
const stored=sessionStorage.getItem('alpesexOrder');
let order=null;try{order=stored?JSON.parse(stored):null}catch{}
const labels={master:'Master',user:'Utilisateur',userPack5:'Lot de 5 Utilisateur',userPack20:'Lot de 20 Utilisateur',manager:'Manager',direction:'Direction'},prices={user:19,userPack5:90,userPack20:342,manager:39,direction:29};
async function requireManager(){
  const response=await fetch('../api/auth/session/',{headers:{Accept:'application/json'},credentials:'same-origin'});
  if(response.status===401){location.replace('../compte/?session=expired');return false}
  const data=await response.json().catch(()=>({}));
  if(!response.ok||data.user?.role!=='manager'){location.replace('./');return false}
  document.querySelector('#header-user').textContent=data.user.firstName+' '+data.user.lastName;return true
}
function render(){
  if(!order||!order.offerName||!order.included||!order.extras){location.replace('./#subscription');return}
  const rows=[['Offre '+order.offerName,'1',order.basePrice+' €'],...Object.entries(order.included).map(([key,quantity])=>['Licence '+labels[key]+' incluse',quantity,'Inclus']),...Object.entries(order.extras).filter(([,quantity])=>quantity>0).map(([key,quantity])=>[(key==='userPack5'||key==='userPack20'?labels[key]:'Licence '+labels[key]+' supplémentaire'),quantity,(prices[key]*quantity)+' €'])];
  document.querySelector('#order-summary').innerHTML='<div class="summary-head"><span>Désignation</span><span>Quantité</span><span>Montant HT/mois</span></div>'+rows.map(row=>'<div class="summary-row"><span>'+row[0]+'</span><span>'+row[1]+'</span><strong>'+row[2]+'</strong></div>').join('');
  document.querySelector('#checkout-total').textContent=order.total+' € HT/mois';
}
document.querySelector('#validate-order').addEventListener('change',event=>document.querySelector('#payment-button').disabled=!event.currentTarget.checked);
document.querySelector('#payment-button').addEventListener('click',()=>{const message=document.querySelector('#payment-message');message.textContent='Votre commande est prête. Le prestataire de paiement sécurisé doit maintenant être raccordé avant de pouvoir encaisser.';message.classList.add('visible')});
document.querySelector('#logout').addEventListener('click',async()=>{try{await fetch('../api/auth/logout/',{method:'POST',headers:{Accept:'application/json'},credentials:'same-origin'})}finally{location.replace('../compte/')}});
requireManager().then(ok=>{if(ok)render()}).catch(()=>location.replace('../compte/?session=expired'));
