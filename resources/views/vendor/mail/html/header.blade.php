<tr>
<td class="header">
<a href="{{ config('app.front_end_url') }}" style="display: inline-block;">
@if (trim($slot) === 'Laravel')
<img src="https://res.cloudinary.com/dje12mkro/image/upload/v1751924244/it5wdlvtgkwovzrlafpc.png" class="logo" alt="Logo">
@else
{{ $slot }}
@endif
</a>
</td>
</tr>
