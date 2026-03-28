import{B as be,a as f,g as m,o as p,P as S,k as U,A as h,R as re,t as b,G as X,a_ as ie,E as we,am as _,a$ as Ie,L as xe,I as ke,J as Se,K as Ce,S as Le,D as Me,b2 as G,M as R,N as Ke,b as c,z as Ve,n as V,f as r,F as $,j as ee,w as v,T as De,ax as Te,i as Pe,m as D,d as Ae,u as _e,r as Q,c as W,a5 as Fe,e as a,h as ze,l as fe,y as Ee}from"./app-CCacAZZY.js";import{_ as B}from"./index-BWN8-746.js";import{_ as oe}from"./Label.vue_vue_type_script_setup_true_lang-DxB94iC4.js";import{d as Re,_ as Be}from"./AppLayout.vue_vue_type_script_setup_true_lang-VBIPKb4Q.js";import{f as $e}from"./Primitive-bsswwoZe.js";import{c as je,f as Z,R as Ne,a as Ge,e as Ue,b as qe,x as le}from"./index-BZ_tFLKh.js";import{a as He,s as Qe,b as We,C as Je,O as Ye}from"./index-Dv0TueRm.js";import{c as Xe}from"./index-CsPhAP1V.js";import{s as se}from"./index-C6jyQR1M.js";import{s as J}from"./index-B3e2zRs-.js";import{A as Ze}from"./arrow-left-CDB-baHb.js";import{T as he,P as et}from"./trash-2-CSfUnHk2.js";import{S as tt}from"./save-C9_it4dN.js";import"./useForwardExpose-D0BOaO1B.js";import"./usePrimitiveElement-UlmEbrbD.js";import"./DropdownMenuTrigger.vue_vue_type_script_setup_true_lang-BBdJPORv.js";import"./createLucideIcon-DBXkqyvZ.js";import"./RovingFocusGroup-C9V0JD4Z.js";import"./useArrowNavigation-C6Y-ieo6.js";import"./VisuallyHidden-BvBKxd15.js";import"./index-jOkOEACf.js";import"./x-BsqigTHH.js";import"./AppLogoIcon.vue_vue_type_script_setup_true_lang-7FaFcvsr.js";import"./index-aLC3lZAJ.js";var nt=`
    .p-chip {
        display: inline-flex;
        align-items: center;
        background: dt('chip.background');
        color: dt('chip.color');
        border-radius: dt('chip.border.radius');
        padding-block: dt('chip.padding.y');
        padding-inline: dt('chip.padding.x');
        gap: dt('chip.gap');
    }

    .p-chip-icon {
        color: dt('chip.icon.color');
        font-size: dt('chip.icon.size');
        width: dt('chip.icon.size');
        height: dt('chip.icon.size');
    }

    .p-chip-image {
        border-radius: 50%;
        width: dt('chip.image.width');
        height: dt('chip.image.height');
        margin-inline-start: calc(-1 * dt('chip.padding.y'));
    }

    .p-chip:has(.p-chip-remove-icon) {
        padding-inline-end: dt('chip.padding.y');
    }

    .p-chip:has(.p-chip-image) {
        padding-block-start: calc(dt('chip.padding.y') / 2);
        padding-block-end: calc(dt('chip.padding.y') / 2);
    }

    .p-chip-remove-icon {
        cursor: pointer;
        font-size: dt('chip.remove.icon.size');
        width: dt('chip.remove.icon.size');
        height: dt('chip.remove.icon.size');
        color: dt('chip.remove.icon.color');
        border-radius: 50%;
        transition:
            outline-color dt('chip.transition.duration'),
            box-shadow dt('chip.transition.duration');
        outline-color: transparent;
    }

    .p-chip-remove-icon:focus-visible {
        box-shadow: dt('chip.remove.icon.focus.ring.shadow');
        outline: dt('chip.remove.icon.focus.ring.width') dt('chip.remove.icon.focus.ring.style') dt('chip.remove.icon.focus.ring.color');
        outline-offset: dt('chip.remove.icon.focus.ring.offset');
    }
`,it={root:"p-chip p-component",image:"p-chip-image",icon:"p-chip-icon",label:"p-chip-label",removeIcon:"p-chip-remove-icon"},ot=be.extend({name:"chip",style:nt,classes:it}),lt={name:"BaseChip",extends:je,props:{label:{type:[String,Number],default:null},icon:{type:String,default:null},image:{type:String,default:null},removable:{type:Boolean,default:!1},removeIcon:{type:String,default:void 0}},style:ot,provide:function(){return{$pcChip:this,$parentInstance:this}}},ye={name:"Chip",extends:lt,inheritAttrs:!1,emits:["remove"],data:function(){return{visible:!0}},methods:{onKeydown:function(e){(e.key==="Enter"||e.key==="Backspace")&&this.close(e)},close:function(e){this.visible=!1,this.$emit("remove",e)}},computed:{dataP:function(){return Z({removable:this.removable})}},components:{TimesCircleIcon:Re}},st=["aria-label","data-p"],rt=["src"];function at(t,e,n,i,l,o){return l.visible?(p(),f("div",h({key:0,class:t.cx("root"),"aria-label":t.label},t.ptmi("root"),{"data-p":o.dataP}),[S(t.$slots,"default",{},function(){return[t.image?(p(),f("img",h({key:0,src:t.image},t.ptm("image"),{class:t.cx("image")}),null,16,rt)):t.$slots.icon?(p(),U(re(t.$slots.icon),h({key:1,class:t.cx("icon")},t.ptm("icon")),null,16,["class"])):t.icon?(p(),f("span",h({key:2,class:[t.cx("icon"),t.icon]},t.ptm("icon")),null,16)):m("",!0),t.label!==null?(p(),f("div",h({key:3,class:t.cx("label")},t.ptm("label")),b(t.label),17)):m("",!0)]}),t.removable?S(t.$slots,"removeicon",{key:0,removeCallback:o.close,keydownCallback:o.onKeydown},function(){return[(p(),U(re(t.removeIcon?"span":"TimesCircleIcon"),h({class:[t.cx("removeIcon"),t.removeIcon],onClick:o.close,onKeydown:o.onKeydown},t.ptm("removeIcon")),null,16,["class","onClick","onKeydown"]))]}):m("",!0)],16,st)):m("",!0)}ye.render=at;var dt=`
    .p-autocomplete {
        display: inline-flex;
    }

    .p-autocomplete-loader {
        position: absolute;
        top: 50%;
        margin-top: -0.5rem;
        inset-inline-end: dt('autocomplete.padding.x');
    }

    .p-autocomplete:has(.p-autocomplete-dropdown) .p-autocomplete-loader {
        inset-inline-end: calc(dt('autocomplete.dropdown.width') + dt('autocomplete.padding.x'));
    }

    .p-autocomplete:has(.p-autocomplete-dropdown) .p-autocomplete-input {
        flex: 1 1 auto;
        width: 1%;
    }

    .p-autocomplete:has(.p-autocomplete-dropdown) .p-autocomplete-input,
    .p-autocomplete:has(.p-autocomplete-dropdown) .p-autocomplete-input-multiple {
        border-start-end-radius: 0;
        border-end-end-radius: 0;
    }

    .p-autocomplete-dropdown {
        cursor: pointer;
        display: inline-flex;
        user-select: none;
        align-items: center;
        justify-content: center;
        overflow: hidden;
        position: relative;
        width: dt('autocomplete.dropdown.width');
        border-start-end-radius: dt('autocomplete.dropdown.border.radius');
        border-end-end-radius: dt('autocomplete.dropdown.border.radius');
        background: dt('autocomplete.dropdown.background');
        border: 1px solid dt('autocomplete.dropdown.border.color');
        border-inline-start: 0 none;
        color: dt('autocomplete.dropdown.color');
        transition:
            background dt('autocomplete.transition.duration'),
            color dt('autocomplete.transition.duration'),
            border-color dt('autocomplete.transition.duration'),
            outline-color dt('autocomplete.transition.duration'),
            box-shadow dt('autocomplete.transition.duration');
        outline-color: transparent;
    }

    .p-autocomplete-dropdown:not(:disabled):hover {
        background: dt('autocomplete.dropdown.hover.background');
        border-color: dt('autocomplete.dropdown.hover.border.color');
        color: dt('autocomplete.dropdown.hover.color');
    }

    .p-autocomplete-dropdown:not(:disabled):active {
        background: dt('autocomplete.dropdown.active.background');
        border-color: dt('autocomplete.dropdown.active.border.color');
        color: dt('autocomplete.dropdown.active.color');
    }

    .p-autocomplete-dropdown:focus-visible {
        box-shadow: dt('autocomplete.dropdown.focus.ring.shadow');
        outline: dt('autocomplete.dropdown.focus.ring.width') dt('autocomplete.dropdown.focus.ring.style') dt('autocomplete.dropdown.focus.ring.color');
        outline-offset: dt('autocomplete.dropdown.focus.ring.offset');
    }

    .p-autocomplete-overlay {
        position: absolute;
        top: 0;
        left: 0;
        background: dt('autocomplete.overlay.background');
        color: dt('autocomplete.overlay.color');
        border: 1px solid dt('autocomplete.overlay.border.color');
        border-radius: dt('autocomplete.overlay.border.radius');
        box-shadow: dt('autocomplete.overlay.shadow');
        min-width: 100%;
    }

    .p-autocomplete-list-container {
        overflow: auto;
    }

    .p-autocomplete-list {
        margin: 0;
        list-style-type: none;
        display: flex;
        flex-direction: column;
        gap: dt('autocomplete.list.gap');
        padding: dt('autocomplete.list.padding');
    }

    .p-autocomplete-option {
        cursor: pointer;
        white-space: nowrap;
        position: relative;
        overflow: hidden;
        display: flex;
        align-items: center;
        padding: dt('autocomplete.option.padding');
        border: 0 none;
        color: dt('autocomplete.option.color');
        background: transparent;
        transition:
            background dt('autocomplete.transition.duration'),
            color dt('autocomplete.transition.duration'),
            border-color dt('autocomplete.transition.duration');
        border-radius: dt('autocomplete.option.border.radius');
    }

    .p-autocomplete-option:not(.p-autocomplete-option-selected):not(.p-disabled).p-focus {
        background: dt('autocomplete.option.focus.background');
        color: dt('autocomplete.option.focus.color');
    }

    .p-autocomplete-option:not(.p-autocomplete-option-selected):not(.p-disabled):hover {
        background: dt('autocomplete.option.focus.background');
        color: dt('autocomplete.option.focus.color');
    }

    .p-autocomplete-option-selected {
        background: dt('autocomplete.option.selected.background');
        color: dt('autocomplete.option.selected.color');
    }

    .p-autocomplete-option-selected.p-focus {
        background: dt('autocomplete.option.selected.focus.background');
        color: dt('autocomplete.option.selected.focus.color');
    }

    .p-autocomplete-option-group {
        margin: 0;
        padding: dt('autocomplete.option.group.padding');
        color: dt('autocomplete.option.group.color');
        background: dt('autocomplete.option.group.background');
        font-weight: dt('autocomplete.option.group.font.weight');
    }

    .p-autocomplete-input-multiple {
        margin: 0;
        list-style-type: none;
        cursor: text;
        overflow: hidden;
        display: flex;
        align-items: center;
        flex-wrap: wrap;
        padding: calc(dt('autocomplete.padding.y') / 2) dt('autocomplete.padding.x');
        gap: calc(dt('autocomplete.padding.y') / 2);
        color: dt('autocomplete.color');
        background: dt('autocomplete.background');
        border: 1px solid dt('autocomplete.border.color');
        border-radius: dt('autocomplete.border.radius');
        width: 100%;
        transition:
            background dt('autocomplete.transition.duration'),
            color dt('autocomplete.transition.duration'),
            border-color dt('autocomplete.transition.duration'),
            outline-color dt('autocomplete.transition.duration'),
            box-shadow dt('autocomplete.transition.duration');
        outline-color: transparent;
        box-shadow: dt('autocomplete.shadow');
    }

    .p-autocomplete-input-multiple.p-disabled {
        opacity: 1;
        background: dt('autocomplete.disabled.background');
        color: dt('autocomplete.disabled.color');
    }

    .p-autocomplete-input-multiple:not(.p-disabled):hover {
        border-color: dt('autocomplete.hover.border.color');
    }

    .p-autocomplete.p-focus .p-autocomplete-input-multiple:not(.p-disabled) {
        border-color: dt('autocomplete.focus.border.color');
        box-shadow: dt('autocomplete.focus.ring.shadow');
        outline: dt('autocomplete.focus.ring.width') dt('autocomplete.focus.ring.style') dt('autocomplete.focus.ring.color');
        outline-offset: dt('autocomplete.focus.ring.offset');
    }

    .p-autocomplete.p-invalid .p-autocomplete-input-multiple {
        border-color: dt('autocomplete.invalid.border.color');
    }

    .p-variant-filled.p-autocomplete-input-multiple {
        background: dt('autocomplete.filled.background');
    }

    .p-autocomplete-input-multiple.p-variant-filled:not(.p-disabled):hover {
        background: dt('autocomplete.filled.hover.background');
    }

    .p-autocomplete.p-focus .p-autocomplete-input-multiple.p-variant-filled:not(.p-disabled) {
        background: dt('autocomplete.filled.focus.background');
    }

    .p-autocomplete-chip.p-chip {
        padding-block-start: calc(dt('autocomplete.padding.y') / 2);
        padding-block-end: calc(dt('autocomplete.padding.y') / 2);
        border-radius: dt('autocomplete.chip.border.radius');
    }

    .p-autocomplete-input-multiple:has(.p-autocomplete-chip) {
        padding-inline-start: calc(dt('autocomplete.padding.y') / 2);
        padding-inline-end: calc(dt('autocomplete.padding.y') / 2);
    }

    .p-autocomplete-chip-item.p-focus .p-autocomplete-chip {
        background: dt('autocomplete.chip.focus.background');
        color: dt('autocomplete.chip.focus.color');
    }

    .p-autocomplete-input-chip {
        flex: 1 1 auto;
        display: inline-flex;
        padding-block-start: calc(dt('autocomplete.padding.y') / 2);
        padding-block-end: calc(dt('autocomplete.padding.y') / 2);
    }

    .p-autocomplete-input-chip input {
        border: 0 none;
        outline: 0 none;
        background: transparent;
        margin: 0;
        padding: 0;
        box-shadow: none;
        border-radius: 0;
        width: 100%;
        font-family: inherit;
        font-feature-settings: inherit;
        font-size: 1rem;
        color: inherit;
    }

    .p-autocomplete-input-chip input::placeholder {
        color: dt('autocomplete.placeholder.color');
    }

    .p-autocomplete.p-invalid .p-autocomplete-input-chip input::placeholder {
        color: dt('autocomplete.invalid.placeholder.color');
    }

    .p-autocomplete-empty-message {
        padding: dt('autocomplete.empty.message.padding');
    }

    .p-autocomplete-fluid {
        display: flex;
    }

    .p-autocomplete-fluid:has(.p-autocomplete-dropdown) .p-autocomplete-input {
        width: 1%;
    }

    .p-autocomplete:has(.p-inputtext-sm) .p-autocomplete-dropdown {
        width: dt('autocomplete.dropdown.sm.width');
    }

    .p-autocomplete:has(.p-inputtext-sm) .p-autocomplete-dropdown .p-icon {
        font-size: dt('form.field.sm.font.size');
        width: dt('form.field.sm.font.size');
        height: dt('form.field.sm.font.size');
    }

    .p-autocomplete:has(.p-inputtext-lg) .p-autocomplete-dropdown {
        width: dt('autocomplete.dropdown.lg.width');
    }

    .p-autocomplete:has(.p-inputtext-lg) .p-autocomplete-dropdown .p-icon {
        font-size: dt('form.field.lg.font.size');
        width: dt('form.field.lg.font.size');
        height: dt('form.field.lg.font.size');
    }

    .p-autocomplete-clear-icon {
        position: absolute;
        top: 50%;
        margin-top: -0.5rem;
        cursor: pointer;
        color: dt('form.field.icon.color');
        inset-inline-end: dt('autocomplete.padding.x');
    }

    .p-autocomplete:has(.p-autocomplete-dropdown) .p-autocomplete-clear-icon {
        inset-inline-end: calc(dt('autocomplete.padding.x') + dt('autocomplete.dropdown.width'));
    }

    .p-autocomplete:has(.p-autocomplete-clear-icon) .p-autocomplete-input {
        padding-inline-end: calc((dt('form.field.padding.x') * 2) + dt('icon.size'));
    }

    .p-inputgroup .p-autocomplete-dropdown {
        border-radius: 0;
    }

    .p-inputgroup > .p-autocomplete:last-child:has(.p-autocomplete-dropdown) > .p-autocomplete-input {
        border-start-end-radius: 0;
        border-end-end-radius: 0;
    }

    .p-inputgroup > .p-autocomplete:last-child .p-autocomplete-dropdown {
        border-start-end-radius: dt('autocomplete.dropdown.border.radius');
        border-end-end-radius: dt('autocomplete.dropdown.border.radius');
    }
`,ut={root:{position:"relative"}},pt={root:function(e){var n=e.instance;return["p-autocomplete p-component p-inputwrapper",{"p-invalid":n.$invalid,"p-focus":n.focused,"p-inputwrapper-filled":n.$filled||X(n.inputValue),"p-inputwrapper-focus":n.focused,"p-autocomplete-open":n.overlayVisible,"p-autocomplete-fluid":n.$fluid,"p-autocomplete-clearable":n.isClearIconVisible}]},pcInputText:"p-autocomplete-input",inputMultiple:function(e){var n=e.instance,i=e.props;return["p-autocomplete-input-multiple",{"p-variant-filled":n.$variant==="filled","p-disabled":i.disabled}]},clearIcon:"p-autocomplete-clear-icon",chipItem:function(e){var n=e.instance,i=e.i;return["p-autocomplete-chip-item",{"p-focus":n.focusedMultipleOptionIndex===i}]},pcChip:"p-autocomplete-chip",chipIcon:"p-autocomplete-chip-icon",inputChip:"p-autocomplete-input-chip",loader:"p-autocomplete-loader",dropdown:"p-autocomplete-dropdown",overlay:"p-autocomplete-overlay p-component",listContainer:"p-autocomplete-list-container",list:"p-autocomplete-list",optionGroup:"p-autocomplete-option-group",option:function(e){var n=e.instance,i=e.option,l=e.i,o=e.getItemOptions;return["p-autocomplete-option",{"p-autocomplete-option-selected":n.isSelected(i),"p-focus":n.focusedOptionIndex===n.getOptionIndex(l,o),"p-disabled":n.isOptionDisabled(i)}]},emptyMessage:"p-autocomplete-empty-message"},ct=be.extend({name:"autocomplete",style:dt,classes:pt,inlineStyles:ut}),ft={name:"BaseAutoComplete",extends:We,props:{suggestions:{type:Array,default:null},optionLabel:null,optionDisabled:null,optionGroupLabel:null,optionGroupChildren:null,scrollHeight:{type:String,default:"14rem"},dropdown:{type:Boolean,default:!1},dropdownMode:{type:String,default:"blank"},multiple:{type:Boolean,default:!1},loading:{type:Boolean,default:!1},placeholder:{type:String,default:null},dataKey:{type:String,default:null},minLength:{type:Number,default:1},delay:{type:Number,default:300},appendTo:{type:[String,Object],default:"body"},forceSelection:{type:Boolean,default:!1},completeOnFocus:{type:Boolean,default:!1},showClear:{type:Boolean,default:!1},inputId:{type:String,default:null},inputStyle:{type:Object,default:null},inputClass:{type:[String,Object],default:null},panelStyle:{type:Object,default:null},panelClass:{type:[String,Object],default:null},overlayStyle:{type:Object,default:null},overlayClass:{type:[String,Object],default:null},dropdownIcon:{type:String,default:null},dropdownClass:{type:[String,Object],default:null},loader:{type:String,default:null},loadingIcon:{type:String,default:null},removeTokenIcon:{type:String,default:null},chipIcon:{type:String,default:null},virtualScrollerOptions:{type:Object,default:null},autoOptionFocus:{type:Boolean,default:!1},selectOnFocus:{type:Boolean,default:!1},focusOnHover:{type:Boolean,default:!0},searchLocale:{type:String,default:void 0},searchMessage:{type:String,default:null},selectionMessage:{type:String,default:null},emptySelectionMessage:{type:String,default:null},emptySearchMessage:{type:String,default:null},showEmptyMessage:{type:Boolean,default:!0},tabindex:{type:Number,default:0},typeahead:{type:Boolean,default:!0},ariaLabel:{type:String,default:null},ariaLabelledby:{type:String,default:null}},style:ct,provide:function(){return{$pcAutoComplete:this,$parentInstance:this}}};function me(t,e,n){return(e=ht(e))in t?Object.defineProperty(t,e,{value:n,enumerable:!0,configurable:!0,writable:!0}):t[e]=n,t}function ht(t){var e=mt(t,"string");return j(e)=="symbol"?e:e+""}function mt(t,e){if(j(t)!="object"||!t)return t;var n=t[Symbol.toPrimitive];if(n!==void 0){var i=n.call(t,e);if(j(i)!="object")return i;throw new TypeError("@@toPrimitive must return a primitive value.")}return(e==="string"?String:Number)(t)}function j(t){"@babel/helpers - typeof";return j=typeof Symbol=="function"&&typeof Symbol.iterator=="symbol"?function(e){return typeof e}:function(e){return e&&typeof Symbol=="function"&&e.constructor===Symbol&&e!==Symbol.prototype?"symbol":typeof e},j(t)}function Y(t){return yt(t)||bt(t)||vt(t)||gt()}function gt(){throw new TypeError(`Invalid attempt to spread non-iterable instance.
In order to be iterable, non-array objects must have a [Symbol.iterator]() method.`)}function vt(t,e){if(t){if(typeof t=="string")return ae(t,e);var n={}.toString.call(t).slice(8,-1);return n==="Object"&&t.constructor&&(n=t.constructor.name),n==="Map"||n==="Set"?Array.from(t):n==="Arguments"||/^(?:Ui|I)nt(?:8|16|32)(?:Clamped)?Array$/.test(n)?ae(t,e):void 0}}function bt(t){if(typeof Symbol<"u"&&t[Symbol.iterator]!=null||t["@@iterator"]!=null)return Array.from(t)}function yt(t){if(Array.isArray(t))return ae(t)}function ae(t,e){(e==null||e>t.length)&&(e=t.length);for(var n=0,i=Array(e);n<e;n++)i[n]=t[n];return i}var de={name:"AutoComplete",extends:ft,inheritAttrs:!1,emits:["change","focus","blur","item-select","item-unselect","option-select","option-unselect","dropdown-click","clear","complete","before-show","before-hide","show","hide"],inject:{$pcFluid:{default:null}},outsideClickListener:null,resizeListener:null,scrollHandler:null,overlay:null,virtualScroller:null,searchTimeout:null,dirty:!1,startRangeIndex:-1,data:function(){return{clicked:!1,focused:!1,focusedOptionIndex:-1,focusedMultipleOptionIndex:-1,overlayVisible:!1,searching:!1}},watch:{suggestions:function(){this.searching&&(this.show(),this.focusedOptionIndex=this.overlayVisible&&this.autoOptionFocus?this.findFirstFocusedOptionIndex():-1,this.searching=!1,!this.showEmptyMessage&&this.visibleOptions.length===0&&this.hide()),this.autoUpdateModel()}},mounted:function(){this.autoUpdateModel()},updated:function(){this.overlayVisible&&this.alignOverlay()},beforeUnmount:function(){this.unbindOutsideClickListener(),this.unbindResizeListener(),this.scrollHandler&&(this.scrollHandler.destroy(),this.scrollHandler=null),this.overlay&&(le.clear(this.overlay),this.overlay=null)},methods:{getOptionIndex:function(e,n){return this.virtualScrollerDisabled?e:n&&n(e).index},getOptionLabel:function(e){return this.optionLabel?G(e,this.optionLabel):e},getOptionValue:function(e){return e},getOptionRenderKey:function(e,n){return(this.dataKey?G(e,this.dataKey):this.getOptionLabel(e))+"_"+n},getPTOptions:function(e,n,i,l){return this.ptm(l,{context:{option:e,index:i,selected:this.isSelected(e),focused:this.focusedOptionIndex===this.getOptionIndex(i,n),disabled:this.isOptionDisabled(e)}})},isOptionDisabled:function(e){return this.optionDisabled?G(e,this.optionDisabled):!1},isOptionGroup:function(e){return this.optionGroupLabel&&e.optionGroup&&e.group},getOptionGroupLabel:function(e){return G(e,this.optionGroupLabel)},getOptionGroupChildren:function(e){return G(e,this.optionGroupChildren)},getAriaPosInset:function(e){var n=this;return(this.optionGroupLabel?e-this.visibleOptions.slice(0,e).filter(function(i){return n.isOptionGroup(i)}).length:e)+1},show:function(e){this.$emit("before-show"),this.dirty=!0,this.overlayVisible=!0,this.focusedOptionIndex=this.focusedOptionIndex!==-1?this.focusedOptionIndex:this.autoOptionFocus?this.findFirstFocusedOptionIndex():-1,e&&_(this.multiple?this.$refs.focusInput:this.$refs.focusInput.$el)},hide:function(e){var n=this,i=function(){var o;n.$emit("before-hide"),n.dirty=e,n.overlayVisible=!1,n.clicked=!1,n.focusedOptionIndex=-1,e&&_(n.multiple?n.$refs.focusInput:(o=n.$refs.focusInput)===null||o===void 0?void 0:o.$el)};setTimeout(function(){i()},0)},onFocus:function(e){this.disabled||(!this.dirty&&this.completeOnFocus&&this.search(e,e.target.value,"focus"),this.dirty=!0,this.focused=!0,this.overlayVisible&&(this.focusedOptionIndex=this.focusedOptionIndex!==-1?this.focusedOptionIndex:this.overlayVisible&&this.autoOptionFocus?this.findFirstFocusedOptionIndex():-1,this.scrollInView(this.focusedOptionIndex)),this.$emit("focus",e))},onBlur:function(e){var n,i;this.dirty=!1,this.focused=!1,this.focusedOptionIndex=-1,this.$emit("blur",e),(n=(i=this.formField).onBlur)===null||n===void 0||n.call(i)},onKeyDown:function(e){if(this.disabled){e.preventDefault();return}switch(e.code){case"ArrowDown":this.onArrowDownKey(e);break;case"ArrowUp":this.onArrowUpKey(e);break;case"ArrowLeft":this.onArrowLeftKey(e);break;case"ArrowRight":this.onArrowRightKey(e);break;case"Home":this.onHomeKey(e);break;case"End":this.onEndKey(e);break;case"PageDown":this.onPageDownKey(e);break;case"PageUp":this.onPageUpKey(e);break;case"Enter":case"NumpadEnter":this.onEnterKey(e);break;case"Space":this.onSpaceKey(e);break;case"Escape":this.onEscapeKey(e);break;case"Tab":this.onTabKey(e);break;case"ShiftLeft":case"ShiftRight":this.onShiftKey(e);break;case"Backspace":this.onBackspaceKey(e);break}this.clicked=!1},onInput:function(e){var n=this;if(this.typeahead){this.searchTimeout&&clearTimeout(this.searchTimeout);var i=e.target.value;this.multiple||this.updateModel(e,i),i.length===0?(this.searching=!1,this.hide(),this.$emit("clear")):i.length>=this.minLength?(this.focusedOptionIndex=-1,this.searchTimeout=setTimeout(function(){n.search(e,i,"input")},this.delay)):(this.searching=!1,this.hide())}},onChange:function(e){var n=this;if(this.forceSelection){var i=!1;if(this.visibleOptions&&!this.multiple){var l,o=this.multiple?this.$refs.focusInput.value:(l=this.$refs.focusInput)===null||l===void 0||(l=l.$el)===null||l===void 0?void 0:l.value,x=this.visibleOptions.find(function(N){return n.isOptionMatched(N,o||"")});x!==void 0&&(i=!0,!this.isSelected(x)&&this.onOptionSelect(e,x))}if(!i){if(this.multiple)this.$refs.focusInput.value="";else{var C,M=(C=this.$refs.focusInput)===null||C===void 0?void 0:C.$el;M&&(M.value="")}this.$emit("clear"),!this.multiple&&this.updateModel(e,null)}}},onMultipleContainerFocus:function(){this.disabled||(this.focused=!0)},onMultipleContainerBlur:function(){this.focusedMultipleOptionIndex=-1,this.focused=!1},onMultipleContainerKeyDown:function(e){if(this.disabled){e.preventDefault();return}switch(e.code){case"ArrowLeft":this.onArrowLeftKeyOnMultiple(e);break;case"ArrowRight":this.onArrowRightKeyOnMultiple(e);break;case"Backspace":this.onBackspaceKeyOnMultiple(e);break}},onContainerClick:function(e){this.clicked=!0,!(this.disabled||this.searching||this.loading||this.isDropdownClicked(e))&&(!this.overlay||!this.overlay.contains(e.target))&&_(this.multiple?this.$refs.focusInput:this.$refs.focusInput.$el)},onDropdownClick:function(e){var n=void 0;if(this.overlayVisible)this.hide(!0);else{var i=this.multiple?this.$refs.focusInput:this.$refs.focusInput.$el;_(i),n=i.value,this.dropdownMode==="blank"?this.search(e,"","dropdown"):this.dropdownMode==="current"&&this.search(e,n,"dropdown")}this.$emit("dropdown-click",{originalEvent:e,query:n})},onOptionSelect:function(e,n){var i=arguments.length>2&&arguments[2]!==void 0?arguments[2]:!0,l=this.getOptionValue(n);this.multiple?(this.$refs.focusInput.value="",this.isSelected(n)||this.updateModel(e,[].concat(Y(this.d_value||[]),[l]))):this.updateModel(e,l),this.$emit("item-select",{originalEvent:e,value:n}),this.$emit("option-select",{originalEvent:e,value:n}),i&&this.hide(!0)},onOptionMouseMove:function(e,n){this.focusOnHover&&this.changeFocusedOptionIndex(e,n)},onOptionSelectRange:function(e){var n=this,i=arguments.length>1&&arguments[1]!==void 0?arguments[1]:-1,l=arguments.length>2&&arguments[2]!==void 0?arguments[2]:-1;if(i===-1&&(i=this.findNearestSelectedOptionIndex(l,!0)),l===-1&&(l=this.findNearestSelectedOptionIndex(i)),i!==-1&&l!==-1){var o=Math.min(i,l),x=Math.max(i,l),C=this.visibleOptions.slice(o,x+1).filter(function(M){return n.isValidOption(M)}).filter(function(M){return!n.isSelected(M)}).map(function(M){return n.getOptionValue(M)});this.updateModel(e,[].concat(Y(this.d_value||[]),Y(C)))}},onClearClick:function(e){this.updateModel(e,null)},onOverlayClick:function(e){Ye.emit("overlay-click",{originalEvent:e,target:this.$el})},onOverlayKeyDown:function(e){switch(e.code){case"Escape":this.onEscapeKey(e);break}},onArrowDownKey:function(e){if(this.overlayVisible){var n=this.focusedOptionIndex!==-1?this.findNextOptionIndex(this.focusedOptionIndex):this.clicked?this.findFirstOptionIndex():this.findFirstFocusedOptionIndex();this.multiple&&e.shiftKey&&this.onOptionSelectRange(e,this.startRangeIndex,n),this.changeFocusedOptionIndex(e,n),e.preventDefault()}},onArrowUpKey:function(e){if(this.overlayVisible)if(e.altKey)this.focusedOptionIndex!==-1&&this.onOptionSelect(e,this.visibleOptions[this.focusedOptionIndex]),this.overlayVisible&&this.hide(),e.preventDefault();else{var n=this.focusedOptionIndex!==-1?this.findPrevOptionIndex(this.focusedOptionIndex):this.clicked?this.findLastOptionIndex():this.findLastFocusedOptionIndex();this.multiple&&e.shiftKey&&this.onOptionSelectRange(e,n,this.startRangeIndex),this.changeFocusedOptionIndex(e,n),e.preventDefault()}},onArrowLeftKey:function(e){var n=e.currentTarget;this.focusedOptionIndex=-1,this.multiple&&(Me(n.value)&&this.$filled?(_(this.$refs.multiContainer),this.focusedMultipleOptionIndex=this.d_value.length):e.stopPropagation())},onArrowRightKey:function(e){this.focusedOptionIndex=-1,this.multiple&&e.stopPropagation()},onHomeKey:function(e){var n=e.currentTarget,i=n.value.length,l=e.metaKey||e.ctrlKey,o=this.findFirstOptionIndex();this.multiple&&e.shiftKey&&l&&this.onOptionSelectRange(e,o,this.startRangeIndex),n.setSelectionRange(0,e.shiftKey?i:0),this.focusedOptionIndex=-1,e.preventDefault()},onEndKey:function(e){var n=e.currentTarget,i=n.value.length,l=e.metaKey||e.ctrlKey,o=this.findLastOptionIndex();this.multiple&&e.shiftKey&&l&&this.onOptionSelectRange(e,this.startRangeIndex,o),n.setSelectionRange(e.shiftKey?0:i,i),this.focusedOptionIndex=-1,e.preventDefault()},onPageUpKey:function(e){this.scrollInView(0),e.preventDefault()},onPageDownKey:function(e){this.scrollInView(this.visibleOptions.length-1),e.preventDefault()},onEnterKey:function(e){this.typeahead?this.overlayVisible?(this.focusedOptionIndex!==-1&&(this.multiple&&e.shiftKey?(this.onOptionSelectRange(e,this.focusedOptionIndex),e.preventDefault()):this.onOptionSelect(e,this.visibleOptions[this.focusedOptionIndex])),this.hide()):(this.focusedOptionIndex=-1,this.onArrowDownKey(e)):this.multiple&&(e.target.value.trim()&&(this.updateModel(e,[].concat(Y(this.d_value||[]),[e.target.value.trim()])),this.$refs.focusInput.value=""),e.preventDefault())},onSpaceKey:function(e){!this.autoOptionFocus&&this.focusedOptionIndex!==-1&&this.onEnterKey(e)},onEscapeKey:function(e){this.overlayVisible&&this.hide(!0),e.preventDefault()},onTabKey:function(e){this.focusedOptionIndex!==-1&&this.onOptionSelect(e,this.visibleOptions[this.focusedOptionIndex]),this.overlayVisible&&this.hide()},onShiftKey:function(){this.startRangeIndex=this.focusedOptionIndex},onBackspaceKey:function(e){if(this.multiple){if(X(this.d_value)&&!this.$refs.focusInput.value){var n=this.d_value[this.d_value.length-1],i=this.d_value.slice(0,-1);this.writeValue(i,e),this.$emit("item-unselect",{originalEvent:e,value:n}),this.$emit("option-unselect",{originalEvent:e,value:n})}e.stopPropagation()}},onArrowLeftKeyOnMultiple:function(){this.focusedMultipleOptionIndex=this.focusedMultipleOptionIndex<1?0:this.focusedMultipleOptionIndex-1},onArrowRightKeyOnMultiple:function(){this.focusedMultipleOptionIndex++,this.focusedMultipleOptionIndex>this.d_value.length-1&&(this.focusedMultipleOptionIndex=-1,_(this.$refs.focusInput))},onBackspaceKeyOnMultiple:function(e){this.focusedMultipleOptionIndex!==-1&&this.removeOption(e,this.focusedMultipleOptionIndex)},onOverlayEnter:function(e){le.set("overlay",e,this.$primevue.config.zIndex.overlay),Le(e,{position:"absolute",top:"0"}),this.alignOverlay(),this.$attrSelector&&e.setAttribute(this.$attrSelector,"")},onOverlayAfterEnter:function(){this.bindOutsideClickListener(),this.bindScrollListener(),this.bindResizeListener(),this.$emit("show")},onOverlayLeave:function(){this.unbindOutsideClickListener(),this.unbindScrollListener(),this.unbindResizeListener(),this.$emit("hide"),this.overlay=null},onOverlayAfterLeave:function(e){le.clear(e)},alignOverlay:function(){var e=this.multiple?this.$refs.multiContainer:this.$refs.focusInput.$el;this.appendTo==="self"?ke(this.overlay,e):(this.overlay.style.minWidth=Se(e)+"px",Ce(this.overlay,e))},bindOutsideClickListener:function(){var e=this;this.outsideClickListener||(this.outsideClickListener=function(n){e.overlayVisible&&e.overlay&&e.isOutsideClicked(n)&&e.hide()},document.addEventListener("click",this.outsideClickListener,!0))},unbindOutsideClickListener:function(){this.outsideClickListener&&(document.removeEventListener("click",this.outsideClickListener,!0),this.outsideClickListener=null)},bindScrollListener:function(){var e=this;this.scrollHandler||(this.scrollHandler=new Je(this.$refs.container,function(){e.overlayVisible&&e.hide()})),this.scrollHandler.bindScrollListener()},unbindScrollListener:function(){this.scrollHandler&&this.scrollHandler.unbindScrollListener()},bindResizeListener:function(){var e=this;this.resizeListener||(this.resizeListener=function(){e.overlayVisible&&!xe()&&e.hide()},window.addEventListener("resize",this.resizeListener))},unbindResizeListener:function(){this.resizeListener&&(window.removeEventListener("resize",this.resizeListener),this.resizeListener=null)},isOutsideClicked:function(e){return!this.overlay.contains(e.target)&&!this.isInputClicked(e)&&!this.isDropdownClicked(e)},isInputClicked:function(e){return this.multiple?e.target===this.$refs.multiContainer||this.$refs.multiContainer.contains(e.target):e.target===this.$refs.focusInput.$el},isDropdownClicked:function(e){return this.$refs.dropdownButton?e.target===this.$refs.dropdownButton||this.$refs.dropdownButton.contains(e.target):!1},isOptionMatched:function(e,n){var i;return this.isValidOption(e)&&((i=this.getOptionLabel(e))===null||i===void 0?void 0:i.toLocaleLowerCase(this.searchLocale))===n.toLocaleLowerCase(this.searchLocale)},isValidOption:function(e){return X(e)&&!(this.isOptionDisabled(e)||this.isOptionGroup(e))},isValidSelectedOption:function(e){return this.isValidOption(e)&&this.isSelected(e)},isEquals:function(e,n){return Ie(e,n,this.equalityKey)},isSelected:function(e){var n=this,i=this.getOptionValue(e);return this.multiple?(this.d_value||[]).some(function(l){return n.isEquals(l,i)}):this.isEquals(this.d_value,this.getOptionValue(e))},findFirstOptionIndex:function(){var e=this;return this.visibleOptions.findIndex(function(n){return e.isValidOption(n)})},findLastOptionIndex:function(){var e=this;return ie(this.visibleOptions,function(n){return e.isValidOption(n)})},findNextOptionIndex:function(e){var n=this,i=e<this.visibleOptions.length-1?this.visibleOptions.slice(e+1).findIndex(function(l){return n.isValidOption(l)}):-1;return i>-1?i+e+1:e},findPrevOptionIndex:function(e){var n=this,i=e>0?ie(this.visibleOptions.slice(0,e),function(l){return n.isValidOption(l)}):-1;return i>-1?i:e},findSelectedOptionIndex:function(){var e=this;return this.$filled?this.visibleOptions.findIndex(function(n){return e.isValidSelectedOption(n)}):-1},findFirstFocusedOptionIndex:function(){var e=this.findSelectedOptionIndex();return e<0?this.findFirstOptionIndex():e},findLastFocusedOptionIndex:function(){var e=this.findSelectedOptionIndex();return e<0?this.findLastOptionIndex():e},search:function(e,n,i){n!=null&&(i==="input"&&n.trim().length===0||(this.searching=!0,this.$emit("complete",{originalEvent:e,query:n})))},removeOption:function(e,n){var i=this,l=this.d_value[n],o=this.d_value.filter(function(x,C){return C!==n}).map(function(x){return i.getOptionValue(x)});this.updateModel(e,o),this.$emit("item-unselect",{originalEvent:e,value:l}),this.$emit("option-unselect",{originalEvent:e,value:l}),this.dirty=!0,_(this.multiple?this.$refs.focusInput:this.$refs.focusInput.$el)},changeFocusedOptionIndex:function(e,n){this.focusedOptionIndex!==n&&(this.focusedOptionIndex=n,this.scrollInView(),this.selectOnFocus&&this.onOptionSelect(e,this.visibleOptions[n],!1))},scrollInView:function(){var e=this,n=arguments.length>0&&arguments[0]!==void 0?arguments[0]:-1;this.$nextTick(function(){var i=n!==-1?"".concat(e.$id,"_").concat(n):e.focusedOptionId,l=we(e.list,'li[id="'.concat(i,'"]'));l?l.scrollIntoView&&l.scrollIntoView({block:"nearest",inline:"start"}):e.virtualScrollerDisabled||e.virtualScroller&&e.virtualScroller.scrollToIndex(n!==-1?n:e.focusedOptionIndex)})},autoUpdateModel:function(){this.selectOnFocus&&this.autoOptionFocus&&!this.$filled&&(this.focusedOptionIndex=this.findFirstFocusedOptionIndex(),this.onOptionSelect(null,this.visibleOptions[this.focusedOptionIndex],!1))},updateModel:function(e,n){this.writeValue(n,e),this.$emit("change",{originalEvent:e,value:n})},flatOptions:function(e){var n=this;return(e||[]).reduce(function(i,l,o){i.push({optionGroup:l,group:!0,index:o});var x=n.getOptionGroupChildren(l);return x&&x.forEach(function(C){return i.push(C)}),i},[])},overlayRef:function(e){this.overlay=e},listRef:function(e,n){this.list=e,n&&n(e)},virtualScrollerRef:function(e){this.virtualScroller=e},findNextSelectedOptionIndex:function(e){var n=this,i=this.$filled&&e<this.visibleOptions.length-1?this.visibleOptions.slice(e+1).findIndex(function(l){return n.isValidSelectedOption(l)}):-1;return i>-1?i+e+1:-1},findPrevSelectedOptionIndex:function(e){var n=this,i=this.$filled&&e>0?ie(this.visibleOptions.slice(0,e),function(l){return n.isValidSelectedOption(l)}):-1;return i>-1?i:-1},findNearestSelectedOptionIndex:function(e){var n=arguments.length>1&&arguments[1]!==void 0?arguments[1]:!1,i=-1;return this.$filled&&(n?(i=this.findPrevSelectedOptionIndex(e),i=i===-1?this.findNextSelectedOptionIndex(e):i):(i=this.findNextSelectedOptionIndex(e),i=i===-1?this.findPrevSelectedOptionIndex(e):i)),i>-1?i:e}},computed:{visibleOptions:function(){return this.optionGroupLabel?this.flatOptions(this.suggestions):this.suggestions||[]},inputValue:function(){if(this.$filled)if(j(this.d_value)==="object"){var e=this.getOptionLabel(this.d_value);return e??this.d_value}else return this.d_value;else return""},hasSelectedOption:function(){return this.$filled},equalityKey:function(){return this.dataKey},searchResultMessageText:function(){return X(this.visibleOptions)&&this.overlayVisible?this.searchMessageText.replaceAll("{0}",this.visibleOptions.length):this.emptySearchMessageText},searchMessageText:function(){return this.searchMessage||this.$primevue.config.locale.searchMessage||""},emptySearchMessageText:function(){return this.emptySearchMessage||this.$primevue.config.locale.emptySearchMessage||""},selectionMessageText:function(){return this.selectionMessage||this.$primevue.config.locale.selectionMessage||""},emptySelectionMessageText:function(){return this.emptySelectionMessage||this.$primevue.config.locale.emptySelectionMessage||""},selectedMessageText:function(){return this.$filled?this.selectionMessageText.replaceAll("{0}",this.multiple?this.d_value.length:"1"):this.emptySelectionMessageText},listAriaLabel:function(){return this.$primevue.config.locale.aria?this.$primevue.config.locale.aria.listLabel:void 0},focusedOptionId:function(){return this.focusedOptionIndex!==-1?"".concat(this.$id,"_").concat(this.focusedOptionIndex):null},focusedMultipleOptionId:function(){return this.focusedMultipleOptionIndex!==-1?"".concat(this.$id,"_multiple_option_").concat(this.focusedMultipleOptionIndex):null},isClearIconVisible:function(){return this.showClear&&this.$filled&&!this.disabled&&!this.loading},ariaSetSize:function(){var e=this;return this.visibleOptions.filter(function(n){return!e.isOptionGroup(n)}).length},virtualScrollerDisabled:function(){return!this.virtualScrollerOptions},panelId:function(){return this.$id+"_panel"},containerDataP:function(){return Z({fluid:this.$fluid})},overlayDataP:function(){return Z(me({},"portal-"+this.appendTo,"portal-"+this.appendTo))},inputMultipleDataP:function(){return Z(me({invalid:this.$invalid,disabled:this.disabled,focus:this.focused,fluid:this.$fluid,filled:this.$variant==="filled",empty:!this.$filled},this.size,this.size))}},components:{InputText:Qe,VirtualScroller:Xe,Portal:qe,Chip:ye,ChevronDownIcon:He,SpinnerIcon:Ue,TimesIcon:Ge},directives:{ripple:Ne}};function q(t){"@babel/helpers - typeof";return q=typeof Symbol=="function"&&typeof Symbol.iterator=="symbol"?function(e){return typeof e}:function(e){return e&&typeof Symbol=="function"&&e.constructor===Symbol&&e!==Symbol.prototype?"symbol":typeof e},q(t)}function ge(t,e){var n=Object.keys(t);if(Object.getOwnPropertySymbols){var i=Object.getOwnPropertySymbols(t);e&&(i=i.filter(function(l){return Object.getOwnPropertyDescriptor(t,l).enumerable})),n.push.apply(n,i)}return n}function ve(t){for(var e=1;e<arguments.length;e++){var n=arguments[e]!=null?arguments[e]:{};e%2?ge(Object(n),!0).forEach(function(i){Ot(t,i,n[i])}):Object.getOwnPropertyDescriptors?Object.defineProperties(t,Object.getOwnPropertyDescriptors(n)):ge(Object(n)).forEach(function(i){Object.defineProperty(t,i,Object.getOwnPropertyDescriptor(n,i))})}return t}function Ot(t,e,n){return(e=wt(e))in t?Object.defineProperty(t,e,{value:n,enumerable:!0,configurable:!0,writable:!0}):t[e]=n,t}function wt(t){var e=It(t,"string");return q(e)=="symbol"?e:e+""}function It(t,e){if(q(t)!="object"||!t)return t;var n=t[Symbol.toPrimitive];if(n!==void 0){var i=n.call(t,e);if(q(i)!="object")return i;throw new TypeError("@@toPrimitive must return a primitive value.")}return(e==="string"?String:Number)(t)}var xt=["data-p"],kt=["aria-activedescendant","data-p-has-dropdown","data-p"],St=["id","aria-label","aria-setsize","aria-posinset"],Ct=["id","placeholder","tabindex","disabled","aria-label","aria-labelledby","aria-expanded","aria-controls","aria-activedescendant","aria-invalid"],Lt=["data-p-has-dropdown"],Mt=["disabled","aria-expanded","aria-controls"],Kt=["id","data-p"],Vt=["id","aria-label"],Dt=["id"],Tt=["id","aria-label","aria-selected","aria-disabled","aria-setsize","aria-posinset","onClick","onMousemove","data-p-selected","data-p-focused","data-p-disabled"];function Pt(t,e,n,i,l,o){var x=R("InputText"),C=R("TimesIcon"),M=R("Chip"),N=R("SpinnerIcon"),P=R("VirtualScroller"),H=R("Portal"),te=Ke("ripple");return p(),f("div",h({ref:"container",class:t.cx("root"),style:t.sx("root"),onClick:e[11]||(e[11]=function(){return o.onContainerClick&&o.onContainerClick.apply(o,arguments)}),"data-p":o.containerDataP},t.ptmi("root")),[t.multiple?m("",!0):(p(),U(x,{key:0,ref:"focusInput",id:t.inputId,type:"text",name:t.$formName,class:V([t.cx("pcInputText"),t.inputClass]),style:Ve(t.inputStyle),defaultValue:o.inputValue,placeholder:t.placeholder,tabindex:t.disabled?-1:t.tabindex,fluid:t.$fluid,disabled:t.disabled,size:t.size,invalid:t.invalid,variant:t.variant,autocomplete:"off",role:"combobox","aria-label":t.ariaLabel,"aria-labelledby":t.ariaLabelledby,"aria-haspopup":"listbox","aria-autocomplete":"list","aria-expanded":l.overlayVisible,"aria-controls":l.overlayVisible?o.panelId:void 0,"aria-activedescendant":l.focused?o.focusedOptionId:void 0,onFocus:o.onFocus,onBlur:o.onBlur,onKeydown:o.onKeyDown,onInput:o.onInput,onChange:o.onChange,unstyled:t.unstyled,"data-p-has-dropdown":t.dropdown,pt:t.ptm("pcInputText")},null,8,["id","name","class","style","defaultValue","placeholder","tabindex","fluid","disabled","size","invalid","variant","aria-label","aria-labelledby","aria-expanded","aria-controls","aria-activedescendant","onFocus","onBlur","onKeydown","onInput","onChange","unstyled","data-p-has-dropdown","pt"])),o.isClearIconVisible?S(t.$slots,"clearicon",{key:1,class:V(t.cx("clearIcon")),clearCallback:o.onClearClick},function(){return[c(C,h({class:[t.cx("clearIcon")],onClick:o.onClearClick},t.ptm("clearIcon")),null,16,["class","onClick"])]}):m("",!0),t.multiple?(p(),f("ul",h({key:2,ref:"multiContainer",class:t.cx("inputMultiple"),tabindex:"-1",role:"listbox","aria-orientation":"horizontal","aria-activedescendant":l.focused?o.focusedMultipleOptionId:void 0,onFocus:e[5]||(e[5]=function(){return o.onMultipleContainerFocus&&o.onMultipleContainerFocus.apply(o,arguments)}),onBlur:e[6]||(e[6]=function(){return o.onMultipleContainerBlur&&o.onMultipleContainerBlur.apply(o,arguments)}),onKeydown:e[7]||(e[7]=function(){return o.onMultipleContainerKeyDown&&o.onMultipleContainerKeyDown.apply(o,arguments)}),"data-p-has-dropdown":t.dropdown,"data-p":o.inputMultipleDataP},t.ptm("inputMultiple")),[(p(!0),f($,null,ee(t.d_value,function(w,y){return p(),f("li",h({key:"".concat(y,"_").concat(o.getOptionLabel(w)),id:t.$id+"_multiple_option_"+y,class:t.cx("chipItem",{i:y}),role:"option","aria-label":o.getOptionLabel(w),"aria-selected":!0,"aria-setsize":t.d_value.length,"aria-posinset":y+1},{ref_for:!0},t.ptm("chipItem")),[S(t.$slots,"chip",h({class:t.cx("pcChip"),value:w,index:y,removeCallback:function(K){return o.removeOption(K,y)}},{ref_for:!0},t.ptm("pcChip")),function(){return[c(M,{class:V(t.cx("pcChip")),label:o.getOptionLabel(w),removeIcon:t.chipIcon||t.removeTokenIcon,removable:"",unstyled:t.unstyled,onRemove:function(K){return o.removeOption(K,y)},"data-p-focused":l.focusedMultipleOptionIndex===y,pt:t.ptm("pcChip")},{removeicon:v(function(){return[S(t.$slots,t.$slots.chipicon?"chipicon":"removetokenicon",{class:V(t.cx("chipIcon")),index:y,removeCallback:function(K){return o.removeOption(K,y)}})]}),_:2},1032,["class","label","removeIcon","unstyled","onRemove","data-p-focused","pt"])]})],16,St)}),128)),r("li",h({class:t.cx("inputChip"),role:"option"},t.ptm("inputChip")),[r("input",h({ref:"focusInput",id:t.inputId,type:"text",style:t.inputStyle,class:t.inputClass,placeholder:t.placeholder,tabindex:t.disabled?-1:t.tabindex,disabled:t.disabled,autocomplete:"off",role:"combobox","aria-label":t.ariaLabel,"aria-labelledby":t.ariaLabelledby,"aria-haspopup":"listbox","aria-autocomplete":"list","aria-expanded":l.overlayVisible,"aria-controls":t.$id+"_list","aria-activedescendant":l.focused?o.focusedOptionId:void 0,"aria-invalid":t.invalid||void 0,onFocus:e[0]||(e[0]=function(){return o.onFocus&&o.onFocus.apply(o,arguments)}),onBlur:e[1]||(e[1]=function(){return o.onBlur&&o.onBlur.apply(o,arguments)}),onKeydown:e[2]||(e[2]=function(){return o.onKeyDown&&o.onKeyDown.apply(o,arguments)}),onInput:e[3]||(e[3]=function(){return o.onInput&&o.onInput.apply(o,arguments)}),onChange:e[4]||(e[4]=function(){return o.onChange&&o.onChange.apply(o,arguments)})},t.ptm("input")),null,16,Ct)],16)],16,kt)):m("",!0),l.searching||t.loading?S(t.$slots,t.$slots.loader?"loader":"loadingicon",{key:3,class:V(t.cx("loader"))},function(){return[t.loader||t.loadingIcon?(p(),f("i",h({key:0,class:["pi-spin",t.cx("loader"),t.loader,t.loadingIcon],"aria-hidden":"true","data-p-has-dropdown":t.dropdown},t.ptm("loader")),null,16,Lt)):t.loading?(p(),U(N,h({key:1,class:t.cx("loader"),spin:"","aria-hidden":"true","data-p-has-dropdown":t.dropdown},t.ptm("loader")),null,16,["class","data-p-has-dropdown"])):m("",!0)]}):m("",!0),S(t.$slots,t.$slots.dropdown?"dropdown":"dropdownbutton",{toggleCallback:function(y){return o.onDropdownClick(y)}},function(){return[t.dropdown?(p(),f("button",h({key:0,ref:"dropdownButton",type:"button",class:[t.cx("dropdown"),t.dropdownClass],disabled:t.disabled,"aria-haspopup":"listbox","aria-expanded":l.overlayVisible,"aria-controls":o.panelId,onClick:e[8]||(e[8]=function(){return o.onDropdownClick&&o.onDropdownClick.apply(o,arguments)})},t.ptm("dropdown")),[S(t.$slots,"dropdownicon",{class:V(t.dropdownIcon)},function(){return[(p(),U(re(t.dropdownIcon?"span":"ChevronDownIcon"),h({class:t.dropdownIcon},t.ptm("dropdownIcon")),null,16,["class"]))]})],16,Mt)):m("",!0)]}),t.typeahead?(p(),f("span",h({key:4,role:"status","aria-live":"polite",class:"p-hidden-accessible"},t.ptm("hiddenSearchResult"),{"data-p-hidden-accessible":!0}),b(o.searchResultMessageText),17)):m("",!0),c(H,{appendTo:t.appendTo},{default:v(function(){return[c(De,h({name:"p-anchored-overlay",onEnter:o.onOverlayEnter,onAfterEnter:o.onOverlayAfterEnter,onLeave:o.onOverlayLeave,onAfterLeave:o.onOverlayAfterLeave},t.ptm("transition")),{default:v(function(){return[l.overlayVisible?(p(),f("div",h({key:0,ref:o.overlayRef,id:o.panelId,class:[t.cx("overlay"),t.panelClass,t.overlayClass],style:ve(ve({},t.panelStyle),t.overlayStyle),onClick:e[9]||(e[9]=function(){return o.onOverlayClick&&o.onOverlayClick.apply(o,arguments)}),onKeydown:e[10]||(e[10]=function(){return o.onOverlayKeyDown&&o.onOverlayKeyDown.apply(o,arguments)}),"data-p":o.overlayDataP},t.ptm("overlay")),[S(t.$slots,"header",{value:t.d_value,suggestions:o.visibleOptions}),r("div",h({class:t.cx("listContainer"),style:{"max-height":o.virtualScrollerDisabled?t.scrollHeight:""}},t.ptm("listContainer")),[c(P,h({ref:o.virtualScrollerRef},t.virtualScrollerOptions,{style:{height:t.scrollHeight},items:o.visibleOptions,tabindex:-1,disabled:o.virtualScrollerDisabled,pt:t.ptm("virtualScroller")}),Te({content:v(function(w){var y=w.styleClass,F=w.contentRef,K=w.items,L=w.getItemOptions,z=w.contentStyle,A=w.itemSize;return[r("ul",h({ref:function(k){return o.listRef(k,F)},id:t.$id+"_list",class:[t.cx("list"),y],style:z,role:"listbox","aria-label":o.listAriaLabel},t.ptm("list")),[(p(!0),f($,null,ee(K,function(O,k){return p(),f($,{key:o.getOptionRenderKey(O,o.getOptionIndex(k,L))},[o.isOptionGroup(O)?(p(),f("li",h({key:0,id:t.$id+"_"+o.getOptionIndex(k,L),style:{height:A?A+"px":void 0},class:t.cx("optionGroup"),role:"option"},{ref_for:!0},t.ptm("optionGroup")),[S(t.$slots,"optiongroup",{option:O.optionGroup,index:o.getOptionIndex(k,L)},function(){return[D(b(o.getOptionGroupLabel(O.optionGroup)),1)]})],16,Dt)):Pe((p(),f("li",h({key:1,id:t.$id+"_"+o.getOptionIndex(k,L),style:{height:A?A+"px":void 0},class:t.cx("option",{option:O,i:k,getItemOptions:L}),role:"option","aria-label":o.getOptionLabel(O),"aria-selected":o.isSelected(O),"aria-disabled":o.isOptionDisabled(O),"aria-setsize":o.ariaSetSize,"aria-posinset":o.getAriaPosInset(o.getOptionIndex(k,L)),onClick:function(E){return o.onOptionSelect(E,O)},onMousemove:function(E){return o.onOptionMouseMove(E,o.getOptionIndex(k,L))},"data-p-selected":o.isSelected(O),"data-p-focused":l.focusedOptionIndex===o.getOptionIndex(k,L),"data-p-disabled":o.isOptionDisabled(O)},{ref_for:!0},o.getPTOptions(O,L,k,"option")),[S(t.$slots,"option",{option:O,index:o.getOptionIndex(k,L)},function(){return[D(b(o.getOptionLabel(O)),1)]})],16,Tt)),[[te]])],64)}),128)),t.showEmptyMessage&&(!K||K&&K.length===0)?(p(),f("li",h({key:0,class:t.cx("emptyMessage"),role:"option"},t.ptm("emptyMessage")),[S(t.$slots,"empty",{},function(){return[D(b(o.searchResultMessageText),1)]})],16)):m("",!0)],16,Vt)]}),_:2},[t.$slots.loader?{name:"loader",fn:v(function(w){var y=w.options;return[S(t.$slots,"loader",{options:y})]}),key:"0"}:void 0]),1040,["style","items","disabled","pt"])],16),S(t.$slots,"footer",{value:t.d_value,suggestions:o.visibleOptions}),r("span",h({role:"status","aria-live":"polite",class:"p-hidden-accessible"},t.ptm("hiddenSelectedMessage"),{"data-p-hidden-accessible":!0}),b(o.selectedMessageText),17)],16,Kt)):m("",!0)]}),_:3},16,["onEnter","onAfterEnter","onLeave","onAfterLeave"])]}),_:3},8,["appendTo"])],16,xt)}de.render=Pt;const At={class:"sticky top-0 z-20 border-b border-border/60 bg-background/95 backdrop-blur-sm sm:hidden"},_t={class:"relative flex items-center justify-center px-4 py-3"},Ft={class:"mx-auto max-w-5xl p-4 sm:p-6"},zt={class:"rounded-xl border bg-card p-4 sm:p-6 shadow-sm"},Et={class:"grid gap-4 sm:grid-cols-3"},Rt={key:0,class:"mt-1 text-xs text-destructive"},Bt={key:0,class:"text-destructive"},$t={class:"py-0.5"},jt={class:"font-medium leading-tight"},Nt={class:"mt-0.5 flex items-center gap-2 text-xs text-muted-foreground"},Gt={class:"font-mono"},Ut={key:0,class:"before:mr-2 before:content-['·']"},qt={key:0,class:"mt-1 text-xs text-destructive"},Ht={key:0,class:"text-destructive"},Qt={class:"py-0.5"},Wt={class:"font-medium leading-tight"},Jt={key:0,class:"mt-0.5 text-xs text-muted-foreground"},Yt={key:0,class:"mt-1 text-xs text-destructive"},Xt={key:0,class:"mt-3 text-xs text-amber-600 dark:text-amber-400"},Zt={class:"rounded-xl border bg-card p-4 sm:p-6 shadow-sm"},en={key:0,class:"mb-3 text-xs text-destructive"},tn={class:"hidden overflow-hidden rounded-lg border sm:block"},nn={class:"w-full text-sm"},on={class:"divide-y"},ln={class:"px-4 py-3"},sn={key:0,class:"mt-1 text-xs text-destructive"},rn={class:"px-4 py-3"},an={class:"px-4 py-3"},dn={class:"px-4 py-3 text-right tabular-nums font-medium"},un={class:"px-4 py-3 text-center"},pn={class:"space-y-3 sm:hidden"},cn={class:"mt-2.5 grid grid-cols-2 gap-2.5"},fn={class:"mt-2.5 flex items-center justify-between"},hn={class:"text-sm font-semibold tabular-nums"},mn={class:"mt-4 flex items-center justify-between"},gn={class:"text-right"},vn={class:"text-2xl font-bold tabular-nums"},bn={class:"flex items-center justify-between"},yn={class:"fixed bottom-0 left-0 right-0 z-20 border-t border-border/60 bg-background/95 px-4 py-3 backdrop-blur-sm sm:hidden"},Gn=Ae({__name:"Create",props:{produits:{},vehicules:{},clients:{},sites:{}},setup(t){const e=t,n=[{title:"Tableau de bord",href:"/dashboard"},{title:"Ventes",href:"/ventes"},{title:"Nouvelle commande",href:"/ventes/create"}],i=_e({site_id:null,vehicule_id:null,client_id:null,lignes:[{produit_id:null,qte:1,prix_vente:0,total:0}]}),l=Q(null),o=Q([]);function x(u){const s=u.query.toLowerCase().trim();o.value=s?e.vehicules.filter(d=>d.nom_vehicule.toLowerCase().includes(s)||d.immatriculation.toLowerCase().includes(s)||d.livreur_nom&&d.livreur_nom.toLowerCase().includes(s)):[...e.vehicules]}function C(u){i.vehicule_id=u?.id??null}function M(){i.vehicule_id=null,l.value=null}function N(u){return`${u.nom_vehicule} — ${u.immatriculation}`}const P=Q(null),H=Q([]);function te(u){const s=u.query.toLowerCase().trim();H.value=s?e.clients.filter(d=>d.nom.toLowerCase().includes(s)||d.prenom&&d.prenom.toLowerCase().includes(s)||d.telephone&&d.telephone.includes(s)):[...e.clients]}function w(u){i.client_id=u?.id??null}function y(){i.client_id=null,P.value=null}function F(u){return[u.prenom,u.nom].filter(Boolean).join(" ")}const K=W(()=>e.sites.map(u=>({value:u.id,label:u.nom}))),L=W(()=>e.produits.map(u=>({value:u.id,label:`${u.nom} (${z(u.prix_vente)})`})));function z(u){return new Intl.NumberFormat("fr-FR").format(u)+" GNF"}function A(u,s){if(s===null){i.lignes[u].produit_id=null,i.lignes[u].prix_vente=0,i.lignes[u].total=0;return}const d=i.lignes.findIndex((T,Oe)=>Oe!==u&&T.produit_id===s);if(d!==-1){const T=i.lignes[d];T.qte+=i.lignes[u].qte,T.total=T.prix_vente*T.qte,i.lignes.splice(u,1);return}const g=i.lignes[u];g.produit_id=s;const I=e.produits.find(T=>T.id===s);g.prix_vente=I?I.prix_vente:0,g.total=g.prix_vente*g.qte}function O(u,s){const d=i.lignes[u];d.qte=s??1,d.total=d.prix_vente*d.qte}function k(u,s){const d=i.lignes[u];d.prix_vente=s??0,d.total=d.prix_vente*d.qte}function ne(){i.lignes.push({produit_id:null,qte:1,prix_vente:0,total:0})}function E(u){i.lignes.length>1&&i.lignes.splice(u,1)}const ue=W(()=>i.lignes.reduce((u,s)=>u+s.total,0));Fe(()=>{if(i.reset(),l.value=null,P.value=null,e.produits.length>0){const u=e.produits[0];i.lignes[0].produit_id=u.id,i.lignes[0].prix_vente=u.prix_vente,i.lignes[0].total=u.prix_vente*i.lignes[0].qte}});const pe=W(()=>i.site_id!==null&&(i.vehicule_id!==null||i.client_id!==null)&&ue.value>0&&!i.processing);function ce(){i.post("/ventes")}return(u,s)=>(p(),f($,null,[c(a(ze),{title:"Nouvelle commande"}),c(Be,{breadcrumbs:n,"hide-mobile-header":!0},{default:v(()=>[r("div",At,[r("div",_t,[c(a(fe),{href:"/ventes",class:"absolute left-4 inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-muted text-muted-foreground transition-transform active:scale-95"},{default:v(()=>[c(a(Ze),{class:"h-4 w-4"})]),_:1}),s[5]||(s[5]=r("div",{class:"text-center"},[r("h1",{class:"text-[17px] font-semibold leading-tight"},"Nouvelle vente")],-1))])]),r("div",Ft,[s[21]||(s[21]=r("div",{class:"mb-6 hidden sm:block"},[r("h1",{class:"text-2xl font-semibold tracking-tight"},"Nouvelle commande de vente"),r("p",{class:"mt-1 text-sm text-muted-foreground"}," Créez une commande et sa facture sera générée automatiquement. ")],-1)),r("form",{id:"vente-form",class:"space-y-6",onSubmit:Ee(ce,["prevent"])},[r("div",zt,[s[11]||(s[11]=r("h2",{class:"mb-5 text-sm font-semibold uppercase tracking-wider text-muted-foreground"}," Informations générales ",-1)),r("div",Et,[r("div",null,[c(a(oe),{class:"mb-1.5 block text-sm"},{default:v(()=>[...s[6]||(s[6]=[D("Site ",-1),r("span",{class:"text-destructive"},"*",-1)])]),_:1}),c(a(se),{modelValue:a(i).site_id,"onUpdate:modelValue":s[0]||(s[0]=d=>a(i).site_id=d),options:K.value,"option-label":"label","option-value":"value",placeholder:"— Sélectionner —","show-clear":"",filter:"",class:V(["w-full",{"p-invalid":a(i).errors.site_id}])},null,8,["modelValue","options","class"]),a(i).errors.site_id?(p(),f("p",Rt,b(a(i).errors.site_id),1)):m("",!0)]),r("div",null,[c(a(oe),{class:"mb-1.5 block text-sm"},{default:v(()=>[s[7]||(s[7]=D(" Véhicule ",-1)),a(i).client_id?m("",!0):(p(),f("span",Bt," *"))]),_:1}),c(a(de),{modelValue:l.value,"onUpdate:modelValue":s[1]||(s[1]=d=>l.value=d),suggestions:o.value,"option-label":N,onComplete:x,onItemSelect:s[2]||(s[2]=d=>C(l.value)),onClear:M,placeholder:"Nom, immatriculation, livreur…",class:V(["w-full",{"p-invalid":a(i).errors.vehicule_id}]),"input-class":"w-full",dropdown:"","force-selection":""},{option:v(({option:d})=>[r("div",$t,[r("div",jt,b(d.nom_vehicule),1),r("div",Nt,[r("span",Gt,b(d.immatriculation),1),d.livreur_nom?(p(),f("span",Ut,b(d.livreur_nom),1)):m("",!0)])])]),empty:v(()=>[...s[8]||(s[8]=[r("span",{class:"text-sm text-muted-foreground"},"Aucun véhicule trouvé.",-1)])]),_:1},8,["modelValue","suggestions","class"]),a(i).errors.vehicule_id?(p(),f("p",qt,b(a(i).errors.vehicule_id),1)):m("",!0)]),r("div",null,[c(a(oe),{class:"mb-1.5 block text-sm"},{default:v(()=>[s[9]||(s[9]=D(" Client ",-1)),a(i).vehicule_id?m("",!0):(p(),f("span",Ht," *"))]),_:1}),c(a(de),{modelValue:P.value,"onUpdate:modelValue":s[3]||(s[3]=d=>P.value=d),suggestions:H.value,"option-label":F,onComplete:te,onItemSelect:s[4]||(s[4]=d=>w(P.value)),onClear:y,placeholder:"Nom, prénom, téléphone…",class:V(["w-full",{"p-invalid":a(i).errors.client_id}]),"input-class":"w-full",dropdown:"","force-selection":""},{option:v(({option:d})=>[r("div",Qt,[r("div",Wt,b([d.prenom,d.nom].filter(Boolean).join(" ")),1),d.telephone?(p(),f("div",Jt,b(a($e)(d.telephone)),1)):m("",!0)])]),empty:v(()=>[...s[10]||(s[10]=[r("span",{class:"text-sm text-muted-foreground"},"Aucun client trouvé.",-1)])]),_:1},8,["modelValue","suggestions","class"]),a(i).errors.client_id?(p(),f("p",Yt,b(a(i).errors.client_id),1)):m("",!0)])]),!a(i).vehicule_id&&!a(i).client_id?(p(),f("p",Xt," Sélectionnez au moins un véhicule ou un client. ")):m("",!0)]),r("div",Zt,[s[18]||(s[18]=r("h2",{class:"mb-5 text-sm font-semibold uppercase tracking-wider text-muted-foreground"}," Lignes de commande ",-1)),a(i).errors.lignes?(p(),f("p",en,b(a(i).errors.lignes),1)):m("",!0),r("div",tn,[r("table",nn,[s[12]||(s[12]=r("thead",null,[r("tr",{class:"border-b bg-muted/40"},[r("th",{class:"px-4 py-2.5 text-left font-medium text-muted-foreground"},"Produit"),r("th",{class:"px-4 py-2.5 text-center font-medium text-muted-foreground",style:{width:"110px"}},"Qté"),r("th",{class:"px-4 py-2.5 text-right font-medium text-muted-foreground",style:{width:"180px"}},"Prix unit."),r("th",{class:"px-4 py-2.5 text-right font-medium text-muted-foreground",style:{width:"160px"}},"Total"),r("th",{class:"px-4 py-2.5",style:{width:"48px"}})])],-1)),r("tbody",on,[(p(!0),f($,null,ee(a(i).lignes,(d,g)=>(p(),f("tr",{key:g,class:"hover:bg-muted/10"},[r("td",ln,[c(a(se),{"model-value":d.produit_id,"onUpdate:modelValue":I=>A(g,I),options:L.value,"option-label":"label","option-value":"value",placeholder:"Choisir un produit...",filter:"",class:V(["w-full",{"p-invalid":a(i).errors[`lignes.${g}.produit_id`]}])},null,8,["model-value","onUpdate:modelValue","options","class"]),a(i).errors[`lignes.${g}.produit_id`]?(p(),f("p",sn,b(a(i).errors[`lignes.${g}.produit_id`]),1)):m("",!0)]),r("td",rn,[c(a(J),{"model-value":d.qte,"onUpdate:modelValue":I=>O(g,I),min:1,"use-grouping":!1,class:"w-full","input-class":"w-full text-center"},null,8,["model-value","onUpdate:modelValue"])]),r("td",an,[c(a(J),{"model-value":d.prix_vente,"onUpdate:modelValue":I=>k(g,I),min:0,"use-grouping":!1,suffix:" GNF",class:"w-full","input-class":"w-full text-right"},null,8,["model-value","onUpdate:modelValue"])]),r("td",dn,b(d.total>0?z(d.total):"—"),1),r("td",un,[c(a(B),{type:"button",variant:"ghost",size:"icon",class:"h-7 w-7 text-destructive hover:text-destructive",disabled:a(i).lignes.length<=1,onClick:I=>E(g)},{default:v(()=>[c(a(he),{class:"h-4 w-4"})]),_:1},8,["disabled","onClick"])])]))),128))])])]),r("div",pn,[(p(!0),f($,null,ee(a(i).lignes,(d,g)=>(p(),f("div",{key:g,class:"rounded-xl border bg-muted/20 p-3"},[c(a(se),{"model-value":d.produit_id,"onUpdate:modelValue":I=>A(g,I),options:L.value,"option-label":"label","option-value":"value",placeholder:"Choisir un produit...",filter:"",class:V(["w-full",{"p-invalid":a(i).errors[`lignes.${g}.produit_id`]}])},null,8,["model-value","onUpdate:modelValue","options","class"]),r("div",cn,[r("div",null,[s[13]||(s[13]=r("p",{class:"mb-1 text-[11px] font-medium text-muted-foreground"},"Quantité",-1)),c(a(J),{"model-value":d.qte,"onUpdate:modelValue":I=>O(g,I),min:1,"use-grouping":!1,class:"w-full","input-class":"w-full text-center"},null,8,["model-value","onUpdate:modelValue"])]),r("div",null,[s[14]||(s[14]=r("p",{class:"mb-1 text-[11px] font-medium text-muted-foreground"},"Prix unit. (GNF)",-1)),c(a(J),{"model-value":d.prix_vente,"onUpdate:modelValue":I=>k(g,I),min:0,"use-grouping":!1,class:"w-full","input-class":"w-full"},null,8,["model-value","onUpdate:modelValue"])])]),r("div",fn,[r("div",null,[s[15]||(s[15]=r("p",{class:"text-[11px] text-muted-foreground"},"Total ligne",-1)),r("p",hn,b(d.total>0?z(d.total):"—"),1)]),c(a(B),{type:"button",variant:"ghost",size:"icon",class:"h-8 w-8 text-destructive hover:text-destructive",disabled:a(i).lignes.length<=1,onClick:I=>E(g)},{default:v(()=>[c(a(he),{class:"h-4 w-4"})]),_:1},8,["disabled","onClick"])])]))),128))]),r("div",mn,[c(a(B),{type:"button",variant:"outline",size:"sm",onClick:ne},{default:v(()=>[c(a(et),{class:"mr-2 h-4 w-4"}),s[16]||(s[16]=D(" Ajouter une ligne ",-1))]),_:1}),r("div",gn,[s[17]||(s[17]=r("p",{class:"text-xs uppercase tracking-wider text-muted-foreground"},"Total commande",-1)),r("p",vn,b(z(ue.value)),1)])])]),s[20]||(s[20]=r("div",{class:"h-20 sm:hidden"},null,-1)),r("div",bn,[c(a(fe),{href:"/ventes"},{default:v(()=>[c(a(B),{type:"button",variant:"outline"},{default:v(()=>[...s[19]||(s[19]=[D("Retour",-1)])]),_:1})]),_:1}),c(a(B),{type:"submit",disabled:!pe.value},{default:v(()=>[D(b(a(i).processing?"Enregistrement…":"Enregistrer la commande"),1)]),_:1},8,["disabled"])])],32)]),r("div",yn,[c(a(B),{class:"w-full",disabled:!pe.value,onClick:ce},{default:v(()=>[c(a(tt),{class:"mr-2 h-4 w-4"}),D(" "+b(a(i).processing?"Enregistrement…":"Enregistrer la vente"),1)]),_:1},8,["disabled"])])]),_:1})],64))}});export{Gn as default};
