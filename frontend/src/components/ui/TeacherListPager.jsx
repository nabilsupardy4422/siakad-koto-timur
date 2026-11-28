import {useState,useEffect} from 'react'
import {teacherPage} from './teacherPage.js'
import './TeacherListPager.css'
export function useTeacherPage(rows,filterKey,size=12){const [page,setPage]=useState(1);useEffect(()=>setPage(1),[filterKey]);return {...teacherPage(rows,page,size),setPage}}
export default function TeacherListPager({data,label='Data'}){if(!data.total)return null;return <nav className="teacher-list-pager" aria-label={`Halaman ${label}`}><span aria-live="polite">{data.first}–{data.end} dari {data.total} {label}</span><div><button type="button" disabled={data.page<=1} onClick={()=>data.setPage(data.page-1)} aria-label={`Halaman ${label} sebelumnya`}>‹</button><span>{data.page} / {data.last}</span><button type="button" disabled={data.page>=data.last} onClick={()=>data.setPage(data.page+1)} aria-label={`Halaman ${label} berikutnya`}>›</button></div></nav>}
