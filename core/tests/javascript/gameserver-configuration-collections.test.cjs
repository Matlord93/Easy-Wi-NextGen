'use strict';
const {test}=require('node:test'),assert=require('node:assert/strict'),fs=require('node:fs'),vm=require('node:vm');
const source=fs.readFileSync(require('node:path').join(__dirname,'../../public/js/gameserver/configuration-collections.js'),'utf8');
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
const definitions=[
    {key:'VBloodUnitSettings',label:'Bosses',kind:'bosses',editable:true,message:'',items:[{id:-1,label:'Wolf',selected:false,level:0,unlocked:false},{id:123,label:'Unknown mod boss',selected:true,level:20,unlocked:true}]},
    {key:'UnlockedResearchs',label:'Research',kind:'ids',editable:true,message:'',items:[{id:-495424062,label:'Tier 1',selected:false,level:0,unlocked:false}]},
];
function setup(data=definitions){
    const context={window:{},document:{createElement:tag=>new Element(tag)},Event:class{constructor(type){this.type=type;}}};vm.runInNewContext(source,context);
    const mount=new Element('section');let manager;manager=context.window.EasyWIGameConfigurationLists(mount,()=>manager?.setBusy(false));manager.load(data);
    const control=(list,id,name)=>mount.querySelector(`[data-list="${list}"][data-id="${id}"][data-list-control="${name}"]`);
    return {manager,mount,control,change(list,id,name,value){const input=control(list,id,name);assert.ok(input);if(input.type==='checkbox')input.checked=value;else input.value=value;input.dispatchEvent({type:'input'});},changes:()=>JSON.parse(JSON.stringify(manager.changes()))};
}
test('individual boss and research changes retain native JSON types',()=>{
    const app=setup();assert.equal(app.manager.dirtyCount(),0);assert.equal(app.control('VBloodUnitSettings',-1,'level').disabled,true);
    app.change('VBloodUnitSettings',-1,'selected',true);assert.equal(app.control('VBloodUnitSettings',-1,'level').disabled,false);
    app.change('VBloodUnitSettings',-1,'level','255');app.change('VBloodUnitSettings',-1,'unlocked',true);app.change('UnlockedResearchs',-495424062,'selected',true);
    assert.equal(app.manager.validate(),true);assert.deepEqual(app.changes(),{VBloodUnitSettings:{upsert:[{UnitId:-1,UnitLevel:255,DefaultUnlocked:true}],remove:[]},UnlockedResearchs:{add:[-495424062],remove:[]}});
    app.manager.setBusy(true);assert.equal(app.control('VBloodUnitSettings',-1,'selected').disabled,true);
});
test('removing an existing override sends its ID without replacing the list',()=>{
    const app=setup();app.change('VBloodUnitSettings',123,'selected',false);assert.deepEqual(app.changes(),{VBloodUnitSettings:{upsert:[],remove:[123]}});
    app.change('VBloodUnitSettings',123,'selected',true);assert.equal(app.manager.dirtyCount(),0);
});
test('invalid boss levels remain dirty while filtering and are revealed on validation',()=>{
    const app=setup();app.change('VBloodUnitSettings',123,'level','256');const search=app.mount.querySelector('[data-list-search="VBloodUnitSettings"]');search.value='no match';search.dispatchEvent({type:'input'});
    assert.equal(app.manager.dirtyCount(),1);assert.equal(app.manager.validate(),false);const input=app.control('VBloodUnitSettings',123,'level');assert.equal(input.closest('details').open,true);assert.equal(input.focused,true);assert.ok(input.error);
    app.change('VBloodUnitSettings',123,'level','0');assert.equal(app.manager.validate(),true);
});
test('malformed lists and other games never become editable collections',()=>{
    const app=setup([{...definitions[0],editable:false,message:'Use the file manager'}]);assert.equal(app.mount.querySelectorAll('[data-list-control]').length,0);assert.deepEqual(app.changes(),{});
    app.manager.load([]);assert.equal(app.mount.hidden,true);assert.equal(app.manager.dirtyCount(),0);
});


test('save refresh keeps open groups and search while switching files resets them',()=>{
    const app=setup();const details=app.mount.querySelector('[data-list-group="VBloodUnitSettings"]');details.open=true;const search=details.querySelector('[data-list-search]');search.value='Wolf';search.dispatchEvent({type:'input'});
    app.manager.load(definitions,true);assert.equal(app.mount.querySelector('[data-list-group="VBloodUnitSettings"]').open,true);assert.equal(app.mount.querySelector('[data-list-search="VBloodUnitSettings"]').value,'Wolf');
    let prevented=false;app.mount.querySelector('[data-list-search="VBloodUnitSettings"]').dispatchEvent({type:'keydown',key:'Enter',preventDefault(){prevented=true;}});assert.equal(prevented,true);
    app.manager.load([]);app.manager.load(definitions);assert.equal(app.mount.querySelector('[data-list-group="VBloodUnitSettings"]').open,false);assert.equal(app.mount.querySelector('[data-list-search="VBloodUnitSettings"]').value,'');
});
