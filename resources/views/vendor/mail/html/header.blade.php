<tr>
<td class="header">
<a href="{{ config('app.front_end_url') }}" style="display: inline-block;">
@if (trim($slot) === 'Laravel')
<img src="https://res.cloudinary.com/heyslim/image/upload/v1752370446/heySlim-logo-dark_t77nvy.png" class="logo" alt="Logo">
@else
{{ $slot }}
@endif
</a>
</td>
</tr>
