/* ---------- Claude-Connector (MCP): Admin-Bereich und Profil der LK ---------- */
/* Am Server (eibengasse.at) kommen Einstellungen, Verbindungen und Protokoll von mcp-admin.php; in der Testversion bleiben sie im Browser. */
const MCP_GRP={lesen:"Kurse, Aufgaben und Termine lesen",schreiben:"Aufgaben und Termine anlegen und ändern",abgaben:"Abgaben lesen (Daten der SuS)",bewerten:"Bewerten, Feedback und Freigabe (Daten der SuS)"};
const MCP_WZ=[["kurse_auflisten","Meine Kurse auflisten","lesen"],["aufgaben_auflisten","Aufgaben eines Kurses auflisten","lesen"],["aufgabe_lesen","Aufgabe lesen","lesen"],["termine_auflisten","Termine auflisten","lesen"],
 ["aufgabe_anlegen","Aufgabe anlegen","schreiben"],["aufgabe_aendern","Aufgabe ändern","schreiben"],["termin_anlegen","Termin anlegen","schreiben"],["termin_aendern","Termin ändern","schreiben"],
 ["abgaben_auflisten","Abgaben einer Aufgabe auflisten","abgaben"],["abgabe_lesen","Abgabe eines Kindes lesen (mit Fotos und PDFs)","abgaben"],
 ["bewertung_eintragen","Bewertung eintragen","bewerten"],["feedback_senden","Feedback senden","bewerten"],["bewertungen_freigeben","Bewertungen freigeben","bewerten"]];
const MCP_SERVER=/(^|\.)eibengasse\.at$/.test(location.hostname);
let MCPS=null,MCP_LAEDT=false;
function mcpLokal(){const m=db.mcp||(db.mcp={aktiv:false,werkzeuge:{},lk:[],verb:[]});const lks=db.users.filter(u=>u.rolle!=="s"&&u.aktiv!==false).sort((a,b)=>(a.nn+a.vn).localeCompare(b.nn+b.vn,"de"));
 return {adresse:"https://mate.eibengasse.at/mcp",aktiv:!!m.aktiv,einstellungen:{aktiv:!!m.aktiv,werkzeuge:Object.fromEntries(MCP_WZ.map(([n])=>[n,!!m.werkzeuge[n]])),lk:[...m.lk]},
  lehrkraefte:lks.map(u=>({id:u.id,name:u.vn+" "+u.nn,mail:u.mail||""})),verbindungen:m.verb,protokoll:[],ich:{freigeschaltet:!!m.aktiv&&m.lk.includes(T1.id),verbindungen:m.verb.filter(v=>v.lk===T1.id)}}}
async function mcpAnfrage(body){if(!MCP_SERVER){const m=db.mcp||mcpLokal()&&db.mcp;if(body){if(body.aktion==="speichern"){const e=body.einstellungen;if("aktiv" in e)m.aktiv=!!e.aktiv;if(e.werkzeuge)Object.assign(m.werkzeuge,e.werkzeuge);if(e.lk)m.lk=e.lk;if(!m.aktiv)m.verb=[];m.verb=m.verb.filter(v=>m.lk.includes(v.lk))}
   if(body.aktion==="trennen"||body.aktion==="meine_trennen")m.verb=m.verb.filter(v=>v.verbindung!==body.verbindung);demoSpeichern()}return mcpLokal()}
 const o={credentials:"same-origin",headers:{"Accept":"application/json"}};if(body){o.method="POST";o.headers["Content-Type"]="application/json";o.headers["X-MATE-MCP-CSRF"]=(MCPS&&MCPS.csrf)||"";o.body=JSON.stringify(body)}
 const r=await fetch("mcp-admin.php",o);const j=await r.json().catch(()=>({}));if(!r.ok)throw new Error(j.fehler||"MATE hat nicht geantwortet.");return j}
