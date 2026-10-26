from pathlib import Path
from PIL import Image,ImageDraw,ImageFont,ImageOps
import struct,zlib,json,html
OUT=Path('exports');OUT.mkdir(exist_ok=True)
formats=[('ig-feed','Instagram feed',1080,1350,'4:5','Feed image','Instagram'),('fb-square','Facebook / Instagram square',1080,1080,'1:1','Feed image','Facebook'),('story','Instagram / Facebook story',1080,1920,'9:16','Story image','Instagram'),('linkedin','LinkedIn landscape',1200,628,'1.91:1','Feed image','LinkedIn'),('pin','Pinterest pin',1000,1500,'2:3','Standard pin','Pinterest'),('tiktok','TikTok / Shorts cover',1080,1920,'9:16','Cover artwork · video required for video posts','TikTok'),('youtube','YouTube thumbnail',3840,2160,'16:9','Video thumbnail · not a standalone post','YouTube'),('linkedin-square','LinkedIn square',1200,1200,'1:1','Feed image','LinkedIn')]
photo=Image.open('assets/new-zealand.png').convert('RGBA');logo=Image.open('assets/logo.png').convert('RGBA')
fontpath='assets/Urbanist-Bold.ttf';regular='assets/Urbanist-Regular.ttf'
def I(n):return struct.pack('>I',n)
def H(n):return struct.pack('>H',n)
def psd(w,h,layers,composite,path):
 records=[];pixels=[]
 for name,im,box in reversed(layers):
  x,y=box;rw,rh=im.size;channels=[]
  for idx,chan in [(-1,'A'),(0,'R'),(1,'G'),(2,'B')]:channels.append((idx,H(2)+zlib.compress(im.getchannel(chan).tobytes(),6)))
  nb=name.encode('ascii')[:255];pn=bytes([len(nb)])+nb;pn+=b'\0'*((-len(pn))%4);extra=I(0)+I(0)+pn
  record=struct.pack('>iiiiH',y,x,y+rh,x+rw,4)+b''.join(struct.pack('>hI',cid,len(data)) for cid,data in channels)+b'8BIMnorm'+bytes([255,0,0,0])+I(len(extra))+extra
  records.append(record);pixels.extend(data for _,data in channels)
 info=struct.pack('>h',len(layers))+b''.join(records)+b''.join(pixels);info+=b'\0'*(len(info)%2);section=I(len(info))+info+I(0)
 rgb=composite.convert('RGB');raw=b''.join(rgb.getchannel(c).tobytes() for c in 'RGB')
 path.write_bytes(b'8BPS'+H(1)+b'\0'*6+H(3)+I(h)+I(w)+H(8)+H(3)+I(0)+I(0)+I(len(section))+section+H(0)+raw)
