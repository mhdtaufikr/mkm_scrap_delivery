<form method="GET" action="{{ url()->current() }}" class="no-print mb-3 flex flex-wrap items-end gap-2 rounded-xl border border-border bg-white p-3">
@if(request('queue'))<input type="hidden" name="queue" value="1">@endif
<div class="min-w-40 flex-1"><label for="q">Search documents</label><input id="q" name="q" value="{{ request('q') }}" placeholder="Number, company, driver, vehicle plate"></div>
<div class="w-full sm:w-36"><label for="type">Type</label><select id="type" name="type"><option value="">All types</option value="SCRAP" @selected(request('type') === 'SCRAP')>Monthly Scrap</option><option value="DISPOSAL" @selected(request('type') === 'DISPOSAL')>Disposal</option></select></div>
<div class="w-full sm:w-48"><label for="status">Status</label><select id="status" name="status"><option value="">All statuses</option>@foreach(\App\Models\DeliveryDocument::statusOptions() as $value => $label)<option value="{{ $value }}" @selected(request('status') === $value)>{{ $label }}</option>@endforeach</select></div>
<button type="submit" class="btn">Apply</button><a href="{{ url()->current() }}{{ request('queue') ? '?queue=1' : '' }}" class="btn btn-secondary">Reset</a>
</form>
