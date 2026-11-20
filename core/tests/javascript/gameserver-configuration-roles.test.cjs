'use strict';
const {test}=require('node:test'),assert=require('node:assert/strict'),fs=require('node:fs'),vm=require('node:vm');
const source=fs.readFileSync(require('node:path').join(__dirname,'../../public/js/gameserver/configuration-roles.js'),'utf8');
class Element {
    constructor(tag){this.tag=tag;this.children=[];this.dataset={};this.listeners={};this.attributes={};this.value='';}
    append(...nodes){for(const node of nodes){node.parent=this;this.children.push(node);}}
    replaceChildren(...nodes){this.children=[];this.append(...nodes);}
    setAttribute(key,value){this.attributes[key]=value;}
    addEventListener(type,fn){this.listeners[type]=fn;}
    dispatchEvent(event){return this.listeners[event.type]?.(event);}
    closest(tag){return this.tag===tag?this:this.parent?.closest(tag);}
    querySelectorAll(selector){const conditions=[...selector.matchAll(/\[data-([\w-]+)(?:="([^"]*)")?\]/g)];const matches=node=>conditions.every(([,key,value])=>{const name=key.replace(/-([a-z])/g,(_,c)=>c.toUpperCase());return value===undefined?name in node.dataset:node.dataset[name]===value;});return this.children.flatMap(child=>[...(matches(child)?[child]:[]),...child.querySelectorAll(selector)]);}
    querySelector(selector){return this.querySelectorAll(selector)[0]||null;}
    setCustomValidity(message){this.error=message;}
    focus(){this.focused=true;}
    reportValidity(){return !this.error;}
}

const definition={editable:true,message:'',max_roles:128,max_slots:12,slot_count:8,entries:[{index:0,name:'Admin',public_access:false},{index:1,name:'Guest',public_access:true}]};
function setup(data=definition){
    const context={window:{crypto:{getRandomValues:array=>array.fill(42)}},document:{createElement:tag=>new Element(tag)},Uint8Array};vm.runInNewContext(source,context);
    const mount=new Element('section'),pending=new Map();let manager;manager=context.window.EasyWIGameConfigurationRoles(mount,()=>manager?.setBusy(false),()=>pending);manager.load(data);
    const field=(id,key)=>mount.querySelector(`[data-role-draft="${id}"][data-role-field="${key}"]`);
    return {manager,mount,pending,field,add(){mount.querySelector('[data-role-control="add"]').dispatchEvent({type:'click'});},change(id,key,value){const input=field(id,key);assert.ok(input);if(input.type==='checkbox')input.checked=value;else input.value=value;input.dispatchEvent({type:'input'});},remove(index,value=true){const input=mount.querySelector(`[data-role-index="${index}"]`);input.checked=value;input.dispatchEvent({type:'input'});},changes:()=>JSON.parse(JSON.stringify(manager.changes()))};
}
test('draft creation is deliberate, has no privileges and requires a password or public consent',()=>{
    const app=setup();assert.equal(app.manager.dirtyCount(),0);app.add();assert.equal(app.manager.dirtyCount(),1);assert.equal(app.manager.validate(),false);
    app.change(0,'name','Builders');assert.equal(app.manager.validate(),false);assert.equal(app.field(0,'password').focused,true);
    app.change(0,'allowPublicAccess',true);assert.equal(app.manager.validate(),true);
    const role=app.changes().add[0];assert.equal(role.password,'');assert.equal(role.canKickBan,false);assert.equal(role.canEditBase,false);assert.equal(role.reservedSlots,0);assert.equal(role.allowPublicAccess,true);assert.equal('id' in role,false);
    app.change(0,'password',' secret ');app.change(0,'canEditBase',true);assert.equal(app.changes().add[0].password,' secret ');assert.equal(app.changes().add[0].canEditBase,true);
});
test('removals are reversible and retain original indices alongside new drafts',()=>{
    const app=setup();app.remove(0);assert.deepEqual(app.changes(),{add:[],remove:[0]});app.remove(0,false);assert.equal(app.manager.dirtyCount(),0);
    app.remove(0);app.remove(1);assert.equal(app.manager.validate(),false);app.add();app.change(0,'name','Replacement');app.change(0,'password','new');assert.equal(app.manager.validate(),true);assert.deepEqual(app.changes().remove,[0,1]);
    app.manager.load(definition);assert.equal(app.manager.dirtyCount(),0);
});
test('reserved places use the pending player limit without altering booked capacity',()=>{
    const app=setup();app.add();app.change(0,'name','Name');app.change(0,'password','new');app.change(0,'reservedSlots','9');assert.equal(app.manager.validate(),false);
    app.pending.set('slotCount','10');app.manager.setBusy(false);assert.equal(app.field(0,'reservedSlots').max,'10');assert.equal(app.manager.validate(),true);
    app.pending.set('slotCount','13');assert.equal(app.manager.validate(),false);app.pending.set('slotCount','4');app.change(0,'reservedSlots','1.5');assert.equal(app.manager.validate(),false);app.change(0,'reservedSlots','4');assert.equal(app.manager.validate(),true);
});
test('busy controls preserve drafts and role labels follow staged scalar names',()=>{
    const app=setup();app.add();app.change(0,'name','Unsent');app.pending.set('userGroups.0.name','Owner');app.manager.setBusy(true);assert.equal(app.field(0,'name').disabled,true);assert.equal(app.mount.querySelector('[data-role-name="0"]').textContent,'Owner');assert.equal(app.changes().add[0].name,'Unsent');
    app.manager.setBusy(false);assert.equal(app.field(0,'name').disabled,false);
});
test('generated passwords use cryptographic randomness and remain drafts',()=>{
    const app=setup();app.add();app.mount.querySelector('[data-role-control="generate"]').dispatchEvent({type:'click'});assert.equal(app.changes().add[0].password,'2a'.repeat(16));assert.equal(app.field(0,'password').type,'password');assert.equal(app.manager.dirtyCount(),1);
});
test('legacy or malformed roles are read only and unrelated files reset the manager',()=>{
    const app=setup({...definition,editable:false,message:'Start game to migrate old password'});assert.equal(app.mount.querySelectorAll('[data-role-control]').length,0);assert.equal(app.manager.dirtyCount(),0);app.manager.load(null);assert.equal(app.mount.hidden,true);assert.deepEqual(app.changes(),{add:[],remove:[]});
});
