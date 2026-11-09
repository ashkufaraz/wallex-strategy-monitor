const fs=require('fs'),vm=require('vm'),assert=require('assert');
const esc=s=>String(s??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const content={innerHTML:'',textContent:'',dataset:{},querySelectorAll:()=>[]};
const dialog={scrollTop:20};
const sample=JSON.parse(fs.readFileSync('public/example.json','utf8'));
const context=vm.createContext({esc,time:n=>String(n||'—'),console,
 document:{getElementById:id=>id==='detailsContent'?content:dialog},
 data:{strategies:[{version:'v1',config:sample,state:{stage:'wait_break',last_check:100}}]},
 fetch:async()=>({ok:true,json:async()=>({logs:[{id:7,version:'v1',report:{checked:100,price:75000,action:'SHORT',from_stage:'a',stage:'b',status:'checked',trace:[{event:'price',kind:'transition',rule:1,condition:{left_source:{price:true},left:75000,op:'lt',right_source:76000,right:76000,result:true}}],waiting:[],trade:sample.trade}}]})})
});
vm.runInContext(fs.readFileSync('public/trade.js','utf8')+'\n'+fs.readFileSync('public/details.js','utf8')+'\nthis.details=strategyDetails;',context);
const details=context.details;
const unsafe={left_source:{price:true},right_source:10,left:20,right:10,op:'gt',result:true,error:'<img onerror=alert(1)>'};
assert(!details.condition(unsafe).includes('<img'));
assert(details.condition(unsafe).includes('20'));
assert(details.condition({result:null,error:'missing'}).includes('missing'));
const trade=details.trade(sample.trade,'SHORT',75000);
assert(trade.includes('450')&&trade.includes('0.45')&&trade.includes('1.11'));
const market={...sample.trade,entry:{type:'MARKET'}};
assert(details.trade(market,'SHORT',74800).includes('74800'));
assert(details.trade(null,'SHORT',75000).length>0);
(async()=>{
 details.id=sample.id;await details.refresh();
 assert(content.innerHTML.includes('75,000')&&content.innerHTML.includes('data-log="7"'));
 assert(!content.innerHTML.includes('data-log="7" open'));
 assert(content.innerHTML.includes('75450')&&content.innerHTML.includes('a'));
 assert.strictEqual(dialog.scrollTop,20);
 assert.strictEqual(details.loading,false);
 const item=context.data.strategies[0];
 const snapshot={checked:100,status:'checked',waiting:[{stage:'wait_break',rule:1,condition:{group:'all',result:false,children:[{left_source:{price:true},op:'gt',right_source:74000,left:75000,right:74000,result:true},{left_source:{price:true},op:'lt',right_source:74500,left:75000,right:74500,result:false}]}}]};
 const overview=details.conditionOverview(item,[],snapshot);
 assert(overview.includes('detail-rule yes')&&overview.includes('detail-rule no')&&overview.includes('detail-rule unknown'));
 assert(overview.includes('75,000')&&overview.includes('74,500'));
 assert(!details.dashboard({...item,config:{...item.config,name:'<img src=x>'}},[],snapshot).includes('<img'));
 const noData=details.dashboard(item,[],null);
 assert(noData.includes('هنوز قیمتی دریافت نشده')&&noData.includes('در انتظار رسیدن موتور'));
 assert(details.order(item,75000).includes('detail-metric stop'));
 assert(details.order(item,75000).includes('detail-metric target'));
 console.log('Diagnostic dialog rendering, observed values, trade calculations and escaping passed.');
})().catch(e=>{console.error(e);process.exitCode=1});
