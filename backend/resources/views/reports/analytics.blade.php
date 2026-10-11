<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Reports and Analytics</title>
<style>
    body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #1f2937; }
    h1 { font-size: 20px; margin: 0 0 2px; color: #0E6B4F; }
    h2 { font-size: 14px; margin: 22px 0 8px; color: #0E6B4F; border-bottom: 1px solid #d1d5db; padding-bottom: 4px; }
    .meta { color: #6b7280; margin-bottom: 14px; }
    .stats { width: 100%; border-collapse: separate; border-spacing: 8px 0; margin: 0 -8px; }
    .stat { border: 1px solid #d1d5db; padding: 10px; }
    .stat .n { font-size: 20px; font-weight: bold; color: #0E6B4F; }
    table.data { width: 100%; border-collapse: collapse; }
    table.data th { background: #0E6B4F; color: #fff; text-align: left; padding: 5px 6px; }
    table.data td { padding: 4px 6px; border-bottom: 1px solid #e5e7eb; }
    .r { text-align: right; }
    .empty { color: #6b7280; font-style: italic; }
</style>
</head>
<body>
<h1>Reports and Analytics</h1>
<div class="meta">
    Sorsogon Tourism &middot; Generated {{ $generatedAt->format('F j, Y g:i A') }}@if($generatedBy) by {{ $generatedBy }}@endif &middot; Period: last {{ $days }} days
</div>

<table class="stats">
    <tr>
        <td class="stat"><div>Views (last {{ $days }} days)</div><div class="n">{{ number_format($monthlyViews) }}</div></td>
        <td class="stat"><div>Searches (last {{ $days }} days)</div><div class="n">{{ number_format($monthlySearches) }}</div></td>
    </tr>
</table>

<h2>Visitor Trend</h2>
@if($visitors->isEmpty())
    <p class="empty">No visitor data for this period.</p>
@else
    <table class="data">
        <thead><tr><th>Date</th><th class="r">Page Views</th></tr></thead>
        <tbody>
        @foreach($visitors as $v)
            <tr><td>{{ \Illuminate\Support\Carbon::parse($v->date)->format('M j, Y') }}</td><td class="r">{{ number_format($v->total_views) }}</td></tr>
        @endforeach
        </tbody>
    </table>
@endif

<h2>Popular Destinations</h2>
@if($popular->isEmpty())
    <p class="empty">No destinations yet.</p>
@else
    <table class="data">
        <thead><tr><th>#</th><th>Destination</th><th class="r">Rating</th><th class="r">Reviews</th><th class="r">Views</th></tr></thead>
        <tbody>
        @foreach($popular as $i => $d)
            <tr>
                <td>{{ $i + 1 }}</td>
                <td>{{ $d->name }}</td>
                <td class="r">{{ number_format($d->average_rating, 1) }}</td>
                <td class="r">{{ number_format($d->total_reviews) }}</td>
                <td class="r">{{ number_format($d->view_count) }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>
@endif

<h2>Popular Searches</h2>
@if($searches->isEmpty())
    <p class="empty">No search data available.</p>
@else
    <table class="data">
        <thead><tr><th>Search term</th><th class="r">Searches</th></tr></thead>
        <tbody>
        @foreach($searches as $s)
            <tr><td>{{ $s->query }}</td><td class="r">{{ number_format($s->count) }}</td></tr>
        @endforeach
        </tbody>
    </table>
@endif
</body>
</html>
