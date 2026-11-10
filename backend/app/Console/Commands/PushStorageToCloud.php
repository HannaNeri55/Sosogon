<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

/**
 * One-time copy of this computer's uploaded files into the production R2 buckets:
 * storage/app/public to AWS_BUCKET and storage/app/private (business permits) to
 * AWS_PRIVATE_BUCKET. Reads the AWS_* values from .env but leaves local storage as it is
 * (STORAGE_DRIVER stays unset). Files already in the bucket with the same size are
 * skipped, so it can be re-run after an interruption.
 */
class PushStorageToCloud extends Command
{
    protected $signature = 'storage:push-to-cloud {--dry-run : List what would be uploaded without uploading}';

    protected $description = 'Upload local public and private storage files to the R2 buckets';

    public function handle(): int
    {
        foreach (['AWS_ACCESS_KEY_ID', 'AWS_SECRET_ACCESS_KEY', 'AWS_ENDPOINT', 'AWS_BUCKET', 'AWS_PRIVATE_BUCKET'] as $key) {
            if (! env($key)) {
                $this->error("{$key} is not set in .env.");

                return self::FAILURE;
            }
        }

        $failed = $this->push(storage_path('app/public'), env('AWS_BUCKET'))
            + $this->push(storage_path('app/private'), env('AWS_PRIVATE_BUCKET'));

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    /** Uploads every file under $root to $bucket and returns how many failed. */
    private function push(string $root, string $bucket): int
    {
        $disk = $this->bucket($bucket);
        $files = File::allFiles($root);
        $uploaded = $skipped = $failed = 0;

        $this->info("{$root} -> {$bucket} (".count($files).' files)');
        $bar = $this->output->createProgressBar(count($files));

        foreach ($files as $file) {
            $path = str_replace('\\', '/', $file->getRelativePathname());
            $bar->advance();

            if ($path === '.gitignore' || ($disk->exists($path) && $disk->size($path) === $file->getSize())) {
                $skipped++;

                continue;
            }

            if ($this->option('dry-run')) {
                $uploaded++;

                continue;
            }

            $stream = fopen($file->getPathname(), 'r');
            $disk->writeStream($path, $stream) ? $uploaded++ : $failed++;
            fclose($stream);
        }

        $bar->finish();
        $this->newLine();
        $this->line(($this->option('dry-run') ? 'Would upload' : 'Uploaded')." {$uploaded}, skipped {$skipped}, failed {$failed}.");

        return $failed;
    }

    private function bucket(string $bucket): Filesystem
    {
        return Storage::build([
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION', 'auto'),
            'bucket' => $bucket,
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => true,
            'throw' => false,
            'report' => false,
        ]);
    }
}
