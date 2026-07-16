import{a1 as _,ar as A,aj as P,a3 as S,a8 as K,i as B,o as a,w,b as o,j as b,J as c,e as D,aH as O,x as $,D as m,F as E,f as i,n as I,t as h,C as j,d as z,r as x,A as T,v as V,g as F}from"./app-BgEsh_4K.js";import{a as N,s as U,x as C}from"./index-C3HEpoZh.js";import{s as Z}from"./index-D1ZaK56w.js";import{F as M,u as q,b as H}from"./index-00Cb3IR0.js";import{s as J,f as W}from"./index-DrnGpYyg.js";var G=`
    .p-drawer {
        display: flex;
        flex-direction: column;
        transform: translate3d(0px, 0px, 0px);
        position: relative;
        transition: transform 0.3s;
        background: dt('drawer.background');
        color: dt('drawer.color');
        border-style: solid;
        border-color: dt('drawer.border.color');
        box-shadow: dt('drawer.shadow');
    }

    .p-drawer-content {
        overflow-y: auto;
        flex-grow: 1;
        padding: dt('drawer.content.padding');
    }

    .p-drawer-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        flex-shrink: 0;
        padding: dt('drawer.header.padding');
    }

    .p-drawer-footer {
        padding: dt('drawer.footer.padding');
    }

    .p-drawer-title {
        font-weight: dt('drawer.title.font.weight');
        font-size: dt('drawer.title.font.size');
    }

    .p-drawer-full .p-drawer {
        transition: none;
        transform: none;
        width: 100vw !important;
        height: 100vh !important;
        max-height: 100%;
        top: 0px !important;
        left: 0px !important;
        border-width: 1px;
    }

    .p-drawer-left .p-drawer-enter-active {
        animation: p-animate-drawer-enter-left 0.5s cubic-bezier(0.32, 0.72, 0, 1);
    }
    .p-drawer-left .p-drawer-leave-active {
        animation: p-animate-drawer-leave-left 0.5s cubic-bezier(0.32, 0.72, 0, 1);
    }

    .p-drawer-right .p-drawer-enter-active {
        animation: p-animate-drawer-enter-right 0.5s cubic-bezier(0.32, 0.72, 0, 1);
    }
    .p-drawer-right .p-drawer-leave-active {
        animation: p-animate-drawer-leave-right 0.5s cubic-bezier(0.32, 0.72, 0, 1);
    }

    .p-drawer-top .p-drawer-enter-active {
        animation: p-animate-drawer-enter-top 0.5s cubic-bezier(0.32, 0.72, 0, 1);
    }
    .p-drawer-top .p-drawer-leave-active {
        animation: p-animate-drawer-leave-top 0.5s cubic-bezier(0.32, 0.72, 0, 1);
    }

    .p-drawer-bottom .p-drawer-enter-active {
        animation: p-animate-drawer-enter-bottom 0.5s cubic-bezier(0.32, 0.72, 0, 1);
    }
    .p-drawer-bottom .p-drawer-leave-active {
        animation: p-animate-drawer-leave-bottom 0.5s cubic-bezier(0.32, 0.72, 0, 1);
    }

    .p-drawer-full .p-drawer-enter-active {
        animation: p-animate-drawer-enter-full 0.5s cubic-bezier(0.32, 0.72, 0, 1);
    }
    .p-drawer-full .p-drawer-leave-active {
        animation: p-animate-drawer-leave-full 0.5s cubic-bezier(0.32, 0.72, 0, 1);
    }
    
    .p-drawer-left .p-drawer {
        width: 20rem;
        height: 100%;
        border-inline-end-width: 1px;
    }

    .p-drawer-right .p-drawer {
        width: 20rem;
        height: 100%;
        border-inline-start-width: 1px;
    }

    .p-drawer-top .p-drawer {
        height: 10rem;
        width: 100%;
        border-block-end-width: 1px;
    }

    .p-drawer-bottom .p-drawer {
        height: 10rem;
        width: 100%;
        border-block-start-width: 1px;
    }

    .p-drawer-left .p-drawer-content,
    .p-drawer-right .p-drawer-content,
    .p-drawer-top .p-drawer-content,
    .p-drawer-bottom .p-drawer-content {
        width: 100%;
        height: 100%;
    }

    .p-drawer-open {
        display: flex;
    }

    .p-drawer-mask:dir(rtl) {
        flex-direction: row-reverse;
    }

    @keyframes p-animate-drawer-enter-left {
        from {
            transform: translate3d(-100%, 0px, 0px);
        }
    }

    @keyframes p-animate-drawer-leave-left {
        to {
            transform: translate3d(-100%, 0px, 0px);
        }
    }

    @keyframes p-animate-drawer-enter-right {
        from {
            transform: translate3d(100%, 0px, 0px);
        }
    }

    @keyframes p-animate-drawer-leave-right {
        to {
            transform: translate3d(100%, 0px, 0px);
        }
    }

    @keyframes p-animate-drawer-enter-top {
        from {
            transform: translate3d(0px, -100%, 0px);
        }
    }

    @keyframes p-animate-drawer-leave-top {
        to {
            transform: translate3d(0px, -100%, 0px);
        }
    }

    @keyframes p-animate-drawer-enter-bottom {
        from {
            transform: translate3d(0px, 100%, 0px);
        }
    }

    @keyframes p-animate-drawer-leave-bottom {
        to {
            transform: translate3d(0px, 100%, 0px);
        }
    }

    @keyframes p-animate-drawer-enter-full {
        from {
            opacity: 0;
            transform: scale(0.93);
        }
    }

    @keyframes p-animate-drawer-leave-full {
        to {
            opacity: 0;
            transform: scale(0.93);
        }
    }
`,Q={mask:function(t){var n=t.position,s=t.modal;return{position:"fixed",height:"100%",width:"100%",left:0,top:0,display:"flex",justifyContent:n==="left"?"flex-start":n==="right"?"flex-end":"center",alignItems:n==="top"?"flex-start":n==="bottom"?"flex-end":"center",pointerEvents:s?"auto":"none"}},root:{pointerEvents:"auto"}},X={mask:function(t){var n=t.instance,s=t.props,d=["left","right","top","bottom"],r=d.find(function(f){return f===s.position});return["p-drawer-mask",{"p-overlay-mask p-overlay-mask-enter-active":s.modal,"p-drawer-open":n.containerVisible,"p-drawer-full":n.fullScreen},r?"p-drawer-".concat(r):""]},root:function(t){var n=t.instance;return["p-drawer p-component",{"p-drawer-full":n.fullScreen}]},header:"p-drawer-header",title:"p-drawer-title",pcCloseButton:"p-drawer-close-button",content:"p-drawer-content",footer:"p-drawer-footer"},Y=_.extend({name:"drawer",style:G,classes:X,inlineStyles:Q}),ee={name:"BaseDrawer",extends:J,props:{visible:{type:Boolean,default:!1},position:{type:String,default:"left"},header:{type:null,default:null},baseZIndex:{type:Number,default:0},autoZIndex:{type:Boolean,default:!0},dismissable:{type:Boolean,default:!0},showCloseIcon:{type:Boolean,default:!0},closeButtonProps:{type:Object,default:function(){return{severity:"secondary",text:!0,rounded:!0}}},closeIcon:{type:String,default:void 0},modal:{type:Boolean,default:!0},blockScroll:{type:Boolean,default:!1},closeOnEscape:{type:Boolean,default:!0}},style:Y,provide:function(){return{$pcDrawer:this,$parentInstance:this}}};function v(e){"@babel/helpers - typeof";return v=typeof Symbol=="function"&&typeof Symbol.iterator=="symbol"?function(t){return typeof t}:function(t){return t&&typeof Symbol=="function"&&t.constructor===Symbol&&t!==Symbol.prototype?"symbol":typeof t},v(e)}function L(e,t,n){return(t=te(t))in e?Object.defineProperty(e,t,{value:n,enumerable:!0,configurable:!0,writable:!0}):e[t]=n,e}function te(e){var t=ne(e,"string");return v(t)=="symbol"?t:t+""}function ne(e,t){if(v(e)!="object"||!e)return e;var n=e[Symbol.toPrimitive];if(n!==void 0){var s=n.call(e,t);if(v(s)!="object")return s;throw new TypeError("@@toPrimitive must return a primitive value.")}return(t==="string"?String:Number)(e)}var R={name:"Drawer",extends:ee,inheritAttrs:!1,emits:["update:visible","show","after-show","hide","after-hide","before-hide"],data:function(){return{containerVisible:this.visible}},container:null,mask:null,content:null,headerContainer:null,footerContainer:null,closeButton:null,outsideClickListener:null,documentKeydownListener:null,watch:{dismissable:function(t){t&&!this.modal?this.bindOutsideClickListener():this.unbindOutsideClickListener()}},updated:function(){this.visible&&(this.containerVisible=this.visible)},beforeUnmount:function(){this.disableDocumentSettings(),this.mask&&this.autoZIndex&&C.clear(this.mask),this.container=null,this.mask=null},methods:{hide:function(){this.$emit("update:visible",!1)},onEnter:function(){this.$emit("show"),this.focus(),this.bindDocumentKeyDownListener(),this.autoZIndex&&C.set("modal",this.mask,this.baseZIndex||this.$primevue.config.zIndex.modal)},onAfterEnter:function(){this.enableDocumentSettings(),this.$emit("after-show")},onBeforeLeave:function(){this.modal&&!this.isUnstyled&&P(this.mask,"p-overlay-mask-leave-active"),this.$emit("before-hide")},onLeave:function(){this.$emit("hide")},onAfterLeave:function(){this.autoZIndex&&C.clear(this.mask),this.unbindDocumentKeyDownListener(),this.containerVisible=!1,this.disableDocumentSettings(),this.$emit("after-hide")},onMaskClick:function(t){this.dismissable&&this.modal&&this.mask===t.target&&this.hide()},focus:function(){var t=function(d){return d&&d.querySelector("[autofocus]")},n=this.$slots.header&&t(this.headerContainer);n||(n=this.$slots.default&&t(this.container),n||(n=this.$slots.footer&&t(this.footerContainer),n||(n=this.closeButton))),n&&A(n)},enableDocumentSettings:function(){this.dismissable&&!this.modal&&this.bindOutsideClickListener(),this.blockScroll&&H()},disableDocumentSettings:function(){this.unbindOutsideClickListener(),this.blockScroll&&q()},onKeydown:function(t){t.code==="Escape"&&this.closeOnEscape&&this.hide()},containerRef:function(t){this.container=t},maskRef:function(t){this.mask=t},contentRef:function(t){this.content=t},headerContainerRef:function(t){this.headerContainer=t},footerContainerRef:function(t){this.footerContainer=t},closeButtonRef:function(t){this.closeButton=t?t.$el:void 0},bindDocumentKeyDownListener:function(){this.documentKeydownListener||(this.documentKeydownListener=this.onKeydown,document.addEventListener("keydown",this.documentKeydownListener))},unbindDocumentKeyDownListener:function(){this.documentKeydownListener&&(document.removeEventListener("keydown",this.documentKeydownListener),this.documentKeydownListener=null)},bindOutsideClickListener:function(){var t=this;this.outsideClickListener||(this.outsideClickListener=function(n){t.isOutsideClicked(n)&&t.hide()},document.addEventListener("click",this.outsideClickListener,!0))},unbindOutsideClickListener:function(){this.outsideClickListener&&(document.removeEventListener("click",this.outsideClickListener,!0),this.outsideClickListener=null)},isOutsideClicked:function(t){return this.container&&!this.container.contains(t.target)}},computed:{fullScreen:function(){return this.position==="full"},closeAriaLabel:function(){return this.$primevue.config.locale.aria?this.$primevue.config.locale.aria.close:void 0},dataP:function(){return W(L(L(L({"full-screen":this.position==="full"},this.position,this.position),"open",this.containerVisible),"modal",this.modal))}},directives:{focustrap:M},components:{Button:Z,Portal:U,TimesIcon:N}},re=["data-p"],ae=["role","aria-modal","data-p"];function ie(e,t,n,s,d,r){var f=S("Button"),y=S("Portal"),k=K("focustrap");return a(),B(y,null,{default:w(function(){return[d.containerVisible?(a(),o("div",c({key:0,ref:r.maskRef,onMousedown:t[0]||(t[0]=function(){return r.onMaskClick&&r.onMaskClick.apply(r,arguments)}),class:e.cx("mask"),style:e.sx("mask",!0,{position:e.position,modal:e.modal}),"data-p":r.dataP},e.ptm("mask")),[D(O,c({name:"p-drawer",onEnter:r.onEnter,onAfterEnter:r.onAfterEnter,onBeforeLeave:r.onBeforeLeave,onLeave:r.onLeave,onAfterLeave:r.onAfterLeave,appear:""},e.ptm("transition")),{default:w(function(){return[e.visible?$((a(),o("div",c({key:0,ref:r.containerRef,class:e.cx("root"),style:e.sx("root"),role:e.modal?"dialog":"complementary","aria-modal":e.modal?!0:void 0,"data-p":r.dataP},e.ptmi("root")),[e.$slots.container?m(e.$slots,"container",{key:0,closeCallback:r.hide}):(a(),o(E,{key:1},[i("div",c({ref:r.headerContainerRef,class:e.cx("header")},e.ptm("header")),[m(e.$slots,"header",{class:I(e.cx("title"))},function(){return[e.header?(a(),o("div",c({key:0,class:e.cx("title")},e.ptm("title")),h(e.header),17)):b("",!0)]}),e.showCloseIcon?m(e.$slots,"closebutton",{key:0,closeCallback:r.hide},function(){return[D(f,c({ref:r.closeButtonRef,type:"button",class:e.cx("pcCloseButton"),"aria-label":r.closeAriaLabel,unstyled:e.unstyled,onClick:r.hide},e.closeButtonProps,{pt:e.ptm("pcCloseButton"),"data-pc-group-section":"iconcontainer"}),{icon:w(function(g){return[m(e.$slots,"closeicon",{},function(){return[(a(),B(j(e.closeIcon?"span":"TimesIcon"),c({class:[e.closeIcon,g.class]},e.ptm("pcCloseButton").icon),null,16,["class"]))]})]}),_:3},16,["class","aria-label","unstyled","onClick","pt"])]}):b("",!0)],16),i("div",c({ref:r.contentRef,class:e.cx("content")},e.ptm("content")),[m(e.$slots,"default")],16),e.$slots.footer?(a(),o("div",c({key:0,ref:r.footerContainerRef,class:e.cx("footer")},e.ptm("footer")),[m(e.$slots,"footer")],16)):b("",!0)],64))],16,ae)),[[k]]):b("",!0)]}),_:3},16,["onEnter","onAfterEnter","onBeforeLeave","onLeave","onAfterLeave"])],16,re)):b("",!0)]}),_:3})}R.render=ie;var oe={name:"Sidebar",extends:R,mounted:function(){console.warn("Deprecated since v4. Use Drawer component instead.")}};const se={class:"flex items-center gap-2"},de={class:"font-semibold"},le={class:"flex h-full flex-col"},ce={key:0,class:"flex flex-1 items-center justify-center"},ue={key:1,class:"flex flex-1 items-center justify-center text-sm text-destructive"},fe={key:2,class:"flex flex-1 items-center justify-center text-sm text-muted-foreground"},pe={key:3,class:"space-y-0 divide-y overflow-y-auto"},me={class:"flex items-start gap-3"},be={class:"flex-1 space-y-1"},he={class:"flex flex-wrap items-center gap-2"},we={class:"text-xs text-muted-foreground"},ve={key:0,class:"text-sm font-medium"},ye={class:"text-xs text-muted-foreground"},Be=z({__name:"AuditDrawer",props:{visible:{type:Boolean},title:{},auditableType:{},auditableId:{},module:{}},emits:["update:visible"],setup(e,{emit:t}){const n=e,s=t,d=x([]),r=x(!1),f=x(!1),y={created:"bg-blue-100 text-blue-800 dark:bg-blue-900/30 dark:text-blue-300",updated:"bg-amber-100 text-amber-800 dark:bg-amber-900/30 dark:text-amber-300",validated:"bg-emerald-100 text-emerald-800 dark:bg-emerald-900/30 dark:text-emerald-300",cancelled:"bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-300",deleted:"bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-300",paid:"bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-300",rejected:"bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-300",submitted:"bg-blue-100 text-blue-800 dark:bg-blue-900/30 dark:text-blue-300",exported:"bg-slate-100 text-slate-700 dark:bg-slate-800/50 dark:text-slate-300",printed:"bg-slate-100 text-slate-700 dark:bg-slate-800/50 dark:text-slate-300",auto_generated:"bg-cyan-100 text-cyan-800 dark:bg-cyan-900/30 dark:text-cyan-300",auto_recalculated:"bg-cyan-100 text-cyan-800 dark:bg-cyan-900/30 dark:text-cyan-300",frais_added:"bg-orange-100 text-orange-800 dark:bg-orange-900/30 dark:text-orange-300",frais_deleted:"bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-300",payment_cancelled:"bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-300",status_changed:"bg-amber-100 text-amber-800 dark:bg-amber-900/30 dark:text-amber-300",encaissement_added:"bg-violet-100 text-violet-800 dark:bg-violet-900/30 dark:text-violet-300",encaissement_deleted:"bg-orange-100 text-orange-800 dark:bg-orange-900/30 dark:text-orange-300",stock_adjusted:"bg-teal-100 text-teal-800 dark:bg-teal-900/30 dark:text-teal-300"};function k(p){return y[p]??"bg-slate-100 text-slate-700 dark:bg-slate-800/50 dark:text-slate-300"}async function g(){if(!(!n.auditableType||!n.auditableId)){r.value=!0,f.value=!1,d.value=[];try{const p=new URLSearchParams({auditable_type:n.auditableType,auditable_id:n.auditableId});n.module&&p.set("module",n.module);const u=await fetch(`/backoffice/comptabilite/historique/entite?${p.toString()}`,{headers:{Accept:"application/json"}});if(!u.ok)throw new Error("HTTP "+u.status);d.value=await u.json()}catch{f.value=!0}finally{r.value=!1}}}return T(()=>n.visible,p=>{p&&g()}),(p,u)=>(a(),B(F(oe),{visible:e.visible,position:"right",style:{width:"480px"},"onUpdate:visible":u[0]||(u[0]=l=>s("update:visible",l))},{header:w(()=>[i("div",se,[i("span",de,h(e.title??"Historique"),1)])]),default:w(()=>[i("div",le,[r.value?(a(),o("div",ce,[...u[1]||(u[1]=[i("div",{class:"h-6 w-6 animate-spin rounded-full border-2 border-primary border-t-transparent"},null,-1)])])):f.value?(a(),o("div",ue," Impossible de charger l'historique. ")):d.value.length===0?(a(),o("div",fe," Aucune action enregistrée. ")):(a(),o("div",pe,[(a(!0),o(E,null,V(d.value,l=>(a(),o("div",{key:l.id,class:"px-1 py-4"},[i("div",me,[i("div",be,[i("div",he,[i("span",{class:I(["inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium",k(l.event_code)])},h(l.event_label),3),i("span",we,h(l.created_at),1)]),l.description?(a(),o("p",ve,h(l.description),1)):b("",!0),i("p",ye,h(l.actor_name),1)])])]))),128))]))])]),_:1},8,["visible"]))}});export{Be as _};