function mcpLaden(){if(MCP_LAEDT)return;MCP_LAEDT=true;mcpAnfrage(null).then(s=>{MCPS=s}).catch(e=>{MCPS={fehler:e.message}}).finally(()=>{MCP_LAEDT=false;if(["claude","profil"].includes(st.view))render()})}
async function mcpSpeichern(body,ok){try{MCPS=await mcpAnfrage(body);if(ok)toast(ok)}catch(e){toast(e.message)}render()}
function mcpZeit(t){if(!t)return"–";const d=new Date(t);return `${d.getDate()}.${d.getMonth()+1}., ${String(d.getHours()).padStart(2,"0")}:${String(d.getMinutes()).padStart(2,"0")}`}
function mcpWzTitel(n){const w=MCP_WZ.find(x=>x[0]===n);return w?w[1]:n}
function mcpAnleitung(adr){return `1. In Claude: **Einstellungen → Connectors → „Connector hinzufügen“** (bzw. „Add custom connector“).\n2. Name: **MATE**, Adresse: **${adr}**\n3. „Verbinden“ – es öffnet sich die Anmeldeseite von MATE. Mit deinem MATE-Konto anmelden und **„Erlauben“**.\n4. In einem Chat z. B. fragen: *„Welche Aufgaben in meinem Kurs 2B sind noch nicht bewertet?“*\n\n! Claude fragt vor jeder Änderung nach. Trennen geht jederzeit hier oder in Claude.`}
function vClaudeA(){if(!MCPS&&!MCP_LAEDT)mcpLaden();const s=MCPS;
 const kopf=`<div class="head"><div><h1>Claude-Connector</h1><p>Lehrkräfte verbinden ihr eigenes Claude mit MATE und arbeiten dort mit ihren Kursen – nur mit ihren Rechten.</p></div></div>`;
 if(!s)return kopf+`<section class="panel"><div class="body"><p style="color:var(--muted)">Lädt …</p></div></section>`;
 if(s.fehler)return kopf+`<div class="note warn">${esc(s.fehler)}</div><div class="actions"><button class="btn" data-mcp="neu">Neu laden</button></div>`;
 const e=s.einstellungen,an=MCP_WZ.filter(([n])=>e.werkzeuge[n]).length,sus=MCP_WZ.some(([n,,g])=>e.werkzeuge[n]&&(g==="abgaben"||g==="bewerten"));
 const grp=Object.entries(MCP_GRP).map(([g,t])=>`<div class="body" style="border-top:1px solid var(--line)"><h3 style="margin:0">${esc(t)}</h3>${MCP_WZ.filter(w=>w[2]===g).map(([n,ti])=>`<label class="check"><input type="checkbox" data-mcp-w="${n}" ${e.werkzeuge[n]?"checked":""}> ${esc(ti)}</label>`).join("")}</div>`).join("");
 const lkAn=s.lehrkraefte.filter(l=>e.lk.includes(l.id)).length;
 const lks=s.lehrkraefte.map(l=>`<label class="check" data-mcp-lkzeile="${esc((l.name+" "+l.mail).toLowerCase())}"><input type="checkbox" data-mcp-lk="${l.id}" ${e.lk.includes(l.id)?"checked":""}> ${esc(l.name)} <small style="color:var(--muted)">${esc(l.mail)}</small></label>`).join("");
 const verb=s.verbindungen.length?`<ul class="list">${s.verbindungen.map(v=>`<li><div class="row"><span class="main"><b>${esc(v.name)}</b><span class="sub">verbunden seit ${mcpZeit(v.seit)} · zuletzt ${mcpZeit(v.zuletzt)}</span></span><button class="btn small" data-mcp="trennen" data-lk="${v.lk}" data-v="${esc(v.verbindung)}">Trennen</button></div></li>`).join("")}</ul>`:`<div class="body"><p style="color:var(--muted);margin:0">Noch keine Verbindungen.</p></div>`;
 const prot=(s.protokoll||[]).length?`<div class="tablewrap"><table><thead><tr><th>Zeit</th><th>Lehrkraft</th><th>Werkzeug</th><th>Ergebnis</th></tr></thead><tbody>${s.protokoll.map(p=>`<tr><td>${mcpZeit(p.zeit)}</td><td>${esc(p.name)}</td><td>${esc(mcpWzTitel(p.werkzeug))}</td><td>${p.ok?"ok":`<span class="pill p-late">Fehler</span>`} <small style="color:var(--muted)">${esc(p.info||"")}</small></td></tr>`).join("")}</tbody></table></div>`:`<div class="body"><p style="color:var(--muted);margin:0">${MCP_SERVER?"Noch keine Aufrufe.":"In der Testversion gibt es keinen echten Connector – hier stehen am Server die letzten 100 Aufrufe."}</p></div>`;
 return kopf+`
 <section class="panel"><div class="body"><label class="check"><input type="checkbox" id="mcp-aktiv" ${e.aktiv?"checked":""}> <b>Claude-Connector eingeschaltet</b></label>
  <span class="hint">${e.aktiv?`${an} von ${MCP_WZ.length} Werkzeugen, ${lkAn} ${lkAn===1?"Lehrkraft":"Lehrkräfte"} freigeschaltet.`:"Ausgeschaltet: niemand kann Claude verbinden, bestehende Verbindungen sind getrennt."}</span>
  <div class="field"><label for="mcp-adr">Adresse für Claude</label><div class="flex" style="gap:8px"><input type="text" id="mcp-adr" readonly value="${esc(s.adresse)}"><button class="btn" data-mcp="kopieren">Kopieren</button></div></div>
  ${warum("Was passiert mit den Daten?","Was Claude über den Connector liest, geht an **Anthropic** (Anbieter von Claude) – bei Abgaben und Bewertungen also **Namen und Arbeiten der SuS**.\n- Private Konten (Free, Pro, Max): jede LK schaltet in Claude unter Datenschutz das Training aus.\n- Team- oder Education-Vertrag der Schule: kein Training, Auftragsverarbeitung geregelt.\n\n! Werkzeuge mit Daten der SuS nur einschalten, wenn die Schulleitung zugestimmt hat. Jeder Aufruf steht unten im Protokoll (ohne Inhalte, 90 Tage).")}
 </div></section>
 ${sus?`<div class="note warn">Eingeschaltet sind Werkzeuge mit Daten der SuS (Abgaben, Bewertungen). Nur mit Zustimmung der Schulleitung verwenden.</div>`:""}
 <section class="panel"><header><h2>Werkzeuge</h2></header><div class="body"><p style="color:var(--muted);margin:0">Nur angehakte Werkzeuge sieht Claude. Jedes Werkzeug arbeitet mit den Rechten der LK: nur eigene Kurse, archivierte Kurse nur lesen.</p></div>${grp}</section>
 <section class="panel"><header><h2>Freigeschaltete Lehrkräfte</h2></header><div class="body">${s.lehrkraefte.length>8?`<input type="search" id="mcp-such" placeholder="Name oder E-Mail suchen" autocomplete="off">`:""}${lks||`<p style="color:var(--muted)">Keine Lehrkräfte.</p>`}<span class="hint">Nimmst du das Häkchen weg, wird die Verbindung dieser Lehrkraft sofort getrennt.</span></div></section>
 <section class="panel"><header><h2>Verbindungen</h2></header>${verb}</section>
 <section class="panel"><header><h2>Protokoll</h2></header>${prot}</section>`}
