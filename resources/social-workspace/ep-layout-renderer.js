async function epLoadImage(src){return new Promise((resolve,reject)=>{const image=new Image();image.onload=()=>resolve(image);image.onerror=()=>reject(Error('The selected image could not load.'));image.src=src})}
function epTextLines(ctx,text,width){
    const words=String(text).trim().split(/\s+/),lines=[];let line='';
    for(let word of words){while(ctx.measureText(word).width>width&&word.length>1){if(line){lines.push(line);line=''}let end=1;while(end<word.length&&ctx.measureText(word.slice(0,end+1)).width<=width)end++;lines.push(word.slice(0,end));word=word.slice(end)}
        const next=line?line+' '+word:word;if(ctx.measureText(next).width>width&&line){lines.push(line);line=word}else line=next}
    if(line)lines.push(line);return lines;
}
function epDrawText(ctx,text,box,size,weight){
    let lines;do{ctx.font=`${weight} ${size}px Urbanist, sans-serif`;lines=epTextLines(ctx,text,box.w);if(lines.length*size*1.16<=box.h)break;size--}while(size>8);
    ctx.textBaseline='top';ctx.fillStyle='#ffffff';lines.forEach((line,i)=>ctx.fillText(line,box.x,box.y+i*size*1.16));
}
async function drawEpLayout(canvas,photoSource,design,format){
    await document.fonts.load('700 40px Urbanist');await document.fonts.load('400 40px Urbanist');await document.fonts.ready;const photo=await epLoadImage(photoSource),logo=await epLoadImage('assets/logo.png');
    const w=format.width,h=format.height,m=Math.min(w,h)*.055,wide=w/h>1.5;canvas.width=w;canvas.height=h;
    const ctx=canvas.getContext('2d');ctx.imageSmoothingQuality='high';ctx.fillStyle='#436235';ctx.fillRect(0,0,w,h);
    const bottom=design.layout==='photo-bottom';let p,t;
    if(wide){const pw=Math.round(w*.53);p={x:bottom?w-pw:0,y:0,w:pw,h};t={x:bottom?0:pw,y:0,w:w-pw,h}}
    else{const ph=Math.round(h*.54);p={x:0,y:bottom?h-ph:0,w,h:ph};t={x:0,y:bottom?0:ph,w,h:h-ph}}
    const scale=Math.max(p.w/photo.naturalWidth,p.h/photo.naturalHeight),sw=p.w/scale,sh=p.h/scale;
    ctx.drawImage(photo,(photo.naturalWidth-sw)/2,(photo.naturalHeight-sh)/2,sw,sh,p.x,p.y,p.w,p.h);
    const lw=Math.min(w,h)*.13,lh=lw*logo.naturalHeight/logo.naturalWidth;
    ctx.fillStyle='#ffffff';ctx.fillRect(p.x+m-10,p.y+m-10,lw+20,lh+20);ctx.drawImage(logo,p.x+m,p.y+m,lw,lh);
    const inner={x:t.x+m,y:t.y+m,w:t.w-2*m,h:t.h-2*m};
    epDrawText(ctx,'EP \xb7 Education pathways',{...inner,h:inner.h*.08},Math.round(Math.min(w,h)*.024),400);
    epDrawText(ctx,design.headline,{...inner,y:inner.y+inner.h*.16,h:inner.h*.43},Math.round(Math.min(w,h)*.075),700);
    epDrawText(ctx,design.supporting_copy,{...inner,y:inner.y+inner.h*.64,h:inner.h*.19},Math.round(Math.min(w,h)*.031),400);
    epDrawText(ctx,design.cta,{...inner,y:inner.y+inner.h*.87,h:inner.h*.13},Math.round(Math.min(w,h)*.032),700);
}
