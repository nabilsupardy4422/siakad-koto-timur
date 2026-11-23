export function parseWorkspace(response) {
  // apiClient.get() returns Laravel JSON directly, NOT an Axios {data: response} wrapper.
  const data = response?.data;
  const meta = response?.meta;
  if (!data || !Array.isArray(data.assignments) || !data.submissions ||
      typeof data.submissions !== 'object' || Array.isArray(data.submissions) ||
      !Number.isInteger(meta?.current_page) || !Number.isInteger(meta?.last_page) ||
      !Number.isInteger(meta?.total) || meta.current_page < 1 ||
      meta.last_page < 1 || meta.total < 0 || meta.current_page > meta.last_page) {
    throw new Error('Kontrak data Tugas Siswa tidak valid.');
  }
  const seen = new Set();
  for (const item of data.assignments) {
    if (!Number.isInteger(item?.id) || seen.has(item.id) ||
        !Number.isInteger(item?.jadwal_pelajaran_id)) {
      throw new Error('Daftar tugas mengandung ID tidak valid atau duplikat.');
    }
    seen.add(item.id);
  }
  for (const [key, submission] of Object.entries(data.submissions)) {
    if (!seen.has(Number(key)) || Number(submission?.assignment_id) !== Number(key)) {
      throw new Error('Submission tidak sesuai dengan daftar tugas siswa.');
    }
  }
  return {data, meta};
}

export function assignmentState(assignment, submission, now = Date.now()) {
  if (submission) {
    const status = String(submission.status || '').toLowerCase();
    return {
      type: status === 'terlambat' ? 'late-submitted' : 'submitted',
      label: ({terlambat:'Terlambat dikumpulkan',perlu_revisi:'Perlu revisi',dinilai:'Dinilai',dikumpulkan:'Sudah dikumpulkan'})[status] || 'Sudah dikumpulkan',
    };
  }
  const deadline = Date.parse(assignment?.deadline || '');
  return Number.isFinite(deadline) && deadline < now
    ? {type:'overdue',label:'Tenggat terlewat — belum dikumpulkan'}
    : {type:'pending',label:'Belum dikumpulkan'};
}

export function summarizeAssignments(assignments, submissions, now = Date.now()) {
  const result = {total: assignments.length, pending:0, submitted:0, late:0};
  for (const item of assignments) {
    const state = assignmentState(item, submissions[item.id], now);
    if (state.type === 'pending') result.pending++;
    if (state.type === 'overdue' || state.type === 'late-submitted') result.late++;
    if (state.type === 'submitted' || state.type === 'late-submitted') result.submitted++;
  }
  return result;
}
