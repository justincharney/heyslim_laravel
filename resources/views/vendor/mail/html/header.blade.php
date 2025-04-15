<tr>
<td class="header">
<a href="{{ config('app.front_end_url') }}" style="display: inline-block;">
@if (trim($slot) === 'Laravel')
<img src="{{ asset('images/logo.png') }}" class="logo" alt="Logo">
@else
{{ $slot }}
@endif
</a>
</td>
</tr>
