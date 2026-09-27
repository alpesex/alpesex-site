/* Pure expected-document configuration helpers, shared by browser and tests. */
(function(root) {
  'use strict';
  const columns = ['famille','reference','nom','responsable','echeance','visibilite'];
  const validFamily = value => /^DC-(?:0[1-9]|10)$/.test(value);
  function parse(text) {
    text = String(text).replace(/^\uFEFF/, '');
    if (text.length > 2 * 1024 * 1024) throw new Error('Configuration CSV trop volumineuse.');
    const first = text.split(/\r?\n/, 1)[0];
    const delimiter = first.includes(';') ? ';' : ',';
    const rows = []; let row = [], value = '', quoted = false;
    for (let i=0; i<text.length; i++) {
      const c = text[i];
      if (c === '"') {
        if (quoted && text[i+1] === '"') { value += '"'; i++; }
        else if (quoted || value === '') quoted = !quoted;
        else throw new Error('Guillemets CSV invalides.');
      } else if (!quoted && (c === delimiter || c === '\n' || c === '\r')) {
        row.push(value); value = '';
        if (c !== delimiter) { if (row.some(Boolean)) rows.push(row); row=[]; if(c==='\r'&&text[i+1]==='\n')i++; }
      } else value += c;
    }
    if (quoted) throw new Error('Guillemets CSV non fermés.');
    row.push(value); if(row.some(Boolean))rows.push(row);
    const header = rows.shift() || [];
    if (header.join('|') !== columns.join('|')) throw new Error('Colonnes attendues : '+columns.join(';'));
    if (rows.length > 1000) throw new Error('Maximum 1 000 lignes par import.');
    const refs = new Set();
    return rows.map((r,i)=> {
      if(r.length!==columns.length)throw new Error('Nombre de colonnes incorrect à la ligne '+(i+2));
      // Undo the apostrophe that export uses to protect spreadsheet formulas.
      const [family,ref,title,owner,due,visibility]=r.map(x=>x.replace(/^'(?=[=+@\-\t\r])/, '').trim());
      if(!validFamily(family)||!new RegExp('^'+family+'-[0-9]{3,6}$').test(ref)||refs.has(ref)||!title||title.length>255||owner.length>190||!['Interne','Client','Les 2','Confidentiel'].includes(visibility))throw new Error('Configuration invalide à la ligne '+(i+2));
      if(due && (!/^\d{4}-\d{2}-\d{2}$/.test(due)||!Number.isFinite(Date.parse(due))||new Date(due).toISOString().slice(0,10)!==due))throw new Error('Date invalide à la ligne '+(i+2));
      refs.add(ref); return {family,ref,title,owner,due,visibility};
    });
  }
  function serialize(docs, family='') {
    const quote = x => '"'+String(x??'').replace(/^[=+@\-\t\r]/, m=>"'"+m).replace(/"/g,'""')+'"';
    return '\uFEFF'+[columns,...docs.filter(d=>!family||d.family===family).map(d=>[d.family,d.ref,d.title,d.owner,d.due,d.visibility||'Interne'])].map(r=>r.map(quote).join(';')).join('\r\n');
  }
  function merge(existing, incoming, family='') {
    if(family&&incoming.some(d=>d.family!==family))throw new Error('Le CSV contient une autre famille.');
    const docs=existing.map(d=>({...d}));
    for(const row of incoming) {
      const old=docs.find(d=>d.ref===row.ref);
      if(old)Object.assign(old,row);
      else docs.push({id:'DOC-'+crypto.randomUUID(),revision:'A',status:'À produire',history:[],fileName:'',...row});
    }
    return docs;
  }
  root.CpmpDocumentConfig = {parse,serialize,merge,validFamily};
})(globalThis);
