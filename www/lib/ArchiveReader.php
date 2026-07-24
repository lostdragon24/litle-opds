<?php

// lib/ArchiveReader.php

class ArchiveReader
{
    public function extractFile($archivePath, $internalPath)
    {
        if (!file_exists($archivePath)) {
            return null;
        }

        $ext = strtolower(pathinfo($archivePath, PATHINFO_EXTENSION));

        switch ($ext) {
            case 'zip':
                return $this->extractFromZip($archivePath, $internalPath);
            case 'rar':
                return $this->extractFromRar($archivePath, $internalPath);
            case '7z':
                return $this->extractFrom7z($archivePath, $internalPath);
            default:
                return null;
        }
    }

    private function extractFromZip($archivePath, $internalPath)
    {
        $zip = new ZipArchive();
        if ($zip->open($archivePath) !== true) {
            return null;
        }

        $content = $zip->getFromName($internalPath);
        $zip->close();
        return $content;
    }

    private function extractFromRar($archivePath, $internalPath)
    {
        $rar = rar_open($archivePath);
        if (!$rar) {
            return null;
        }

        $entry = rar_entry_get($rar, $internalPath);
        if (!$entry) {
            rar_close($rar);
            return null;
        }

        $content = $entry->getStream();
        rar_close($rar);

        if ($content) {
            return stream_get_contents($content);
        }
        return null;
    }

    private function extractFrom7z($archivePath, $internalPath)
    {
        // Используем exec для 7z
        $tempDir = sys_get_temp_dir() . '/7z_extract_' . uniqid();
        mkdir($tempDir, 0777, true);

        $cmd = sprintf(
            '7z x -y "%s" -o"%s" "%s" 2>&1',
            $archivePath,
            $tempDir,
            $internalPath
        );

        exec($cmd, $output, $returnCode);

        if ($returnCode !== 0) {
            rmdir($tempDir);
            return null;
        }

        $extractedFile = $tempDir . '/' . basename($internalPath);
        if (!file_exists($extractedFile)) {
            rmdir($tempDir);
            return null;
        }

        $content = file_get_contents($extractedFile);
        unlink($extractedFile);
        rmdir($tempDir);

        return $content;
    }
}
