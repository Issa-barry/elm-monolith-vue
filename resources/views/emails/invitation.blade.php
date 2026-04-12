<x-mail::message>
# Invitation à rejoindre {{ $invitation->site->nom }}

Vous avez été invité à rejoindre le site **{{ $invitation->site->nom }}** en tant que **{{ $invitation->role }}**.

Ce lien d'invitation est valable pendant **24 heures**.

<x-mail::button :url="$acceptUrl">
Accepter l'invitation
</x-mail::button>

Si vous n'attendiez pas cette invitation, vous pouvez ignorer ce message.

Cordialement,
*L'équipe {{ config('app.name') }}*
</x-mail::message>
