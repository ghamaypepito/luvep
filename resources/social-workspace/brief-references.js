let epBriefFiles=[],epBriefSequence=0;
function epAddBriefFiles(files){
    const incoming=[...files].filter(file=>!epBriefFiles.some(row=>row.file.name===file.name&&row.file.size===file.size&&row.file.lastModified===file.lastModified));
    if(epBriefFiles.length+incoming.length>3)throw Error('Attach up to 3 reference files. Remove a file before adding another.');
    for(const file of incoming){
        if(!/\.(pdf|png|jpe?g|webp|txt)$/i.test(file.name)||file.type&&!['application/pdf','image/png','image/jpeg','image/webp','text/plain'].includes(file.type))throw Error('Use PDF, PNG, JPG, WebP or TXT reference files.');
        if(!file.size||file.size>4*1024*1024)throw Error('Each reference must be non-empty and no larger than 4 MB.');
        if(/\.txt$/i.test(file.name)&&file.size>50*1024)throw Error('Text references must be 50 KB or smaller.');
    }
    if([...epBriefFiles.map(row=>row.file),...incoming].reduce((sum,file)=>sum+file.size,0)>8*1024*1024)throw Error('Reference files must total 8 MB or less.');
    epBriefFiles.push(...incoming.map(file=>({id:++epBriefSequence,file})));
}
function epRemoveBriefFile(id){epBriefFiles=epBriefFiles.filter(row=>row.id!==id)}
function epClearBriefReferences(){epBriefFiles=[];if(typeof epRenderBriefReferences==='function')epRenderBriefReferences()}
function epBriefCapture(){return $('#campaign-fields').hidden?[]:[...epBriefFiles]}
function epReferenceSnapshot(){return epBriefCapture().map(({id,file})=>({id,name:file.name,size:file.size,lastModified:file.lastModified}))}
function epBriefRequest(inputs,files){
    const {references,...payload}=inputs;
    if(!files.length)return {headers:{'Content-Type':'application/json'},body:JSON.stringify(payload)};
    const body=new FormData();
    for(const [key,value] of Object.entries(payload)){if(Array.isArray(value))value.forEach(item=>body.append(key+'[]',item));else if(value!==undefined&&value!==null)body.append(key,value)}
    files.forEach(({file})=>body.append('references[]',file,file.name));
    return {headers:{},body};
}
// Reference picker UI
function epRenderBriefReferences(){
    const list=$('#brief-reference-list');list.replaceChildren();
    for(const row of epBriefFiles){
        const item=el('li'),label=el('span','',row.file.name+' \xb7 '+(row.file.size<1024*1024?Math.ceil(row.file.size/1024)+' KB':(row.file.size/1024/1024).toFixed(1)+' MB')),remove=el('button','text-link','Remove');
        item.style.display='flex';item.style.alignItems='center';item.style.gap='12px';item.style.justifyContent='space-between';item.style.marginTop='8px';label.style.overflowWrap='anywhere';remove.type='button';remove.setAttribute('aria-label','Remove reference '+row.file.name);remove.onclick=()=>{epRemoveBriefFile(row.id);epRenderBriefReferences()};item.append(label,remove);list.append(item);
    }
    $('#brief-reference-status').textContent=epBriefFiles.length?`${epBriefFiles.length} of 3 references attached. Ready to use with your brief.`:'';
}
$('#brief-references').onchange=event=>{try{epAddBriefFiles(event.target.files);epRenderBriefReferences()}catch(error){$('#brief-reference-status').textContent=error.message}finally{event.target.value=''}};
