{{-- "+N alts" pill for a by-player-aggregated Wallet Insights row. The card
     name links to the player profile for the full breakdown; this pill shows
     the account is aggregated and its tooltip previews each alt's contribution.
     Expects: $altCount (int), $alts (array of {name, contribution}), $iskFmt. --}}
@if(($altCount ?? 1) > 1 && !empty($alts))
    <span class="badge"
          style="background: rgba(102,126,234,0.18); color: #a5b4fc; border: 1px solid rgba(102,126,234,0.4); font-size: 0.6rem; font-weight: 400;"
          title="{{ collect($alts)->map(fn ($a) => $a['name'] . ' — ' . $iskFmt($a['contribution']))->implode('   |   ') }}">
        <i class="fas fa-users"></i> +{{ $altCount - 1 }} {{ trans('hr-manager::corp-health.alts_label') }}
    </span>
@endif
