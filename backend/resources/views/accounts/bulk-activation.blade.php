<!DOCTYPE html><html lang="id"><head><meta charset="UTF-8"><style>
@page { size: A4 portrait; margin: 10mm 12mm; }
body { font-family: DejaVu Sans,sans-serif; font-size: 8pt; line-height:1.15; color:#172e45; margin:0; }
.slip { border:1px dashed #8ba2b5; padding: 2.5mm 4mm; margin-bottom: 2mm; min-height: 59mm; page-break-inside:avoid; }
.head { width:100%; border-collapse:collapse; border-bottom:1.5pt solid #173b62; margin-bottom:2mm; }
.head td { vertical-align:middle; padding-bottom:1.5mm; }
.logo { height:9mm; } .school { font-size:10pt; font-weight:bold; } .title {font-size:7pt; color:#435d73;}
.content { width:100%; table-layout:fixed; border-collapse:collapse; } .content td {vertical-align:top; word-wrap:break-word;}
.name {font-size:9pt; font-weight:bold;} .sub {font-size:7.5pt; color:#435d73;}
.code { font-family:DejaVu Sans Mono,monospace; font-size:10pt; font-weight:bold; padding:1.5mm; background:#eef3f8; margin:1mm 0; text-align:center; }
.qr { width:31mm; height:31mm; } .qrbox { text-align:center; padding-left:2mm; }
.small { font-size:7pt; line-height:1.22; word-wrap:break-word; } .break {page-break-after:always;}
</style></head><body>
@foreach(array_chunk($slips,4) as $page)
@foreach($page as $slip)
<div class="slip">
<table class="head"><tr>@if($logo)<td width="12mm"><img class="logo" src="{{ $logo }}" alt="Logo"></td>@endif<td><div class="school">SMA Negeri 1 V Koto Timur</div><div class="title">SIAKAD &bull; SLIP AKTIVASI AKUN</div></td><td align="right" width="25%"><b>RAHASIA</b><br><span class="title">INDIVIDUAL</span></td></tr></table>
<table class="content"><tr><td width="78%">
<div class="name">{{ $slip['name'] }}</div>
<div class="sub">{{ $slip['role'] }} @if($slip['class']) &bull; Kelas: {{ $slip['class'] }} @endif</div>
<div class="small">Username: <b>{{ $slip['username'] }}</b></div>
<div class="code">{{ $slip['activation_code'] }}</div>
<div class="small"><b>Berlaku hingga {{ \Carbon\Carbon::parse($slip['expires_at'])->timezone('Asia/Jakarta')->format('d/m/Y H:i') }} WIB</b> &bull; sekali pakai.<br>Pindai QR atau buka {{ $url }}<br>Masukkan username dan kode, lalu buat password pribadi.</div>
</td><td width="22%" class="qrbox"><img class="qr" src="{{ $qr }}" alt="QR halaman aktivasi"><div class="small">Pindai untuk aktivasi</div></td></tr></table>
<div class="small" style="border-top:1px solid #d1dce5;margin-top:1mm;padding-top:1mm;">Serahkan hanya kepada pemilik akun. Jangan bagikan kode melalui grup atau media publik.</div>
</div>
@endforeach
@if(!$loop->last)<div class="break"></div>@endif
@endforeach
</body></html>
