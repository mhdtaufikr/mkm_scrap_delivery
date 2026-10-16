@extends('layouts.app')
@section('title', $document->exists ? 'Edit Draft' : 'New Goods Release')
@section('content')
@php($selectedType = old('type', $document->type))
<div class="page-heading"><div><h1>{{ $document->exists ? 'Edit draft - '.$document->number : 'New Goods Release' }}</h1><p class="help">Select Scrap or Disposal. Each type has its own document fields and approval requirements.</p></div><a href="{{ route('documents.index') }}" class="btn btn-secondary">Back</a></div>
<form action="{{ $document->exists ? route('documents.update', $document) : route('documents.store') }}" method="POST" data-submit-once data-goods-form>@csrf @if($document->exists) @method('PUT') @endif
<div class="card mb-3"><div class="grid gap-2 p-3 sm:grid-cols-3"><div><label for="type">Document type *</label><select name="type" id="type" @disabled($document->exists)><option value="SCRAP" @selected(old('type', $document->type) === 'SCRAP')>Monthly Scrap</option><option value="DISPOSAL" @selected(old('type', $document->type) === 'DISPOSAL')>Disposal - draft only</option></select>@if($document->exists)<input type="hidden" name="type" value="{{ $document->type }}">@endif</div><div><label for="document_date">Document date *</label><input type="date" name="document_date" id="document_date" value="{{ old('document_date', $document->document_date?->format('Y-m-d')) }}" required></div><div data-type-field="SCRAP"><label for="departure_at">Departure date / time</label><input type="datetime-local" name="departure_at" id="departure_at" value="{{ old('departure_at', $document->departure_at?->format('Y-m-d\TH:i')) }}"></div></div></div>
<fieldset class="paper-sheet paper-editor" data-type-panel="SCRAP" @disabled($selectedType !== 'SCRAP') @if($selectedType !== 'SCRAP') hidden @endif>
<div class="paper-brand"><img src="{{ asset('assets/img/mms.png') }}" alt="MKM"><div><strong>PT MITSUBISHI KRAMA YUDHA MOTORS AND MANUFACTURING</strong><h2>SURAT MUAT BARANG</h2><p>Goods Loading Slip (SMB)</p></div><span class="paper-state">DRAFT</span></div>
<div class="paper-form-heading"><span>No. {{ $document->number ?: 'Assigned after saving' }}</span><span>LOT: {{ data_get($document->form_details,'lot') ?: '-' }}</span></div>
<div class="grid gap-x-4 gap-y-2 py-3 sm:grid-cols-2">
@foreach(['driver_name'=>['Driver / carrier name',150], 'company'=>['Company / buyer',200], 'destination'=>['Destination',255], 'vehicle'=>['Vehicle type',100], 'plate_number'=>['Plate number',30]] as $field=>[$label,$max])
<div class="paper-input-line"><label for="{{ $field }}">{{ $label }} *</label><input id="{{ $field }}" name="{{ $field }}" value="{{ old($field,$document->$field) }}" maxlength="{{ $max }}" required></div>
@endforeach
@foreach(['delivery_number'=>['Official delivery note number',100], 'identity_number'=>['Identity card / SIM number',60], 'lot'=>['Lot number',60], 'department'=>['Department / shop',150]] as $key=>[$label,$max])
<div class="paper-input-line"><label for="detail-{{ $key }}">{{ $label }}</label><input id="detail-{{ $key }}" name="form_details[{{ $key }}]" value="{{ old('form_details.'.$key,data_get($document->form_details,$key)) }}" maxlength="{{ $max }}"></div>
@endforeach
</div>
<div class="mb-2 flex items-center justify-between gap-2"><h3 class="text-xs font-bold uppercase">Goods to be loaded</h3><button type="button" class="btn btn-secondary" data-add-goods>Add item</button></div>
<p class="help mb-2 sm:hidden">Swipe the table to complete all item columns.</p>
<div class="overflow-x-auto"><table class="paper-table paper-items-editor"><thead><tr><th>No.</th><th>Code</th><th>Goods description *</th><th>Quantity *</th><th>Unit *</th><th>Pallet</th><th>Posts</th><th>Remarks</th><th><span class="sr-only">Actions</span></th></tr></thead><tbody data-goods-rows>
@php($rows = old('items', $document->items ?: [['name'=>$document->goods_description,'quantity'=>'','unit'=>'']]))
@foreach($rows as $index=>$item)
@include('documents.item-row', ['index'=>$index,'item'=>$item])
@endforeach
</tbody></table></div>
<template data-goods-template>@include('documents.item-row', ['index'=>'__INDEX__','item'=>[]])</template>
<div class="mt-3 grid gap-3 sm:grid-cols-3"><div class="paper-signature"><strong>CHECKED BY</strong><p class="help">Recorded through the approval workflow.</p></div><div class="paper-signature"><label for="loader_name">LOADED BY</label><input id="loader_name" name="form_details[loader_name]" value="{{ old('form_details.loader_name',data_get($document->form_details,'loader_name')) }}" maxlength="150" placeholder="Loader name"></div><div><label for="attachments">Attachments</label><select id="attachments" name="form_details[attachments]"><option value="none" @selected(old('form_details.attachments',data_get($document->form_details,'attachments')) !== 'attached')>None</option><option value="attached" @selected(old('form_details.attachments',data_get($document->form_details,'attachments')) === 'attached')>Attached</option></select><p class="help">Original loading slip is submitted to obtain the delivery note. Supporting evidence is recorded at handover.</p></div></div>
<div class="mt-4 border-t border-border pt-3"><h3 class="mb-2 text-sm font-bold">Approval signatures</h3><div class="grid grid-cols-2 gap-2 sm:grid-cols-5">@foreach(['Procurement Dept. Head','HRD Dept. Head','Goods recipient','Goods carrier','PT MKM Security'] as $signatory)<div class="rounded border border-border text-center"><div class="bg-primary-soft p-2 text-[10px] font-semibold">{{ $signatory }}</div><div class="h-12"></div><p class="border-t border-border p-1 text-[9px] text-muted">Name &amp; date recorded at the relevant step</p></div>@endforeach</div></div>
</fieldset>
<fieldset class="card" data-type-panel="DISPOSAL" @disabled($selectedType !== 'DISPOSAL') @if($selectedType !== 'DISPOSAL') hidden @endif>
<div class="card-header"><div><h2 class="text-base font-bold text-primary">GOODS RELEASE - DISPOSAL</h2><p class="help">Disposal request and approval form</p></div><span class="badge badge-draft">Draft</span></div>
<div class="m-3 rounded border border-border"><h3 class="bg-primary-soft px-3 py-2 text-xs font-bold text-primary">Document information</h3><div class="grid gap-3 p-3 sm:grid-cols-2">
<div><label for="disposal-number">SPB number</label><input id="disposal-number" value="{{ $document->number ?: 'Assigned automatically after saving' }}" readonly></div>
<div><label for="disposal-date">Release date</label><input type="date" id="disposal-date" name="release_date" value="{{ old('release_date', $document->departure_at?->format('Y-m-d')) }}"></div>
<div><label for="workflow_reference">Workflow reference *</label><input id="workflow_reference" name="workflow_reference" value="{{ old('workflow_reference',$document->workflow_reference) }}" maxlength="100" required></div>
<div><label for="disposal-time">Release time</label><input type="time" id="disposal-time" name="release_time" value="{{ old('release_time', $document->departure_at?->format('H:i')) }}"></div>
<div><label for="disposal-buyer">Buyer *</label><input id="disposal-buyer" name="company" value="{{ old('company',$document->company) }}" maxlength="200" required></div>
<div><label for="disposal-goods">Goods description *</label><textarea id="disposal-goods" name="goods_description" rows="2" maxlength="5000" required>{{ old('goods_description',$document->goods_description) }}</textarea></div>
</div></div>
<div class="m-3 rounded border border-border"><h3 class="bg-primary-soft px-3 py-2 text-xs font-bold text-primary">Approval signatures</h3><div class="grid gap-3 p-3 sm:grid-cols-3">@foreach(['Procurement Dept. Head','HRD / GA Dept. Head','User Dept. Head'] as $signatory)<div class="rounded border border-border text-center"><div class="bg-slate-50 p-2 text-xs font-semibold">{{ $signatory }}</div><div class="h-16"></div><p class="border-t border-border p-2 text-[10px] text-muted">Name &amp; date</p></div>@endforeach</div><p class="px-3 pb-3 text-xs text-amber-800">Approval integration is pending. Disposal can be saved as a draft; these signature spaces do not grant release authorization.</p></div>
</fieldset>
<details class="card mt-3"><summary class="px-3 py-2 text-sm font-bold">Additional release letter details (optional)</summary><div class="grid gap-2 p-3 sm:grid-cols-2 lg:grid-cols-3">
@foreach(['letter_city'=>['Letter city','text',100], 'letter_number'=>['Letter reference number','text',100], 'recipient_hr'=>['HR & GA / Safety recipient','text',255], 'recipient_ppc'=>['PPC recipient','text',255], 'sales_reference'=>['Sales request reference','text',150], 'sales_reference_date'=>['Sales request date','date',null], 'origin_department'=>['Requesting department','text',150], 'disposal_date'=>['Disposal / destruction date','date',null], 'sale_date'=>['Sale date','date',null], 'sale_time'=>['Sale time / schedule','text',100], 'buyer_contact'=>['Buyer contact person','text',200], 'location'=>['Sale / collection location','text',200]] as $key=>[$label,$inputType,$max])
<div><label for="detail-{{ $key }}">{{ $label }}</label><input id="detail-{{ $key }}" type="{{ $inputType }}" name="form_details[{{ $key }}]" value="{{ old('form_details.'.$key,data_get($document->form_details,$key)) }}" @if($max) maxlength="{{ $max }}" @endif></div>
@endforeach
<div class="sm:col-span-2"><label for="notes">Notes</label><textarea id="notes" name="notes" rows="2" maxlength="3000">{{ old('notes',$document->notes) }}</textarea></div>
</div></details>
<p class="my-3 text-xs text-muted" role="status" data-type-hint>Saving a draft does not authorize goods release.</p>
<div class="flex justify-end gap-2"><a href="{{ route('documents.index') }}" class="btn btn-secondary">Cancel</a><button class="btn" type="submit">Save draft</button></div>
</form>
@endsection
