'use strict';
const panels=[...document.querySelectorAll('.panel')],tabs=[...document.querySelectorAll('.tab')];
function showPanel(id){
  panels.forEach(panel=>{const active=panel.id===id;panel.hidden=!active;panel.classList.toggle('active',active)});
  tabs.forEach(tab=>{const active=tab.dataset.panel===id;tab.classList.toggle('active',active);tab.setAttribute('aria-selected',String(active))});
  document.querySelector('#'+id+' input')?.focus();
}
tabs.forEach(tab=>tab.addEventListener('click',()=>showPanel(tab.dataset.panel)));
document.querySelectorAll('[data-open-reset]').forEach(button=>button.addEventListener('click',()=>showPanel('reset-panel')));
document.querySelector('.back-to-login').addEventListener('click',()=>showPanel('login-panel'));
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
  }catch(error){
    const unavailable=error instanceof TypeError;
    message(form,unavailable?'Le portail est prêt, mais le service sécurisé de comptes n’est pas encore activé.':error.message);
  }finally{button.disabled=false}
}
document.querySelector('#login-form').addEventListener('submit',event=>{event.preventDefault();const form=event.currentTarget;submit(form,'../api/auth/login',values(form),'Connexion réussie.')});
document.querySelector('#register-form').addEventListener('submit',event=>{
  event.preventDefault();const form=event.currentTarget,data=values(form);
  if(data.password!==data.passwordConfirmation)return message(form,'Les deux mots de passe ne correspondent pas.');
  if(!data.manager)return message(form,'Pour créer une entreprise, vous devez être son premier gestionnaire. Les collaborateurs rejoignent ensuite le compte sur invitation.');
  delete data.passwordConfirmation;data.manager=true;data.marketing=Boolean(data.marketing);
  submit(form,'../api/auth/register-company',data,'Un e-mail de confirmation vient de vous être envoyé.');
});
document.querySelector('#reset-form').addEventListener('submit',event=>{event.preventDefault();const form=event.currentTarget;submit(form,'../api/auth/password/request',values(form),'Si cette adresse correspond à un compte, un lien temporaire vient d’être envoyé.')});
document.querySelector('#year').textContent=new Date().getFullYear();
