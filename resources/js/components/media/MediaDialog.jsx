import {useEffect,useId,useRef} from 'react';
import {createPortal} from 'react-dom';
import {X} from 'lucide-react';
import '../../../css/media-drive.css';
export default function MediaDialog({title,description,onClose,busy=false,wide=false,children}){
 const ref=useRef(null),label=useId();
 useEffect(()=>{const previous=document.activeElement,overflow=document.body.style.overflow;document.body.style.overflow='hidden';ref.current.showModal();return()=>{ref.current?.close();document.body.style.overflow=overflow;if(previous?.isConnected)previous.focus?.();};},[]);
 return createPortal(<dialog ref={ref} aria-labelledby={label} className={`mdrive md-dialog ${wide?'md-dialog-wide':''}`} onCancel={e=>{e.preventDefault();if(!busy)onClose();}} onClick={e=>{if(e.target!==ref.current||busy)return;const r=ref.current.getBoundingClientRect();if(e.clientX<r.left||e.clientX>r.right||e.clientY<r.top||e.clientY>r.bottom)onClose();}}><header className="md-dialog-header"><div><h2 id={label}>{title}</h2>{description&&<p>{description}</p>}</div><button type="button" className="md-icon" aria-label="Close dialog" disabled={busy} onClick={onClose}><X size={19}/></button></header><div className="md-dialog-body">{children}</div></dialog>,document.body);
}
