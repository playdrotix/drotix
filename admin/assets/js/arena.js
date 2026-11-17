/* Arena slider (leaderboard / tournament / winners / rewards) + tournament popup. Sample data only. */
(function(){
var d=document;
/* countdown to next Saturday 20:00 IST (14:30 UTC) */
function nextCup(){var n=new Date(),t=new Date(Date.UTC(n.getUTCFullYear(),n.getUTCMonth(),n.getUTCDate(),14,30,0));
 t.setUTCDate(t.getUTCDate()+((6-t.getUTCDay()+7)%7));if(t<=n)t.setUTCDate(t.getUTCDate()+7);return t}
var T=nextCup();
function pad(x){return x<10?'0'+x:''+x}
function tick(){var s=Math.max(0,Math.floor((T-new Date())/1000)),v=[Math.floor(s/86400),Math.floor(s%86400/3600),Math.floor(s%3600/60),s%60];
 d.querySelectorAll('.cd').forEach(function(c){c.querySelectorAll('b').forEach(function(b,i){b.textContent=pad(v[i])})})}
tick();setInterval(tick,1000);
/* slider */
var tr=d.querySelector('.sl-track');
if(tr){var sl=[].slice.call(tr.children),tabs=[].slice.call(d.querySelectorAll('.at button')),dots=d.querySelector('.dots'),cur=0,timer,hov=false;
 sl.forEach(function(s,i){var b=d.createElement('button');b.type='button';b.setAttribute('aria-label','Slide '+(i+1));b.onclick=function(){go(i)};dots.appendChild(b)});
 function mark(i){cur=i;tabs.forEach(function(t,k){t.setAttribute('aria-selected',k===i)});[].forEach.call(dots.children,function(b,k){b.setAttribute('aria-current',k===i)})}
 function go(i){i=(i+sl.length)%sl.length;tr.scrollTo({left:sl[i].offsetLeft-tr.offsetLeft,behavior:'smooth'});mark(i)}
 tabs.forEach(function(t,i){t.onclick=function(){go(i);auto()}});
 d.querySelector('.ar.pv').onclick=function(){go(cur-1);auto()};d.querySelector('.ar.nx').onclick=function(){go(cur+1);auto()};
 var st;tr.addEventListener('scroll',function(){clearTimeout(st);st=setTimeout(function(){var w=sl[0].offsetWidth+18;mark(Math.min(sl.length-1,Math.round(tr.scrollLeft/w)))},80)},{passive:true});
 function auto(){clearInterval(timer);if(matchMedia('(prefers-reduced-motion:reduce)').matches)return;timer=setInterval(function(){if(!hov&&!d.hidden)go(cur+1)},6500)}
 var sec=d.getElementById('arena');sec.addEventListener('mouseenter',function(){hov=true});sec.addEventListener('mouseleave',function(){hov=false});sec.addEventListener('touchstart',function(){hov=true},{passive:true});
 mark(0);auto()}
/* popup */
var p=d.getElementById('cup-pop');
if(p){var last,KEY='drotix_cup_seen';
 var vi=p.querySelector('.pop-b'),vr=p.querySelector('.pv-reg'),card=p.querySelector('.pop-card'),bn=p.querySelector('.pop-card>img');
 function view(reg){if(vi)vi.hidden=!!reg;if(vr)vr.hidden=!reg;if(bn)bn.style.display=reg?'none':'';card.scrollTop=0;if(reg){var f=vr.querySelector('input');if(f&&matchMedia('(min-width:700px)').matches)f.focus()}}
 function open(reg){last=d.activeElement;view(!!reg);p.classList.add('on');d.body.style.overflow='hidden';if(!reg)p.querySelector('.pop-x').focus()}
 function close(){p.classList.remove('on');d.body.style.overflow='';try{sessionStorage.setItem(KEY,'1')}catch(e){}if(last&&last.focus)last.focus()}
 p.querySelectorAll('[data-close]').forEach(function(b){b.addEventListener('click',close)});
 p.addEventListener('click',function(e){if(e.target===p)close()});
 d.addEventListener('keydown',function(e){if(!p.classList.contains('on'))return;if(e.key==='Escape')close();
  if(e.key==='Tab'){var f=p.querySelectorAll('button:not([hidden]),a[href],input,select');f=[].filter.call(f,function(x){return x.offsetParent!==null}),a=f[0],z=f[f.length-1];if(e.shiftKey&&d.activeElement===a){e.preventDefault();z.focus()}else if(!e.shiftKey&&d.activeElement===z){e.preventDefault();a.focus()}}});
 d.querySelectorAll('[data-open-cup]').forEach(function(b){b.addEventListener('click',function(e){e.preventDefault();open(b.hasAttribute('data-reg'))})});
 p.querySelectorAll('[data-show-reg]').forEach(function(b){b.addEventListener('click',function(){view(true)})});
 p.querySelectorAll('[data-show-info]').forEach(function(b){b.addEventListener('click',function(){view(false)})});
 if(location.hash==='#register-cup')setTimeout(function(){open(true)},300);
 var seen=false;try{seen=sessionStorage.getItem(KEY)==='1'}catch(e){}
 if(!seen)setTimeout(function(){open(false)},1800)}
})();
