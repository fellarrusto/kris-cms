import{p as D,o as ve,A as we,G as xe,q as v,x as M,H as oe,I as ce,l as Me,J as be,K as N,L as re,M as Se,N as Ie,O as Ce,m as je,k as Le,P as _e,v as ze}from"./runtime-dom.esm-bundler-BIQKpi-v.js";const F=window.KRIS||{},G=F.api||"index.php";F.assets;const Ae=F.csrf||"",Oe={ADMIN:"admin"},B={LIVE:"live",STAGING:"staging"};let R=Ae;class I extends Error{constructor(n,t,s={}){super(n),this.status=t,this.data=s}}function qe(e){e&&(R=e)}async function J(e,{body:n,query:t}={}){const s=new URLSearchParams({api:e,...t||{}});let o;try{o=await fetch(`${G}?${s}`,{method:n===void 0?"GET":"POST",credentials:"same-origin",cache:"no-store",headers:n===void 0?{}:{"Content-Type":"application/json","X-Kris-Csrf":R},body:n===void 0?void 0:JSON.stringify(n)})}catch{throw new I("Connessione interrotta: non possiamo confermare la richiesta. Controlla la rete e riprova.",0)}const a=await o.json().catch(()=>null);if(a!=null&&a.csrf&&qe(a.csrf),o.status===401)throw window.location.reload(),new I("Sessione scaduta: accedi di nuovo.",401);if(!a)throw new I("Risposta non valida dal server: ricarica la pagina e riprova.",o.status);if(!o.ok||a.ok===!1)throw new I(a.error||"Richiesta non riuscita.",o.status,a);return a}async function Ft(e){const n=new FormData;n.append("file",e),n.append("csrf",R);const t=await fetch(G.replace(/index\.php$/,"upload.php"),{method:"POST",body:n,credentials:"same-origin",headers:{"X-Requested-With":"XMLHttpRequest"}}).catch(()=>null);if(!t)throw new I("Connessione interrotta durante il caricamento.",0);const s=await t.json().catch(()=>null);if(!t.ok||!(s!=null&&s.url))throw new I((s==null?void 0:s.error)||(t.status===403?"Sessione scaduta: accedi di nuovo.":"Caricamento non riuscito."),t.status);return s}async function Gt(e,n={}){const t=new FormData;t.append("op",e),t.append("csrf",R);for(const[a,c]of Object.entries(n))t.append(a,c);const s=await fetch(G.replace(/index\.php$/,"update.php"),{method:"POST",body:t,credentials:"same-origin",cache:"no-store"}).catch(()=>null);if(!s)throw new I("Connessione interrotta: ricarica la pagina tra qualche minuto per vedere com'è andata.",0);const o=await s.json().catch(()=>null);if(!o)throw new I("Risposta non valida: ricarica la pagina tra qualche minuto.",s.status);return o}const x={get:(e,n)=>J(e,{query:n}),post:(e,n={},t)=>J(e,{body:n,query:t})};/**
 * @license lucide-vue-next v0.468.0 - ISC
 *
 * This source code is licensed under the ISC license.
 * See the LICENSE file in the root directory of this source tree.
 */const Ee=e=>e.replace(/([a-z0-9])([A-Z])/g,"$1-$2").toLowerCase();/**
 * @license lucide-vue-next v0.468.0 - ISC
 *
 * This source code is licensed under the ISC license.
 * See the LICENSE file in the root directory of this source tree.
 */var E={xmlns:"http://www.w3.org/2000/svg",width:24,height:24,viewBox:"0 0 24 24",fill:"none",stroke:"currentColor","stroke-width":2,"stroke-linecap":"round","stroke-linejoin":"round"};/**
 * @license lucide-vue-next v0.468.0 - ISC
 *
 * This source code is licensed under the ISC license.
 * See the LICENSE file in the root directory of this source tree.
 */const Pe=({size:e,strokeWidth:n=2,absoluteStrokeWidth:t,color:s,iconNode:o,name:a,class:c,...l},{slots:p})=>D("svg",{...E,width:e||E.width,height:e||E.height,stroke:s||E.stroke,"stroke-width":t?Number(n)*24/Number(e):n,class:["lucide",`lucide-${Ee(a??"icon")}`],...l},[...o.map(u=>D(...u)),...p.default?[p.default()]:[]]);/**
 * @license lucide-vue-next v0.468.0 - ISC
 *
 * This source code is licensed under the ISC license.
 * See the LICENSE file in the root directory of this source tree.
 */const i=(e,n)=>(t,{slots:s})=>D(Pe,{...t,iconNode:n,name:e},s);/**
 * @license lucide-vue-next v0.468.0 - ISC
 *
 * This source code is licensed under the ISC license.
 * See the LICENSE file in the root directory of this source tree.
 */const Ne=i("ArrowRightIcon",[["path",{d:"M5 12h14",key:"1ays0h"}],["path",{d:"m12 5 7 7-7 7",key:"xquz4c"}]]);/**
 * @license lucide-vue-next v0.468.0 - ISC
 *
 * This source code is licensed under the ISC license.
 * See the LICENSE file in the root directory of this source tree.
 */const Re=i("CalendarIcon",[["path",{d:"M8 2v4",key:"1cmpym"}],["path",{d:"M16 2v4",key:"4m81vk"}],["rect",{width:"18",height:"18",x:"3",y:"4",rx:"2",key:"1hopcy"}],["path",{d:"M3 10h18",key:"8toen8"}]]);/**
 * @license lucide-vue-next v0.468.0 - ISC
 *
 * This source code is licensed under the ISC license.
 * See the LICENSE file in the root directory of this source tree.
 */const Ve=i("ChartColumnIcon",[["path",{d:"M3 3v16a2 2 0 0 0 2 2h16",key:"c24i48"}],["path",{d:"M18 17V9",key:"2bz60n"}],["path",{d:"M13 17V5",key:"1frdt8"}],["path",{d:"M8 17v-3",key:"17ska0"}]]);/**
 * @license lucide-vue-next v0.468.0 - ISC
 *
 * This source code is licensed under the ISC license.
 * See the LICENSE file in the root directory of this source tree.
 */const Te=i("CheckIcon",[["path",{d:"M20 6 9 17l-5-5",key:"1gmf2c"}]]);/**
 * @license lucide-vue-next v0.468.0 - ISC
 *
 * This source code is licensed under the ISC license.
 * See the LICENSE file in the root directory of this source tree.
 */const He=i("ChevronDownIcon",[["path",{d:"m6 9 6 6 6-6",key:"qrunsl"}]]);/**
 * @license lucide-vue-next v0.468.0 - ISC
 *
 * This source code is licensed under the ISC license.
 * See the LICENSE file in the root directory of this source tree.
 */const De=i("ChevronLeftIcon",[["path",{d:"m15 18-6-6 6-6",key:"1wnfg3"}]]);/**
 * @license lucide-vue-next v0.468.0 - ISC
 *
 * This source code is licensed under the ISC license.
 * See the LICENSE file in the root directory of this source tree.
 */const Ue=i("ChevronUpIcon",[["path",{d:"m18 15-6-6-6 6",key:"153udz"}]]);/**
 * @license lucide-vue-next v0.468.0 - ISC
 *
 * This source code is licensed under the ISC license.
 * See the LICENSE file in the root directory of this source tree.
 */const $e=i("CircleXIcon",[["circle",{cx:"12",cy:"12",r:"10",key:"1mglay"}],["path",{d:"m15 9-6 6",key:"1uzhvr"}],["path",{d:"m9 9 6 6",key:"z0biqf"}]]);/**
 * @license lucide-vue-next v0.468.0 - ISC
 *
 * This source code is licensed under the ISC license.
 * See the LICENSE file in the root directory of this source tree.
 */const Fe=i("ClockIcon",[["circle",{cx:"12",cy:"12",r:"10",key:"1mglay"}],["polyline",{points:"12 6 12 12 16 14",key:"68esgv"}]]);/**
 * @license lucide-vue-next v0.468.0 - ISC
 *
 * This source code is licensed under the ISC license.
 * See the LICENSE file in the root directory of this source tree.
 */const Ge=i("CodeIcon",[["polyline",{points:"16 18 22 12 16 6",key:"z7tu5w"}],["polyline",{points:"8 6 2 12 8 18",key:"1eg1df"}]]);/**
 * @license lucide-vue-next v0.468.0 - ISC
 *
 * This source code is licensed under the ISC license.
 * See the LICENSE file in the root directory of this source tree.
 */const We=i("ConstructionIcon",[["rect",{x:"2",y:"6",width:"20",height:"8",rx:"1",key:"1estib"}],["path",{d:"M17 14v7",key:"7m2elx"}],["path",{d:"M7 14v7",key:"1cm7wv"}],["path",{d:"M17 3v3",key:"1v4jwn"}],["path",{d:"M7 3v3",key:"7o6guu"}],["path",{d:"M10 14 2.3 6.3",key:"1023jk"}],["path",{d:"m14 6 7.7 7.7",key:"1s8pl2"}],["path",{d:"m8 6 8 8",key:"hl96qh"}]]);/**
 * @license lucide-vue-next v0.468.0 - ISC
 *
 * This source code is licensed under the ISC license.
 * See the LICENSE file in the root directory of this source tree.
 */const Ke=i("CopyIcon",[["rect",{width:"14",height:"14",x:"8",y:"8",rx:"2",ry:"2",key:"17jyea"}],["path",{d:"M4 16c-1.1 0-2-.9-2-2V4c0-1.1.9-2 2-2h10c1.1 0 2 .9 2 2",key:"zix9uf"}]]);/**
 * @license lucide-vue-next v0.468.0 - ISC
 *
 * This source code is licensed under the ISC license.
 * See the LICENSE file in the root directory of this source tree.
 */const Xe=i("DatabaseIcon",[["ellipse",{cx:"12",cy:"5",rx:"9",ry:"3",key:"msslwz"}],["path",{d:"M3 5V19A9 3 0 0 0 21 19V5",key:"1wlel7"}],["path",{d:"M3 12A9 3 0 0 0 21 12",key:"mv7ke4"}]]);/**
 * @license lucide-vue-next v0.468.0 - ISC
 *
 * This source code is licensed under the ISC license.
 * See the LICENSE file in the root directory of this source tree.
 */const Ze=i("ExternalLinkIcon",[["path",{d:"M15 3h6v6",key:"1q9fwt"}],["path",{d:"M10 14 21 3",key:"gplh6r"}],["path",{d:"M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6",key:"a6xqqp"}]]);/**
 * @license lucide-vue-next v0.468.0 - ISC
 *
 * This source code is licensed under the ISC license.
 * See the LICENSE file in the root directory of this source tree.
 */const Y=i("FileTextIcon",[["path",{d:"M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z",key:"1rqfz7"}],["path",{d:"M14 2v4a2 2 0 0 0 2 2h4",key:"tnqrlb"}],["path",{d:"M10 9H8",key:"b1mrlr"}],["path",{d:"M16 13H8",key:"t4e002"}],["path",{d:"M16 17H8",key:"z1uh3a"}]]);/**
 * @license lucide-vue-next v0.468.0 - ISC
 *
 * This source code is licensed under the ISC license.
 * See the LICENSE file in the root directory of this source tree.
 */const Be=i("GlobeIcon",[["circle",{cx:"12",cy:"12",r:"10",key:"1mglay"}],["path",{d:"M12 2a14.5 14.5 0 0 0 0 20 14.5 14.5 0 0 0 0-20",key:"13o1zl"}],["path",{d:"M2 12h20",key:"9i4pu4"}]]);/**
 * @license lucide-vue-next v0.468.0 - ISC
 *
 * This source code is licensed under the ISC license.
 * See the LICENSE file in the root directory of this source tree.
 */const Je=i("GripVerticalIcon",[["circle",{cx:"9",cy:"12",r:"1",key:"1vctgf"}],["circle",{cx:"9",cy:"5",r:"1",key:"hp0tcf"}],["circle",{cx:"9",cy:"19",r:"1",key:"fkjjf6"}],["circle",{cx:"15",cy:"12",r:"1",key:"1tmaij"}],["circle",{cx:"15",cy:"5",r:"1",key:"19l28e"}],["circle",{cx:"15",cy:"19",r:"1",key:"f4zoj3"}]]);/**
 * @license lucide-vue-next v0.468.0 - ISC
 *
 * This source code is licensed under the ISC license.
 * See the LICENSE file in the root directory of this source tree.
 */const Ye=i("ImageIcon",[["rect",{width:"18",height:"18",x:"3",y:"3",rx:"2",ry:"2",key:"1m3agn"}],["circle",{cx:"9",cy:"9",r:"2",key:"af1f0g"}],["path",{d:"m21 15-3.086-3.086a2 2 0 0 0-2.828 0L6 21",key:"1xmnt7"}]]);/**
 * @license lucide-vue-next v0.468.0 - ISC
 *
 * This source code is licensed under the ISC license.
 * See the LICENSE file in the root directory of this source tree.
 */const Qe=i("LanguagesIcon",[["path",{d:"m5 8 6 6",key:"1wu5hv"}],["path",{d:"m4 14 6-6 2-3",key:"1k1g8d"}],["path",{d:"M2 5h12",key:"or177f"}],["path",{d:"M7 2h1",key:"1t2jsx"}],["path",{d:"m22 22-5-10-5 10",key:"don7ne"}],["path",{d:"M14 18h6",key:"1m8k6r"}]]);/**
 * @license lucide-vue-next v0.468.0 - ISC
 *
 * This source code is licensed under the ISC license.
 * See the LICENSE file in the root directory of this source tree.
 */const et=i("LayoutGridIcon",[["rect",{width:"7",height:"7",x:"3",y:"3",rx:"1",key:"1g98yp"}],["rect",{width:"7",height:"7",x:"14",y:"3",rx:"1",key:"6d4xhi"}],["rect",{width:"7",height:"7",x:"14",y:"14",rx:"1",key:"nxv5o0"}],["rect",{width:"7",height:"7",x:"3",y:"14",rx:"1",key:"1bb6yr"}]]);/**
 * @license lucide-vue-next v0.468.0 - ISC
 *
 * This source code is licensed under the ISC license.
 * See the LICENSE file in the root directory of this source tree.
 */const tt=i("LayoutTemplateIcon",[["rect",{width:"18",height:"7",x:"3",y:"3",rx:"1",key:"f1a2em"}],["rect",{width:"9",height:"7",x:"3",y:"14",rx:"1",key:"jqznyg"}],["rect",{width:"5",height:"7",x:"16",y:"14",rx:"1",key:"q5h2i8"}]]);/**
 * @license lucide-vue-next v0.468.0 - ISC
 *
 * This source code is licensed under the ISC license.
 * See the LICENSE file in the root directory of this source tree.
 */const nt=i("LinkIcon",[["path",{d:"M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71",key:"1cjeqo"}],["path",{d:"M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71",key:"19qd67"}]]);/**
 * @license lucide-vue-next v0.468.0 - ISC
 *
 * This source code is licensed under the ISC license.
 * See the LICENSE file in the root directory of this source tree.
 */const st=i("ListIcon",[["path",{d:"M3 12h.01",key:"nlz23k"}],["path",{d:"M3 18h.01",key:"1tta3j"}],["path",{d:"M3 6h.01",key:"1rqtza"}],["path",{d:"M8 12h13",key:"1za7za"}],["path",{d:"M8 18h13",key:"1lx6n3"}],["path",{d:"M8 6h13",key:"ik3vkj"}]]);/**
 * @license lucide-vue-next v0.468.0 - ISC
 *
 * This source code is licensed under the ISC license.
 * See the LICENSE file in the root directory of this source tree.
 */const at=i("LogOutIcon",[["path",{d:"M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4",key:"1uf3rs"}],["polyline",{points:"16 17 21 12 16 7",key:"1gabdz"}],["line",{x1:"21",x2:"9",y1:"12",y2:"12",key:"1uyos4"}]]);/**
 * @license lucide-vue-next v0.468.0 - ISC
 *
 * This source code is licensed under the ISC license.
 * See the LICENSE file in the root directory of this source tree.
 */const ot=i("MailIcon",[["rect",{width:"20",height:"16",x:"2",y:"4",rx:"2",key:"18n3k1"}],["path",{d:"m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7",key:"1ocrg3"}]]);/**
 * @license lucide-vue-next v0.468.0 - ISC
 *
 * This source code is licensed under the ISC license.
 * See the LICENSE file in the root directory of this source tree.
 */const ct=i("MenuIcon",[["line",{x1:"4",x2:"20",y1:"12",y2:"12",key:"1e0a9i"}],["line",{x1:"4",x2:"20",y1:"6",y2:"6",key:"1owob3"}],["line",{x1:"4",x2:"20",y1:"18",y2:"18",key:"yk5zj1"}]]);/**
 * @license lucide-vue-next v0.468.0 - ISC
 *
 * This source code is licensed under the ISC license.
 * See the LICENSE file in the root directory of this source tree.
 */const rt=i("MonitorIcon",[["rect",{width:"20",height:"14",x:"2",y:"3",rx:"2",key:"48i651"}],["line",{x1:"8",x2:"16",y1:"21",y2:"21",key:"1svkeh"}],["line",{x1:"12",x2:"12",y1:"17",y2:"21",key:"vw1qmm"}]]);/**
 * @license lucide-vue-next v0.468.0 - ISC
 *
 * This source code is licensed under the ISC license.
 * See the LICENSE file in the root directory of this source tree.
 */const it=i("MoonIcon",[["path",{d:"M12 3a6 6 0 0 0 9 9 9 9 0 1 1-9-9Z",key:"a7tn18"}]]);/**
 * @license lucide-vue-next v0.468.0 - ISC
 *
 * This source code is licensed under the ISC license.
 * See the LICENSE file in the root directory of this source tree.
 */const lt=i("NewspaperIcon",[["path",{d:"M4 22h16a2 2 0 0 0 2-2V4a2 2 0 0 0-2-2H8a2 2 0 0 0-2 2v16a2 2 0 0 1-2 2Zm0 0a2 2 0 0 1-2-2v-9c0-1.1.9-2 2-2h2",key:"7pis2x"}],["path",{d:"M18 14h-8",key:"sponae"}],["path",{d:"M15 18h-5",key:"95g1m2"}],["path",{d:"M10 6h8v4h-8V6Z",key:"smlsk5"}]]);/**
 * @license lucide-vue-next v0.468.0 - ISC
 *
 * This source code is licensed under the ISC license.
 * See the LICENSE file in the root directory of this source tree.
 */const ut=i("PencilIcon",[["path",{d:"M21.174 6.812a1 1 0 0 0-3.986-3.987L3.842 16.174a2 2 0 0 0-.5.83l-1.321 4.352a.5.5 0 0 0 .623.622l4.353-1.32a2 2 0 0 0 .83-.497z",key:"1a8usu"}],["path",{d:"m15 5 4 4",key:"1mk7zo"}]]);/**
 * @license lucide-vue-next v0.468.0 - ISC
 *
 * This source code is licensed under the ISC license.
 * See the LICENSE file in the root directory of this source tree.
 */const ht=i("PlusIcon",[["path",{d:"M5 12h14",key:"1ays0h"}],["path",{d:"M12 5v14",key:"s699le"}]]);/**
 * @license lucide-vue-next v0.468.0 - ISC
 *
 * This source code is licensed under the ISC license.
 * See the LICENSE file in the root directory of this source tree.
 */const pt=i("PuzzleIcon",[["path",{d:"M15.39 4.39a1 1 0 0 0 1.68-.474 2.5 2.5 0 1 1 3.014 3.015 1 1 0 0 0-.474 1.68l1.683 1.682a2.414 2.414 0 0 1 0 3.414L19.61 15.39a1 1 0 0 1-1.68-.474 2.5 2.5 0 1 0-3.014 3.015 1 1 0 0 1 .474 1.68l-1.683 1.682a2.414 2.414 0 0 1-3.414 0L8.61 19.61a1 1 0 0 0-1.68.474 2.5 2.5 0 1 1-3.014-3.015 1 1 0 0 0 .474-1.68l-1.683-1.682a2.414 2.414 0 0 1 0-3.414L4.39 8.61a1 1 0 0 1 1.68.474 2.5 2.5 0 1 0 3.014-3.015 1 1 0 0 1-.474-1.68l1.683-1.682a2.414 2.414 0 0 1 3.414 0z",key:"w46dr5"}]]);/**
 * @license lucide-vue-next v0.468.0 - ISC
 *
 * This source code is licensed under the ISC license.
 * See the LICENSE file in the root directory of this source tree.
 */const yt=i("RefreshCwIcon",[["path",{d:"M3 12a9 9 0 0 1 9-9 9.75 9.75 0 0 1 6.74 2.74L21 8",key:"v9h5vc"}],["path",{d:"M21 3v5h-5",key:"1q7to0"}],["path",{d:"M21 12a9 9 0 0 1-9 9 9.75 9.75 0 0 1-6.74-2.74L3 16",key:"3uifl3"}],["path",{d:"M8 16H3v5",key:"1cv678"}]]);/**
 * @license lucide-vue-next v0.468.0 - ISC
 *
 * This source code is licensed under the ISC license.
 * See the LICENSE file in the root directory of this source tree.
 */const dt=i("SearchIcon",[["circle",{cx:"11",cy:"11",r:"8",key:"4ej97u"}],["path",{d:"m21 21-4.3-4.3",key:"1qie3q"}]]);/**
 * @license lucide-vue-next v0.468.0 - ISC
 *
 * This source code is licensed under the ISC license.
 * See the LICENSE file in the root directory of this source tree.
 */const ft=i("SendIcon",[["path",{d:"M14.536 21.686a.5.5 0 0 0 .937-.024l6.5-19a.496.496 0 0 0-.635-.635l-19 6.5a.5.5 0 0 0-.024.937l7.93 3.18a2 2 0 0 1 1.112 1.11z",key:"1ffxy3"}],["path",{d:"m21.854 2.147-10.94 10.939",key:"12cjpa"}]]);/**
 * @license lucide-vue-next v0.468.0 - ISC
 *
 * This source code is licensed under the ISC license.
 * See the LICENSE file in the root directory of this source tree.
 */const kt=i("SettingsIcon",[["path",{d:"M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z",key:"1qme2f"}],["circle",{cx:"12",cy:"12",r:"3",key:"1v7zrd"}]]);/**
 * @license lucide-vue-next v0.468.0 - ISC
 *
 * This source code is licensed under the ISC license.
 * See the LICENSE file in the root directory of this source tree.
 */const mt=i("ShieldIcon",[["path",{d:"M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z",key:"oel41y"}]]);/**
 * @license lucide-vue-next v0.468.0 - ISC
 *
 * This source code is licensed under the ISC license.
 * See the LICENSE file in the root directory of this source tree.
 */const gt=i("ShoppingCartIcon",[["circle",{cx:"8",cy:"21",r:"1",key:"jimo8o"}],["circle",{cx:"19",cy:"21",r:"1",key:"13723u"}],["path",{d:"M2.05 2.05h2l2.66 12.42a2 2 0 0 0 2 1.58h9.78a2 2 0 0 0 1.95-1.57l1.65-7.43H5.12",key:"9zh506"}]]);/**
 * @license lucide-vue-next v0.468.0 - ISC
 *
 * This source code is licensed under the ISC license.
 * See the LICENSE file in the root directory of this source tree.
 */const vt=i("SparklesIcon",[["path",{d:"M9.937 15.5A2 2 0 0 0 8.5 14.063l-6.135-1.582a.5.5 0 0 1 0-.962L8.5 9.936A2 2 0 0 0 9.937 8.5l1.582-6.135a.5.5 0 0 1 .963 0L14.063 8.5A2 2 0 0 0 15.5 9.937l6.135 1.581a.5.5 0 0 1 0 .964L15.5 14.063a2 2 0 0 0-1.437 1.437l-1.582 6.135a.5.5 0 0 1-.963 0z",key:"4pj2yx"}],["path",{d:"M20 3v4",key:"1olli1"}],["path",{d:"M22 5h-4",key:"1gvqau"}],["path",{d:"M4 17v2",key:"vumght"}],["path",{d:"M5 18H3",key:"zchphs"}]]);/**
 * @license lucide-vue-next v0.468.0 - ISC
 *
 * This source code is licensed under the ISC license.
 * See the LICENSE file in the root directory of this source tree.
 */const wt=i("SunMoonIcon",[["path",{d:"M12 8a2.83 2.83 0 0 0 4 4 4 4 0 1 1-4-4",key:"1fu5g2"}],["path",{d:"M12 2v2",key:"tus03m"}],["path",{d:"M12 20v2",key:"1lh1kg"}],["path",{d:"m4.9 4.9 1.4 1.4",key:"b9915j"}],["path",{d:"m17.7 17.7 1.4 1.4",key:"qc3ed3"}],["path",{d:"M2 12h2",key:"1t8f8n"}],["path",{d:"M20 12h2",key:"1q8mjw"}],["path",{d:"m6.3 17.7-1.4 1.4",key:"5gca6"}],["path",{d:"m19.1 4.9-1.4 1.4",key:"wpu9u6"}]]);/**
 * @license lucide-vue-next v0.468.0 - ISC
 *
 * This source code is licensed under the ISC license.
 * See the LICENSE file in the root directory of this source tree.
 */const xt=i("SunIcon",[["circle",{cx:"12",cy:"12",r:"4",key:"4exip2"}],["path",{d:"M12 2v2",key:"tus03m"}],["path",{d:"M12 20v2",key:"1lh1kg"}],["path",{d:"m4.93 4.93 1.41 1.41",key:"149t6j"}],["path",{d:"m17.66 17.66 1.41 1.41",key:"ptbguv"}],["path",{d:"M2 12h2",key:"1t8f8n"}],["path",{d:"M20 12h2",key:"1q8mjw"}],["path",{d:"m6.34 17.66-1.41 1.41",key:"1m8zz5"}],["path",{d:"m19.07 4.93-1.41 1.41",key:"1shlcs"}]]);/**
 * @license lucide-vue-next v0.468.0 - ISC
 *
 * This source code is licensed under the ISC license.
 * See the LICENSE file in the root directory of this source tree.
 */const Mt=i("Trash2Icon",[["path",{d:"M3 6h18",key:"d0wm0j"}],["path",{d:"M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6",key:"4alrt4"}],["path",{d:"M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2",key:"v07s0e"}],["line",{x1:"10",x2:"10",y1:"11",y2:"17",key:"1uufr5"}],["line",{x1:"14",x2:"14",y1:"11",y2:"17",key:"xtxkd"}]]);/**
 * @license lucide-vue-next v0.468.0 - ISC
 *
 * This source code is licensed under the ISC license.
 * See the LICENSE file in the root directory of this source tree.
 */const bt=i("TriangleAlertIcon",[["path",{d:"m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3",key:"wmoenq"}],["path",{d:"M12 9v4",key:"juzpu7"}],["path",{d:"M12 17h.01",key:"p32p05"}]]);/**
 * @license lucide-vue-next v0.468.0 - ISC
 *
 * This source code is licensed under the ISC license.
 * See the LICENSE file in the root directory of this source tree.
 */const St=i("UploadIcon",[["path",{d:"M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4",key:"ih7n3h"}],["polyline",{points:"17 8 12 3 7 8",key:"t8dd8p"}],["line",{x1:"12",x2:"12",y1:"3",y2:"15",key:"widbto"}]]);/**
 * @license lucide-vue-next v0.468.0 - ISC
 *
 * This source code is licensed under the ISC license.
 * See the LICENSE file in the root directory of this source tree.
 */const It=i("UserIcon",[["path",{d:"M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2",key:"975kel"}],["circle",{cx:"12",cy:"7",r:"4",key:"17ys0d"}]]);/**
 * @license lucide-vue-next v0.468.0 - ISC
 *
 * This source code is licensed under the ISC license.
 * See the LICENSE file in the root directory of this source tree.
 */const Ct=i("UsersIcon",[["path",{d:"M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2",key:"1yyitq"}],["circle",{cx:"9",cy:"7",r:"4",key:"nufk8"}],["path",{d:"M22 21v-2a4 4 0 0 0-3-3.87",key:"kshegd"}],["path",{d:"M16 3.13a4 4 0 0 1 0 7.75",key:"1da9ce"}]]);/**
 * @license lucide-vue-next v0.468.0 - ISC
 *
 * This source code is licensed under the ISC license.
 * See the LICENSE file in the root directory of this source tree.
 */const jt=i("XIcon",[["path",{d:"M18 6 6 18",key:"1bl5f8"}],["path",{d:"m6 6 12 12",key:"d8bk6v"}]]),Wt={__name:"KIcon",props:{name:{type:String,required:!0}},setup(e){const n={grid:et,media:Ye,list:st,menu:ct,page:Y,block:tt,posts:lt,globe:Be,shield:mt,notfound:$e,wip:We,code:Ge,link:nt,settings:kt,logout:at,publish:ft,clock:Fe,external:Ze,search:dt,plus:ht,back:De,arrow:Ne,check:Te,close:jt,trash:Mt,up:Ue,down:He,drag:Je,upload:St,copy:Ke,theme:wt,sun:xt,moon:it,monitor:rt,warning:bt,agent:vt,language:Qe,version:yt,user:It,users:Ct,puzzle:pt,calendar:Re,cart:gt,chart:Ve,mail:ot,edit:ut,database:Xe},t=e,s=v(()=>n[t.name]||Y);return(o,a)=>(ve(),we(xe(s.value),{"stroke-width":1.75,"aria-hidden":"true"}))}};/*!
 * pinia v3.0.4
 * (c) 2025 Eduardo San Martin Morote
 * @license MIT
 */let ie;const V=e=>ie=e,le=Symbol();function U(e){return e&&typeof e=="object"&&Object.prototype.toString.call(e)==="[object Object]"&&typeof e.toJSON!="function"}var q;(function(e){e.direct="direct",e.patchObject="patch object",e.patchFunction="patch function"})(q||(q={}));function Kt(){const e=ce(!0),n=e.run(()=>M({}));let t=[],s=[];const o=oe({install(a){V(o),o._a=a,a.provide(le,o),a.config.globalProperties.$pinia=o,s.forEach(c=>t.push(c)),s=[]},use(a){return this._a?t.push(a):s.push(a),this},_p:t,_a:null,_e:e,_s:new Map,state:n});return o}const ue=()=>{};function Q(e,n,t,s=ue){e.add(n);const o=()=>{e.delete(n)&&s()};return!t&&Ie()&&Ce(o),o}function j(e,...n){e.forEach(t=>{t(...n)})}const Lt=e=>e(),ee=Symbol(),H=Symbol();function $(e,n){e instanceof Map&&n instanceof Map?n.forEach((t,s)=>e.set(s,t)):e instanceof Set&&n instanceof Set&&n.forEach(e.add,e);for(const t in n){if(!n.hasOwnProperty(t))continue;const s=n[t],o=e[t];U(o)&&U(s)&&e.hasOwnProperty(t)&&!N(s)&&!re(s)?e[t]=$(o,s):e[t]=s}return e}const _t=Symbol();function zt(e){return!U(e)||!Object.prototype.hasOwnProperty.call(e,_t)}const{assign:S}=Object;function At(e){return!!(N(e)&&e.effect)}function Ot(e,n,t,s){const{state:o,actions:a,getters:c}=n,l=t.state.value[e];let p;function u(){l||(t.state.value[e]=o?o():{});const k=_e(t.state.value[e]);return S(k,a,Object.keys(c||{}).reduce((f,r)=>(f[r]=oe(v(()=>{V(t);const d=t._s.get(e);return c[r].call(d,d)})),f),{}))}return p=he(e,u,n,t,s,!0),p}function he(e,n,t={},s,o,a){let c;const l=S({actions:{}},t),p={deep:!0};let u,k,f=new Set,r=new Set,d;const b=s.state.value[e];!a&&!b&&(s.state.value[e]={});let K;function X(y){let h;u=k=!1,typeof y=="function"?(y(s.state.value[e]),h={type:q.patchFunction,storeId:e,events:d}):($(s.state.value[e],y),h={type:q.patchObject,payload:y,storeId:e,events:d});const m=K=Symbol();Le().then(()=>{K===m&&(u=!0)}),k=!0,j(f,h,s.state.value[e])}const de=a?function(){const{state:h}=t,m=h?h():{};this.$patch(C=>{S(C,m)})}:ue;function fe(){c.stop(),f.clear(),r.clear(),s._s.delete(e)}const Z=(y,h="")=>{if(ee in y)return y[H]=h,y;const m=function(){V(s);const C=Array.from(arguments),z=new Set,T=new Set;function me(g){z.add(g)}function ge(g){T.add(g)}j(r,{args:C,name:m[H],store:w,after:me,onError:ge});let A;try{A=y.apply(this&&this.$id===e?this:w,C)}catch(g){throw j(T,g),g}return A instanceof Promise?A.then(g=>(j(z,g),g)).catch(g=>(j(T,g),Promise.reject(g))):(j(z,A),A)};return m[ee]=!0,m[H]=h,m},ke={_p:s,$id:e,$onAction:Q.bind(null,r),$patch:X,$reset:de,$subscribe(y,h={}){const m=Q(f,y,h.detached,()=>C()),C=c.run(()=>ze(()=>s.state.value[e],z=>{(h.flush==="sync"?k:u)&&y({storeId:e,type:q.direct,events:d},z)},S({},p,h)));return m},$dispose:fe},w=je(ke);s._s.set(e,w);const _=(s._a&&s._a.runWithContext||Lt)(()=>s._e.run(()=>(c=ce()).run(()=>n({action:Z}))));for(const y in _){const h=_[y];if(N(h)&&!At(h)||re(h))a||(b&&zt(h)&&(N(h)?h.value=b[y]:$(h,b[y])),s.state.value[e][y]=h);else if(typeof h=="function"){const m=Z(h,y);_[y]=m,l.actions[y]=h}}return S(w,_),S(Se(w),_),Object.defineProperty(w,"$state",{get:()=>s.state.value[e],set:y=>{X(h=>{S(h,y)})}}),s._p.forEach(y=>{S(w,c.run(()=>y({store:w,app:s._a,pinia:s,options:l})))}),b&&a&&t.hydrate&&t.hydrate(w.$state,b),u=!0,k=!0,w}/*! #__NO_SIDE_EFFECTS__ */function W(e,n,t){let s;const o=typeof n=="function";s=o?t:n;function a(c,l){const p=be();return c=c||(p?Me(le,null):null),c&&V(c),c=ie,c._s.has(e)||(o?he(e,n,s,c):Ot(e,s,c)),c._s.get(e)}return a.$id=e,a}const qt=W("ui",()=>{const e=M(""),n=M(null);let t;function s(a){clearTimeout(t),e.value=a,t=setTimeout(()=>e.value="",6e3)}function o({title:a,text:c,confirmLabel:l="Conferma",danger:p=!0,options:u}){return new Promise(k=>{n.value={title:a,text:c,options:u||[{label:"Annulla",value:!1},{label:l,value:!0,kind:p?"btn-danger":"btn-primary"}],resolve:f=>{n.value=null,k(f)}}})}return{toastText:e,dialog:n,toast:s,confirm:o}}),Xt={text:"Testo",richtext:"Testo formattato",media:"Immagine o file",boolean:"Interruttore",select:"Scelta",list:"Elenco",embed:"Contenuto incorporato"},Et=e=>typeof e=="string"&&(e.startsWith("kris_")||e.startsWith("plg_")),Pt={kris_site:{label:"Proprietà del sito",icon:"globe"},kris_legal:{label:"Privacy e cookie",icon:"shield"},kris_notfound:{label:"Pagina 404",icon:"notfound"},kris_wip:{label:"Lavori in corso",icon:"wip"}},pe=e=>{const n=String(e||"").replace(/_/g," ");return n.charAt(0).toUpperCase()+n.slice(1)};function Nt(e){return Object.entries((e==null?void 0:e.types)||{}).filter(([n,t])=>t&&!Et(n)&&!t.reserved).map(([n,t])=>({name:n,...t}))}const L=(e,n)=>{var t;return((t=e==null?void 0:e.types)==null?void 0:t[n])||null},Rt=(e,n)=>{var t,s;return(((t=L(e,n))==null?void 0:t.label)||"").trim()||((s=Pt[n])==null?void 0:s.label)||pe(n)},Zt=(e,n)=>{var t;return(((t=L(e,n))==null?void 0:t.description)||"").trim()},Bt=(e,n)=>{var t;return((t=L(e,n))==null?void 0:t.single)===!0},Jt=(e,n)=>{var t;return((t=L(e,n))==null?void 0:t.newest_first)===!0},Vt=(e,n)=>(e||[]).filter(t=>t.type===n),Tt=(e,n,t)=>(e||[]).find(s=>s.type===n&&Number(s.id)===Number(t))||null;function Yt(e,n,t,s=[]){var a;let o=Tt(e,n,t);for(let c=0;o&&c<s.length;c+=2){if(s[c+1]===void 0)return null;const l=(a=o.values)==null?void 0:a[s[c]];o=Array.isArray(l)&&l.find(p=>Number(p==null?void 0:p.id)===Number(s[c+1]))||null}return o}const Qt=(e=[])=>e.filter((n,t)=>t%2===0).map(String);function Ht(e,n,t=[]){var o;let s=((o=L(e,n))==null?void 0:o.fields)||[];for(const a of t){const c=s.find(l=>(l==null?void 0:l.name)===a);if((c==null?void 0:c.type)!=="list")return[];s=c.of||[]}return s.filter(a=>a&&typeof a.name=="string")}const en=e=>((e==null?void 0:e.description)||"").trim()||pe(e==null?void 0:e.name);function ye(e,n){if(Array.isArray(e)||(e&&typeof e=="object"&&(e=(typeof e[n]=="string"&&e[n].trim()!==""?e[n]:Object.values(e).find(o=>typeof o=="string"&&o.trim()!==""))??""),typeof e!="string"))return"";const t=document.createElement("div");return t.innerHTML=e,(t.textContent||"").trim()}function tn(e,n=[],t){if(!e)return"Contenuto non disponibile";const s=e.values||{},o=Object.fromEntries(n.map(a=>[a.name,a.type]));for(const a of["title","page_title","titolo","name","nome","meta_title",null])for(const[c,l]of Object.entries(s)){if(a!==null&&c!==a||o[c]&&!["text","richtext"].includes(o[c]))continue;const p=ye(l,t);if(p)return p.slice(0,85)}return`Contenuto #${e.id??0}`}function nn(e,n,t,s){var o;for(const a of n){if(!["text","select"].includes(a.type))continue;const c=(o=e.values)==null?void 0:o[a.name],l=Array.isArray(c)?c.join(", "):ye(c,t);if(l&&l.slice(0,85)!==s)return l.length>90?l.slice(0,89)+"…":l}return""}function sn(e,n){var t;for(const s of n){if(s.type!=="media")continue;const o=(t=e.values)==null?void 0:t[s.name],a=o&&typeof o=="object"?Object.values(o).find(c=>typeof c=="string"&&c):o;if(typeof a=="string"&&a&&/\.(jpe?g|png|gif|webp|avif|svg)$/i.test(a))return a}return""}function an(e){const n=new Map([["",[]]]);return e.forEach((t,s)=>{const o=typeof t.section=="string"?t.section:"";n.has(o)||n.set(o,[]),n.get(o).push({...t,index:s})}),[...n].filter(([,t])=>t.length).map(([t,s])=>({section:t,fields:s}))}const te=e=>e==null||e===""||e===!1||Array.isArray(e)&&e.length===0,ne=(e,n)=>JSON.stringify(e)===JSON.stringify(n)||te(e)&&te(n);function on(e,n,t,s,o,a,c){const l={op:"set_field",key:e,id:Number(n),...t.length?{path:t}:{}},p=[];for(const u of s){if(u.type==="list"||!(u.name in a))continue;if(!u.localized){ne(o[u.name],a[u.name])||p.push({...l,field:u.name,value:a[u.name]});continue}const k=o[u.name]&&typeof o[u.name]=="object"?o[u.name]:{},f=a[u.name]&&typeof a[u.name]=="object"?a[u.name]:{};for(const r of c)r in f&&!ne(k[r],f[r])&&p.push({...l,field:u.name,lang:r,value:f[r]})}return p}function cn(e,n){const t=e.map(Number),s=[];for(const a of n.map(Number))t.includes(a)&&!s.includes(a)&&s.push(a);const o=[];return s.forEach((a,c)=>{t[c]!==a&&(t.splice(t.indexOf(a),1),t.splice(c,0,a),o.push([a,c]))}),o}function rn(e,n=new Set){if(!e)return"";const t=/^assets\/uploads\/([A-Za-z0-9._-]+)$/.exec(e);return t&&n.has(t[1])?`../index.php?kris_preview_asset=uploads/${encodeURIComponent(t[1])}&kris_preview=1`:/^https?:\/\//i.test(e)?e:/^(?:[a-z][a-z0-9+.-]*:|\/\/)/i.test(e)?"":e.startsWith("/")||e.startsWith("../")?e:"../"+e}const ln=e=>/\.pdf$/i.test(e)?"pdf":/\.(mp4|webm)$/i.test(e)?"video":"image",O=M(!1),P=M(!1);window.addEventListener("beforeunload",e=>{(O.value||P.value)&&(e.preventDefault(),e.returnValue="")});function un(){const e=qt();async function n(){if(P.value)return e.toast("Attendi la fine del salvataggio."),!1;if(!O.value)return!0;const t=await e.confirm({title:"Uscire senza salvare?",text:"Hai modifiche non salvate in questa pagina: uscendo andranno perse.",confirmLabel:"Esci senza salvare"});return t&&(O.value=!1),t}return{dirty:O,busy:P,setDirty:t=>O.value=t,setBusy:t=>P.value=t,allowLeave:n}}const se={get:()=>x.get("session"),setMode:e=>x.post("mode",{mode:e})},Dt=W("session",()=>{const e=M(null),n=v(()=>{var r;return((r=e.value)==null?void 0:r.user)??null}),t=v(()=>{var r;return((r=n.value)==null?void 0:r.role)===Oe.ADMIN}),s=v(()=>{var r;return((r=e.value)==null?void 0:r.mode)??B.LIVE}),o=v(()=>s.value===B.STAGING),a=v(()=>{var r;return((r=e.value)==null?void 0:r.languages)??["it"]}),c=v(()=>{var r;return!!((r=e.value)!=null&&r.lock)}),l=v(()=>{var r,d;return((d=(r=e.value)==null?void 0:r.staging)==null?void 0:d.count)??0});async function p(){e.value=await se.get()}async function u(r){await se.setMode(r),await p()}function k(r){e.value&&r&&(e.value.staging=r)}return{data:e,user:n,isAdmin:t,mode:s,isStaging:o,languages:a,locked:c,stagingCount:l,load:p,setMode:u,setStaging:k,languageName:r=>{var d,b;return((b=(d=e.value)==null?void 0:d.language_names)==null?void 0:b[r])??r.toUpperCase()}}}),ae={get:()=>x.get("site"),apply:e=>x.post("ops",{ops:e}),saveStructure:(e,n,t=!1)=>x.post("structure",{type:e,fields:n,confirm:t}),saveLanguages:(e,n)=>x.post("languages",{languages:e,main:n}),saveUploadSettings:e=>x.post("upload-settings",e),saveSeo:e=>x.post("seo",{seo:e}),saveRedirects:e=>x.post("redirects",{redirects:e}),store:()=>x.get("store")},hn=W("site",()=>{const e=M({types:{},pages:{}}),n=M([]),t=M({}),s=M(!1),o=v(()=>Nt(e.value)),a=v(()=>n.value.reduce((r,d)=>({...r,[d.type]:(r[d.type]||0)+1}),{}));async function c(){const r=await ae.get();e.value=r.schema,n.value=r.content,t.value=r.settings,s.value=!0}async function l(r){const d=await ae.apply(r);return Dt().setStaging(d.staging),await c(),d}return{schema:e,content:n,settings:t,loaded:s,userTypes:o,counts:a,load:c,apply:l,type:r=>L(e.value,r),label:r=>Rt(e.value,r),items:r=>Vt(n.value,r),fieldsOf:(r,d=[])=>Ht(e.value,r,d)}});export{I as A,Xt as F,B as M,Pt as R,ae as S,Wt as _,x as a,un as b,Dt as c,hn as d,qt as e,Gt as f,Kt as g,Bt as h,Jt as i,cn as j,tn as k,sn as l,rn as m,en as n,qe as o,Et as p,Qt as q,Yt as r,nn as s,Zt as t,Ft as u,Tt as v,on as w,an as x,ln as y};
