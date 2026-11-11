// A separate, bounded request, scheduled only after primary polling/notifications.
const orderFlowRunner={
 running:false,timer:null,next:0,
 schedule(state,csrf){
  if(!state?.order_flow_enabled||!state.order_flow_browser_enabled||!state.browser_checks||this.timer||this.running||Date.now()<this.next)return;
  this.timer=setTimeout(async()=>{
   this.timer=null;
   if((typeof browserRunner!=='undefined'&&browserRunner.running)||(typeof busy!=='undefined'&&busy))return;
   this.running=true;const controller=new AbortController();const timeout=setTimeout(()=>controller.abort(),2300);
   try{await fetch('api.php',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},body:JSON.stringify({action:'order_flow_tick'}),signal:controller.signal});}
   catch(error){/* Auxiliary outage never interrupts price polling or notifications. */}
   finally{clearTimeout(timeout);this.running=false;this.next=Date.now()+3000;}
  },1200);
 }
};
