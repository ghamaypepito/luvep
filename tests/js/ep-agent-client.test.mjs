import test from 'node:test';
import assert from 'node:assert/strict';
import { epAgentClient } from '../../resources/js/lib/epAgentClient.js';

test('private requests use live session CSRF, no-store and abort identity', async () => {
    global.document={cookie:'XSRF-TOKEN=first%2Btoken',querySelector:()=>null};
    const calls=[];global.fetch=async(url,options)=>{calls.push({url,options});return {ok:true,status:201,json:async()=>({id:9})};};
    const signal=new AbortController().signal;
    await epAgentClient.list({signal});
    assert.equal(calls[0].options.cache,'no-store');assert.equal(calls[0].options.credentials,'same-origin');assert.equal(calls[0].options.signal,signal);
    await epAgentClient.append(9,'Hello');
    assert.equal(calls[1].options.headers['X-XSRF-TOKEN'],'first+token');
    assert.deepEqual(JSON.parse(calls[1].options.body),{content:'Hello'});
    document.cookie='XSRF-TOKEN=rotated';await epAgentClient.archive(9);
    assert.equal(calls[2].options.headers['X-XSRF-TOKEN'],'rotated');assert.equal(calls[2].options.method,'DELETE');
});
test('failed or malformed responses never report a saved request', async()=>{
    global.fetch=async()=>({ok:false,status:503,json:async()=>{throw Error('HTML');}});
    const result=await epAgentClient.append(9,'Private');
    assert.equal(result.ok,false);assert.equal(result.status,503);assert.deepEqual(result.data,{});
});
test('request cancellation stays observable and manager cursors are encoded',async()=>{
    global.fetch=async()=>{throw new DOMException('cancelled','AbortError');};
    await assert.rejects(epAgentClient.list(),{name:'AbortError'});
    let requested;global.fetch=async(url)=>{requested=url;return {ok:true,status:200,json:async()=>({})};};
    await epAgentClient.manager('immigration',{before:42,attention:true});
    assert.equal(requested,'/api/ep-agent/manager?portal=immigration&before=42&attention=1');
});
