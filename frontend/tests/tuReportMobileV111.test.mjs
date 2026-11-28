import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
const source=readFileSync(new URL('../src/pages/reports/roles/TuReportPreviewPanel.jsx',import.meta.url),'utf8');
const css=readFileSync(new URL('../src/pages/reports/roles/tu-reports.css',import.meta.url),'utf8');
test('TU preview pairs each displayed value with its exact server heading and retains semantic desktop headers',()=>{
  assert.match(source,/<th key=\{i\} scope="col">\{h\}<\/th>/);
  assert.match(source,/Object\.values\(r\)\.map\(\(v,j\)=>\s*<td key=\{j\}><span className="tur-cell-label" aria-hidden="true">\{data\.headings\[j\]\}<\/span><span className="tur-cell-value">\{display\(v\)\}<\/span><\/td>/);
  assert.match(source,/v==null\|\|v===''\?'—':String\(v\)/);
});
test('TU mobile preview displays real labels, suppresses empty inherited pseudo label and wraps both columns',()=>{
  assert.match(css,/\.tur-preview \.tur-cell-label\{display:none\}/);
  const mobile=css.split('@media(max-width:600px)').at(-1);
  assert.match(mobile,/\.tu-academic-monitoring \.tur-preview \.tam-table-scroll td\{min-width:0;grid-template-columns:minmax\(0,44%\) minmax\(0,1fr\)/);
  assert.match(mobile,/\.tur-preview \.tam-table-scroll td::before\{content:none;display:none\}/);
  assert.match(mobile,/\.tur-preview \.tur-cell-label\{display:block;[^}]*overflow-wrap:anywhere/);
  assert.match(mobile,/\.tur-preview \.tur-cell-value\{display:block/);
});
