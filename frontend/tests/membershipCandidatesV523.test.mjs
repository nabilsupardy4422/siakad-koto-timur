import test from 'node:test'
import assert from 'node:assert/strict'
import {readFile} from 'node:fs/promises'
const moduleUrl=source=>'data:text/javascript;base64,'+Buffer.from(source).toString('base64')
test('actual class member service exposes all workspace methods and calls authenticated candidate endpoint',async()=>{
 const dir=new URL('../src/services/',import.meta.url)
 const err=moduleUrl(await readFile(new URL('apiError.js',dir),'utf8'))
 const client=moduleUrl((await readFile(new URL('apiClient.js',dir),'utf8')).replace(/(['"])\.\/apiError\1/,JSON.stringify(err)).replaceAll('import.meta.env.','import.meta.env?.'))
 const {classMemberApi}=await import(moduleUrl((await readFile(new URL('classMemberApi.js',dir),'utf8')).replace(/(['"])\.\/apiClient\1/,JSON.stringify(client))))
 for(const method of ['directory','history','previewTransition','commitTransition','candidates','getAll','create','endMembership','downloadTemplate','previewBulk','commitBulk','previewImport','importExcel'])assert.equal(typeof classMemberApi[method],'function',method)
 const oldFetch=globalThis.fetch,oldStorage=globalThis.localStorage,calls=[]
 globalThis.localStorage={getItem:()=> 'test-token'};globalThis.fetch=async(url,opts)=>{calls.push([new URL(url),opts]);return new Response(JSON.stringify({data:[],meta:{total:0}}),{headers:{'content-type':'application/json'}})}
 try{
  await classMemberApi.directory(5); await classMemberApi.history(6);await classMemberApi.candidates(8,{tanggal_mulai:'2026-11-27',search:'009 & A',page:2,per_page:15})
  assert.equal(calls[0][0].pathname,'/api/membership-directory');assert.equal(calls[0][0].searchParams.get('academic_year_id'),'5');assert.equal(calls[1][0].pathname,'/api/membership-history/6')
  const [url,opts]=calls[2];assert.equal(url.pathname,'/api/classes/8/students/candidates');assert.equal(url.searchParams.get('tanggal_mulai'),'2026-11-27');assert.equal(url.searchParams.get('search'),'009 & A');assert.equal(url.searchParams.get('page'),'2');assert.equal(opts.method,'GET');assert.equal(opts.headers.Authorization,'Bearer test-token')
 }finally{globalThis.fetch=oldFetch;if(oldStorage===undefined)delete globalThis.localStorage;else globalThis.localStorage=oldStorage}
})
