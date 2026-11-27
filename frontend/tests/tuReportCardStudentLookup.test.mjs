import test from 'node:test'
import assert from 'node:assert/strict'
import {uploadForm} from '../src/pages/report-cards/roles/tuReportCardWorkspace.js'
import {parseStudentMatches, studentOptionLabel} from '../src/pages/report-cards/roles/reportCardStudentLookup.js'
const fixture={data:[{id:7,nama_lengkap:'Siswa Contoh',nis:'123',nisn:'0058083291'}],meta:{current_page:1,last_page:1,total:1}}
test('respons student API asli dibaca dari data dan meta',()=>assert.deepEqual(parseStudentMatches(fixture), [{id:7,nama_lengkap:'Siswa Contoh',nis:'123',nisn:'0058083291'}]))
test('ID profil siswa berbeda dari NISN dan tidak disimpulkan dari NISN',()=>assert.equal(parseStudentMatches(fixture)[0].id,7))
test('hasil tanpa metadata pagination ditolak',()=>assert.throws(()=>parseStudentMatches({data:fixture.data})))
test('duplikasi ID siswa ditolak',()=>assert.throws(()=>parseStudentMatches({...fixture,data:[...fixture.data,...fixture.data]})))
test('ID bukan integer ditolak',()=>assert.throws(()=>parseStudentMatches({...fixture,data:[{...fixture.data[0],id:'abc'}]})))
test('nama siswa kosong ditolak',()=>assert.throws(()=>parseStudentMatches({...fixture,data:[{...fixture.data[0],nama_lengkap:''}]})))
test('format label tidak kehilangan NIS dan NISN',()=>assert.match(studentOptionLabel(parseStudentMatches(fixture)[0]),/NISN 0058083291/))
test('form upload memakai ID profil siswa yang dipilih dan konteks rombel',()=>{
  const student=parseStudentMatches(fixture)[0];const f=uploadForm({student_id:student.id,document_id:null},{academic_year_id:'2',class_id:'3',type:'AKHIR_SEMESTER'},new File(['pdf'],'rapor.pdf',{type:'application/pdf'}),'upload','');
  assert.equal(f.get('siswa_id'),'7');assert.equal(f.get('class_id'),'3');assert.equal(f.get('tahun_akademik_id'),'2');assert.notEqual(f.get('siswa_id'),student.nisn);
})
