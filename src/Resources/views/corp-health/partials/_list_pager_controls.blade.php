{{-- Reusable per-page + result-count controls for a client-side paginated
     list. Params: $prefix — the id stem shared with the hrPaginate() call
     (expects #{prefix}-count and #{prefix}-size). --}}
<div class="d-flex align-items-center flex-wrap mb-2" style="gap: 10px;">
    <span id="{{ $prefix }}-count" style="color: var(--hr-text-muted); font-size: 0.8rem;"></span>
    <div class="ml-auto d-flex align-items-center" style="gap: 6px;">
        <small style="color: var(--hr-text-muted);">{{ trans('hr-manager::corp-health.per_page') }}</small>
        <select id="{{ $prefix }}-size" class="form-control form-control-sm" style="width: auto; display: inline-block;">
            <option value="25">25</option>
            <option value="50" selected>50</option>
            <option value="100">100</option>
            <option value="250">250</option>
            <option value="500">500</option>
        </select>
    </div>
</div>
