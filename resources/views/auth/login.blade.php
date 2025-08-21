<!doctype html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Connexion</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.5/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-SgOJa3DmI69IUzQ2PVdRZhwQ+dy64/BUtbMJw1MZ8t5HZApcHrRKUc4W0kG879m7" crossorigin="anonymous">
</head>
<body>
<section class="vh-100 mt-5">
    <div class="container-fluid h-custom">
        <div class="row d-flex justify-content-center align-items-center h-100">
            <div class="col-md-8 col-lg-6 col-xl-4 offset-xl-1 rounded border shadow-lg p-5">
                <h1 class="text-center fs-3 mb-5">Connexion à la console ADMIN</h1>
                <form method="POST" action="{{ route('login:admin') }}">
                    @csrf

                    <!-- Email input -->
                    <div data-mdb-input-init class="form-outline mb-4">
                        <label class="form-label" for="login">Login</label>
                        <input type="text" id="login" name="login" class="form-control form-control-lg"
                               placeholder="Entrer votre login" autocomplete="off"/>
                    </div>

                    <!-- Password input -->
                    <div data-mdb-input-init class="form-outline mb-3">
                        <label class="form-label" for="password">Mot de passe</label>
                        <input type="password" id="password" name="password" class="form-control form-control-lg"
                               placeholder="Entrer le mot de passe" autocomplete="off"/>
                    </div>

                    <div class=" mt-4 pt-2 d-flex justify-content-center">
                        <button  type="submit" class="btn btn-primary btn-lg">
                            Connexion
                        </button>
                    </div>

                </form>
            </div>
        </div>
    </div>
</section>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.5/dist/js/bootstrap.bundle.min.js" integrity="sha384-k6d4wzSIapyDyv1kpU366/PK5hCdSbCRGRCMv+eplOQJWyd1fbcAu9OCUj5zNLiq" crossorigin="anonymous"></script>
</body>
</html>
