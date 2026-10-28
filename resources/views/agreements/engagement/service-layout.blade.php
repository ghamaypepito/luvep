<!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8"><title>{{ $doc_header }}</title>
<style>
@page { margin: 46px 52px 48px; }
body { font-family: DejaVu Sans, sans-serif; font-size: 9.5pt; color: #414747; line-height: 1.4; }
.masthead { border-bottom: 1px solid #009b9d; padding-bottom: 12px; margin-bottom: 16px; }
.masthead img { width: 160px; }
h1 { font-size: 12.5pt; text-align: center; margin: 12px 0 16px; }
h2 { font-size: 10.5pt; margin: 13px 0 6px; }
p { margin: 0 0 11px; }
ul { padding-left: 18px; } li { margin-bottom: 7px; }
table.details { width: 100%; border-collapse: collapse; margin: 8px 0 17px; }
.details td { border: 1px solid #b8d2d4; padding: 5px 9px; vertical-align: top; overflow-wrap: break-word; }
.details td:first-child { width: 29%; font-weight: bold; background: #e9f3f3; }
.page-break { page-break-before: always; }
.signatures { width: 100%; margin-top: 32px; } .signatures td { width: 50%; vertical-align: top; padding-right: 18px; }
.signature { height: 58px; border-bottom: 1px solid #707777; margin-bottom: 8px; }
.signature img { max-width: 210px; max-height: 55px; }
.footer { position: fixed; bottom: -29px; left: 0; right: 0; font-size: 8pt; color: #666; border-top: 1px solid #aac9ca; padding-top: 6px; }
@if(!empty($preview)) body { max-width: 700px; margin: 30px auto; padding: 32px; background: white; } .footer { position: static; margin-top: 30px; } @endif
</style></head><body>
<div class="footer">D Immigration Consultancy Limited | {{ $doc_header }}</div>
<div class="masthead"><img src="{{ $logo_data }}" alt="D Immigration Consultancy"></div>
@yield('document')
@if(!empty($preview))
<script>
window.addEventListener('message', function (event) {
    if (event.origin !== window.location.origin || !event.data || event.data.type !== 'applicant-signature') return;
    var img = document.getElementById('applicant-signature');
    if (img) { img.src = event.data.value || ''; img.style.display = img.src ? 'inline' : 'none'; }
});
</script>
@endif
</body></html>
