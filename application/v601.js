/* CPMP ASM 6.0.1: executive views and expected-document workflows. */
(() => {
  'use strict';
  const esc = escapeHtml;
  const node = (tag, text, parent) => { const el=document.createElement(tag); if(text)el.textContent=text; if(parent)parent.append(el); return el; };
  function modal(title) {
    const dialog=node('dialog');dialog.className='asm-dialog';
    node('h2',title,dialog);const form=node('form',null,dialog);form.method='dialog';
    const body=node('div',null,form);body.className='asm-form';
    const footer=node('div',null,form);footer.className='asm-dialog-actions';
    const cancel=node('button','Annuler',footer);cancel.type='button';cancel.onclick=()=>dialog.close();
    const submit=node('button','Enregistrer',footer);submit.type='submit';submit.className='primary';
    dialog.onclose=()=>dialog.remove();document.body.append(dialog);dialog.showModal();
    return {dialog,form,body,submit};
  }
  function field(parent, label, value='', type='text', options=null) {
    const wrap=node('label',label,parent), input=node(options?'select':'input',null,wrap);
    if(options)options.forEach(([v,t])=>{const o=node('option',t,input);o.value=v;});else input.type=type;
    input.setAttribute('aria-label',label);input.value=value;return input;
  }
  DCF.push(['DC-10','Autres documents']);
  const originalRender = dcRenderAll;
  dcRenderAll = function() {
    const family=dcFamilyFilter.value,status=dcStatusFilter.value;
    originalRender();dcFamilyFilter.value=family;dcStatusFilter.value=status;dcRenderRegister();
  };
  dcEditDoc = function(id) {
    if(currentMode!=='dc-admin')return;
    const project=activeDcProject,d=dcData(),old=d.documents.find(x=>x.id===id);
    const ui=modal(old?'Modifier le document attendu':'Nouveau document attendu');
    const family=field(ui.body,'Famille',old?.family||dcFamilyFilter.value||'DC-01','text',DCF.map(([c,n])=>[c,c+' — '+n]));
    // An uploaded document is addressed by its reference. Its family stays stable.
    if(old)family.disabled=true;
    const title=field(ui.body,'Nom du document',old?.title||'');title.required=true;title.maxLength=255;
    const owner=field(ui.body,'Responsable',old?.owner||'');owner.maxLength=190;
    const due=field(ui.body,'Échéance',old?.due||'','date');
    const choices=[['Interne','Interne'],['Client','Client'],['Les 2','Les 2']];
    if(old?.visibility==='Confidentiel')choices.push(['Confidentiel','Confidentiel (existant)']);
    const visibility=field(ui.body,'Visibilité',old?.visibility||'Interne','text',choices);
    const status=field(ui.body,'Statut',old?.status||'À produire','text',DCS.map(s=>[s,s]));
    ui.form.onsubmit=e=>{e.preventDefault();if(activeDcProject!==project||currentMode!=='dc-admin')return ui.dialog.close();
      const values={title:title.value.trim(),owner:owner.value.trim(),due:due.value,visibility:visibility.value,status:status.value};
      if(!values.title)return title.focus();
      if(old)Object.assign(old,values);
      else {const refs=new Set(d.documents.map(x=>x.ref));let n=1;while(refs.has(family.value+'-'+String(n).padStart(3,'0')))n++;
        d.documents.push({id:'DOC-'+crypto.randomUUID(),ref:family.value+'-'+String(n).padStart(3,'0'),family:family.value,revision:'A',fileName:'',history:[],...values});}
      dcPersist();dcRenderAll();ui.dialog.close();
    };
  };
  const showTabBeforeInline=dcShowTab;
  dcShowTab=function(tab){showTabBeforeInline(tab);if(tab==='dash'&&dcData())dcRenderAll();};
  const originalRegister=dcRenderRegister;
  dcRenderRegister=function() {
    originalRegister();if(currentMode!=='dc-admin')return;
    for(const tr of dcDocRows.querySelectorAll('tr')) {
      const id=tr.querySelector('[data-id]')?.dataset.id,x=dcData()?.documents.find(d=>d.id===id);
      if(!x)continue;
      const project=activeDcProject;
      for(const [key,label,index,type,choices] of [
        ['status','Statut',5,'select',DCS],
        ['owner','Responsable',6,'text',null],
        ['due','Échéance',7,'date',null],
        ['visibility','Visibilité',8,'select',['Interne','Client','Les 2']]
      ]) {
        const cell=tr.cells[index];cell.replaceChildren();
        const control=node(type==='select'?'select':'input',null,cell);
        control.className='dc-inline-editor';control.dataset.docField=key;control.dataset.docId=x.id;
        control.setAttribute('aria-label',label+' — '+x.ref);
        if(choices){const values=[...choices];if(x[key]&&!values.includes(x[key]))values.push(x[key]);for(const value of values){const option=node('option',value,control);option.value=value;}}
        else {control.type=type;if(type==='text')control.maxLength=190;}
        control.value=x[key]||(key==='visibility'?'Interne':'');
        let previous=control.value;
        const save=()=>{if(activeDcProject!==project||currentMode!=='dc-admin'||!control.checkValidity())return;x[key]=control.value;dcPersist();};
        control.oninput=save;
        control.onchange=()=>{save();if(previous!==x[key]){x.history=x.history||[];x.history.push({date:new Date().toISOString(),event:'Fiche modifiée',field:key,before:previous,after:x[key]});previous=x[key];dcPersist();}};
      }
      if(!x.fileName)continue;
      const b=node('button','Supprimer le fichier',tr.querySelector('.dc-actions'));b.className='danger';
      b.onclick=async()=>{
        if(!confirm('Supprimer le fichier « '+x.fileName+' » ? La fiche du document attendu sera conservée.'))return;
        b.disabled=true;const project=activeDcProject;
        try {await window.erpAsmDocuments.remove({project:dcBridgeProject(),document:{ref:x.ref}});
          Object.assign(x,{fileName:'',fileSize:0,fileModifiedAt:'',syncState:'Non accessible',status:'À produire'});
          x.history=x.history||[];x.history.push({date:new Date().toISOString(),event:'Fichier supprimé'});
          if(activeDcProject===project){dcPersist();dcRenderAll();}
        }catch(e){alert(e.message);b.disabled=false;}
      };
    }
  };
  const toolbar=dcAddDoc.parentElement;
  const exportOne=node('button','Exporter ce DC',toolbar),importOne=node('button','Importer ce DC',toolbar);
  const exportAll=node('button','Exporter tout',toolbar),importAll=node('button','Importer tout',toolbar);
  importOne.className=importAll.className='dc-admin-col';
  const note=node('p','Fichiers : 20 Mo maximum. Quota du propriétaire : 2 Go et 1 000 fichiers.',toolbar.parentElement);note.className='asm-note';
  function downloadConfig(family) {
    if(!dcData())return;
    const blob=new Blob([CpmpDocumentConfig.serialize(dcData().documents,family)],{type:'text/csv;charset=utf-8'}),url=URL.createObjectURL(blob);
    const a=node('a');a.href=url;a.download='CPMP-documents-attendus-'+(family||'tous-DC')+'.csv';a.click();setTimeout(()=>URL.revokeObjectURL(url),1000);
  }
  exportOne.onclick=()=>dcFamilyFilter.value?downloadConfig(dcFamilyFilter.value):alert('Sélectionnez une famille DC dans le filtre.');
  exportAll.onclick=()=>downloadConfig('');
  function importConfig(family) {
    if(currentMode!=='dc-admin')return;
    const input=node('input');input.type='file';input.accept='.csv,text/csv';
    const project=activeDcProject;
    input.onchange=async()=>{try{const file=input.files[0];if(!file)return;if(file.size>2*1024*1024)throw new Error('Maximum 2 Mo pour une configuration CSV.');
      const rows=CpmpDocumentConfig.parse(await file.text());
      if(activeDcProject!==project||currentMode!=='dc-admin')return;
      const docs=CpmpDocumentConfig.merge(dcData().documents,rows,family);
      if(!confirm(rows.length+' documents attendus à ajouter ou mettre à jour. Les autres fiches et fichiers seront conservés. Continuer ?'))return;
      dcData().documents=docs;dcPersist();dcRenderAll();
    }catch(e){alert('Import impossible : '+e.message);}};input.click();
  }
  importOne.onclick=()=>dcFamilyFilter.value?importConfig(dcFamilyFilter.value):alert('Sélectionnez une famille DC dans le filtre.');
  importAll.onclick=()=>importConfig('');

  let portfolioViewMode='summary';
  const summary=node('section',null,portfolioView);summary.className='asm-summary';summary.hidden=true;
  const roleNav=node('nav',null,portfolioView);roleNav.className='asm-role-nav';roleNav.setAttribute('aria-label','Navigation du portefeuille');
  portfolioView.prepend(roleNav,summary);
  function metrics(projects) {
    const result={count:projects.length,budget:0,cost:0,sales:0,late:0,alerts:0};
    for(const p of projects){const f=finances(p);result.budget+=f.budget;result.cost+=f.terminaison;result.sales+=f.prixVente;
      if(delay(p)>0)result.late++;if(f.ecart<0||(p.actions||[]).some(a=>/critique|haute/i.test(a.criticite||a.priorite||'')&&!/termin|clos/i.test(a.statut||'')))result.alerts++;
    }return result;
  }
  function delay(p){return Math.max(0,...(p.planningMacro||[]).map(x=>x.finActuelle&&x.finPrevue?Math.round((Date.parse(x.finActuelle)-Date.parse(x.finPrevue))/86400000)||0:0));}
  function kpis(m,compact=false){return [[m.count,'Projets'],[money(m.cost),'Coût à terminaison'],[m.sales?((m.sales-m.cost)/m.sales*100).toFixed(1)+' %':'—','Marge pondérée'],[m.late,'Projets en retard'],[m.alerts,'Projets à surveiller']].filter((_,i)=>!compact||i<3).map(([v,k])=>'<div class="asm-metric"><span>'+k+'</span><strong>'+v+'</strong></div>').join('');}
  function renderRoleNavigation(role) {
    roleNav.replaceChildren();
    const views=role==='direction'
      ? [['summary','Vue entreprise'],['managers','Synthèse par manager'],['portfolio','Portefeuille détaillé']]
      : [['summary','Vue de mon équipe'],['portfolio','Portefeuille détaillé']];
    for(const [mode,label] of views){const button=node('button',label,roleNav);button.type='button';button.className='asm-role-nav-button';
      if(mode===portfolioViewMode){button.classList.add('active');button.setAttribute('aria-current','page');}
      button.onclick=()=>{portfolioViewMode=mode;renderPortfolio(portfolioAdmin);};
    }
  }
  function renderSummary(projects,byManager=false) {
    const role=ERP_SESSION?.role,isDirector=role==='direction';summary.replaceChildren();
    node('p',byManager?'DIRECTION · MANAGERS':isDirector?'DIRECTION · ENTREPRISE':'MANAGER · MON GROUPE',summary).className='asm-eyebrow';
    node('h1',byManager?'Synthèse par manager':isDirector?'Vue entreprise':'Vue de mon équipe',summary);
    node('p',byManager?'Performance et points de vigilance des portefeuilles accessibles · Données synchronisées':'Situation des projets accessibles · Données synchronisées du portefeuille',summary).className='asm-note';
    const top=node('div',null,summary);top.className='asm-metrics';top.innerHTML=kpis(metrics(projects));
    const cloud=new Map((window.erpAsmProjects.cached?.()||[]).map(x=>[x.localId,x]));
    const groups=new Map();for(const p of projects){const c=cloud.get(p.meta.portfolioId);let key,label;
      if(byManager){key=String(c?.ownerId||c?.ownerEmail||p.meta.ownerEmail||p.meta.chefDeProjet||'manager-non-affecte');label=c?.ownerName||c?.managerName||c?.ownerEmail||p.meta.ownerEmail||p.meta.chefDeProjet||'Manager non affecté';}
      else {key=label=isDirector?((c?.groupName||'Sans groupe')+' · '+(c?.groupId||'—')):(c?.ownerEmail||p.meta.ownerEmail||p.meta.chefDeProjet||'Non affecté');}
      if(!groups.has(key))groups.set(key,{label,rows:[]});groups.get(key).rows.push(p);
    }
    const grid=node('div',null,summary);grid.className='asm-group-grid';
    for(const {label:name,rows} of groups.values()){const card=node('article',null,grid);card.className='asm-group';node('h2',name.replace(/ · (?:[0-9]+|—)$/,''),card);const counts=node('div',null,card);counts.className='asm-metrics';counts.innerHTML=kpis(metrics(rows),true);
      for(const p of rows){const f=finances(p),line=node('div',null,card);line.className='asm-project-line';
        const label=node('div',null,line);node('strong',p.meta.nomProjet,label);node('small',(p.meta.chefDeProjet||'Responsable non renseigné')+' · '+(p.meta.phaseActuelle||'')+' · '+(delay(p)>0?'Retard '+delay(p)+' j':f.ecart<0?'Dépassement budgétaire':'Sans alerte coût/délai'),label);
        const b=node('button','Détail PC ASM',line);b.onclick=()=>openProject(p.meta.portfolioId,portfolioAdmin&&projectCanEdit(p));
        const dc=node('button','Documents',line);dc.onclick=()=>openDCProject(p.meta.portfolioId,portfolioAdmin&&projectCanEdit(p));
      }
    }
    if(!projects.length)node('p','Aucun projet accessible pour le moment.',summary);
  }
  const originalPortfolio=renderPortfolio;
  let initialRole=null;
  renderPortfolio=function(adminMode=false){
    originalPortfolio(adminMode);
    const role=ERP_SESSION?.role,allowed=['direction','manager'].includes(role);
    if(role!==initialRole){portfolioViewMode=allowed?'summary':'portfolio';initialRole=role;}
    if(role!=='direction'&&portfolioViewMode==='managers')portfolioViewMode='summary';
    roleNav.hidden=!allowed;renderRoleNavigation(role);
    const showSummary=allowed&&portfolioViewMode!=='portfolio';summary.hidden=!showSummary;
    projectList.hidden=financialSummary.hidden=showSummary;
    if(showSummary)renderSummary(getProjects(),role==='direction'&&portfolioViewMode==='managers');
    if(adminMode){const projects=getProjects();for(const [i,card] of [...projectList.children].entries()){
      const p=projects[i];if(!p||!projectCanEdit(p)||p.meta.ownerEmail!==ERP_SESSION?.email)continue;
      const b=node('button','Transférer',card.lastElementChild);b.onclick=()=>transferProject(p);
    }}
  };
  async function transferProject(project) {
    try{
      const result=await window.erpAsmProjects.recipients(project);
      if(!result.members.length)return alert('Aucun destinataire éligible dans votre entreprise.');
      const ui=modal('Transférer « '+project.meta.nomProjet+' »');
      node('p','Le dossier complet, sa sauvegarde Cloud et ses fichiers passent au nouveau propriétaire. Un seul transfert par projet tous les sept jours. Vos accès seront recalculés selon votre rôle.',ui.body);
      const recipient=field(ui.body,'Destinataire','','text',[['','Sélectionner une personne'],...result.members.map(m=>[String(m.id),(m.name||m.email)+' — '+m.email])]);recipient.required=true;
      ui.submit.textContent='Confirmer le transfert';
      ui.form.onsubmit=async e=>{e.preventDefault();ui.submit.disabled=true;try{
        await window.erpAsmProjects.transfer(project,Number(recipient.value));
        await syncProjectsFromCloud();ui.dialog.close();renderPortfolio(portfolioAdmin);alert('Transfert terminé.');
      }catch(error){alert(error.message);ui.submit.disabled=false;}};
    }catch(e){alert(e.message);}
  }
})();
