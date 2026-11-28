import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { nextDialogTabIndex } from '../src/components/ui/dialogFocus.js';
const read = path => readFileSync(new URL('../src/'+path,import.meta.url),'utf8');
const ui = name => read('components/ui/'+name);

test('dialog Tab wrap respects both edges, no candidates and programmatic panel focus',()=>{
 assert.equal(nextDialogTabIndex(0,-1,false),-1);
 assert.equal(nextDialogTabIndex(3,-1,false),0);
 assert.equal(nextDialogTabIndex(3,-1,true),2);
 assert.equal(nextDialogTabIndex(3,0,true),2);
 assert.equal(nextDialogTabIndex(3,2,false),0);
 assert.equal(nextDialogTabIndex(3,1,false),null);
 assert.equal(nextDialogTabIndex(3,1,true),null);
 assert.equal(nextDialogTabIndex(1,0,true),0);
});
test('native shared dialogs retain close guards, nested focus boundaries and caller footer contract',()=>{
 const legacy=ui('Modal.jsx'),workspace=ui('WorkspaceModal.jsx'),picker=read('pages/schedules/components/SchedulePicker.jsx'),focus=ui('dialogFocus.js');
 assert.match(legacy,/node\.showModal\(\)/);assert.match(legacy,/event\.target !== event\.currentTarget/);assert.match(legacy,/event\.preventDefault\(\)/);assert.match(legacy,/onCloseRef\.current\?\.\(\)/);assert.match(legacy,/\{footer\}/);assert.match(legacy,/useId\(\)/);assert.match(legacy,/previousActiveElement\?\.isConnected/);
 assert.match(workspace,/if\(!busy\)onClose\(\)/);assert.match(picker,/e\.stopPropagation\(\)/);assert.match(focus,/node\.closest\('dialog'\) === dialog/);assert.match(focus,/!node\.matches\(':disabled'\)/);
});
test('each state imports CSS without relying on another lazy module',()=>{
 for(const n of ['EmptyState','LoadingState','ErrorState','UnauthorizedState','ForbiddenState']) assert.match(ui(n+'.jsx'),/import ['"]\.\/State\.css['"]/);
});
test('shared form IDs are unique by fallback and error only refers to visible text',()=>{
 for(const n of ['Input','Select']){const s=ui(n+'.jsx');assert.match(s,/const generatedId = useId\(\)/);assert.match(s,/explicitId \|\| generatedId/);assert.match(s,/hint && !error && `\$\{id\}-hint`/);assert.match(s,/htmlFor=\{id\}/);assert.match(s,/aria-invalid=\{Boolean\(error\)\}/);}
});
test('mobile controls use scoped touch tokens and header wraps without hiding actions',()=>{
 assert.match(read('styles/variables.css'),/--ui-touch: 44px/);assert.match(ui('PageHeader.css'),/flex-direction:column/);assert.match(ui('PageHeader.css'),/flex-wrap:wrap/);
 for(const n of ['Input','Select','Button','Pagination','Modal','State'])assert.match(ui(n+'.css'),/var\(--ui-touch\)/);
 assert.match(ui('Input.css'),/font-size:16px/);assert.match(ui('Select.css'),/font-size:16px/);
});
test('toast keeps error and warning visible and waits while native modal is active',()=>{
 const s=ui('ToastNotification.jsx');assert.match(s,/type==='error'\|\|type==='warning'\|\|hovered\|\|focused\|\|modalOpen/);assert.match(s,/document\.querySelector\('dialog:modal'\)/);assert.match(s,/observer\.disconnect\(\)/);assert.match(s,/closeRef\.current\?\.\(\)/);
});
