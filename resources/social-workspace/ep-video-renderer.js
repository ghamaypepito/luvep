function epVideoMimeType(){
    if(typeof MediaRecorder==='undefined'||typeof MediaRecorder.isTypeSupported!=='function'||typeof HTMLCanvasElement.prototype.captureStream!=='function')return '';
    return ['video/mp4;codecs=avc1.42E028','video/mp4'].find(type=>MediaRecorder.isTypeSupported(type))||'';
}
function epVideoPlacements(channel){
    return ({Facebook:['Reel','Story'],Instagram:['Reel','Story'],YouTube:['Shorts'],TikTok:['Vertical video']})[channel]||[];
}
function epDrawVideoFrame(canvas,scenes,seconds,duration){
    const ctx=canvas.getContext('2d'),span=duration/3,index=Math.min(2,Math.floor(seconds/span)),progress=Math.min(1,(seconds-index*span)/span);
    ctx.fillStyle='#214f44';ctx.fillRect(0,0,canvas.width,canvas.height);
    const draw=(scene,alpha,zoom)=>{ctx.save();ctx.globalAlpha=alpha;ctx.translate(540,938);ctx.scale(zoom,zoom);ctx.drawImage(scene,-432,-768,864,1536);ctx.restore();};
    draw(scenes[index],1,1+progress*.025);
    const blend=Math.max(0,Math.min(1,(progress- .90)/.10));
    if(index<2&&blend)draw(scenes[index+1],blend,1);
}
async function epCreateBrandedVideo({photo,design,duration,signal,onProgress=()=>{}}){
    if(![15,30].includes(duration))throw Error('Choose a 15 or 30 second video.');
    const mimeType=epVideoMimeType();
    if(!mimeType)throw Error('MP4 export is not supported in this browser. Use an up-to-date Chrome or Safari.');
    const check=()=>{if(signal?.aborted)throw new DOMException('Video generation cancelled.','AbortError');};
    check();
    const copy=JSON.parse(JSON.stringify(design)),scenes=[];
    const cards=[copy,{...copy,headline:copy.supporting_copy,supporting_copy:'',cta:''},{...copy,headline:copy.cta,supporting_copy:copy.headline,cta:''}];
    for(const card of cards){check();const scene=document.createElement('canvas');await drawEpLayout(scene,photo,card,{width:1080,height:1920});check();scenes.push(scene);}
    const canvas=document.createElement('canvas');canvas.width=1080;canvas.height=1920;epDrawVideoFrame(canvas,scenes,0,duration);
    const stream=canvas.captureStream(24);
    try{
        return await new Promise((resolve,reject)=>{
            let recorder,raf,timer,settled=false,started=0,lastProgress=-1,total=0;
            const chunks=[];
            const clean=()=>{cancelAnimationFrame(raf);clearTimeout(timer);signal?.removeEventListener('abort',abort);};
            const fail=error=>{if(settled)return;settled=true;clean();if(recorder?.state==='recording')recorder.stop();reject(error);};
            const abort=()=>fail(new DOMException('Video generation cancelled.','AbortError'));
            const stop=()=>{if(recorder?.state==='recording'){epDrawVideoFrame(canvas,scenes,duration,duration);recorder.stop();}};
            try{
                recorder=new MediaRecorder(stream,{mimeType,videoBitsPerSecond:4000000});
                recorder.ondataavailable=event=>{if(!event.data.size||settled)return;total+=event.data.size;if(total>=50*1024*1024){fail(Error('The video exceeds 50MB. Try the 15 second option.'));return;}chunks.push(event.data);};
                recorder.onerror=()=>fail(Error('The browser could not encode this video. Please try again.'));
                recorder.onstop=()=>{if(settled)return;settled=true;clean();if(!chunks.length){reject(Error('The browser returned an empty video. Please try again.'));return;}onProgress(100);resolve(new Blob(chunks,{type:'video/mp4'}));};
                signal?.addEventListener('abort',abort,{once:true});check();
                recorder.start(250);started=performance.now();
                const frame=()=>{if(settled||recorder.state!=='recording')return;const seconds=Math.min(duration,(performance.now()-started)/1000);epDrawVideoFrame(canvas,scenes,seconds,duration);const progress=Math.min(99,Math.floor(seconds/duration*100));if(progress!==lastProgress){lastProgress=progress;onProgress(progress);}if(seconds>=duration)stop();else raf=requestAnimationFrame(frame);};
                raf=requestAnimationFrame(frame);
                timer=setTimeout(stop,duration*1000+250);
            }catch(error){fail(error);}
        });
    }finally{stream.getTracks().forEach(track=>track.stop());scenes.forEach(scene=>{scene.width=scene.height=0;});canvas.width=canvas.height=0;}
}
