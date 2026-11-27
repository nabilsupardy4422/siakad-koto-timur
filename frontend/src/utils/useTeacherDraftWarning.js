import {useEffect} from 'react'
// Browser unload and normal in-app anchor clicks; no persistent student data.
export function useTeacherDraftWarning(dirty) {
 useEffect(()=>{if(!dirty)return;const unload=e=>{e.preventDefault();e.returnValue=''};const link=e=>{const a=e.target?.closest?.('a[href]');if(!a||e.defaultPrevented||e.button!==0||e.metaKey||e.ctrlKey||e.shiftKey||e.altKey||a.target==='_blank'||a.hasAttribute('download'))return;const u=new URL(a.href,location.href);if(u.origin===location.origin&&u.pathname!==location.pathname&&!window.confirm('Ada perubahan belum disimpan. Tinggalkan halaman?')){e.preventDefault();e.stopPropagation()}};window.addEventListener('beforeunload',unload);document.addEventListener('click',link,true);return()=>{window.removeEventListener('beforeunload',unload);document.removeEventListener('click',link,true)}},[dirty])
}
