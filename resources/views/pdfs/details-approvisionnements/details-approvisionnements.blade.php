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
        body,
        html {
            height: 100%;
            margin: 0;
            padding: 0;
            font-size: 3mm !important;
            font-family: "Times New Roman", serif;
        }

        h1, h6 {
            font-size: 4.5mm !important; /* 🔥 Titres un peu plus grands */
        }

        table, table tbody, table th, table td {
            font-size: 3.5mm !important; /* 🔥 Tableau plus lisible */
        }

        table th {
            background-color: rgba(0, 0, 0, 0.144) !important;
        }

        img {
            width: auto;
            height: auto;
        }
    </style>
</head>

<body>

{{-- Header --}}
<header class="text-center" style="font-family: 'Times New Roman', serif">
    <div class="fs-3 fw-bold text-uppercase">
        BON DE COMMANDE N° {{ $supply->purchaseOrder->reference }}
    </div>

    <div class="mt-1 fw-bold">
        Approvisionnement :
        @if($supply->purchaseOrder->type === 'external')
            externe
        @else
           interne
        @endif
        — {{ $supply->reference ?? '---' }}
    </div>


</header>

<div class="mt-2 w-100" style="border-top: 1px double rgba(0,0,0,0.75); margin-bottom: 2px"></div>
<div class="mb-2 w-100" style="border-top: 1px double rgba(0,0,0,0.75);"></div>

<h1 class="fw-bold text-center text-uppercase text-decoration-underline mt-3">
    Articles approvisionnés
</h1>

<p class="fst-italic text-end">
    Date d'impression : {{ now()->format('d/m/Y H:i') }}
</p>

<table class="table table-striped table-bordered">
    <thead>
    <tr>
        <th>N°</th>
        <th>Articles</th>
        <th>Qté commandée</th>
        <th>Qté approvisionnée</th>

        @if($supply->purchaseOrder->type === 'external')
            <th>Prix d'achat (FCFA)</th>
        @endif

        <th>Notes</th>
    </tr>
    </thead>

    <tbody>
    @foreach($supply->items as $index => $item)
        <tr>
            <td>{{ $index + 1 }}</td>

            <td>{{ $item->product->name ?? '---' }}</td>

            <td>
                {{ intval(optional($supply->purchaseOrder->items->firstWhere('product_uuid', $item->product_uuid))->quantity)
                    ?? '---'
                }}
            </td>

            <td>{{ intval($item->quantity_supplied ?? 0) ?: '---' }}</td>

            @if($supply->purchaseOrder->type === 'external')
                <td>{{ intval($item->purchase_price) }}</td>
            @endif

            <td>{{ $item->notes ?? '' }}</td>
        </tr>
    @endforeach
    </tbody>
</table>


<!-- Statut -->
<div class="mt-3">
    <strong>Statut :</strong>

    @switch($supply->status)
        @case('pending')
            <span class="badge bg-warning px-3 py-2 rounded-pill">En brouillon</span>
            @break

        @case('validated')
            <span class="badge bg-success px-3 py-2 rounded-pill">Validé</span>
            @break

        @case('rejected')
            <span class="badge bg-danger px-3 py-2 rounded-pill">Rejeté</span>
            @break

        @case('partially_validated')
            <span class="badge bg-info px-3 py-2 rounded-pill">Validé partiellement</span>
            @break
    @endswitch

    @if($supply->rejection_reason)
        <p class="mt-2 fw-bold">
            Raison du rejet : {{ $supply->rejection_reason }}
        </p>
    @endif

    @if($supply->partial_validation_reason)
        <p class="mt-2 fw-bold">
            Raison de la validation partielle : {{ $supply->partial_validation_reason }}
        </p>
    @endif

</div>


<!-- Fournisseurs / Entrepôts -->
<div class="mt-3">
    @if($supply->purchaseOrder->type === 'external')

        <h6 class="fw-bold text-uppercase">Fournisseur(s)</h6>
        <ul class="list-group">
            @forelse($supply->suppliers as $supplierLink)
                <li class="list-group-item">
                    {{ $supplierLink->supplier->company_name ?? '---' }}
                    — {{ $supplierLink->supplier->first_name }} {{ $supplierLink->supplier->last_name }}
                    <br>
                    <small class="text-muted">
                        {{ $supplierLink->supplier->company_phone }} / {{ $supplierLink->supplier->address }}
                    </small>
                </li>
            @empty
                <li class="list-group-item text-muted">Aucun fournisseur enregistré.</li>
            @endforelse
        </ul>

    @else

        <h6 class="fw-bold text-uppercase">Entrepôt(s)</h6>
        <ul class="list-group">
            @if($supply->purchaseOrder->warehouse_from)
                <li class="list-group-item">
                    <strong>De :</strong> {{ $supply->purchaseOrder->warehouse_from->name }}
                </li>
            @endif
            @if($supply->purchaseOrder->warehouseTo)
                <li class="list-group-item">
                    <strong>Vers :</strong> {{ $supply->purchaseOrder->warehouseTo->name }}
                </li>
            @endif
        </ul>

    @endif
</div>



</body>
</html>
