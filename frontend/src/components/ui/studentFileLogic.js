export async function prepareStudentBlob(blob, filename, format, urls=URL) {
  if (!(blob instanceof Blob) || blob.size===0) throw new Error('Berkas kosong atau tidak valid.');
  const mime=blob.type.split(';')[0].toLowerCase();
  const pdf=mime==='application/pdf' || format==='pdf';
  if (pdf && (mime!=='application/pdf' || await blob.slice(0,5).text()!=='%PDF-')) throw new Error('Berkas bukan PDF yang valid.');
  if (format==='excel' && (mime!=='application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'||await blob.slice(0,2).text()!=='PK')) throw new Error('Berkas bukan Excel yang valid.');
  if (!pdf && format!=='excel' && !(/^(application\/(octet-stream|msword|zip|x-zip-compressed|vnd\.(ms-|openxmlformats-officedocument\.|oasis\.opendocument\.).+)|image\/(png|jpeg|gif|webp)|text\/(csv|plain)|audio\/(mpeg|wav|x-wav|mp4|x-m4a)|video\/mp4)$/i.test(mime))) throw new Error('Tipe berkas tidak didukung.');
  const name=String(filename||'lampiran').replace(/[\\/\x00-\x1f\x7f]/g,'_').slice(0,180);
  return {url:urls.createObjectURL(blob),blob,filename:name,canOpen:pdf,canOpenImage:/^image\/(png|jpeg|gif|webp)$/.test(mime)};
}
