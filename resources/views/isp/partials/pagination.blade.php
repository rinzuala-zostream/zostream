@if ($paginator->hasPages())
    <nav class="isp-pagination" role="navigation" aria-label="Pagination navigation">
        <div class="isp-pagination__summary">
            <span class="isp-pagination__summary-icon" aria-hidden="true">
                <svg viewBox="0 0 20 20"><path d="M5 6h10M5 10h10M5 14h6"/></svg>
            </span>
            <p>
                <span>Showing</span>
                <strong>{{ number_format($paginator->firstItem()) }}–{{ number_format($paginator->lastItem()) }}</strong>
                <span>of {{ number_format($paginator->total()) }}</span>
            </p>
            <span class="isp-pagination__page-count">Page {{ $paginator->currentPage() }} of {{ $paginator->lastPage() }}</span>
        </div>

        <div class="isp-pagination__controls">
            @if ($paginator->onFirstPage())
                <span class="isp-pagination__button is-disabled" aria-disabled="true">
                    <svg viewBox="0 0 20 20" aria-hidden="true"><path d="m12.5 15-5-5 5-5"/></svg>
                    <span>Previous</span>
                </span>
            @else
                <a class="isp-pagination__button" href="{{ $paginator->previousPageUrl() }}" rel="prev" aria-label="Go to previous page">
                    <svg viewBox="0 0 20 20" aria-hidden="true"><path d="m12.5 15-5-5 5-5"/></svg>
                    <span>Previous</span>
                </a>
            @endif

            <div class="isp-pagination__pages" role="group" aria-label="Choose a page">
                @foreach ($elements as $element)
                    @if (is_string($element))
                        <span class="isp-pagination__ellipsis" aria-hidden="true">•••</span>
                    @endif

                    @if (is_array($element))
                        @foreach ($element as $page => $url)
                            @if ($page == $paginator->currentPage())
                                <span class="isp-pagination__number is-current" aria-current="page" aria-label="Current page, page {{ $page }}">{{ $page }}</span>
                            @else
                                <a class="isp-pagination__number" href="{{ $url }}" aria-label="Go to page {{ $page }}">{{ $page }}</a>
                            @endif
                        @endforeach
                    @endif
                @endforeach
            </div>

            @if ($paginator->hasMorePages())
                <a class="isp-pagination__button" href="{{ $paginator->nextPageUrl() }}" rel="next" aria-label="Go to next page">
                    <span>Next</span>
                    <svg viewBox="0 0 20 20" aria-hidden="true"><path d="m7.5 5 5 5-5 5"/></svg>
                </a>
            @else
                <span class="isp-pagination__button is-disabled" aria-disabled="true">
                    <span>Next</span>
                    <svg viewBox="0 0 20 20" aria-hidden="true"><path d="m7.5 5 5 5-5 5"/></svg>
                </span>
            @endif
        </div>
    </nav>
@endif
