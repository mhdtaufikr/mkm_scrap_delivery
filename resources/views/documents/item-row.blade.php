<tr data-goods-row><td data-row-number>{{ is_numeric($index) ? $index + 1 : '' }}</td>
@foreach(['code'=>['text',60], 'name'=>['text',255], 'quantity'=>['number',null], 'unit'=>['text',30], 'pallet'=>['text',60], 'posts'=>['text',60], 'remarks'=>['text',255]] as $field=>[$inputType,$max])
<td><input type="{{ $inputType }}" name="items[{{ $index }}][{{ $field }}]" value="{{ $item[$field] ?? '' }}" aria-label="{{ ucfirst($field) }} for item {{ is_numeric($index) ? $index + 1 : '' }}" @required(in_array($field,['name','quantity','unit'])) @if($max) maxlength="{{ $max }}" @endif @if($inputType === 'number') min="{{ $field === 'quantity' ? '0.000001' : '0' }}" step="{{ $field === 'quantity' ? 'any' : '1' }}" @endif></td>
@endforeach
<td><button type="button" data-remove-goods class="px-2 py-2 text-red-700" aria-label="Remove item">&times;</button></td></tr>
