<!doctype html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Ticket {{ $order->code }}</title>

    <style>
        {!! $bootstrap !!}
    </style>

    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap');

        body, html {
            margin: 0;
            padding: 0;
            font-family: 'Inter', -apple-system, sans-serif;
            color: #000000;
            background-color: #ffffff;
            font-size: 7.5pt !important;
            line-height: 1.2;
        }

        .fs-3 { font-size: 9.5pt !important; }
        .fs-6 { font-size: 7.5pt !important; }
        .small { font-size: 6.5pt !important; }

        .ticket-divider {
            border-top: 1px dashed #444444;
            margin: 5px 0;
            width: 100%;
        }

        .info-line {
            display: flex;
            justify-content: space-between;
            margin-bottom: 2px;
        }

        .details-label {
            font-size: 7pt;
            text-transform: uppercase;
            color: #555555;
            font-weight: 500;
        }

        .details-value {
            font-size: 7.5pt;
            color: #000000;
            font-weight: 600;
            text-align: right;
        }

        .ticket-items-list {
            width: 100%;
            margin-top: 3px;
            font-size: 7.5pt !important;
        }
        .ticket-item-row {
            margin-bottom: 5px;
        }
        .item-main {
            display: flex;
            justify-content: space-between;
            font-weight: 600;
        }
        .item-complements {
            font-size: 6.8pt;
            color: #555555;
            padding-left: 12px;
            font-style: italic;
            line-height: 1.1;
            margin-top: 1px;
        }

        .total-block {
            display: flex;
            justify-content: space-between;
            font-size: 9pt !important;
            font-weight: 700;
            margin-top: 5px;
            padding-top: 3px;
        }
    </style>
</head>

