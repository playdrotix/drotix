/* Drotix Tic Tac Toe - playable demo (vs The Doll or Pass & Play). No data leaves the browser. */
(function(){
var el=document.getElementById('game'); if(!el) return;
var $=function(s){return el.querySelector(s)};
var boardEl=$('.gp-board'),statusEl=$('.gp-status'),timerEl=$('.gp-timer i'),overEl=$('.gp-over');
var S={n:3,k:3,mode:'ai',diff:'hard',b:[],turn:'X',over:false,t:null,left:20,score:{X:0,O:0,D:0}};
var TL=20;
function lines(n,k){var L=[],r,c,i,l;for(r=0;r<n;r++)for(c=0;c<n;c++)[[0,1],[1,0],[1,1],[1,-1]].forEach(function(d){
 var er=r+d[0]*(k-1),ec=c+d[1]*(k-1);if(er<0||er>=n||ec<0||ec>=n)return;l=[];for(i=0;i<k;i++)l.push((r+d[0]*i)*n+c+d[1]*i);L.push(l)});return L}
var LN=[];
function winner(b){for(var i=0;i<LN.length;i++){var l=LN[i],v=b[l[0]];if(v&&l.every(function(x){return b[x]===v}))return{p:v,l:l}}return null}
function empties(b){var e=[];b.forEach(function(v,i){if(!v)e.push(i)});return e}
function minimax(b,p,a,be,me){var w=winner(b);if(w)return w.p===me?10:-10;var e=empties(b);if(!e.length)return 0;
 var best=p===me?-99:99;for(var i=0;i<e.length;i++){b[e[i]]=p;var s=minimax(b,p==='X'?'O':'X',a,be,me);b[e[i]]='';
 if(p===me){best=Math.max(best,s);a=Math.max(a,s)}else{best=Math.min(best,s);be=Math.min(be,s)}if(be<=a)break}return best}
function heur(b,me){var o=me==='X'?'O':'X',s=0;LN.forEach(function(l){var m=0,x=0;l.forEach(function(i){if(b[i]===me)m++;else if(b[i]===o)x++});
 if(m&&x)return;if(m)s+=Math.pow(10,m);if(x)s-=Math.pow(10,x)*1.1});return s}
function pick(b,me){var e=empties(b),o=me==='X'?'O':'X',i,w;
 if(S.diff==='easy'&&Math.random()<.6)return e[Math.random()*e.length|0];
 for(i=0;i<e.length;i++){b[e[i]]=me;w=winner(b);b[e[i]]='';if(w)return e[i]}
 if(S.diff!=='easy'||Math.random()<.5)for(i=0;i<e.length;i++){b[e[i]]=o;w=winner(b);b[e[i]]='';if(w)return e[i]}
 if(S.diff==='medium'&&Math.random()<.35)return e[Math.random()*e.length|0];
 if(S.n===3&&S.diff!=='easy'){var bs=-99,bm=[];e.forEach(function(m){b[m]=me;var s=minimax(b,o,-99,99,me);b[m]='';if(s>bs){bs=s;bm=[m]}else if(s===bs)bm.push(m)});return bm[Math.random()*bm.length|0]}
 var bs2=-1e9,bm2=[],c=(S.n-1)/2;e.forEach(function(m){b[m]=me;var s=heur(b,me)-(Math.abs((m/S.n|0)-c)+Math.abs(m%S.n-c))*.5;b[m]='';if(s>bs2){bs2=s;bm2=[m]}else if(s===bs2)bm2.push(m)});
 return bm2[Math.random()*bm2.length|0]}
function icon(p){return '<svg aria-hidden="true"><use href="#'+(p==='X'?'x':'o')+'"/></svg>'}
function render(win){boardEl.style.gridTemplateColumns='repeat('+S.n+',1fr)';boardEl.className='gp-board n'+S.n;boardEl.innerHTML='';
 S.b.forEach(function(v,i){var b=document.createElement('button');b.type='button';b.innerHTML=v?icon(v):'';
  b.setAttribute('aria-label','Row '+((i/S.n|0)+1)+', column '+(i%S.n+1)+(v?': '+v:': empty'));b.disabled=!!v||S.over||(S.mode==='ai'&&S.turn==='O');
  if(win&&win.l.indexOf(i)>-1)b.className='w';b.onclick=function(){move(i)};boardEl.appendChild(b)})}
function setStatus(t){statusEl.textContent=t}
function names(){return S.mode==='ai'?['You','The Doll']:['Player 1','Player 2']}
function score(){$('.s-x').textContent=S.score.X;$('.s-d').textContent=S.score.D;$('.s-o').textContent=S.score.O;var n=names();$('.n-x').textContent=n[0];$('.n-o').textContent=n[1]}
function tick(){clearInterval(S.t);S.left=TL;timerEl.style.transform='scaleX(1)';if(S.over)return;
 S.t=setInterval(function(){S.left-=.2;timerEl.style.transform='scaleX('+Math.max(S.left/TL,0)+')';
  if(S.left<=0){clearInterval(S.t);if(!(S.mode==='ai'&&S.turn==='O')){var e=empties(S.b);move(e[Math.random()*e.length|0],true)}}},200)}
function end(win){S.over=true;clearInterval(S.t);var o=overEl,h=o.querySelector('h3'),p=o.querySelector('p'),k;
 if(!win){k='draw';h.textContent='Draw';p.textContent='Nobody gets eliminated this round.';S.score.D++}
 else if(S.mode==='ai'){if(win.p==='X'){k='win';h.textContent='Survived';p.textContent='You outsmarted The Doll. Player 456 lives on.';S.score.X++}else{k='lose';h.textContent='Eliminated';p.textContent='The Doll never blinks. Try again.';S.score.O++}}
 else{k='win';h.textContent=names()[win.p==='X'?0:1]+' wins';p.textContent='Survivor of this round.';S.score[win.p]++}
 o.className='gp-over on '+k;score();render(win);setTimeout(function(){o.querySelector('button').focus({preventScroll:true})},60)}
function move(i,auto){if(S.over||S.b[i])return;S.b[i]=S.turn;var w=winner(S.b);
 if(w||!empties(S.b).length)return end(w);S.turn=S.turn==='X'?'O':'X';render();stat();tick();
 if(S.mode==='ai'&&S.turn==='O')setTimeout(function(){if(!S.over){move(pick(S.b.slice(),'O'))}},420+Math.random()*380)}
function stat(){var n=names();setStatus(S.mode==='ai'?(S.turn==='X'?'Your move':'The Doll is thinking…'):n[S.turn==='X'?0:1]+' · '+S.turn+' to move')}
function start(){S.k=S.n===3?3:4;LN=lines(S.n,S.k);S.b=new Array(S.n*S.n).fill('');S.turn='X';S.over=false;overEl.className='gp-over';render();stat();score();tick()}
function seg(name,cb){el.querySelectorAll('[data-'+name+']').forEach(function(b){b.onclick=function(){el.querySelectorAll('[data-'+name+']').forEach(function(x){x.setAttribute('aria-pressed','false')});b.setAttribute('aria-pressed','true');cb(b.getAttribute('data-'+name));start()}})}
seg('size',function(v){S.n=+v});seg('mode',function(v){S.mode=v;S.score={X:0,O:0,D:0};el.querySelector('.diff').style.display=v==='ai'?'':'none'});seg('diff',function(v){S.diff=v});
el.querySelectorAll('.again').forEach(function(b){b.onclick=start});
start();
})();
