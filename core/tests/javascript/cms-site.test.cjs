const {test}=require('node:test'),assert=require('node:assert/strict'),vm=require('node:vm'),fs=require('node:fs'),path=require('node:path');
const source=fs.readFileSync(path.join(__dirname,'../../public/js/cms-site.js'),'utf8');
function setup(present=true){
 const documentEvents={},windowEvents={},buttonEvents={},classes=new Set(),attributes={},body={dataset:{}},focus={toggle:0,account:0};
 const toggle={setAttribute:(key,value)=>attributes[key]=value,addEventListener:(name,fn)=>buttonEvents[name]=fn,focus:()=>focus.toggle++};
 const navigation={classList:{toggle:key=>{if(classes.has(key)){classes.delete(key);return false;}classes.add(key);return true;},remove:key=>classes.delete(key),contains:key=>classes.has(key)}};
 const account={open:false,querySelector:()=>({focus:()=>focus.account++})};
 const context={innerWidth:390,document:{body,querySelector:()=>present?toggle:null,getElementById:()=>present?navigation:null,addEventListener:(name,fn)=>documentEvents[name]=fn,querySelectorAll:()=>account.open?[account]:[]},window:{addEventListener:(name,fn)=>windowEvents[name]=fn}};
 vm.runInNewContext(source,context);
 return {context,body,attributes,classes,focus,account,click:()=>buttonEvents.click(),key:key=>documentEvents.keydown({key}),resize:width=>{context.innerWidth=width;windowEvents.resize();},outside:()=>documentEvents.click({target:{closest:()=>null}}),link:()=>documentEvents.click({target:{closest:selector=>selector==='#cms-site-navigation a'?{}:null}})};
}
test('pages without a header retain a usable no-script layout',()=>{assert.doesNotThrow(()=>setup(false));assert.equal(setup(false).body.dataset.cmsReady,undefined);});
test('the menu button exposes its open state to assistive technology',()=>{const s=setup();s.click();assert.equal(s.attributes['aria-expanded'],'true');assert.ok(s.classes.has('is-open'));s.click();assert.equal(s.attributes['aria-expanded'],'false');assert.equal(s.classes.has('is-open'),false);});
test('Escape closes an open navigation and returns focus to its button',()=>{const s=setup();s.click();s.key('Escape');assert.equal(s.attributes['aria-expanded'],'false');assert.equal(s.focus.toggle,1);s.key('Escape');assert.equal(s.focus.toggle,1);});
test('following a navigation link closes the mobile list',()=>{const s=setup();s.click();s.link();assert.equal(s.attributes['aria-expanded'],'false');assert.equal(s.focus.toggle,0);});
test('desktop resizing resets the menu while mobile resizing preserves it',()=>{const s=setup();s.click();s.resize(700);assert.equal(s.attributes['aria-expanded'],'true');s.resize(1200);assert.equal(s.attributes['aria-expanded'],'false');});
test('account menus close on an outside click and return focus on Escape',()=>{const s=setup();s.account.open=true;s.outside();assert.equal(s.account.open,false);s.account.open=true;s.key('Escape');assert.equal(s.account.open,false);assert.equal(s.focus.account,1);});
