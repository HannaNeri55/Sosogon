<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{{ $itinerary->title }} · SOSOGON</title>
<style>
    /* One template for the PDF (DomPDF: tables for layout, no flexbox), printing and the offline copy.
       Long stops are blocks, not table rows, so they can continue on the next page. */
    @page { margin: 40px 40px 52px; }
    html, body { margin: 0; padding: 0; }
    body { font-family: "DejaVu Sans", Arial, sans-serif; font-size: 10px; line-height: 1.5; color: #1f2937; background: #fff; }
    .page { max-width: 760px; margin: 0 auto; }
    a { color: #0a546a; }
    table { width: 100%; border-collapse: collapse; }
    td { vertical-align: top; }
    p { margin: 0; }
    .muted { color: #6b7280; }

    .brand td { vertical-align: middle; }
    .brand-logo { width: 110px; }
    .brand-logo img { height: 44px; }
    .brand-name { font-size: 14px; font-weight: bold; color: #0a546a; letter-spacing: 1.5px; }
    .brand-sub { color: #6b7280; font-size: 8.5px; }
    .brand-right { text-align: right; color: #6b7280; font-size: 8.5px; }
    .doc-kind { font-size: 9px; font-weight: bold; color: #0f7a94; letter-spacing: 1.2px; }

    .cover { margin-top: 12px; background: #0c3946; color: #fff; padding: 16px 18px; border-radius: 8px; }
    .eyebrow { font-size: 8.5px; letter-spacing: 1.5px; color: #9fd8e4; font-weight: bold; }
    h1 { font-size: 20px; line-height: 1.25; margin: 4px 0 4px; color: #fff; }
    .cover-towns { color: #cfeaf0; font-size: 10.5px; }

    .facts { margin-top: 10px; border-collapse: separate; border-spacing: 6px 6px; margin-left: -6px; width: 101.6%; }
    .facts td { background: #eef8fa; border-radius: 6px; padding: 7px 9px; width: 33.3%; }
    .fact-label { font-size: 7.5px; color: #4b6b75; text-transform: uppercase; letter-spacing: 0.8px; }
    .fact-value { font-weight: bold; color: #0c3946; font-size: 11px; }

    h2 { font-size: 12.5px; margin: 16px 0 7px; color: #0a546a; padding-bottom: 3px; border-bottom: 2px solid #d5eef3; }

    .stay { border: 1px solid #e5e7eb; border-radius: 6px; margin-bottom: 7px; page-break-inside: avoid; }
    .stay td { padding: 8px; }
    .stay-photo { width: 104px; }
    .stay-photo img { width: 96px; height: 72px; border-radius: 4px; }
    .place { font-size: 12px; font-weight: bold; color: #111827; line-height: 1.3; }

    .glance { font-size: 9.5px; }
    .glance td { padding: 3px 6px; border-bottom: 1px solid #f0f2f4; }
    .glance-day td { background: #0f7a94; color: #fff; font-weight: bold; padding: 5px 8px; border-bottom: 0; }
    .glance-day .glance-sum { font-weight: normal; color: #d9f1f6; }
    .g-time { width: 128px; color: #0a546a; font-weight: bold; white-space: nowrap; }
    .g-kind { width: 150px; text-align: right; white-space: nowrap; }
    .g-sub { padding-left: 14px; color: #4b5563; }

    ul.notes { margin: 0; padding-left: 14px; }
    ul.notes li { margin-bottom: 2px; }

    .chip { display: inline-block; padding: 1px 7px; border-radius: 8px; font-size: 8px; font-weight: bold; letter-spacing: 0.3px; }
    .chip-visit { background: #dff1f5; color: #0a546a; }
    .chip-meal { background: #ffedd5; color: #9a3412; }
    .chip-stay { background: #ede9fe; color: #5b21b6; }
    .chip-shop { background: #fce7f3; color: #9d174d; }

    .day { margin-top: 18px; }
    .day-start { page-break-inside: avoid; }
    .day-head { background: #0f7a94; color: #fff; border-radius: 8px; margin-bottom: 10px; }
    .day-head td { vertical-align: middle; padding: 9px 12px; }
    .day-num { width: 46px; text-align: center; background: #0c3946; border-radius: 8px 0 0 8px; }
    .day-label { font-size: 7.5px; letter-spacing: 1.5px; color: #9fd8e4; font-weight: bold; }
    .day-big { font-size: 22px; font-weight: bold; line-height: 1.1; }
    .day-date { font-size: 13px; font-weight: bold; }
    .day-sum { font-size: 9px; color: #d9f1f6; }

    .stop-head { margin-top: 4px; page-break-inside: avoid; }
    .t-time { width: 76px; padding-right: 8px; text-align: right; }
    .t-start { font-size: 11px; font-weight: bold; color: #0c3946; white-space: nowrap; }
    .t-end { font-size: 8.5px; color: #6b7280; white-space: nowrap; }
    .t-title { padding-left: 10px; border-left: 3px solid #0f7a94; }
    .t-photo { width: 98px; padding-left: 8px; }
    .t-photo img { width: 96px; height: 72px; border-radius: 4px; }
    .dur { font-size: 8.5px; color: #6b7280; }
    .k-meal { border-left-color: #ea580c; }
    .k-stay { border-left-color: #7c3aed; }
    .k-shop { border-left-color: #db2777; }

    .stop-body { margin-left: 84px; padding: 4px 0 2px 10px; border-left: 3px solid #cfe9ef; }
    .body-meal { border-left-color: #fed7aa; }
    .body-stay { border-left-color: #ddd6fe; }
    .body-shop { border-left-color: #fbcfe8; }
    .stop-body p { margin-top: 4px; }
    .about { color: #4b5563; font-size: 9.5px; }
    .plan { color: #111827; }
    .why { color: #374151; }
    .flag { padding: 5px 8px; background: #fffbeb; border-left: 3px solid #f59e0b; color: #78350f; font-size: 9.5px; }
    .cost { font-size: 9.5px; color: #374151; }
    .cost strong { color: #0c3946; }

    .agenda { margin-top: 6px; }
    .agenda-title { font-size: 7.5px; font-weight: bold; letter-spacing: 1px; color: #0a546a; text-transform: uppercase; }
    .a-item { padding: 3px 0 3px 8px; border-left: 2px solid #9fd8e4; margin-top: 3px; page-break-inside: avoid; }
    .a-time { font-weight: bold; color: #0a546a; font-size: 9px; }
    .a-desc { color: #6b7280; font-size: 9px; }
    .meal-box { margin-top: 5px; padding: 6px 9px; background: #fff7ed; border-left: 3px solid #ea580c; border-radius: 0 6px 6px 0; }
    .meal-label { font-size: 7.5px; font-weight: bold; letter-spacing: 1px; color: #9a3412; text-transform: uppercase; }
    .meal-box .place { font-size: 11px; }

    .leg { margin: 5px 0 6px 84px; padding: 4px 9px; background: #f4f6f8; border-radius: 6px; color: #4b5563; font-size: 8.5px; page-break-inside: avoid; }
    .leg-arrow { color: #0f7a94; font-weight: bold; }
    .online-note { color: #9ca3af; }
    .overnight { margin: 10px 0 0 84px; padding: 7px 10px; background: #f3effe; border-radius: 6px; color: #4c1d95; page-break-inside: avoid; }

    .extras { margin-top: 18px; page-break-inside: avoid; }
    .prefs td { padding: 3px 6px 3px 0; border-bottom: 1px solid #f3f4f6; }
    .prefs td.key { width: 110px; color: #6b7280; }

    @media screen and (max-width: 560px) {
        body { font-size: 14px; }
        .page { padding: 12px; }
        h1 { font-size: 23px; }
        .facts td { display: block; width: auto; }
        .g-time { width: auto; white-space: normal; }
        .stop-head td { display: block; width: auto; text-align: left; }
        .t-time { padding: 0 0 2px; }
        .t-start, .t-end { display: inline; }
        .t-photo { padding: 6px 0 0 13px; }
        .t-photo img { width: 160px; }
        .stop-body, .leg, .overnight { margin-left: 0; }
        /* The PDF's small print sizes, scaled to the phone's 14px text. */
        .place { font-size: 17px; }
        .meal-box .place { font-size: 15px; }
        .t-start, .day-date { font-size: 16px; }
        .about, .flag, .cost, .a-desc, .a-time, .leg, .glance, .day-sum, .t-end, .dur, .fact-value, .brand-sub, .brand-right, .cover-towns { font-size: 13px; }
        .chip, .fact-label, .eyebrow, .agenda-title, .meal-label, .day-label, .doc-kind { font-size: 11px; }
    }
    @media screen and (min-width: 561px) { .page { padding: 20px; } }
</style>
</head>
<body>
<div class="page">
    <table class="brand">
        <tr>
            @if($logo)<td class="brand-logo"><img src="{{ $logo }}" alt="SOSOGON"></td>@endif
            <td>
                <div class="brand-name">SOSOGON</div>
                <div class="brand-sub">Sorsogon Tourism Information and Management System</div>
            </td>
            <td class="brand-right"><div class="doc-kind">TRAVEL ITINERARY</div>Prepared {{ $generatedAt->format('M j, Y') }}</td>
        </tr>
    </table>

    <div class="cover">
        <div class="eyebrow">YOUR TRIP TO SORSOGON</div>
        <h1>{{ $itinerary->title }}</h1>
        @if($towns)<div class="cover-towns">{{ $towns }}, Sorsogon</div>@endif
    </div>

    <table class="facts">
        <tr>
            <td><div class="fact-label">Travel dates</div><div class="fact-value">{{ $dates ?? 'Not set' }}</div></td>
            <td><div class="fact-label">Length</div><div class="fact-value">{{ $itinerary->num_days }} day{{ $itinerary->num_days > 1 ? 's' : '' }} · {{ $travelers }} traveler{{ $travelers > 1 ? 's' : '' }}</div></td>
            <td><div class="fact-label">Getting around</div><div class="fact-value">{{ $transportLabel }}</div></td>
        </tr>
        <tr>
            <td><div class="fact-label">Total travel distance</div><div class="fact-value">{{ (float) $itinerary->total_distance_km }} km</div></td>
            <td colspan="2"><div class="fact-label">Estimated cost for the group</div><div class="fact-value">{{ $cost ?? 'Not available' }}</div></td>
        </tr>
    </table>

    @if($stays)
        <h2>Where you'll stay</h2>
        @foreach($stays as $stay)
            <table class="stay">
                <tr>
                    @if($stay['image'])<td class="stay-photo"><img src="{{ $stay['image'] }}" alt=""></td>@endif
                    <td>
                        <div class="place">{{ $stay['name'] }}</div>
                        <div class="muted">{{ collect([$stay['town'], $stay['nights']])->filter()->implode(' · ') }}</div>
                        @if($stay['address'])<div>{{ $stay['address'] }}</div>@endif
                        @if($stay['phone'])<div>Contact: {{ $stay['phone'] }}</div>@endif
                        @if($stay['cost'])<div>Stay cost: <strong>{{ $stay['cost'] }}</strong></div>@endif
                    </td>
                </tr>
            </table>
        @endforeach
    @elseif($itinerary->accommodation_note)
        <h2>Where you'll stay</h2>
        <p>{{ $itinerary->accommodation_note }}</p>
    @endif

    <h2>Before you go</h2>
    <ul class="notes">
        <li>Times, distances and costs are estimates. Allow extra time for weather and road conditions.</li>
        <li>SOSOGON takes no bookings. Confirm rates, opening hours and availability with each place before you go.</li>
        <li>Transport fares are not included in the estimated cost.</li>
        <li>This document works without internet once saved on your device. Google Maps direction links need a connection.</li>
    </ul>

    <h2>Trip at a glance</h2>
    <table class="glance">
        @foreach($days as $day)
            <tr class="glance-day">
                <td colspan="3">Day {{ $day['number'] }}@if($day['date']) · {{ $day['date'] }}@endif <span class="glance-sum">&nbsp; {{ $day['summary'] }}</span></td>
            </tr>
            @foreach($day['rows'] as $row)
                <tr>
                    <td class="g-time">{{ $row['stop']['slot'] }}</td>
                    <td>{{ $row['stop']['name'] }}</td>
                    <td class="g-kind"><span class="chip chip-{{ $row['stop']['kind'] }}">{{ $row['stop']['badge'] }}</span></td>
                </tr>
                @if($row['lunch'])
                    <tr>
                        <td class="g-time g-sub">{{ $row['lunch']['slot'] }}</td>
                        <td class="g-sub">{{ $row['lunch']['name'] }} <span class="muted">(during this visit)</span></td>
                        <td class="g-kind"><span class="chip chip-meal">{{ $row['lunch']['badge'] }}</span></td>
                    </tr>
                @endif
            @endforeach
        @endforeach
    </table>

    @foreach($days as $day)
        <div class="day">
            @foreach($day['rows'] as $row)
                @php($stop = $row['stop'])
                @if($loop->first)
                    {{-- The day's heading stays on the same page as its first stop. --}}
                    <div class="day-start">
                        @include('itineraries.partials.day-head', ['day' => $day])
                        @include('itineraries.partials.stop-head', ['stop' => $stop])
                    </div>
                @else
                    @include('itineraries.partials.stop-head', ['stop' => $stop])
                @endif
                <div class="stop-body body-{{ $stop['kind'] }}">
                    @include('itineraries.partials.stop-details', ['stop' => $stop, 'agenda' => $row['agenda']])

                    @if($row['lunch'] && ! $row['agenda'])
                        @include('itineraries.partials.meal-break', ['meal' => $row['lunch']])
                    @endif
                </div>

                @if($row['leg'])
                    <div class="leg">
                        <span class="leg-arrow">&darr; Next stop:</span> {{ implode(' · ', $row['leg']['facts']) }}
                        @if($row['leg']['url'])
                            @if($row['leg']['facts']) · @endif<a href="{{ $row['leg']['url'] }}">{{ $row['leg']['linkLabel'] }}</a> <span class="online-note">(needs internet)</span>
                        @endif
                    </div>
                @endif
            @endforeach

            @if($day['overnight'])
                <div class="overnight">Overnight at <strong>{{ $day['overnight'] }}</strong>@if($day['town']) ({{ $day['town'] }})@endif</div>
            @endif
        </div>
    @endforeach

    @if($preferences)
        <div class="extras">
            <h2>Your trip preferences</h2>
            <table class="prefs">
                @foreach($preferences as $label => $value)
                    <tr><td class="key">{{ $label }}</td><td>{{ $value }}</td></tr>
                @endforeach
            </table>
        </div>
    @endif
</div>
</body>
</html>
