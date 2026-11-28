{{--
    Shared layout for Exalt flat/house-sharing agreements (Whole-Property,
    Transient short-term, Standard long-term). The three share the same Terms,
    House Rules and Declaration appendices — only the title and the move-in/rent
    section differ. Renders cleanly both as a dompdf PDF and as the live HTML
    preview shown in the Agreements module ($preview = true).

    Vars: $doc_title, $body (yielded), $preview (bool)
--}}
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>{{ $doc_title ?? 'Flat/House Sharing Agreement' }} — Exalt Property Management</title>
<style>
    @page { margin: 64px 48px 54px 48px; }
    * { box-sizing: border-box; }
    body { font-family: DejaVu Sans, 'Helvetica Neue', Arial, sans-serif; font-size: 10pt; color: #1f2937; line-height: 1.45; margin: 0; }

    .brandbar { border-bottom: 2px solid #1F5A8B; padding-bottom: 6px; margin-bottom: 14px; }
    .brandbar .co { color: #1F5A8B; font-weight: 700; font-size: 11pt; }
    .brandbar .rev { color: #6b7280; font-size: 8.5pt; float: right; }
    .brandbar .logo { width: 92px; vertical-align: middle; margin-right: 9px; }

    .doc-title { color: #1F5A8B; font-size: 17pt; font-weight: 700; margin: 2px 0 2px 0; text-transform: uppercase; letter-spacing: .3px; }
    .prop-line { font-size: 10.5pt; font-weight: 700; margin: 4px 0 12px 0; }
    .prop-line .val { color: #1F5A8B; }
    .note-line { color: #4b5563; font-style: italic; font-size: 9pt; margin: -6px 0 10px 0; }

    h2.section { color: #1F5A8B; font-size: 12.5pt; font-weight: 700; margin: 22px 0 8px 0; padding-bottom: 5px; border-bottom: 2px solid #1F5A8B; text-transform: uppercase; }
    h3.blk { color: #111827; font-size: 11pt; font-weight: 700; margin: 14px 0 5px 0; }
    h3.tc-head { color: #1F5A8B; font-size: 10pt; font-weight: 700; margin: 12px 0 3px 0; }
    p { margin: 6px 0; text-align: justify; }
    p.clause { margin: 4px 0; }
    p.clause .n { color: #1F5A8B; font-weight: 700; }
    p.strong { font-weight: 700; }
    ul.bullets { margin: 5px 0 5px 0; padding-left: 20px; }
    ul.bullets li { margin: 2px 0; text-align: justify; }

    table.kv { width: 100%; border-collapse: collapse; margin: 8px 0 4px 0; }
    table.kv td { border: 1px solid #d1d5db; padding: 6px 9px; font-size: 9.5pt; vertical-align: top; }
    table.kv td.label { width: 30%; font-weight: 700; color: #374151; background: #f3f4f6; }
    table.kv td .fill { color: #9ca3af; font-style: italic; }
    .subt-head { background: #1F5A8B; color: #fff; font-weight: 700; padding: 6px 9px; font-size: 9.5pt; margin-top: 10px; }

    table.grid { width: 100%; border-collapse: collapse; margin: 8px 0; }
    table.grid th, table.grid td { border: 1px solid #d1d5db; padding: 6px 9px; font-size: 9.5pt; text-align: left; vertical-align: top; }
    table.grid th { background: #1F5A8B; color: #fff; }
    table.grid td.amt { text-align: right; white-space: nowrap; }
    table.grid tr.total td { font-weight: 700; background: #eef4f9; }

    .callout { border-left: 3px solid #1F5A8B; background: #f2f7fb; padding: 8px 12px; margin: 10px 0; font-size: 9.5pt; }
    .tick { font-family: DejaVu Sans; }

    table.sign { width: 100%; margin-top: 14px; border-collapse: collapse; }
    table.sign td { width: 50%; vertical-align: bottom; padding: 20px 18px 4px 0; }
    .sign-line { border-bottom: 1px solid #374151; height: 30px; }
    .sign-cap { color: #6b7280; font-size: 9pt; padding-top: 3px; }

    table.sig-inline { width: 60%; margin: 8px 0; border-collapse: collapse; }
    table.sig-inline td { padding: 6px 4px; font-size: 9.5pt; }
    table.sig-inline td.ln { border-bottom: 1px solid #9ca3af; }

    p.decl-line { margin: 12px 0 0 0; }
    p.decl-line .ln { display: inline-block; min-width: 240px; border-bottom: 1px solid #9ca3af; }
    p.decl-cap { color: #6b7280; font-size: 8.5pt; font-style: italic; margin: 1px 0 0 0; }

    .appendix { page-break-before: always; }
    .avoid-break { page-break-inside: avoid; }

    @if(!empty($preview))
    /* On-screen preview: paint a paper sheet on a grey backdrop. */
    html { background: #e5e7eb; }
    body { max-width: 820px; margin: 0 auto; padding: 40px 48px 60px 48px; background: #fff; box-shadow: 0 2px 16px rgba(0,0,0,.14); font-size: 11pt; }
    .appendix { page-break-before: auto; border-top: 2px dashed #cbd5e1; margin-top: 26px; padding-top: 18px; }
    @endif
</style>
</head>
<body>
    <div class="brandbar">
        @if(!empty($revised))
            <img class="logo" alt="Exalt" src="data:image/jpeg;base64,{{ base64_encode(file_get_contents(resource_path('assets/accomodation/exaltlogo.jpg'))) }}">
        @endif
        <span class="co">Exalt Property Management Ltd</span>
        <span class="rev">Flat/House Sharing Agreement (CV1.2026)</span>
    </div>

    @yield('body')
</body>
</html>
