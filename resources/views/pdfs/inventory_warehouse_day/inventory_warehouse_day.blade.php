<!doctype html>
<html lang="fr">

<head>
    <meta charset="UTF-8">
    <meta name="viewport"
          content="width=device-width, user-scalable=no, initial-scale=1.0, maximum-scale=1.0, minimum-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">
    <style>
        {!! $bootstrap !!}
    </style>

    <style>
        @import url('https://fonts.googleapis.com/css2?family=Merriweather:ital,opsz,wght@0,18..144,300..900;1,18..144,300..900&display=swap');

        body, html {
            height: 100%;
            margin: 0;
            padding: 0;
            font-size: 3mm !important;
            font-family: "Merriweather", Georgia, serif;
        }

        h1 {
            font-size: 5mm !important;
        }

        table {
            page-break-inside: auto;
            width: 100%;
        }

        thead {
            display: table-header-group;
        }

        tfoot {
            display: table-footer-group;
        }

        tr {
            page-break-inside: avoid;
            page-break-after: auto;
        }

        img {
            width: auto;
            height: auto;
        }
    </style>
</head>

<body>

@php
    $isAll = !empty($is_all_warehouses);
@endphp

{{-- Header --}}
<header class="text-center mb-3">
    <div class="fs-3 fw-bold text-uppercase">
        @if(!empty($warehouse))
            INVENTAIRE DE STOCKS — {{ $warehouse->name }}
        @elseif($isAll)
            INVENTAIRE GLOBAL DE TOUS LES ENTREPÔTs
        @else
            INVENTAIRE DE STOCKS PAR ENTREPÔT
        @endif
    </div>

    @if(!empty($end_date))
        <div class="mt-1 text-muted">
            Date : <strong>{{ \Carbon\Carbon::parse($end_date)->format('d/m/Y') }}</strong>
        </div>
    @endif
</header>

<div class="mt-2 w-100" style="border-top: 1px double rgba(0,0,0,0.75); margin-bottom: 2px"></div>
<div class="mb-2 w-100" style="border-top: 1px double rgba(0,0,0,0.75);"></div>

<p class="fst-italic text-end">
    Date d'impression : {{ now()->format('d/m/Y H:i') }}
</p>

@if($isAll)
    @php
        $globalItems = $product_points ?? collect();
    @endphp
    <section class="mt-3">
        <div class="d-flex justify-content-between align-items-center bg-light border border-black px-2 py-1 mb-2" style="font-size: 12px;">
            <div class="fw-bold text-uppercase">
                Tous les articles (tous entrepôts confondus)
            </div>
            <div>
                Articles : <strong>{{ $globalItems->count() }}</strong> |
                Quantité totale : <strong>{{ $globalItems->sum('quantity') }}</strong>
            </div>
        </div>

        <div class="d-flex justify-content-center mt-2">
            <table class="table table-bordered table-striped border-black" style="font-size: 11px;">
                <thead>
                <tr>
                    <th>N°</th>
                    <th>Article</th>
                    <th>Quantité</th>
                    <th>Référence</th>
                    <th>Catégorie</th>
                    <th>Unité</th>
                </tr>
                </thead>
                <tbody>
                @forelse($globalItems as $index => $item)
                    @php
                        $rawCategories = $item->product->category_json ?? [];
                        $categories = is_array($rawCategories) ? $rawCategories : (json_decode((string) $rawCategories, true) ?: []);
                        $count = count($categories);

                        if ($count >= 4) {
                            $displayCategories = array_merge(
                                array_slice($categories, 0, 3),
                                ['...'],
                                [end($categories)]
                            );
                        } else {
                            $displayCategories = $categories;
                        }
                    @endphp
                    <tr>
                        <td>{{ $index + 1 }}</td>
                        <td>{{ $item->product->name ?? '' }}</td>
                        <td class="fw-bold">{{ $item->quantity }}</td>
                        <td>{{ $item->product->code ?? '' }}</td>
                        <td>{{ implode(' → ', $displayCategories) }}</td>
                        <td>{{ $item->product->unitMeasure->name ?? '' }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="text-center text-muted">
                            Aucun produit trouvé.
                        </td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </section>
@else
    @forelse($inventories_by_warehouse as $whIndex => $block)
        @php
            $currentWarehouse = $block->warehouse;
            $items = $block->product_points ?? collect();
        @endphp

        <section class="mt-4" @if($whIndex > 0 && empty($warehouse)) style="page-break-before: always;" @endif>
            <div class="d-flex justify-content-between align-items-center bg-light border border-black px-2 py-1 mb-2" style="font-size: 12px;">
                <div class="fw-bold text-uppercase">
                    Entrepôt : {{ $currentWarehouse->name ?? 'Entrepôt non défini' }}
                    @if(!empty($currentWarehouse?->is_primary))
                        <span class="badge bg-secondary ms-1" style="font-size: 9px;">Principal</span>
                    @endif
                </div>
                <div>
                    Articles : <strong>{{ $items->count() }}</strong> |
                    Quantité totale : <strong>{{ $block->total_quantity ?? $items->sum('quantity') }}</strong>
                </div>
            </div>

            <div class="d-flex justify-content-center mt-2">
                <table class="table table-bordered table-striped border-black" style="font-size: 11px;">
                    <thead>
                    <tr>
                        <th>N°</th>
                        <th>Article</th>
                        <th>Quantité</th>
                        <th>Référence</th>
                        <th>Catégorie</th>
                        <th>Unité</th>
                    </tr>
                    </thead>
                    <tbody>
                    @forelse($items as $index => $item)
                        @php
                            $rawCategories = $item->product->category_json ?? [];
                            $categories = is_array($rawCategories) ? $rawCategories : (json_decode((string) $rawCategories, true) ?: []);
                            $count = count($categories);

                            if ($count >= 4) {
                                $displayCategories = array_merge(
                                    array_slice($categories, 0, 3),
                                    ['...'],
                                    [end($categories)]
                                );
                            } else {
                                $displayCategories = $categories;
                            }
                        @endphp
                        <tr>
                            <td>{{ $index + 1 }}</td>
                            <td>{{ $item->product->name ?? '' }}</td>
                            <td class="fw-bold">{{ $item->quantity }}</td>
                            <td>{{ $item->product->code ?? '' }}</td>
                            <td>{{ implode(' → ', $displayCategories) }}</td>
                            <td>{{ $item->product->unitMeasure->name ?? '' }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="text-center text-muted">
                                Aucun article enregistré pour cet entrepôt.
                            </td>
                        </tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    @empty
        <div class="alert alert-warning text-center mt-3">
            Aucun entrepôt ou inventaire trouvé pour la sélection.
        </div>
    @endforelse
@endif

</body>

</html>
