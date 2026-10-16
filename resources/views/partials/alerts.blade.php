@if(session('success'))
<div role="status" class="alert-success">{{ session('success') }}</div>
@endif
@if($errors->any())
<div role="alert" class="alert-error"><p class="font-semibold">Please review the fields below.</p><ul class="mt-1 list-inside list-disc">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
@endif
