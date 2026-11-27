// Every write has its own response/verification. Do not retry unknown results.
export async function runTeacherGradeBatch(targets,persist){
 const counts={saved:0,failed:0,uncertain:0,notProcessed:0}
 for(let i=0;i<targets.length;i++){
  const result=await persist(targets[i]);if(!['saved','failed','uncertain'].includes(result))throw new Error('Hasil simpan tidak dikenal.')
  counts[result]++;if(result==='uncertain'){counts.notProcessed=targets.length-i-1;break}
 }return counts
}
