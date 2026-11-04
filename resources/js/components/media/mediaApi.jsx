import axios from 'axios';
import {useEffect,useRef,useState} from 'react';
export const API='/media-drive/api';
export const fileSize=n=>n<1024?`${n||0} B`:n<1048576?`${(n/1024).toFixed(0)} KB`:`${(n/1048576).toFixed(1)} MB`;
export const mediaError=error=>Object.values(error?.response?.data?.errors||{}).flat()[0]||error?.response?.data?.message||error?.message||'Could not complete this request. Please try again.';
export const statusLabel={Submitted:'Awaiting review',UnderReview:'Under review',Checked:'Referred for verification',Approved:'Approved',Rejected:'Needs attention',StaffShared:'Added by team',MixedReviews:'Review varies by attachment'};
export function useContents(params,base=API+'/folders',enabled=true){
 const [data,setData]=useState(null),[loading,setLoading]=useState(false),[error,setError]=useState(null),[revision,setRevision]=useState(0);
 const key=JSON.stringify(params),sequence=useRef(0);
 useEffect(()=>{if(!enabled)return;const run=++sequence.current,controller=new AbortController();let live=true;setLoading(true);setError(null);
  axios.get(base,{params:JSON.parse(key),signal:controller.signal}).then(r=>{if(live&&run===sequence.current)setData(r.data);}).catch(e=>{if(live&&run===sequence.current&&!axios.isCancel(e))setError(e);}).finally(()=>{if(live&&run===sequence.current)setLoading(false);});
  return()=>{live=false;controller.abort();};
 },[key,base,revision,enabled]);
 return {data,loading,error,reload:()=>setRevision(n=>n+1)};
}
export function Pager({page,onPage}){if(!page||page.last_page<2)return null;return <nav className="md-pager" aria-label="Files pagination"><button type="button" disabled={page.current_page<=1} onClick={()=>onPage(page.current_page-1)}>Previous</button><span>Page {page.current_page} of {page.last_page}</span><button type="button" disabled={page.current_page>=page.last_page} onClick={()=>onPage(page.current_page+1)}>Next</button></nav>}
