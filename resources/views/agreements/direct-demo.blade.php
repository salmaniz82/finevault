<!doctype html><html><head><meta charset="utf-8"><style>
@page{margin:40pt}body{font:11pt DejaVu Sans;color:#253028;line-height:1.5}h1{font-size:24pt}h2{font-size:14pt;color:#87543e}p{margin:12pt 0}.note{background:#eef0e8;padding:12pt;font-size:10pt}table{border-collapse:collapse}td{vertical-align:top}
</style></head><body>
<p>FINEWORTH / PRIVATE CLIENT PORTAL</p><h1>Sample acquisition agreement</h1>
<p class="note">Fictional demonstration only · {{ $extended ? 'Extended agreement' : 'Standard agreement' }} · No real transaction.</p>
<h2>Client and cask</h2><p>Alexander Morgan · Glenbrae 2018 single malt · Cask GB-1842 · First-fill bourbon barrel · 200 L nominal capacity.</p>
<h2>Consideration</h2><p>Sample purchase price: GBP 12,500.00. Deposit recorded externally: GBP 2,500.00. Remaining balance: GBP 10,000.00 due 12 November 2026.</p>
<h2>Demonstration terms</h2><p>The deposit is an illustrative external payment record. This portal does not collect or verify payment. Storage, insurance, ownership and approved commercial terms require separate confirmation.</p>
@if($extended)
@for($i=1;$i<=12;$i++)<h2>Additional sample clause {{ $i }}</h2><p>This additional fictional clause demonstrates how agreement content can grow. The signing block must follow the final text and remain together on one page. These words do not establish commercial rights or obligations. All assets and amounts in this demonstration are imaginary.</p>@endfor
@endif
<p>I have reviewed this sample agreement and understand its demonstration status.</p>
@include('agreements.signing-block')
</body></html>
