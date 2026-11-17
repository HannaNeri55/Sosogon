{{-- A lunch or dinner break taken during a visit, placed in that visit's schedule at its time. --}}
<div class="meal-box">
    <table class="meal-head">
        <tr>
            <td>
                <div class="meal-label">{{ str_starts_with($meal['notes'] ?? '', 'Dinner') ? 'Dinner' : 'Lunch' }} break · {{ $meal['slot'] }}</div>
                <div class="place">{{ $meal['name'] }}</div>
            </td>
            @if($meal['image'])<td class="t-photo"><img src="{{ $meal['image'] }}" alt=""></td>@endif
        </tr>
    </table>
    @include('itineraries.partials.stop-details', ['stop' => $meal, 'agenda' => null])
</div>
