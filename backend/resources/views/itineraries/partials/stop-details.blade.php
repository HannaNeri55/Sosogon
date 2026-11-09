@if($stop['description'])<p class="para">{{ $stop['description'] }}</p>@endif
@if($stop['notes'])<p class="para">{{ $stop['notes'] }}</p>@endif
@if($stop['reason'])<p class="why"><strong>Why we recommend it:</strong> {{ $stop['reason'] }}</p>@endif
@if($stop['preferenceNote'])<p class="flag">{{ $stop['preferenceNote'] }}</p>@endif
@if($stop['activities'])
    <div class="activities">
        @foreach($stop['activities'] as $activity)
            <div class="activity">
                <span class="time">{{ $activity['time'] ?? '' }}</span> <strong>{{ $activity['name'] ?? '' }}</strong>
                @if(! empty($activity['description']))<br><span class="muted">{{ $activity['description'] }}</span>@endif
            </div>
        @endforeach
    </div>
@endif
@if($stop['picks'])
    <p class="para"><strong>Suggested:</strong>
        @foreach($stop['picks'] as $pick){{ $pick['name'] }} (₱{{ number_format($pick['price']) }}@if($pick['quantity'] > 1) × {{ $pick['quantity'] }}@endif)@if(! $loop->last), @endif @endforeach
    </p>
@endif
@if($stop['costNote'])<p class="para muted">@if($stop['cost']){{ $stop['cost'] }} · @endif{{ $stop['costNote'] }}</p>@endif
