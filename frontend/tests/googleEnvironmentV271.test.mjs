import test from 'node:test'
import assert from 'node:assert/strict'
import {readFileSync} from 'node:fs'
import {getGoogleEnvironment, googleUnavailableMessage} from '../src/utils/googleEnvironment.js'
const id='dummy.apps.googleusercontent.com'
const src=p=>readFileSync(new URL('../src/'+p, import.meta.url),'utf8')
test('local development loopback and eligible HTTPS domain retain Google',()=>{
 for(const origin of ['http://localhost:5173','http://127.0.0.1:5173','https://siakad.example.org','https://siakad.example.org:8443']) assert.deepEqual(getGoogleEnvironment(origin,id),{available:true,reason:null},origin)
})
test('LAN HTTP, raw IPs, unsupported schemes and malformed origins are blocked',()=>{
 for(const origin of ['http://192.168.1.20:5173','http://10.0.0.5:5173','http://172.16.0.1:5173','http://school.local:5173','http://siakad.example.org','https://192.168.1.20','https://8.8.8.8','https://[2001:db8::1]','https://school','file://','null','not a url','https://siakad.example.org/path','https://user@siakad.example.org','http://localhost.evil.example','http://[::1]:5173']) assert.deepEqual(getGoogleEnvironment(origin,id),{available:false,reason:'unsupported_origin'},origin)
})
test('missing configuration stays disabled even at eligible origin',()=>{
 for(const clientId of [undefined,null,'','   ']) assert.deepEqual(getGoogleEnvironment('http://localhost:5173',clientId),{available:false,reason:'not_configured'})
 assert.equal(getGoogleEnvironment('http://192.168.1.20:5173','').reason,'unsupported_origin')
})
test('fallback guidance offers normal login or recovery assistance without security claims',()=>{
 const env=getGoogleEnvironment('http://192.168.1.20:5173',id)
 assert.match(googleUnavailableMessage(env),/username dan password/)
 assert.match(googleUnavailableMessage(env,'recovery'),/Tata Usaha/)
 assert.match(googleUnavailableMessage(getGoogleEnvironment('http://localhost:5173','')),/belum dikonfigurasi/)
 assert.equal(googleUnavailableMessage(getGoogleEnvironment('http://localhost:5173',id)),'')
})
test('login guard covers GIS initialization, callbacks, and recovery challenge without blocking password login',()=>{
 const s=src('pages/auth/LoginPage.jsx');assert.match(s,/getGoogleEnvironment\(window\.location\.origin, GOOGLE_CLIENT_ID\)/)
 assert.match(s,/if \(!googleAvailable\) \{[\s\S]*?return[\s\S]*?post\('\/recovery\/google\/password\/challenge'/)
 assert.match(s,/if \(!googleAvailable \|\| !mountedRef.current \|\| !response\?\.credential/)
 assert.match(s,/status === 'authenticated' \|\|\s*!googleAvailable \|\|[\s\S]*?loadGoogleScript\(\)/)
 assert.match(s,/onSubmit=\{handlePasswordLogin\}/);assert.match(s,/mode === 'password' && \(Boolean\(passwordChallenge\) \|\| !googleAvailable\)/)
 assert.match(s,/if \(cancelled\) return\s*googleCallbackRef/)
})
test('profile guard preserves status and password-authorized unlink independently of GIS',()=>{
 const s=src('pages/profile/ProfilePage.jsx');assert.match(s,/getGoogleEnvironment\(window\.location\.origin, GOOGLE_CLIENT_ID\)/)
 assert.match(s,/!showGoogleButton \|\| !googleAvailable/);assert.match(s,/if \(!googleAvailable \|\| !mountedRef.current \|\| tab !== 'security'/)
 assert.match(s,/disabled=\{working \|\| !googleAvailable\}/);assert.match(s,/googleAccountApi\.status\(\)/);assert.match(s,/googleAccountApi\.unlink\(\s*password/)
 assert.match(s,/if \(!cancelled\) googleCallbackRef.current/);assert.match(s,/Tautan Google yang sudah tersimpan tetap dipertahankan/)
})
