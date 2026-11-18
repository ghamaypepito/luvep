<?php

namespace Tests\Feature\Hr;

use App\Services\Hr\HrFile;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;
use ZipArchive;

class HrFileTest extends TestCase
{
    public function test_supported_file_content_and_extensions_are_validated(): void
    {
        foreach (['doc' => 'application/msword', 'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'] as $ext => $mime) {
            $file = new UploadedFile(base_path('tests/Fixtures/Hr/minimal.'.$ext), 'support.'.$ext, null, null, true);
            $this->assertSame($mime, app(HrFile::class)->validate($file)['mime']);
        }
        $this->assertSame('image/png', app(HrFile::class)->validate(UploadedFile::fake()->image('photo.png'))['mime']);
    }

    public function test_spoofed_office_files_are_rejected(): void
    {
        foreach (['fake.doc', 'fake.docx'] as $name) {
            try {
                app(HrFile::class)->validate(UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n%%EOF"));
                $this->fail('A renamed PDF was accepted.');
            } catch (ValidationException $error) {
                $this->assertArrayHasKey('file', $error->errors());
            }
        }
    }

    public function test_doc_requires_a_real_compound_word_stream_not_a_signature_and_name(): void
    {
        $header = substr(file_get_contents(base_path('tests/Fixtures/Hr/minimal.doc')), 0, 512);
        $file = UploadedFile::fake()->createWithContent('forged.doc', $header.mb_convert_encoding('WordDocument', 'UTF-16LE').str_repeat('x', 550));
        $this->expectException(ValidationException::class);
        app(HrFile::class)->validate($file);
    }

    public function test_docx_rejects_missing_structure_macros_executables_bombs_paths_and_entities(): void
    {
        $cases = [
            ['word/document.xml' => null],
            ['word/vbaProject.bin' => 'macro'],
            ['word/media/file.jpg' => 'MZ executable'],
            ['word/media/file.exe' => 'executable'],
            ['word/big.xml' => '<root>'.str_repeat('a', 1024 * 1024).'</root>'],
            ['../outside.xml' => '<root/>'],
            ['word/document.xml' => '<!DOCTYPE document [<!ENTITY secret SYSTEM "file:///etc/passwd">]><document>&secret;</document>'],
            ['[Content_Types].xml' => '<Types><Override PartName="/word/document.xml" ContentType="application/vnd.ms-word.document.macroEnabled.main+xml"/></Types>'],
        ];
        foreach ($cases as $changes) {
            $path = tempnam(sys_get_temp_dir(), 'hr-docx-');
            copy(base_path('tests/Fixtures/Hr/minimal.docx'), $path);
            $zip = new ZipArchive;
            $zip->open($path);
            foreach ($changes as $name => $contents) {
                $contents === null ? $zip->deleteName($name) : $zip->addFromString($name, $contents);
            }
            $zip->close();
            try {
                app(HrFile::class)->validate(new UploadedFile($path, 'support.docx', null, null, true));
                $this->fail('Unsafe DOCX was accepted: '.array_key_first($changes));
            } catch (ValidationException $error) {
                $this->assertArrayHasKey('file', $error->errors());
            } finally {
                unlink($path);
            }
        }
    }
}
