<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

#[Signature('files:remove-temp-public-files')]
#[Description('Command description')]

class RemoveTempFiles extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->removeFiles();
        $this->removeZips();

        return self::SUCCESS;
    }

    private function removeFiles(): void
    {
        $files = Storage::disk('public')->files();

        foreach ($files as $file) {
            if ($file !== '.gitignore') {
                try {
                    Storage::disk('public')->delete($file);
                } catch (\Exception $e) {
                    Log::error($e);
                }
            }
        }
    }

    private function removeZips(): void
    {
        Storage::disk('public')->deleteDirectory('zip');
    }
}
