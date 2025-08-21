@component('mail::message')
# Première connexion

Bienvenu dans votre nouvelle application {{ config('app.name') }}.

Vos identifiants de connexion sont les suivants :
- USERNAME : **{{ $username }}**
- Mot de passe : **{{ $password }}**

@component('mail::button', ['url' => config('app.frontend_url') . '/login'])
Se connecter
@endcomponent

Thanks,<br>
{{ config('app.name') }}
@endcomponent
