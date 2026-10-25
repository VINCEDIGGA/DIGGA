(function () {
  "use strict";
  const $ = (id) => document.getElementById(id);
  const api = "../backend/api/";
  const caseId = new URLSearchParams(location.search).get("case") || "";
  const state = { unlocked:false, contexts:[], validation:null, assets:[], sources:[], assetId:"", job:null, jobRevision:null, jobs:[], localJobs:[], original:"", revision:0, undo:[""], undoAt:0, busy:false, width:50 };
  const source = $("source-editor");
  const e = (value) => String(value == null ? "" : value).replace(/[&<>"']/g, c => ({"&":"&amp;","<":"&lt;",">":"&gt;",'"':"&quot;","'":"&#39;"}[c]));
  const safeUrl = value => { try { const u = new URL(value); return /^https?:$/.test(u.protocol) ? u.href : ""; } catch (_) { return ""; } };
  const link = (url,label) => safeUrl(url) ? `<a class="ws-source-link" href="${e(safeUrl(url))}" target="_blank" rel="noopener noreferrer">${e(label)} ↗</a>` : "";
  function status(message,error=false) { $("workshop-status").textContent=message; $("workshop-status").classList.toggle("is-error",error); }
  async function request(path,options={},timeout=14000) {
    const controller=new AbortController(), timer=setTimeout(()=>controller.abort(),timeout);
    try { const response=await fetch(api+path,{...options,signal:controller.signal,headers:{Accept:"application/json",...(options.headers||{})}}); const data=await response.json().catch(()=>({})); if(!response.ok) throw new Error(data.error||`Tjänsten svarade med HTTP ${response.status}.`); return data; }
    catch(error) { if(error.name==="AbortError") throw new Error("Tjänsten svarade inte i tid. Försök igen."); throw error; }
    finally { clearTimeout(timer); }
  }
  const post=(path,body)=>request(path,{method:"POST",headers:{"Content-Type":"application/json"},body:JSON.stringify(body)});
  function busy(value) { state.busy=value; ["validate-source","format-source","load-asset"].forEach(id=>{$(id).disabled=value||!state.unlocked||(id==="load-asset"&&!$("asset-select").value)||(id==="format-source"&&$("source-format").value==="certificate");}); }
  function tabs(attribute,value) { document.querySelectorAll(`[${attribute}]`).forEach(button=>{ const active=button.getAttribute(attribute)===value; button.classList.toggle("is-active",active); button.setAttribute("aria-selected",String(active)); button.tabIndex=active?0:-1; const panel=$(button.getAttribute("aria-controls")); if(panel) panel.hidden=!active; }); }
  function resultTab(value) { tabs("data-result",value); if(value==="history") loadHistory(); }
  function summarySnapshot() {
    const blocked=privateMaterial();
    return {caseId,contexts:state.contexts,source:{name:$("source-name").value,format:$("source-format").value,content:blocked?"":source.value,blocked},validation:state.validation,job:blocked?null:state.job,jobAssociation:state.job?(state.jobRevision===state.revision?"current":"historical"):null,now:new Date().toISOString()};
  }
  function renderSummary() {
    if($("summary-area").hidden)return;
    if(!window.WorkshopSummary){$("summary-document").textContent="Sammanställningen kunde inte laddas. Ladda om sidan och försök igen.";$("summary-pdf").disabled=true;return;}
    $("summary-document").innerHTML=window.WorkshopSummary.render(summarySnapshot());
    $("summary-pdf").disabled=!state.unlocked;
  }
  function showArea(value,saveUrl=true) {
    if(!["files","summary","recipients"].includes(value))value="files";
    tabs("data-area",value);
    if(saveUrl){const url=new URL(location.href);if(value==="files")url.searchParams.delete("view");else url.searchParams.set("view",value);history.replaceState(null,"",url);}
    if(value==="summary"){
      renderSummary();
      if(caseId&&state.unlocked)DiggApi.getStepContexts(caseId).then(contexts=>{state.contexts=contexts||[];renderSummary();}).catch(()=>{});
    }
  }
  function meaningful(context) { return DiggApi.hasMeaningfulContext(context); }
  async function checkGate() {
    document.querySelectorAll("[data-case-link]").forEach(a=>{if(caseId){const u=new URL(a.getAttribute("href"),location.href);u.searchParams.set("case",caseId);a.href=u.href;}});
    if(!caseId){$("case-summary").textContent="Inget ärende valt";$("gate-message").textContent="Öppna verkstaden från Underlag i ett ärende. Spara kommunens beskrivning eller andra uppgifter först.";status("Välj ett ärende och spara underlag för att börja.");return;}
    const testMode=Boolean(window.DiggaWorkshopAccess&&window.DiggaWorkshopAccess.isUnlocked(caseId));
    try { let contexts=[];
      try{contexts=await DiggApi.getStepContexts(caseId);}catch(error){if(!testMode)throw error;}
      const context=(contexts||[]).find(meaningful);
      if(!context&&!testMode){$("case-summary").textContent="Ärendet saknar sparat underlag";$("gate-message").textContent="Fyll i och spara kommunens behov eller andra uppgifter på Underlag. Verkstaden blir tillgänglig när det finns något att arbeta med.";status("Spara underlag innan du använder verkstaden.");return;}
      state.contexts=contexts||[];state.unlocked=true;$("case-summary").textContent=testMode?"Testläge · underlagskravet är tillfälligt upplåst":context.organization_name||context.short_description||"Underlag sparat för det här ärendet";$("workspace-gate").hidden=true;$("workspace-content").hidden=false;status(testMode?"Testläge: välj ett SDK-exempel eller öppna din egen fil. Inget underlag har skapats av upplåsningen.":"Välj ett SDK-exempel eller öppna din egen fil.");await loadResources();busy(false);
      const requested = new URLSearchParams(location.search).get("asset");
      if (requested && Array.from($("asset-select").options).some(option=>option.value===requested)) { $("asset-select").value=requested; await openAsset(); }
      showArea(new URLSearchParams(location.search).get("view")||"files",false);
    }catch(error){status(error.message,true);$("gate-message").textContent="Kunde inte läsa underlaget. Öppna Underlag och spara på nytt.";}
  }
  async function loadResources(){
    try{const data=await request("sdk_resources.php");state.assets=Array.isArray(data.assets)?data.assets:[];state.sources=Array.isArray(data.sources)?data.sources:[];
      const allowed=new Set(["sdk-message-valid","sdk-message-invalid-date","sdk-message-invalid-sender","sdk-mtmk-openapi","sdk-addressbook-openapi","sdk-message-json"]), assets=state.assets.filter(a=>allowed.has(a.id));
      $("asset-select").innerHTML='<option value="">Välj SDK-exempel…</option>'+assets.map(a=>`<option value="${e(a.id)}">${e(a.title)}</option>`).join("");if(assets.length)$("asset-select").value=assets[0].id;else status("Inga exempel kunde hittas. Du kan öppna en egen fil.",true);
    }catch(error){$("asset-select").innerHTML='<option value="">Exempel kunde inte hämtas</option>';status(error.message+" Du kan fortfarande öppna en egen fil.",true);}
  }
  function detectFormat(name,content){if(/\.(pem|cer|crt)$/i.test(name)||/-----BEGIN CERTIFICATE-----/.test(content))return"certificate";if(/\.(yaml|yml)$/i.test(name)||/["']?openapi["']?\s*[:]/.test(content.slice(0,5000)))return"openapi";return /\.json$/i.test(name)||/^[\s]*[\[{]/.test(content)?"json":"xml";}
  function privateMaterial(){return /PRIVATE[\s_-]*KEY|-----BEGIN (?:ENCRYPTED )?(?:RSA |EC |DSA |OPENSSH )?PRIVATE/i.test(source.value)||/\.(p12|pfx|key)$/i.test($("source-name").value);}
  function checkInput(){if(!state.unlocked)throw new Error("Spara underlag i ärendet först.");if(privateMaterial())throw new Error("Privat nyckel eller nyckelbehållare upptäckt. Innehållet har inte skickats. Använd endast det publika PEM-certifikatet.");if(!source.value.trim())throw new Error("Öppna en fil, ett exempel eller klistra in innehåll först.");if(source.value.length>600000)throw new Error("Filen är för stor. Använd ett utdrag under 600 000 tecken.");}
  function sourceChanged(record=true){
    state.revision++;state.validation=null;state.job=null;state.jobRevision=null;state.original="";["approve-draft","reject-draft","copy-output","download-output"].forEach(id=>$(id).disabled=true);$("review-state").textContent="Källfil ändrad";
    $("output-text").textContent="Källfilen har ändrats. Skapa ett nytt utkast för granskning.";
    $("changes-panel").textContent="Skapa ett nytt utkast för att jämföra med källfilen.";
    $("validation-panel").innerHTML='<p class="ws-muted">Källfilen har ändrats. Kör Kontrollera fil för ett nytt resultat.</p>';
    if(record&&state.undo[state.undoAt]!==source.value){state.undo=state.undo.slice(0,state.undoAt+1);state.undo.push(source.value);if(state.undo.length>50)state.undo.shift();state.undoAt=state.undo.length-1;}
    $("line-numbers").textContent=Array.from({length:Math.min(source.value.split("\n").length,10000)},(_,i)=>i+1).join("\n");
    $("source-detail").textContent=source.value?`${source.value.split("\n").length} rader · ${new Intl.NumberFormat("sv-SE").format(new Blob([source.value]).size)} byte`:"Ingen fil öppnad";
    const cert=$("source-format").value==="certificate"||/-----BEGIN CERTIFICATE-----/.test(source.value);$("certificate-notice").hidden=!cert;$("format-source").disabled=cert||state.busy;
    if(!$("visual-panel").hidden)renderVisual();
    renderSummary();
  }
  function setSource(content,name,format){source.value=content;$("source-name").value=name;$("source-format").value=format||detectFormat(name,content);sourceChanged();}
  async function openAsset(){if(!$("asset-select").value||state.busy)return;busy(true);status("Hämtar SDK-exempel…");
    try{const data=await request("sdk_resources.php?asset_id="+encodeURIComponent($("asset-select").value)),a=data.asset||state.assets.find(a=>a.id===$("asset-select").value)||{};
      const content=typeof data.content==="string"?data.content:JSON.stringify(data.content,null,2), format=/openapi/i.test(a.id||"")?"openapi":/json/i.test(a.id||"")?"json":"xml",name=(a.local_path||"").split(/[\\/]/).pop()||(format==="openapi"?"sdk-mtmk-openapi.json":`sdk-exempel.${format}`);
      setSource(content,name,format);state.assetId=a.id||"";$("asset-provenance").hidden=false;$("asset-provenance").innerHTML=`<strong>${e(a.title||"SDK-exempel")}</strong> · ${link(a.url,"Original och källa")} ${/invalid/.test(a.id||"")?"Avsiktligt felaktigt testfall – prova kontrollen.":""}`;status("Exemplet är öppnat. Kontrollera filen för att se prövade regler.");tabs("data-editor","code");
    }catch(error){status(error.message,true);}finally{busy(false);}
  }
  async function openFile(file){if(!file)return;if(/\.(p12|pfx|key)$/i.test(file.name)){status("P12/PFX och privata nycklar ska inte öppnas här. Exportera enbart det publika certifikatet i PEM-format.",true);$("source-file").value="";return;}if(file.size>600000){status("Filen är för stor. Välj en fil under 600 kB.",true);return;}
    const content=await file.text();if(/PRIVATE[\s_-]*KEY|-----BEGIN (?:RSA |EC |DSA |OPENSSH )?PRIVATE/i.test(content)){status("Filen innehåller en privat nyckel. Inget har skickats vidare.",true);return;}
    if(/\.(cer|crt|pem)$/i.test(file.name)&&!content.includes("-----BEGIN CERTIFICATE-----")){status("Exportera det publika certifikatet till PEM-format och öppna det igen.",true);return;}
    setSource(content,file.name);state.assetId="";$("asset-provenance").hidden=true;status("Filen är öppnad. Välj Kontrollera fil för att granska innehållet.");
  }
  async function validate(){try{checkInput();}catch(error){status(error.message,true);return;}state.validation=null;busy(true);resultTab("validation");status("Kontrollerar filen med fasta regler…");const revision=state.revision,format=/-----BEGIN CERTIFICATE-----/.test(source.value)?"certificate":$("source-format").value;
    try{const data=await post("sdk_validate.php",{format,content:source.value,asset_id:state.assetId||undefined});if(state.revision!==revision){status("Filen ändrades under kontrollen. Kör kontrollen igen.",true);return;}state.validation=data;renderValidation(data,format);status(data.valid?"Kontrollen är klar. Läs omfattningen innan du använder resultatet.":"Kontrollen hittade avvikelser. Läs resultatet och korrigera källfilen.",!data.valid);}
    catch(error){$("validation-panel").innerHTML=`<div class="ws-validation-summary is-failed"><h3>Kontrollen kunde inte slutföras</h3><p>${e(error.message)}</p></div><p class="ws-muted">Inget godkänt resultat har skapats.</p>`;status(error.message,true);}finally{busy(false);renderSummary();}
  }
  function describe(value){return typeof value==="object"?JSON.stringify(value):typeof value==="boolean"?value?"Utförd":"Inte utförd":String(value);}
  function objectTable(object){return `<table class="ws-visual-table"><tbody>${Object.entries(object).map(([key,value])=>`<tr><th scope="row">${e(key)}</th><td>${e(describe(value))}</td></tr>`).join("")}</tbody></table>`;}
  function renderValidation(data,format){const coverage=data.coverage||{},summary=typeof coverage==="string"?coverage:coverage.summary||"Endast de kontroller som listas nedan har utförts.";
    const checks=(data.checks||[]).map(c=>{const s=String(c.status).toLowerCase(),failed=["fail","failed","error","invalid"].includes(s),skipped=!failed&&!["pass","passed","ok","valid","success"].includes(s);return `<li><span class="ws-check-icon ${failed?"is-fail":skipped?"is-skip":""}" aria-label="${failed?"Fel":skipped?"Information":"Godkänd"}">${failed?"!":skipped?"i":"✓"}</span><div><strong>${e(c.label||c.id)}</strong><p>${e(c.message)}</p></div></li>`;}).join("");
    const issues=(data.issues||[]).map(i=>`<li><strong>${e(i.severity==="error"?"Fel":"Kontrollera")}${i.path?" · "+e(i.path):""}</strong>${e(i.message)}${i.rule?"<br>Regel: "+e(i.rule):""}${link(i.source_url,"Källa till regeln")}</li>`).join("");
    const exclusions=Array.isArray(coverage.exclusions)?coverage.exclusions:coverage.exclusions?[coverage.exclusions]:[];
    const normalized=data.normalized&&typeof data.normalized==="object"&&format==="certificate"?`<h4>Publik certifikatmetadata</h4>${objectTable(data.normalized)}`:"";
    const urls=Array.from(new Set(data.source_urls||[]));
    $("validation-panel").innerHTML=`<div class="ws-validation-summary ${data.valid?"":"is-failed"}"><h3>${data.valid?"Inga fel i de utförda kontrollerna":"Filen behöver korrigeras"}</h3><p>${e(summary)}</p></div><ul class="ws-check-list">${checks}</ul><ul class="ws-issues">${issues}</ul>${normalized}<details class="ws-coverage" open><summary>Vad kontrollen täcker</summary><p>${e(summary)}</p>${typeof coverage.xsd!=="undefined"?`<p><strong>XSD:</strong> ${e(describe(coverage.xsd))}</p>`:""}${typeof coverage.schematron!=="undefined"?`<p><strong>Schematron:</strong> ${e(describe(coverage.schematron))}</p>`:""}${exclusions.length?`<ul>${exclusions.map(i=>`<li>${e(describe(i))}</li>`).join("")}</ul>`:""}<p>Resultatet ersätter inte SDK:s obligatoriska tester, säkerhetsgranskning eller DIGG:s godkännande.</p></details><div>${urls.map(url=>{const doc=state.sources.find(s=>s.url===url);return link(url,doc?doc.title:"Officiell källa");}).join("")}</div>`;
  }
  async function formatDraft(){try{checkInput();if($("source-format").value==="certificate"||/-----BEGIN CERTIFICATE-----/.test(source.value))throw new Error("Använd Kontrollera fil för certifikat. Certifikatinnehåll sparas inte som utkast.");}catch(error){status(error.message,true);return;}
    busy(true);status("Skapar ett granskningsbart utkast…");const original=source.value,revision=state.revision,format=$("source-format").value;
    const payload={case_id:caseId,template_key:{xml:"xml_payload",json:"json_payload",openapi:"openapi_yaml"}[format],file:{name:$("source-name").value.trim()||`underlag.${format}`,type:({xml:"application/xml",json:"application/json",openapi:"application/yaml"})[format],size:new Blob([source.value]).size,preview:source.value}};
    try{let job;try{const data=await post("reformat_files.php",payload);if(!data.job)throw new Error("Utkastet saknas i svaret.");job=data.job;}
      catch(error){let text=original;if(format==="json")text=JSON.stringify(JSON.parse(original),null,2);else if(format==="xml"){const xml=new DOMParser().parseFromString(original,"application/xml");if(xml.querySelector("parsererror"))throw new Error("XML är inte välformad. Korrigera filen innan du formaterar.");text=prettyXml(original);}
        job={id:"local-"+Date.now(),local:true,status:"draft",original_name:payload.file.name,reformatted_name:outputName(payload.file.name),reformatted_text:text,issues:["Utkastet finns bara i denna flik. Databasen kunde inte nås. "+error.message],created_at:new Date().toISOString()};state.localJobs.unshift(job);}
      if(revision!==state.revision){status("Källfilen ändrades. Utkastet skapades av den tidigare versionen; öppna det via Sparade utkast.",true);return;}
      renderJob(job,original);resultTab("output");status(job.local?"Ett lokalt utkast har skapats. Det är inte sparat i databasen.":"Utkastet är sparat. Granska innehåll och ändringar innan du godkänner.");
    }catch(error){status(error.message,true);}finally{busy(false);}
  }
  function outputName(name){const dot=name.lastIndexOf(".");return dot>0?name.slice(0,dot)+".utkast"+name.slice(dot):name+".utkast.txt";}
  function renderJob(job,original=null,jobRevision=original===null?null:state.revision){state.job=job;state.jobRevision=jobRevision;state.original=original;$("output-name").textContent=job.reformatted_name||"utkast.txt";$("output-text").textContent=job.reformatted_text||"";
    $("review-state").textContent=(job.local?"Lokalt · ":"")+({draft:"Utkast",approved:"Godkänt",rejected:"Avvisat"}[job.status]||"Utkast");
    $("format-issues").innerHTML=(job.issues||[]).map(i=>`<li>${e(typeof i==="string"?i:i.message||JSON.stringify(i))}</li>`).join("");
    ["copy-output","download-output","approve-draft","reject-draft"].forEach(id=>{$(id).disabled=false;});renderChanges();renderSummary();
  }
  async function review(action){if(!state.job||state.busy)return;busy(true);$("approve-draft").disabled=true;$("reject-draft").disabled=true;const job=state.job,revision=state.revision,jobRevision=state.jobRevision,original=state.original;
    try{if(!job.local)await post("reformat_files.php",{action,job_id:job.id,case_id:caseId});job.status=action==="approve"?"approved":"rejected";
      if(state.revision!==revision||state.job!==job){status("Beslutet gäller det tidigare utkastet. Källfilen har ändrats och behöver granskas på nytt.");return;}
      renderJob(job,original,jobRevision);status((job.status==="approved"?"Utkastet har godkänts av dig.":"Utkastet har avvisats.")+(job.local?" Beslutet finns bara i den här fliken.":" Beslutet är sparat."));}
    catch(error){status(error.message,true);if(state.revision===revision&&state.job===job){$("approve-draft").disabled=false;$("reject-draft").disabled=false;}}
    finally{busy(false);renderSummary();}
  }
  async function loadHistory(){ $("draft-list").textContent="Hämtar sparade utkast…";let warning="";
    try{const data=await request("reformat_files.php?case_id="+encodeURIComponent(caseId));state.jobs=data.jobs||[];}catch(error){state.jobs=[];warning=`<p class="ws-muted">${e(error.message)} Lokala utkast i den här fliken visas nedan.</p>`;}
    const jobs=state.localJobs.concat(state.jobs);$("draft-list").innerHTML=warning+(jobs.length?jobs.map((job,index)=>`<div class="ws-draft-card"><div><strong>${e(job.reformatted_name||job.original_name)}</strong><span class="ws-badge">${e(({draft:"Utkast",approved:"Godkänt",rejected:"Avvisat"})[job.status]||job.status)}${job.local?" · lokalt":""}</span></div><p>${e(job.created_at||"")} · ${e(job.original_name||"")}</p><button type="button" class="ws-btn ws-small" data-open-job="${index}">Öppna utkast</button></div>`).join(""):'<p class="ws-muted">Inga sparade utkast för det här ärendet ännu.</p>');
    $("draft-list").querySelectorAll("[data-open-job]").forEach(button=>button.addEventListener("click",()=>{renderJob(jobs[Number(button.dataset.openJob)],null);resultTab("output");status("Sparat utkast öppnat. Originalversionen följer inte med i historiksvaret.");}));
  }
  function prettyXml(text){let level=0;return text.trim().replace(/>\s*</g,">\n<").split("\n").map(line=>{const t=line.trim();if(/^<\//.test(t))level=Math.max(0,level-1);const row="  ".repeat(level)+t;if(/^<[^!?/][^>]*>/.test(t)&&!/\/>$/.test(t)&&!/<\/[^>]+>$/.test(t))level=Math.min(100,level+1);return row;}).join("\n");}
  function renderChanges(){if(state.original==null){$("changes-panel").innerHTML='<p class="ws-muted">Originalversionen finns inte i det sparade utkastet. Öppna källfilen och skapa ett nytt utkast för att jämföra.</p>';return;}
    const before=state.original.split(/\r?\n/),after=(state.job.reformatted_text||"").split(/\r?\n/);if(before.join("\n")===after.join("\n")){$("changes-panel").innerHTML='<p class="ws-muted">Inga textändringar. Utkastet har samma innehåll som källfilen.</p>';return;}
    let start=0;while(start<Math.min(before.length,after.length)&&before[start]===after[start])start++;let eb=before.length-1,ea=after.length-1;while(eb>=start&&ea>=start&&before[eb]===after[ea]){eb--;ea--;}
    const rows=before.slice(Math.max(0,start-2),start).map(text=>({type:"",text})).concat(before.slice(start,eb+1).map(text=>({type:"removed",text})),after.slice(start,ea+1).map(text=>({type:"added",text})),after.slice(ea+1,ea+3).map(text=>({type:"",text})));
    $("changes-panel").innerHTML='<p class="ws-muted">− Ursprungligt textblock · + Textblock i utkastet</p>'+rows.slice(0,2000).map(r=>`<span class="ws-diff-row ${r.type}">${r.type==="removed"?"− ":r.type==="added"?"+ ":"  "}${e(r.text)}</span>`).join("")+(rows.length>2000?'<p class="ws-muted">Jämförelsen har kortats. Ladda ner båda filerna för fullständig jämförelse.</p>':"");
  }
  function tree(label,value,depth=0){if(depth>8)return`<p>${e(label)}: …</p>`;if(value&&typeof value==="object")return`<details ${depth<2?"open":""}><summary>${e(label)} <small>(${Array.isArray(value)?value.length+" poster":Object.keys(value).length+" fält"})</small></summary>${Object.entries(value).slice(0,100).map(([key,item])=>tree(key,item,depth+1)).join("")}</details>`;return`<p>${e(label)}: <strong>${e(value)}</strong></p>`;}
  function renderVisual(){try{if(privateMaterial())throw new Error("Privat nyckel ska inte visas i verkstaden.");const content=source.value.trim(),format=$("source-format").value;
    if(!content){$("visual-panel").innerHTML='<p class="ws-muted">Öppna en fil för att visa strukturen.</p>';return;}
    if(format==="certificate"){$("visual-panel").innerHTML='<h3>Publikt certifikat</h3><p>Välj Kontrollera fil för att läsa utfärdare, giltighetstid och annan publik metadata. Certifikatet sparas inte som ett utkast.</p>';return;}
    if(format==="json"||(format==="openapi"&&/^[{[]/.test(content))){const data=JSON.parse(content);
      if(format==="openapi"){const rows=Object.entries(data.paths||{}).flatMap(([path,methods])=>Object.entries(methods).filter(([method])=>/^(get|post|put|patch|delete|head|options)$/.test(method)).map(([method,info])=>`<tr><td>${e(method.toUpperCase())}</td><td>${e(path)}</td><td>${e(info.summary||info.operationId||"")}</td></tr>`));$("visual-panel").innerHTML=`<h3>${e((data.info||{}).title||"OpenAPI")}</h3><p>OpenAPI ${e(data.openapi)} · version ${e((data.info||{}).version)}</p><table class="ws-visual-table"><thead><tr><th>Metod</th><th>Sökväg</th><th>Beskrivning</th></tr></thead><tbody>${rows.join("")}</tbody></table>`;}
      else $("visual-panel").innerHTML=tree("JSON",data);
    }else if(format==="xml"){const xml=new DOMParser().parseFromString(content,"application/xml");if(xml.querySelector("parsererror"))throw new Error("XML kunde inte läsas. Kontrollera syntaxen först.");let count=0;
      const node=(element,depth=0)=>{if(++count>500||depth>15)return'<p>Fler noder har dolts.</p>';return`<details ${depth<2?"open":""}><summary>&lt;${e(element.nodeName)}${Array.from(element.attributes).map(a=>" "+e(a.name)+'="'+e(a.value)+'"').join("")}&gt;</summary>${Array.from(element.children).map(child=>node(child,depth+1)).join("")}${!element.children.length?`<p>${e(element.textContent)}</p>`:""}</details>`;};$("visual-panel").innerHTML=node(xml.documentElement);
    }else{$("visual-panel").innerHTML='<h3>OpenAPI i YAML</h3><p>YAML-filen visas i kodläget. Använd Kontrollera fil för strukturkontroll mot de regler som stöds.</p>';}
    }catch(error){$("visual-panel").innerHTML=`<p class="ws-muted">${e(error.message)}</p>`;}
  }
  let recipientSearchRevision=0,addressSearchRevision=0;
  function addressProvenance(data){
    const date=new Date(data.fetched_at),when=Number.isNaN(date.getTime())?"":" · "+new Intl.DateTimeFormat("sv-SE",{dateStyle:"short",timeStyle:"short"}).format(date);
    const message=data.stale?"Tjänsten kunde inte uppdateras. Ett äldre sparat svar visas":data.cached?"Ett sparat svar visas":"Hämtat från Digg";
    $("address-source").innerHTML=link(data.source_url,"Källa: SDK adressbok OPEN-TEST")+`<br>${e(message+when)}.`;
  }
  const addressLimit=rows=>rows.length===20?'<p class="ws-muted">Visar de första 20 träffarna i denna vy.</p>':"";
  async function searchOrganizations(event){event.preventDefault();const revision=++recipientSearchRevision,q=$("recipient-search").value.trim();++addressSearchRevision;
    $("address-source").textContent="";$("address-results").textContent="Välj en organisation för att visa dess funktionsadresser.";
    $("address-results").removeAttribute("aria-busy");
    if(!q){$("organization-results").textContent="Ange ett organisationsnamn.";$("organization-results").removeAttribute("aria-busy");return;}
    $("organization-results").textContent="Söker i OPEN-TEST…";$("organization-results").setAttribute("aria-busy","true");
    try{const data=await request("sdk_addressbook.php?environment=open-test&type=organizations&q="+encodeURIComponent(q),{},20000),rows=Array.isArray(data.data)?data.data:[];
      if(revision!==recipientSearchRevision)return;
      $("organization-results").innerHTML=rows.length?rows.map((org,index)=>{const a=org.attributes||{};return`<button type="button" class="ws-org-button" data-org="${index}">${e(a.name||a.organizationName||org.id)}<span>${e(a.participantIdentifier||a.organizationNumber||org.id)}</span></button>`;}).join("")+addressLimit(rows):'<p class="ws-muted">Ingen organisation hittades i OPEN-TEST. Prova ett annat namn eller en del av namnet.</p>';
      $("organization-results").querySelectorAll("[data-org]").forEach(button=>button.addEventListener("click",()=>{$("organization-results").querySelectorAll("button").forEach(b=>b.classList.remove("is-active"));button.classList.add("is-active");loadAddresses(rows[Number(button.dataset.org)]);}));
      addressProvenance(data);
    }catch(error){if(revision===recipientSearchRevision)$("organization-results").innerHTML=`<p class="ws-muted">${e(error.message)}</p>`;}
    finally{if(revision===recipientSearchRevision)$("organization-results").removeAttribute("aria-busy");}
  }
  async function loadAddresses(org){const revision=++addressSearchRevision;$("address-source").textContent="";$("address-results").textContent="Hämtar funktionsadresser…";$("address-results").setAttribute("aria-busy","true");
    try{const data=await request("sdk_addressbook.php?environment=open-test&type=addresses&organization_id="+encodeURIComponent(org.id),{},20000),rows=Array.isArray(data.data)?data.data:[];
      if(revision!==addressSearchRevision)return;
      $("address-results").innerHTML=rows.length?`<p class="ws-muted">${e((org.attributes||{}).name||"Vald organisation")}</p>`+rows.map(row=>{const a=row.attributes||{};return`<div class="ws-address-card"><strong>${e(a.name||a.unitName||a.functionalAddress||"Funktionsadress")}</strong><p>${e(a.description||"")}</p><p>${e(a.identifier||a.functionalAddress||a.address||row.id)}</p></div>`;}).join("")+addressLimit(rows):'<p class="ws-muted">Inga publika funktionsadresser hittades för organisationen i OPEN-TEST.</p>';addressProvenance(data);
    }catch(error){if(revision===addressSearchRevision)$("address-results").innerHTML=`<p class="ws-muted">${e(error.message)}</p>`;}
    finally{if(revision===addressSearchRevision)$("address-results").removeAttribute("aria-busy");}
  }
  function download(name,text){const url=URL.createObjectURL(new Blob([text],{type:"text/plain;charset=utf-8"})),a=document.createElement("a");a.href=url;a.download=name.replace(/[\\/:*?"<>|]/g,"_");a.click();setTimeout(()=>URL.revokeObjectURL(url),1000);}
  function resize(percent){state.width=Math.max(30,Math.min(70,percent));$("workbench").style.gridTemplateColumns=`minmax(0,${state.width}fr) 12px minmax(0,${100-state.width}fr)`;$("workspace-divider").setAttribute("aria-valuenow",String(Math.round(state.width)));}
  document.querySelectorAll("[data-area]").forEach(b=>b.addEventListener("click",()=>showArea(b.dataset.area)));
  $("summary-back").addEventListener("click",()=>{showArea("files");$("area-files").focus();});
  $("summary-pdf").addEventListener("click",()=>{try{if(!state.unlocked||!window.WorkshopSummary)return;window.WorkshopSummary.downloadPdf(summarySnapshot());status("PDF-rapporten har exporterats med aktuell sammanställning.");}catch(error){status("Rapporten kunde inte exporteras: "+error.message,true);}});
  document.querySelectorAll("[data-result]").forEach(b=>b.addEventListener("click",()=>resultTab(b.dataset.result)));
  document.querySelectorAll("[data-editor]").forEach(b=>b.addEventListener("click",()=>{tabs("data-editor",b.dataset.editor);if(b.dataset.editor==="visual")renderVisual();}));
  document.querySelectorAll('[role="tablist"]').forEach(list=>list.addEventListener("keydown",event=>{if(!["ArrowLeft","ArrowRight","Home","End"].includes(event.key))return;const buttons=Array.from(list.querySelectorAll('[role="tab"]')),index=buttons.indexOf(document.activeElement);if(index<0)return;event.preventDefault();const next=event.key==="Home"?0:event.key==="End"?buttons.length-1:(index+(event.key==="ArrowRight"?1:-1)+buttons.length)%buttons.length;buttons[next].click();buttons[next].focus();}));
  $("load-asset").addEventListener("click",openAsset);$("asset-select").addEventListener("change",()=>busy(state.busy));
  $("upload-source").addEventListener("click",()=>$("source-file").click());$("source-file").addEventListener("change",()=>openFile($("source-file").files[0]));
  source.addEventListener("input",()=>sourceChanged());source.addEventListener("scroll",()=>{$("line-numbers").scrollTop=source.scrollTop;});
  $("source-name").addEventListener("change",()=>sourceChanged(false));$("source-format").addEventListener("change",()=>sourceChanged(false));
  $("source-undo").addEventListener("click",()=>{if(state.undoAt>0){source.value=state.undo[--state.undoAt];sourceChanged(false);}});$("source-redo").addEventListener("click",()=>{if(state.undoAt<state.undo.length-1){source.value=state.undo[++state.undoAt];sourceChanged(false);}});
  $("validate-source").addEventListener("click",validate);$("format-source").addEventListener("click",formatDraft);$("approve-draft").addEventListener("click",()=>review("approve"));$("reject-draft").addEventListener("click",()=>review("reject"));
  $("download-source").addEventListener("click",()=>{try{checkInput();download($("source-name").value||"underlag.txt",source.value);}catch(error){status(error.message,true);}});$("download-output").addEventListener("click",()=>{if(state.job)download(state.job.reformatted_name||"utkast.txt",state.job.reformatted_text||"");});
  $("copy-output").addEventListener("click",async()=>{try{await navigator.clipboard.writeText(state.job.reformatted_text||"");status("Utkastet har kopierats.");}catch(_){status("Kopiering misslyckades. Använd Ladda ner utkast.",true);}});
  $("refresh-history").addEventListener("click",loadHistory);$("recipient-form").addEventListener("submit",searchOrganizations);
  $("workspace-divider").addEventListener("keydown",event=>{if(["ArrowLeft","ArrowRight","Home"].includes(event.key)){event.preventDefault();resize(event.key==="Home"?50:state.width+(event.key==="ArrowRight"?3:-3));}});
  $("workspace-divider").addEventListener("pointerdown",event=>{event.preventDefault();const divider=event.currentTarget;divider.setPointerCapture(event.pointerId);const move=ev=>{const rect=$("workbench").getBoundingClientRect();resize((ev.clientX-rect.left)/rect.width*100);};const stop=()=>{divider.removeEventListener("pointermove",move);divider.removeEventListener("pointerup",stop);divider.removeEventListener("pointercancel",stop);};divider.addEventListener("pointermove",move);divider.addEventListener("pointerup",stop);divider.addEventListener("pointercancel",stop);});
  $("workspace-divider").addEventListener("dblclick",()=>resize(50));document.addEventListener("keydown",event=>{if((event.ctrlKey||event.metaKey)&&event.key==="Enter"){event.preventDefault();if(!state.busy)validate();}});
  tabs("data-area","files");tabs("data-editor","code");tabs("data-result","validation");busy(true);checkGate();
})();
