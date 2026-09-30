{{-- La fiche de rémunération (spec 2026-09-30, §5.6), fidèle à la fiche manuelle. --}}
@php($fcfa = fn ($v) => number_format((float) $v, 0, ',', ' '))
@php($two = fn ($v) => str_pad((string) $v, 2, '0', STR_PAD_LEFT))
<!doctype html>
<html lang="fr"><head><meta charset="utf-8">
<style>
  @font-face { font-family: 'Montserrat'; font-weight: 400; src: url('{{ $fonts }}/Montserrat-Regular.ttf'); }
  @font-face { font-family: 'Montserrat'; font-weight: 600; src: url('{{ $fonts }}/Montserrat-SemiBold.ttf'); }
  @font-face { font-family: 'Montserrat'; font-weight: 700; src: url('{{ $fonts }}/Montserrat-Bold.ttf'); }
  @page { margin: 28px 36px 90px 110px; }
  body { font-family: 'Montserrat', sans-serif; font-size: 11px; color: #111; }
  .watermark { position: fixed; left: -100px; top: -28px; width: 70px; }
  .draft { position: fixed; top: 380px; left: 40px; font-size: 90px; color: #f3caca; transform: rotate(-30deg); font-weight: 700; }
  h1 { font-size: 17px; font-weight: 700; margin: 18px 0 14px; }
  h2 { font-size: 12px; font-weight: 700; margin: 16px 0 8px; }
  h2 .month { color: #1e6e4a; }
  table.a { width: 100%; border-collapse: collapse; }
  table.a td { border: 1px solid #333; padding: 6px 8px; }
  table.a td.small { font-size: 9px; }
  table.a tr.strong td { font-weight: 700; }
  table.b td { padding: 4px 8px; vertical-align: middle; }
  table.b td.tile { background: #1e6e4a; color: #fff; font-weight: 700; text-align: center; width: 110px; height: 40px; }
  .specimen { color: #b91c1c; font-weight: 700; font-size: 14px; border: 2px solid #b91c1c; padding: 8px; }
  .legal { position: fixed; bottom: -70px; left: 0; right: 0; font-size: 8px; color: #333; }
</style></head>
<body>
  <img class="watermark" src="{{ $watermark }}" alt="">
  @if ($draft)<div class="draft">BROUILLON</div>@endif

  <img src="{{ $logo }}" alt="KOKA" style="height: 56px">
  <h1>FICHE DE RÉMUNÉRATION PROPRIÉTAIRE TUK TUK</h1>
  <div>
    <b>Projet :</b> Chic Tuk-Tuk<br>
    <b>Propriétaire Tuk Tuk :</b> {{ $f->ownerName }}<br>
    <b>Date de démarrage :</b> {{ \Carbon\Carbon::parse($f->startDate)->format('d/m/Y') }}
    @if ($number)<br><b>N° :</b> {{ $number }}@endif
  </div>

  <h2>A. SYNTHÈSE FINANCIÈRE – <span class="month">{{ $monthLabel }}</span></h2>
  <table class="a">
    <tr class="strong"><td>Caractéristique</td><td>{{ $f->vehicleNumber }}</td></tr>
    <tr><td>Type de contrat</td><td>{{ $f->contractMonths }} mois</td></tr>
    <tr><td>Statut jours de pause</td><td>{{ $two($f->pauseDaysTaken) }}/{{ $f->pauseAllowance }}</td></tr>
    <tr><td>Jours ouvrés (Total)</td><td>{{ $two($f->businessDays) }}</td></tr>
    <tr><td>Jours de pause</td><td>({{ $two($f->pauseDays) }})</td></tr>
    <tr><td class="small">Paiements en instance</td><td>({{ $two($f->pendingCount) }})</td></tr>
    <tr><td class="small">Jours d'immobilisation</td><td>({{ $two($f->immobilizationDays) }})</td></tr>
    <tr><td>Jours comptabilisés</td><td>{{ $two($f->countedDays) }}</td></tr>
    <tr><td>Montant journalier généré (F CFA)</td><td>{{ $fcfa($f->dailyAmount) }}</td></tr>
    <tr class="strong"><td>Total recettes (F CFA)</td><td>{{ $fcfa($f->revenue) }}</td></tr>
    @if ($f->recovered > 0)
      <tr><td>Paiements recouvrés (F CFA)</td><td>{{ $fcfa($f->recovered) }}</td></tr>
    @endif
    @foreach ($f->charges as $charge)
      <tr><td>{{ $charge['label'] }}</td><td>{{ $charge['deducted'] > 0 ? '('.$fcfa($charge['deducted']).')' : '-' }}</td></tr>
    @endforeach
    <tr class="strong"><td>Solde dû (en F CFA)</td><td>{{ $fcfa($f->balanceDue) }}</td></tr>
  </table>

  <h2>B. SYNTHÈSE CONTRAT</h2>
  <table class="b">
    <tr>
      <td><b>Cumul Revenu mensuel (FCFA)</b></td><td class="tile">{{ $fcfa($f->cumulativeRevenue) }}</td>
      <td><b>Cumul Revenu net mensuel (FCFA)</b></td><td class="tile">{{ $fcfa($f->cumulativeNet) }}</td>
    </tr>
    <tr>
      <td><b>Cumul Charge mensuelle (FCFA)</b></td><td class="tile">{{ $fcfa($f->cumulativeCharges) }}</td>
      <td><b>Nombre de mois effectué</b></td><td class="tile">{{ $two($f->workedMonths) }} | {{ $f->contractMonths }}</td>
    </tr>
  </table>

  <table style="width: 100%; margin-top: 24px"><tr>
    <td style="vertical-align: top">Fait à {{ config('remuneration.city') }}, le {{ $issuedOn->format('d/m/Y') }}</td>
    <td style="text-align: right; width: 260px">
      @if ($signed)
        <img src="{{ $stamp }}" alt="" style="width: 130px">
        <img src="{{ $signature }}" alt="" style="width: 90px; margin-left: -110px">
      @else
        <span class="specimen">{{ $draft ? 'BROUILLON' : 'SPÉCIMEN' }} — non signé</span>
      @endif
      <br>Le gérant, {{ config('remuneration.signatory') }}
    </td>
  </tr></table>

  {{-- Deux colonnes, comme la fiche d'origine : la société à gauche, le siège et les numéros à droite. --}}
  @php($company = config('remuneration.company'))
  <table class="legal"><tr>
    <td style="vertical-align: top; width: 55%">@foreach (array_slice($company, 0, 3) as $line){{ $line }}<br>@endforeach</td>
    <td style="vertical-align: top">@foreach (array_slice($company, 3) as $line){{ $line }}<br>@endforeach</td>
  </tr></table>
</body></html>
