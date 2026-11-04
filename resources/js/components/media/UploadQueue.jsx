import {useEffect,useRef,useState} from 'react';
import axios from 'axios';
import {usePage} from '@inertiajs/react';
import {Upload,Check,X,RotateCcw} from 'lucide-react';
import {fileSize,mediaError} from './mediaApi';
const extensions=/\.(pdf|png|jpe?g|webp|mp4|mov|webm|docx?|xlsx?|csv|pptx?|txt)$/i;
export default function UploadQueue({getTarget,onUploaded,onBusyChange=()=>{},maxFiles=10,noteOnly=false}){
 const serverLimit=usePage().props?.media_drive?.max_upload_mb??100;
 const maxMb=Math.min(noteOnly?10:100,serverLimit);
 const [items,setItems]=useState([]),[error,setError]=useState(''),[drag,setDrag]=useState(false);
 const queue=useRef([]),running=useRef(false),alive=useRef(true),input=useRef(null),controller=useRef(null),cancelled=useRef(new Set()),callbacks=useRef({getTarget,onUploaded,onBusyChange});callbacks.current={getTarget,onUploaded,onBusyChange};
 useEffect(()=>()=>{alive.current=false;controller.current?.abort();},[]);
 const update=fn=>{queue.current=fn(queue.current);if(alive.current)setItems([...queue.current]);};
 const process=async()=>{if(running.current)return;running.current=true;callbacks.current.onBusyChange(true);
  try{while(alive.current){const item=queue.current.find(i=>i.status==='queued');if(!item)break;update(rows=>rows.map(i=>i.key===item.key?{...i,status:'uploading',progress:0,error:''}:i));
   try{const url=await callbacks.current.getTarget();if(!alive.current)break;if(cancelled.current.has(item.key))throw new Error('Upload canceled. Retry to check and finish the saved file.');controller.current=new AbortController();const form=new FormData();form.append('upload_key',item.key);form.append('file',item.file);
    const response=await axios.post(url,form,{timeout:0,signal:controller.current.signal,onUploadProgress:e=>{if(e.total)update(rows=>rows.map(i=>i.key===item.key?{...i,progress:Math.round(e.loaded/e.total*100)}:i));}});
    if(!alive.current)break;update(rows=>rows.map(i=>i.key===item.key?{...i,status:'done',progress:100}:i));callbacks.current.onUploaded(response.data.file);
   }catch(e){if(alive.current)update(rows=>rows.map(i=>i.key===item.key?{...i,status:'error',error:axios.isCancel(e)?'Upload canceled. Retry to check and finish the saved file.':mediaError(e)}:i));}
  }}finally{running.current=false;if(alive.current)callbacks.current.onBusyChange(false);}
 };
 const add=files=>{setError('');if(files.length>maxFiles){setError(`Choose up to ${maxFiles} files at a time.`);return;}
  const invalid=files.find(f=>!extensions.test(f.name)||f.size>maxMb*1048576||(noteOnly&&!/\.(pdf|png|jpe?g|webp)$/i.test(f.name)));
  if(invalid){setError(`${invalid.name}: ${noteOnly?`choose a PDF or image up to ${maxMb} MB`:`choose a supported file up to ${maxMb} MB`}.`);return;}
  update(rows=>[...rows,...files.map(file=>({key:crypto.randomUUID(),file,status:'queued',progress:0}))]);void process();
 };
 return <div className="md-upload"><div className={`md-drop ${drag?'md-dragging':''}`} onDragOver={e=>{e.preventDefault();setDrag(true);}} onDragLeave={()=>setDrag(false)} onDrop={e=>{e.preventDefault();setDrag(false);add([...e.dataTransfer.files]);}}><Upload size={24}/><strong>Drop files here or choose from your device</strong><p>{noteOnly?`PDF or images · up to ${maxMb} MB per file`:`PDF, images, Office documents and video · up to ${maxMb} MB per file`}</p><button type="button" className="md-secondary" onClick={()=>input.current.click()}>Choose files</button><input ref={input} aria-label="Choose files to upload" type="file" multiple hidden accept={noteOnly?'.pdf,.png,.jpg,.jpeg,.webp':'.pdf,.png,.jpg,.jpeg,.webp,.mp4,.mov,.webm,.doc,.docx,.xls,.xlsx,.csv,.ppt,.pptx,.txt'} onChange={e=>{add([...e.target.files]);e.target.value='';}}/></div>
  <p className="md-caption">Files are saved in this client’s Media Drive. Choose files below before attaching them to the profile.</p>
  {error&&<p role="alert" className="md-error">{error}</p>}
  {items.length>0&&<ul className="md-upload-list" aria-label="Upload progress">{items.map(item=><li key={item.key}><div><strong>{item.file.name}</strong><small>{fileSize(item.file.size)} · {item.status==='done'?'Uploaded':item.status==='uploading'?`Uploading ${item.progress}%`:item.status==='queued'?'Waiting':'Upload failed'}</small>{item.error&&<p role="alert" className="md-error">{item.error}</p>}{item.status==='uploading'&&<progress max="100" value={item.progress} aria-label={`Uploading ${item.file.name}`}/>}</div>{item.status==='done'?<Check size={18}/>:item.status==='error'?<button type="button" className="md-secondary" onClick={()=>{cancelled.current.delete(item.key);update(rows=>rows.map(i=>i.key===item.key?{...i,status:'queued'}:i));void process();}}><RotateCcw size={14}/>Retry</button>:item.status==='uploading'?<button type="button" className="md-icon" aria-label={`Cancel upload ${item.file.name}`} onClick={()=>{cancelled.current.add(item.key);controller.current?.abort();}}><X size={16}/></button>:null}</li>)}</ul>}
 </div>;
}
