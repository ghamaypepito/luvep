const assert=require('node:assert/strict'),fs=require('node:fs'),vm=require('node:vm');
let frames=[],clock=0,tracksStopped=0,recorders=0,rendered=0,supported=true;
const document={createElement:()=>({width:0,height:0,getContext:()=>({fillRect(){},save(){},restore(){},translate(){},scale(){},drawImage(){}}),captureStream:()=>({getTracks:()=>[{stop(){tracksStopped++;}}]})})};
class Recorder{
    static isTypeSupported(){return supported;}
    constructor(){recorders++;this.state='inactive';}
    start(){this.state='recording';}
    stop(){this.state='inactive';this.ondataavailable?.({data:new Blob(['MP4 bytes'])});this.onstop?.();}
}
const ctx={Blob,DOMException,Error,JSON,Math,HTMLCanvasElement:{prototype:{captureStream(){}}},MediaRecorder:Recorder,document,performance:{now:()=>clock},requestAnimationFrame:callback=>(frames.push(callback),frames.length),cancelAnimationFrame(){frames=[];},setTimeout:()=>1,clearTimeout(){},drawEpLayout:async()=>{rendered++;}};
vm.createContext(ctx);vm.runInContext(fs.readFileSync('resources/social-workspace/ep-video-renderer.js','utf8'),ctx);
const input={photo:'',design:{headline:'Plan with EP',supporting_copy:'Explore your options.',cta:'Talk to the team'},duration:15};
(async()=>{
    assert.deepEqual(Array.from(ctx.epVideoPlacements('YouTube')),['Shorts']);assert.equal(ctx.epVideoPlacements('X').length,0);
    supported=false;await assert.rejects(ctx.epCreateBrandedVideo(input),/MP4 export is not supported/);assert.equal(recorders,0);supported=true;
    await assert.rejects(ctx.epCreateBrandedVideo({...input,duration:500}),/15 or 30/);
    let progress=[];const complete=ctx.epCreateBrandedVideo({...input,onProgress:value=>progress.push(value)});await new Promise(resolve=>setImmediate(resolve));
    clock=15000;frames.shift()();const blob=await complete;assert.equal(blob.type,'video/mp4');assert.equal(rendered,3);assert.equal(tracksStopped,1);assert.equal(progress.at(-1),100);assert.equal(frames.length,0);
    const longer=ctx.epCreateBrandedVideo({...input,duration:30});await new Promise(resolve=>setImmediate(resolve));clock=45000;frames.shift()();assert.equal((await longer).type,'video/mp4');assert.equal(tracksStopped,2);
    const controller=new AbortController();const cancelled=ctx.epCreateBrandedVideo({...input,signal:controller.signal});await new Promise(resolve=>setImmediate(resolve));controller.abort();await assert.rejects(cancelled,{name:'AbortError'});assert.equal(tracksStopped,3);assert.equal(frames.length,0,'Cancellation releases animation and media tracks');
    const before=recorders;await assert.rejects(ctx.epCreateBrandedVideo({...input,signal:controller.signal}),{name:'AbortError'});assert.equal(recorders,before,'Already cancelled requests cannot open a recorder');
    console.log('Compose video: codec guard, duration validation, three scenes, MP4 output and cancellation cleanup verified.');
})().catch(error=>{console.error(error);process.exitCode=1;});
