import test from 'node:test'
import assert from 'node:assert/strict'
import {readFileSync} from 'node:fs'
import { parseReportCardsResponse, academicPeriod, readableSize, displayDate, availablePeriods } from '../src/pages/report-cards/roles/studentReportCardsView.js'
const item={id:1,type:'MID_SEMESTER',academic_year:{id:2,tahun_mulai:2026,tahun_selesai:2027,semester:'Ganjil'},file:{name:'uji.pdf',size:1000},uploaded_at:'2026-11-22T12:00:00Z'}
const wrap=(data=[item],page=1,last=1)=>({data,meta:{current_page:page,last_page:last,total:data.length}})
test('membaca respons Laravel langsung, bukan wrapper Axios ganda',()=>assert.equal(parseReportCardsResponse(wrap()).records[0].id,1))
test('menolak respons tanpa metadata pagination',()=>assert.throws(()=>parseReportCardsResponse({data:[item]})))
test('menolak identitas dokumen ganda',()=>assert.throws(()=>parseReportCardsResponse(wrap([item,item]))))
test('menolak jenis dokumen yang tidak resmi',()=>assert.throws(()=>parseReportCardsResponse(wrap([{...item,type:'UNKNOWN'}]))))
test('menerima halaman lebih dari satu',()=>assert.equal(parseReportCardsResponse(wrap([item],2,4)).lastPage,4))
test('menolak halaman di luar batas',()=>assert.throws(()=>parseReportCardsResponse(wrap([item],5,4))))
test('metadata periode berasal dari respons',()=>assert.equal(academicPeriod(item),'2026/2027 · Ganjil'))
test('periode tanpa ID tidak ditambahkan',()=>assert.equal(availablePeriods([{...item,academic_year:{}}]).length,0))
test('periode duplikat tidak berulang',()=>assert.equal(availablePeriods([item,{...item,id:2}]).length,1))
test('ukuran file kosong tidak direka',()=>assert.equal(readableSize(null),'Ukuran tidak tersedia'))
test('tanggal kosong tidak direka',()=>assert.equal(displayDate(null),'Tanggal tidak tersedia'))
const source=readFileSync(new URL('../src/pages/report-cards/roles/SiswaReportCards.jsx',import.meta.url),'utf8')
test('React memiliki request generation guard',()=>assert.match(source,/generation\.current/))
test('React menghapus data lama saat API error',()=>assert.match(source,/setRecords\(\[\]\)/))
test('navigasi pagination diimplementasikan',()=>assert.match(source,/load\(page \+ 1, filters\)/))
test('unduhan melalui API terlindungi',()=>assert.match(source,/reportCardApi\.downloadReportCard/))
test('tidak ada student_id override pada request frontend',()=>assert.doesNotMatch(source,/student_id\s*:/))
