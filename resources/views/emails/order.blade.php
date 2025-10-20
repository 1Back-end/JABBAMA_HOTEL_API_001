<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Transmission de commande - Jabbama Hotel</title>
    <style>
        body {
            font-family: 'Segoe UI', Arial, sans-serif;
            background-color: #f4f6f8;
            margin: 0;
            padding: 0;
        }
        .email-container {
            background-color: #ffffff;
            max-width: 600px;
            margin: 40px auto;
            border-radius: 10px;
            overflow: hidden;
            box-shadow: 0 3px 10px rgba(0,0,0,0.08);
        }
        .email-header {
            background-color: #1F4283;
            color: #ffffff;
            text-align: center;
            padding: 20px 10px;
        }
        .email-header h2 {
            margin: 0;
            font-size: 22px;
        }
        .email-body {
            padding: 25px;
            color: #333333;
            line-height: 1.6;
        }
        .email-body p {
            margin: 10px 0;
        }
        .order-ref {
            background-color: #f1f5ff;
            border: 1px solid #d0dcff;
            border-radius: 8px;
            padding: 12px;
            text-align: center;
            font-size: 18px;
            color: #1F4283;
            font-weight: bold;
            margin: 20px 0;
        }
        .email-footer {
            background-color: #f8f9fa;
            text-align: center;
            padding: 15px;
            font-size: 13px;
            color: #666666;
        }
    </style>
</head>
<body>
<div class="email-container">
    <div class="email-header">
        <h2>Nouvelle commande transférée</h2>
    </div>

    <div class="email-body">
        <p>Bonjour,</p>
        <p>
            Une nouvelle commande vient de vous être transférée via la plateforme
            <strong>Jabbama Hotel</strong>. Vous trouverez ci-dessous la référence associée :
        </p>

        <div class="order-ref">
            {{ $reference }}
        </div>

        <p>
            Merci de bien vouloir consulter le système pour plus de détails
            et prendre les dispositions nécessaires.
        </p>

        <p>Cordialement,<br><strong>L’équipe Jabbama Hotel</strong></p>
    </div>

    <div class="email-footer">
        © {{ date('Y') }} Jabbama Hotel — Tous droits réservés.
    </div>
</div>
</body>
</html>
