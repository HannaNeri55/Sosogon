<table class="day-head">
    <tr>
        <td class="day-num"><div class="day-label">DAY</div><div class="day-big">{{ $day['number'] }}</div></td>
        <td>
            <div class="day-date">{{ $day['date'] ?? 'Day '.$day['number'] }}</div>
            <div class="day-sum">{{ $day['summary'] }}@if($day['town']) · Staying in {{ $day['town'] }}@endif</div>
        </td>
    </tr>
</table>
