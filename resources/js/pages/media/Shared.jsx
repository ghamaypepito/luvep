import {useEffect,useState} from 'react';
import {Head} from '@inertiajs/react';
import axios from 'axios';
import {Folder,LockKeyhole,ShieldCheck,ArrowLeft,Mail} from 'lucide-react';
import FileBrowser from '@/components/media/FileBrowser';
import FilePreview from '@/components/media/FilePreview';
import UploadQueue from '@/components/media/UploadQueue';
import {mediaError,Pager,useContents} from '@/components/media/mediaApi';
import '../../../css/media-drive.css';
export default function Shared({verified=false,base='',email_hint='',unavailable=null}){
 const [ready,setReady]=useState(verified),[email,setEmail]=useState(''),[code,setCode]=useState(''),[step,setStep]=useState('email'),[error,setError]=useState(''),[notice,setNotice]=useState(''),[saving,setSaving]=useState(false),[resendAt,setResendAt]=useState(0),[seconds,setSeconds]=useState(0),[folderId,setFolderId]=useState(null),[page,setPage]=useState(1),[preview,setPreview]=useState(null),[uploading,setUploading]=useState(false);
 const contents=useContents({...(folderId?{folder_id:folderId}:{}),page},base+'/contents',ready&&!unavailable);
 useEffect(()=>{setReady(verified);},[verified]);
 useEffect(()=>{if(!resendAt)return;const update=()=>setSeconds(Math.max(0,Math.ceil((resendAt-Date.now())/1000)));update();const timer=setInterval(update,1000);return()=>clearInterval(timer);},[resendAt]);
 useEffect(()=>{if(contents.error?.response?.status===403){setReady(false);setStep('email');setNotice('Your verification session ended. Request a new code to continue.');setPreview(null);}},[contents.error]);
 const send=async e=>{e?.preventDefault();if(saving)return;setSaving(true);setError('');try{const r=await axios.post(base+'/code',{email});setStep('code');setNotice(r.data.message);setResendAt(Date.now()+(r.data.resend_after||60)*1000);}catch(e){setError(mediaError(e));}finally{setSaving(false);}};
 const open=async e=>{e.preventDefault();if(saving)return;setSaving(true);setError('');try{await axios.post(base+'/verify',{code});setReady(true);setNotice('');setCode('');}catch(e){setError(mediaError(e));}finally{setSaving(false);}};
 const data=contents.data,dead=unavailable||(contents.error?.response?.status===410?mediaError(contents.error):null);
 return <div className="mdrive md-client-page"><Head title="Shared folder · Luvep"><meta name="robots" content="noindex,nofollow,noarchive"/><meta name="referrer" content="no-referrer"/></Head><header className="md-client-header"><img src="/images/ep-logo.png" alt="EP"/><span>Media Drive</span><span className="md-client-private"><LockKeyhole size={14}/>Private client folder</span></header>
 {dead?<main className="md-verification"><LockKeyhole size={28}/><h1>This folder link is unavailable</h1><p>{dead}</p><p>Ask your EP team for a new sharing link.</p></main>:!ready?<main className="md-verification"><span className="md-verify-icon"><ShieldCheck size={30}/></span><h1>Open your shared folder</h1><p>Verify the email invited by your EP team. Your code will be sent to that address.</p><p className="md-caption">Invited email: {email_hint}</p>{notice&&<p role="status" className="md-notice">{notice}</p>}{error&&<p role="alert" className="md-error">{error}</p>}
  {step==='email'?<form onSubmit={send}><label>Email address<input type="email" autoComplete="email" required value={email} onChange={e=>setEmail(e.target.value)}/></label><button className="md-primary" disabled={saving}>{saving?'Sending…':'Send verification code'}</button></form>:<form onSubmit={open}><label>Verification code<input type="text" inputMode="numeric" autoComplete="one-time-code" pattern="[0-9]{6}" maxLength={6} required value={code} onChange={e=>setCode(e.target.value.replace(/\D/g,''))}/></label><p className="md-caption">Six digits · expires in 10 minutes</p><button className="md-primary" disabled={saving||code.length!==6}>{saving?'Verifying…':'Verify & open folder'}</button><button type="button" className="md-text-button" disabled={saving||seconds>0} onClick={send}>{seconds>0?`Resend code in ${seconds}s`:'Resend code'}</button><button type="button" className="md-text-button" onClick={()=>{setStep('email');setError('');}}>Use a different email</button></form>}
 </main>:<main className="md-client-workspace">{contents.loading&&!data?<p role="status">Opening folder…</p>:contents.error?<div role="alert" className="md-error">{mediaError(contents.error)} <button onClick={contents.reload}>Retry</button></div>:data&&<><div className="md-page-heading"><div><h1>{data.folder.name}</h1><p>Shared with you by the EP team.</p></div><span className="md-access-badge">{data.permission==='editor'?'Editor · view, download and upload':'Viewer · view and download'}</span></div>
  {data.permission==='editor'&&<UploadQueue getTarget={()=>`${base}/folders/${data.folder.id}/files`} onBusyChange={setUploading} onUploaded={()=>contents.reload()}/>}
  <div className="md-breadcrumbs">{data.breadcrumbs?.map(f=><button type="button" key={f.id} disabled={uploading} onClick={()=>{setFolderId(f.id);setPage(1);}}>{f.name} /</button>)}<span>{data.folder.name}</span></div>
  <div className="md-folder-grid md-small-folders">{data.folders.map(f=><button type="button" key={f.id} disabled={uploading} onClick={()=>{setFolderId(f.id);setPage(1);}}><Folder size={20}/><span>{f.name}</span></button>)}</div>
  <FileBrowser files={data.files.data} onPreview={setPreview}/><Pager page={data.files} onPage={setPage}/><p className="md-caption">Uploads are reviewed by your EP team. Your access applies only to this shared folder.</p>
 </>}</main>}
 {preview&&<FilePreview file={preview} onClose={()=>setPreview(null)} client/>}
 </div>;
}
