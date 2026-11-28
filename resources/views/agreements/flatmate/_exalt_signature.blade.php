<table class="sign">
    <tr>
        <td>
            @if($adminSignature)
                <img src="{{ $adminSignature }}" alt="Exalt Property Admin signature" style="max-height:48px; display:block; margin-bottom:2px;">
            @endif
            <div class="sign-line"></div>
            @if($revised)
                <div class="sign-cap">Exalt Property Management</div>
                <div class="sign-cap">{{ $adminName }}</div>
                <div class="sign-cap">Date · @if(isset($data['admin_signed_on'])){!! $d($data['admin_signed_on']) !!}@else On issue @endif</div>
            @else
                <div class="sign-cap">Exalt Property Admin</div>
                @if(($data['workflow_version'] ?? null) === 2)
                    <div class="sign-cap">{{ $adminName }}</div>
                @endif
                <div class="decl-cap">[signatory of admin]</div>
            @endif
        </td>
        <td></td>
    </tr>
</table>
