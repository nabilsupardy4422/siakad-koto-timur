import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { resolve, dirname } from 'node:path';
const root=resolve(dirname(fileURLToPath(import.meta.url)),'../src/pages/assignments/roles');
const code=readFileSync(resolve(root,'studentAssignmentsView.js'),'utf8');
const {parseWorkspace,assignmentState,summarizeAssignments}=await import(`data:text/javascript,${encodeURIComponent(code)}`);
const a={id:1,jadwal_pelajaran_id:9,deadline:'2026-11-22T00:00:00.000Z'};
const valid=(items=[a],subs={})=>({data:{academic_year_id:1,class:{id:3},assignments:items,submissions:subs},meta:{current_page:1,last_page:1,total:items.length}});
test('workspace valid mempertahankan tugas dan metadata',()=> assert.equal(parseWorkspace(valid()).data.assignments[0].id,1));
test('payload tanpa pagination ditolak',()=>assert.throws(()=>parseWorkspace({data:{assignments:[],submissions:{}}})));
test('ID tugas duplikat ditolak',()=> assert.throws(()=>parseWorkspace(valid([a,a]))));
test('submission asing ditolak',()=>assert.throws(()=>parseWorkspace(valid([a],{2:{assignment_id:2}}))));
test('belum submit lewat tenggat bukan terkirim terlambat',()=>assert.equal(assignmentState(a,null,Date.parse('2026-11-23')).type,'overdue'));
test('submission terlambat dibedakan dari overdue',()=>assert.equal(assignmentState(a,{status:'terlambat'},Date.parse('2026-11-23')).type,'late-submitted'));
test('jumlah submitted dan late memiliki semantik eksplisit',()=> assert.deepEqual(summarizeAssignments([a],{1:{status:'terlambat'}},Date.parse('2026-11-23')),{total:1,pending:0,submitted:1,late:1}));
test('tugas tanpa deadline dan submission tetap pending',()=>assert.equal(assignmentState({id:3},null).type,'pending'));
const jsx=readFileSync(resolve(root,'SiswaAssignmentsPage.jsx'),'utf8');
const service=readFileSync(resolve(root,'../../../services/assignmentApi.js'),'utf8');
test('React memproteksi respons lama',()=>{assert.match(jsx,/requestGeneration/);assert.match(jsx,/generation !== requestGeneration.current/)});
test('React mengumpulkan seluruh halaman',()=>{assert.match(jsx,/page <= lastPage/);assert.match(jsx,/expectedTotal/)});
test('React mempertahankan formulir lama untuk submit dan revisi',()=>{assert.match(jsx,/submitWithFile/);assert.match(jsx,/updateSubmissionWithFile/)});
test('service workspace tidak menerima student identity override',()=>{assert.match(service,/getStudentWorkspace/);assert.match(service,/assignments\/student-workspace/)});

test('real apiClient response is read without Axios wrapper', () => {
  const result = parseWorkspace(valid());
  assert.equal(result.data.academic_year_id, 1);
  assert.equal(result.meta.total, 1);
});
test('incorrect Axios-style double wrapper is rejected', () => {
  assert.throws(() => parseWorkspace({data: valid()}));
});
test('API failure does not impersonate an empty task state', () => {
  assert.match(jsx, /pageError \? null : loading/);
  assert.match(jsx, /setContext\(null\)/);
});
test('subject groups initially collapsed and expand on request', () => {
  assert.match(jsx, /const isCollapsed = !Boolean\(collapsedSubjects\[group.subject\]\)/);
});

const helperCode=readFileSync(resolve(root,'studentAssignmentFiles.js'),'utf8');
const {appendFilesToSelection}=await import(`data:text/javascript,${encodeURIComponent(helperCode)}`);
const file=(name,size=100,modified=1)=>({name,size,lastModified:modified});
test('memilih file bertahap mempertahankan pilihan terdahulu',()=>{
 const first=appendFilesToSelection([], [file('satu.pdf')]);
 const second=appendFilesToSelection(first.files,[file('dua.docx')]);
 assert.deepEqual(second.files.map(f=>f.name),['satu.pdf','dua.docx']);
});
test('file yang sama tidak terduplikasi',()=>{
 const result=appendFilesToSelection([file('satu.pdf')],[file('satu.pdf')]);
 assert.equal(result.files.length,1);
});
test('batas maksimal berlaku untuk gabungan file antar pemilihan',()=>{
 const prev=Array.from({length:10},(_,i)=>file(`${i}.pdf`));
 const result=appendFilesToSelection(prev,[file('tambahan.pdf')]);
 assert.equal(result.files.length,10);assert.match(result.error,/Maksimal 10/);
});
test('file baru tidak valid tidak menghapus pilihan yang sudah valid',()=>{
 const prev=[file('satu.pdf')];
 const result=appendFilesToSelection(prev,[file('berbahaya.exe')],{validate:f=>f.name.endsWith('.exe')?'Format ditolak':''});
 assert.deepEqual(result.files,prev);assert.match(result.error,/Format ditolak/);
});
test('partial selection invalid tidak menambahkan file secara diam-diam',()=>{
 const prev=[file('lama.pdf')];
 const result=appendFilesToSelection(prev,[file('baru.pdf'),file('salah.exe')],{validate:f=>f.name.endsWith('.exe')?'Format ditolak':''});
 assert.deepEqual(result.files,prev);
});
test('React menggunakan tambah file dan mereset file input agar mobile bisa memilih berulang',()=>{
 assert.match(jsx,/appendFilesToSelection\(selectedFiles, incoming/);
 assert.match(jsx,/event\.target\.value = ""/);
 assert.match(jsx,/fileInputRef\.current\?\.click\(\)/);
 assert.match(jsx,/selectedFiles\.forEach\(\(file\) => \{/);
});
test('notifikasi sukses bisa ditutup dan memiliki live region',()=>{
 assert.match(jsx,/className="siswa-assignment-toast" role="status" aria-live="polite"/);
 assert.match(jsx,/onClick=\{\(\) => setNotice\(""\)\}/);
});
