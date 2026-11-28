export function teacherDay(now=new Date()) {
 const p=new Intl.DateTimeFormat('en-CA',{timeZone:'Asia/Jakarta',year:'numeric',month:'2-digit',day:'2-digit'}).formatToParts(now)
 const get=k=>p.find(x=>x.type===k).value;return `${get('year')}-${get('month')}-${get('day')}`
}
