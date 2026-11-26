<?php

namespace App\Support;

/**
 * Centralized file-upload allow-lists. One place for every upload context's
 * accepted types + size cap so new uploads reuse the same rules.
 *
 * Laravel's `mimes:` rule validates against the extension *guessed from the
 * file's contents* (not the client-supplied filename), so a renamed .exe or
 * .php is rejected — it isn't naive extension matching. We deliberately use
 * `mimes:` over a strict `mimetypes:` list because the latter false-rejects
 * valid Office files (docx/xlsx are detected as application/zip).
 */
class UploadValidation
{
    /** Documents staff/leads exchange — no executables. 50 MB (scanned
     *  passports / medicals / bank statements can run large). NOTE: the
     *  server's PHP upload_max_filesize + post_max_size and the web server's
     *  body-size limit must be >= this or the file is rejected before Laravel
     *  ever validates it — see docs/deployment.md. */
    public const DOCUMENT_MIMES = 'pdf,jpg,jpeg,png,doc,docx,xls,xlsx';

    public const DOCUMENT_MAX_KB = 51200;

    /** Image-only contexts (banners, property photos, avatars). 5 MB. */
    public const IMAGE_MIMES = 'jpg,jpeg,png,webp';

    public const IMAGE_MAX_KB = 5120;

    /** Informal task-board attachments — adds csv/txt. 10 MB. */
    public const TASK_MIMES = 'pdf,jpg,jpeg,png,doc,docx,xls,xlsx,csv,txt';

    public const TASK_MAX_KB = 10240;

    /** Tabular import files (lenient — CSV MIME detection is unreliable). 20 MB. */
    public const SPREADSHEET_MIMES = 'csv,txt,xls,xlsx';

    public const SPREADSHEET_MAX_KB = 20480;

    /** Rule fragment for a single document file (compose with required/nullable). */
    public static function document(): string
    {
        return 'file|mimes:'.self::DOCUMENT_MIMES.'|max:'.self::DOCUMENT_MAX_KB;
    }

    /** Content and filename must both identify a PDF. */
    public static function pdfDocument(): string
    {
        return 'file|mimes:pdf|extensions:pdf|max:'.self::DOCUMENT_MAX_KB;
    }

    /** Rule fragment for a single image file. */
    public static function image(): string
    {
        return 'image|mimes:'.self::IMAGE_MIMES.'|max:'.self::IMAGE_MAX_KB;
    }

    /** Rule fragment for a single task attachment. */
    public static function taskAttachment(): string
    {
        return 'file|mimes:'.self::TASK_MIMES.'|max:'.self::TASK_MAX_KB;
    }

    /** Rule fragment for a CSV/spreadsheet import. */
    public static function spreadsheet(): string
    {
        return 'file|mimes:'.self::SPREADSHEET_MIMES.'|max:'.self::SPREADSHEET_MAX_KB;
    }
}
