export const CANDIDATE_PAGE_SIZE = 4

// Search on the eligible server snapshot only. Never coerce NIP to a number.
export function candidatePage(options, query, requestedPage) {
  const term = String(query ?? '').trim().toLocaleLowerCase('id')
  const matches = options.filter(option => [option.label, option.searchText, option.description]
    .some(value => String(value ?? '').toLocaleLowerCase('id').includes(term)))
  const last = Math.max(1, Math.ceil(matches.length / CANDIDATE_PAGE_SIZE))
  const page = Math.max(1, Math.min(Number.isSafeInteger(requestedPage) ? requestedPage : 1, last))
  return { rows: matches.slice((page - 1) * CANDIDATE_PAGE_SIZE, page * CANDIDATE_PAGE_SIZE), total: matches.length, page, last }
}
