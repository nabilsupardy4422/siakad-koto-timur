import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { pathToFileURL } from 'node:url'
import { gzipSync } from 'node:zlib'

/** Only static imports count toward the initial entry. Dynamic imports remain separately reported. */
export function measureManifest(manifest, readAsset) {
  const entries = Object.keys(manifest).filter(key => manifest[key].isEntry)
  if (entries.length !== 1) throw new Error(`Expected one entry, found ${entries.length}`)
  const visited = new Set(), initialFiles = new Set(), allFiles = new Set()
  const add = (set, record) => {
    if (!record?.file) throw new Error('Invalid manifest record')
    for (const file of [record.file, ...(record.css || [])]) {
      if (/\.(?:m?js|css)$/.test(file)) set.add(file)
    }
  }
  const visit = key => {
    if (visited.has(key)) return
    if (!manifest[key]) throw new Error(`Missing manifest import: ${key}`)
    visited.add(key)
    add(initialFiles, manifest[key])
    for (const dependency of manifest[key].imports || []) visit(dependency)
  }
  visit(entries[0])
  for (const record of Object.values(manifest)) add(allFiles, record)
  const stats = files => [...files].sort().map(file => {
    const bytes = readAsset(file)
    return { file, bytes: bytes.length, gzip: gzipSync(bytes).length }
  })
  const initial = stats(initialFiles), all = stats(allFiles)
  const summarize = files => Object.fromEntries(['js','css'].map(type => {
    const selected = files.filter(f => type === 'js' ? /\.m?js$/.test(f.file) : f.file.endsWith('.css'))
    return [type,{ files: selected.length, bytes: selected.reduce((sum,f)=>sum+f.bytes,0), gzip: selected.reduce((sum,f)=>sum+f.gzip,0) }]
  }))
  return { entry: entries[0], initial: summarize(initial), total: summarize(all), initialFiles: initial, largestJavaScript: all.filter(f=>/\.m?js$/.test(f.file)).sort((a,b)=>b.bytes-a.bytes)[0] || null, dynamicEntries: Object.values(manifest).filter(r=>r.isDynamicEntry).length }
}

if (process.argv[1] && import.meta.url === pathToFileURL(resolve(process.argv[1])).href) {
  const dist = resolve(process.argv[2] || 'dist')
  try {
    const manifest = JSON.parse(readFileSync(resolve(dist,'.vite/manifest.json'),'utf8'))
    const results = measureManifest(manifest,file=>readFileSync(resolve(dist,file)))
    console.log(JSON.stringify(results,null,2))
    console.log('Sizes are bytes; gzip is computed per file. Initial entry excludes lazy route chunks, which load on demand. This is not a network timing measurement.')
  } catch (error) {
    console.error('Bundle measurement failed:',error.message,'Run npm run build -- --manifest first.')
    process.exitCode=1
  }
}
