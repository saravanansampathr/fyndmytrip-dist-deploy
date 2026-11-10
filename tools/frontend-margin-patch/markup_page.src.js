async function uae(){let{data:e}=await K.get(`/markup-rules`);return e.data}
async function dae(e){let{data:t}=await K.post(`/markup-rules`,e);return t.data}
async function fae(e,t){let{data:n}=await K.put(`/markup-rules/${e}`,t);return n.data}
async function pae(e){await K.delete(`/markup-rules/${e}`)}
async function fmtMkOptions(q){let{data:e}=await K.get(`/markup-rules/options`,{params:q?{q}:{}});return e.data}
async function fmtMkPreview(b){let{data:e}=await K.post(`/markup-rules/preview`,b);return e.data}
var hae=Object.keys(hf),_ae=Object.keys(_f);
var fmtMkModuleLabels={all:`All services`,...ef};
var fmtMkAudienceLabels={all:`Everyone`,b2c:`Direct customers (B2C)`,agent:`Agents`,sub_agent:`Sub-agents`,white_label:`White-label partners`,api_partner:`API partners`,staff:`Staff users`,admin:`Admins`};
var fmtMkSelectCls=`w-full rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-brand-teal focus:outline-none focus:ring-1 focus:ring-brand-teal`;
function fmtMkH(t,p,...c){return c.length>1?(0,U.jsxs)(t,{...p,children:c}):(0,U.jsx)(t,{...p,children:c[0]})}
function o9(){return{tenant_id:``,module:`all`,rule_type:`percentage`,applies_to_role:`all`,user_id:``,target_tenant_id:``,percentage:`5`,amount:``,origin:``,destination:``,name:``,booking_scope:`both`,supplier_code:``,is_active:!0}}
function vae(e){if(e.rule_type===`percentage`)return`${e.config.percentage??`0`}%`;let t=`₹${e.config.amount??`0`}`;return e.rule_type===`flat_per_pax`?`${t} / passenger`:e.rule_type===`flat_per_destination`?`${t} on ${[e.origin,e.destination].filter(Boolean).join(` → `)||`destination`}`:`${t} / booking`}
function yae(e){let t=[_f[e.booking_scope]];return e.supplier_code&&t.push(e.supplier_code),t.join(` · `)}
function fmtMkTarget(e){let t=[];e.target_tenant&&t.push(`Account: ${e.target_tenant.name}`);e.user&&t.push(`User: ${e.user.name} (${e.user.email})`);return t.length?t.join(` · `):`Everyone in audience`}
function fmtMkSelect(id,label,value,onChange,opts,extra){return fmtMkH(`div`,{className:`space-y-1`},fmtMkH(`label`,{htmlFor:id,className:`block text-sm font-medium text-slate-700`},label),fmtMkH(`select`,{id,value:value??``,onChange:e=>onChange(e.target.value),className:fmtMkSelectCls,...(extra||{})},...opts.map(o=>(0,U.jsx)(`option`,{value:o.value,children:o.label},String(o.value)))))}
function bae(){
let[e,t]=(0,x.useState)(o9()),[n,r]=(0,x.useState)(null),[i,a]=(0,x.useState)(null),[sq,setSq]=(0,x.useState)(``),[flt,setFlt]=(0,x.useState)({module:``,role:``,user:``}),
[pv,setPv]=(0,x.useState)({module:`flight`,user_id:``,tenant_id:``,net_amount:`10000`,passenger_count:`1`,is_international:!1}),[pr,setPr]=(0,x.useState)(null),[pe,setPe]=(0,x.useState)(null),
o=vr(),s=zi({queryKey:[`markup-rules`],queryFn:uae}),op=zi({queryKey:[`markup-rule-options`,sq],queryFn:()=>fmtMkOptions(sq)}),O=op.data,canManage=O?O.can_manage:!1;
function c(){t(o9()),r(null),a(null)}
let l=W({mutationFn:e=>n?fae(n,e):dae(e),onSuccess:()=>{a(null),c(),o.invalidateQueries({queryKey:[`markup-rules`]})},onError:e=>a(q(e))}),
u=W({mutationFn:e=>pae(e),onSuccess:()=>o.invalidateQueries({queryKey:[`markup-rules`]})}),
pm=W({mutationFn:b=>fmtMkPreview(b),onSuccess:d=>{setPr(d),setPe(null)},onError:er=>{setPr(null),setPe(q(er))}});
function d(e){r(e.id),a(null),t({tenant_id:e.tenant_id??``,module:e.module,rule_type:e.rule_type,applies_to_role:e.applies_to_role,user_id:e.user_id??``,target_tenant_id:e.target_tenant_id??``,percentage:e.config.percentage??``,amount:e.config.amount??``,origin:e.origin??``,destination:e.destination??``,name:e.name??``,booking_scope:e.booking_scope,supplier_code:e.supplier_code??``,is_active:e.is_active}),window.scrollTo&&window.scrollTo({top:0,behavior:`smooth`})}
function f(ev){if(ev.preventDefault(),e.rule_type===`percentage`&&(e.percentage===``||Number(e.percentage)<0)){a(`Enter a percentage (0 or more).`);return}if(e.rule_type!==`percentage`&&(e.amount===``||Number(e.amount)<0)){a(`Enter a flat amount (0 or more).`);return}if(e.rule_type===`flat_per_destination`&&!e.destination?.trim()){a(`A destination-based rule needs at least a destination.`);return}
l.mutate({...e,tenant_id:e.tenant_id?Number(e.tenant_id):null,user_id:e.user_id?Number(e.user_id):null,target_tenant_id:e.target_tenant_id?Number(e.target_tenant_id):null,percentage:e.rule_type===`percentage`?e.percentage:null,amount:e.rule_type===`percentage`?null:e.amount,origin:e.origin?.trim()?e.origin.trim().toUpperCase():null,destination:e.destination?.trim()?e.destination.trim().toUpperCase():null,name:e.name?.trim()||null,supplier_code:e.supplier_code?.trim()?e.supplier_code.trim().toUpperCase():null})}
function runPreview(ev){ev.preventDefault(),pm.mutate({module:pv.module,net_amount:pv.net_amount,passenger_count:Number(pv.passenger_count||1),is_international:pv.is_international,user_id:pv.user_id?Number(pv.user_id):null,tenant_id:!pv.user_id&&pv.tenant_id?Number(pv.tenant_id):null})}
let modOpts=(O?.modules??Object.entries(fmtMkModuleLabels).map(([value,label])=>({value,label}))),audOpts=(O?.audiences??Object.entries(fmtMkAudienceLabels).map(([value,label])=>({value,label}))),
acctOpts=[{value:``,label:`Any account`},...(O?.target_tenants??[]).map(t=>({value:t.id,label:`${t.name} (${t.type.replace(/_/g,` `)})`}))],
usersFor=a2=>(O?.users??[]).filter(u=>!a2||String(u.tenant_id)===String(a2)),
userOpts=a2=>[{value:``,label:`Any user`},...usersFor(a2).map(u=>({value:u.id,label:`${u.name} · ${u.email} · ${u.role.replace(/_/g,` `)}${u.tenant_name?` · ${u.tenant_name}`:``}`}))],
rows=(s.data??[]).filter(rw=>(!flt.module||rw.module===flt.module)&&(!flt.role||rw.applies_to_role===flt.role)&&(!flt.user||String(rw.user_id)===flt.user));
return fmtMkH(`div`,{className:`space-y-6`},
fmtMkH(`div`,{},fmtMkH(`h1`,{className:`text-2xl font-bold text-brand-navy`},`Margin (markup) rules`),fmtMkH(`p`,{className:`text-sm text-slate-500`},`Set the margin added on top of net supplier cost for every kind of user — admins, staff, white-label partners, API partners, agents, sub-agents and direct customers — on all services or one service, and optionally for one chosen account or one chosen user. As a percentage, a flat amount per booking (PNR), per passenger, or for a specific destination. The most specific rule wins (user → account → service → audience); a rule from a level above you is a ceiling you can never exceed.`)),
!canManage&&O&&fmtMkH(`div`,{className:`rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800`,"data-testid":`markup-readonly`},`You can view these margins but not change them. Ask your agent or account admin to update them.`),
canManage&&fmtMkH(X,{},fmtMkH(`h2`,{className:`mb-3 text-sm font-semibold text-slate-700`},n?`Edit rule #${n}`:`New rule`),
fmtMkH(`form`,{onSubmit:f,className:`space-y-4`,"data-testid":`markup-form`},
fmtMkH(`div`,{className:`grid grid-cols-1 gap-3 sm:grid-cols-3`},
(0,U.jsx)(Y,{id:`markup-name`,label:`Name (optional)`,placeholder:`e.g. VIP agent margin`,value:e.name??``,onChange:ev=>t(tt=>({...tt,name:ev.target.value}))}),
fmtMkSelect(`markup-module`,`Service`,e.module,v=>t(tt=>({...tt,module:v})),modOpts),
fmtMkSelect(`markup-audience`,`Applies to (user type)`,e.applies_to_role,v=>t(tt=>({...tt,applies_to_role:v})),audOpts)),
fmtMkH(`div`,{className:`grid grid-cols-1 gap-3 sm:grid-cols-3`},
O?.can_choose_owner?fmtMkSelect(`markup-owner`,`Rule owner (whose margin)`,e.tenant_id,v=>t(tt=>({...tt,tenant_id:v})),[{value:``,label:`My own tenant`},...(O?.owner_tenants??[]).map(tn=>({value:tn.id,label:`${tn.name} (${tn.type.replace(/_/g,` `)})`}))]):fmtMkH(`div`,{className:`space-y-1 text-sm text-slate-500`},fmtMkH(`span`,{className:`block font-medium text-slate-700`},`Rule owner`),`Your own account`),
fmtMkSelect(`markup-account`,`Only for this account (optional)`,e.target_tenant_id,v=>t(tt=>({...tt,target_tenant_id:v,user_id:``})),acctOpts),
fmtMkSelect(`markup-user`,`Only for this user (optional)`,e.user_id,v=>t(tt=>({...tt,user_id:v})),userOpts(e.target_tenant_id))),
fmtMkH(`div`,{className:`grid grid-cols-1 gap-3 sm:grid-cols-3`},(0,U.jsx)(Y,{id:`markup-user-search`,label:`Find accounts / users`,placeholder:`Type a name or email…`,value:sq,onChange:ev=>setSq(ev.target.value)})),
fmtMkH(`div`,{},fmtMkH(`label`,{className:`mb-1 block text-sm font-medium text-slate-700`},`Type (pick one)`),fmtMkH(`div`,{className:`flex flex-wrap gap-2`},...hae.map(nn=>(0,U.jsx)(`button`,{type:`button`,onClick:()=>t(tt=>({...tt,rule_type:nn})),className:`rounded-full px-3 py-1 text-xs font-medium ${e.rule_type===nn?`bg-brand-teal text-white`:`bg-slate-100 text-slate-600 hover:bg-slate-200`}`,children:hf[nn]},nn)))),
fmtMkH(`div`,{className:`grid grid-cols-1 gap-3 sm:grid-cols-4`},
e.rule_type===`percentage`?(0,U.jsx)(Y,{id:`markup-percentage`,label:`Percentage (%)`,type:`number`,min:`0`,max:`100`,step:`0.01`,value:e.percentage??``,onChange:ev=>t(tt=>({...tt,percentage:ev.target.value}))}):(0,U.jsx)(Y,{id:`markup-amount`,label:`Flat amount (₹)${e.rule_type===`flat_per_pax`?` per passenger`:``}`,type:`number`,min:`0`,step:`0.01`,value:e.amount??``,onChange:ev=>t(tt=>({...tt,amount:ev.target.value}))}),
e.rule_type===`flat_per_destination`&&(0,U.jsx)(Y,{id:`markup-origin`,label:`Origin (optional, e.g. DEL)`,maxLength:10,value:e.origin??``,onChange:ev=>t(tt=>({...tt,origin:ev.target.value.toUpperCase()}))}),
e.rule_type===`flat_per_destination`&&(0,U.jsx)(Y,{id:`markup-destination`,label:`Destination (e.g. DXB)`,maxLength:10,value:e.destination??``,onChange:ev=>t(tt=>({...tt,destination:ev.target.value.toUpperCase()}))}),
fmtMkH(`label`,{className:`flex items-center gap-2 self-end pb-2 text-sm text-slate-600`},(0,U.jsx)(`input`,{type:`checkbox`,checked:e.is_active??!0,onChange:ev=>t(tt=>({...tt,is_active:ev.target.checked})),className:`accent-brand-teal`}),`Active`)),
fmtMkH(`div`,{className:`grid grid-cols-1 gap-3 sm:grid-cols-3`},
fmtMkSelect(`markup-scope`,`Domestic / international`,e.booking_scope??`both`,v=>t(tt=>({...tt,booking_scope:v})),_ae.map(k=>({value:k,label:_f[k]}))),
(0,U.jsx)(Y,{id:`markup-supplier-code`,label:`Specific airline/hotel/operator (optional)`,placeholder:`e.g. 6E — blank applies to all`,value:e.supplier_code??``,onChange:ev=>t(tt=>({...tt,supplier_code:ev.target.value.toUpperCase()}))})),
fmtMkH(`div`,{className:`flex items-center justify-end gap-2`},n&&(0,U.jsx)(J,{type:`button`,variant:`secondary`,onClick:c,children:`Cancel edit`}),(0,U.jsx)(J,{type:`submit`,disabled:l.isPending,children:l.isPending?`Saving…`:n?`Save changes`:`Create rule`})),
(0,U.jsx)(Jp,{message:i}))),
fmtMkH(X,{},fmtMkH(`h2`,{className:`mb-3 text-lg font-semibold text-brand-navy`},`Check a margin`),
fmtMkH(`form`,{onSubmit:runPreview,className:`space-y-3`,"data-testid":`markup-preview-form`},
fmtMkH(`div`,{className:`grid grid-cols-1 gap-3 sm:grid-cols-4`},
fmtMkSelect(`preview-module`,`Service`,pv.module,v=>setPv(p=>({...p,module:v})),modOpts.filter(m=>m.value!==`all`)),
fmtMkSelect(`preview-account`,`Account`,pv.tenant_id,v=>setPv(p=>({...p,tenant_id:v,user_id:``})),[{value:``,label:`My own account`},...acctOpts.slice(1)]),
fmtMkSelect(`preview-user`,`User`,pv.user_id,v=>setPv(p=>({...p,user_id:v})),[{value:``,label:`Any user (guest / customer)`},...userOpts(pv.tenant_id).slice(1)]),
(0,U.jsx)(Y,{id:`preview-net`,label:`Net supplier cost (₹)`,type:`number`,min:`0`,step:`0.01`,value:pv.net_amount,onChange:ev=>setPv(p=>({...p,net_amount:ev.target.value}))})),
fmtMkH(`div`,{className:`flex items-center justify-end gap-2`},(0,U.jsx)(J,{type:`submit`,disabled:pm.isPending,children:pm.isPending?`Checking…`:`Check margin`})),
(0,U.jsx)(Jp,{message:pe}),
pr&&fmtMkH(`div`,{className:`rounded-md bg-slate-50 px-4 py-3 text-sm text-slate-700`,"data-testid":`markup-preview-result`},
fmtMkH(`div`,{},`Margin: `,fmtMkH(`strong`,{},`₹${pr.markup_amount}`),` · Sell price: `,fmtMkH(`strong`,{},`₹${pr.gross_amount}`),` · Priced as: ${fmtMkAudienceLabels[pr.audience]??pr.audience}`),
fmtMkH(`div`,{className:`text-xs text-slate-500`},pr.rule?`Rule #${pr.rule.id}${pr.rule.name?` “${pr.rule.name}”`:``} owned by ${pr.rule.owner?.name??`—`} · ${vae(pr.rule)}${pr.capped_by_parent?` · capped by a parent's ceiling at ${pr.applied_value}`:``}`:`No rule matches — sold at net cost.`)))),
fmtMkH(X,{},fmtMkH(`div`,{className:`mb-3 flex flex-wrap items-end justify-between gap-3`},fmtMkH(`h2`,{className:`text-lg font-semibold text-brand-navy`},`Rules`),
fmtMkH(`div`,{className:`flex flex-wrap gap-2`},
fmtMkSelect(`filter-module`,`Service`,flt.module,v=>setFlt(p=>({...p,module:v})),[{value:``,label:`All`},...modOpts]),
fmtMkSelect(`filter-audience`,`User type`,flt.role,v=>setFlt(p=>({...p,role:v})),[{value:``,label:`All`},...audOpts]),
fmtMkSelect(`filter-user`,`User`,flt.user,v=>setFlt(p=>({...p,user:v})),[{value:``,label:`All`},...usersFor(``).map(u=>({value:String(u.id),label:u.name}))]))),
fmtMkH(`div`,{className:`overflow-x-auto`},fmtMkH(`table`,{className:`w-full text-sm`,"data-testid":`markup-table`},
fmtMkH(`thead`,{},fmtMkH(`tr`,{className:`border-b border-slate-200 text-left text-slate-500`},fmtMkH(`th`,{className:`py-2`},`Name`),fmtMkH(`th`,{},`Owner`),fmtMkH(`th`,{},`Service`),fmtMkH(`th`,{},`User type`),fmtMkH(`th`,{},`For`),fmtMkH(`th`,{},`Scope`),fmtMkH(`th`,{},`Type`),fmtMkH(`th`,{},`Value`),fmtMkH(`th`,{},`Status`),fmtMkH(`th`,{}))),
(0,U.jsx)(`tbody`,{children:rows.map(rw=>(0,U.jsxs)(`tr`,{className:`border-b border-slate-100`,children:[
(0,U.jsx)(`td`,{className:`py-2`,children:rw.name??(0,U.jsx)(`span`,{className:`text-slate-400`,children:`—`})}),
(0,U.jsx)(`td`,{className:`text-xs`,children:rw.owner?.name??`—`}),
(0,U.jsx)(`td`,{children:fmtMkModuleLabels[rw.module]??rw.module}),
(0,U.jsx)(`td`,{children:fmtMkAudienceLabels[rw.applies_to_role]??rw.applies_to_role}),
(0,U.jsx)(`td`,{className:`text-xs text-slate-500`,children:fmtMkTarget(rw)}),
(0,U.jsx)(`td`,{className:`text-xs text-slate-500`,children:yae(rw)}),
(0,U.jsx)(`td`,{className:`text-xs`,children:hf[rw.rule_type]}),
(0,U.jsx)(`td`,{className:`font-medium text-brand-navy`,children:vae(rw)}),
(0,U.jsx)(`td`,{children:(0,U.jsx)(Z,{tone:rw.is_active?`green`:`neutral`,children:rw.is_active?`Active`:`Inactive`})}),
(0,U.jsx)(`td`,{className:`space-x-2 text-right whitespace-nowrap`,children:canManage?(0,U.jsxs)(U.Fragment,{children:[(0,U.jsx)(`button`,{type:`button`,className:`text-brand-teal-dark hover:underline`,onClick:()=>d(rw),children:`Edit`}),(0,U.jsx)(`button`,{type:`button`,className:`text-red-600 hover:underline`,onClick:()=>{window.confirm(`Delete this margin rule?`)&&u.mutate(rw.id)},children:`Delete`})]}):null})]},rw.id))}))),
s.data&&rows.length===0&&fmtMkH(`p`,{className:`py-4 text-center text-sm text-slate-500`},`No margin rules match — without one, offers are sold at net supplier cost with no markup added.`)))}
