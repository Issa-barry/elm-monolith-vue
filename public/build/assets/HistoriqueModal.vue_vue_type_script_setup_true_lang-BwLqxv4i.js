import{s as J}from"./index-7kAwplYa.js";import{a1 as A,J as f,aa as W,ar as Y,as as D,ak as N,a8 as H,x as I,D as y,i as $,o as i,w as m,C as K,n as z,bg as F,bf as X,ai as Z,ah as V,ad as tt,ao as j,b as l,j as g,f as o,F as P,Y as et,d as at,c as nt,e as x,k as B,g as v,t as c,v as O}from"./app-BJVmqiyI.js";import{R as M,s as C,f as U}from"./index-N6VhB34w.js";import{s as st}from"./index-DouL17QA.js";import{s as rt}from"./index-DHFZkWPv.js";import{L as it}from"./loader-circle-B1k7M4Hv.js";import{A as ot,a as lt}from"./arrow-up-6teRSnyk.js";function dt(e){return e?(new DOMParser().parseFromString(e,"text/html").body.textContent??"").trim().replace(/\s+/g," "):""}var ut={root:function(t){var n=t.instance,s=t.props;return["p-tab",{"p-tab-active":n.active,"p-disabled":s.disabled}]}},ct=A.extend({name:"tab",classes:ut}),bt={name:"BaseTab",extends:C,props:{value:{type:[String,Number],default:void 0},disabled:{type:Boolean,default:!1},as:{type:[String,Object],default:"BUTTON"},asChild:{type:Boolean,default:!1}},style:ct,provide:function(){return{$pcTab:this,$parentInstance:this}}},E={name:"Tab",extends:bt,inheritAttrs:!1,inject:["$pcTabs","$pcTabList"],methods:{onFocus:function(){this.$pcTabs.selectOnFocus&&this.changeActiveValue()},onClick:function(){this.changeActiveValue()},onKeydown:function(t){switch(t.code){case"ArrowRight":this.onArrowRightKey(t);break;case"ArrowLeft":this.onArrowLeftKey(t);break;case"Home":this.onHomeKey(t);break;case"End":this.onEndKey(t);break;case"PageDown":this.onPageDownKey(t);break;case"PageUp":this.onPageUpKey(t);break;case"Enter":case"NumpadEnter":case"Space":this.onEnterKey(t);break}},onArrowRightKey:function(t){var n=this.findNextTab(t.currentTarget);n?this.changeFocusedTab(t,n):this.onHomeKey(t),t.preventDefault()},onArrowLeftKey:function(t){var n=this.findPrevTab(t.currentTarget);n?this.changeFocusedTab(t,n):this.onEndKey(t),t.preventDefault()},onHomeKey:function(t){var n=this.findFirstTab();this.changeFocusedTab(t,n),t.preventDefault()},onEndKey:function(t){var n=this.findLastTab();this.changeFocusedTab(t,n),t.preventDefault()},onPageDownKey:function(t){this.scrollInView(this.findLastTab()),t.preventDefault()},onPageUpKey:function(t){this.scrollInView(this.findFirstTab()),t.preventDefault()},onEnterKey:function(t){this.changeActiveValue()},findNextTab:function(t){var n=arguments.length>1&&arguments[1]!==void 0?arguments[1]:!1,s=n?t:t.nextElementSibling;return s?N(s,"data-p-disabled")||N(s,"data-pc-section")==="activebar"?this.findNextTab(s):D(s,'[data-pc-name="tab"]'):null},findPrevTab:function(t){var n=arguments.length>1&&arguments[1]!==void 0?arguments[1]:!1,s=n?t:t.previousElementSibling;return s?N(s,"data-p-disabled")||N(s,"data-pc-section")==="activebar"?this.findPrevTab(s):D(s,'[data-pc-name="tab"]'):null},findFirstTab:function(){return this.findNextTab(this.$pcTabList.$refs.tabs.firstElementChild,!0)},findLastTab:function(){return this.findPrevTab(this.$pcTabList.$refs.tabs.lastElementChild,!0)},changeActiveValue:function(){this.$pcTabs.updateValue(this.value)},changeFocusedTab:function(t,n){Y(n),this.scrollInView(n)},scrollInView:function(t){var n;t==null||(n=t.scrollIntoView)===null||n===void 0||n.call(t,{block:"nearest"})}},computed:{active:function(){var t;return W((t=this.$pcTabs)===null||t===void 0?void 0:t.d_value,this.value)},id:function(){var t;return"".concat((t=this.$pcTabs)===null||t===void 0?void 0:t.$id,"_tab_").concat(this.value)},ariaControls:function(){var t;return"".concat((t=this.$pcTabs)===null||t===void 0?void 0:t.$id,"_tabpanel_").concat(this.value)},attrs:function(){return f(this.asAttrs,this.a11yAttrs,this.ptmi("root",this.ptParams))},asAttrs:function(){return this.as==="BUTTON"?{type:"button",disabled:this.disabled}:void 0},a11yAttrs:function(){return{id:this.id,tabindex:this.active?this.$pcTabs.tabindex:-1,role:"tab","aria-selected":this.active,"aria-controls":this.ariaControls,"data-pc-name":"tab","data-p-disabled":this.disabled,"data-p-active":this.active,onFocus:this.onFocus,onKeydown:this.onKeydown}},ptParams:function(){return{context:{active:this.active}}},dataP:function(){return U({active:this.active})}},directives:{ripple:M}};function pt(e,t,n,s,u,a){var b=H("ripple");return e.asChild?y(e.$slots,"default",{key:1,dataP:a.dataP,class:z(e.cx("root")),active:a.active,a11yAttrs:a.a11yAttrs,onClick:a.onClick}):I((i(),$(K(e.as),f({key:0,class:e.cx("root"),"data-p":a.dataP,onClick:a.onClick},a.attrs),{default:m(function(){return[y(e.$slots,"default")]}),_:3},16,["class","data-p","onClick"])),[[b]])}E.render=pt;var ft={root:"p-tablist",content:"p-tablist-content p-tablist-viewport",tabList:"p-tablist-tab-list",activeBar:"p-tablist-active-bar",prevButton:"p-tablist-prev-button p-tablist-nav-button",nextButton:"p-tablist-next-button p-tablist-nav-button"},ht=A.extend({name:"tablist",classes:ft}),vt={name:"BaseTabList",extends:C,props:{},style:ht,provide:function(){return{$pcTabList:this,$parentInstance:this}}},q={name:"TabList",extends:vt,inheritAttrs:!1,inject:["$pcTabs"],data:function(){return{isPrevButtonEnabled:!1,isNextButtonEnabled:!0}},resizeObserver:void 0,watch:{showNavigators:function(t){t?this.bindResizeObserver():this.unbindResizeObserver()},activeValue:{flush:"post",handler:function(){this.updateInkBar()}}},mounted:function(){var t=this;setTimeout(function(){t.updateInkBar()},150),this.showNavigators&&(this.updateButtonState(),this.bindResizeObserver())},updated:function(){this.showNavigators&&this.updateButtonState()},beforeUnmount:function(){this.unbindResizeObserver()},methods:{onScroll:function(t){this.showNavigators&&this.updateButtonState(),t.preventDefault()},onPrevButtonClick:function(){var t=this.$refs.content,n=this.getVisibleButtonWidths(),s=F(t)-n,u=Math.abs(t.scrollLeft),a=s*.8,b=u-a,h=Math.max(b,0);t.scrollLeft=j(t)?-1*h:h},onNextButtonClick:function(){var t=this.$refs.content,n=this.getVisibleButtonWidths(),s=F(t)-n,u=Math.abs(t.scrollLeft),a=s*.8,b=u+a,h=t.scrollWidth-s,_=Math.min(b,h);t.scrollLeft=j(t)?-1*_:_},bindResizeObserver:function(){var t=this;this.resizeObserver=new ResizeObserver(function(){return t.updateButtonState()}),this.resizeObserver.observe(this.$refs.list)},unbindResizeObserver:function(){var t;(t=this.resizeObserver)===null||t===void 0||t.unobserve(this.$refs.list),this.resizeObserver=void 0},updateInkBar:function(){var t=this.$refs,n=t.content,s=t.inkbar,u=t.tabs;if(s){var a=D(n,'[data-pc-name="tab"][data-p-active="true"]');this.$pcTabs.isVertical()?(s.style.height=Z(a)+"px",s.style.top=V(a).top-V(u).top+"px"):(s.style.width=tt(a)+"px",s.style.left=V(a).left-V(u).left+"px")}},updateButtonState:function(){var t=this.$refs,n=t.list,s=t.content,u=s.scrollTop,a=s.scrollWidth,b=s.scrollHeight,h=s.offsetWidth,_=s.offsetHeight,w=Math.abs(s.scrollLeft),L=[F(s),X(s)],S=L[0],p=L[1];this.$pcTabs.isVertical()?(this.isPrevButtonEnabled=u!==0,this.isNextButtonEnabled=n.offsetHeight>=_&&parseInt(u)!==b-p):(this.isPrevButtonEnabled=w!==0,this.isNextButtonEnabled=n.offsetWidth>=h&&parseInt(w)!==a-S)},getVisibleButtonWidths:function(){var t=this.$refs,n=t.prevButton,s=t.nextButton,u=0;return this.showNavigators&&(u=(n?.offsetWidth||0)+(s?.offsetWidth||0)),u}},computed:{templates:function(){return this.$pcTabs.$slots},activeValue:function(){return this.$pcTabs.d_value},showNavigators:function(){return this.$pcTabs.showNavigators},prevButtonAriaLabel:function(){return this.$primevue.config.locale.aria?this.$primevue.config.locale.aria.previous:void 0},nextButtonAriaLabel:function(){return this.$primevue.config.locale.aria?this.$primevue.config.locale.aria.next:void 0},dataP:function(){return U({scrollable:this.$pcTabs.scrollable})}},components:{ChevronLeftIcon:st,ChevronRightIcon:rt},directives:{ripple:M}},mt=["data-p"],gt=["aria-label","tabindex"],xt=["data-p"],yt=["aria-orientation"],kt=["aria-label","tabindex"];function _t(e,t,n,s,u,a){var b=H("ripple");return i(),l("div",f({ref:"list",class:e.cx("root"),"data-p":a.dataP},e.ptmi("root")),[a.showNavigators&&u.isPrevButtonEnabled?I((i(),l("button",f({key:0,ref:"prevButton",type:"button",class:e.cx("prevButton"),"aria-label":a.prevButtonAriaLabel,tabindex:a.$pcTabs.tabindex,onClick:t[0]||(t[0]=function(){return a.onPrevButtonClick&&a.onPrevButtonClick.apply(a,arguments)})},e.ptm("prevButton"),{"data-pc-group-section":"navigator"}),[(i(),$(K(a.templates.previcon||"ChevronLeftIcon"),f({"aria-hidden":"true"},e.ptm("prevIcon")),null,16))],16,gt)),[[b]]):g("",!0),o("div",f({ref:"content",class:e.cx("content"),onScroll:t[1]||(t[1]=function(){return a.onScroll&&a.onScroll.apply(a,arguments)}),"data-p":a.dataP},e.ptm("content")),[o("div",f({ref:"tabs",class:e.cx("tabList"),role:"tablist","aria-orientation":a.$pcTabs.orientation||"horizontal"},e.ptm("tabList")),[y(e.$slots,"default"),o("span",f({ref:"inkbar",class:e.cx("activeBar"),role:"presentation","aria-hidden":"true"},e.ptm("activeBar")),null,16)],16,yt)],16,xt),a.showNavigators&&u.isNextButtonEnabled?I((i(),l("button",f({key:1,ref:"nextButton",type:"button",class:e.cx("nextButton"),"aria-label":a.nextButtonAriaLabel,tabindex:a.$pcTabs.tabindex,onClick:t[2]||(t[2]=function(){return a.onNextButtonClick&&a.onNextButtonClick.apply(a,arguments)})},e.ptm("nextButton"),{"data-pc-group-section":"navigator"}),[(i(),$(K(a.templates.nexticon||"ChevronRightIcon"),f({"aria-hidden":"true"},e.ptm("nextIcon")),null,16))],16,kt)),[[b]]):g("",!0)],16,mt)}q.render=_t;var $t={root:function(t){var n=t.instance;return["p-tabpanel",{"p-tabpanel-active":n.active}]}},wt=A.extend({name:"tabpanel",classes:$t}),Tt={name:"BaseTabPanel",extends:C,props:{value:{type:[String,Number],default:void 0},as:{type:[String,Object],default:"DIV"},asChild:{type:Boolean,default:!1},header:null,headerStyle:null,headerClass:null,headerProps:null,headerActionProps:null,contentStyle:null,contentClass:null,contentProps:null,disabled:Boolean},style:wt,provide:function(){return{$pcTabPanel:this,$parentInstance:this}}},R={name:"TabPanel",extends:Tt,inheritAttrs:!1,inject:["$pcTabs"],computed:{active:function(){var t;return W((t=this.$pcTabs)===null||t===void 0?void 0:t.d_value,this.value)},id:function(){var t;return"".concat((t=this.$pcTabs)===null||t===void 0?void 0:t.$id,"_tabpanel_").concat(this.value)},ariaLabelledby:function(){var t;return"".concat((t=this.$pcTabs)===null||t===void 0?void 0:t.$id,"_tab_").concat(this.value)},attrs:function(){return f(this.a11yAttrs,this.ptmi("root",this.ptParams))},a11yAttrs:function(){var t;return{id:this.id,tabindex:(t=this.$pcTabs)===null||t===void 0?void 0:t.tabindex,role:"tabpanel","aria-labelledby":this.ariaLabelledby,"data-pc-name":"tabpanel","data-p-active":this.active}},ptParams:function(){return{context:{active:this.active}}}}};function Bt(e,t,n,s,u,a){var b,h;return a.$pcTabs?(i(),l(P,{key:1},[e.asChild?y(e.$slots,"default",{key:1,class:z(e.cx("root")),active:a.active,a11yAttrs:a.a11yAttrs}):(i(),l(P,{key:0},[!((b=a.$pcTabs)!==null&&b!==void 0&&b.lazy)||a.active?I((i(),$(K(e.as),f({key:0,class:e.cx("root")},a.attrs),{default:m(function(){return[y(e.$slots,"default")]}),_:3},16,["class"])),[[et,(h=a.$pcTabs)!==null&&h!==void 0&&h.lazy?!0:a.active]]):g("",!0)],64))],64)):y(e.$slots,"default",{key:0})}R.render=Bt;var Pt={root:"p-tabpanels"},At=A.extend({name:"tabpanels",classes:Pt}),Ct={name:"BaseTabPanels",extends:C,props:{},style:At,provide:function(){return{$pcTabPanels:this,$parentInstance:this}}},Q={name:"TabPanels",extends:Ct,inheritAttrs:!1};function Lt(e,t,n,s,u,a){return i(),l("div",f({class:e.cx("root"),role:"presentation"},e.ptmi("root")),[y(e.$slots,"default")],16)}Q.render=Lt;var St=`
    .p-tabs {
        display: flex;
        flex-direction: column;
    }

    .p-tablist {
        display: flex;
        position: relative;
        overflow: hidden;
        background: dt('tabs.tablist.background');
    }

    .p-tablist-viewport {
        overflow-x: auto;
        overflow-y: hidden;
        scroll-behavior: smooth;
        scrollbar-width: none;
        overscroll-behavior: contain auto;
    }

    .p-tablist-viewport::-webkit-scrollbar {
        display: none;
    }

    .p-tablist-tab-list {
        position: relative;
        display: flex;
        border-style: solid;
        border-color: dt('tabs.tablist.border.color');
        border-width: dt('tabs.tablist.border.width');
    }

    .p-tablist-content {
        flex-grow: 1;
    }

    .p-tablist-nav-button {
        all: unset;
        position: absolute !important;
        flex-shrink: 0;
        inset-block-start: 0;
        z-index: 2;
        height: 100%;
        display: flex;
        align-items: center;
        justify-content: center;
        background: dt('tabs.nav.button.background');
        color: dt('tabs.nav.button.color');
        width: dt('tabs.nav.button.width');
        transition:
            color dt('tabs.transition.duration'),
            outline-color dt('tabs.transition.duration'),
            box-shadow dt('tabs.transition.duration');
        box-shadow: dt('tabs.nav.button.shadow');
        outline-color: transparent;
        cursor: pointer;
    }

    .p-tablist-nav-button:focus-visible {
        z-index: 1;
        box-shadow: dt('tabs.nav.button.focus.ring.shadow');
        outline: dt('tabs.nav.button.focus.ring.width') dt('tabs.nav.button.focus.ring.style') dt('tabs.nav.button.focus.ring.color');
        outline-offset: dt('tabs.nav.button.focus.ring.offset');
    }

    .p-tablist-nav-button:hover {
        color: dt('tabs.nav.button.hover.color');
    }

    .p-tablist-prev-button {
        inset-inline-start: 0;
    }

    .p-tablist-next-button {
        inset-inline-end: 0;
    }

    .p-tablist-prev-button:dir(rtl),
    .p-tablist-next-button:dir(rtl) {
        transform: rotate(180deg);
    }

    .p-tab {
        flex-shrink: 0;
        cursor: pointer;
        user-select: none;
        position: relative;
        border-style: solid;
        white-space: nowrap;
        gap: dt('tabs.tab.gap');
        background: dt('tabs.tab.background');
        border-width: dt('tabs.tab.border.width');
        border-color: dt('tabs.tab.border.color');
        color: dt('tabs.tab.color');
        padding: dt('tabs.tab.padding');
        font-weight: dt('tabs.tab.font.weight');
        transition:
            background dt('tabs.transition.duration'),
            border-color dt('tabs.transition.duration'),
            color dt('tabs.transition.duration'),
            outline-color dt('tabs.transition.duration'),
            box-shadow dt('tabs.transition.duration');
        margin: dt('tabs.tab.margin');
        outline-color: transparent;
    }

    .p-tab:not(.p-disabled):focus-visible {
        z-index: 1;
        box-shadow: dt('tabs.tab.focus.ring.shadow');
        outline: dt('tabs.tab.focus.ring.width') dt('tabs.tab.focus.ring.style') dt('tabs.tab.focus.ring.color');
        outline-offset: dt('tabs.tab.focus.ring.offset');
    }

    .p-tab:not(.p-tab-active):not(.p-disabled):hover {
        background: dt('tabs.tab.hover.background');
        border-color: dt('tabs.tab.hover.border.color');
        color: dt('tabs.tab.hover.color');
    }

    .p-tab-active {
        background: dt('tabs.tab.active.background');
        border-color: dt('tabs.tab.active.border.color');
        color: dt('tabs.tab.active.color');
    }

    .p-tabpanels {
        background: dt('tabs.tabpanel.background');
        color: dt('tabs.tabpanel.color');
        padding: dt('tabs.tabpanel.padding');
        outline: 0 none;
    }

    .p-tabpanel:focus-visible {
        box-shadow: dt('tabs.tabpanel.focus.ring.shadow');
        outline: dt('tabs.tabpanel.focus.ring.width') dt('tabs.tabpanel.focus.ring.style') dt('tabs.tabpanel.focus.ring.color');
        outline-offset: dt('tabs.tabpanel.focus.ring.offset');
    }

    .p-tablist-active-bar {
        z-index: 1;
        display: block;
        position: absolute;
        inset-block-end: dt('tabs.active.bar.bottom');
        height: dt('tabs.active.bar.height');
        background: dt('tabs.active.bar.background');
        transition: 250ms cubic-bezier(0.35, 0, 0.25, 1);
    }
`,Nt={root:function(t){var n=t.props;return["p-tabs p-component",{"p-tabs-scrollable":n.scrollable}]}},Vt=A.extend({name:"tabs",style:St,classes:Nt}),It={name:"BaseTabs",extends:C,props:{value:{type:[String,Number],default:void 0},lazy:{type:Boolean,default:!1},scrollable:{type:Boolean,default:!1},showNavigators:{type:Boolean,default:!0},tabindex:{type:Number,default:0},selectOnFocus:{type:Boolean,default:!1}},style:Vt,provide:function(){return{$pcTabs:this,$parentInstance:this}}},G={name:"Tabs",extends:It,inheritAttrs:!1,emits:["update:value"],data:function(){return{d_value:this.value}},watch:{value:function(t){this.d_value=t}},methods:{updateValue:function(t){this.d_value!==t&&(this.d_value=t,this.$emit("update:value",t))},isVertical:function(){return this.orientation==="vertical"}}};function Kt(e,t,n,s,u,a){return i(),l("div",f({class:e.cx("root")},e.ptmi("root")),[y(e.$slots,"default")],16)}G.render=Kt;const zt={key:0,class:"flex items-center justify-center gap-2 py-10 text-sm text-muted-foreground"},Ft={key:0,class:"ml-1.5 rounded-full bg-teal-100 px-1.5 py-0.5 text-xs font-medium text-teal-700 dark:bg-teal-950/40 dark:text-teal-400"},Ot={key:0,class:"ml-1.5 rounded-full bg-muted px-1.5 py-0.5 text-xs font-medium text-muted-foreground"},Dt={key:0,class:"py-8 text-center text-sm text-muted-foreground"},Et={key:1,class:"overflow-x-auto pt-2"},Rt={class:"w-full text-sm"},jt={class:"divide-y divide-border/50"},Wt={class:"py-2 pr-4 font-mono text-xs whitespace-nowrap text-muted-foreground"},Ht={class:"py-2 pr-4 text-xs"},Mt={key:0,class:"inline-flex items-center gap-1 rounded bg-muted px-1.5 py-0.5 font-mono text-xs font-medium text-muted-foreground"},Ut={key:1,class:"text-muted-foreground"},qt={class:"py-2 pr-4 text-xs"},Qt={class:"py-2 pr-4 text-center"},Gt={key:0,class:"inline-flex items-center gap-1 rounded-full bg-blue-100 px-2 py-0.5 text-xs font-medium text-blue-700 dark:bg-blue-950/30 dark:text-blue-400"},Jt={key:1,class:"inline-flex items-center gap-1 rounded-full bg-emerald-100 px-2 py-0.5 text-xs font-medium text-emerald-700 dark:bg-emerald-950/30 dark:text-emerald-400"},Yt={key:2,class:"inline-flex items-center gap-1 rounded-full bg-red-100 px-2 py-0.5 text-xs font-medium text-red-700 dark:bg-red-950/30 dark:text-red-400"},Xt={class:"py-2 pr-4 text-right text-muted-foreground tabular-nums"},Zt={class:"py-2 pr-4 text-right font-semibold tabular-nums"},te={class:"py-2 text-xs text-muted-foreground"},ee={key:0,class:"py-8 text-center text-sm text-muted-foreground"},ae={key:1,class:"relative border-l border-border pt-2 pl-1"},ne={class:"flex flex-wrap items-baseline gap-1 text-xs"},se={class:"text-foreground"},re={class:"text-muted-foreground"},ie={key:0,class:"mt-2 overflow-hidden rounded-lg border text-xs"},oe={class:"w-full"},le={class:"border-b bg-muted/40"},de={key:0,class:"px-3 py-1.5 text-left font-medium text-muted-foreground"},ue={key:1,class:"px-3 py-1.5 text-left font-medium text-muted-foreground"},ce={class:"divide-y"},be={class:"px-3 py-1.5 font-medium text-muted-foreground"},pe={key:0,class:"px-3 py-1.5 whitespace-pre-line"},fe={key:1,class:"px-3 py-1.5 whitespace-pre-line"},_e=at({__name:"HistoriqueModal",props:{visible:{type:Boolean},ajustements:{},modifications:{},loading:{type:Boolean},title:{}},emits:["update:visible"],setup(e,{emit:t}){const n=e,s=t,u=nt({get:()=>n.visible,set:p=>s("update:visible",p)}),a={created:"text-blue-600 dark:text-blue-400",updated:"text-amber-600 dark:text-amber-400",deleted:"text-red-600 dark:text-red-400",validated:"text-emerald-600 dark:text-emerald-400",cancelled:"text-red-600 dark:text-red-400"},b={created:"Créé par",updated:"Modifié par",deleted:"Supprimé par",validated:"Validé par",cancelled:"Annulé par"},h={created:"bg-blue-500",updated:"bg-amber-500",deleted:"bg-red-500",validated:"bg-emerald-500",cancelled:"bg-red-500"},_={nom:"Nom",type:"Type",statut:"Statut",prix_vente:"Prix de vente",prix_achat:"Prix d'achat",prix_usine:"Prix usine",cout:"Coût",qte_stock:"Stock",seuil_alerte_stock:"Seuil d'alerte",is_alerte:"Alerte",description:"Description",code_fournisseur:"Code fournisseur"};function w(p,d){return d==null?"—":p==="is_alerte"?d?"Oui":"Non":["prix_vente","prix_achat","prix_usine","cout"].includes(p)?new Intl.NumberFormat("fr-FR").format(Number(d))+" GNF":["qte_stock","seuil_alerte_stock"].includes(p)?new Intl.NumberFormat("fr-FR").format(Number(d)):typeof d=="number"?String(d):dt(String(d))}function L(p){const d=p.old_values??{},r=p.new_values??{};return[...new Set([...Object.keys(d),...Object.keys(r)])].map(k=>({field:k,label:_[k]??k,old:w(k,d[k]),new:w(k,r[k])}))}function S(p){return p==null?"—":new Intl.NumberFormat("fr-FR").format(p)}return(p,d)=>(i(),$(v(J),{visible:u.value,"onUpdate:visible":d[0]||(d[0]=r=>u.value=r),modal:"",header:e.title??"Historique",style:{width:"820px"},draggable:!1},{default:m(()=>[e.loading?(i(),l("div",zt,[x(v(it),{class:"h-5 w-5 animate-spin"}),d[1]||(d[1]=B(" Chargement… ",-1))])):(i(),$(v(G),{key:1,value:"0"},{default:m(()=>[x(v(q),null,{default:m(()=>[x(v(E),{value:"0"},{default:m(()=>[d[2]||(d[2]=B(" Ajustements stock ",-1)),e.ajustements.length?(i(),l("span",Ft,c(e.ajustements.length),1)):g("",!0)]),_:1}),x(v(E),{value:"1"},{default:m(()=>[d[3]||(d[3]=B(" Modifications ",-1)),e.modifications.length?(i(),l("span",Ot,c(e.modifications.length),1)):g("",!0)]),_:1})]),_:1}),x(v(Q),null,{default:m(()=>[x(v(R),{value:"0"},{default:m(()=>[e.ajustements.length===0?(i(),l("div",Dt," Aucun ajustement de stock enregistré. ")):(i(),l("div",Et,[o("table",Rt,[d[4]||(d[4]=o("thead",null,[o("tr",{class:"border-b text-xs text-muted-foreground"},[o("th",{class:"pr-4 pb-2 text-left font-medium"}," Date "),o("th",{class:"pr-4 pb-2 text-left font-medium"}," Site "),o("th",{class:"pr-4 pb-2 text-left font-medium"}," Par "),o("th",{class:"pr-4 pb-2 text-center font-medium"}," Action "),o("th",{class:"pr-4 pb-2 text-right font-medium"}," Avant "),o("th",{class:"pr-4 pb-2 text-right font-medium"}," Après "),o("th",{class:"pb-2 text-left font-medium"}," Motif ")])],-1)),o("tbody",jt,[(i(!0),l(P,null,O(e.ajustements,r=>(i(),l("tr",{key:r.id,class:"group"},[o("td",Wt,c(r.created_at),1),o("td",Ht,[r.site_code||r.site_nom?(i(),l("span",Mt,c(r.site_code??r.site_nom),1)):(i(),l("span",Ut,"—"))]),o("td",qt,c(r.createur_nom||"—"),1),o("td",Qt,[r.is_initial?(i(),l("span",Gt,c(r.quantite),1)):r.type==="entree"?(i(),l("span",Jt,[x(v(ot),{class:"h-3 w-3"}),B(" +"+c(r.quantite),1)])):(i(),l("span",Yt,[x(v(lt),{class:"h-3 w-3"}),B(" -"+c(r.quantite),1)]))]),o("td",Xt,c(S(r.stock_avant)),1),o("td",Zt,c(S(r.stock_apres)),1),o("td",te,c(r.notes||"—"),1)]))),128))])])]))]),_:1}),x(v(R),{value:"1"},{default:m(()=>[e.modifications.length===0?(i(),l("div",ee," Aucune modification enregistrée. ")):(i(),l("ol",ae,[(i(!0),l(P,null,O(e.modifications,r=>(i(),l("li",{key:r.id,class:"mb-6 ml-5 last:mb-0"},[o("span",{class:z(["absolute -left-1.5 mt-1.5 h-3 w-3 rounded-full border-2 border-background",h[r.event_code]??"bg-zinc-400"])},null,2),o("div",ne,[o("span",{class:z(["font-semibold",a[r.event_code]??"text-muted-foreground"])},c(b[r.event_code]??r.event_label),3),o("strong",se,c(r.actor_name),1),o("span",re,"— "+c(r.created_at),1)]),r.old_values&&Object.keys(r.old_values).length>0||r.new_values&&Object.keys(r.new_values).length>0?(i(),l("div",ie,[o("table",oe,[o("thead",null,[o("tr",le,[d[5]||(d[5]=o("th",{class:"px-3 py-1.5 text-left font-medium text-muted-foreground"}," Champ ",-1)),r.old_values?(i(),l("th",de," Avant ")):g("",!0),r.new_values?(i(),l("th",ue," Après ")):g("",!0)])]),o("tbody",ce,[(i(!0),l(P,null,O(L(r),T=>(i(),l("tr",{key:T.field,class:"hover:bg-muted/10"},[o("td",be,c(T.label),1),r.old_values?(i(),l("td",pe,c(T.old),1)):g("",!0),r.new_values?(i(),l("td",fe,c(T.new),1)):g("",!0)]))),128))])])])):g("",!0)]))),128))]))]),_:1})]),_:1})]),_:1}))]),_:1},8,["visible","header"]))}});export{_e as _};
