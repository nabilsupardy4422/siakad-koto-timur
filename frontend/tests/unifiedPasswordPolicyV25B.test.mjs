import test from 'node:test'
import assert from 'node:assert/strict'
import {readFileSync} from 'node:fs'
import {isStrongPassword,passwordChecks} from '../src/components/security/passwordPolicy.js'
const source = p=>readFileSync(new URL('../src/'+p,import.meta.url),'utf8')
test('minimum length, mixed case, numbers and symbol are mandatory',()=>{
 for(const p of ['Ab1!short','abcdefghij1!','ABCDEFGHIJ1!','Abcdefghijk!','Abcdefghij1 ']) assert.equal(isStrongPassword(p),false)
 assert.equal(isStrongPassword('Abcdefghij1!'),true)
})
test('UTF-8 byte boundary and Unicode categories match intended policy',()=>{
 assert.equal(isStrongPassword('Ab1!'+'é'.repeat(34)),true)
 assert.equal(isStrongPassword('Ab1!'+'é'.repeat(35)),false)
 assert.equal(isStrongPassword('ÉcoleSécurité١!'),true)
 assert.equal(isStrongPassword('Abcdefghij1!\u0000'),false)
 assert.equal(isStrongPassword('Abcdefghij1!\n'),false)
 assert.equal(isStrongPassword('Abcdefghij1!\ud800'),false)
})
test('confirmation is separate and required when provided',()=>{
 const p='Abcdefghij1!';assert.equal(passwordChecks(p).length,5)
 assert.equal(passwordChecks(p,'').every(x=>x.ok),false)
 assert.equal(passwordChecks(p,p).every(x=>x.ok),true)
})
test('activation and Google recovery mount shared requirements and enforce new password policy',()=>{
 for(const p of ['pages/public/ActivateAccountPage.jsx','pages/auth/LoginPage.jsx']){const s=source(p);assert.match(s,/import PasswordRequirements/);assert.match(s,/<PasswordRequirements password=/);assert.match(s,/if\s*\(\s*!isStrongPassword\(/)}
})
test('admin create and reset share rules, with explicit confirmation for creation',()=>{
 const s=source('pages/users/UsersPage.jsx');assert.match(s,/isStrongPassword\(form.password\)/);assert.match(s,/isStrongPassword\(resetForm.password\)/);assert.match(s,/payload.password_confirmation = form.password_confirmation/);assert.equal((s.match(/<PasswordRequirements password=/g)||[]).length,2)
})
test('self change shares policy and ordinary login does not apply creation checks',()=>{
 assert.match(source('pages/profile/personalProfileLogic.js'),/export \{ passwordChecks \} from/);assert.match(source('pages/profile/PersonalProfileWorkspace.jsx'),/<PasswordRequirements password=/)
 const s=source('pages/auth/LoginPage.jsx');assert.equal((s.match(/!isStrongPassword\(/g)||[]).length,1);assert.match(s,/!isStrongPassword\(resetPassword\)/)
})
