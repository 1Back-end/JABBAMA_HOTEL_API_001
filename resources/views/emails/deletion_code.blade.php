<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Code de validation</title>
    <link href="https://fonts.googleapis.com/css2?family=Rubik:ital,wght@0,300..900;1,300..900&display=swap" rel="stylesheet">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Rubik:ital,wght@0,300..900;1,300..900&display=swap');
        body {
            font-family: "Rubik", sans-serif;
            background-color: #f5f6fa;
            margin: 0;
            padding: 0;
        }
        .container {
            max-width: 600px;
            margin: 40px auto;
            background-color: #fff;
            border-radius: 10px;
            padding: 30px;
            box-shadow: 0 8px 25px rgba(0,0,0,0.15);
        }
        h2 {
            color: #1F4283;
            text-align: center;
            margin-bottom: 20px;
        }
        p {
            font-size: 16px;
            line-height: 1.6;
            color: #333;
        }
        .code {
            display: block;
            margin: 25px auto;
            padding: 12px 25px;
            font-size: 26px;
            font-weight: 700;
            color: #fff;
            background-color: #1F4283;
            border-radius: 8px;
            text-align: center;
            letter-spacing: 5px;
        }
        .footer {
            font-size: 12px;
            text-align: center;
            color: #999;
            margin-top: 25px;
        }
    </style>
</head>
<body>
<div class="container">
    <h2>Code de validation pour suppression</h2>
    <p>Bonjour,</p>
    <p>Vous avez demandé une opération nécessitant une validation. Veuillez utiliser le code ci-dessous pour confirmer votre action :</p>
    <span class="code">{{ $code }}</span>
    <p>Ce code est valide jusqu'au <strong>{{ $expires_at->format('d/m/Y H:i') }}</strong>. Ne partagez ce code avec personne.</p>
    <div class="footer">
        &copy; {{ date('Y') }} JABBMA HOTEL. Tous droits réservés.
    </div>
</div>
</body>
</html>