function mcpProfilHtml(){if(st.role==="schueler")return"";if(!MCPS&&!MCP_LAEDT)mcpLaden();const s=MCPS;if(!s||s.fehler||!s.aktiv||!s.ich||!s.ich.freigeschaltet)return"";
 const vs=s.ich.verbindungen||[];
 return `<div class="body" style="border-top:1px solid var(--line)"><h3>Claude-Connector</h3><p style="color:var(--muted)">Du kannst dein Claude mit MATE verbinden und dort mit deinen Kursen arbeiten (z. B. Aufgaben anlegen, Abgaben ansehen, Feedback vorschlagen lassen).</p>
 <div class="fmt">${fmt(mcpAnleitung(s.adresse))}</div>
 ${vs.length?`<ul class="list">${vs.map(v=>`<li><div class="row"><span class="main"><b>Claude</b><span class="sub">verbunden seit ${mcpZeit(v.seit)} · zuletzt ${mcpZeit(v.zuletzt)}</span></span><button class="btn small" data-mcp="meine-trennen" data-v="${esc(v.verbindung)}">Trennen</button></div></li>`).join("")}</ul>`:`<p style="color:var(--muted);margin:0">Noch nicht verbunden.</p>`}</div>`}
document.addEventListener("change",e=>{const t=e.target;if(!t.matches||!MCPS||!MCPS.einstellungen)return;
 if(t.id==="mcp-aktiv"){mcpSpeichern({aktion:"speichern",einstellungen:{aktiv:t.checked}},t.checked?"Claude-Connector eingeschaltet.":"Claude-Connector ausgeschaltet, alle Verbindungen getrennt.");return}
 if(t.dataset.mcpW){mcpSpeichern({aktion:"speichern",einstellungen:{werkzeuge:{[t.dataset.mcpW]:t.checked}}},`„${mcpWzTitel(t.dataset.mcpW)}“ ${t.checked?"eingeschaltet":"ausgeschaltet"}.`);return}
 if(t.dataset.mcpLk){const id=MCPS.lehrkraefte.find(l=>String(l.id)===t.dataset.mcpLk).id;const lk=MCPS.einstellungen.lk.filter(x=>x!==id);if(t.checked)lk.push(id);mcpSpeichern({aktion:"speichern",einstellungen:{lk}},t.checked?"Freigeschaltet.":"Freischaltung entfernt, Verbindung getrennt.")}});
document.addEventListener("input",e=>{if(e.target.id!=="mcp-such")return;const q=e.target.value.trim().toLowerCase();document.querySelectorAll("[data-mcp-lkzeile]").forEach(l=>{l.style.display=!q||l.dataset.mcpLkzeile.includes(q)||l.querySelector("input").checked?"":"none"})});
document.addEventListener("click",e=>{const b=e.target.closest&&e.target.closest("[data-mcp]");if(!b)return;const a=b.dataset.mcp;
 if(a==="neu"){MCPS=null;render();return}
 if(a==="kopieren"){const i=$("#mcp-adr");try{navigator.clipboard.writeText(i.value).then(()=>toast("Adresse kopiert."),()=>{i.select();toast("Adresse markiert – jetzt kopieren.")})}catch(x){i.select()}return}
 if(a==="trennen"){const lk=MCPS.verbindungen.find(v=>v.verbindung===b.dataset.v);mcpSpeichern({aktion:"trennen",lk:lk?lk.lk:0,verbindung:b.dataset.v},"Verbindung getrennt.");return}
 if(a==="meine-trennen"){mcpSpeichern({aktion:"meine_trennen",verbindung:b.dataset.v},"Claude ist getrennt.")}});
