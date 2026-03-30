<x-mail::message>
# Nouveau message reçu

Un nouveau message a été soumis via le formulaire de contact.

---

**Téléphone :** {{ $message->phone }}

@if($message->name)
**Nom / Entreprise :** {{ $message->name }}
@endif

@if($message->email)
**Email :** {{ $message->email }}
@endif

**Message :**

{{ $message->message }}

---

*Envoyé depuis le formulaire de contact.*

</x-mail::message>
