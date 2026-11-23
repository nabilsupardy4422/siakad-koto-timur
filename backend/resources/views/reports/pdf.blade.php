<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<title>{{ $meta['title'] }}</title>
<style>
 @page { margin: 126pt 31pt 43pt; }
 body { font-family: DejaVu Sans, sans-serif; font-size: 9pt; color: #17344e; margin: 0; }
 header { position: fixed; top: -108pt; left: 0; right: 0; height: 100pt; }
 .school { font-size: 14pt; font-weight: bold; margin: 0 0 2pt; }
 .sub { color: #52677e; font-size: 8pt; }
 .brand { width: 100%; border-bottom: 1.5pt solid #17344e; margin-bottom: 8pt; padding-bottom: 8pt; }
 .brand td { padding: 0; vertical-align: middle; }
 .logo { width: 42pt; height: 42pt; }
 h1 { font-size: 15pt; margin: 0 0 3pt; line-height: 1.15; }
 .context { color: #285f98; font-size: 8pt; font-weight: bold; }
 .meta { background: #edf3f8; padding: 9pt 11pt; margin-bottom: 11pt; border: .5pt solid #d7e1eb; font-size: 8pt; line-height: 1.5; overflow-wrap: break-word; }
 .meta div { margin-bottom: 2pt; }
 .data { width: 100%; table-layout: fixed; border-collapse: collapse; margin-bottom: 12pt; }
 .data thead { display: table-header-group; }
 .data th, .data td { padding: 5pt 5pt; border-bottom: .5pt solid #d7e1eb; vertical-align: top; overflow-wrap: break-word; word-wrap: break-word; }
 .data th { background: #17344e; color: white; font-size: 8pt; text-align: left; }
 .data .group { background: #edf3f8; color: #17344e; border: .5pt solid #d7e1eb; font-size: 9pt; }
 .data tbody tr:nth-child(even) { background: #f2f6fa; }
 .data td { font-size: 8pt; white-space: pre-line; }
 .data tr { page-break-inside: avoid; }
 .num { text-align: center; }
 .empty { text-align: center; color: #52677e; padding: 15pt !important; }
 .appendix { font-size: 8pt; white-space: pre-wrap; word-wrap: break-word; overflow-wrap: break-word; }
 .note { font-size: 8pt; color: #52677e; line-height: 1.5; margin: 8pt 0; }
 footer { position: fixed; bottom: -27pt; left: 0; right: 0; height: 16pt; border-top: .6pt solid #d7e1eb; padding-top: 5pt; font-size: 8pt; color: #52677e; }
</style>
</head>
<body>
<header>
 <table class="brand"><tr>
 @if($logo)<td style="width:52pt"><img class="logo" src="{{ $logo }}" alt="Logo sekolah"></td>@endif
 <td><div class="school">{{ $meta['school'] }}</div><div class="sub">Sistem Informasi Akademik / Dokumen rekap</div></td>
 <td style="text-align:right; width:112pt"><div class="context">SIAKAD</div><div class="sub">Laporan akademik</div></td>
 </tr></table>
 <h1>{{ $meta['title'] }}</h1>
 <div class="context">{{ $meta['scope'] }} &nbsp; | &nbsp; {{ $meta['period'] }}</div>
</header>
<footer>SIAKAD / Dokumen informasi akademik</footer>
<div class="meta">
 <div><strong>Filter laporan:</strong> {{ $meta['filters'] }}</div>
 <div><strong>Jumlah baris:</strong> {{ $meta['count'] }} &nbsp; | &nbsp; <strong>Dibuat:</strong> {{ $meta['generated'] }}</div>
 <div>{{ $meta['note'] }}</div>
</div>
@foreach($sections as $section)
<table class="data">
 <colgroup><col style="width:4%">@foreach($section['widths'] as $width)<col style="width:{{ $width * .96 }}%">@endforeach</colgroup>
 <thead>
  <tr><th class="group" colspan="{{ count($section['headings']) + 1 }}">{{ $section['context'] }}</th></tr>
  <tr><th class="num">No.</th>@foreach($section['headings'] as $heading)<th>{{ $heading }}</th>@endforeach</tr>
 </thead>
 <tbody>
 @php($longNotes = [])
 @forelse($section['projected'] as $row)
  @php($rowNumber = $loop->iteration)
  <tr><td class="num">{{ $rowNumber }}</td>
  @foreach($row as $index => $value)
   @if($section['headings'][$index] === 'Catatan' && is_string($value) && (mb_strlen($value) > 300 || substr_count($value, "\n") > 5))
    @php($longNotes[$rowNumber] = $value)
    <td>Lihat catatan lengkap baris {{ $rowNumber }}.</td>
   @else
    <td>{{ $value === null || $value === '' ? '—' : $value }}</td>
   @endif
  @endforeach
  </tr>
 @empty
  <tr><td colspan="{{ count($section['headings']) + 1 }}" class="empty">Tidak ada data sesuai filter laporan.</td></tr>
 @endforelse
 </tbody>
</table>
@if($longNotes)
 <p class="context">Catatan lengkap — {{ $section['context'] }}</p>
 @foreach($longNotes as $number => $note)
  <p class="appendix"><strong>Baris {{ $number }}:</strong> {{ $note }}</p>
 @endforeach
@endif
@endforeach
<p class="note">{{ $meta['note'] }} Tanda “—” menandakan data kosong, bukan angka nol.</p>
</body>
</html>
