/* Keep hosting requests sequential; never start a permanent PHP process. */
const browserRunner = {
 running: false,
 nextAttempt: 0,
 async tick(csrf, refresh, message) {
  if(this.running || Date.now()<this.nextAttempt || !csrf)return;
  this.running=true;
  try {
   const response=await fetch('api.php',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},body:JSON.stringify({action:'tick'})});
   const result=await response.json();
   if(!response.ok)throw Error(result.error||'خطا در اجرای بررسی');
  } catch(error) {message('بررسی از مرورگر انجام نشد: '+error.message,true);}
  finally {this.running=false;this.nextAttempt=Date.now()+3000;await refresh();}
 }
};