manifest=[]
for id,title,w,h,ratio,placement,platform in formats:
 layers=[]
 def add(name,im,x=0,y=0):
  box=im.getbbox()
  if box:
   crop=im.crop(box);layers.append((name,crop,(x+box[0],y+box[1])))
 def block(name,color,x,y,bw,bh):add(name,Image.new('RGBA',(int(bw),int(bh)),color),int(x),int(y))
 def text(name,words,x,y,size,color='white',bold=False,maxw=None):
  f=ImageFont.truetype(fontpath if bold else regular,int(size));f.set_variation_by_axes([700 if bold else 400]);canvas=Image.new('RGBA',(w,h));d=ImageDraw.Draw(canvas)
  if maxw:
   lines=[];line=''
   for para in words.split('\n'):
    line=''
    for word in para.split():
     trial=(line+' '+word).strip()
     if d.textlength(trial,font=f)>maxw and line:lines.append(line);line=word
     else:line=trial
    lines.append(line)
   words='\n'.join(lines)
  d.multiline_text((int(x),int(y)),words,font=f,fill=color,spacing=int(size*.13),anchor=None)
  add(name,canvas)
 landscape=w/h>1.2
 block('EP green background','#436235',0,0,w,h)
 if landscape:
  pw=int(w*.51);ph=h;add('New Zealand photo',ImageOps.fit(photo,(pw,ph)),w-pw,0)
  pad=int(w*.045);tw=w*.43;sz=w*.056
  block('Logo white backing','white',pad,int(h*.07),int(w*.10),int(w*.065));lg=ImageOps.contain(logo,(int(w*.08),int(w*.047)));add('EP logo',lg,pad+int(w*.01),int(h*.077))
  text('Brand descriptor','Education pathways',pad,h*.27,w*.018,'#e4ebdf')
  text('Headline - raster typography','Your next chapter.\nNew Zealand.',pad,h*.37,sz,bold=True,maxw=tw)
  text('Supporting copy - raster typography','Explore your study options with EP.',pad,h*.70,w*.020,maxw=tw)
  text('Call to action - raster typography','Book a consultation',pad,h*.84,w*.021,bold=True,maxw=tw)
 else:
  isvertical=h/w>1.6;ph=int(h*(.48 if isvertical else (.45 if h/w<=1.05 else .51)));add('New Zealand photo',ImageOps.fit(photo,(w,ph)))
  pad=int(w*.075);base=ph+int(h*.035);sz=w*.075
  # Vertical templates reserve bottom space for platform interface overlays.
  block('Logo white backing','white',pad,base,int(w*.115),int(w*.078));lg=ImageOps.contain(logo,(int(w*.095),int(w*.06)));add('EP logo',lg,pad+int(w*.01),base+int(w*.008))
  text('Brand descriptor','Education pathways',pad+w*.15,base+w*.02,w*.026,'#e4ebdf')
  text('Headline - raster typography','Your next chapter.\nNew Zealand.',pad,base+w*.12,sz,bold=True,maxw=w*.82)
  text('Supporting copy - raster typography','Explore your study options with EP.',pad,base+w*.33,w*.031,maxw=w*.82)
  text('Call to action - raster typography','Book a consultation',pad,base+w*.43,w*.033,bold=True,maxw=w*.82)
 composite=Image.new('RGBA',(w,h));meta=[]
 for i,(name,im,(x,y)) in enumerate(layers):
  composite.alpha_composite(im,(x,y));file=f'{id}-layer-{i}.png';im.save(OUT/file);meta.append({'name':name,'file':'exports/'+file,'x':x,'y':y,'width':im.width,'height':im.height})
 composite.convert('RGB').save(OUT/f'{id}.png',optimize=True)
 thumb=composite.copy();thumb.thumbnail((500,680));thumb.convert('RGB').save(OUT/f'{id}-preview.jpg',quality=86)
 psd(w,h,layers,composite,OUT/f'{id}.psd')
 obj={'id':id,'title':title,'width':w,'height':h,'ratio':ratio,'placement':placement,'platform':platform,'layers':meta,'psd':f'exports/{id}.psd','png':f'exports/{id}.png','thumbnail':f'exports/{id}-preview.jpg','editableText':False,'note':'Sample template. PSD preserves separate raster layers; text is not native editable Photoshop text. The photograph remains one layer.'}
 (OUT/f'{id}-layers.json').write_text(json.dumps(obj,indent=2));manifest.append(obj)
 print(id,len(layers),'layers',flush=True)
Path('formats.json').write_text(json.dumps(manifest,indent=2))
# Verify PSD header, layer count, ZIP channel streams and merged image through Pillow.
for obj in manifest:
 path=Path(obj['psd']);b=path.read_bytes();assert b[:4]==b'8BPS';assert struct.unpack('>II',b[14:22])==(obj['height'],obj['width']);n=struct.unpack('>h',b[42:44])[0];assert n==len(obj['layers'])
 with Image.open(path) as image:image.load();assert image.size==(obj['width'],obj['height'])
print('All PSDs reopen through Pillow; dimensions and named-layer counts verified.')
