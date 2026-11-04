import {useState} from 'react';
import axios from 'axios';
import {router} from '@inertiajs/react';
import {FolderOpen,Loader2} from 'lucide-react';
import {toast} from 'sonner';
import MediaPicker from './MediaPicker';
import {API,mediaError} from './mediaApi';
export default function MediaAttachButton({clientType='lead',clientId,checklistKey=null,requestId=null,dependentId=null,category='other',label='Media Drive / Upload',onAttached,className=''}){
 const [open,setOpen]=useState(false),[saving,setSaving]=useState(false),[error,setError]=useState('');
 const attach=async files=>{if(saving)return;setSaving(true);setError('');try{const r=await axios.post(API+'/attach',{client_type:clientType,client_id:clientId,file_ids:files.map(f=>f.id),checklist_key:checklistKey,request_id:requestId,dependent_id:dependentId,category});setOpen(false);toast.success(r.data.attached?'Files attached to this client.':'These files are already attached here.');if(onAttached)onAttached();else router.reload({preserveScroll:true});}catch(e){setError(mediaError(e));}finally{setSaving(false);}};
 return <><button type="button" className={className||'mdrive md-secondary md-attach-button'} onClick={()=>setOpen(true)} disabled={saving}>{saving?<Loader2 size={14}/>:<FolderOpen size={14}/>} {label}</button>{open&&<MediaPicker clientType={clientType} clientId={clientId} onClose={()=>{if(!saving)setOpen(false);}} onSelect={attach} saving={saving} externalError={error}/>} {error&&<p role="alert" className="mdrive md-error">{error}</p>}</>;
}
