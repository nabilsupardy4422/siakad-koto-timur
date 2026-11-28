import { useEffect, useMemo, useRef, useState } from 'react'
import { PRINCIPAL_SCHEDULE_DAYS, principalScheduleClassLabel } from './principalScheduleMonitoring'
import { buildPrincipalMatrix, CLASS_PAGE_SIZE, TIME_PAGE_SIZE, LIST_PAGE_SIZE, GROUP_PAGE_SIZE, pageSlice, initialMatrixDay, searchPrincipalSchedule, dayLabel, displayTime, scheduleTitle, teacherTitle } from './principalScheduleScale'

export function PrincipalScheduleDetail({ rows, onClose }) {
  const ref=useRef(null)
  const [page,setPage]=useState(1)
  const data=pageSlice(rows,page,GROUP_PAGE_SIZE)
  useEffect(()=>{const dialog=ref.current;if(dialog&&!dialog.open)dialog.showModal();return()=>{if(dialog?.open)dialog.close()}},[])
  return <dialog ref={ref} className="ps-scale__dialog" aria-labelledby="ps-scale-dialog-title" onCancel={e=>{e.preventDefault();onClose()}}>
    <header><div><span className="ps-scale__eyebrow">DETAIL JADWAL · WIB</span><h2 id="ps-scale-dialog-title">{rows.length===1?'Jadwal pembelajaran':`${rows.length} jadwal dalam kelompok`}</h2></div><button type="button" onClick={onClose} aria-label="Tutup detail jadwal" autoFocus>×</button></header>
    <div className="ps-scale__dialog-body">{data.rows.map(row=><article key={row.id} data-detail-id={row.id}><strong>{scheduleTitle(row)}</strong><span>{dayLabel(row.hari)} · {displayTime(row.jam_mulai)}–{displayTime(row.jam_selesai)}</span><span>{principalScheduleClassLabel(row)} · {teacherTitle(row)}</span></article>)}</div>
    <footer>{data.last>1&&<Pager data={data} label="Jadwal kelompok" onChange={p=>{setPage(p);ref.current?.scrollTo(0,0)}}/>}<button type="button" onClick={onClose}>Tutup</button></footer>
  </dialog>
}
function Pager({ data, label, onChange }) {
  return <div className="ps-scale__pager"><span>{label}: {data.first}–{data.end} dari {data.total}</span><div><button type="button" aria-label={`Sebelumnya ${label}`} disabled={data.page===1} onClick={()=>onChange(data.page-1)}>‹</button><span>{data.page}/{data.last}</span><button type="button" aria-label={`Berikutnya ${label}`} disabled={data.page===data.last} onClick={()=>onChange(data.page+1)}>›</button></div></div>
}
export function PrincipalScheduleMatrix({ schedules, filterDay }) {
  const [day,setDay]=useState(()=>initialMatrixDay(schedules,filterDay))
  const [classPage,setClassPage]=useState(1),[timePage,setTimePage]=useState(1)
  const [detail,setDetail]=useState(null)
  const scrollRef=useRef(null)
  const actualDay=filterDay||day
  const data=useMemo(()=>buildPrincipalMatrix(schedules,actualDay),[schedules,actualDay])
  const cols=pageSlice(data.classes,classPage,CLASS_PAGE_SIZE), times=pageSlice(data.slots,timePage,TIME_PAGE_SIZE)
  const shown=times.rows.reduce((n,slot)=>n+cols.rows.reduce((sum,c)=>sum+(slot.cells.get(c.value)?.length||0),0),0)
  function changeDay(d){setDay(d);setTimePage(1);setClassPage(1);scrollRef.current?.scrollTo(0,0)}
  return <section className="ps-scale__panel" aria-label="Matriks jadwal sekolah">
    <div className="ps-scale__heading"><div><span className="ps-scale__eyebrow">SELURUH ROMBEL SESUAI FILTER</span><h2>Matriks jadwal sekolah</h2><p>Baris menunjukkan jam mulai; setiap kartu mencantumkan jam selesai. Tanda kosong bukan berarti kelas bebas.</p></div><span className="ps-scale__count">{data.count} jadwal · {dayLabel(actualDay)}</span></div>
    <div className="ps-scale__days" role="group" aria-label="Hari matriks">{PRINCIPAL_SCHEDULE_DAYS.map(d=><button type="button" key={d} aria-pressed={actualDay===d} disabled={!!filterDay&&d!==filterDay} onClick={()=>changeDay(d)}>{dayLabel(d)}</button>)}</div>
    {data.count===0?<p className="ps-scale__empty">Tidak ada jadwal sesuai filter pada hari ini.</p>:<>
      <div className="ps-scale__hint"><span>Menampilkan {shown} dari {data.count} jadwal hari ini. Geser matriks untuk melihat rombel lainnya.</span><div><button type="button" aria-label="Geser matriks ke kiri" onClick={()=>scrollRef.current?.scrollBy({left:-320,behavior:'auto'})}>‹</button><button type="button" aria-label="Geser matriks ke kanan" onClick={()=>scrollRef.current?.scrollBy({left:320,behavior:'auto'})}>›</button></div></div>
      <div ref={scrollRef} className="ps-scale__matrix-scroll" role="region" aria-label="Matriks rombel dan waktu, dapat digeser" tabIndex={0}>
        <table className="ps-scale__matrix"><thead><tr><th scope="col">Mulai WIB</th>{cols.rows.map(c=><th key={c.value} scope="col">{c.label}</th>)}</tr></thead><tbody>{times.rows.map(slot=><tr key={slot.start}><th scope="row">{slot.time}</th>{cols.rows.map(c=><td key={c.value}>{(slot.cells.get(c.value)||[]).map(row=><button type="button" key={row.id} data-schedule-id={row.id} className="ps-scale__event" onClick={()=>setDetail([row])}><strong>{scheduleTitle(row)}</strong><span>{displayTime(row.jam_mulai)}–{displayTime(row.jam_selesai)}</span><small>{teacherTitle(row)}</small></button>)}{!slot.cells.has(c.value)&&<span className="ps-scale__dash">—</span>}</td>)}</tr>)}</tbody></table>
      </div>
      <Pager data={cols} label="Rombel" onChange={p=>{setClassPage(p);scrollRef.current?.scrollTo(0,0)}}/><Pager data={times} label="Jam mulai" onChange={p=>{setTimePage(p);scrollRef.current?.scrollTo(0,0)}}/>
    </>}
    {detail&&<PrincipalScheduleDetail rows={detail} onClose={()=>setDetail(null)}/>}
  </section>
}
export function PrincipalScheduleList({schedules,historical=false}) {
  const [query,setQuery]=useState(''),[page,setPage]=useState(1),[detail,setDetail]=useState(null)
  const sorted=useMemo(()=>searchPrincipalSchedule(schedules,query),[schedules,query])
  const data=pageSlice(sorted,page,LIST_PAGE_SIZE)
  return <section className="ps-scale__panel" aria-label={historical?'Daftar jadwal periode tidak aktif':'Daftar jadwal'}>
    <div className="ps-scale__heading"><div><span className="ps-scale__eyebrow">{historical?'RIWAYAT JADWAL':'DAFTAR MINGGUAN'}</span><h2>{historical?'Jadwal mingguan tersimpan':'Daftar jadwal'}</h2><p>{historical?'Pola jadwal periode terpilih, bukan kalender aktual atau pertemuan terlaksana.':'Cari kelas, guru, mapel, hari atau jam. Seluruh hasil dapat dijangkau melalui halaman.'}</p></div><span className="ps-scale__count">{data.total} dari {schedules.length} jadwal</span></div>
    <label className="ps-scale__search">Cari jadwal<input type="search" value={query} onChange={e=>{setQuery(e.target.value);setPage(1)}} placeholder="Nama guru, mapel, kelas..."/></label>
    {data.total===0?<p className="ps-scale__empty">Tidak ada hasil pencarian.</p>:<>
      <div className="ps-scale__list" role="region" aria-label="Hasil daftar jadwal"><table><thead><tr>{['Hari','Jam WIB','Rombel','Mata pelajaran','Guru','Detail'].map(label=><th key={label} scope="col">{label}</th>)}</tr></thead><tbody>{data.rows.map(row=><tr key={row.id} data-schedule-id={row.id}><td data-label="Hari">{dayLabel(row.hari)}</td><td data-label="Jam WIB">{displayTime(row.jam_mulai)}–{displayTime(row.jam_selesai)}</td><td data-label="Rombel">{principalScheduleClassLabel(row)}</td><td data-label="Mata pelajaran"><strong>{scheduleTitle(row)}</strong></td><td data-label="Guru">{teacherTitle(row)}</td><td><button type="button" onClick={()=>setDetail([row])} aria-label={`Detail ${scheduleTitle(row)} ${principalScheduleClassLabel(row)}`}>Detail</button></td></tr>)}</tbody></table></div>
      <Pager data={data} label="Jadwal" onChange={setPage}/>
    </>}
    {detail&&<PrincipalScheduleDetail rows={detail} onClose={()=>setDetail(null)}/>}
  </section>
}
