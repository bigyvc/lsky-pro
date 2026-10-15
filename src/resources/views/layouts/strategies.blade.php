<!-- Strategies dropdown -->
<x-dropdown>
    <x-slot name="trigger">
        <button type="button" id="strategy-menu-button" class="ls-btn ls-btn-sm h-10 w-10 rounded-full px-0 justify-center gap-2 border-line sm:w-auto sm:px-3" aria-expanded="false" aria-haspopup="true">
            <span class="sr-only">Open strategies menu</span>
            <div class="flex items-center justify-center">
                <i class="fas fa-server text-ink-2 text-[13.5px]"></i>
            </div>
            <span class="sm:block hidden" id="strategy-selected" data-id="0">获取中...</span>
        </button>
    </x-slot>

        <x-slot name="content">
            <div id="strategies">
            @foreach($_group->strategies as $strategy)
                @php
                $r2Host = parse_url($strategy->configs->get(\App\Enums\Strategy\S3Option::Endpoint, ''), PHP_URL_HOST);
                $r2Direct = Auth::check() && Auth::user()->is_adminer
                    && in_array($strategy->key, [\App\Enums\StrategyKey::S3, \App\Enums\StrategyKey::Minio], true)
                    && preg_match('/^[a-f0-9]{32}(?:\.(?:eu|fedramp))?\.r2\.cloudflarestorage\.com$/iD', (string) $r2Host);
            @endphp
            <x-dropdown-link data-r2-direct="{{ $r2Direct ? 1 : 0 }}" data-id="{{ $strategy->id }}" href="javascript:void(0)" @click="open = false">{{ $strategy->name }}</x-dropdown-link>
            @endforeach
            </div>
        </x-slot>
</x-dropdown>

@push('scripts')
    <script>
        let defaultStrategy = {{ Auth::check() ? Auth::user()->configs->get('default_strategy') : 0 }} || (localStorage.getItem('strategy') || 0);
        let setStrategy = function (id) {
            let isSelected = false;
            $('#strategies a').each(function () {
                if (parseInt($(this).data('id')) === parseInt(id)) {
                    localStorage.setItem('strategy', id)
                    $('#strategy-selected').text($(this).text()).data('id', id).data('r2-direct', $(this).data('r2-direct'));
                    isSelected = true;

                    @if(Auth::check())
                        if (defaultStrategy != id) {
                            axios.put('{{ route('settings.strategy.set') }}', {id: id}).then(response => {
                                if (! response.data.status) {
                                    toastr.error(response.data.message);
                                }
                            });
                        }
                    @endif
                }
            });
            if (! isSelected) {
                let $first = $('#strategies a:first-child');
                localStorage.setItem('strategy', $first.data('id'))
                $('#strategy-selected').text($first.text()).data('id', $first.data('id')).data('r2-direct', $first.data('r2-direct'));
            }
        };

        setStrategy(defaultStrategy);

        $('#strategies a').click(function () {
            setStrategy($(this).data('id'))
        });
    </script>
@endpush
