import test from 'node:test';import assert from 'node:assert/strict';
import { money } from '../../resources/js/lib/epMoney.js';
import { epAdminClient } from '../../resources/js/lib/epAdminClient.js';
test('money preserves unknown, zero, fractional and large integer micro-USD exactly',()=>{
 for(const [input,want] of [[null,'Not recorded'],[undefined,'Not recorded'],['0','US$0.00'],['1','US$0.000001'],['1250000','US$1.25'],['1000001','US$1.000001'],['9007199254740993123456','US$9,007,199,254,740,993.123456'],['secret','Not recorded'],['-1','Not recorded']])assert.equal(money(input),want);
});
test('admin client requests only metadata endpoints with no-store, session and cancellation',async()=>{
 const calls=[];global.fetch=async(url,options)=>{calls.push({url,options});return {ok:true,status:200,json:async()=>({data:[]})};};
 const signal=new AbortController().signal;await epAdminClient.overview('month',{signal});await epAdminClient.runs({period:'7d',portal:'education',state:'blocked',before:12,signal});await epAdminClient.run(12,{signal});
 assert.equal(calls[1].url,'/api/ep-agent/admin/runs?period=7d&portal=education&state=blocked&before=12');
 for(const call of calls){assert.equal(call.options.credentials,'same-origin');assert.equal(call.options.cache,'no-store');assert.equal(call.options.signal,signal);assert.ok(!call.url.includes('/conversations'));assert.ok(!call.options.body);}
});
test('failed non-json admin requests stay failures',async()=>{global.fetch=async()=>({ok:false,status:503,json:async()=>{throw Error()}});assert.deepEqual(await epAdminClient.overview('month'),{ok:false,status:503,data:{}});});
