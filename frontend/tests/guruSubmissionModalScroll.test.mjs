import test from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'

const css = readFileSync(new URL('../src/pages/assignments/styles/GuruAssignmentSubmissions.css', import.meta.url), 'utf8')
const rules = (selector) => {
  const escaped = selector.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')
  const matches = [...css.matchAll(new RegExp(`${escaped}\\s*\\{([^{}]*)\\}`, 'g'))]
  return matches.map(match => match[1]).join('\n')
}

test('modal Guru uses a constrained split layout instead of clipping long answers', () => {
  const declaration = rules('.siakad-workspace-modal.gs-workspace .gs-split')
  assert.match(declaration, /height\s*:\s*clamp\(/)
  assert.match(declaration, /min-height\s*:\s*0/)
})

test('student detail scrolls independently when attachments or metadata are expanded', () => {
  const declaration = rules('.siakad-workspace-modal.gs-workspace .gs-detail')
  assert.match(declaration, /overflow-y\s*:\s*auto/)
  assert.match(declaration, /height\s*:\s*100%/)
  assert.match(declaration, /box-sizing\s*:\s*border-box/)
  assert.match(declaration, /overscroll-behavior\s*:\s*contain/)
})

test('long roster has its own scroll without moving the detail column', () => {
  const declaration = rules('.siakad-workspace-modal.gs-workspace .gs-students')
  assert.match(declaration, /overflow-y\s*:\s*auto/)
  assert.match(rules('.siakad-workspace-modal.gs-workspace .gs-list'), /minmax\(0,\s*1fr\)/)
})

test('modal body remains scrollable on short screens and has no horizontal overflow', () => {
  const declaration = rules('.siakad-workspace-modal.gs-workspace .wm-body')
  assert.match(declaration, /overflow-y\s*:\s*auto/)
  assert.match(declaration, /overflow-x\s*:\s*hidden/)
  assert.match(declaration, /min-height\s*:\s*0/)
})

test('mobile does not trap user in a nested scroll area', () => {
  const mobile = css.slice(css.lastIndexOf('@media (max-width: 700px)'))
  assert.match(mobile, /\.gs-split\s*\{[^}]*height\s*:\s*auto/s)
  assert.match(mobile, /\.gs-detail\s*\{[^}]*overflow\s*:\s*visible/s)
  assert.match(mobile, /\.gs-students\s*\{[^}]*overflow\s*:\s*visible/s)
})

test('the regression patch is scoped to the Guru modal', () => {
  const patch = css.slice(css.indexOf('FIX: Pemantauan Pengumpulan'))
  assert.doesNotMatch(patch, /\n\.siakad-workspace-modal(?:\s|\{)/)
  assert.doesNotMatch(patch, /SiswaAssignmentsPage/)
})
