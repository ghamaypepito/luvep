<?php

namespace App\Services\Hr;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class HrFile
{
    public function validate(UploadedFile $file): array
    {
        Validator::make(['file' => $file], ['file' => 'required|file|max:10240'])->validate();
        $path = $file->getRealPath();
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path);
        $extension = strtolower($file->getClientOriginalExtension());
        $valid = $file->getSize() > 0 && match ($extension) {
            'pdf' => $mime === 'application/pdf',
            'png' => $mime === 'image/png',
            'jpg', 'jpeg' => $mime === 'image/jpeg',
            'doc' => in_array($mime, ['application/msword', 'application/x-ole-storage', 'application/CDFV2'], true) && $this->doc($path),
            'docx' => in_array($mime, ['application/zip', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/octet-stream'], true) && $this->docx($path),
            default => false,
        };
        if (! $valid) {
            throw ValidationException::withMessages(['file' => 'Upload a non-empty PDF, DOC, DOCX, PNG or JPEG whose filename matches its content (maximum 10 MiB).']);
        }

        return ['mime' => match ($extension) {
            'doc' => 'application/msword', 'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', default => $mime,
        }, 'extension' => $extension, 'original_filename' => mb_substr(basename(str_replace('\\', '/', $file->getClientOriginalName())), 0, 200), 'size' => $file->getSize(), 'sha256' => hash_file('sha256', $path)];
    }

    private function doc(string $path): bool
    {
        $bytes = file_get_contents($path);
        if (strlen($bytes) < 512 || substr($bytes, 0, 8) !== "\xd0\xcf\x11\xe0\xa1\xb1\x1a\xe1") {
            return false;
        }
        $short = fn ($offset) => unpack('v', substr($bytes, $offset, 2))[1];
        $number = fn ($offset) => unpack('V', substr($bytes, $offset, 4))[1];
        $shift = $short(30);
        if ($short(28) !== 0xFFFE || ! in_array($shift, [9, 12], true) || $short(32) !== 6
            || $short(26) !== ($shift === 9 ? 3 : 4) || $number(56) !== 4096) {
            return false;
        }
        $sectorSize = 1 << $shift;
        $sectors = intdiv(strlen($bytes), $sectorSize) - 1;
        if (strlen($bytes) % $sectorSize !== 0 || $sectors < 2 || $number(44) < 1 || $number(44) > (int) ceil($sectors / ($sectorSize / 4)) + 1
            || $number(72) > (int) ceil(max(0, $number(44) - 109) / ($sectorSize / 4 - 1))
            || $number(64) > (int) ceil($sectors / 16)) {
            return false;
        }
        $sector = fn ($id) => $id < $sectors ? substr($bytes, ($id + 1) * $sectorSize, $sectorSize) : null;
        $fatSectors = array_values(unpack('V*', substr($bytes, 76, 436)));
        $difat = $number(68);
        $visited = [];
        for ($i = 0; $i < $number(72); $i++) {
            if (isset($visited[$difat]) || ($block = $sector($difat)) === null) {
                return false;
            }
            $visited[$difat] = true;
            $ids = array_values(unpack('V*', $block));
            $difat = array_pop($ids);
            $fatSectors = [...$fatSectors, ...$ids];
        }
        $fat = [];
        $visited = [];
        foreach (array_slice($fatSectors, 0, $number(44)) as $id) {
            if (isset($visited[$id]) || ($block = $sector($id)) === null) {
                return false;
            }
            $visited[$id] = true;
            $fat = [...$fat, ...array_values(unpack('V*', $block))];
        }
        $chain = function ($id, array $table, int $unit, string $source) {
            $seen = [];
            $result = '';
            while ($id !== 0xFFFFFFFE) {
                if (isset($seen[$id]) || ! isset($table[$id]) || ($id + 1) * $unit > strlen($source)) {
                    return null;
                }
                $seen[$id] = true;
                $result .= substr($source, $id * $unit, $unit);
                $id = $table[$id];
            }

            return $result;
        };
        $source = substr($bytes, $sectorSize);
        $directory = $chain($number(48), $fat, $sectorSize, $source);
        if ($directory === null || strlen($directory) < 128 || ord($directory[66]) !== 5) {
            return false;
        }
        $entryNumber = fn ($entry, $offset) => unpack('V', substr($entry, $offset, 4))[1];
        $pending = [$entryNumber($directory, 76)];
        $seen = [];
        while ($pending) {
            $id = array_pop($pending);
            if ($id === 0xFFFFFFFF) {
                continue;
            }
            if (isset($seen[$id]) || ($id + 1) * 128 > strlen($directory)) {
                return false;
            }
            $seen[$id] = true;
            $entry = substr($directory, $id * 128, 128);
            $pending[] = $entryNumber($entry, 68);
            $pending[] = $entryNumber($entry, 72);
            $nameLength = unpack('v', substr($entry, 64, 2))[1];
            if ($nameLength < 2 || $nameLength > 64 || $nameLength % 2 !== 0) {
                return false;
            }
            if (ord($entry[66]) !== 2 || substr($entry, 0, $nameLength) !== mb_convert_encoding("WordDocument\0", 'UTF-16LE')) {
                continue;
            }
            $size = $entryNumber($entry, 120);
            if ($size < 32 || $size > strlen($bytes) || $entryNumber($entry, 124) !== 0) {
                return false;
            }
            if ($size < 4096) {
                $miniFat = $chain($number(60), $fat, $sectorSize, $source);
                $miniStream = $chain($entryNumber($directory, 116), $fat, $sectorSize, $source);
                if ($miniFat === null || $miniStream === null || strlen($miniFat) !== $number(64) * $sectorSize) {
                    return false;
                }
                $stream = $chain($entryNumber($entry, 116), array_values(unpack('V*', $miniFat)), 64, $miniStream);
            } else {
                $stream = $chain($entryNumber($entry, 116), $fat, $sectorSize, $source);
            }
            if ($stream === null || strlen($stream) < $size) {
                return false;
            }
            $fib = unpack('videntifier/vversion', substr($stream, 0, 4));
            $text = unpack('Vstart/Vend', substr($stream, 24, 8));

            return $fib['identifier'] === 0xA5EC && $fib['version'] >= 0x65 && $fib['version'] <= 0x112
                && $text['start'] >= 32 && $text['end'] >= $text['start'] && $text['end'] <= $size;
        }

        return false;
    }

    private function docx(string $path): bool
    {
        $zip = new \ZipArchive;
        if ($zip->open($path, \ZipArchive::RDONLY) !== true) {
            return false;
        }
        try {
            if ($zip->numFiles > 1000) {
                return false;
            }
            $total = 0;
            $names = [];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $entry = $zip->statIndex($i);
                $name = $entry['name'];
                $total += $entry['size'];
                if (isset($names[strtolower($name)]) || preg_match('~(^/|\\\\|(^|/)\.\.?(/|$)|vba|macro|activex|embeddings)~i', $name)
                    || ($entry['encryption_method'] ?? 0) !== 0 || $total > 50 * 1024 * 1024
                    || $entry['size'] > 20 * 1024 * 1024 || $entry['size'] > max(1, $entry['comp_size']) * 100) {
                    return false;
                }
                $names[strtolower($name)] = true;
                if (str_ends_with($name, '/')) {
                    continue;
                }
                if (! preg_match('/\.(xml|rels|png|jpe?g|gif|bmp|tiff?|emf|wmf)$/i', $name)) {
                    return false;
                }
                $contents = $zip->getFromIndex($i);
                if ($contents === false || str_starts_with($contents, 'MZ') || str_starts_with($contents, "\x7fELF") || str_starts_with($contents, '#!')) {
                    return false;
                }
                if (preg_match('/\.(xml|rels)$/i', $name) && ! $this->xml($contents)) {
                    return false;
                }
            }
            $types = $zip->getFromName('[Content_Types].xml');
            $document = $zip->getFromName('word/document.xml');
            $rels = $zip->getFromName('_rels/.rels');
            if ($types === false || $document === false || $rels === false) {
                return false;
            }
            $types = $this->xml($types);
            $document = $this->xml($document);
            $rels = $this->xml($rels);
            if (! $types || ! $document || ! $rels || $types->documentElement->localName !== 'Types'
                || $types->documentElement->namespaceURI !== 'http://schemas.openxmlformats.org/package/2006/content-types'
                || $rels->documentElement->localName !== 'Relationships'
                || $rels->documentElement->namespaceURI !== 'http://schemas.openxmlformats.org/package/2006/relationships'
                || $document->documentElement->localName !== 'document'
                || ! in_array($document->documentElement->namespaceURI, ['http://schemas.openxmlformats.org/wordprocessingml/2006/main', 'http://purl.oclc.org/ooxml/wordprocessingml/main'], true)) {
                return false;
            }
            $main = false;
            foreach ($types->documentElement->childNodes as $type) {
                if (! $type instanceof \DOMElement) {
                    continue;
                }
                if ($type->localName === 'Override' && $type->getAttribute('PartName') === '/word/document.xml' && $type->getAttribute('ContentType') === 'application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml') {
                    $main = true;
                }
                if (preg_match('/macro|vba|activex|oleObject|msdownload|executable/i', $type->getAttribute('ContentType'))) {
                    return false;
                }
            }
            foreach ($rels->getElementsByTagName('Relationship') as $rel) {
                if ($main && in_array($rel->getAttribute('Type'), ['http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument', 'http://purl.oclc.org/ooxml/officeDocument/relationships/officeDocument'], true)
                    && in_array($rel->getAttribute('Target'), ['word/document.xml', '/word/document.xml'], true) && $rel->getAttribute('TargetMode') !== 'External') {
                    return true;
                }
            }

            return false;
        } finally {
            $zip->close();
        }
    }

    private function xml(string $contents): ?\DOMDocument
    {
        if (stripos($contents, '<!DOCTYPE') !== false || stripos($contents, '<!ENTITY') !== false) {
            return null;
        }
        $xml = new \DOMDocument;
        $previous = libxml_use_internal_errors(true);
        try {
            return $xml->loadXML($contents, LIBXML_NONET) ? $xml : null;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }
}
