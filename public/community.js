(() => {
 const byId=id=>document.getElementById(id),escape=s=>String(s??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
 const main=document.querySelector('main'),editor=byId('json').closest('section');
 const guides=document.createElement('details');guides.id='writingGuides';
 guides.innerHTML='<summary>راهنمای نوشتن استراتژی و اندیکاتورها</summary><p>ساختار JSON، مراحل، شرط‌های ورود، ابطال، منابع تحلیل و تنظیم فیلترهای اندیکاتور را در راهنماها ببینید.</p><div class="row"><a href="guide.php?name=strategy" target="_blank" rel="noopener">خواندن راهنمای استراتژی</a><a href="guide.php?name=strategy&download=1">دانلود فایل راهنما</a><a href="guide.php?name=indicators" target="_blank" rel="noopener">خواندن راهنمای اندیکاتورها</a><a href="guide.php?name=indicators&download=1">دانلود فایل اندیکاتورها</a></div>';
 editor.insertBefore(guides,editor.children[1]);
 const account=document.createElement('section');account.id='accountBar';
 main.insertBefore(account,main.querySelector('header').nextSibling);
 const dialog=document.createElement('dialog');dialog.id='accountDialog';
 dialog.innerHTML='<div class="row"><h2>حساب کاربری</h2><button type="button" id="closeAccount">بستن</button></div><div class="row"><button type="button" data-account-mode="login">ورود</button><button type="button" data-account-mode="register">ثبت‌نام</button></div><form id="accountForm"><label id="accountNameLabel">نام نمایشی<input id="accountName" autocomplete="name" maxlength="80"></label><label>ایمیل<input id="accountEmail" type="email" autocomplete="username" required maxlength="254" dir="ltr"></label><label>رمز عبور<input id="accountPassword" type="password" autocomplete="current-password" required maxlength="72"></label><p id="accountHelp" class="small muted"></p><div id="accountMessage" role="status"></div><button id="submitAccount" class="primary" type="submit">ورود</button></form>';
 document.body.append(dialog);
 let mode='login',activation='',lastUser,comments=[],more=true,commentSignature='';
 const chooseMode=next=>{mode=next;byId('accountNameLabel').hidden=mode!=='register';byId('accountName').required=mode==='register';byId('accountPassword').minLength=mode==='login'?1:10;byId('accountPassword').autocomplete=mode==='login'?'current-password':'new-password';byId('submitAccount').textContent=mode==='register'?'ساخت حساب':mode==='activate_owner'?'فعال‌سازی و تعیین رمز':'ورود';byId('accountHelp').textContent=mode==='login'?'':'رمز عبور حداقل ۱۰ نویسه و حداکثر ۷۲ بایت باشد.';byId('accountMessage').textContent='';};
 dialog.onclick=e=>{if(e.target.dataset.accountMode)chooseMode(e.target.dataset.accountMode);};byId('closeAccount').onclick=()=>dialog.close();
 byId('accountForm').onsubmit=async e=>{
  e.preventDefault();byId('submitAccount').disabled=true;
  try{await request({action:mode,email:byId('accountEmail').value,password:byId('accountPassword').value,name:byId('accountName').value,token:activation});activation='';byId('accountPassword').value='';dialog.close();await refresh();msg('ورود به حساب انجام شد.');}
  catch(error){byId('accountMessage').textContent=error.message;byId('accountMessage').className='error';}
  finally{byId('submitAccount').disabled=false;}
 };
 const section=document.createElement('section');section.id='publicComments';
 section.innerHTML='<h2>نظرات عمومی</h2><p class="small muted">ثبت و مشاهدهٔ نظر برای همه آزاد است. نام و متن نظر به‌صورت عمومی نمایش داده می‌شود.</p><form id="commentForm"><label>نام<input id="commentName" required maxlength="80" autocomplete="name"></label><label>نظر شما<textarea id="commentBody" required maxlength="2000" rows="4"></textarea></label><button type="submit" id="submitComment" class="primary">ثبت نظر</button><span id="commentMessage" role="status"></span></form><div id="commentList"></div><button type="button" id="olderComments">نظرات قدیمی‌تر</button>';
 main.insertBefore(section,main.querySelector('footer'));
 const renderComments=()=>{
  const signature=JSON.stringify([comments,data?.user?.role]);if(signature===commentSignature)return;commentSignature=signature;
  byId('commentList').innerHTML=comments.map(c=>`<article class="public-comment"><b>${escape(c.name)}</b> <time class="small muted">${escape(new Date(c.created*1000).toLocaleString('fa-IR'))}</time><p>${escape(c.body)}</p>${data?.user?.role==='owner'?`<button type="button" data-remove-comment="${Number(c.id)}">حذف نظر</button>`:''}</article>`).join('')||'<p class="muted">هنوز نظری ثبت نشده است.</p>';
  byId('olderComments').hidden=!more||!comments.length;
 };
 byId('commentForm').onsubmit=async e=>{
  e.preventDefault();byId('submitComment').disabled=true;
  try{await request({action:'comment',name:byId('commentName').value,body:byId('commentBody').value});byId('commentBody').value='';byId('commentMessage').textContent='نظر شما ثبت شد.';await refresh();}
  catch(error){byId('commentMessage').textContent=error.message;}
  finally{byId('submitComment').disabled=false;}
 };
 byId('olderComments').onclick=async()=>{const button=byId('olderComments');button.disabled=true;try{const response=await fetch(`api.php?comments_before=${Math.min(...comments.map(c=>Number(c.id)))}`,{cache:'no-store'});const result=await response.json();if(!response.ok)throw Error(result.error);more=result.comments.length===50;const ids=new Set(comments.map(c=>c.id));comments.push(...result.comments.filter(c=>!ids.has(c.id)));renderComments();}catch(error){msg(error.message,true);}finally{button.disabled=false;}};
 byId('commentList').onclick=async e=>{const button=e.target.closest('[data-remove-comment]');if(!button)return;if(!confirm('این نظر حذف شود؟'))return;try{await request({action:'delete_comment',id:Number(button.dataset.removeComment)});comments=comments.filter(c=>Number(c.id)!==Number(button.dataset.removeComment));await refresh();renderComments();}catch(error){msg(error.message,true);}};
 window.community={render(){
  const user=data.user,identity=user?.id??null;
  const accountAnchor=user?main.querySelector('footer'):main.querySelector('header').nextElementSibling;
  if(accountAnchor!==account&&account.nextElementSibling!==accountAnchor)main.insertBefore(account,accountAnchor);
  if(lastUser!==undefined&&identity!==lastUser){byId('previewBox').replaceChildren();pending=null;editVersion=null;}
  lastUser=identity;
  const html=user?`<div class="row"><span>وارد شده‌اید: <b>${escape(user.name)}</b></span><button type="button" id="logoutAccount">خروج از حساب</button></div>`:'<div class="row"><span>استراتژی‌ها و هشدارها عمومی‌اند؛ برای ثبت استراتژی و مدیریت موارد خود وارد شوید.</span><button type="button" id="openAccount" class="primary">ورود / ثبت‌نام</button></div>';
  if(account.innerHTML!==html){account.innerHTML=html;if(user)byId('logoutAccount').onclick=async()=>{try{await request({action:'logout'});await refresh();}catch(error){msg(error.message,true);}};else byId('openAccount').onclick=()=>{chooseMode('login');dialog.showModal();};}
  byId('preview').disabled=!user;byId('preview').title=user?'':'ابتدا وارد حساب شوید';byId('settings').disabled=!data.can_manage_settings;byId('systemLogs').hidden=!data.can_manage_settings;
  for(const button of document.querySelectorAll('#detailsContent [data-manual-signal],#detailsContent [data-order-flow-visibility]')){const item=data.strategies.find(x=>x.config.id===strategyDetails.id);button.hidden=!item?.can_manage;}
  const latest=data.comments||[],ids=new Set(latest.map(c=>c.id));comments=latest.length?[...latest,...comments.filter(c=>!ids.has(c.id)&&Number(c.id)<Math.min(...latest.map(c=>Number(c.id)),Infinity))].slice(0,1000):[];if(comments.length<=50)more=latest.length===50;renderComments();
 }};
 chooseMode('login');
 const fragment=new URLSearchParams(location.hash.slice(1));const token=fragment.get('activate_owner');
 if(token){history.replaceState(null,'',location.pathname+location.search);activation=token;chooseMode('activate_owner');byId('accountEmail').value='ashkufaraz@gmail.com';dialog.showModal();}
})();
