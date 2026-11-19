@if ($paginator->hasPages())
<nav class="pager" aria-label="Strony wyników">
    @if (method_exists($paginator, 'total'))
        <span class="pager-summary">Wyniki {{ $paginator->firstItem() }}–{{ $paginator->lastItem() }} z {{ $paginator->total() }}</span>
    @endif
    <div class="pager-links">
        @if ($paginator->onFirstPage())
            <span class="pager-disabled" aria-disabled="true">← Poprzednia</span>
        @else
            <a href="{{ $paginator->previousPageUrl() }}" rel="prev">← Poprzednia</a>
        @endif
        @foreach ($elements ?? [] as $element)
            @if (is_string($element))
                <span class="pager-gap">{{ $element }}</span>
            @else
                @foreach ($element as $page => $url)
                    @if ($page == $paginator->currentPage())
                        <span class="pager-current" aria-current="page">{{ $page }}</span>
                    @else
                        <a href="{{ $url }}" aria-label="Strona {{ $page }}">{{ $page }}</a>
                    @endif
                @endforeach
            @endif
        @endforeach
        @if ($paginator->hasMorePages())
            <a href="{{ $paginator->nextPageUrl() }}" rel="next">Następna →</a>
        @else
            <span class="pager-disabled" aria-disabled="true">Następna →</span>
        @endif
    </div>
</nav>
@endif
