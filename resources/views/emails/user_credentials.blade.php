<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Identifiants de connexion</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        *{
            font-family: 'Poppins', sans-serif;
        }
        body {
            font-family: 'Poppins', sans-serif;
            background-color: #f0f2f5;
            margin: 0;
            padding: 0;
        }
        .container {
            max-width: 600px;
            margin: 50px auto;
            background-color: #ffffff;
            border-radius: 12px;
            padding: 40px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
        }
        h2 {
            color: #1F4283;
            text-align: center;
            font-size: 28px;
            margin-bottom: 20px;
        }
        p {
            font-size: 16px;
            color: #555;
            line-height: 1.6;
            text-align: center;
        }
        .credentials {
            margin: 30px auto;
            padding: 25px;
            background-color: #1F4283;
            border-radius: 10px;
            color: #ffffff;
            text-align: center;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }
        .credentials span {
            display: block;
            font-size: 18px;
            margin: 10px 0;
        }
        .credentials strong {
            font-weight: 700;
        }
        .footer {
            text-align: center;
            color: #999;
            font-size: 12px;
            margin-top: 30px;
        }
        @media (max-width: 600px) {
            .container {
                padding: 20px;
            }
            h2 {
                font-size: 24px;
            }
            .credentials span {
                font-size: 16px;
            }
        }
    </style>
</head>
<body>
<div class="container">
    <h2>Vos identifiants de connexion</h2>
    <p>Bonjour,</p>
    <p>Votre compte a été créé avec succès. Voici vos informations de connexion :</p>

    <div class="credentials">
        <span>Login : <strong>{{ $login }}</strong></span>
        <span>Mot de passe : <strong>{{ $password }}</strong></span>
    </div>

    <div class="footer">
        &copy; {{ date('Y') }} JABBMA HOTEL. Tous droits réservés.
    </div>
</div>
</body>
</html>
