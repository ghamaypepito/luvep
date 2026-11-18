import {useEffect,useState} from 'react';
import axios from 'axios';
import {Download,FileText} from 'lucide-react';
import MediaDialog from './MediaDialog';
import {FileIcon,Status} from './FileBrowser';
import {API,fileSize,mediaError} from './mediaApi';
export default function FilePreview({file,onClose,client=false}){
 const [detail,setDetail]=useState(null),[error,setError]=useState('');
 useEffect(()=>{if(client)return;let live=true;axios.get(`${API}/files/${file.id}`).then(r=>{if(live)setDetail(r.data);}).catch(e=>{if(live)setError(mediaError(e));});return()=>{live=false;};},[file.id,client]);
 return <MediaDialog title={file.name} description={fileSize(file.size)} onClose={onClose} wide><div className="md-preview-layout"><div className="md-preview-canvas">{file.mime?.startsWith('image/')?<img src={file.preview_url} alt={file.name}/>:file.mime?.startsWith('video/')?<video src={file.preview_url} controls preload="metadata"/>:file.mime==='application/pdf'?<iframe src={file.preview_url} title={file.name}/>:<div className="md-empty"><FileIcon file={file} large/><h3>Download this file to open it</h3><p>Office and text files keep their original format.</p></div>}</div><aside className="md-preview-meta"><h3>File details</h3><dl><dt>Review status</dt><dd><Status status={(detail?.file||file).status}/></dd><dt>Added by</dt><dd>{file.added_by||'EP team'}</dd><dt>Size</dt><dd>{fileSize(file.size)}</dd></dl><a className="md-primary" href={file.download_url}><Download size={16}/>Download file</a>{!client&&<><h3>Activity</h3>{error&&<p role="alert" className="md-error">{error}</p>}<ul className="md-activity">{detail?.events?.map(e=><li key={e.id}><strong>{e.kind.replaceAll('_',' ')}</strong><span>{e.actor} · {new Date(e.created_at).toLocaleString()}</span></li>)}</ul>{detail?.file?.reviews?.length>0&&<><h3>Attachment reviews</h3><p className="md-caption">Each attachment keeps its own review decision.</p><ul className="md-activity">{detail.file.reviews.map(r=><li key={r.id}><strong>{r.target}</strong><Status status={r.status}/></li>)}</ul></>}</>}</aside></div></MediaDialog>;
}
