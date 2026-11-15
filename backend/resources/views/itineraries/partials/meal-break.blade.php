{{-- A lunch or dinner break taken during a visit, placed in that visit's schedule at its time. --}}
<div class="meal-box">
    <div class="meal-label">{{ str_starts_with($meal['notes'] ?? '', 'Dinner') ? 'Dinner' : 'Lunch' }} break · {{ $meal['slot'] }}</div>
    <div class="place">{{ $meal['name'] }}</div>
    @include('itineraries.partials.stop-details', ['stop' => $meal, 'agenda' => null])
</div>
