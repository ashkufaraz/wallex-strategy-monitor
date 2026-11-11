const fs=require('fs'),vm=require('vm'),assert=require('assert');
const esc=s=>String(s??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const ctx=vm.createContext({esc,Date}),now=Math.floor(Date.now()/1000);
vm.runInContext(fs.readFileSync('public/order-flow.js','utf8')+'\nthis.view=orderFlowView',ctx);
const item={config:{id:'s',symbol:'BTCUSDT'}},record={signal_id:'id',symbol:'BTCUSDT',kind:'SIGNAL',calculated_at:now,trade_window_start:now-300,trade_window_end:now,depth_received_at:now,data_completeness:'COMPLETE_WITHIN_REQUESTED_WINDOWS',advisory_status:'SUPPORTIVE',trade_count:10,indicator_values:{300:{window_seconds:300,normalized_delta:.5,cvd_trend:'UP',volume_label:'HIGH',delta:5,cvd:5,relative_volume:2,order_book:{imbalance:.5}}}};
const flow={enabled:true,visible:true,max_age_seconds:90,processor_last_seen:now,results:[record]};
for(const [status,label] of [['SUPPORTIVE','همسو با سیگنال'],['CONTRADICTORY','مخالف سیگنال'],['MIXED','خنثی یا ترکیبی'],['UNAVAILABLE','اطلاعات کافی نیست']]){
 const html=ctx.view.render({...flow,results:[{...record,advisory_status:status}]},item,'id');assert(html.includes(label)&&html.includes('Order Flow ابزار کمکی تحلیل است'));
 assert(html.includes('نتیجه ذخیره‌شده مربوط به زمان سیگنال')&&html.includes('تحلیل به‌روز بعدی'));
}
assert(ctx.view.render(flow,item,'id').includes('خریداران'));
const stale=ctx.view.render({...flow,results:[{...record,kind:'LATEST',trade_window_end:now-500}]},item,'id');
assert(stale.includes('data-flow-status="UNAVAILABLE"')&&!stale.includes('data-flow-status="SUPPORTIVE"'));
const incomplete=ctx.view.render({...flow,results:[{...record,data_completeness:'UNVERIFIED'}]},item,'id');
assert(!incomplete.includes('data-flow-status="SUPPORTIVE"'));
assert(!ctx.view.render(flow,item,'another-signal').includes('data-flow-status="SUPPORTIVE"'));
assert(ctx.view.render({...flow,visible:false},item,'id').includes('نمایش تحلیل کمکی'));
assert(ctx.view.render({enabled:false},item,'id')==='');
assert(ctx.view.render({...flow,processor_last_seen:null},item,'id').includes('پردازشگر کمکی فعال تأیید نشده'));
assert(!ctx.view.render({...flow,results:[{...record,advisory_status:'UNAVAILABLE',explanation:'<img src=x onerror=bad()>'}]},item,'id').includes('<img'));
assert(ctx.view.safeRender({...flow,results:[null]},item,'id').includes('سیگنال و بررسی ورود دستی مستقل باقی می‌مانند'));
let tasks=[],calls=[];
const runnerCtx=vm.createContext({Date,setTimeout:fn=>{tasks.push(fn);return tasks.length;},clearTimeout:()=>{},AbortController:class{constructor(){this.signal={}}abort(){}},fetch:async(...args)=>{calls.push(args);throw Error('offline')},browserRunner:{running:false},busy:false});
vm.runInContext(fs.readFileSync('public/order-flow-runner.js','utf8')+'\nthis.runner=orderFlowRunner',runnerCtx);
(async()=>{
 runnerCtx.runner.schedule({order_flow_enabled:true,browser_checks:true},'token');assert(tasks.length===0);
 runnerCtx.runner.schedule({order_flow_enabled:true,order_flow_browser_enabled:true,browser_checks:true},'token');await tasks[0]();
 assert(calls.length===1&&JSON.parse(calls[0][1].body).action==='order_flow_tick');
 assert(!runnerCtx.runner.running,'auxiliary outage must release its own flag');
 console.log('Order Flow renderer: four states, historical/latest separation, stale/incomplete masking, isolation, escaping, visibility and independent bounded runner passed.');
})().catch(error=>{console.error(error);process.exitCode=1});
