'use strict';
const panels=[...document.querySelectorAll('.panel')],tabs=[...document.querySelectorAll('.tab')];
function showPanel(id){
  panels.forEach(panel=>{const active=panel.id===id;panel.hidden=!active;panel.classList.toggle('active',active)});
  tabs.forEach(tab=>{const active=tab.dataset.panel===id;tab.classList.toggle('active',active);tab.setAttribute('aria-selected',String(active))});
  document.querySelector('#'+id+' input')?.focus();
}
tabs.forEach(tab=>tab.addEventListener('click',()=>showPanel(tab.dataset.panel)));
document.querySelectorAll('[data-open-reset]').forEach(button=>button.addEventListener('click',()=>showPanel('reset-panel')));
document.querySelector('.back-to-login')?.addEventListener('click',()=>showPanel('login-panel'));

document.querySelectorAll('.registration-type').forEach(button=>button.addEventListener('click',()=>{
  document.querySelectorAll('.registration-type').forEach(item=>item.classList.toggle('active',item===button));
  document.querySelectorAll('.registration-form').forEach(form=>{const active=form.id===button.dataset.registration;form.hidden=!active;form.classList.toggle('active',active)});
}));

document.querySelectorAll('.show-password').forEach(button=>button.addEventListener('click',()=>{
  const input=button.parentElement.querySelector('input'),visible=input.type==='text';input.type=visible?'password':'text';button.textContent=visible?'Afficher':'Masquer';
}));
function message(form,text,type='error'){const box=form.querySelector('.form-message');box.textContent=text;box.className='form-message visible '+type}
function values(form){return Object.fromEntries(new FormData(form).entries())}
async function submit(form,endpoint,payload,success){
  const button=form.querySelector('button[type=submit]');button.disabled=true;message(form,'Traitement en cours…','success');
  try{
    const response=await fetch(endpoint,{method:'POST',headers:{'Content-Type':'application/json','Accept':'application/json'},credentials:'same-origin',body:JSON.stringify(payload)});
    const data=await response.json().catch(()=>({}));
    if(!response.ok)throw new Error(data.message||'La demande n’a pas pu être traitée.');
    message(form,data.message||success,'success');
    if(data.redirect)location.href=data.redirect;
  }catch(error){message(form,error instanceof TypeError?'L’interface est prête, mais le service sécurisé de comptes doit encore être raccordé.':error.message)}
  finally{button.disabled=false}
}
document.querySelector('#login-form')?.addEventListener('submit',event=>{
  event.preventDefault();const form=event.currentTarget,data=values(form);
  submit(form,'../api/auth/login/',data,'Connexion réussie.');
});
document.querySelector('#company-form')?.addEventListener('submit',event=>{
  event.preventDefault();const form=event.currentTarget,data=values(form);
  if(data.password!==data.passwordConfirmation)return message(form,'Les deux mots de passe ne correspondent pas.');
  const digits=value=>String(value||'').replace(/\D/g,'');data.siren=digits(data.siren);data.siret=digits(data.siret);
  if(data.country==='France'&&(data.siren.length!==9||data.siret.length!==14))return message(form,'Le SIREN doit contenir 9 chiffres et le SIRET 14 chiffres.');
  if(data.siret.slice(0,9)!==data.siren)return message(form,'Le SIRET doit commencer par le SIREN de l’entreprise.');
  delete data.passwordConfirmation;data.firstAccountManager=true;data.marketing=Boolean(data.marketing);
  submit(form,'../api/auth/register-company/',data,'Votre organisation est créée. Confirmez votre adresse e-mail.');
});
document.querySelector('#invited-form')?.addEventListener('submit',event=>{
  event.preventDefault();const form=event.currentTarget,data=values(form);
  if(data.password!==data.passwordConfirmation)return message(form,'Les deux mots de passe ne correspondent pas.');
  if(!data.managerInvitation)return message(form,'Vous devez confirmer l’invitation du gestionnaire.');
  delete data.passwordConfirmation;data.invitedByManager=true;data.invitationToken=new URLSearchParams(location.search).get('invitation')||'';
  submit(form,'../api/auth/register-invited-user/',data,'Votre compte est créé et rattaché à l’organisation.');
});
document.querySelector('#reset-form')?.addEventListener('submit',event=>{event.preventDefault();const form=event.currentTarget;submit(form,'../api/auth/password/request/',values(form),'Si cette adresse correspond à un compte, un lien temporaire vient d’être envoyé.')});
document.querySelector('#new-password-form')?.addEventListener('submit',event=>{event.preventDefault();const form=event.currentTarget,data=values(form);if(data.password!==data.passwordConfirmation)return message(form,'Les deux mots de passe ne correspondent pas.');delete data.passwordConfirmation;data.token=new URLSearchParams(location.search).get('reset')||'';submit(form,'../api/auth/password/reset/',data,'Votre mot de passe a été modifié.')});
document.querySelector('#year').textContent=new Date().getFullYear();
const urlParams=new URLSearchParams(location.search),requestedPlan=urlParams.get('offre'),confirmation=urlParams.get('confirmation'),invitationToken=urlParams.get('invitation'),invitationAccount=urlParams.get('invitationAccount'),resetToken=urlParams.get('reset');if(invitationToken&&invitationToken!=='accepted'){showPanel('register-panel');document.querySelector('[data-registration="invited-form"]')?.click();const invitedForm=document.querySelector('#invited-form');const emailField=invitedForm?.querySelector('[name="email"]');if(emailField)emailField.focus()}if(requestedPlan){showPanel('register-panel');message(document.querySelector('#company-form'),'Offre sélectionnée : '+requestedPlan+'. Créez votre organisation pour poursuivre.','success')}if(confirmation==='success'){showPanel('login-panel');message(document.querySelector('#login-form'),'Votre adresse e-mail est confirmée. Vous pouvez maintenant vous connecter.','success')}else if(confirmation==='invalid'){showPanel('login-panel');message(document.querySelector('#login-form'),'Ce lien de confirmation est invalide ou expiré.')}if(invitationToken==='accepted'){showPanel('login-panel');message(document.querySelector('#login-form'),'Votre compte a été créé. Vous pouvez maintenant vous connecter.','success')}if(invitationAccount==='existing'){showPanel('login-panel');message(document.querySelector('#login-form'),'Vous possédez déjà un compte ALPES’Ex. Connectez-vous pour accéder à votre espace.','success')}if(resetToken&&resetToken!=='success'){showPanel('reset-panel');document.querySelector('#reset-request').hidden=true;document.querySelector('#new-password-form').hidden=false}if(resetToken==='success'){showPanel('login-panel');message(document.querySelector('#login-form'),'Votre mot de passe a été modifié. Vous pouvez maintenant vous connecter.','success')}if(urlParams.get('session')==='expired'){showPanel('login-panel');message(document.querySelector('#login-form'),'Votre session a expiré. Veuillez vous reconnecter.')}
const menuToggle=document.querySelector('.menu-toggle'),accountNav=document.querySelector('.site-header nav');
menuToggle?.addEventListener('click',()=>{const open=accountNav.classList.toggle('open');menuToggle.setAttribute('aria-expanded',String(open))});
accountNav?.querySelectorAll('a').forEach(link=>link.addEventListener('click',()=>accountNav.classList.remove('open')));
