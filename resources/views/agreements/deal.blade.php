<!doctype html><html><head><meta charset="utf-8"><style>
@page { margin: 44px 46px; } body {font-family:DejaVu Sans,sans-serif;font-size:11px;line-height:1.5;color:#253028} h1{font-size:25px;margin:12px 0} h2{font-size:15px;margin-top:20px;color:#87543e} .label{color:#62695f} .banner{padding:12px;background:#eef0e8;font-size:10px} table{width:100%;border-collapse:collapse} td{padding:7px 9px;border-bottom:1px solid #ddd;vertical-align:top;overflow-wrap:break-word} td:first-child{width:48%} .total{font-weight:bold;background:#eef0e8} .page{page-break-before:always} .signature{margin-top:35px;border-top:1px solid #aaa;padding-top:8px} .foot{font-size:9px;color:#62695f}
</style></head><body>
<div class="label">FINEWORTH / PRIVATE CLIENT PORTAL</div><h1>Acquisition agreement</h1>
<div class="banner">FICTIONAL DEMONSTRATION ONLY · FW-{{ $id }} · Template {{ $data['template_version'] }}<br>Sample commercial terms for workflow review. Not an approved contract or proof of ownership.</div>
<h2>01 / Client and stock</h2>
<table><tr><td>Client</td><td>{{ $data['client_name'] }}<br>{{ $data['client_email'] }}</td></tr><tr><td>Address</td><td>{{ $data['client_address'] }}</td></tr>
@foreach(['name'=>'Stock name','reference'=>'Stock / cask reference','producer'=>'Distillery / producer','storage'=>'Storage location'] as $key=>$label)<tr><td>{{ $label }}</td><td>{{ $data[$key] }}</td></tr>@endforeach
<tr><td>Asset and quantity</td><td>{{ ucfirst($data['type']) }} · {{ $data['quantity'] }} {{ $data['type'] === 'whisky' ? 'cask' : 'bottles' }}</td></tr>
@if($data['type'] === 'whisky')
@foreach(['cask_type'=>'Cask type','cask_size'=>'Nominal cask capacity (L)','ola'=>'Original litres of alcohol (OLA, L)','rla'=>'Regauged litres of alcohol (RLA, L)','abv'=>'Recorded ABV (%)','measurement_date'=>'Measurement date'] as $key=>$label)<tr><td>{{ $label }}</td><td>{{ $data[$key] }}</td></tr>@endforeach
@else<tr><td>Vintage / bottle size</td><td>{{ $data['vintage'] }} / {{ $data['bottle_ml'] }} mL</td></tr><tr><td>Total wine volume</td><td>{{ number_format($data['quantity']*$data['bottle_ml']/1000,2) }} L</td></tr>@endif</table>
<p class="foot">Client and stock details are frozen when the agreement is generated. OLA/RLA are recorded alcohol volumes, distinct from nominal cask capacity. Measurements are supplied demo data.</p>
<div class="page"><div class="label">FINEWORTH / FW-{{ $id }}</div><h2>02 / Consideration and fees</h2>
<table><tr><td>Unit price (per {{ $data['type'] === 'whisky' ? 'cask' : 'bottle' }})</td><td>{{ \App\Services\DealAgreement::money(\App\Services\DealAgreement::pennies($data['unit_price'])) }}</td></tr>
@foreach(['stock'=>'Stock subtotal','markup'=>'Markup ('.$data['markup_rate'].'%)','management'=>'Management fee ('.$data['management_rate'].'%)','brokerage'=>'Brokerage / commission ('.$data['brokerage_rate'].'%)','total'=>'Agreement total','deposit'=>'External deposit recorded','balance'=>'Remaining balance'] as $key=>$label)<tr class="{{ in_array($key,['total','balance']) ? 'total' : '' }}"><td>{{ $label }}</td><td>{{ \App\Services\DealAgreement::money($data['totals'][$key]) }}</td></tr>@endforeach
<tr><td>Deposit date / reference</td><td>{{ $data['deposit_date'] }} / {{ $data['deposit_reference'] }}</td></tr><tr><td>Balance due</td><td>{{ $data['balance_due'] }}</td></tr></table>
<h2>03 / Demonstration terms</h2>
<p>Fineworth prepares this illustrative acquisition record for the named client and stock. Each fee is calculated once on the stock subtotal, rounded to the nearest penny, and then added to it. Markup is shown separately. No fee is compounded or recurring in this example.</p>
<p>The deposit is a staff-entered record of an external payment; the portal neither collects nor verifies funds. The remaining balance is the agreement total less that deposit, due on the date above. Tax, duties, storage, insurance and cancellation terms are not modelled and require approved wording before commercial use.</p>
<p>Publication means an administrator reviewed the document. Client review acknowledgement is not a legal signature, ownership transfer or confirmation of full payment. Electronic signing, when demonstrated, uses DocuSign sandbox only.</p>
@include('agreements.signing-block')
<p class="foot">Prepared by {{ $data['created_by'] }} · {{ $data['created_at'] }}<br>Fictional demonstration only. No guaranteed return, valuation or production contract is represented.</p></div>
</body></html>
