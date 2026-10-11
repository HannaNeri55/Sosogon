<?php

namespace Database\Seeders;

use App\Models\Event;
use App\Models\Municipality;
use App\Models\User;
use App\Services\ImageOptimizationService;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class EventSeeder extends Seeder
{
    public function __construct(private ImageOptimizationService $images) {}

    private array $festivals = [
        [
            'title' => 'Kasanggayahan Festival',
            'municipality' => null,
            'description' => "The \"Festival of Festivals\" for the entire province, commemorating Sorsogon's foundation anniversary as an independent province on October 17, 1894.",
            'start' => [10, 1],
            'end' => [10, 31],
            'is_featured' => true,
        ],
        [
            'title' => 'Pili Festival & Sorsogon City Fiesta',
            'municipality' => 'Sorsogon City',
            'description' => "Sorsogon City's City Fiesta honoring its patron saints St. Peter and St. Paul, celebrated together with the Pili Festival showcasing the pili nut and tree indigenous to the province.",
            'start' => [6, 28],
            'end' => [6, 29],
        ],
        [
            'title' => 'Barcelona Town Fiesta',
            'municipality' => 'Barcelona',
            'description' => "Barcelona's town fiesta in honor of St. Joseph the Worker.",
            'start' => [5, 19],
            'end' => [5, 19],
        ],
        [
            'title' => 'Padaraw Festival & Bulan Town Fiesta',
            'municipality' => 'Bulan',
            'description' => 'A thanksgiving for the bounties of land and sea, with fishermen dancing through the streets of Bulan carrying the padaraw torchlights used to draw fish to their nets.',
            'start' => [5, 30],
            'end' => [5, 30],
        ],
        [
            'title' => 'Agingay Festival & Bulusan Town Fiesta',
            'municipality' => 'Bulusan',
            'description' => "Bulusan's town fiesta, featuring the Agingay Festival which depicts local folklore.",
            'start' => [7, 25],
            'end' => [7, 25],
        ],
        [
            'title' => 'Gugurang Festival & Casiguran Town Fiesta',
            'municipality' => 'Casiguran',
            'description' => "Casiguran's town fiesta in honor of Our Lady of the Most Holy Rosary, its patroness.",
            'start' => [9, 30],
            'end' => [10, 7],
        ],
        [
            'title' => 'Unod Festival',
            'municipality' => 'Castilla',
            'description' => "A week of thanksgiving for Castilla's harvest of agricultural root crops.",
            'start' => [10, 1],
            'end' => [10, 7],
        ],
        [
            'title' => 'Castilla Town Fiesta',
            'municipality' => 'Castilla',
            'description' => "Castilla's traditional fiesta in honor of St. John the Baptist.",
            'start' => [6, 24],
            'end' => [6, 24],
        ],
        [
            'title' => 'Butanding Arribada Festival',
            'municipality' => 'Donsol',
            'description' => "Marks the peak of Donsol's whale shark (butanding) season.",
            'start' => [4, 24],
            'end' => [4, 30],
        ],
        [
            'title' => 'Donsol Town Fiesta',
            'municipality' => 'Donsol',
            'description' => "Donsol's traditional town fiesta in honor of St. Joseph the Worker.",
            'start' => [5, 19],
            'end' => [5, 19],
        ],
        [
            'title' => 'Ginubat Festival & Gubat Town Fiesta',
            'municipality' => 'Gubat',
            'description' => "Gubat's town fiesta in honor of St. Anthony of Padua, with street dancing by colorfully-attired dancers.",
            'start' => [6, 13],
            'end' => [6, 13],
        ],
        [
            'title' => 'Hin-ay Festival & Irosin Town Fiesta',
            'municipality' => 'Irosin',
            'description' => "Irosin's town fiesta in honor of St. Michael the Archangel, held alongside the Hin-ay (Paray) Festival.",
            'start' => [9, 28],
            'end' => [9, 29],
        ],
        [
            'title' => 'Bagacawa Festival & Juban Town Fiesta',
            'municipality' => 'Juban',
            'description' => "Juban's town fiesta, known locally as the Bagacawa Festival.",
            'start' => [7, 19],
            'end' => [7, 19],
        ],
        [
            'title' => 'Magalleones Festival & Magallanes Town Fiesta',
            'municipality' => 'Magallanes',
            'description' => "Commemorates Magallanes' Spanish-era shipbuilding history and honors Our Lady of Mt. Carmel.",
            'start' => [7, 9],
            'end' => [7, 16],
        ],
        [
            'title' => 'Matnog Town Fiesta',
            'municipality' => 'Matnog',
            'description' => "Matnog's town fiesta in honor of the Holy Infant Jesus.",
            'start' => [5, 16],
            'end' => [5, 16],
        ],
        [
            'title' => 'Parau Festival & Pilar Town Fiesta',
            'municipality' => 'Pilar',
            'description' => "Honors Pilar's maritime heritage — the traditional paraw boat — and its patroness, Our Lady of the Pillar.",
            'start' => [10, 12],
            'end' => [10, 12],
        ],
        [
            'title' => 'Prieto Diaz Town Fiesta',
            'municipality' => 'Prieto Diaz',
            'description' => "Prieto Diaz's town fiesta in honor of St. Lawrence.",
            'start' => [8, 10],
            'end' => [8, 10],
        ],
        [
            'title' => 'Santa Magdalena Town Fiesta',
            'municipality' => 'Santa Magdalena',
            'description' => "Santa Magdalena's town fiesta in honor of St. Mary Magdalene.",
            'start' => [7, 22],
            'end' => [7, 22],
        ],
    ];

    private array $defaultVisitorInfo = [
        'what_to_wear' => "Light, comfortable clothing suited to Bicol's tropical heat",
        'what_to_bring' => 'Water, sun protection, cash, and a light rain jacket',
        'travel_tips' => [
            'Roads near the town plaza are often closed to traffic during processions and parades — plan extra travel time.',
            'Keep valuables close in crowded areas.',
            'Modest dress is appreciated for the accompanying church services.',
        ],
    ];

    public function run(): void
    {
        $admin = User::where('role_id', 1)->first();

        if (! $admin) {
            $this->command->error('EventSeeder needs an admin user (role_id 1) to exist — run AdminUserSeeder first.');

            return;
        }

        $today = Carbon::today();

        foreach ($this->festivals as $festival) {
            $municipality = $festival['municipality']
                ? Municipality::where('name', $festival['municipality'])->first()
                : null;

            [$start, $end] = $this->nextOccurrence(
                $festival['start'][0],
                $festival['start'][1],
                $festival['end'][0],
                $festival['end'][1],
                $today
            );

            Event::updateOrCreate(
                ['title' => $festival['title']],
                [
                    'description' => $festival['description'],
                    'location' => $municipality ? null : $festival['municipality'],
                    'municipality_id' => $municipality?->id,
                    'image_path' => $this->resolveImagePath($festival['title']),
                    'start_date' => $start,
                    'end_date' => $end,
                    'is_featured' => $festival['is_featured'] ?? false,
                    'status' => 'published',
                    'created_by' => $admin->id,
                    'what_to_wear' => $this->defaultVisitorInfo['what_to_wear'],
                    'what_to_bring' => $this->defaultVisitorInfo['what_to_bring'],
                    'travel_tips' => $this->defaultVisitorInfo['travel_tips'],

                ]
            );
        }
    }

    private function nextOccurrence(int $startMonth, int $startDay, int $endMonth, int $endDay, Carbon $today): array
    {
        $year = $today->year;
        $start = Carbon::createFromDate($year, $startMonth, $startDay)->startOfDay();
        $end = Carbon::createFromDate($year, $endMonth, $endDay)->startOfDay();

        if ($end->lt($today->copy()->startOfDay())) {
            $start->addYear();
            $end->addYear();
        }

        return [$start->toDateString(), $end->toDateString()];
    }

    private function resolveImagePath(string $title): string
    {
        $slug = Str::slug($title);
        $sourcePath = storage_path("app/seed-images/events/{$slug}.jpg");

        if (file_exists($sourcePath)) {
            return $this->images->storeFromPath($sourcePath, 'events')['image_path'];
        }

        return $this->ensurePlaceholderImage($title);
    }

    private function ensurePlaceholderImage(string $title): string
    {
        $path = 'events/'.Str::slug($title).'.svg';

        if (! Storage::disk('public')->exists($path)) {
            Storage::disk('public')->put($path, $this->buildPlaceholderSvg($title));
        }

        return $path;
    }

    private function buildPlaceholderSvg(string $title): string
    {
        $palette = ['#0E6B4F', '#2B7FBF', '#E8A838', '#094A37', '#1B557F'];
        $color = $palette[crc32($title) % count($palette)];
        $lines = $this->wrapTitle($title);
        $lineHeight = 40;
        $blockHeight = count($lines) * $lineHeight;
        $firstLineY = 225 - $blockHeight / 2 + $lineHeight * 0.75;

        $textElements = '';
        foreach ($lines as $i => $line) {
            $y = $firstLineY + $i * $lineHeight;
            $safeLine = htmlspecialchars($line, ENT_XML1 | ENT_QUOTES, 'UTF-8');
            $textElements .= "\n  <text x=\"400\" y=\"{$y}\" font-family=\"Arial, sans-serif\" font-size=\"32\" font-weight=\"700\" fill=\"#fff\" text-anchor=\"middle\">{$safeLine}</text>";
        }
        $subtitleY = $firstLineY + count($lines) * $lineHeight + 20;

        return <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 800 450">
  <rect width="800" height="450" fill="{$color}"/>
  <circle cx="700" cy="60" r="120" fill="#fff" opacity="0.08"/>
  <circle cx="80" cy="400" r="160" fill="#fff" opacity="0.08"/>{$textElements}
  <text x="400" y="{$subtitleY}" font-family="Arial, sans-serif" font-size="16" fill="#fff" opacity="0.8" text-anchor="middle">Sorsogon Province</text>
</svg>
SVG;
    }

    private function wrapTitle(string $title, int $maxCharsPerLine = 24): array
    {
        $words = explode(' ', $title);
        $lines = [];
        $current = '';

        foreach ($words as $word) {
            $candidate = trim($current.' '.$word);
            if (mb_strlen($candidate) > $maxCharsPerLine && $current !== '') {
                $lines[] = $current;
                $current = $word;
            } else {
                $current = $candidate;
            }
        }
        if ($current !== '') {
            $lines[] = $current;
        }

        return $lines;
    }
}