<body>
<div class="print-wrapper" style="padding: 2px;">

    <header>
        <div class="d-flex justify-content-between align-items-start">
            <div>
                <div class="fs-3 fw-bold text-uppercase" style="letter-spacing: -0.3px;">
                    FACTURE / COMMANDE
                </div>
                <div class="text-muted" style="font-size: 7pt;">
                    Réf: <span class="fw-bold text-dark">{{ $order->code }}</span>
                </div>
            </div>

            <div class="text-end" style="font-size: 7pt; line-height: 1.1;">
                <div>
                    <span class="text-muted">Par:</span>
                    <span class="fw-bold text-uppercase">{{ $order->updater->nom_utilisateur ?? $order->updater->login ?? $order->creator->login ?? '' }}</span>
                </div>
                <div class="text-muted" style="font-size: 6.5pt;">
                    {{ $order->created_at->format('d/m/Y H:i') }}
                </div>
            </div>
        </div>
    </header>

    <div class="ticket-divider"></div>

    <div class="invoice-details-vertical">
        <div class="info-line">
            <span class="details-label">Consommation :</span>
            <span class="details-value">{{ $order->consumption_type_label ?? '---' }}</span>
        </div>

        <div class="info-line">
            <span class="details-label">Table :</span>
            <span class="details-value">
                @if($order->restaurantTable)
                    N° {{ $order->restaurantTable->table_number }}
                @else
                    ---
                @endif
            </span>
        </div>

        <div class="info-line">
            <span class="details-label">Client :</span>
            <span class="details-value text-uppercase">
                @if($order->partners_restaurant)
                    {{ $order->partners_restaurant->full_name }} <span class="small text-muted" style="font-weight:normal;">(Part.)</span>
                @elseif($order->type_clients_for_payment === 'debtor')
                    {{ $order->full_name }} <span class="small text-danger" style="font-weight:normal;">(Déb.)</span>
                @elseif($order->type_clients_for_payment === 'free')
                    {{ $order->free_client_for_restaurant->full_name ?? '' }} <span class="small text-success" style="font-weight:normal;">(Grat.)</span>
                @else
                    Standard
                @endif
            </span>
        </div>

        @if($order->remise > 0)
            <div class="info-line" style="margin-top: 1px;">
                <span class="details-label text-danger">Remise :</span>
                <span class="details-value text-danger">{{ $order->remise }}%</span>
            </div>
        @endif
    </div>

    <div class="ticket-divider"></div>

    <div class="ticket-items-list" style="width: 100%;">

        {{-- SECTION MENUS --}}
        @if($order->items && count($order->items) > 0)
            <div class="section-divider fw-bold">Menus</div>
            <table class="invoice-table" style="font-size: 12px !important; width: 100%; table-layout: fixed; border-collapse: collapse; margin-bottom: 5px;">
                <thead>
                <tr style="border-bottom: 1px solid #000000; width: 100%;">
                    <th style="width: 45%; padding: 2px 0; text-align: left;">Désignation</th>
                    <th style="width: 12%; padding: 2px 0;" class="text-center">Qté</th>
                    <th style="width: 18%; padding: 2px 0;" class="text-end">P.U</th>
                    <th style="width: 20%; padding: 2px 0;" class="text-end">Total</th>
                </tr>
                </thead>
                <tbody>
                @foreach($order->items as $index => $item)
                    <tr style="border-bottom: 1px dashed #dddddd; width: 100%;">
                        <td class="text-uppercase" style="padding: 3px 0; font-size: 12px; vertical-align: top; word-wrap: break-word;">
                            {{ $item->menu->name ?? 'Menu inconnu' }}

                            @if(isset($item->complements) && count($item->complements) > 0)
                                <div class="complements-block" style="margin-top: 1px; padding-left: 5px;">
                                    @foreach($item->complements as $c)
                                        @if(($c->quantity_exactly ?? $c->quantity ?? 0) > 0)
                                            <div class="text-muted fst-italic" style="font-size: 6px">
                                                • {{ $c->complementRestaurant->name ?? $c->complement->name ?? $c->name ?? 'Complément' }}
                                                (x{{ $c->quantity_exactly ?? $c->quantity }})
                                            </div>
                                        @endif
                                    @endforeach
                                </div>
                            @endif
                        </td>
                        <td class="text-center" style="padding: 3px 0; vertical-align: top;">
                            {{ number_format($item->quantity_exactly ?? $item->quantity ?? 0, 0, ',', ' ') }}
                        </td>
                        <td class="text-end" style="padding: 3px 0; vertical-align: top;">
                            {{ number_format($item->unit_price ?? 0, 0, ',', ' ') }}
                        </td>
                        <td class="text-end" style="padding: 3px 0; vertical-align: top;">
                            {{ number_format(($item->unit_price ?? 0) * ($item->quantity_exactly ?? $item->quantity ?? 0), 0, ',', ' ') }}
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        @endif

        {{-- SECTION BOISSONS --}}
        @if($order->drinks && count($order->drinks) > 0)
            <div class="section-divider fw-bold" style="margin-top: 5px;">Boissons</div>
            <table class="invoice-table" style="font-size: 12px !important; width: 100%; table-layout: fixed; border-collapse: collapse;">
                <thead>
                <tr style="border-bottom: 1px solid #000000; width: 100%;">
                    <th style="width: 45%; padding: 2px 0; text-align: left;">Désignation</th>
                    <th style="width: 12%; padding: 2px 0;" class="text-center">Qté</th>
                    <th style="width: 18%; padding: 2px 0;" class="text-end">P.U</th>
                    <th style="width: 20%; padding: 2px 0;" class="text-end">Total</th>
                </tr>
                </thead>
                <tbody>
                @foreach($order->drinks as $index => $drink)
                    <tr style="border-bottom: 1px dashed #dddddd; width: 100%;">
                        <td class="text-uppercase" style="padding: 3px 0; font-size: 12px; vertical-align: top; word-wrap: break-word;">
                            {{ $drink->drink_config->product->name ?? $drink->drink_config->drink_name ?? $drink->drinkConfig->product->name ?? $drink->drinkConfig->drink_name ?? 'Boisson inconnue' }}
                        </td>
                        <td class="text-center" style="padding: 3px 0; vertical-align: top;">
                            {{ number_format($drink->quantity_exactly ?? $drink->quantity ?? 0, 0, ',', ' ') }}
                        </td>
                        <td class="text-end" style="padding: 3px 0; vertical-align: top;">
                            {{ number_format($drink->unit_price ?? 0, 0, ',', ' ') }}
                        </td>
                        <td class="text-end" style="padding: 3px 0; vertical-align: top;">
                            {{ number_format(($drink->unit_price ?? 0) * ($drink->quantity_exactly ?? $drink->quantity ?? 0), 0, ',', ' ') }}
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        @endif

    </div>

    <div class="ticket-divider"></div>

    <div class="total-block">
        <span>TOTAL À PAYER :</span>
        <span>{{ number_format($order->total_order ?? 0, 0, ',', ' ') }} F CFA</span>
    </div>

    <div style="font-size: 6.3pt; color: #555555; font-style: italic; margin-top: 4px; text-align: center; line-height: 1.1;">
        * T.V.A et taxes incluses.
    </div>

    <div class="ticket-divider"></div>

    <div class="text-center text-muted small my-1" style="font-size: 6.5pt !important; letter-spacing: 0.5px;">
        * MERCI POUR VOTRE VISITE *
    </div>

</div>
</body>
</html>
