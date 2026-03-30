const __vite__mapDeps=(i,m=__vite__mapDeps,d=(m.f||(m.f=["assets/quill-UE7W4a09.js","assets/app-BAP4VrGH.js","assets/app-Cvk7-xGE.css"])))=>i.map(i=>d[i]);
import{B as L,ab as R,T as I,a as b,k as p,f as e,a7 as H,O as r,ac as T,ad as B,d as G,c as $,q as D,j as k,b as s,w as c,i as d,e as i,n as y,t as g,v as M,g as Q}from"./app-BAP4VrGH.js";import{_ as S}from"./index-C8WftgfC.js";import{_ as A}from"./Checkbox.vue_vue_type_script_setup_true_lang-COO0hR6Z.js";import{_ as m}from"./Label.vue_vue_type_script_setup_true_lang-BJXCSiBM.js";import{s as V}from"./index-Dtk38At9.js";import{b as F,s as j}from"./index-zXMqDhmX.js";import{s as h}from"./index-CRFWvGYx.js";import{c as J}from"./createLucideIcon-kw9snnLP.js";import{X as K}from"./x-yNBTEuSw.js";import{S as Y}from"./save-CmyAP7OW.js";/**
 * @license lucide-vue-next v0.468.0 - ISC
 *
 * This source code is licensed under the ISC license.
 * See the LICENSE file in the root directory of this source tree.
 */const O=J("ImageIcon",[["rect",{width:"18",height:"18",x:"3",y:"3",rx:"2",ry:"2",key:"1m3agn"}],["circle",{cx:"9",cy:"9",r:"2",key:"af1f0g"}],["path",{d:"m21 15-3.086-3.086a2 2 0 0 0-2.828 0L6 21",key:"1xmnt7"}]]);var W=`
    /*!
* Quill Editor v1.3.3
* https://quilljs.com/
* Copyright (c) 2014, Jason Chen
* Copyright (c) 2013, salesforce.com
*/
    .ql-container {
        box-sizing: border-box;
        font-family: Helvetica, Arial, sans-serif;
        font-size: 13px;
        height: 100%;
        margin: 0;
        position: relative;
    }
    .ql-container.ql-disabled .ql-tooltip {
        visibility: hidden;
    }
    .ql-container.ql-disabled .ql-editor ul[data-checked] > li::before {
        pointer-events: none;
    }
    .ql-clipboard {
        inset-inline-start: -100000px;
        height: 1px;
        overflow-y: hidden;
        position: absolute;
        top: 50%;
    }
    .ql-clipboard p {
        margin: 0;
        padding: 0;
    }
    .ql-editor {
        box-sizing: border-box;
        line-height: 1.42;
        height: 100%;
        outline: none;
        overflow-y: auto;
        padding: 12px 15px;
        tab-size: 4;
        -moz-tab-size: 4;
        text-align: left;
        white-space: pre-wrap;
        word-wrap: break-word;
    }
    .ql-editor > * {
        cursor: text;
    }
    .ql-editor p,
    .ql-editor ol,
    .ql-editor ul,
    .ql-editor pre,
    .ql-editor blockquote,
    .ql-editor h1,
    .ql-editor h2,
    .ql-editor h3,
    .ql-editor h4,
    .ql-editor h5,
    .ql-editor h6 {
        margin: 0;
        padding: 0;
        counter-reset: list-1 list-2 list-3 list-4 list-5 list-6 list-7 list-8 list-9;
    }
    .ql-editor ol,
    .ql-editor ul {
        padding-inline-start: 1.5rem;
    }
    .ql-editor ol > li,
    .ql-editor ul > li {
        list-style-type: none;
    }
    .ql-editor ul > li::before {
        content: '\\2022';
    }
    .ql-editor ul[data-checked='true'],
    .ql-editor ul[data-checked='false'] {
        pointer-events: none;
    }
    .ql-editor ul[data-checked='true'] > li *,
    .ql-editor ul[data-checked='false'] > li * {
        pointer-events: all;
    }
    .ql-editor ul[data-checked='true'] > li::before,
    .ql-editor ul[data-checked='false'] > li::before {
        color: #777;
        cursor: pointer;
        pointer-events: all;
    }
    .ql-editor ul[data-checked='true'] > li::before {
        content: '\\2611';
    }
    .ql-editor ul[data-checked='false'] > li::before {
        content: '\\2610';
    }
    .ql-editor li::before {
        display: inline-block;
        white-space: nowrap;
        width: 1.2rem;
    }
    .ql-editor li:not(.ql-direction-rtl)::before {
        margin-inline-start: -1.5rem;
        margin-inline-end: 0.3rem;
        text-align: right;
    }
    .ql-editor li.ql-direction-rtl::before {
        margin-inline-start: 0.3rem;
        margin-inline-end: -1.5rem;
    }
    .ql-editor ol li:not(.ql-direction-rtl),
    .ql-editor ul li:not(.ql-direction-rtl) {
        padding-inline-start: 1.5rem;
    }
    .ql-editor ol li.ql-direction-rtl,
    .ql-editor ul li.ql-direction-rtl {
        padding-inline-end: 1.5rem;
    }
    .ql-editor ol li {
        counter-reset: list-1 list-2 list-3 list-4 list-5 list-6 list-7 list-8 list-9;
        counter-increment: list-0;
    }
    .ql-editor ol li:before {
        content: counter(list-0, decimal) '. ';
    }
    .ql-editor ol li.ql-indent-1 {
        counter-increment: list-1;
    }
    .ql-editor ol li.ql-indent-1:before {
        content: counter(list-1, lower-alpha) '. ';
    }
    .ql-editor ol li.ql-indent-1 {
        counter-reset: list-2 list-3 list-4 list-5 list-6 list-7 list-8 list-9;
    }
    .ql-editor ol li.ql-indent-2 {
        counter-increment: list-2;
    }
    .ql-editor ol li.ql-indent-2:before {
        content: counter(list-2, lower-roman) '. ';
    }
    .ql-editor ol li.ql-indent-2 {
        counter-reset: list-3 list-4 list-5 list-6 list-7 list-8 list-9;
    }
    .ql-editor ol li.ql-indent-3 {
        counter-increment: list-3;
    }
    .ql-editor ol li.ql-indent-3:before {
        content: counter(list-3, decimal) '. ';
    }
    .ql-editor ol li.ql-indent-3 {
        counter-reset: list-4 list-5 list-6 list-7 list-8 list-9;
    }
    .ql-editor ol li.ql-indent-4 {
        counter-increment: list-4;
    }
    .ql-editor ol li.ql-indent-4:before {
        content: counter(list-4, lower-alpha) '. ';
    }
    .ql-editor ol li.ql-indent-4 {
        counter-reset: list-5 list-6 list-7 list-8 list-9;
    }
    .ql-editor ol li.ql-indent-5 {
        counter-increment: list-5;
    }
    .ql-editor ol li.ql-indent-5:before {
        content: counter(list-5, lower-roman) '. ';
    }
    .ql-editor ol li.ql-indent-5 {
        counter-reset: list-6 list-7 list-8 list-9;
    }
    .ql-editor ol li.ql-indent-6 {
        counter-increment: list-6;
    }
    .ql-editor ol li.ql-indent-6:before {
        content: counter(list-6, decimal) '. ';
    }
    .ql-editor ol li.ql-indent-6 {
        counter-reset: list-7 list-8 list-9;
    }
    .ql-editor ol li.ql-indent-7 {
        counter-increment: list-7;
    }
    .ql-editor ol li.ql-indent-7:before {
        content: counter(list-7, lower-alpha) '. ';
    }
    .ql-editor ol li.ql-indent-7 {
        counter-reset: list-8 list-9;
    }
    .ql-editor ol li.ql-indent-8 {
        counter-increment: list-8;
    }
    .ql-editor ol li.ql-indent-8:before {
        content: counter(list-8, lower-roman) '. ';
    }
    .ql-editor ol li.ql-indent-8 {
        counter-reset: list-9;
    }
    .ql-editor ol li.ql-indent-9 {
        counter-increment: list-9;
    }
    .ql-editor ol li.ql-indent-9:before {
        content: counter(list-9, decimal) '. ';
    }
    .ql-editor .ql-video {
        display: block;
        max-width: 100%;
    }
    .ql-editor .ql-video.ql-align-center {
        margin: 0 auto;
    }
    .ql-editor .ql-video.ql-align-right {
        margin: 0 0 0 auto;
    }
    .ql-editor .ql-bg-black {
        background: #000;
    }
    .ql-editor .ql-bg-red {
        background: #e60000;
    }
    .ql-editor .ql-bg-orange {
        background: #f90;
    }
    .ql-editor .ql-bg-yellow {
        background: #ff0;
    }
    .ql-editor .ql-bg-green {
        background: #008a00;
    }
    .ql-editor .ql-bg-blue {
        background: #06c;
    }
    .ql-editor .ql-bg-purple {
        background: #93f;
    }
    .ql-editor .ql-color-white {
        color: #fff;
    }
    .ql-editor .ql-color-red {
        color: #e60000;
    }
    .ql-editor .ql-color-orange {
        color: #f90;
    }
    .ql-editor .ql-color-yellow {
        color: #ff0;
    }
    .ql-editor .ql-color-green {
        color: #008a00;
    }
    .ql-editor .ql-color-blue {
        color: #06c;
    }
    .ql-editor .ql-color-purple {
        color: #93f;
    }
    .ql-editor .ql-font-serif {
        font-family:
            Georgia,
            Times New Roman,
            serif;
    }
    .ql-editor .ql-font-monospace {
        font-family:
            Monaco,
            Courier New,
            monospace;
    }
    .ql-editor .ql-size-small {
        font-size: 0.75rem;
    }
    .ql-editor .ql-size-large {
        font-size: 1.5rem;
    }
    .ql-editor .ql-size-huge {
        font-size: 2.5rem;
    }
    .ql-editor .ql-direction-rtl {
        direction: rtl;
        text-align: inherit;
    }
    .ql-editor .ql-align-center {
        text-align: center;
    }
    .ql-editor .ql-align-justify {
        text-align: justify;
    }
    .ql-editor .ql-align-right {
        text-align: right;
    }
    .ql-editor.ql-blank::before {
        color: dt('form.field.placeholder.color');
        content: attr(data-placeholder);
        font-style: italic;
        inset-inline-start: 15px;
        pointer-events: none;
        position: absolute;
        inset-inline-end: 15px;
    }
    .ql-snow.ql-toolbar:after,
    .ql-snow .ql-toolbar:after {
        clear: both;
        content: '';
        display: table;
    }
    .ql-snow.ql-toolbar button,
    .ql-snow .ql-toolbar button {
        background: none;
        border: none;
        cursor: pointer;
        display: inline-block;
        float: left;
        height: 24px;
        padding-block: 3px;
        padding-inline: 5px;
        width: 28px;
    }
    .ql-snow.ql-toolbar button svg,
    .ql-snow .ql-toolbar button svg {
        float: left;
        height: 100%;
    }
    .ql-snow.ql-toolbar button:active:hover,
    .ql-snow .ql-toolbar button:active:hover {
        outline: none;
    }
    .ql-snow.ql-toolbar input.ql-image[type='file'],
    .ql-snow .ql-toolbar input.ql-image[type='file'] {
        display: none;
    }
    .ql-snow.ql-toolbar button:hover,
    .ql-snow .ql-toolbar button:hover,
    .ql-snow.ql-toolbar button:focus,
    .ql-snow .ql-toolbar button:focus,
    .ql-snow.ql-toolbar button.ql-active,
    .ql-snow .ql-toolbar button.ql-active,
    .ql-snow.ql-toolbar .ql-picker-label:hover,
    .ql-snow .ql-toolbar .ql-picker-label:hover,
    .ql-snow.ql-toolbar .ql-picker-label.ql-active,
    .ql-snow .ql-toolbar .ql-picker-label.ql-active,
    .ql-snow.ql-toolbar .ql-picker-item:hover,
    .ql-snow .ql-toolbar .ql-picker-item:hover,
    .ql-snow.ql-toolbar .ql-picker-item.ql-selected,
    .ql-snow .ql-toolbar .ql-picker-item.ql-selected {
        color: #06c;
    }
    .ql-snow.ql-toolbar button:hover .ql-fill,
    .ql-snow .ql-toolbar button:hover .ql-fill,
    .ql-snow.ql-toolbar button:focus .ql-fill,
    .ql-snow .ql-toolbar button:focus .ql-fill,
    .ql-snow.ql-toolbar button.ql-active .ql-fill,
    .ql-snow .ql-toolbar button.ql-active .ql-fill,
    .ql-snow.ql-toolbar .ql-picker-label:hover .ql-fill,
    .ql-snow .ql-toolbar .ql-picker-label:hover .ql-fill,
    .ql-snow.ql-toolbar .ql-picker-label.ql-active .ql-fill,
    .ql-snow .ql-toolbar .ql-picker-label.ql-active .ql-fill,
    .ql-snow.ql-toolbar .ql-picker-item:hover .ql-fill,
    .ql-snow .ql-toolbar .ql-picker-item:hover .ql-fill,
    .ql-snow.ql-toolbar .ql-picker-item.ql-selected .ql-fill,
    .ql-snow .ql-toolbar .ql-picker-item.ql-selected .ql-fill,
    .ql-snow.ql-toolbar button:hover .ql-stroke.ql-fill,
    .ql-snow .ql-toolbar button:hover .ql-stroke.ql-fill,
    .ql-snow.ql-toolbar button:focus .ql-stroke.ql-fill,
    .ql-snow .ql-toolbar button:focus .ql-stroke.ql-fill,
    .ql-snow.ql-toolbar button.ql-active .ql-stroke.ql-fill,
    .ql-snow .ql-toolbar button.ql-active .ql-stroke.ql-fill,
    .ql-snow.ql-toolbar .ql-picker-label:hover .ql-stroke.ql-fill,
    .ql-snow .ql-toolbar .ql-picker-label:hover .ql-stroke.ql-fill,
    .ql-snow.ql-toolbar .ql-picker-label.ql-active .ql-stroke.ql-fill,
    .ql-snow .ql-toolbar .ql-picker-label.ql-active .ql-stroke.ql-fill,
    .ql-snow.ql-toolbar .ql-picker-item:hover .ql-stroke.ql-fill,
    .ql-snow .ql-toolbar .ql-picker-item:hover .ql-stroke.ql-fill,
    .ql-snow.ql-toolbar .ql-picker-item.ql-selected .ql-stroke.ql-fill,
    .ql-snow .ql-toolbar .ql-picker-item.ql-selected .ql-stroke.ql-fill {
        fill: #06c;
    }
    .ql-snow.ql-toolbar button:hover .ql-stroke,
    .ql-snow .ql-toolbar button:hover .ql-stroke,
    .ql-snow.ql-toolbar button:focus .ql-stroke,
    .ql-snow .ql-toolbar button:focus .ql-stroke,
    .ql-snow.ql-toolbar button.ql-active .ql-stroke,
    .ql-snow .ql-toolbar button.ql-active .ql-stroke,
    .ql-snow.ql-toolbar .ql-picker-label:hover .ql-stroke,
    .ql-snow .ql-toolbar .ql-picker-label:hover .ql-stroke,
    .ql-snow.ql-toolbar .ql-picker-label.ql-active .ql-stroke,
    .ql-snow .ql-toolbar .ql-picker-label.ql-active .ql-stroke,
    .ql-snow.ql-toolbar .ql-picker-item:hover .ql-stroke,
    .ql-snow .ql-toolbar .ql-picker-item:hover .ql-stroke,
    .ql-snow.ql-toolbar .ql-picker-item.ql-selected .ql-stroke,
    .ql-snow .ql-toolbar .ql-picker-item.ql-selected .ql-stroke,
    .ql-snow.ql-toolbar button:hover .ql-stroke-miter,
    .ql-snow .ql-toolbar button:hover .ql-stroke-miter,
    .ql-snow.ql-toolbar button:focus .ql-stroke-miter,
    .ql-snow .ql-toolbar button:focus .ql-stroke-miter,
    .ql-snow.ql-toolbar button.ql-active .ql-stroke-miter,
    .ql-snow.ql-toolbar button.ql-active .ql-stroke-miter,
    .ql-snow.ql-toolbar .ql-picker-label:hover .ql-stroke-miter,
    .ql-snow .ql-toolbar .ql-picker-label:hover .ql-stroke-miter,
    .ql-snow.ql-toolbar .ql-picker-label.ql-active .ql-stroke-miter,
    .ql-snow .ql-toolbar .ql-picker-label.ql-active .ql-stroke-miter,
    .ql-snow.ql-toolbar .ql-picker-item:hover .ql-stroke-miter,
    .ql-snow .ql-toolbar .ql-picker-item:hover .ql-stroke-miter,
    .ql-snow.ql-toolbar .ql-picker-item.ql-selected .ql-stroke-miter,
    .ql-snow .ql-toolbar .ql-picker-item.ql-selected .ql-stroke-miter {
        stroke: #06c;
    }
    @media (pointer: coarse) {
        .ql-snow.ql-toolbar button:hover:not(.ql-active),
        .ql-snow .ql-toolbar button:hover:not(.ql-active) {
            color: #444;
        }
        .ql-snow.ql-toolbar button:hover:not(.ql-active) .ql-fill,
        .ql-snow .ql-toolbar button:hover:not(.ql-active) .ql-fill,
        .ql-snow.ql-toolbar button:hover:not(.ql-active) .ql-stroke.ql-fill,
        .ql-snow .ql-toolbar button:hover:not(.ql-active) .ql-stroke.ql-fill {
            fill: #444;
        }
        .ql-snow.ql-toolbar button:hover:not(.ql-active) .ql-stroke,
        .ql-snow .ql-toolbar button:hover:not(.ql-active) .ql-stroke,
        .ql-snow.ql-toolbar button:hover:not(.ql-active) .ql-stroke-miter,
        .ql-snow .ql-toolbar button:hover:not(.ql-active) .ql-stroke-miter {
            stroke: #444;
        }
    }
    .ql-snow {
        box-sizing: border-box;
    }
    .ql-snow * {
        box-sizing: border-box;
    }
    .ql-snow .ql-hidden {
        display: none;
    }
    .ql-snow .ql-out-bottom,
    .ql-snow .ql-out-top {
        visibility: hidden;
    }
    .ql-snow .ql-tooltip {
        position: absolute;
        transform: translateY(10px);
    }
    .ql-snow .ql-tooltip a {
        cursor: pointer;
        text-decoration: none;
    }
    .ql-snow .ql-tooltip.ql-flip {
        transform: translateY(-10px);
    }
    .ql-snow .ql-formats {
        display: inline-block;
        vertical-align: middle;
    }
    .ql-snow .ql-formats:after {
        clear: both;
        content: '';
        display: table;
    }
    .ql-snow .ql-stroke {
        fill: none;
        stroke: #444;
        stroke-linecap: round;
        stroke-linejoin: round;
        stroke-width: 2;
    }
    .ql-snow .ql-stroke-miter {
        fill: none;
        stroke: #444;
        stroke-miterlimit: 10;
        stroke-width: 2;
    }
    .ql-snow .ql-fill,
    .ql-snow .ql-stroke.ql-fill {
        fill: #444;
    }
    .ql-snow .ql-empty {
        fill: none;
    }
    .ql-snow .ql-even {
        fill-rule: evenodd;
    }
    .ql-snow .ql-thin,
    .ql-snow .ql-stroke.ql-thin {
        stroke-width: 1;
    }
    .ql-snow .ql-transparent {
        opacity: 0.4;
    }
    .ql-snow .ql-direction svg:last-child {
        display: none;
    }
    .ql-snow .ql-direction.ql-active svg:last-child {
        display: inline;
    }
    .ql-snow .ql-direction.ql-active svg:first-child {
        display: none;
    }
    .ql-snow .ql-editor h1 {
        font-size: 2rem;
    }
    .ql-snow .ql-editor h2 {
        font-size: 1.5rem;
    }
    .ql-snow .ql-editor h3 {
        font-size: 1.17rem;
    }
    .ql-snow .ql-editor h4 {
        font-size: 1rem;
    }
    .ql-snow .ql-editor h5 {
        font-size: 0.83rem;
    }
    .ql-snow .ql-editor h6 {
        font-size: 0.67rem;
    }
    .ql-snow .ql-editor a {
        text-decoration: underline;
    }
    .ql-snow .ql-editor blockquote {
        border-inline-start: 4px solid #ccc;
        margin-block-end: 5px;
        margin-block-start: 5px;
        padding-inline-start: 16px;
    }
    .ql-snow .ql-editor code,
    .ql-snow .ql-editor pre {
        background: #f0f0f0;
        border-radius: 3px;
    }
    .ql-snow .ql-editor pre {
        white-space: pre-wrap;
        margin-block-end: 5px;
        margin-block-start: 5px;
        padding: 5px 10px;
    }
    .ql-snow .ql-editor code {
        font-size: 85%;
        padding: 2px 4px;
    }
    .ql-snow .ql-editor pre.ql-syntax {
        background: #23241f;
        color: #f8f8f2;
        overflow: visible;
    }
    .ql-snow .ql-editor img {
        max-width: 100%;
    }
    .ql-snow .ql-picker {
        color: #444;
        display: inline-block;
        float: left;
        inset-inline-start: 0;
        font-size: 14px;
        font-weight: 500;
        height: 24px;
        position: relative;
        vertical-align: middle;
    }
    .ql-snow .ql-picker-label {
        cursor: pointer;
        display: inline-block;
        height: 100%;
        padding-inline-start: 8px;
        padding-inline-end: 2px;
        position: relative;
        width: 100%;
    }
    .ql-snow .ql-picker-label::before {
        display: inline-block;
        line-height: 22px;
    }
    .ql-snow .ql-picker-options {
        background: #fff;
        display: none;
        min-width: 100%;
        padding: 4px 8px;
        position: absolute;
        white-space: nowrap;
    }
    .ql-snow .ql-picker-options .ql-picker-item {
        cursor: pointer;
        display: block;
        padding-block-end: 5px;
        padding-block-start: 5px;
    }
    .ql-snow .ql-picker.ql-expanded .ql-picker-label {
        color: #ccc;
        z-index: 2;
    }
    .ql-snow .ql-picker.ql-expanded .ql-picker-label .ql-fill {
        fill: #ccc;
    }
    .ql-snow .ql-picker.ql-expanded .ql-picker-label .ql-stroke {
        stroke: #ccc;
    }
    .ql-snow .ql-picker.ql-expanded .ql-picker-options {
        display: block;
        margin-block-start: -1px;
        top: 100%;
        z-index: 1;
    }
    .ql-snow .ql-color-picker,
    .ql-snow .ql-icon-picker {
        width: 28px;
    }
    .ql-snow .ql-color-picker .ql-picker-label,
    .ql-snow .ql-icon-picker .ql-picker-label {
        padding: 2px 4px;
    }
    .ql-snow .ql-color-picker .ql-picker-label svg,
    .ql-snow .ql-icon-picker .ql-picker-label svg {
        inset-inline-end: 4px;
    }
    .ql-snow .ql-icon-picker .ql-picker-options {
        padding: 4px 0;
    }
    .ql-snow .ql-icon-picker .ql-picker-item {
        height: 24px;
        width: 24px;
        padding: 2px 4px;
    }
    .ql-snow .ql-color-picker .ql-picker-options {
        padding: 3px 5px;
        width: 152px;
    }
    .ql-snow .ql-color-picker .ql-picker-item {
        border: 1px solid transparent;
        float: left;
        height: 16px;
        margin: 2px;
        padding: 0;
        width: 16px;
    }
    .ql-snow .ql-picker:not(.ql-color-picker):not(.ql-icon-picker) svg {
        position: absolute;
        margin-block-start: -9px;
        inset-inline-end: 0;
        top: 50%;
        width: 18px;
    }
    .ql-snow .ql-picker.ql-header .ql-picker-label[data-label]:not([data-label=''])::before,
    .ql-snow .ql-picker.ql-font .ql-picker-label[data-label]:not([data-label=''])::before,
    .ql-snow .ql-picker.ql-size .ql-picker-label[data-label]:not([data-label=''])::before,
    .ql-snow .ql-picker.ql-header .ql-picker-item[data-label]:not([data-label=''])::before,
    .ql-snow .ql-picker.ql-font .ql-picker-item[data-label]:not([data-label=''])::before,
    .ql-snow .ql-picker.ql-size .ql-picker-item[data-label]:not([data-label=''])::before {
        content: attr(data-label);
    }
    .ql-snow .ql-picker.ql-header {
        width: 98px;
    }
    .ql-snow .ql-picker.ql-header .ql-picker-label::before,
    .ql-snow .ql-picker.ql-header .ql-picker-item::before {
        content: 'Normal';
    }
    .ql-snow .ql-picker.ql-header .ql-picker-label[data-value='1']::before,
    .ql-snow .ql-picker.ql-header .ql-picker-item[data-value='1']::before {
        content: 'Heading 1';
    }
    .ql-snow .ql-picker.ql-header .ql-picker-label[data-value='2']::before,
    .ql-snow .ql-picker.ql-header .ql-picker-item[data-value='2']::before {
        content: 'Heading 2';
    }
    .ql-snow .ql-picker.ql-header .ql-picker-label[data-value='3']::before,
    .ql-snow .ql-picker.ql-header .ql-picker-item[data-value='3']::before {
        content: 'Heading 3';
    }
    .ql-snow .ql-picker.ql-header .ql-picker-label[data-value='4']::before,
    .ql-snow .ql-picker.ql-header .ql-picker-item[data-value='4']::before {
        content: 'Heading 4';
    }
    .ql-snow .ql-picker.ql-header .ql-picker-label[data-value='5']::before,
    .ql-snow .ql-picker.ql-header .ql-picker-item[data-value='5']::before {
        content: 'Heading 5';
    }
    .ql-snow .ql-picker.ql-header .ql-picker-label[data-value='6']::before,
    .ql-snow .ql-picker.ql-header .ql-picker-item[data-value='6']::before {
        content: 'Heading 6';
    }
    .ql-snow .ql-picker.ql-header .ql-picker-item[data-value='1']::before {
        font-size: 2rem;
    }
    .ql-snow .ql-picker.ql-header .ql-picker-item[data-value='2']::before {
        font-size: 1.5rem;
    }
    .ql-snow .ql-picker.ql-header .ql-picker-item[data-value='3']::before {
        font-size: 1.17rem;
    }
    .ql-snow .ql-picker.ql-header .ql-picker-item[data-value='4']::before {
        font-size: 1rem;
    }
    .ql-snow .ql-picker.ql-header .ql-picker-item[data-value='5']::before {
        font-size: 0.83rem;
    }
    .ql-snow .ql-picker.ql-header .ql-picker-item[data-value='6']::before {
        font-size: 0.67rem;
    }
    .ql-snow .ql-picker.ql-font {
        width: 108px;
    }
    .ql-snow .ql-picker.ql-font .ql-picker-label::before,
    .ql-snow .ql-picker.ql-font .ql-picker-item::before {
        content: 'Sans Serif';
    }
    .ql-snow .ql-picker.ql-font .ql-picker-label[data-value='serif']::before,
    .ql-snow .ql-picker.ql-font .ql-picker-item[data-value='serif']::before {
        content: 'Serif';
    }
    .ql-snow .ql-picker.ql-font .ql-picker-label[data-value='monospace']::before,
    .ql-snow .ql-picker.ql-font .ql-picker-item[data-value='monospace']::before {
        content: 'Monospace';
    }
    .ql-snow .ql-picker.ql-font .ql-picker-item[data-value='serif']::before {
        font-family:
            Georgia,
            Times New Roman,
            serif;
    }
    .ql-snow .ql-picker.ql-font .ql-picker-item[data-value='monospace']::before {
        font-family:
            Monaco,
            Courier New,
            monospace;
    }
    .ql-snow .ql-picker.ql-size {
        width: 98px;
    }
    .ql-snow .ql-picker.ql-size .ql-picker-label::before,
    .ql-snow .ql-picker.ql-size .ql-picker-item::before {
        content: 'Normal';
    }
    .ql-snow .ql-picker.ql-size .ql-picker-label[data-value='small']::before,
    .ql-snow .ql-picker.ql-size .ql-picker-item[data-value='small']::before {
        content: 'Small';
    }
    .ql-snow .ql-picker.ql-size .ql-picker-label[data-value='large']::before,
    .ql-snow .ql-picker.ql-size .ql-picker-item[data-value='large']::before {
        content: 'Large';
    }
    .ql-snow .ql-picker.ql-size .ql-picker-label[data-value='huge']::before,
    .ql-snow .ql-picker.ql-size .ql-picker-item[data-value='huge']::before {
        content: 'Huge';
    }
    .ql-snow .ql-picker.ql-size .ql-picker-item[data-value='small']::before {
        font-size: 10px;
    }
    .ql-snow .ql-picker.ql-size .ql-picker-item[data-value='large']::before {
        font-size: 18px;
    }
    .ql-snow .ql-picker.ql-size .ql-picker-item[data-value='huge']::before {
        font-size: 32px;
    }
    .ql-snow .ql-color-picker.ql-background .ql-picker-item {
        background: #fff;
    }
    .ql-snow .ql-color-picker.ql-color .ql-picker-item {
        background: #000;
    }
    .ql-toolbar.ql-snow {
        border: 1px solid #ccc;
        box-sizing: border-box;
        font-family: 'Helvetica Neue', 'Helvetica', 'Arial', sans-serif;
        padding: 8px;
    }
    .ql-toolbar.ql-snow .ql-formats {
        margin-inline-end: 15px;
    }
    .ql-toolbar.ql-snow .ql-picker-label {
        border: 1px solid transparent;
    }
    .ql-toolbar.ql-snow .ql-picker-options {
        border: 1px solid transparent;
        box-shadow: rgba(0, 0, 0, 0.2) 0 2px 8px;
    }
    .ql-toolbar.ql-snow .ql-picker.ql-expanded .ql-picker-label {
        border-color: #ccc;
    }
    .ql-toolbar.ql-snow .ql-picker.ql-expanded .ql-picker-options {
        border-color: #ccc;
    }
    .ql-toolbar.ql-snow .ql-color-picker .ql-picker-item.ql-selected,
    .ql-toolbar.ql-snow .ql-color-picker .ql-picker-item:hover {
        border-color: #000;
    }
    .ql-toolbar.ql-snow + .ql-container.ql-snow {
        border-block-start: 0;
    }
    .ql-snow .ql-tooltip {
        background: #fff;
        border: 1px solid #ccc;
        box-shadow: 0 0 5px #ddd;
        color: #444;
        padding: 5px 12px;
        white-space: nowrap;
    }
    .ql-snow .ql-tooltip::before {
        content: 'Visit URL:';
        line-height: 26px;
        margin-inline-end: 8px;
    }
    .ql-snow .ql-tooltip input[type='text'] {
        display: none;
        border: 1px solid #ccc;
        font-size: 13px;
        height: 26px;
        margin: 0;
        padding: 3px 5px;
        width: 170px;
    }
    .ql-snow .ql-tooltip a.ql-preview {
        display: inline-block;
        max-width: 200px;
        overflow-x: hidden;
        text-overflow: ellipsis;
        vertical-align: top;
    }
    .ql-snow .ql-tooltip a.ql-action::after {
        border-inline-end: 1px solid #ccc;
        content: 'Edit';
        margin-inline-start: 16px;
        padding-inline-end: 8px;
    }
    .ql-snow .ql-tooltip a.ql-remove::before {
        content: 'Remove';
        margin-inline-start: 8px;
    }
    .ql-snow .ql-tooltip a {
        line-height: 26px;
    }
    .ql-snow .ql-tooltip.ql-editing a.ql-preview,
    .ql-snow .ql-tooltip.ql-editing a.ql-remove {
        display: none;
    }
    .ql-snow .ql-tooltip.ql-editing input[type='text'] {
        display: inline-block;
    }
    .ql-snow .ql-tooltip.ql-editing a.ql-action::after {
        border-inline-end: 0;
        content: 'Save';
        padding-inline-end: 0;
    }
    .ql-snow .ql-tooltip[data-mode='link']::before {
        content: 'Enter link:';
    }
    .ql-snow .ql-tooltip[data-mode='formula']::before {
        content: 'Enter formula:';
    }
    .ql-snow .ql-tooltip[data-mode='video']::before {
        content: 'Enter video:';
    }
    .ql-snow a {
        color: #06c;
    }
    .ql-container.ql-snow {
        border: 1px solid #ccc;
    }

    .p-editor {
        display: block;
    }

    .p-editor .p-editor-toolbar {
        background: dt('editor.toolbar.background');
        border-start-end-radius: dt('editor.toolbar.border.radius');
        border-start-start-radius: dt('editor.toolbar.border.radius');
    }

    .p-editor .p-editor-toolbar.ql-snow {
        border: 1px solid dt('editor.toolbar.border.color');
    }

    .p-editor .p-editor-toolbar.ql-snow .ql-stroke {
        stroke: dt('editor.toolbar.item.color');
    }

    .p-editor .p-editor-toolbar.ql-snow .ql-fill {
        fill: dt('editor.toolbar.item.color');
    }

    .p-editor .p-editor-toolbar.ql-snow .ql-picker .ql-picker-label {
        border: 0 none;
        color: dt('editor.toolbar.item.color');
    }

    .p-editor .p-editor-toolbar.ql-snow .ql-picker .ql-picker-label:hover {
        color: dt('editor.toolbar.item.hover.color');
    }

    .p-editor .p-editor-toolbar.ql-snow .ql-picker .ql-picker-label:hover .ql-stroke {
        stroke: dt('editor.toolbar.item.hover.color');
    }

    .p-editor .p-editor-toolbar.ql-snow .ql-picker .ql-picker-label:hover .ql-fill {
        fill: dt('editor.toolbar.item.hover.color');
    }

    .p-editor .p-editor-toolbar.ql-snow .ql-picker.ql-expanded .ql-picker-label {
        color: dt('editor.toolbar.item.active.color');
    }

    .p-editor .p-editor-toolbar.ql-snow .ql-picker.ql-expanded .ql-picker-label .ql-stroke {
        stroke: dt('editor.toolbar.item.active.color');
    }

    .p-editor .p-editor-toolbar.ql-snow .ql-picker.ql-expanded .ql-picker-label .ql-fill {
        fill: dt('editor.toolbar.item.active.color');
    }

    .p-editor .p-editor-toolbar.ql-snow .ql-picker.ql-expanded .ql-picker-options {
        background: dt('editor.overlay.background');
        border: 1px solid dt('editor.overlay.border.color');
        box-shadow: dt('editor.overlay.shadow');
        border-radius: dt('editor.overlay.border.radius');
        padding: dt('editor.overlay.padding');
    }

    .p-editor .p-editor-toolbar.ql-snow .ql-picker.ql-expanded .ql-picker-options .ql-picker-item {
        color: dt('editor.overlay.option.color');
        border-radius: dt('editor.overlay.option.border.radius');
    }

    .p-editor .p-editor-toolbar.ql-snow .ql-picker.ql-expanded .ql-picker-options .ql-picker-item:hover {
        background: dt('editor.overlay.option.focus.background');
        color: dt('editor.overlay.option.focus.color');
    }

    .p-editor .p-editor-toolbar.ql-snow .ql-picker.ql-expanded:not(.ql-color-picker, .ql-icon-picker) .ql-picker-item {
        padding: dt('editor.overlay.option.padding');
    }

    .p-editor .p-editor-content {
        border-end-end-radius: dt('editor.content.border.radius');
        border-end-start-radius: dt('editor.content.border.radius');
    }

    .p-editor .p-editor-content.ql-snow {
        border: 1px solid dt('editor.content.border.color');
    }

    .p-editor .p-editor-content .ql-editor {
        background: dt('editor.content.background');
        color: dt('editor.content.color');
        border-end-end-radius: dt('editor.content.border.radius');
        border-end-start-radius: dt('editor.content.border.radius');
    }

    .p-editor .ql-snow.ql-toolbar button:hover,
    .p-editor .ql-snow.ql-toolbar button:focus {
        color: dt('editor.toolbar.item.hover.color');
    }

    .p-editor .ql-snow.ql-toolbar button:hover .ql-stroke,
    .p-editor .ql-snow.ql-toolbar button:focus .ql-stroke {
        stroke: dt('editor.toolbar.item.hover.color');
    }

    .p-editor .ql-snow.ql-toolbar button:hover .ql-fill,
    .p-editor .ql-snow.ql-toolbar button:focus .ql-fill {
        fill: dt('editor.toolbar.item.hover.color');
    }

    .p-editor .ql-snow.ql-toolbar button.ql-active,
    .p-editor .ql-snow.ql-toolbar .ql-picker-label.ql-active,
    .p-editor .ql-snow.ql-toolbar .ql-picker-item.ql-selected {
        color: dt('editor.toolbar.item.active.color');
    }

    .p-editor .ql-snow.ql-toolbar button.ql-active .ql-stroke,
    .p-editor .ql-snow.ql-toolbar .ql-picker-label.ql-active .ql-stroke,
    .p-editor .ql-snow.ql-toolbar .ql-picker-item.ql-selected .ql-stroke {
        stroke: dt('editor.toolbar.item.active.color');
    }

    .p-editor .ql-snow.ql-toolbar button.ql-active .ql-fill,
    .p-editor .ql-snow.ql-toolbar .ql-picker-label.ql-active .ql-fill,
    .p-editor .ql-snow.ql-toolbar .ql-picker-item.ql-selected .ql-fill {
        fill: dt('editor.toolbar.item.active.color');
    }

    .p-editor .ql-snow.ql-toolbar button.ql-active .ql-picker-label,
    .p-editor .ql-snow.ql-toolbar .ql-picker-label.ql-active .ql-picker-label,
    .p-editor .ql-snow.ql-toolbar .ql-picker-item.ql-selected .ql-picker-label {
        color: dt('editor.toolbar.item.active.color');
    }
`,X={root:function(o){var t=o.instance;return["p-editor",{"p-invalid":t.$invalid}]},toolbar:"p-editor-toolbar",content:"p-editor-content"},Z=L.extend({name:"editor",style:W,classes:X}),_={name:"BaseEditor",extends:F,props:{placeholder:String,readonly:Boolean,formats:Array,editorStyle:null,modules:null},style:Z,provide:function(){return{$pcEditor:this,$parentInstance:this}}};function x(l){"@babel/helpers - typeof";return x=typeof Symbol=="function"&&typeof Symbol.iterator=="symbol"?function(o){return typeof o}:function(o){return o&&typeof Symbol=="function"&&o.constructor===Symbol&&o!==Symbol.prototype?"symbol":typeof o},x(l)}function P(l,o){var t=Object.keys(l);if(Object.getOwnPropertySymbols){var a=Object.getOwnPropertySymbols(l);o&&(a=a.filter(function(v){return Object.getOwnPropertyDescriptor(l,v).enumerable})),t.push.apply(t,a)}return t}function ll(l){for(var o=1;o<arguments.length;o++){var t=arguments[o]!=null?arguments[o]:{};o%2?P(Object(t),!0).forEach(function(a){el(l,a,t[a])}):Object.getOwnPropertyDescriptors?Object.defineProperties(l,Object.getOwnPropertyDescriptors(t)):P(Object(t)).forEach(function(a){Object.defineProperty(l,a,Object.getOwnPropertyDescriptor(t,a))})}return l}function el(l,o,t){return(o=nl(o))in l?Object.defineProperty(l,o,{value:t,enumerable:!0,configurable:!0,writable:!0}):l[o]=t,l}function nl(l){var o=ol(l,"string");return x(o)=="symbol"?o:o+""}function ol(l,o){if(x(l)!="object"||!l)return l;var t=l[Symbol.toPrimitive];if(t!==void 0){var a=t.call(l,o);if(x(a)!="object")return a;throw new TypeError("@@toPrimitive must return a primitive value.")}return(o==="string"?String:Number)(l)}var C=(function(){try{return window.Quill}catch{return null}})(),E={name:"Editor",extends:_,inheritAttrs:!1,emits:["text-change","selection-change","load"],quill:null,watch:{modelValue:function(o,t){o!==t&&this.quill&&!this.quill.hasFocus()&&this.renderValue(o)},d_value:function(o,t){o!==t&&this.quill&&!this.quill.hasFocus()&&this.renderValue(o)},readonly:function(){this.handleReadOnlyChange()}},mounted:function(){var o=this,t={modules:ll({toolbar:this.$refs.toolbarElement},this.modules),readOnly:this.readonly,theme:"snow",formats:this.formats,placeholder:this.placeholder};C?(this.quill=new C(this.$refs.editorElement,t),this.initQuill(),this.handleLoad()):R(()=>import("./quill-UE7W4a09.js"),__vite__mapDeps([0,1,2])).then(function(a){a&&I(o.$refs.editorElement)&&(a.default?o.quill=new a.default(o.$refs.editorElement,t):o.quill=new a(o.$refs.editorElement,t),o.initQuill())}).then(function(){o.handleLoad()})},beforeUnmount:function(){this.quill=null},methods:{renderValue:function(o){if(this.quill)if(o){var t=this.quill.clipboard.convert({html:o});this.quill.setContents(t)}else this.quill.setText("")},initQuill:function(){var o=this;this.renderValue(this.d_value),this.quill.on("text-change",function(t,a,v){if(v==="user"){var w=o.quill.getSemanticHTML(),f=o.quill.getText().trim();w==="<p><br></p>"&&(w=""),o.writeValue(w),o.$emit("text-change",{htmlValue:w,textValue:f,delta:t,source:v,instance:o.quill})}}),this.quill.on("selection-change",function(t,a,v){var w=o.quill.getSemanticHTML(),f=o.quill.getText().trim();o.$emit("selection-change",{htmlValue:w,textValue:f,range:t,oldRange:a,source:v,instance:o.quill})})},handleLoad:function(){this.quill&&this.quill.getModule("toolbar")&&this.$emit("load",{instance:this.quill})},handleReadOnlyChange:function(){this.quill&&this.quill.enable(!this.readonly)}}};function tl(l,o,t,a,v,w){return p(),b("div",r({class:l.cx("root")},l.ptmi("root")),[e("div",r({ref:"toolbarElement",class:l.cx("toolbar")},l.ptm("toolbar")),[H(l.$slots,"toolbar",{},function(){return[e("span",r({class:"ql-formats"},l.ptm("formats")),[e("select",r({class:"ql-header",defaultValue:"0"},l.ptm("header")),[e("option",r({value:"1"},l.ptm("option")),"Heading",16),e("option",r({value:"2"},l.ptm("option")),"Subheading",16),e("option",r({value:"0"},l.ptm("option")),"Normal",16)],16),e("select",r({class:"ql-font"},l.ptm("font")),[e("option",T(B(l.ptm("option"))),null,16),e("option",r({value:"serif"},l.ptm("option")),null,16),e("option",r({value:"monospace"},l.ptm("option")),null,16)],16)],16),e("span",r({class:"ql-formats"},l.ptm("formats")),[e("button",r({class:"ql-bold",type:"button"},l.ptm("bold")),null,16),e("button",r({class:"ql-italic",type:"button"},l.ptm("italic")),null,16),e("button",r({class:"ql-underline",type:"button"},l.ptm("underline")),null,16)],16),e("span",r({class:"ql-formats"},l.ptm("formats")),[e("select",r({class:"ql-color"},l.ptm("color")),null,16),e("select",r({class:"ql-background"},l.ptm("background")),null,16)],16),e("span",r({class:"ql-formats"},l.ptm("formats")),[e("button",r({class:"ql-list",value:"ordered",type:"button"},l.ptm("list")),null,16),e("button",r({class:"ql-list",value:"bullet",type:"button"},l.ptm("list")),null,16),e("select",r({class:"ql-align"},l.ptm("select")),[e("option",r({defaultValue:""},l.ptm("option")),null,16),e("option",r({value:"center"},l.ptm("option")),null,16),e("option",r({value:"right"},l.ptm("option")),null,16),e("option",r({value:"justify"},l.ptm("option")),null,16)],16)],16),e("span",r({class:"ql-formats"},l.ptm("formats")),[e("button",r({class:"ql-link",type:"button"},l.ptm("link")),null,16),e("button",r({class:"ql-image",type:"button"},l.ptm("image")),null,16),e("button",r({class:"ql-code-block",type:"button"},l.ptm("codeBlock")),null,16)],16),e("span",r({class:"ql-formats"},l.ptm("formats")),[e("button",r({class:"ql-clean",type:"button"},l.ptm("clean")),null,16)],16)]})],16),e("div",r({ref:"editorElement",class:l.cx("content"),style:l.editorStyle},l.ptm("content")),null,16)],16)}E.render=tl;const rl={class:"rounded-xl border bg-card p-4 shadow-sm sm:p-6"},il={class:"grid gap-4 sm:grid-cols-2 sm:gap-5"},sl={key:0,class:"mt-1 text-xs text-destructive"},al={key:0,class:"mt-1 text-xs text-destructive"},ql={class:"sm:col-span-2"},dl={key:0,class:"mt-1 text-xs text-destructive"},cl={key:0},pl={class:"flex h-10 w-full items-center rounded-md border bg-muted/40 px-3 font-mono text-sm tracking-widest text-muted-foreground select-all"},ul={class:"rounded-xl border bg-card p-4 shadow-sm sm:p-6"},bl={key:0},ml={key:0,class:"mt-1 text-xs text-destructive"},fl={key:0,class:"mt-1 text-xs text-destructive"},kl={key:0,class:"mt-1 text-xs text-destructive"},wl={key:0,class:"rounded-xl border bg-card p-4 shadow-sm sm:p-6"},vl={class:"grid gap-4 sm:grid-cols-3 sm:gap-5"},gl={class:"flex items-center gap-3 sm:pt-6"},hl={class:"rounded-xl border bg-card p-4 shadow-sm sm:p-6"},xl={class:"flex flex-col gap-4 sm:flex-row sm:items-start sm:gap-6"},yl={class:"flex h-28 w-28 shrink-0 items-center justify-center self-center overflow-hidden rounded-xl border-2 border-dashed bg-muted/40 sm:h-36 sm:w-36 sm:self-start"},zl=["src"],$l={class:"flex flex-col gap-3"},Sl={class:"flex flex-wrap items-center gap-2"},Vl={class:"cursor-pointer"},jl={class:"inline-flex items-center gap-1.5 rounded-md border bg-background px-3 py-2 text-sm font-medium shadow-sm transition-colors hover:bg-muted"},Ol={key:0,class:"text-xs text-destructive"},Pl={class:"rounded-xl border bg-card p-4 shadow-sm sm:p-6"},Cl={class:"hidden items-center justify-between sm:flex"},El={href:"/produits"},Ml=G({__name:"ProduitForm",props:{form:{},errors:{},types:{},statuts:{},processing:{type:Boolean},currentImageUrl:{},currentCodeInterne:{}},emits:["submit"],setup(l,{emit:o}){const t=l,a=o,v=$(()=>!["service"].includes(t.form.type)),w=$(()=>t.form.type==="fabricable"),f=D(null);function U(u){const n=u.target.files?.[0]??null;f.value&&URL.revokeObjectURL(f.value),f.value=n?URL.createObjectURL(n):null,a("update:form",{...t.form,image:n})}function N(){f.value&&URL.revokeObjectURL(f.value),f.value=null,a("update:form",{...t.form,image:null})}const z=$(()=>f.value??t.currentImageUrl??null);return(u,n)=>(p(),b("form",{id:"produit-form",class:"space-y-4 sm:space-y-8",onSubmit:n[12]||(n[12]=Q(q=>a("submit"),["prevent"]))},[e("div",rl,[n[18]||(n[18]=e("h3",{class:"mb-4 text-xs font-semibold tracking-wider text-muted-foreground uppercase sm:mb-5"}," Identification ",-1)),e("div",il,[e("div",null,[s(i(m),{class:"mb-1.5 block"},{default:c(()=>[...n[13]||(n[13]=[d("Type ",-1),e("span",{class:"text-destructive"},"*",-1)])]),_:1}),s(i(V),{"model-value":l.form.type,"onUpdate:modelValue":n[0]||(n[0]=q=>u.$emit("update:form",{...l.form,type:q})),options:l.types,"option-label":"label","option-value":"value",placeholder:"Sélectionner un type",class:y(["w-full",{"p-invalid":l.errors.type}])},null,8,["model-value","options","class"]),l.errors.type?(p(),b("p",sl,g(l.errors.type),1)):k("",!0)]),e("div",null,[s(i(m),{class:"mb-1.5 block"},{default:c(()=>[...n[14]||(n[14]=[d("Statut ",-1),e("span",{class:"text-destructive"},"*",-1)])]),_:1}),s(i(V),{"model-value":l.form.statut,"onUpdate:modelValue":n[1]||(n[1]=q=>u.$emit("update:form",{...l.form,statut:q})),options:l.statuts,"option-label":"label","option-value":"value",placeholder:"Sélectionner un statut",class:y(["w-full",{"p-invalid":l.errors.statut}])},null,8,["model-value","options","class"]),l.errors.statut?(p(),b("p",al,g(l.errors.statut),1)):k("",!0)]),e("div",ql,[s(i(m),{for:"nom",class:"mb-1.5 block"},{default:c(()=>[...n[15]||(n[15]=[d("Nom du produit ",-1),e("span",{class:"text-destructive"},"*",-1)])]),_:1}),s(i(j),{id:"nom","model-value":l.form.nom,"onUpdate:modelValue":n[2]||(n[2]=q=>u.$emit("update:form",{...l.form,nom:q})),class:y(["w-full",{"p-invalid":l.errors.nom}])},null,8,["model-value","class"]),l.errors.nom?(p(),b("p",dl,g(l.errors.nom),1)):k("",!0)]),l.currentCodeInterne?(p(),b("div",cl,[s(i(m),{class:"mb-1.5 block"},{default:c(()=>[...n[16]||(n[16]=[d("Code-barres (Code 128)",-1)])]),_:1}),e("div",pl,g(l.currentCodeInterne),1)])):k("",!0),e("div",null,[s(i(m),{for:"code_fournisseur",class:"mb-1.5 block"},{default:c(()=>[...n[17]||(n[17]=[d("Code fournisseur",-1)])]),_:1}),s(i(j),{id:"code_fournisseur","model-value":l.form.code_fournisseur??"","onUpdate:modelValue":n[3]||(n[3]=q=>u.$emit("update:form",{...l.form,code_fournisseur:q||null})),class:"w-full font-mono"},null,8,["model-value"])])])]),e("div",ul,[n[23]||(n[23]=e("h3",{class:"mb-4 text-xs font-semibold tracking-wider text-muted-foreground uppercase sm:mb-5"},[d(" Tarification "),e("span",{class:"text-xs font-normal normal-case"},"(GNF)")],-1)),e("div",{class:y(["grid gap-4 sm:grid-cols-2 sm:gap-5",w.value?"lg:grid-cols-4":"lg:grid-cols-3"])},[w.value?(p(),b("div",bl,[s(i(m),{class:"mb-1.5 block"},{default:c(()=>[...n[19]||(n[19]=[d("Prix usine",-1)])]),_:1}),s(i(h),{"model-value":l.form.prix_usine,"onUpdate:modelValue":n[4]||(n[4]=q=>u.$emit("update:form",{...l.form,prix_usine:q})),min:0,"use-grouping":!0,locale:"fr-GN",class:"w-full","input-class":"w-full"},null,8,["model-value"]),l.errors.prix_usine?(p(),b("p",ml,g(l.errors.prix_usine),1)):k("",!0)])):k("",!0),e("div",null,[s(i(m),{class:"mb-1.5 block"},{default:c(()=>[...n[20]||(n[20]=[d("Prix achat",-1)])]),_:1}),s(i(h),{"model-value":l.form.prix_achat,"onUpdate:modelValue":n[5]||(n[5]=q=>u.$emit("update:form",{...l.form,prix_achat:q})),min:0,"use-grouping":!0,locale:"fr-GN",class:"w-full","input-class":"w-full"},null,8,["model-value"]),l.errors.prix_achat?(p(),b("p",fl,g(l.errors.prix_achat),1)):k("",!0)]),e("div",null,[s(i(m),{class:"mb-1.5 block"},{default:c(()=>[...n[21]||(n[21]=[d("Prix vente",-1)])]),_:1}),s(i(h),{"model-value":l.form.prix_vente,"onUpdate:modelValue":n[6]||(n[6]=q=>u.$emit("update:form",{...l.form,prix_vente:q})),min:0,"use-grouping":!0,locale:"fr-GN",class:"w-full","input-class":"w-full"},null,8,["model-value"]),l.errors.prix_vente?(p(),b("p",kl,g(l.errors.prix_vente),1)):k("",!0)]),e("div",null,[s(i(m),{class:"mb-1.5 block"},{default:c(()=>[...n[22]||(n[22]=[d("Coût de revient",-1)])]),_:1}),s(i(h),{"model-value":l.form.cout,"onUpdate:modelValue":n[7]||(n[7]=q=>u.$emit("update:form",{...l.form,cout:q})),min:0,"use-grouping":!0,locale:"fr-GN",class:"w-full","input-class":"w-full"},null,8,["model-value"])])],2)]),v.value?(p(),b("div",wl,[n[29]||(n[29]=e("h3",{class:"mb-4 text-xs font-semibold tracking-wider text-muted-foreground uppercase sm:mb-5"}," Stock ",-1)),e("div",vl,[e("div",null,[s(i(m),{class:"mb-1.5 block"},{default:c(()=>[...n[24]||(n[24]=[d("Quantité en stock",-1)])]),_:1}),s(i(h),{"model-value":l.form.qte_stock,"onUpdate:modelValue":n[8]||(n[8]=q=>u.$emit("update:form",{...l.form,qte_stock:q??0})),min:0,class:"w-full","input-class":"w-full"},null,8,["model-value"])]),e("div",null,[s(i(m),{class:"mb-1.5 block"},{default:c(()=>[...n[25]||(n[25]=[d("Seuil d'alerte stock",-1)])]),_:1}),s(i(h),{"model-value":l.form.seuil_alerte_stock,"onUpdate:modelValue":n[9]||(n[9]=q=>u.$emit("update:form",{...l.form,seuil_alerte_stock:q})),min:0,class:"w-full","input-class":"w-full"},null,8,["model-value"]),n[26]||(n[26]=e("p",{class:"mt-1 text-xs text-muted-foreground"}," Laisser vide pour utiliser le seuil global ",-1))]),e("div",gl,[s(i(A),{id:"is_critique","model-value":!!l.form.is_critique,"onUpdate:modelValue":n[10]||(n[10]=q=>u.$emit("update:form",{...l.form,is_critique:q===!0}))},null,8,["model-value"]),e("div",null,[s(i(m),{for:"is_critique",class:"cursor-pointer font-medium"},{default:c(()=>[...n[27]||(n[27]=[d("Produit critique",-1)])]),_:1}),n[28]||(n[28]=e("p",{class:"text-xs text-muted-foreground"}," Déclenche une alerte en cas de rupture ",-1))])])])])):k("",!0),e("div",hl,[n[33]||(n[33]=e("h3",{class:"mb-4 text-xs font-semibold tracking-wider text-muted-foreground uppercase sm:mb-5"}," Image du produit ",-1)),e("div",xl,[e("div",yl,[z.value?(p(),b("img",{key:0,src:z.value,alt:"Aperçu",class:"h-full w-full object-cover"},null,8,zl)):(p(),M(i(O),{key:1,class:"h-10 w-10 text-muted-foreground/40"}))]),e("div",$l,[s(i(m),{class:"block text-sm text-muted-foreground"},{default:c(()=>[...n[30]||(n[30]=[d(" Formats acceptés : JPG, PNG, WEBP — max 2 Mo ",-1)])]),_:1}),e("div",Sl,[e("label",Vl,[e("input",{type:"file",accept:"image/jpeg,image/png,image/webp",class:"sr-only",onChange:U},null,32),e("span",jl,[s(i(O),{class:"h-4 w-4"}),n[31]||(n[31]=d(" Choisir une image ",-1))])]),z.value?(p(),b("button",{key:0,type:"button",onClick:N,class:"inline-flex items-center gap-1.5 rounded-md px-3 py-2 text-sm text-destructive transition-colors hover:bg-destructive/10"},[s(i(K),{class:"h-4 w-4"}),n[32]||(n[32]=d(" Supprimer ",-1))])):k("",!0)]),l.errors.image?(p(),b("p",Ol,g(l.errors.image),1)):k("",!0)])])]),e("div",Pl,[n[35]||(n[35]=e("h3",{class:"mb-4 text-xs font-semibold tracking-wider text-muted-foreground uppercase sm:mb-5"}," Description ",-1)),s(i(E),{"model-value":l.form.description??"","onUpdate:modelValue":n[11]||(n[11]=q=>u.$emit("update:form",{...l.form,description:q||null})),"editor-style":"min-height: 140px",class:"w-full"},{toolbar:c(()=>[...n[34]||(n[34]=[e("span",{class:"ql-formats"},[e("button",{class:"ql-bold"}),e("button",{class:"ql-italic"}),e("button",{class:"ql-underline"})],-1),e("span",{class:"ql-formats"},[e("button",{class:"ql-list",value:"bullet"}),e("button",{class:"ql-list",value:"ordered"})],-1),e("span",{class:"ql-formats"},[e("button",{class:"ql-link"}),e("button",{class:"ql-clean"})],-1)])]),_:1},8,["model-value"])]),e("div",Cl,[e("a",El,[s(i(S),{type:"button",variant:"outline"},{default:c(()=>[...n[36]||(n[36]=[d(" Retour ",-1)])]),_:1})]),s(i(S),{type:"submit",disabled:l.processing},{default:c(()=>[s(i(Y),{class:"mr-2 h-4 w-4"}),d(" "+g(l.processing?"Enregistrement…":"Enregistrer"),1)]),_:1},8,["disabled"])]),n[37]||(n[37]=e("div",{class:"h-20 sm:hidden"},null,-1))],32))}});export{Ml as _};
