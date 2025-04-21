<tr>
<td class="header">
<a href="{{ config('app.front_end_url') }}" style="display: inline-block;">
@if (trim($slot) === 'Laravel')
<img src="https://res.cloudinary.com/dje12mkro/image/upload/v1745259919/h6i2qn6j5jgwaqzlghoa.png" class="logo" alt="Logo">
@else
{{ $slot }}
@endif
</a>
</td>
</tr>
