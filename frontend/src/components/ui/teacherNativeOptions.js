export function nativeOptions(options,query='',selected='') {
 const q=String(query).trim().toLocaleLowerCase('id'); return options.filter(o=>String(o.value)===''||String(o.value)===String(selected)||`${o.label??''} ${o.description??''} ${o.searchText??''}`.toLocaleLowerCase('id').includes(q))
}
