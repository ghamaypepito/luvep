@foreach(\App\Support\EducationTimeZones::preferred() as $timezoneId=>$timezoneLabel)
<option value="{{ $timezoneId }}" @selected(($selectedTimezone??'Asia/Manila')===$timezoneId)>{{ $timezoneLabel }}</option>
@endforeach
@foreach(\App\Support\EducationTimeZones::groups() as $timezoneRegion=>$timezoneChoices)
<optgroup label="{{ $timezoneRegion==='America'?'Americas':$timezoneRegion }}">
@foreach($timezoneChoices as $timezoneId=>$timezoneLabel)
<option value="{{ $timezoneId }}" @selected(($selectedTimezone??'Asia/Manila')===$timezoneId)>{{ $timezoneLabel }}</option>
@endforeach
</optgroup>
@endforeach
