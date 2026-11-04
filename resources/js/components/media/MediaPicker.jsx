import {useEffect,useRef,useState} from 'react';
import axios from 'axios';
import {Folder,ChevronRight,ArrowLeft,Search,Check,X} from 'lucide-react';
import MediaDialog from './MediaDialog';
import FileBrowser from './FileBrowser';
import UploadQueue from './UploadQueue';
import {API,mediaError,Pager,useContents} from './mediaApi';
export default function MediaPicker(props){return <PickerBody key={`${props.clientType||'lead'}:${props.clientId}`} {...props}/>}
function PickerBody({clientType='lead',clientId,onClose,onSelect,maxFiles=10,noteOnly=false,externalError='',saving=false}){
 const [folderId,setFolderId]=useState(null),[root,setRoot]=useState(null),[tab,setTab]=useState('drive'),[search,setSearch]=useState(''),[page,setPage]=useState(1),[selected,setSelected]=useState([]),[error,setError]=useState(''),[busy,setBusy]=useState(false),[client,setClient]=useState(null);
 const folderPromise=useRef(null),alive=useRef(true);
 const contents=useContents(folderId?{folder_id:folderId,search,page}:{client_type:clientType,client_id:clientId});
 useEffect(()=>{let live=true;axios.get(API+'/clients',{params:{type:clientType,id:clientId}}).then(r=>{if(live)setClient(r.data.clients[0]||null);}).catch(()=>{});return()=>{live=false;alive.current=false;};},[]);
 useEffect(()=>{if(!folderId&&contents.data?.folders?.data?.[0]){const f=contents.data.folders.data[0];setRoot(f);setFolderId(f.id);}if(contents.data?.root)setRoot(contents.data.root);},[contents.data,folderId]);
 const toggle=file=>{setError('');setSelected(old=>{if(old.some(f=>f.id===file.id))return old.filter(f=>f.id!==file.id);if(old.length>=maxFiles){setError(`Choose up to ${maxFiles} files.`);return old;}return [...old,file];});};
 const ensure=async()=>{if(root?.id)return root.id;if(folderPromise.current)return folderPromise.current;
  folderPromise.current=(async()=>{try{const r=await axios.post(API+'/folders',{client_type:clientType,client_id:clientId,name:client?.name||'Client documents'});if(alive.current){setRoot(r.data.folder);setFolderId(r.data.folder.id);}return r.data.folder.id;}catch(e){const r=await axios.get(API+'/folders',{params:{client_type:clientType,client_id:clientId}});const f=r.data.folders.data[0];if(!f)throw e;if(alive.current){setRoot(f);setFolderId(f.id);}return f.id;}})();
  try{return await folderPromise.current;}finally{folderPromise.current=null;}
 };
 const getTarget=async()=>`${API}/folders/${folderId||await ensure()}/files`;
 const useFiles=()=>{if(!selected.length){setError('Choose at least one file.');return;}onSelect(selected);};
 return <MediaDialog title="Attach files" description={client?.name?`Choose files from ${client.name}’s Media Drive or upload new files.`:'Choose files from this client’s Media Drive or upload new files.'} onClose={onClose} busy={busy||saving} wide>
  <div className="md-tabs" role="group" aria-label="Attachment source"><button type="button" aria-pressed={tab==='drive'} onClick={()=>setTab('drive')}>Media Drive</button><button type="button" aria-pressed={tab==='upload'} onClick={()=>setTab('upload')}>Upload</button></div>
  {tab==='upload'&&<UploadQueue getTarget={getTarget} maxFiles={maxFiles} noteOnly={noteOnly} onBusyChange={setBusy} onUploaded={file=>{toggle(file);contents.reload();}}/>}
  {tab==='drive'&&<><div className="md-picker-tools"><button type="button" className="md-secondary" disabled={!contents.data?.folder?.parent_id||busy} onClick={()=>{setFolderId(contents.data.folder.parent_id);setPage(1);setSearch('');}}><ArrowLeft size={14}/>Back</button><span className="md-caption">{contents.data?.folder?.name||'Client folder'}</span><label className="md-search"><Search size={16}/><input aria-label="Search this client’s files" placeholder="Search files" value={search} onChange={e=>{setSearch(e.target.value);setPage(1);}}/></label></div>
   {contents.loading?<p role="status" className="md-empty">Loading client files…</p>:contents.error?<div role="alert" className="md-error">{mediaError(contents.error)} <button type="button" onClick={contents.reload}>Retry</button></div>:<><div className="md-folder-grid md-small-folders">{(Array.isArray(contents.data?.folders)?contents.data.folders:[]).map(folder=><button type="button" key={folder.id} onClick={()=>{setFolderId(folder.id);setPage(1);setSearch('');}}><Folder size={20}/><span>{folder.name}</span><ChevronRight size={14}/></button>)}</div><FileBrowser files={contents.data?.files?.data||[]} selected={selected.map(f=>f.id)} onToggle={toggle} noteOnly={noteOnly} pick/><Pager page={contents.data?.files} onPage={setPage}/></>}
  </>}
  {selected.length>0&&<div className="md-selection" aria-label="Selected files">{selected.map(file=><span key={file.id}><Check size={13}/>{file.name}<button type="button" aria-label={`Remove ${file.name}`} onClick={()=>toggle(file)}><X size={13}/></button></span>)}</div>}
  {(error||externalError)&&<p role="alert" className="md-error">{error||externalError}</p>}
  <footer className="md-dialog-actions"><span>{selected.length} selected · up to {maxFiles}</span><button type="button" className="md-secondary" disabled={busy} onClick={onClose}>Cancel</button><button type="button" className="md-primary" disabled={!selected.length||busy||saving} onClick={useFiles}>{saving?'Attaching…':'Use selected files'}</button></footer>
 </MediaDialog>;
}
