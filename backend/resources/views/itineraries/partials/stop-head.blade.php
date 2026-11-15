{{-- A stop's time, kind, name and photo: short, so it is never split across pages. --}}
<table class="stop-head">
    <tr>
        <td class="t-time">
            <div class="t-start">{{ $stop['start'] }}</div>
            @if($stop['end'])<div class="t-end">to {{ $stop['end'] }}</div>@endif
        </td>
        <td class="t-title k-{{ $stop['kind'] }}">
            <span class="chip chip-{{ $stop['kind'] }}">{{ $stop['badge'] }}</span>
            @if($stop['duration'])<span class="dur">&nbsp;{{ $stop['duration'] }}</span>@endif
            <div class="place">{{ $stop['name'] }}</div>
            @if($stop['town'])<div class="muted">{{ $stop['town'] }}</div>@endif
        </td>
        @if($stop['image'])<td class="t-photo"><img src="{{ $stop['image'] }}" alt=""></td>@endif
    </tr>
</table>
