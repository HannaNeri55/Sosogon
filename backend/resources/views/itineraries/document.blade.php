<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{{ $itinerary->title }} · SOSOGON</title>
<style>
    @page { margin: 42px 42px 48px; }
    html, body { margin: 0; padding: 0; }
    body { font-family: "DejaVu Sans", Arial, sans-serif; font-size: 10.5px; line-height: 1.5; color: #1f2937; background: #fff; }
    .page { max-width: 760px; margin: 0 auto; }
    a { color: #0a546a; }
    table { width: 100%; border-collapse: collapse; }
    td { vertical-align: top; }
    .brand td { vertical-align: middle; }
    .brand img { height: 42px; }
    .brand-name { font-size: 15px; font-weight: bold; color: #0a546a; letter-spacing: 1px; }
    .brand-sub { color: #6b7280; font-size: 9px; }
    .brand-right { text-align: right; color: #6b7280; font-size: 9px; }
    .rule { border-top: 2px solid #0f7a94; margin: 8px 0 14px; }
    h1 { font-size: 21px; line-height: 1.25; margin: 0 0 8px; color: #0c3946; }
    h2 { font-size: 13px; margin: 18px 0 8px; color: #0a546a; border-bottom: 1px solid #d5eef3; padding-bottom: 3px; }
    .facts td { width: 25%; border: 1px solid #d5eef3; background: #eef8fa; padding: 7px 8px; }
    .fact-label { font-size: 8.5px; color: #6b7280; text-transform: uppercase; letter-spacing: 0.4px; }
    .fact-value { font-weight: bold; color: #0c3946; font-size: 11px; }
    .town-line { margin: 0 0 12px; color: #4b5563; }
    .stay { border: 1px solid #e5e7eb; margin-bottom: 8px; page-break-inside: avoid; }
    .stay td { padding: 8px; }
    .photo { width: 112px; }
    .photo img { width: 100px; height: auto; display: block; }
    .place { font-size: 12px; font-weight: bold; color: #111827; }
    .muted { color: #6b7280; }
    .prefs td { padding: 3px 6px 3px 0; border-bottom: 1px solid #f3f4f6; }
    .prefs td.key { width: 110px; color: #6b7280; }
    ul.notes { margin: 0; padding-left: 15px; }
    ul.notes li { margin-bottom: 3px; }
    .day { page-break-before: always; }
    .day-head { background: #0f7a94; color: #fff; padding: 8px 11px; }
    .day-title { font-size: 14px; font-weight: bold; }
    .day-sub { font-size: 9.5px; }
    .stop { border-bottom: 1px solid #e5e7eb; padding: 10px 0; page-break-inside: avoid; }
    .time { font-weight: bold; color: #0a546a; }
    .badge { color: #4b5563; font-size: 9px; }
    .para { margin: 4px 0 0; }
    .why { margin: 4px 0 0; color: #374151; }
    .flag { margin: 5px 0 0; padding: 4px 7px; background: #fffbeb; border-left: 3px solid #f59e0b; color: #78350f; }
    .activities { margin: 5px 0 0; padding-left: 9px; border-left: 2px solid #d5eef3; }
    .activity { margin-bottom: 3px; }
    .lunch { margin: 7px 0 0 12px; padding: 6px 9px; border: 1px dashed #abdce6; background: #f7fcfd; }
    .lunch-label { font-size: 9px; font-weight: bold; color: #0a546a; }
    .leg { padding: 6px 0 0; color: #4b5563; font-size: 9.5px; page-break-inside: avoid; }
    .overnight { margin-top: 10px; padding: 6px 9px; background: #f3f4f6; }
    .online-note { font-size: 9px; color: #6b7280; }
    @media screen and (max-width: 560px) {
        body { font-size: 14px; }
        .page { padding: 12px; }
        h1 { font-size: 23px; }
        .facts td { display: block; width: auto; margin-bottom: 4px; }
        .photo { width: 92px; }
        .photo img { width: 82px; }
    }
    @media screen and (min-width: 561px) { .page { padding: 20px; } }
</style>
</head>
<body>
<div class="page">
    <table class="brand">
        <tr>
            @if($logo)<td style="width: 90px;"><img src="{{ $logo }}" alt="SOSOGON"></td>@endif
            <td>
                <div class="brand-name">SOSOGON</div>
                <div class="brand-sub">Sorsogon Tourism Information and Management System</div>
            </td>
            <td class="brand-right">Travel itinerary<br>Prepared {{ $generatedAt->format('M j, Y') }}</td>
        </tr>
    </table>
    <div class="rule"></div>

    <h1>{{ $itinerary->title }}</h1>
    @if($towns)<p class="town-line">{{ $towns }}, Sorsogon</p>@endif

    <table class="facts">
        <tr>
            <td><div class="fact-label">Travel dates</div><div class="fact-value">{{ $dates ?? 'Not set' }}</div></td>
            <td><div class="fact-label">Length</div><div class="fact-value">{{ $itinerary->num_days }} day{{ $itinerary->num_days > 1 ? 's' : '' }}</div></td>
            <td><div class="fact-label">Travelers</div><div class="fact-value">{{ $travelers }}</div></td>
            <td><div class="fact-label">Getting around</div><div class="fact-value">{{ $transportLabel }}</div></td>
        </tr>
        <tr>
            <td colspan="2"><div class="fact-label">Total travel distance</div><div class="fact-value">{{ (float) $itinerary->total_distance_km }} km</div></td>
            <td colspan="2"><div class="fact-label">Estimated cost for the group</div><div class="fact-value">{{ $cost ?? 'Not available' }}</div></td>
        </tr>
    </table>

    @if($stays)
        <h2>Where you'll stay</h2>
        @foreach($stays as $stay)
            <table class="stay">
                <tr>
                    @if($stay['image'])<td class="photo"><img src="{{ $stay['image'] }}" alt=""></td>@endif
                    <td>
                        <div class="place">{{ $stay['name'] }}</div>
                        <div class="muted">{{ collect([$stay['town'], $stay['nights']])->filter()->implode(' · ') }}</div>
                        @if($stay['address'])<div>{{ $stay['address'] }}</div>@endif
                        @if($stay['phone'])<div>Contact: {{ $stay['phone'] }}</div>@endif
                        @if($stay['cost'])<div>Stay cost: {{ $stay['cost'] }}</div>@endif
                    </td>
                </tr>
            </table>
        @endforeach
    @elseif($itinerary->accommodation_note)
        <h2>Where you'll stay</h2>
        <p class="para">{{ $itinerary->accommodation_note }}</p>
    @endif

    @if($preferences)
        <h2>Your trip preferences</h2>
        <table class="prefs">
            @foreach($preferences as $label => $value)
                <tr><td class="key">{{ $label }}</td><td>{{ $value }}</td></tr>
            @endforeach
        </table>
    @endif

    <h2>Important travel notes</h2>
    <ul class="notes">
        <li>Times, distances and costs are estimates. Allow extra time for weather and road conditions.</li>
        <li>SOSOGON takes no bookings. Confirm rates, opening hours and availability with each place before you go.</li>
        <li>Transport fares are not included in the estimated cost.</li>
        <li>This document works without internet once saved on your device. Google Maps direction links need a connection.</li>
    </ul>

    @foreach($days as $day)
        <div class="day">
            <div class="day-head">
                <div class="day-title">Day {{ $day['number'] }}@if($day['date']) · {{ $day['date'] }}@endif</div>
                @if($day['town'])<div class="day-sub">{{ $day['town'] }}</div>@endif
            </div>

            @foreach($day['rows'] as $row)
                @php($stop = $row['stop'])
                <div class="stop">
                    <table>
                        <tr>
                            @if($stop['image'])<td class="photo"><img src="{{ $stop['image'] }}" alt=""></td>@endif
                            <td>
                                <div><span class="time">{{ $stop['slot'] }}</span> <span class="badge">· {{ $stop['badge'] }}@if($stop['minutes'] > 0) · {{ $stop['minutes'] }} min @endif</span></div>
                                <div class="place">{{ $stop['name'] }}</div>
                                @if($stop['town'])<div class="muted">{{ $stop['town'] }}</div>@endif
                                @include('itineraries.partials.stop-details', ['stop' => $stop])
                            </td>
                        </tr>
                    </table>

                    @if($row['lunch'])
                        @php($lunch = $row['lunch'])
                        <div class="lunch">
                            <div class="lunch-label">Lunch break during this visit</div>
                            <div><span class="time">{{ $lunch['slot'] }}</span></div>
                            <div class="place">{{ $lunch['name'] }}</div>
                            @include('itineraries.partials.stop-details', ['stop' => $lunch])
                        </div>
                    @endif

                    @if($row['leg'])
                        <div class="leg">
                            <strong>To the next stop:</strong> {{ implode(' · ', $row['leg']['facts']) }}
                            @if($row['leg']['url'])
                                @if($row['leg']['facts']) · @endif<a href="{{ $row['leg']['url'] }}">{{ $row['leg']['linkLabel'] }}</a> <span class="online-note">(needs internet)</span>
                            @endif
                        </div>
                    @endif
                </div>
            @endforeach

            @if($day['overnight'])
                <div class="overnight">Overnight at <strong>{{ $day['overnight'] }}</strong>@if($day['town']) ({{ $day['town'] }})@endif</div>
            @endif
        </div>
    @endforeach
</div>
</body>
</html>
