@if(!empty($data['welcome_packages']['packages']))
    <div class="appendix">
        <h2 class="section">Exalt Welcome Packages</h2>
        @foreach($data['welcome_packages']['packages'] as $package)
            <h3 class="tc-head">{{ $package['name'] }} | ${{ number_format($package['price'], 2) }} NZD</h3>
            @foreach($package['inclusions'] as $inclusion)
                <p class="clause" style="text-align: left">{{ $inclusion }}</p>
            @endforeach
        @endforeach
        <p class="clause">{{ $data['welcome_packages']['service_note'] }}</p>
        <p class="clause"><strong>Total package cost (service included): ${{ number_format($data['welcome_packages']['total'], 2) }} NZD</strong></p>
    </div>
@endif
