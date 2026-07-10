<h2>Nouvelle soumission formulaire</h2>

<p>
    Site :
    {{ $site->name }}
</p>

<p>
    Formulaire :
    {{ $formId }}
</p>

<hr>

@foreach($values as $key => $value)

    <p>
        <strong>{{ $key }}</strong>
        :
        {{ is_array($value) ? implode(', ', $value) : $value }}
    </p>

@endforeach
