<?php

namespace App\Services;

use App\Models\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ZipCreatorService
{
    /**
     * @param  iterable<File>  $files
     */
    public function createZip(iterable $files): string
    {
        $publicDisk = Storage::disk('public');
        $zipPath = 'zip/'.Str::random().'.zip';
        $publicDisk->makeDirectory(dirname($zipPath));
        $zipFile = $publicDisk->path($zipPath);
        $temporaryFiles = [];

        $zip = new \ZipArchive;

        if ($zip->open($zipFile, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) === true) {
            $this->addFilesToZip($zip, $files, '', $temporaryFiles);
        }

        $zip->close();
        $this->cleanupTemporaryFiles($temporaryFiles);

        return $publicDisk->url($zipPath);
    }

    /**
     * @param  iterable<File>  $files
     * @param  list<string>  $temporaryFiles
     */
    private function addFilesToZip(
        \ZipArchive $zip,
        iterable $files,
        string $ancestors = '',
        array &$temporaryFiles = [],
    ): void {
        foreach ($files as $file) {
            if ($file->is_folder) {
                $this->addFilesToZip($zip, $file->children, $ancestors.$file->name.'/', $temporaryFiles);
            } else {
                if (! $file->storage_path || ! Storage::exists($file->storage_path)) {
                    continue;
                }

                $temporaryFile = $this->createTemporaryFileFromStorage($file->storage_path);

                if (! $temporaryFile) {
                    continue;
                }

                $temporaryFiles[] = $temporaryFile;

                $zip->addFile($temporaryFile, $ancestors.$file->name);
            }
        }
    }

    private function createTemporaryFileFromStorage(string $path): ?string
    {
        $readStream = Storage::readStream($path);

        if (! is_resource($readStream)) {
            return null;
        }

        $temporaryFile = tempnam(sys_get_temp_dir(), 'zip-');

        if ($temporaryFile === false) {
            fclose($readStream);

            return null;
        }

        $temporaryStream = fopen($temporaryFile, 'wb');

        if (! is_resource($temporaryStream)) {
            fclose($readStream);
            @unlink($temporaryFile);

            return null;
        }

        stream_copy_to_stream($readStream, $temporaryStream);

        fclose($readStream);
        fclose($temporaryStream);

        return $temporaryFile;
    }

    /**
     * @param  list<string>  $temporaryFiles
     */
    private function cleanupTemporaryFiles(array $temporaryFiles): void
    {
        foreach ($temporaryFiles as $temporaryFile) {
            if (is_file($temporaryFile)) {
                @unlink($temporaryFile);
            }
        }
    }
}
