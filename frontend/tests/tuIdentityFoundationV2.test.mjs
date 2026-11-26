import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
const source = name => readFileSync(new URL(`../src/pages/${name}`, import.meta.url), 'utf8');
for (const [page, identifier] of [['teachers/TeachersPage.jsx', 'nip'], ['students/StudentsPage.jsx', 'nisn']]) {
  test(`${page} explicitly distinguishes new and existing registration`, () => {
    const s = source(page);
    assert.match(s, /registration_mode: 'new_account'/);
    assert.match(s, /form\.registration_mode === 'new_account'/);
    assert.match(s, new RegExp(`form\\.${identifier}`));
    assert.match(s, /profile_version: editing\w+\.profile_version/);
  });
  test(`${page} requests server sort and ignores stale list response`, () => {
    const s = source(page);
    assert.match(s, /sort_order: sortOrder/);
    assert.match(s, /requestId !== listRequest\.current/);
    assert.match(s, /setSortOrder\(event\.target\.value \|\| 'asc'\); setPage\(1\)/);
  });
}
test('student directory uses NIPD without sending legacy NIS', () => {
  const s = source('students/StudentsPage.jsx');
  assert.match(s, /nipd: form\.nipd\.trim\(\)/);
  assert.doesNotMatch(s, /nis: form\.nis/);
  assert.match(s, /response\?\.data\?\.total_imported/);
});
test('teacher import explicitly passes registration mode and server preview token', () => {
  const s = source('teachers/TeachersPage.jsx');
  assert.equal([...s.matchAll(/formData\.append\('registration_mode', importMode\)/g)].length, 2);
  assert.match(s, /formData\.append\('preview_token', previewData\.preview_token\)/);
});
