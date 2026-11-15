@if($stop['description'])<p class="about">{{ $stop['description'] }}</p>@endif
@if($stop['notes'])<p class="plan">{{ $stop['notes'] }}</p>@endif
@if($stop['reason'])<p class="why"><strong>Why we recommend it:</strong> {{ $stop['reason'] }}</p>@endif
@if($agenda ?? null)
    <div class="agenda">
        @foreach($agenda as $entry)
            @if($entry['type'] === 'meal')
                @if($loop->first)<div class="agenda-title">Your schedule here</div>@endif
                @include('itineraries.partials.meal-break', ['meal' => $entry['meal']])
            @else
                <div class="a-item">
                    @if($loop->first)<div class="agenda-title">Your schedule here</div>@endif
                    <span class="a-time">{{ $entry['time'] }}</span> <strong>{{ $entry['name'] }}</strong>
                    @if($entry['description'])<div class="a-desc">{{ $entry['description'] }}</div>@endif
                </div>
            @endif
        @endforeach
    </div>
@elseif($stop['activities'])
    <div class="agenda">
        @foreach($stop['activities'] as $activity)
            <div class="a-item">
                <span class="a-time">{{ $activity['time'] ?? '' }}</span> <strong>{{ $activity['name'] ?? '' }}</strong>
                @if(! empty($activity['description']))<div class="a-desc">{{ $activity['description'] }}</div>@endif
            </div>
        @endforeach
    </div>
@endif
@if($stop['preferenceNote'])<p class="flag"><strong>Good to know:</strong> {{ $stop['preferenceNote'] }}</p>@endif
@if($stop['picks'])
    <p><strong>Suggested:</strong>
        @foreach($stop['picks'] as $pick){{ $pick['name'] }} (₱{{ number_format($pick['price']) }}@if($pick['quantity'] > 1) × {{ $pick['quantity'] }}@endif)@if(! $loop->last), @endif @endforeach
    </p>
@endif
@if($stop['costNote'])<p class="cost">Cost: @if($stop['cost'])<strong>{{ $stop['cost'] }}</strong> · @endif{{ $stop['costNote'] }}</p>@endif
